<?php

class IndieAuth_Client_Discovery {
	protected $rels     = array();
	protected $manifest = array();
	protected $html     = array();
	protected $mf2      = array();
	public $client_id   = '';
	public $client_name = '';
	public $client_icon = '';

	public function __construct( $client_id ) {
		$this->client_id = $client_id;

		if ( defined( 'INDIEAUTH_UNIT_TESTS' ) ) {
			return;
		}
		// Validate if this is an IP address
		$ip         = filter_var( wp_parse_url( $client_id, PHP_URL_HOST ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 );
		$donotfetch = array(
			'127.0.0.1',
			'0000:0000:0000:0000:0000:0000:0000:0001',
			'::1',
		);

		// If this is an IP address on the donotfetch list then do not fetch.
		if ( ( $ip && ! in_array( $ip, $donotfetch, true ) || 'localhost' === wp_parse_url( $client_id, PHP_URL_HOST ) ) ) {
			return;
		}

		$response = self::parse( $client_id );
		if ( is_wp_error( $response ) ) {
			error_log( __( 'Failed to Retrieve IndieAuth Client Details ', 'indieauth' ) . wp_json_encode( $response ) ); // phpcs:ignore
			return;
		}
	}

	public function export() {
		return array(
			'manifest'    => $this->manifest,
			'rels'        => $this->rels,
			'mf2'         => $this->mf2,
			'html'        => $this->html,
			'client_id'   => $this->client_id,
			'client_name' => $this->client_name,
			'client_icon' => $this->client_icon,
		);
	}

	private function fetch( $url ) {
		$wp_version = get_bloginfo( 'version' );
		$user_agent = apply_filters( 'http_headers_useragent', 'WordPress/' . $wp_version . '; ' . get_bloginfo( 'url' ) );
		$args       = array(
			'timeout'             => 100,
			'limit_response_size' => 1048576,
			'redirection'         => 3,
			'user-agent'          => "$user_agent; IndieAuth Client Information Discovery",
		);
		$response   = wp_safe_remote_get( $url, $args );
		if ( ! is_wp_error( $response ) ) {
			$code = wp_remote_retrieve_response_code( $response );
			if ( ( $code / 100 ) !== 2 ) {
				return new WP_Error( 'retrieval_error', __( 'Failed to Retrieve Client Details', 'indieauth' ), $code );
			}
		}

		return $response;
	}

	private function parse( $url ) {
		$response = self::fetch( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$content = wp_remote_retrieve_body( $response );

		if ( class_exists( 'Masterminds\\HTML5' ) ) {
			$domdocument = new \Masterminds\HTML5( array( 'disable_html_ns' => true ) );
			$domdocument = $domdocument->loadHTML( $content );
		} else {
			$domdocument = new DOMDocument();
			libxml_use_internal_errors( true );
			if ( function_exists( 'mb_convert_encoding' ) ) {
				$content = mb_convert_encoding( $content, 'HTML-ENTITIES', mb_detect_encoding( $content ) );
			}
			$domdocument->loadHTML( $content );
			libxml_use_internal_errors( false );
		}

		$this->get_mf2( $domdocument, $url );
		if ( empty( $this->mf2 ) ) {
			if ( array_key_exists( 'name', $this->mf2 ) ) {
				$this->client_name = $this->mf2['name'][0];
			}
			if ( array_key_exists( 'logo', $this->mf2 ) ) {
				if ( is_string( $this->mf2['logo'][0] ) ) {
					$this->client_icon = $this->mf2['logo'][0];
				} else {
					$this->client_icon = $this->mf2['logo'][0]['value'];
				}
			}
		} elseif ( isset( $this->rels['manifest'] ) ) {
			self::get_manifest( $this->rels['manifest'] );
			$this->client_icon = $this->determine_icon( $this->manifest );
			$this->client_name = $this->manifest['name'];
		} else {
			$this->client_icon = $this->determine_icon( $this->rels );
			$this->get_html( $domdocument );
			$this->client_name = $this->html['title'];
		}

		if ( ! empty( $this->client_icon ) ) {
			$this->client_icon = WP_Http::make_absolute_url( $this->client_icon, $url );
		}
	}

	private function get_mf2( $input, $url ) {
		if ( ! class_exists( 'Mf2\Parser' ) ) {
			require_once plugin_dir_path( __DIR__ ) . 'lib/mf2/Parser.php';
		}
		$mf = Mf2\parse( $input, $url );
		if ( array_key_exists( 'rels', $mf ) ) {
			$this->rels = wp_array_slice_assoc( $mf['rels'], array( 'apple-touch-icon', 'icon', 'mask-icon', 'manifest' ) );
		}
		if ( array_key_exists( 'items', $mf ) ) {
			foreach ( $mf['items'] as $item ) {
				if ( in_array( 'h-app', $item['type'], true ) ) {
					$this->mf2 = $item['properties'];
					return;
				}
			}
		}
	}

	private function get_manifest( $url ) {
		if ( is_array( $url ) ) {
			$url = $url[0];
		}
		$response = self::fetch( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$this->manifest = json_decode( wp_remote_retrieve_body( $response ), true );
	}

	private function get_html( $input ) {
		if ( ! $input ) {
			return;
		}
		$xpath               = new DOMXPath( $input );
		$this->html['title'] = $xpath->query( '//title' )->item( 0 )->textContent;
	}

	private function ifset( $array, $key, $default = false ) {
		if ( ! is_array( $array ) ) {
			return $default;
		}
		if ( is_array( $key ) ) {
			foreach ( $key as $k ) {
				if ( isset( $array[ $k ] ) ) {
					return $array[ $k ];
				}
			}
		} else {
			return isset( $array[ $key ] ) ? $array[ $key ] : $default;
		}
	}

	public function get_name() {
		return $this->client_name;
	}

	// Separate function for possible improved size picking later
	private function determine_icon( $input ) {
		if ( ! is_array( $input ) || empty( $input ) ) {
			return '';
		}

		$icons = array();
		if ( isset( $input['icons'] ) ) {
			$icons = $input['icons'];
		} elseif ( isset( $input['mask-icon'] ) ) {
			$icons = $input['mask-icon'];
		} elseif ( isset( $input['apple-touch-icon'] ) ) {
			$icons = $input['apple-touch-icon'];
		} elseif ( isset( $input['icon'] ) ) {
			$icons = $input['icon'];
		}

		if ( is_array( $icons ) && ! wp_is_numeric_array( $icons ) && isset( $icons['url'] ) ) {
			return $icons['url'];
		} elseif ( is_string( $icons[0] ) ) {
			return $icons[0];
		} elseif ( isset( $icons[0]['url'] ) ) {
			return $icons[0]['url'];
		} elseif ( isset( $icons[0]['src'] ) ) {
			return $icons[0]['src'];
		} else {
			return '';
		}
	}

	public function get_icon() {
		return $this->client_icon;
	}
}
