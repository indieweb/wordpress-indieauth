<?php
/**
 * Tests for the Debug class.
 *
 * @package IndieAuth
 */

use IndieAuth\Authorize;
use IndieAuth\Debug;

class Test_Debug extends WP_UnitTestCase {

	/**
	 * File the error log is redirected to.
	 *
	 * @var string
	 */
	private $log_file;

	/**
	 * Previous error log setting.
	 *
	 * @var string
	 */
	private $error_log;

	public function set_up() {
		parent::set_up();
		$this->log_file  = wp_tempnam( 'indieauth-debug' );
		$this->error_log = ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky
	}

	public function tear_down() {
		ini_set( 'error_log', $this->error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		unlink( $this->log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		parent::tear_down();
	}

	/**
	 * Reads what was logged so far.
	 *
	 * @return string Logged output.
	 */
	private function read_log() {
		return file_get_contents( $this->log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Runs the logger for an error response on a route.
	 *
	 * @param string $route  Request route.
	 * @param string $code   Error code.
	 * @param int    $status HTTP status.
	 * @param array  $params Request body params.
	 * @return string Logged output.
	 */
	private function log_error( $route, $code = 'rest_forbidden', $status = 401, $params = array() ) {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( $params );
		$result  = new WP_REST_Response(
			array(
				'code'    => $code,
				'message' => 'Error',
			),
			$status
		);
		( new Debug() )->log_rest_api_errors( $result, rest_get_server(), $request );

		return $this->read_log();
	}

	public function test_other_plugin_errors_are_not_logged() {
		$this->assertSame( '', $this->log_error( '/other-plugin/1.0/items' ) );
	}

	public function test_indieauth_route_errors_are_logged() {
		$this->assertStringContainsString( 'REST request: /indieauth/1.0/userinfo', $this->log_error( '/indieauth/1.0/userinfo' ) );
	}

	public function test_errors_with_indieauth_token_are_logged() {
		add_filter(
			'indieauth_response',
			function () {
				return array( 'scope' => 'create' );
			}
		);

		$this->assertStringContainsString( 'REST request: /other-plugin/1.0/items', $this->log_error( '/other-plugin/1.0/items', 'rest_forbidden', 403 ) );
	}

	public function test_access_token_param_is_redacted() {
		$log = $this->log_error( '/indieauth/1.0/userinfo', 'invalid_token', 401, array( 'access_token' => 'secret-token' ) );
		$this->assertStringNotContainsString( 'secret-token', $log );
		$this->assertStringContainsString( 'XXXX', $log );
	}

	/**
	 * Runs Authorize's response handling for a request with an unknown token.
	 *
	 * @param mixed $response Route response.
	 * @return string Logged output.
	 */
	private function log_unknown_token( $response ) {
		$authorize                 = new Authorize();
		$authorize->deferred_error = $authorize->verify_access_token( 'not-an-indieauth-token' );
		$authorize->rest_request_after_callbacks( $response );

		return $this->read_log();
	}

	public function test_unknown_token_is_logged_when_request_fails() {
		$this->assertStringContainsString( 'IndieAuth Error: 401 invalid_token', $this->log_unknown_token( new WP_Error( 'rest_forbidden', 'Forbidden', array( 'status' => 401 ) ) ) );
	}

	public function test_unknown_token_is_logged_when_route_returns_own_error() {
		// Micropub answers with a WP_REST_Response error of its own.
		$this->assertStringContainsString( 'IndieAuth Error: 401 invalid_token', $this->log_unknown_token( new WP_REST_Response( array( 'error' => 'insufficient_scope' ), 403 ) ) );
	}

	public function test_unknown_token_is_not_logged_when_request_succeeds() {
		$this->assertSame( '', $this->log_unknown_token( new WP_REST_Response( array(), 200 ) ) );
	}

	public function test_unknown_token_is_not_logged_on_lookup() {
		$error = ( new Authorize() )->verify_access_token( 'not-an-indieauth-token' );

		$this->assertTrue( is_oauth_error( $error ) );
		$this->assertSame( '', $this->read_log() );
	}

	public function test_oauth_errors_are_logged() {
		new IndieAuth\OAuth_Response( 'invalid_request', 'Bad request', 400 );

		$this->assertStringContainsString( 'IndieAuth Error: 400 invalid_request', $this->read_log() );
	}
}
