<?php
/**
 * Tests for the IndieAuth main class.
 *
 * @package IndieAuth
 */
class Test_IndieAuth extends WP_UnitTestCase {

	/**
	 * The test suite must not run as a local environment, otherwise the
	 * development tools in `local/` (which disable SSRF protection) are loaded.
	 */
	public function test_local_tools_are_not_loaded() {
		$this->assertSame( 'production', \wp_get_environment_type() );
		$this->assertFalse( \function_exists( 'IndieAuth\Development\allow_unsafe_http_request_urls' ) );
	}
}
