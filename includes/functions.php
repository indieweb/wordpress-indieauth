<?php

/**
 * Finds Indieauth server URIs based on the given URL
 *
 * Checks the HTML for the rel="authorization_endpoint", rel="token_endpoint" link or headers. It does
 * a check for the headers first and returns that, if available
 *
 * @param string $me URL
 *
 * @return bool|array False on failure, array containing one or both or the headers on success
 */
function indieauth_discover_endpoint( $me ) {
	/** @todo Should use Filter Extension or custom preg_match instead. */
	$parsed_url = wp_parse_url( $me );
	if ( ! isset( $parsed_url['host'] ) ) { // Not an URL. This should never happen.
		return false;
	}
	// do not search for an Indieauth server on our own uploads
	$uploads_dir = wp_upload_dir();
	if ( 0 === strpos( $me, $uploads_dir['baseurl'] ) ) {
		return false;
	}
	$wp_version = get_bloginfo( 'version' );
	$user_agent = apply_filters( 'http_headers_useragent', 'WordPress/' . $wp_version . '; ' . get_bloginfo( 'url' ) );
	$args       = array(
		'timeout'             => 100,
		'limit_response_size' => 1048576,
		'redirection'         => 3,
		'user-agent'          => "$user_agent; finding Indieauth endpoints",
	);
	$response   = wp_safe_remote_head( $me, $args );
	if ( is_wp_error( $response ) ) {
		return false;
	}
	$return = array();
	$code   = (int) wp_remote_retrieve_response_code( $response );
	switch ( $code ) {
		case 301:
		case 308:
			$return['me'] = wp_remote_retrieve_header( $response, 'Location' );
			break;
	}
	if ( isset( $return['me'] ) ) {
		$me = $return['me'];
	}
	// check link header
	$links = wp_remote_retrieve_header( $response, 'link' );
	if ( $links ) {
		if ( is_array( $links ) ) {
			foreach ( $links as $link ) {
				if ( preg_match( '/<(.[^>]+)>;\s+rel\s?=\s?[\"\']?authorization_endpoint[\"\']?/i', $link, $result ) ) {
					$return['authorization_endpoint'] = WP_Http::make_absolute_url( $result[1], $me );
				}
				if ( preg_match( '/<(.[^>]+)>;\s+rel\s?=\s?[\"\']?token_endpoint[\"\']?/i', $link, $result ) ) {
					$return['token_endpoint'] = WP_Http::make_absolute_url( $result[1], $me );
				}
			}
		} else {
			if ( preg_match( '/<(.[^>]+)>;\s+rel\s?=\s?[\"\']?authorization_endpoint[\"\']?/i', $links, $result ) ) {
				$return['authorization_endpoint'] = WP_Http::make_absolute_url( $result[1], $me );
			}
			if ( preg_match( '/<(.[^>]+)>;\s+rel\s?=\s?[\"\']?token_endpoint[\"\']?/i', $links, $result ) ) {
				$return['token_endpoint'] = WP_Http::make_absolute_url( $result[1], $me );
			}
		}
		if ( isset( $return['token_endpoint'] ) && isset( $return['authorization_endpoint'] ) ) {
			return array_filter( $return );
		}
	}
	// not an (x)html, sgml, or xml page, no use going further
	if ( preg_match( '#(image|audio|video|model)/#is', wp_remote_retrieve_header( $response, 'content-type' ) ) ) {
		return false;
	}
	// now do a GET since we're going to look in the html headers (and we're sure its not a binary file)
	$response = wp_safe_remote_get( $me, $args );
	if ( is_wp_error( $response ) ) {
		return false;
	}
	$contents = wp_remote_retrieve_body( $response );
	// unicode to HTML entities
	$contents = mb_convert_encoding( $contents, 'HTML-ENTITIES', mb_detect_encoding( $contents ) );
	libxml_use_internal_errors( true );
	$doc = new DOMDocument();
	$doc->loadHTML( $contents );
	$xpath = new DOMXPath( $doc );
	// check <link> and <a> elements
	// checks only body>a-links
	foreach ( $xpath->query( '(//link|//a)[contains(concat(" ", @rel, " "), " authorization_endpoint ")]/@href' ) as $result ) {
		$return['authorization_endpoint'] = WP_Http::make_absolute_url( $result->value, $me );
	}
	foreach ( $xpath->query( '(//link|//a)[contains(concat(" ", @rel, " "), " token_endpoint ")]/@href' ) as $result ) {
		$return['token_endpoint'] = WP_Http::make_absolute_url( $result->value, $me );
	}
	if ( isset( $return['token_endpoint'] ) || isset( $return['authorization_endpoint'] ) ) {
		return array_filter( $return );
	}
	return false;
}
