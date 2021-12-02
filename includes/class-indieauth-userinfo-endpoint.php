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
					'permission_callback' => array( $this, 'permission_callback' ),
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
		if ( ! indieauth_check_scope( 'profile' ) ) {
			return new WP_OAuth_Response(
				'insufficient_scope',
				__(
					'Bearer Token does not have profile scope',
					'indieauth'
				),
				403
			);
		}

		return indieauth_get_user( wp_get_current_user(), indieauth_check_scope( 'email' ) );
	}

}
