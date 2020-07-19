<?php

class IndieAuth_Client_Discovery {
	protected $html     = array();
	protected $manifest = array();
	public $client_id   = '';
	public $client_name = '';
	public $client_icon = '';

	public function __construct( $client_id ) {
		$this->client_id = $client_id;
		$this->html      = self::parse( $client_id );
		if ( is_wp_error( $this->html ) ) {
			error_log( __( 'Failed to Retrieve IndieAuth Client Details ', 'indieauth' ) . wp_json_encode( $this->html ) ); // phpcs:ignore
			return;
		}
		if ( isset( $this->html['manifest'] ) ) {
			$this->manifest = self::get_manifest( $this->html['manifest'] );
		}
		$this->client_icon = $this->determine_icon();
		$this->client_name = $this->ifset( $this->manifest, 'name', '' );
		if ( empty( $this->client_name ) ) {
			$this->client_name = $this->ifset( $this->html, array( 'application-name', 'og:title', 'title' ), '' );
		}
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
		return wp_safe_remote_get( $url, $args );
	}

	private function parse( $url ) {
		$response = self::fetch( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$return = array();
		// check link header
		$links = wp_remote_retrieve_header( $response, 'link' );
		if ( $links ) {
			if ( is_string( $links ) ) {
				$links = array( $links );
			}
			$return['links'] = parse_link_rels( $links, $url );
		}
		return array_merge( $return, self::extract_client_data_from_html( wp_remote_retrieve_body( $response ), $url ) );
	}

	private function get_manifest( $url ) {
		$response = self::fetch( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return json_decode( wp_remote_retrieve_body( $response ) );
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
	private function determine_icon() {
		if ( is_wp_error( $this->html ) ) {
			return '';
		}
		$icons = array();
		if ( is_array( $this->manifest ) && ! empty( $this->manifest ) && ! isset( $this->manifest['icons'] ) ) {
			$icons = $this->manifest['icons'];
		} elseif ( ! empty( $this->html ) ) {
			if ( isset( $this->html['icon'] ) ) {
				$icons = $this->html['icon'];
			} elseif ( isset( $this->html['mask-icon'] ) ) {
				$icons = $this->html['mask-icon'];
			} elseif ( isset( $this->html['apple-touch-icon'] ) ) {
				$icons = $this->html['apple-touch-icon'];
			}
		}
		if ( is_array( $icons ) && ! wp_is_numeric_array( $icons ) && isset( $icons['url'] ) ) {
			return $icons['url'];
		} else {
			// Return the first icon
			if ( isset( $icons[0]['url'] ) ) {
				return $icons[0]['url'];
			} else {
				return '';
			}
		}
	}

	public function get_icon() {
		return $this->client_icon;
	}

	/**
	 * @param array  $contents HTML to parse for rel links
	 * @param string $url URL to use to make absolute
	 * @return array $rels rel values as indices to properties, empty array if no rels at all
	 */
	public static function extract_client_data_from_html( $contents, $url ) {
		// unicode to HTML entities
		$contents = mb_convert_encoding( $contents, 'HTML-ENTITIES', mb_detect_encoding( $contents ) );
		libxml_use_internal_errors( true );
		$doc = new DOMDocument();
		$doc->loadHTML( $contents );
		$xpath  = new DOMXPath( $doc );
		$return = array();
		// check <link> and <a> elements
		foreach ( $xpath->query( '//a[@rel and @href] | //link[@rel and @href]' ) as $hyperlink ) {
			$rel  = $hyperlink->getAttribute( 'rel' );
			$temp = array();
			// Try to extract icons just in case there isn't a manifest
			switch ( $rel ) {
				case 'icon':
				case 'mask-icon':
				case 'shortcut icon':
				case 'apple-touch-icon-precomposed':
				case 'apple-touch-icon':
					$temp['url']   = WP_Http::make_absolute_url( $hyperlink->getAttribute( 'href' ), $url );
					$temp['sizes'] = $hyperlink->getAttribute( 'sizes' );
					$temp['type']  = $hyperlink->getAttribute( 'temp' );
					$temp          = array_filter( $temp );
					break;
				default:
					$temp = WP_Http::make_absolute_url( $hyperlink->getAttribute( 'href' ), $url );
			}
			if ( 'shortcut icon' === $rel ) {
				$rel = 'icon';
			}
			if ( isset( $return[ $rel ] ) ) {
				if ( is_array( $return[ $rel ] ) ) {
					$return[ $rel ] = $temp;
				}
				if ( is_string( $return[ $rel ] ) ) {
					$return[ $rel ]   = array( $return[ $rel ] );
					$return[ $rel ][] = $temp;
				}
			} else {
				$return[ $rel ] = $temp;
			}
		}
		// As a fallback also retrieve OpenGraph Title and Image Properties
		foreach ( $xpath->query( '//meta[@property and @content]' ) as $meta ) {
			$property = $meta->getAttribute( 'property' );
			if ( in_array( $property, array( 'og:title', 'og:image' ), true ) ) {
				$return[ $property ] = $meta->getAttribute( 'content' );
			}
		}
		$return['title'] = $xpath->query( '//title' )->item( 0 )->textContent;

		return $return;
	}

}
