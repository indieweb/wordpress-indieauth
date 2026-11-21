<?php

use IndieAuth\WP_Admin\Admin;
use IndieAuth\WP_Admin\Health_Check;

class Test_Health_Check extends WP_UnitTestCase {

	/**
	 * Mocked loopback response.
	 *
	 * @var array|WP_Error
	 */
	protected $loopback;

	public function set_up() {
		parent::set_up();
		add_filter( 'pre_http_request', array( $this, 'mock_loopback' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_loopback' ), 10 );
		parent::tear_down();
	}

	public function mock_loopback( $preempt, $args, $url ) {
		if ( false === strpos( $url, 'action=authdiag' ) ) {
			return $preempt;
		}
		return $this->loopback;
	}

	protected function answer( $code, $body ) {
		$this->loopback = array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	public function test_add_tests_keys() {
		$tests = Health_Check::add_tests( array() );

		$this->assertSame( 'indieauth_header', $tests['async']['indieauth_header']['test'] );
		$this->assertTrue( is_callable( $tests['async']['indieauth_header']['async_direct_test'] ) );
		// The weekly cron run must count a stripped header in the menu bubble.
		$this->assertArrayNotHasKey( 'skip_cron', $tests['async']['indieauth_header'] );
		$this->assertArrayHasKey( 'indieauth_https', $tests['direct'] );
	}

	public function test_header_passed() {
		$this->answer( 200, '{"success":true,"message":""}' );
		$this->assertSame( 'passed', Health_Check::test_header() );
		$this->assertSame( 'good', Health_Check::site_health_header_test()['status'] );
	}

	public function test_header_missing() {
		$this->answer( 200, '{"success":false,"message":""}' );
		$this->assertSame( 'missing', Health_Check::test_header() );
		$this->assertSame( 'critical', Health_Check::site_health_header_test()['status'] );
	}

	// A blocked loopback says nothing about the header.
	public function test_header_untested_wp_error() {
		$this->loopback = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$this->assertSame( 'untested', Health_Check::test_header() );
		$this->assertSame( 'recommended', Health_Check::site_health_header_test()['status'] );
	}

	// A security plugin answering wp-login.php with an error page.
	public function test_header_untested_html() {
		$this->answer( 403, '<html><body>Forbidden</body></html>' );
		$this->assertSame( 'untested', Health_Check::test_header() );
	}

	// The site may well reach itself, a firewall on wp-login.php also ends here.
	public function test_header_untested_description_is_neutral() {
		$this->answer( 403, '<html><body>Forbidden</body></html>' );
		$description = Health_Check::site_health_header_test()['description'];

		$this->assertStringNotContainsString( 'could not reach itself', $description );
		$this->assertStringContainsString( 'wp-login.php', $description );
	}

	public function test_ajax_requires_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$response = $this->run_ajax();

		$this->assertFalse( $response['success'] );
	}

	public function test_ajax_returns_result_for_admin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->answer( 200, '{"success":true,"message":""}' );
		$response = $this->run_ajax();

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'indieauth_header', $response['data']['test'] );
		$this->assertSame( 'good', $response['data']['status'] );
	}

	/**
	 * Run the ajax handler, with wp_die() throwing instead of exiting.
	 *
	 * @return array Decoded JSON response.
	 */
	protected function run_ajax() {
		$_REQUEST['_ajax_nonce'] = wp_create_nonce( 'health-check-site-status' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			function () {
				return function ( $message ) {
					throw new WPDieException( (string) $message );
				};
			}
		);

		ob_start();
		try {
			Health_Check::ajax_header_test();
		} catch ( WPDieException $e ) {
			unset( $e );
		}
		$output = ob_get_clean();

		unset( $_REQUEST['_ajax_nonce'] );
		remove_all_filters( 'wp_doing_ajax' );
		remove_all_filters( 'wp_die_ajax_handler' );

		return json_decode( $output, true );
	}

	public function test_admin_test_auth_wrapper() {
		$this->answer( 200, '{"success":true,"message":""}' );
		$this->assertTrue( Admin::test_auth() );

		$this->answer( 200, '{"success":false,"message":""}' );
		$this->assertFalse( Admin::test_auth() );
	}

	public function test_https_test_moved() {
		$this->assertSame( 'indieauth_https', Health_Check::site_health_https_test()['test'] );
	}

	public function test_new_direct_tests_registered() {
		$tests = Health_Check::add_tests( array() );

		$this->assertArrayHasKey( 'indieauth_mbstring', $tests['direct'] );
		$this->assertArrayHasKey( 'indieauth_metadata', $tests['direct'] );
		$this->assertArrayHasKey( 'indieauth_root_user', $tests['direct'] );
	}

	// The missing extension cannot be simulated here.
	public function test_mbstring_good() {
		$this->assertSame( 'good', Health_Check::site_health_mbstring_test()['status'] );
	}

	public function test_metadata_plain_permalinks() {
		$this->set_permalink_structure( '' );
		$this->assertSame( 'recommended', Health_Check::site_health_metadata_test()['status'] );
	}

	public function test_metadata_pretty_permalinks() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->assertSame( 'good', Health_Check::site_health_metadata_test()['status'] );
	}

	// "None" chosen in the settings is a decision, not a problem.
	public function test_root_user_none_on_purpose() {
		update_option( 'indieauth_root_user', '0' );
		$this->assertSame( 'good', Health_Check::site_health_root_user_test()['status'] );
	}

	public function test_root_user_set() {
		update_option( 'indieauth_root_user', self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 'good', Health_Check::site_health_root_user_test()['status'] );
	}

	public function test_root_user_unset_multi_author() {
		delete_option( 'indieauth_root_user' );
		delete_option( 'iw_default_author' );
		delete_transient( 'single_author' );
		foreach ( array( 'author', 'editor' ) as $role ) {
			self::factory()->post->create( array( 'post_author' => self::factory()->user->create( array( 'role' => $role ) ) ) );
		}

		$this->assertSame( 'recommended', Health_Check::site_health_root_user_test()['status'] );
	}

	public function test_debug_information_fields() {
		$user_id = self::factory()->user->create( array( 'display_name' => 'Jane Doe' ) );
		update_option( 'indieauth_root_user', $user_id );

		$info   = Health_Check::debug_information( array() );
		$fields = $info['indieauth']['fields'];

		foreach ( array( 'root_user', 'expires_in', 'show_login_form', 'ticket_endpoint', 'issuer', 'metadata_endpoint', 'well_known_url' ) as $key ) {
			$this->assertArrayHasKey( $key, $fields );
		}
		$this->assertSame( 'Jane Doe', $fields['root_user']['value'] );

		update_option( 'indieauth_root_user', '0' );
		$info = Health_Check::debug_information( array() );
		$this->assertSame( 'None', $info['indieauth']['fields']['root_user']['value'] );
	}
}
