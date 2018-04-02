<?php
/**
 *
 *
 * Implements IndieAuth Authorization Endpoint
 */

class IndieAuth_Authorization_Endpoint {
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

	}


	/**
	 * Register the Route.
	 */
	public function register_routes() {
		register_rest_route(
			'indieauth/1.0', '/auth', array(
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'request' ),
					'args'     => array(
						'response_type'   => array(
							'required' => true
						),
						'client_id'    => array(
							'required' => true,
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
						'redirect_uri' => array(
							'required' => true,
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
						'me'           => array(
							'required' => true,
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
						'state'       => array(
							'required' => true
						)
					),
				),
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'request' ),
					'args'     => array(
						'code'   => array(
							'required' => true
						),
						'client_id'    => array(
							'required' => true,
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
						'redirect_uri' => array(
							'required' => true,
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
					),
				),
			)
		);
	}
}

