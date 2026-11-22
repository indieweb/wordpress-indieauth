<?php
/**
 * Tests for the IndieAuth main class.
 *
 * @package IndieAuth
 */

use IndieAuth\IndieAuth;
use IndieAuth\Token\User as Token_User;
use IndieAuth\Tokens;

class Test_IndieAuth extends WP_UnitTestCase {

	/**
	 * The test suite must not run as a local environment, otherwise the
	 * development tools in `local/` (which disable SSRF protection) are loaded.
	 */
	public function test_local_tools_are_not_loaded() {
		$this->assertSame( 'production', \wp_get_environment_type() );
		$this->assertFalse( \function_exists( 'IndieAuth\Development\allow_unsafe_http_request_urls' ) );
	}

	// The cleanup job moves the tokens that are still in user meta, and leaves the codes there.
	public function test_cleanup_moves_tokens_from_user_meta() {
		$user_id = self::factory()->user->create();
		$data    = array(
			'scope'     => 'create',
			'client_id' => 'https://app.example.com/',
		);
		$stores  = array();
		foreach ( array( '_indieauth_token_', '_indieauth_refresh_', '_indieauth_code_' ) as $prefix ) {
			$stores[ $prefix ] = new Token_User( $prefix, $user_id );
			$stores[ $prefix ]->set( $data, 600 );
		}
		$stores['_indieauth_token_']->set( $data, -30 );

		IndieAuth::expires();

		$this->assertSame( array(), $stores['_indieauth_token_']->get_all() );
		$this->assertSame( array(), $stores['_indieauth_refresh_']->get_all() );
		$this->assertCount( 1, $stores['_indieauth_code_']->get_all() );
		$this->assertCount( 1, ( new Tokens( 'access', $user_id ) )->get_all() );
		$this->assertCount( 1, ( new Tokens( 'refresh', $user_id ) )->get_all() );
	}
}
