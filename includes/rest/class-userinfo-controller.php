<?php
/**
 * Userinfo Controller class file.
 *
 * @package IndieAuth
 */

namespace IndieAuth\Rest;

use IndieAuth\OAuth_Response;
use function IndieAuth\indieauth_get_user;

/**
 * User Info Controller Functionality.
 *
 * @since 1.0.0
 */
class Userinfo_Controller extends \WP_REST_Controller {

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
	protected $rest_base = 'userinfo';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->init_tokens();
	}

	/**
	 * Get the userinfo endpoint URL.
	 *
	 * @return string Endpoint URL.
	 */
	public function get_endpoint() {
		return \rest_url( $this->namespace . '/' . $this->rest_base );
	}

	/**
	 * Add userinfo endpoint to metadata.
	 *
	 * @param array $metadata The metadata array.
	 * @return array Modified metadata.
	 */
	public function metadata( $metadata ) {
		$metadata['userinfo_endpoint'] = $this->get_endpoint();
		return $metadata;
	}

	/**
	 * Add userinfo endpoint to REST index.
	 *
	 * @param array $index The REST index array.
	 * @return array Modified index.
	 */
	public function rest_index( $index ) {
		$index['userinfo'] = $this->get_endpoint();
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
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'userinfo' ),
					'args'                => array(),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * User Info Endpoint request handler.
	 *
	 * @param \WP_REST_Request $request The Request Object.
	 * @return \WP_REST_Response|OAuth_Response Response to return to the REST Server.
	 */
	public function userinfo( $request ) {
		$header = $request->get_header( 'Authorization' );
		if ( ! $header && ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = \wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		$access_token = $this->get_token_from_bearer_header( $header );
		if ( ! $access_token ) {
			return new OAuth_Response(
				'parameter_absent',
				\__(
					'Bearer Token Not Supplied or Server Misconfigured to Not Pass Token. Run diagnostic script in WordPress Admin
				IndieAuth Settings Page',
					'indieauth'
				),
				400
			);
		}
		$token = $this->get_token( $access_token );
		if ( ! $token ) {
			return new OAuth_Response( 'invalid_token', \__( 'Invalid access token', 'indieauth' ), 401 );
		}
		$scopes = explode( ' ', $token['scope'] );
		if ( ! in_array( 'profile', $scopes, true ) ) {
			return new OAuth_Response(
				'insufficient_scope',
				\__(
					'Bearer Token does not have profile scope',
					'indieauth'
				),
				403
			);
		}

		return indieauth_get_user( $token['user'], in_array( 'email', $scopes, true ) );
	}
}
