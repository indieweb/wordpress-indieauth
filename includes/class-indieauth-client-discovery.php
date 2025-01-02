<?php

class IndieAuth_Client_Discovery {
	protected $rels     = array();
	protected $html     = array();
	protected $mf2      = array();
	protected $json     = array();
	public $client_id   = '';
	public $client_name = '';
	public $client_icon = '';
	public $client_uri  = '';

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
		if ( $ip && ! in_array( $ip, $donotfetch, true ) ) {
			return;
		}

		if ( 'localhost' === wp_parse_url( $client_id, PHP_URL_HOST ) ) {
			return;
		}
		error_log( 'Pre-Parse' );
		$response = self::parse( $client_id );
		error_log( 'Post-Parse' );
		if ( is_wp_error( $response ) ) {
			error_log( __( 'Failed to Retrieve IndieAuth Client Details ', 'indieauth' ) . wp_json_encode( $response ) ); // phpcs:ignore
			return;
		}
	}

	public function export() {
		return array(
			'rels'        => $this->rels,
			'mf2'         => $this->mf2,
			'html'        => $this->html,
			'json'        => $this->json,
			'client_id'   => $this->client_id,
			'client_name' => $this->client_name,
			'client_icon' => $this->client_icon,
			'client_uri'  => $this->client_uri,
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

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( 'application/json' === $content_type ) {
			$this->json = json_decode( wp_remote_retrieve_body( $response ), true );
			/**
			 * Expected format is per the IndieAuth standard as revised 2024-06-23 to include a JSON Client Metadata File
			 *
			 * @param array $json {
			 *      An array of metadata about a client
			 *
			 *      @type string $client_uri URL of a webpage providing information about the client.
			 *      @type string $client_id The client identifier.
			 *      @type string $client_name Human Readable Name of the Client. Optional.
			 *      @type string $logo_uri URL that references a logo or icon for the client. Optional.
			 *      @type array $redirect_uris An array of redirect URIs. Optional.
			 *  }
			 **/
			if ( ! is_array( $this->json ) || empty( $this->json ) ) {
					return new WP_Error( 'empty_json', __( 'Discovery Has Returned an Empty JSON Document', 'indieauth' ) );
			}
			if ( ! array_key_exists( 'client_id', $this->json ) ) {
				return new WP_Error( 'missing_client_id', __( 'No Client ID Found in JSON Client Metadata', 'indieauth' ) );
			}
			$this->client_id = $this->json['client_id'];
			if ( array_key_exists( 'client_name', $this->json ) ) {
				$this->client_name = $this->json['client_name'];
			}
			if ( array_key_exists( 'logo_uri', $this->json ) ) {
				$this->client_icon = $this->json['logo_uri'];
			}
			if ( array_key_exists( 'client_uri', $this->json ) ) {
				$this->client_uri = $this->json['client_uri'];
			}
		} elseif ( 'text/html' === $content_type ) {
			$content = wp_remote_retrieve_body( $response );
			$this->get_mf2( $content, $url );
			if ( ! empty( $this->mf2 ) ) {
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
			} else {
				$domdocument       = new DOMDocument( wp_remote_retrieve_body( $response ) );
				$this->client_icon = $this->determine_icon( $this->rels );
				$this->get_html( $domdocument );
				$this->client_name = $this->html['title'];
			}

			if ( ! empty( $this->client_icon ) ) {
				$this->client_icon = WP_Http::make_absolute_url( $this->client_icon, $url );
			}
		}
	}

	private function get_mf2( $input, $url ) {
		if ( ! class_exists( 'Mf2\Parser' ) ) {
			require_once plugin_dir_path( __DIR__ ) . 'lib/mf2/Parser.php';
		}
		$mf = Mf2\parse( $input, $url );
		if ( array_key_exists( 'rels', $mf ) ) {
			$this->rels = wp_array_slice_assoc( $mf['rels'], array( 'apple-touch-icon', 'icon', 'mask-icon' ) );
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

	private function get_html( $input ) {
		$xpath = new DOMXPath( $input );
		if ( ! empty( $xpath ) ) {
			$title = $xpath->query( '//title' );
			if ( ! empty( $title ) ) {
				$this->html['title'] = $title->item( 0 )->textContent;
			}
		}
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

	public function get_uri() {
		return $this->client_uri;
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
