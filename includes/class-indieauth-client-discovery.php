<?php

class IndieAuth_Client_Discovery {
	public $html     = array();
	public $manifest = array();

	public function __construct( $client_id ) {
		$this->html = self::parse( $client_id );
		if ( isset( $this->html['manifest'] ) ) {
			$this->manifest = self::get_manifest( $this->html['manifest'] );
		}
	}

	public function fetch( $url ) {
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

	public function parse( $url ) {
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

	public function get_manifest( $url ) {
		$response = self::fetch( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return json_decode( wp_remote_retrieve_body( $response ) );
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
				case 'shortcut icon':
				case 'apple-touch-icon-precomposed':
				case 'apple-touch-icon':
					$temp['url']   = WP_Http::make_absolute_url( $hyperlink->getAttribute( 'href' ), $url );
					$temp['sizes'] = $hyperlink->getAttribute( 'sizes' );
					$temp['type']  = $hyperlink->getAttribute( 'temp' );
					$temp = array_filter( $temp );
					break;
				default:
					$temp = WP_Http::make_absolute_url( $hyperlink->getAttribute( 'href' ), $url );
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
		return $return;
	}

}
