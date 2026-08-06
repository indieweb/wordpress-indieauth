<?php
/**
 * Global Functions for IndieAuth.
 *
 * @package IndieAuth
 */

namespace IndieAuth;

if ( ! function_exists( 'IndieAuth\parse_link_rels' ) ) {
	/**
	 * Parse link headers for rel values.
	 *
	 * @param array  $links Link headers as a string.
	 * @param string $url   URL to use to make absolute.
	 * @return array Rel values as indices to arrays of URLs, empty array if no rels at all.
	 */
	function parse_link_rels( $links, $url ) {
		$rels = array();
		foreach ( $links as $link ) {
			$hrefandrel = explode( '; ', $link );
			$href       = trim( $hrefandrel[0], '<>' );
			$relarray   = '';
			foreach ( $hrefandrel as $p ) {
				if ( ! strncmp( $p, 'rel=', 4 ) ) {
					$relarray = explode( ' ', trim( substr( $p, 4 ), '"\'' ) );
					break;
				}
			}
			if ( ! empty( $relarray ) ) { // Ignore Link: headers without rel.
				foreach ( $relarray as $rel ) {
					$rel = strtolower( trim( $rel ) );
					if ( ! empty( $rel ) ) {
						$rels[ $rel ] = \WP_Http::make_absolute_url( $href, $url );
					}
				}
			}
		}
		return $rels;
	}
}

if ( ! function_exists( 'IndieAuth\find_rels' ) ) {
	/**
	 * Finds rels on the given URL.
	 *
	 * Checks for specific rel properties in a URL. It does
	 * a check for the headers first and returns that, if available.
	 *
	 * @param string       $me        URL.
	 * @param string|array $endpoints Specific endpoints to search for.
	 * @return bool|array|string False on failure, array containing one or both or the headers on success or string if single property.
	 */
	function find_rels( $me, $endpoints = null ) {
		if ( ! $endpoints ) {
			$endpoints = array( 'indieauth-metadata', 'authorization_endpoint', 'token_endpoint', 'me' );
		}
		if ( ! \wp_http_validate_url( $me ) ) { // Not an URL. This should never happen.
			return false;
		}
		// Do not search for an Indieauth server on our own uploads.
		$uploads_dir = \wp_upload_dir();
		if ( 0 === strpos( $me, $uploads_dir['baseurl'] ) ) {
			return false;
		}
		$wp_version = \get_bloginfo( 'version' );
		$user_agent = \apply_filters( 'http_headers_useragent', 'WordPress/' . $wp_version . '; ' . \get_bloginfo( 'url' ) );
		$args       = array(
			'timeout'             => 100,
			'limit_response_size' => 1048576,
			'redirection'         => 3,
			'user-agent'          => "$user_agent; finding rel properties",
		);
		$response   = \wp_safe_remote_get( $me, $args );
		if ( \is_wp_error( $response ) ) {
			return $response;
		}
		$rels = array();
		// Check link header.
		$links = \wp_remote_retrieve_header( $response, 'link' );
		if ( $links ) {
			if ( is_string( $links ) ) {
				$links = array( $links );
			}
			$rels = parse_link_rels( $links, $me );
		}

		$code = (int) \wp_remote_retrieve_response_code( $response );
		switch ( $code ) {
			case 301:
			case 308:
				$rels['me'] = \wp_remote_retrieve_header( $response, 'Location' );
				break;
		}
		if ( isset( $rels['me'] ) ) {
			$me = $rels['me'];
		}

		// Not an (x)html, sgml, or xml page, no use going further.
		if ( ! preg_match( '#(image|audio|video|model)/#is', \wp_remote_retrieve_header( $response, 'content-type' ) ) ) {
			$contents = \wp_remote_retrieve_body( $response );
			$rels     = array_merge( $rels, parse_html_rels( $contents, $me ) );
		}
		if ( is_array( $endpoints ) ) {
			$endpoints[] = 'me';
			$rels        = \wp_array_slice_assoc( $rels, $endpoints );
			if ( ! empty( $rels ) ) {
				return $rels;
			}
		} elseif ( is_string( $endpoints ) && isset( $rels[ $endpoints ] ) ) {
			return $rels[ $endpoints ];
		}
		return false;
	}
}

if ( ! function_exists( 'IndieAuth\parse_html_rels' ) ) {
	/**
	 * Parse HTML for rel links.
	 *
	 * @param string $contents HTML to parse for rel links.
	 * @param string $url      URL to use to make absolute.
	 * @return array Rel values as indices to arrays of URLs, empty array if no rels at all.
	 */
	function parse_html_rels( $contents, $url ) {
		// Unicode to HTML entities.
		$contents = mb_convert_encoding( $contents, 'HTML-ENTITIES', mb_detect_encoding( $contents ) );
		libxml_use_internal_errors( true );
		$doc = new \DOMDocument();
		$doc->loadHTML( $contents );
		$xpath   = new \DOMXPath( $doc );
		$results = array();
		// Check <link> and <a> elements.
		foreach ( $xpath->query( '//a[@rel and @href] | //link[@rel and @href]' ) as $hyperlink ) {
			$results[ $hyperlink->getAttribute( 'rel' ) ] = \WP_Http::make_absolute_url( $hyperlink->getAttribute( 'href' ), $url );
		}
		return $results;
	}
}

if ( ! function_exists( 'IndieAuth\get_single_author' ) ) {
	/**
	 * Uses the code from is_multi_author to determine the identity of the single author.
	 *
	 * @return false|int User ID of the single author if exists.
	 */
	function get_single_author() {
		global $wpdb;
		$single_author = \get_transient( 'single_author' );
		if ( false === $single_author ) {
			$rows          = (array) $wpdb->get_col( "SELECT DISTINCT post_author FROM $wpdb->posts WHERE post_type = 'post' AND post_status = 'publish' LIMIT 2" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$single_author = 1 === count( $rows ) ? (int) $rows[0] : false;
			\set_transient( 'single_author', $single_author );
		}
		return $single_author;
	}
}

if ( ! function_exists( 'IndieAuth\get_user_by_identifier' ) ) {
	/**
	 * Get the user associated with the specified Identifier-URI.
	 *
	 * @param string $identifier Identifier to match.
	 * @return \WP_User|null Associated user, or null if no associated user.
	 */
	function get_user_by_identifier( $identifier ) {
		// Refuse to validate empty or invalid user identifiers.
		if ( empty( $identifier ) || ! indieauth_validate_user_identifier( $identifier ) ) {
			return null;
		}

		$identifier = normalize_url( $identifier );
		if ( ( 'https' === \wp_parse_url( \home_url(), PHP_URL_SCHEME ) ) && ( \wp_parse_url( \home_url(), PHP_URL_HOST ) === \wp_parse_url( $identifier, PHP_URL_HOST ) ) ) {
			$identifier = \set_url_scheme( $identifier, 'https' );
		}
		// Try to save the expense of a search query if the URL is the site URL.
		if ( \home_url( '/' ) === $identifier ) {
			// Use the settings to set the root user.
			if ( 0 !== indieauth_get_root_user() ) {
				return \get_user_by( 'id', (int) \get_option( 'indieauth_root_user' ) );
			}
		}

		// Check if this is a author post URL.
		$user = url_to_author( $identifier );
		if ( $user instanceof \WP_User ) {
			return $user;
		}

		$args = array(
			'search'         => $identifier,
			'search_columns' => array( 'user_url' ),
		);

		$users = \get_users( $args );
		// Check result.
		if ( is_countable( $users ) && 1 === count( $users ) ) {
			return $users[0];
		}
		return null;
	}
}

if ( ! function_exists( 'IndieAuth\get_url_from_user' ) ) {
	/**
	 * Tries to make some decisions about what URL to return for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string|null URL or null.
	 */
	function get_url_from_user( $user_id ) {
		if ( (int) indieauth_get_root_user() === $user_id ) {
			return \home_url( '/' );
		}
		if ( ! $user_id ) {
			return null;
		}
		return \get_author_posts_url( $user_id );
	}
}

if ( ! function_exists( 'IndieAuth\url_to_author' ) ) {
	/**
	 * Examine a url and try to determine the author ID it represents.
	 *
	 * @param string $url Permalink to check.
	 * @return \WP_User|null User or null on failure.
	 */
	function url_to_author( $url ) {
		global $wp_rewrite;
		// Check if url has the same host.
		if ( \wp_parse_url( \site_url(), PHP_URL_HOST ) !== \wp_parse_url( $url, PHP_URL_HOST ) ) {
			return null;
		}
		// First, check to see if there is a 'author=N' to match against.
		if ( preg_match( '/[?&]author=(\d+)/i', $url, $values ) ) {
			$id = \absint( $values[1] );
			if ( $id ) {
				return \get_user_by( 'id', $id );
			}
		}
		// Check to see if we are using rewrite rules.
		$rewrite = $wp_rewrite->wp_rewrite_rules();
		// Not using rewrite rules, and 'author=N' method failed, so we're out of options.
		if ( empty( $rewrite ) ) {
			return null;
		}
		// Generate rewrite rule for the author url.
		$author_rewrite = $wp_rewrite->get_author_permastruct();
		$author_regexp  = str_replace( '%author%', '', $author_rewrite );
		// Match the rewrite rule with the passed url.
		if ( preg_match( '/https?:\/\/(.+)' . preg_quote( $author_regexp, '/' ) . '([^\/]+)/i', $url, $match ) ) {
			$user = \get_user_by( 'slug', $match[2] );
			if ( $user ) {
				return $user;
			}
		}
		return null;
	}
}

/**
 * Returns if valid URL for REST validation.
 *
 * @param string                $url     URL to validate.
 * @param \WP_REST_Request|null $request REST request object.
 * @param string|null           $key     Parameter key.
 * @return bool Whether URL is valid.
 */
function rest_is_valid_url( $url, $request = null, $key = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
	if ( ! is_string( $url ) || empty( $url ) ) {
		return false;
	}
	return \filter_var( $url, FILTER_VALIDATE_URL );
}

/**
 * Get REST URL that works even when called early.
 *
 * @param string $path Optional path to append.
 * @return string REST URL.
 */
function indieauth_rest_url( $path = '' ) {
	// rest_url is being called too early for wp_rewrite to be set.
	// This fallback checks and returns the non rewritten version.
	global $wp_rewrite;
	if ( ! $wp_rewrite ) {
		return \home_url( 'index.php?rest_route=' . $path );
	}
	return \rest_url( $path );
}

// @see https://github.com/ralouphie/getallheaders.
if ( ! function_exists( 'getallheaders' ) ) {

	/**
	 * Get all HTTP header key/values as an associative array for the current request.
	 *
	 * @return array The HTTP header key/value pairs.
	 */
	function getallheaders() {
		$headers = array();

		$copy_server = array(
			'CONTENT_TYPE'   => 'Content-Type',
			'CONTENT_LENGTH' => 'Content-Length',
			'CONTENT_MD5'    => 'Content-Md5',
		);

		foreach ( $_SERVER as $key => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( substr( $key, 0, 5 ) === 'HTTP_' ) {
				$key = substr( $key, 5 );
				if ( ! isset( $copy_server[ $key ] ) || ! isset( $_SERVER[ $key ] ) ) {
					$key             = str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', $key ) ) ) );
					$headers[ $key ] = $value;
				}
			} elseif ( isset( $copy_server[ $key ] ) ) {
				$headers[ $copy_server[ $key ] ] = $value;
			}
		}

		if ( ! isset( $headers['Authorization'] ) ) {
			if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
				$headers['Authorization'] = \wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			} elseif ( isset( $_SERVER['PHP_AUTH_USER'] ) ) {
				$basic_pass               = isset( $_SERVER['PHP_AUTH_PW'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['PHP_AUTH_PW'] ) ) : '';
				$headers['Authorization'] = 'Basic ' . base64_encode( \sanitize_text_field( \wp_unslash( $_SERVER['PHP_AUTH_USER'] ) ) . ':' . $basic_pass ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			} elseif ( isset( $_SERVER['PHP_AUTH_DIGEST'] ) ) {
				$headers['Authorization'] = \sanitize_text_field( \wp_unslash( $_SERVER['PHP_AUTH_DIGEST'] ) );
			}
		}

		return $headers;
	}
}

if ( ! function_exists( 'IndieAuth\add_query_params_to_url' ) ) {
	/**
	 * Add query strings to an URL.
	 *
	 * Slightly modified from p3k-utils (https://github.com/aaronpk/p3k-utils)
	 * Copyright 2017 Aaron Parecki, used with permission under MIT License.
	 *
	 * @param array  $args The query stings as array.
	 * @param string $url  The final URL.
	 * @return string URL with query parameters.
	 */
	function add_query_params_to_url( $args, $url ) {
		$parts = \wp_parse_url( $url );
		if ( array_key_exists( 'query', $parts ) && $parts['query'] ) {
			parse_str( $parts['query'], $params );
		} else {
			$params = array();
		}
		foreach ( $args as $k => $v ) {
			$params[ $k ] = $v;
		}
		$parts['query'] = http_build_query( $params );

		return build_url( $parts );
	}
}

if ( ! function_exists( 'IndieAuth\build_url' ) ) {
	/**
	 * Inverse of parse_url.
	 *
	 * Slightly modified from p3k-utils (https://github.com/aaronpk/p3k-utils)
	 * Copyright 2017 Aaron Parecki, used with permission under MIT License.
	 *
	 * @link http://php.net/parse_url
	 * @param array $parsed_url The parsed URL (wp_parse_url).
	 * @return string The final URL.
	 */
	function build_url( $parsed_url ) {
		$scheme   = ! empty( $parsed_url['scheme'] ) ? $parsed_url['scheme'] . '://' : '';
		$host     = ! empty( $parsed_url['host'] ) ? $parsed_url['host'] : '';
		$port     = ! empty( $parsed_url['port'] ) ? ':' . $parsed_url['port'] : '';
		$user     = ! empty( $parsed_url['user'] ) ? $parsed_url['user'] : '';
		$pass     = ! empty( $parsed_url['pass'] ) ? ':' . $parsed_url['pass'] : '';
		$pass     = ( $user || $pass ) ? "$pass@" : '';
		$path     = ! empty( $parsed_url['path'] ) ? $parsed_url['path'] : '';
		$query    = ! empty( $parsed_url['query'] ) ? '?' . $parsed_url['query'] : '';
		$fragment = ! empty( $parsed_url['fragment'] ) ? '#' . $parsed_url['fragment'] : '';

		return "$scheme$user$pass$host$port$path$query$fragment";
	}
}

if ( ! function_exists( 'IndieAuth\same_origin' ) ) {
	/**
	 * Check whether two URLs share the same origin.
	 *
	 * The origin is the scheme, host and port; a missing port counts as the
	 * default port for the scheme.
	 *
	 * @param string $url1 First URL.
	 * @param string $url2 Second URL.
	 * @return bool Whether both URLs have the same origin.
	 */
	function same_origin( $url1, $url2 ) {
		$parts1 = \wp_parse_url( $url1 );
		$parts2 = \wp_parse_url( $url2 );

		if ( ! isset( $parts1['scheme'], $parts1['host'], $parts2['scheme'], $parts2['host'] ) ) {
			return false;
		}

		// Schemes and hosts are case-insensitive.
		$scheme1 = strtolower( $parts1['scheme'] );
		$scheme2 = strtolower( $parts2['scheme'] );

		if ( $scheme1 !== $scheme2 || strtolower( $parts1['host'] ) !== strtolower( $parts2['host'] ) ) {
			return false;
		}

		$defaults     = array(
			'http'  => 80,
			'https' => 443,
		);
		$default_port = isset( $defaults[ $scheme1 ] ) ? $defaults[ $scheme1 ] : null;
		$port1        = isset( $parts1['port'] ) ? (int) $parts1['port'] : $default_port;
		$port2        = isset( $parts2['port'] ) ? (int) $parts2['port'] : $default_port;

		return $port1 === $port2;
	}
}

if ( ! function_exists( 'IndieAuth\add_deprecation_headers' ) ) {
	/**
	 * Add deprecation headers to a REST response.
	 *
	 * Used by endpoints that still accept behavior removed from the IndieAuth
	 * specification.
	 *
	 * @param \WP_REST_Response $response The response object.
	 * @return \WP_REST_Response The response with deprecation headers added.
	 */
	function add_deprecation_headers( $response ) {
		$response->header( 'Deprecation', 'true' );
		// Append, so an existing Link header is not dropped.
		$response->header( 'Link', '<https://github.com/indieweb/wordpress-indieauth/issues/294>; rel="deprecation"', false );

		return $response;
	}
}

if ( ! function_exists( 'IndieAuth\normalize_url' ) ) {
	/**
	 * Normalize a URL by adding slash if no path and converting hostname to lowercase.
	 *
	 * @param string $url       URL to normalize.
	 * @param bool   $force_ssl Whether to force SSL.
	 * @return string|false Normalized URL or false.
	 */
	function normalize_url( $url, $force_ssl = false ) {
		$parts = \wp_parse_url( $url );
		if ( array_key_exists( 'path', $parts ) && '' === $parts['path'] ) {
			return false;
		}
		// wp_parse_url returns just "path" for naked domains.
		if ( count( $parts ) === 1 && array_key_exists( 'path', $parts ) ) {
			$parts['host'] = $parts['path'];
			unset( $parts['path'] );
		}
		if ( ! array_key_exists( 'scheme', $parts ) ) {
			$parts['scheme'] = $force_ssl ? 'https' : 'http';
		} elseif ( $force_ssl ) {
			$parts['scheme'] = 'https';
		}
		if ( ! array_key_exists( 'path', $parts ) ) {
			$parts['path'] = '/';
		}
		// Invalid scheme.
		if ( ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) {
			return false;
		}
		return build_url( $parts );
	}
}

/**
 * Get Scope.
 *
 * @return array|null Array of Scopes or Null if Not Added at all.
 */
function indieauth_get_scopes() {
	return \apply_filters( 'indieauth_scopes', null );
}

/**
 * Check Scope.
 *
 * @param string $scope Scope to check.
 * @return bool|null Whether scope is present.
 */
function indieauth_check_scope( $scope ) {
	$scopes = indieauth_get_scopes();
	if ( is_null( $scopes ) ) {
		return null;
	}
	return in_array( $scope, $scopes, true );
}

/**
 * Get Auth Response.
 *
 * @return array|null Array with Response Token from IndieAuth endpoint.
 */
function indieauth_get_response() {
	return \apply_filters( 'indieauth_response', null );
}

/**
 * Get Client ID.
 *
 * @return string|null Client ID.
 */
function indieauth_get_client_id() {
	$response = indieauth_get_response();
	if ( is_null( $response ) || ! isset( $response['client_id'] ) ) {
		return null;
	}
	return $response['client_id'];
}

/**
 * Get Client Data.
 *
 * @return array|\WP_Error|null Client data or null.
 */
function indieauth_get_client_data() {
	$response = indieauth_get_response();
	if ( is_null( $response ) || ! isset( $response['client_id'] ) ) {
		return null;
	}
	return Client_Taxonomy::get_client( $response['client_id'] );
}

/**
 * Get Me.
 *
 * @return string|null The Me property for the current session.
 */
function indieauth_get_me() {
	$response = indieauth_get_response();
	if ( is_null( $response ) || ! isset( $response['me'] ) ) {
		return null;
	}
	return $response['me'];
}

/**
 * Hash data using SHA256.
 *
 * @param string $data Data to hash.
 * @return string Hashed data.
 */
function indieauth_hash( $data ) {
	return hash( 'sha256', $data, true );
}

/**
 * Verify PKCE code challenge.
 *
 * @param string $code_challenge Code challenge.
 * @param string $code_verifier  Code verifier.
 * @param string $method         Challenge method.
 * @return bool Whether verification passed.
 */
function pkce_verifier( $code_challenge, $code_verifier, $method ) {
	if ( 'S256' === $method ) {
		$code_verifier = base64_urlencode( indieauth_hash( $code_verifier ) );
	}
	return ( 0 === strcmp( $code_challenge, $code_verifier ) );
}

/**
 * URL-safe base64 encode.
 *
 * @param string $data String to encode.
 * @return string Encoded string.
 */
function base64_urlencode( $data ) {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

/**
 * Returns IndieAuth profile user data.
 *
 * @param int|\WP_User $user  User.
 * @param bool         $email Whether to return email or not.
 * @return array User information or empty if none.
 */
function indieauth_get_user( $user, $email = false ) {
	if ( is_numeric( $user ) ) {
		$user = \get_user_by( 'ID', $user );
	}
	if ( ! $user instanceof \WP_User ) {
		return array();
	}
	$return = array(
		'name'  => $user->display_name,
		'url'   => empty( $user->user_url ) ? \get_author_posts_url( $user->ID ) : $user->user_url,
		'photo' => \get_avatar_url(
			$user->ID,
			array(
				'size'    => 125,
				'default' => '404',
			)
		),
		'email' => $email ? $user->user_email : false,
	);
	return array_filter( $return );
}

/**
 * Get the root user for IndieAuth.
 *
 * @return int User ID or 0.
 */
function indieauth_get_root_user() {
	$default = \get_option( 'indieauth_root_user', null );
	// Null is only returned if the setting does not exist.
	if ( ! is_null( $default ) ) {
		return $default;
	}
	$default = \get_option( 'iw_default_author', null );
	if ( $default ) {
		return $default;
	}
	$users = \get_users(
		array(
			'fields' => 'ID',
		)
	);

	// If the setting is not set then default it to a single user. This can be overridden if it is set to None in the settings.
	if ( 1 === count( $users ) ) {
		\update_option( 'indieauth_root_user', $users[0] );
	}
	// If there is more than one user, but multiple authors you cannot tell who the prime user is.
	$single = get_single_author();
	if ( ! $single ) {
		return 0;
	}

	\update_option( 'indieauth_root_user', $single );
	return $single;
}

/**
 * Get the metadata endpoint URL.
 *
 * @return string Metadata endpoint URL.
 */
function indieauth_get_metadata_endpoint() {
	return IndieAuth::$metadata->get_endpoint();
}

/**
 * Get the issuer URL.
 *
 * @return string Issuer URL.
 */
function indieauth_get_issuer() {
	return IndieAuth::$metadata->get_issuer();
}

/**
 * Validate a User Identifier.
 *
 * @param string $url User Identifier URL.
 * @return string|false URL or false on failure.
 */
function indieauth_validate_user_identifier( $url ) {
	if ( ! is_string( $url ) || '' === $url || is_numeric( $url ) ) {
		return false;
	}

	$url = \trailingslashit( $url );

	if ( ! $url ) {
		return false;
	}

	$parsed_url = \wp_parse_url( $url );

	if ( ! $parsed_url || empty( $parsed_url['host'] ) || ! in_array( $parsed_url['scheme'], array( 'http', 'https' ), true ) ) {
		return false;
	}

	if ( isset( $parsed_url['user'] ) || isset( $parsed_url['pass'] ) || isset( $parsed_url['fragment'] ) || isset( $parsed_url['port'] ) ) {
		return false;
	}

	// Path has single-dot or double-dot segments; not allowed.
	$paths = explode( '/', $parsed_url['path'] );
	if ( array_intersect( $paths, array( '.', '..' ) ) ) {
		return false;
	}

	// If this is an IP address it is not permitted.
	$ip = \filter_var( $parsed_url['host'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 );
	if ( $ip ) {
		return false;
	}

	return $url;
}

/**
 * Validate a Client Identifier URL.
 *
 * @param string $url Client Identifier URL.
 * @return string|false URL or false on failure.
 */
function indieauth_validate_client_identifier( $url ) {
	if ( ! is_string( $url ) || '' === $url || is_numeric( $url ) ) {
		return false;
	}

	$url = \trailingslashit( $url );

	if ( ! $url ) {
		return false;
	}

	$parsed_url = \wp_parse_url( $url );
	if ( ! $parsed_url || empty( $parsed_url['host'] ) ) {
		return false;
	}

	if ( isset( $parsed_url['user'] ) || isset( $parsed_url['pass'] ) || isset( $parsed_url['fragment'] ) ) {
		return false;
	}

	// Path has single-dot or double-dot segments; not allowed.
	$paths = explode( '/', $parsed_url['path'] );
	if ( array_intersect( $paths, array( '.', '..' ) ) ) {
		return false;
	}

	// Validate that if this is an IP address it is one of the approved IPs.
	$ip      = \filter_var( $parsed_url['host'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 );
	$allowed = array(
		'127.0.0.1',
		'0000:0000:0000:0000:0000:0000:0000:0001',
		'::1',
	);

	if ( $ip && ! in_array( $ip, $allowed, true ) ) {
		return false;
	}

	return $url;
}

/**
 * Validate an Issuer Identifier.
 *
 * @param string $url Issuer Identifier URL.
 * @return string|false URL or false on failure.
 */
function indieauth_validate_issuer_identifier( $url ) {
	if ( ! is_string( $url ) || '' === $url || is_numeric( $url ) ) {
		return false;
	}

	$url = \trailingslashit( $url );

	if ( ! $url ) {
		return false;
	}

	$parsed_url = \wp_parse_url( $url );

	// Issuer Identifiers MUST be https.
	if ( ! isset( $parsed_url['scheme'] ) || 'https' !== $parsed_url['scheme'] ) {
		return false;
	}

	if ( ! $parsed_url || empty( $parsed_url['host'] ) ) {
		return false;
	}

	// Path has single-dot or double-dot segments; not allowed.
	$paths = explode( '/', $parsed_url['path'] );
	if ( array_intersect( $paths, array( '.', '..' ) ) ) {
		return false;
	}

	if ( isset( $parsed_url['user'] ) || isset( $parsed_url['pass'] ) || isset( $parsed_url['fragment'] ) || isset( $parsed_url['query'] ) ) {
		return false;
	}

	return $url;
}

/**
 * Get the OAuth error from a response.
 *
 * Checks if a response is a WP_Error or contains an OAuth error
 * in the response body, and returns an OAuth_Response if so.
 *
 * @param mixed $obj Response object to check.
 * @return OAuth_Response|false OAuth_Response on error, false otherwise.
 */
function get_oauth_error( $obj ) {
	if ( $obj instanceof OAuth_Response ) {
		return $obj;
	}
	if ( \is_wp_error( $obj ) ) {
		return wp_error_to_oauth_response( $obj );
	}
	if ( is_array( $obj ) ) {
		$body = \wp_remote_retrieve_body( $obj );
		if ( $body ) {
			$body = json_decode( $body, true );
			if ( is_array( $body ) && isset( $body['error'] ) ) {
				$code = (int) \wp_remote_retrieve_response_code( $obj );
				return new OAuth_Response(
					$body['error'],
					isset( $body['error_description'] ) ? $body['error_description'] : '',
					$code ? $code : 400
				);
			}
		}
	}
	return false;
}

/**
 * Check if an object is an OAuth error response.
 *
 * @param mixed $obj Object to check.
 * @return bool Whether the object is an OAuth_Response.
 */
function is_oauth_error( $obj ) {
	return $obj instanceof OAuth_Response;
}

/**
 * Convert a WP_Error to an OAuth_Response.
 *
 * @param \WP_Error $error WP_Error to convert.
 * @return OAuth_Response OAuth error response.
 */
function wp_error_to_oauth_response( $error ) {
	$data   = $error->get_error_data();
	$status = isset( $data['status'] ) ? $data['status'] : 400;

	return new OAuth_Response(
		$error->get_error_code(),
		$error->get_error_message(),
		$status
	);
}
