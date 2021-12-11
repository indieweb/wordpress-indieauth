<?php
/**
 * User Info Endpoint Functionality
 */
class IndieAuth_Userinfo_Endpoint extends IndieAuth_Endpoint {

	public function __construct() {
		parent::__construct();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the Route.
	 */
	public function register_routes() {
		register_rest_route(
			'indieauth/1.0',
			'/userinfo',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'userinfo' ),
					'args'                => array(),
					'permission_callback' => '__return_true',
				),
			)
		);
	}


	/*
	 * User Info Endpoint request handler.
	 *
	 * @param WP_REST_Request $request The Request Object.
	 * @return Response to Return to the REST Server.
	 */
	public function userinfo( $request ) {
		$params = $request->get_params();
		$header = $request->get_header( 'Authorization' );
		if ( ! $header && ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}
		$access_token = $this->get_token_from_bearer_header( $header );
		if ( ! $access_token ) {
			return new WP_OAuth_Response(
				'parameter_absent',
				__(
					'Bearer Token Not Supplied or Server Misconfigured to Not Pass Token. Run diagnostic script in WordPress Admin
				IndieAuth Settings Page',
					'indieauth'
				),
				400
			);
		}
		$token = $this->get_token( $access_token );
		if ( ! $token ) {
			return new WP_OAuth_Response( 'invalid_token', __( 'Invalid access token', 'indieauth' ), 401 );
		}
		$scopes = explode( ' ', $token['scope'] );
		if ( ! in_array( 'profile', $scopes, true ) ) {
			return new WP_OAuth_Response(
				'insufficient_scope',
				__(
					'Bearer Token does not have profile scope',
					'indieauth'
				),
				403
			);
		}

		return indieauth_get_user( $token['user'], in_array( 'email', $scopes, true ) );
	}

}
