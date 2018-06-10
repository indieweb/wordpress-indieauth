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

// $args must consist of redirect_uri, client_id, and code
function verify_indieauth_authorization_code( $post_args, $endpoint ) {
	if ( ! wp_http_validate_url( $endpoint ) ) {
		return new WP_OAuth_Response( 'server_error', __( 'Did Not Receive a Valid Authorization Endpoint', 'indieauth' ), 500 );
	}

	$defaults = array(
		'client_id' => home_url(),
	);

	$post_args = wp_parse_args( $post_args, $defaults );
	$args      = array(
		'headers' => array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/x-www-form-urlencoded',
		),
		'body'    => $post_args,
	);
	$response  = wp_remote_post( $endpoint, $args );
	$error     = get_oauth_error( $response );
	if ( is_oauth_error( $error ) ) {
		// Pass through well-formed error messages from the authorization endpoint
		return $error;
	}
	$code     = wp_remote_retrieve_response_code( $response );
	$response = wp_remote_retrieve_body( $response );

	$response = json_decode( $response, true );
	// check if response was json or not

	if ( ! is_array( $response ) ) {
		return new WP_OAuth_Response( 'server_error', __( 'The authorization endpoint did not return a JSON response', 'indieauth' ), 500 );
	}

	if ( 2 === (int) ( $code / 100 ) && isset( $response['me'] ) ) {
		// The authorization endpoint acknowledged that the authorization code
		// is valid and returned the authorization info
		return $response;
	}

	// got an unexpected response from the authorization endpoint
	$error = new WP_OAuth_Response( 'server_error', __( 'There was an error verifying the authorization code, the authorization server return an expected response', 'indieauth' ), 500 );
	$error->set_debug( array( 'debug' => $response ) );
	return $error;
}

