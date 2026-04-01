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
	function indieauth_get_scopes() {
		return IndieAuth\indieauth_get_scopes();
	}
}

if ( ! function_exists( 'indieauth_check_scope' ) ) {
	function indieauth_check_scope( $scope ) {
		return IndieAuth\indieauth_check_scope( $scope );
	}
}

if ( ! function_exists( 'indieauth_get_response' ) ) {
	function indieauth_get_response() {
		return IndieAuth\indieauth_get_response();
	}
}

if ( ! function_exists( 'indieauth_get_client_id' ) ) {
	function indieauth_get_client_id() {
		return IndieAuth\indieauth_get_client_id();
	}
}

if ( ! function_exists( 'indieauth_get_client_data' ) ) {
	function indieauth_get_client_data() {
		return IndieAuth\indieauth_get_client_data();
	}
}

if ( ! function_exists( 'indieauth_get_me' ) ) {
	function indieauth_get_me() {
		return IndieAuth\indieauth_get_me();
	}
}

if ( ! function_exists( 'indieauth_get_metadata_endpoint' ) ) {
	function indieauth_get_metadata_endpoint() {
		return IndieAuth\indieauth_get_metadata_endpoint();
	}
}

if ( ! function_exists( 'indieauth_get_issuer' ) ) {
	function indieauth_get_issuer() {
		return IndieAuth\indieauth_get_issuer();
	}
}

if ( ! function_exists( 'indieauth_get_root_user' ) ) {
	function indieauth_get_root_user() {
		return IndieAuth\indieauth_get_root_user();
	}
}

if ( ! function_exists( 'indieauth_validate_user_identifier' ) ) {
	function indieauth_validate_user_identifier( $url ) {
		return IndieAuth\indieauth_validate_user_identifier( $url );
	}
}

if ( ! function_exists( 'indieauth_validate_client_identifier' ) ) {
	function indieauth_validate_client_identifier( $url ) {
		return IndieAuth\indieauth_validate_client_identifier( $url );
	}
}

if ( ! function_exists( 'indieauth_validate_issuer_identifier' ) ) {
	function indieauth_validate_issuer_identifier( $url ) {
		return IndieAuth\indieauth_validate_issuer_identifier( $url );
	}
}

if ( ! function_exists( 'indieauth_get_user' ) ) {
	function indieauth_get_user( $user, $email = false ) {
		return IndieAuth\indieauth_get_user( $user, $email );
	}
}

if ( ! function_exists( 'indieauth_hash' ) ) {
	function indieauth_hash( $data ) {
		return IndieAuth\indieauth_hash( $data );
	}
}

if ( ! function_exists( 'get_user_by_identifier' ) ) {
	function get_user_by_identifier( $identifier ) {
		return IndieAuth\get_user_by_identifier( $identifier );
	}
}

if ( ! function_exists( 'get_url_from_user' ) ) {
	function get_url_from_user( $user_id ) {
		return IndieAuth\get_url_from_user( $user_id );
	}
}
