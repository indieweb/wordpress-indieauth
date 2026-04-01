<?php
/**
 * IndieAuth Debug class.
 *
 * @package IndieAuth
 */

namespace IndieAuth;

/**
 * Class for debugging IndieAuth requests and responses.
 */
class Debug {

	/**
	 * Constructor. Registers hooks for debugging.
	 */
	public function __construct() {
		\add_filter( 'http_request_args', array( $this, 'indieauth_allow_localhost' ), 10, 2 );
		\add_filter( 'rest_post_dispatch', array( $this, 'log_rest_api_errors' ), 10, 3 );
		\add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Allow localhost URLs if WP_DEBUG is true.
	 *
	 * @param array  $r   Array of HTTP request arguments.
	 * @param string $url The request URL.
	 * @return array Modified request arguments.
	 */
	public function indieauth_allow_localhost( $r, $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$r['reject_unsafe_urls'] = false;

		return $r;
	}

	/**
	 * Log REST API errors.
	 *
	 * @param \WP_REST_Response $result  Result that will be sent to the client.
	 * @param \WP_REST_Server   $server  The API server instance.
	 * @param \WP_REST_Request  $request The request used to generate the response.
	 * @return \WP_REST_Response The result.
	 */
	public function log_rest_api_errors( $result, $server, $request ) {
		$request_route = $request->get_route();
		$result_route  = $result->get_matched_route();
		$routes        = array( 'indieauth/1.0/auth', '/indieauth/1.0/token', 'indieauth/1.0/ticket' );
		if ( in_array( $request_route, $routes, true ) || in_array( $result_route, $routes, true ) ) {
			return $result;
		}
		if ( $result->is_error() ) {
			$params = $request->get_params();
			if ( isset( $params['code'] ) ) {
				// Remove actual code from logs.
				$params['code'] = 'XXXX';
			}
			if ( isset( $params['ticket'] ) ) {
				// Remove actual token from logs.
				$params['ticket'] = 'XXXX';
			}
			$headers = $request->get_headers();
			$token   = isset( $headers['authorization'] ) ? 'Present' : 'Absent';
			$data    = $result->get_data();
			if ( is_array( $data ) && isset( $data['access_token'] ) ) {
				// Remove actual token from logs.
				$data['access_token'] = 'XXXX';
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log(
				sprintf(
					'REST request: %s: %s(Header %s)',
					$request->get_route(),
					\wp_json_encode( $params ),
					$token
				)
			);
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log(
				sprintf(
					'REST result: %s: %s(%s) - %s(User ID: %s)',
					$result->get_matched_route(),
					\wp_json_encode( $data ),
					$result->get_status(),
					\wp_json_encode( $result->get_headers() ),
					\get_current_user_id()
				)
			);
		}
		return $result;
	}

	/**
	 * Register test route for authorization.
	 */
	public function register_routes() {
		\register_rest_route(
			'indieauth/1.0',
			'/test',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'test' ),
					'permission_callback' => array( $this, 'permissions_test' ),
				),
			)
		);
	}

	/**
	 * Permission callback for the test route.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return true|\WP_Error True if authorized, WP_Error otherwise.
	 */
	public function permissions_test( $request ) {
		$access_token = $request->get_param( 'access_token' );
		$header       = $request->get_header( 'authorization' );
		if ( ! $access_token && ! $header ) {
			return new \WP_Error( 'forbidden', \__( 'Could Not Find Token', 'indieauth' ), array( 'status' => 403 ) );
		}
		if ( 0 === \get_current_user_id() ) {
			if ( empty( $this->response ) ) {
				return new \WP_Error(
					'forbidden',
					\__( 'No User is Currently Set', 'indieauth' ),
					array(
						'status'  => 403,
						'request' => $request,
					)
				);
			} else {
				return new \WP_Error( 'forbidden', \__( 'A Successful Auth Response was Given yet No User is Currently Set', 'indieauth' ), array( 'status' => 403 ) );
			}
		}
		return true;
	}

	/**
	 * Callback for the test route.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return array|\WP_Error The IndieAuth response or error.
	 */
	public function test( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( 0 === \get_current_user_id() ) {
			return new \WP_Error( 'forbidden', \__( 'You have passed a permissions test but somehow the system is still not showing you as logged in', 'indieauth' ) );
		}
		return indieauth_get_response();
	}
}
