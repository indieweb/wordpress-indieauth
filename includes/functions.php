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

/**
 * Get the user associated with the specified Identifier-URI.
 *
 * @param string $identifier identifier to match
 * @return WP_User $user Associated user, or null if no associated user
 */
function get_user_by_identifier( $identifier ) {
	if ( empty( $identifier ) ) {
		return null;
	}
	// try it without trailing slash
	$no_slash = untrailingslashit( $identifier );
	// Try to save the expense of a search query if the URL is the site URL
	if ( home_url() === $no_slash ) {
		// Use the Indieweb settings to set the default author
		if ( class_exists( 'Indieweb_Plugin' ) && get_option( 'iw_single_author' ) ) {
			return get_user_by( 'id', get_option( 'iw_default_author' ) );
		}

		// TODO: Add in search for whether there is only one author
	}
	$args       = array(
		'search'         => $no_slash,
		'search_columns' => array( 'user_url' ),
	);
	$user_query = new WP_User_Query( $args );
		// check result
	if ( ! empty( $user_query->results ) ) {
			return $user_query->results[0];
	}
	// try it with trailing slash
	$slash      = trailingslashit( $identifier );
	$args       = array(
		'search'         => $slash,
		'search_columns' => array( 'user_url' ),
	);
	$user_query = new WP_User_Query( $args );
	// check result
	if ( ! empty( $user_query->results ) ) {
		return $user_query->results[0];
	}
	// Check author page
	global $wp_rewrite;
	$link = $wp_rewrite->get_author_permastruct();
	if ( empty( $link ) ) {
		$login = str_replace( home_url( '/' ) . '?author=', '', $identifier );
	} else {
		$link  = str_replace( '%author%', '', $link );
		$link  = home_url( user_trailingslashit( $link ) );
		$login = str_replace( $link, '', $identifier );
	}
	if ( ! $login ) {
		return null;
	}
	$args       = array(
		'login' => $login,
	);
	$user_query = new WP_User_Query( $args );
	if ( ! empty( $user_query->results ) ) {
		return $user_query->results[0];
	}
	return null;
}

	/**
 * Returns if valid URL for REST validation
 *
 * @param string $url
 *
 * @return boolean
 */
function rest_is_valid_url( $url, $request = null, $key = null ) {
	if ( ! is_string( $url ) || empty( $url ) ) {
		return false;
	}
	return filter_var( $url, FILTER_VALIDATE_URL );
}

	/**
 * Generates a random string using the WordPress password for use as a token.
 *
 * @return string
 */
function indieauth_generate_token() {
	return wp_generate_password( 128, false );
}

	/**
 * Hashes and Base64 encodes a token for storage so it cannot be retrieved
 *
 * @param string $string
 *
 * @return string
 */
function indieauth_hash_token( $string ) {
	return base64_encode( wp_hash( $string, 'secure_auth' ) );
}

	/**
 * Get the token from meta
 *
 * @param string $key Prefix for the meta keys for this type of token
 * @param string $token Token to search for
 * @param boolean $hash If the token is already hashed or not
 * @return array|null Token, or null if no token found
 */
function get_indieauth_user_token( $key, $token, $hash = true ) {
	// Either token is already hashed or is not
	$key    .= $hash ? indieauth_hash_token( $token ) : $token;
	$args    = array(
		'number'      => 1,
		'count_total' => false,
		'meta_query'  => array(
			array(
				'key'     => $key,
				'compare' => 'EXISTS',
			),
		),
	);
	$query   = new WP_User_Query( $args );
	$results = $query->get_results();
	if ( empty( $results ) ) {
		return null;
	}
	$user  = $results[0];
	$value = get_user_meta( $user->ID, $key, true );
	if ( empty( $value ) ) {
		return null;
	}
	$value['user'] = $user->ID;
	return $value;
}

	/**
 * Get the token from meta
 *
 * @param num $id User ID
 * @param string $key Prefix for the meta keys for this type of token
 * @param string $token Token
 * @param array $token_data Actual contents of token
 * @return boolean if successful
 */
function set_indieauth_user_token( $id, $key, $token, $token_data ) {
	return add_user_meta( $id, $key . $token, $token_data );
}
