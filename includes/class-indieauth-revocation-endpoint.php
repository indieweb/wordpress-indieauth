<?php
/**
 * Revocation Endpoint class file.
 *
 * @package IndieAuth
 */

/**
 * Revocation Endpoint Functionality.
 *
 * @since 1.0.0
 */
class IndieAuth_Revocation_Endpoint extends IndieAuth_Endpoint {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'indieauth_metadata', array( $this, 'metadata' ) );
		add_filter( 'rest_index_indieauth_endpoints', array( $this, 'rest_index' ) );
	}

	/**
	 * Get the revocation endpoint URL.
	 *
	 * @return string Endpoint URL.
	 */
	public static function get_endpoint() {
		return rest_url( '/indieauth/1.0/revocation' );
	}

	/**
	 * Get supported authentication methods for revocation.
	 *
	 * @return array Supported authentication methods.
	 */
	public function auth_methods_supported() {
		return array_unique( apply_filters( 'indieauth_revocation_auth_methods_supported', array( 'none' ) ) );
	}

	/**
	 * Add revocation endpoint to metadata.
	 *
	 * @param array $metadata The metadata array.
	 * @return array Modified metadata.
	 */
	public function metadata( $metadata ) {
		$metadata['revocation_endpoint']               = $this->get_endpoint();
		$metadata['revocation_auth_methods_supported'] = $this->auth_methods_supported();
		return $metadata;
	}

	/**
	 * Add revocation endpoint to REST index.
	 *
	 * @param array $index The REST index array.
	 * @return array Modified index.
	 */
	public function rest_index( $index ) {
		$index['revocation'] = $this->get_endpoint();
		return $index;
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

	/**
	 * Revocation Endpoint request handler.
	 *
	 * @param WP_REST_Request $request The Request Object.
	 * @return WP_REST_Response Response to return to the REST Server.
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
