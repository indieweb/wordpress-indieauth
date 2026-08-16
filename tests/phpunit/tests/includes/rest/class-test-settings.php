<?php
/**
 * Settings Registration Tests file.
 *
 * @package IndieAuth
 */

/**
 * Settings Registration Tests.
 */
class Test_Settings extends WP_UnitTestCase {

	protected static $admin_id;

	public static function wpSetUpBeforeClass( $factory ) {
		static::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	public static function wpTearDownAfterClass() {
		self::delete_user( static::$admin_id );
	}

	public function set_up() {
		global $wp_rest_server;
		parent::set_up();

		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	// Settings registered with show_in_rest must use a JSON Schema type core accepts.
	public function test_settings_are_exposed_in_rest() {
		wp_set_current_user( static::$admin_id );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/settings' ) );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'indieauth_root_user', $data );
	}
}
