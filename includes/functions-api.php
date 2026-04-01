<?php
/**
 * Backward-compatible global API functions for IndieAuth.
 *
 * These functions maintain backward compatibility for external code
 * that calls the IndieAuth API functions without namespace.
 *
 * @package IndieAuth
 */

if ( ! function_exists( 'indieauth_get_scopes' ) ) {
	/**
	 * Get IndieAuth scopes.
	 *
	 * @return array|null Scopes or null.
	 */
	function indieauth_get_scopes() {
		return IndieAuth\indieauth_get_scopes();
	}
}

if ( ! function_exists( 'indieauth_check_scope' ) ) {
	/**
	 * Check if a scope is present.
	 *
	 * @param string $scope Scope to check.
	 * @return bool|null Whether scope is present.
	 */
	function indieauth_check_scope( $scope ) {
		return IndieAuth\indieauth_check_scope( $scope );
	}
}

if ( ! function_exists( 'indieauth_get_response' ) ) {
	/**
	 * Get IndieAuth response.
	 *
	 * @return array|null Response data or null.
	 */
	function indieauth_get_response() {
		return IndieAuth\indieauth_get_response();
	}
}

if ( ! function_exists( 'indieauth_get_client_id' ) ) {
	/**
	 * Get the current client ID.
	 *
	 * @return string|null Client ID or null.
	 */
	function indieauth_get_client_id() {
		return IndieAuth\indieauth_get_client_id();
	}
}

if ( ! function_exists( 'indieauth_get_client_data' ) ) {
	/**
	 * Get client taxonomy data.
	 *
	 * @return array|WP_Error|null Client data or null.
	 */
	function indieauth_get_client_data() {
		return IndieAuth\indieauth_get_client_data();
	}
}

if ( ! function_exists( 'indieauth_get_me' ) ) {
	/**
	 * Get the me property for the current session.
	 *
	 * @return string|null The me URL or null.
	 */
	function indieauth_get_me() {
		return IndieAuth\indieauth_get_me();
	}
}

if ( ! function_exists( 'indieauth_get_metadata_endpoint' ) ) {
	/**
	 * Get the metadata endpoint URL.
	 *
	 * @return string Metadata endpoint URL.
	 */
	function indieauth_get_metadata_endpoint() {
		return IndieAuth\indieauth_get_metadata_endpoint();
	}
}

if ( ! function_exists( 'indieauth_get_issuer' ) ) {
	/**
	 * Get the issuer URL.
	 *
	 * @return string Issuer URL.
	 */
	function indieauth_get_issuer() {
		return IndieAuth\indieauth_get_issuer();
	}
}

if ( ! function_exists( 'indieauth_get_root_user' ) ) {
	/**
	 * Get the root user for IndieAuth.
	 *
	 * @return int User ID or 0.
	 */
	function indieauth_get_root_user() {
		return IndieAuth\indieauth_get_root_user();
	}
}

if ( ! function_exists( 'indieauth_validate_user_identifier' ) ) {
	/**
	 * Validate a user identifier URL.
	 *
	 * @param string $url User identifier URL.
	 * @return string|false URL or false on failure.
	 */
	function indieauth_validate_user_identifier( $url ) {
		return IndieAuth\indieauth_validate_user_identifier( $url );
	}
}

if ( ! function_exists( 'indieauth_validate_client_identifier' ) ) {
	/**
	 * Validate a client identifier URL.
	 *
	 * @param string $url Client identifier URL.
	 * @return string|false URL or false on failure.
	 */
	function indieauth_validate_client_identifier( $url ) {
		return IndieAuth\indieauth_validate_client_identifier( $url );
	}
}

if ( ! function_exists( 'indieauth_validate_issuer_identifier' ) ) {
	/**
	 * Validate an issuer identifier URL.
	 *
	 * @param string $url Issuer identifier URL.
	 * @return string|false URL or false on failure.
	 */
	function indieauth_validate_issuer_identifier( $url ) {
		return IndieAuth\indieauth_validate_issuer_identifier( $url );
	}
}

if ( ! function_exists( 'indieauth_get_user' ) ) {
	/**
	 * Get IndieAuth user profile data.
	 *
	 * @param int|WP_User $user  User.
	 * @param bool        $email Whether to include email.
	 * @return array User data.
	 */
	function indieauth_get_user( $user, $email = false ) {
		return IndieAuth\indieauth_get_user( $user, $email );
	}
}

if ( ! function_exists( 'indieauth_hash' ) ) {
	/**
	 * Hash data using SHA256.
	 *
	 * @param string $data Data to hash.
	 * @return string Hashed data.
	 */
	function indieauth_hash( $data ) {
		return IndieAuth\indieauth_hash( $data );
	}
}

if ( ! function_exists( 'get_user_by_identifier' ) ) {
	/**
	 * Get user by identifier URL.
	 *
	 * @param string $identifier Identifier to match.
	 * @return WP_User|null Associated user or null.
	 */
	function get_user_by_identifier( $identifier ) {
		return IndieAuth\get_user_by_identifier( $identifier );
	}
}

if ( ! function_exists( 'get_url_from_user' ) ) {
	/**
	 * Get URL from user ID.
	 *
	 * @param int $user_id User ID.
	 * @return string|null URL or null.
	 */
	function get_url_from_user( $user_id ) {
		return IndieAuth\get_url_from_user( $user_id );
	}
}

if ( ! function_exists( 'find_rels' ) ) {
	/**
	 * Find rels on a given URL.
	 *
	 * @param string       $me        URL.
	 * @param string|array $endpoints Endpoints to search for.
	 * @return bool|array|string Results or false.
	 */
	function find_rels( $me, $endpoints = null ) {
		return IndieAuth\find_rels( $me, $endpoints );
	}
}

if ( ! function_exists( 'url_to_author' ) ) {
	/**
	 * Try to determine the author from a URL.
	 *
	 * @param string $url Permalink to check.
	 * @return WP_User|null User or null.
	 */
	function url_to_author( $url ) {
		return IndieAuth\url_to_author( $url );
	}
}

if ( ! function_exists( 'pkce_verifier' ) ) {
	/**
	 * Verify PKCE code challenge.
	 *
	 * @param string $code_challenge Code challenge.
	 * @param string $code_verifier  Code verifier.
	 * @param string $method         Challenge method.
	 * @return bool Whether verification passed.
	 */
	function pkce_verifier( $code_challenge, $code_verifier, $method ) {
		return IndieAuth\pkce_verifier( $code_challenge, $code_verifier, $method );
	}
}

if ( ! function_exists( 'base64_urlencode' ) ) {
	/**
	 * URL-safe base64 encode.
	 *
	 * @param string $data String to encode.
	 * @return string Encoded string.
	 */
	function base64_urlencode( $data ) {
		return IndieAuth\base64_urlencode( $data );
	}
}

if ( ! function_exists( 'normalize_url' ) ) {
	/**
	 * Normalize a URL.
	 *
	 * @param string $url       URL to normalize.
	 * @param bool   $force_ssl Whether to force SSL.
	 * @return string|false Normalized URL or false.
	 */
	function normalize_url( $url, $force_ssl = false ) {
		return IndieAuth\normalize_url( $url, $force_ssl );
	}
}

if ( ! function_exists( 'add_query_params_to_url' ) ) {
	/**
	 * Add query parameters to a URL.
	 *
	 * @param array  $args Query parameters.
	 * @param string $url  URL.
	 * @return string URL with parameters.
	 */
	function add_query_params_to_url( $args, $url ) {
		return IndieAuth\add_query_params_to_url( $args, $url );
	}
}

if ( ! function_exists( 'build_url' ) ) {
	/**
	 * Inverse of parse_url.
	 *
	 * @param array $parsed_url Parsed URL components.
	 * @return string URL.
	 */
	function build_url( $parsed_url ) {
		return IndieAuth\build_url( $parsed_url );
	}
}

if ( ! function_exists( 'rest_is_valid_url' ) ) {
	/**
	 * Validate URL for REST API.
	 *
	 * @param string $url URL to validate.
	 * @return bool Whether valid.
	 */
	function rest_is_valid_url( $url ) {
		return IndieAuth\rest_is_valid_url( $url );
	}
}

if ( ! function_exists( 'get_oauth_error' ) ) {
	/**
	 * Get OAuth error from response.
	 *
	 * @param mixed $obj Response object or array.
	 * @return IndieAuth\OAuth_Response|false OAuth response or false.
	 */
	function get_oauth_error( $obj ) {
		return IndieAuth\get_oauth_error( $obj );
	}
}

if ( ! function_exists( 'is_oauth_error' ) ) {
	/**
	 * Check if object is an OAuth error.
	 *
	 * @param mixed $obj Object to check.
	 * @return bool True if OAuth error.
	 */
	function is_oauth_error( $obj ) {
		return IndieAuth\is_oauth_error( $obj );
	}
}

if ( ! function_exists( 'wp_error_to_oauth_response' ) ) {
	/**
	 * Convert WP_Error to OAuth response.
	 *
	 * @param WP_Error $error WordPress error.
	 * @return IndieAuth\OAuth_Response|null OAuth response or null.
	 */
	function wp_error_to_oauth_response( $error ) {
		return IndieAuth\wp_error_to_oauth_response( $error );
	}
}
