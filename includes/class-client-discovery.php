<?php
/**
 * IndieAuth Client Discovery class.
 *
 * @package IndieAuth
 */

namespace IndieAuth;

/**
 * Discovers information about an IndieAuth client from its client_id URL.
 */
class Client_Discovery {

	/**
	 * Discovered rel values.
	 *
	 * @var array
	 */
	protected $rels = array();

	/**
	 * Parsed HTML data.
	 *
	 * @var array
	 */
	protected $html = array();

	/**
	 * Parsed Microformats2 data.
	 *
	 * @var array
	 */
	protected $mf2 = array();

	/**
	 * Parsed JSON metadata.
	 *
	 * @var array
	 */
	protected $json = array();

	/**
	 * The client identifier URL.
	 *
	 * @var string
	 */
	public $client_id = '';

	/**
	 * The human-readable client name.
	 *
	 * @var string
	 */
	public $client_name = '';

	/**
	 * The client icon URL.
	 *
	 * @var string
	 */
	public $client_icon = '';

	/**
	 * The client URI.
	 *
	 * @var string
	 */
	public $client_uri = '';

	/**
	 * The redirect URIs published by the client.
	 *
	 * @var array
	 */
	protected $redirect_uris = array();

	/**
	 * Constructor. Fetches and parses client information.
	 *
	 * @param string $client_id The client identifier URL.
	 */
	public function __construct( $client_id ) {
		$this->client_id = $client_id;

		if ( defined( 'INDIEAUTH_UNIT_TESTS' ) ) {
			return;
		}

		$this->discover();
	}

	/**
	 * Fetches and parses the client information document.
	 */
	public function discover() {
		// Validate if this is an IP address.
		$ip         = filter_var( \wp_parse_url( $this->client_id, PHP_URL_HOST ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 );
		$donotfetch = array(
			'127.0.0.1',
			'0000:0000:0000:0000:0000:0000:0000:0001',
			'::1',
		);

		// If this is an IP address on the donotfetch list then do not fetch.
		if ( $ip && ! in_array( $ip, $donotfetch, true ) ) {
			return;
		}

		if ( 'localhost' === \wp_parse_url( $this->client_id, PHP_URL_HOST ) ) {
			return;
		}
		$response = self::parse( $this->client_id );
		if ( \is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( \__( 'Failed to Retrieve IndieAuth Client Details ', 'indieauth' ) . \wp_json_encode( $response ) );
			return;
		}
	}

	/**
	 * Exports the discovered client information as an array.
	 *
	 * @return array The client information.
	 */
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

	/**
	 * Fetches the client URL content.
	 *
	 * @param string $url The URL to fetch.
	 * @return array|\WP_Error The HTTP response or WP_Error on failure.
	 */
	private function fetch( $url ) {
		$wp_version = \get_bloginfo( 'version' );
		$user_agent = \apply_filters( 'http_headers_useragent', 'WordPress/' . $wp_version . '; ' . \get_bloginfo( 'url' ) );
		$args       = array(
			'timeout'             => 100,
			'limit_response_size' => 1048576,
			'redirection'         => 3,
			'user-agent'          => "$user_agent; IndieAuth Client Information Discovery",
		);
		$response   = \wp_safe_remote_get( $url, $args );
		if ( ! \is_wp_error( $response ) ) {
			$code = \wp_remote_retrieve_response_code( $response );
			if ( ( $code / 100 ) !== 2 ) {
				return new \WP_Error( 'retrieval_error', \__( 'Failed to Retrieve Client Details', 'indieauth' ), $code );
			}
		}

		return $response;
	}

	/**
	 * Parses the client URL to extract client metadata.
	 *
	 * @param string $url The client URL to parse.
	 * @return void|\WP_Error Void on success, WP_Error on failure.
	 */
	private function parse( $url ) {
		$response = self::fetch( $url );

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		$this->redirect_uris = self::parse_redirect_uris_from_link_headers( $response, $url );

		$content_type = \wp_remote_retrieve_header( $response, 'content-type' );
		if ( 'application/json' === $content_type ) {
			$this->json = json_decode( \wp_remote_retrieve_body( $response ), true );
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
			 */
			if ( ! is_array( $this->json ) || empty( $this->json ) ) {
					return new \WP_Error( 'empty_json', \__( 'Discovery Has Returned an Empty JSON Document', 'indieauth' ) );
			}
			if ( ! array_key_exists( 'client_id', $this->json ) ) {
				return new \WP_Error( 'missing_client_id', \__( 'No Client ID Found in JSON Client Metadata', 'indieauth' ) );
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
			if ( array_key_exists( 'redirect_uris', $this->json ) && is_array( $this->json['redirect_uris'] ) ) {
				$this->redirect_uris = array_merge( $this->redirect_uris, $this->json['redirect_uris'] );
			}
		} elseif ( 'text/html' === $content_type ) {
			$content = \wp_remote_retrieve_body( $response );
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
				$domdocument = new \DOMDocument();
				\libxml_use_internal_errors( true );
				$domdocument->loadHTML( $content );
				\libxml_clear_errors();
				$this->client_icon = $this->determine_icon( $this->rels );
				$this->get_html( $domdocument );
				if ( ! empty( $this->html['title'] ) ) {
					$this->client_name = $this->html['title'];
				}
			}

			if ( ! empty( $this->client_icon ) ) {
				$this->client_icon = \WP_Http::make_absolute_url( $this->client_icon, $url );
			}
		}
	}

	/**
	 * Parses Microformats2 data from HTML content.
	 *
	 * @param string $input The HTML content to parse.
	 * @param string $url   The URL of the content for resolving relative URLs.
	 */
	private function get_mf2( $input, $url ) {
		if ( ! class_exists( 'Mf2\Parser' ) ) {
			require_once \plugin_dir_path( __DIR__ ) . 'lib/mf2/Parser.php';
		}
		$mf = \Mf2\parse( $input, $url );
		if ( array_key_exists( 'rels', $mf ) ) {
			$this->rels = \wp_array_slice_assoc( $mf['rels'], array( 'apple-touch-icon', 'icon', 'mask-icon', 'redirect_uri' ) );
			if ( ! empty( $this->rels['redirect_uri'] ) ) {
				$this->redirect_uris = array_merge( $this->redirect_uris, (array) $this->rels['redirect_uri'] );
			}
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

	/**
	 * Extracts HTML metadata from a DOMDocument.
	 *
	 * @param \DOMDocument $input The parsed DOM document.
	 */
	private function get_html( $input ) {
		$xpath = new \DOMXPath( $input );
		if ( ! empty( $xpath ) ) {
			$title = $xpath->query( '//title' );
			if ( $title instanceof \DOMNodeList && $title->length > 0 ) {
				$this->html['title'] = $title->item( 0 )->textContent;
			}
		}
	}

	/**
	 * Returns a value from an array if it exists, or a default.
	 *
	 * @param array        $data          The array to check.
	 * @param string|array $key           The key or keys to look for.
	 * @param mixed        $default_value The default value if key is not found.
	 * @return mixed The found value or default.
	 */
	private function ifset( $data, $key, $default_value = false ) {
		if ( ! is_array( $data ) ) {
			return $default_value;
		}
		if ( is_array( $key ) ) {
			foreach ( $key as $k ) {
				if ( isset( $data[ $k ] ) ) {
					return $data[ $k ];
				}
			}
		} else {
			return isset( $data[ $key ] ) ? $data[ $key ] : $default_value;
		}
	}

	/**
	 * Returns the client name.
	 *
	 * @return string The client name.
	 */
	public function get_name() {
		return $this->client_name;
	}

	/**
	 * Returns the client URI.
	 *
	 * @return string The client URI.
	 */
	public function get_uri() {
		return $this->client_uri;
	}

	/**
	 * Determines the best icon URL from discovered rel values.
	 *
	 * @param array $input The rel values array.
	 * @return string The icon URL or empty string.
	 */
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

		if ( empty( $icons ) ) {
			return '';
		}

		if ( is_array( $icons ) && ! \wp_is_numeric_array( $icons ) && isset( $icons['url'] ) ) {
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

	/**
	 * Returns the client icon URL.
	 *
	 * @return string The client icon URL.
	 */
	public function get_icon() {
		return $this->client_icon;
	}

	/**
	 * Returns the redirect URIs published by the client.
	 *
	 * @return array The published redirect URIs.
	 */
	public function get_redirect_uris() {
		return array_values( array_unique( $this->redirect_uris ) );
	}

	/**
	 * Extracts redirect URIs from Link headers of a response.
	 *
	 * A client MAY publish one or more Link HTTP headers with a rel attribute
	 * of redirect_uri at the client_id URL.
	 *
	 * @param array  $response The HTTP response.
	 * @param string $url      The requested URL, used to make relative URLs absolute.
	 * @return array The redirect URIs found in Link headers.
	 */
	private static function parse_redirect_uris_from_link_headers( $response, $url ) {
		$redirect_uris = array();
		$links         = \wp_remote_retrieve_header( $response, 'link' );
		if ( empty( $links ) ) {
			return $redirect_uris;
		}
		// Multiple Link headers are returned as an array, a single one as a string that may hold comma-separated values.
		if ( is_string( $links ) ) {
			$links = explode( ',', $links );
		}
		foreach ( (array) $links as $link ) {
			if ( preg_match( '/<\s*([^>]+)\s*>\s*;\s*rel\s*=\s*"?redirect_uri"?/i', $link, $matches ) ) {
				$redirect_uris[] = \WP_Http::make_absolute_url( trim( $matches[1] ), $url );
			}
		}
		return $redirect_uris;
	}
}
