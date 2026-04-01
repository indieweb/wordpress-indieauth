<?php
/**
 * Introspection Controller class file.
 *
 * @package IndieAuth
 */

namespace IndieAuth\Rest;

/**
 * Introspection Controller Functionality.
 *
 * @since 1.0.0
 */
class Introspection_Controller extends \WP_REST_Controller {

	use Token_Management;

	/**
	 * The namespace for the REST route.
	 *
	 * @var string
	 */
	protected $namespace = 'indieauth/1.0';

	/**
	 * The base of the REST route.
	 *
	 * @var string
	 */
	protected $rest_base = 'introspection';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->init_tokens();
	}

	/**
	 * Get the introspection endpoint URL.
	 *
	 * @return string Endpoint URL.
	 */
	public function get_endpoint() {
		return \rest_url( $this->namespace . '/' . $this->rest_base );
	}

	/**
	 * Get supported authentication methods for introspection.
	 *
	 * @return array Supported authentication methods.
	 */
	public function auth_methods_supported() {
		return array_unique( \apply_filters( 'indieauth_introspection_auth_methods_supported', array( 'none' ) ) );
	}

	/**
	 * Add introspection endpoint to metadata.
	 *
	 * @param array $metadata The metadata array.
	 * @return array Modified metadata.
	 */
	public function metadata( $metadata ) {
		$metadata['introspection_endpoint']               = $this->get_endpoint();
		$metadata['introspection_auth_methods_supported'] = $this->auth_methods_supported();
		return $metadata;
	}

	/**
	 * Add introspection endpoint to REST index.
	 *
	 * @param array $index The REST index array.
	 * @return array Modified index.
	 */
	public function rest_index( $index ) {
		$index['introspection'] = $this->get_endpoint();
		return $index;
	}

	/**
	 * Register the Route.
	 */
	public function register_routes() {
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'introspection' ),
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
	 * Introspection Endpoint request handler.
	 *
	 * @param \WP_REST_Request $request The Request Object.
	 * @return \WP_REST_Response Response to return to the REST Server.
	 */
	public function introspection( $request ) {
		$params = $request->get_params();
		$token  = $this->get_token( $params['token'], $params['token_type_hint'] );
		if ( $token ) {
			$token['active'] = 'true';
		} else {
			$token = array( 'active' => false );
		}

		return \rest_ensure_response( $token );
	}
}
