<?php


function get_indieauth_authorization_endpoint() {
	$option = get_option( 'indieauth_config', 'local' );
	switch ( $option ) {
		case 'indieauth':
			return 'https://indieauth.com/auth';
		case 'custom':
			$return = get_option( 'indieauth_authorization_endpoint', rest_url( '/indieauth/1.0/auth' ) );
			return empty( $return ) ? rest_url( '/indieauth/1.0/auth' ) : $return;
		default:
			return indieauth_rest_url( '/indieauth/1.0/auth' );
	}
}

function get_indieauth_token_endpoint() {
	$option = get_option( 'indieauth_config', 'local' );
	switch ( $option ) {
		case 'indieauth':
			return 'https://tokens.indieauth.com/token';
		case 'custom':
			$return = get_option( 'indieauth_token_endpoint', rest_url( '/indieauth/1.0/token' ) );
			return empty( $return ) ? rest_url( '/indieauth/1.0/token' ) : $return;
		default:
			return indieauth_rest_url( '/indieauth/1.0/token' );
	}
}
