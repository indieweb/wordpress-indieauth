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
	// Ensure has trailing slash
	$identifier = trailingslashit( $identifier );
	// Try to save the expense of a search query if the URL is the site URL
	if ( home_url( '/' ) === $identifier ) {
		// Use the Indieweb settings to set the default author
		if ( class_exists( 'Indieweb_Plugin' ) && get_option( 'iw_single_author' ) ) {
			return get_user_by( 'id', get_option( 'iw_default_author' ) );
		}
		$users = get_users( array( 'role__not_in' => array( 'subscriber' ) ) );
		if ( 1 === count( $users ) ) {
			return $users[0];
		}
	}
	// Check if this is a author post URL
	$user = url_to_author( $identifier );
	if ( $user ) {
		return $user;
	}
	$args       = array(
		'search'         => $identifier,
		'search_columns' => array( 'user_url' ),
	);
	$user_query = new WP_User_Query( $args );
		// check result
	if ( ! empty( $user_query->results ) ) {
		return $user_query->results[0];
	}
	return null;
}

/**
 * Examine a url and try to determine the author ID it represents.
 *
 *
 * @param string $url Permalink to check.
 *
 * @return WP_User, or null on failure.
 */
function url_to_author( $url ) {
	global $wp_rewrite;
	// check if url hase the same host
	if ( wp_parse_url( site_url(), PHP_URL_HOST ) !== wp_parse_url( $url, PHP_URL_HOST ) ) {
		return null;
	}
	// first, check to see if there is a 'author=N' to match against
	if ( preg_match( '/[?&]author=(\d+)/i', $url, $values ) ) {
		$id = absint( $values[1] );
		if ( $id ) {
			return $id;
		}
	}
	// check to see if we are using rewrite rules
	$rewrite = $wp_rewrite->wp_rewrite_rules();
	// not using rewrite rules, and 'author=N' method failed, so we're out of options
	if ( empty( $rewrite ) ) {
		return null;
	}
	// generate rewrite rule for the author url
	$author_rewrite = $wp_rewrite->get_author_permastruct();
	$author_regexp  = str_replace( '%author%', '', $author_rewrite );
	// match the rewrite rule with the passed url
	if ( preg_match( '/https?:\/\/(.+)' . preg_quote( $author_regexp, '/' ) . '([^\/]+)/i', $url, $match ) ) {
		$user = get_user_by( 'slug', $match[2] );
		if ( $user ) {
			return $user->ID;
		}
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

function get_indieauth_authorization_endpoint() {
	$option = get_option( 'indieauth_config' );
	switch ( $option ) {
		case 'indieauth':
			return 'https://indieauth.com/auth';
		case 'custom':
			$return = get_option( 'indieauth_authorization_endpoint', rest_url( '/indieauth/1.0/auth' ) );
			return empty( $return ) ? rest_url( '/indieauth/1.0/auth' ) : $return;
		default:
			return rest_url( '/indieauth/1.0/auth' );
	}
}

function get_indieauth_token_endpoint() {
	$option = get_option( 'indieauth_config' );
	switch ( $option ) {
		case 'indieauth':
			return 'https://tokens.indieauth.com/token';
		case 'custom':
			$return = get_option( 'indieauth_token_endpoint', rest_url( '/indieauth/1.0/token' ) );
			return empty( $return ) ? rest_url( '/indieauth/1.0/token' ) : $return;
		default:
			return rest_url( '/indieauth/1.0/token' );
	}
}

