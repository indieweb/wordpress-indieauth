<?php
/**
 * Revocation Endpoint Functionality
 */
class IndieAuth_Revocation_Endpoint extends IndieAuth_Endpoint {
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
			'/revocation',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'revoke' ),
					'args'                => array(
						'token'           => array(
							'required' => true,
						),
						'token_type_hint' => array(
							'default' => 'all',
						), // A hint about the type of the token submitted for revocation, options are access_token or refresh_token.
					),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/*
	 * Revocation Endpoint request handler.
	 *
	 * @param WP_REST_Request $request The Request Object.
	 * @return Response to Return to the REST Server.
	 */

	public function revoke( $request ) {
		$params = $request->get_params();
		$this->delete_token( $params['token'], $params['token_type_hint'] );
		return new WP_REST_Response(
			__( 'The Token Provided is No Longer Valid', 'indieauth' ),
			200
		);
	}
}
