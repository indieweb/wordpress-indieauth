<?php
/**
 * Tests for the IndieAuth main class.
 *
 * @package IndieAuth
 */

use IndieAuth\Token\User as Token_User;

class Test_IndieAuth extends WP_UnitTestCase {

	/**
	 * The test suite must not run as a local environment, otherwise the
	 * development tools in `local/` (which disable SSRF protection) are loaded.
	 */
	public function test_local_tools_are_not_loaded() {
		$this->assertSame( 'production', \wp_get_environment_type() );
		$this->assertFalse( \function_exists( 'IndieAuth\Development\allow_unsafe_http_request_urls' ) );
	}

	/**
	 * Stores an access token, a refresh token and an authorization code for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array The three stores, keyed by prefix.
	 */
	private function add_tokens( $user_id ) {
		$stores = array();
		foreach ( array( '_indieauth_token_', '_indieauth_refresh_', '_indieauth_code_' ) as $prefix ) {
			$stores[ $prefix ] = new Token_User( $prefix, $user_id );
			$stores[ $prefix ]->set(
				array(
					'client_id' => 'https://app.example.com/',
					'scope'     => 'create',
				),
				600
			);
		}
		return $stores;
	}

	public function test_password_reset_revokes_the_tokens_of_the_user() {
		$user_id = self::factory()->user->create();
		$other   = self::factory()->user->create();
		$mine    = $this->add_tokens( $user_id );
		$theirs  = $this->add_tokens( $other );

		$revoked = array();
		add_action(
			'indieauth_token_revoked',
			function ( $token, $revoked_user ) use ( &$revoked ) {
				$revoked[] = $revoked_user;
			},
			10,
			2
		);

		reset_password( get_user_by( 'id', $user_id ), 'a-new-password' );

		foreach ( $mine as $prefix => $store ) {
			$this->assertSame( array(), $store->get_all(), $prefix );
		}
		foreach ( $theirs as $prefix => $store ) {
			$this->assertCount( 1, $store->get_all(), $prefix );
		}

		// The access token and the refresh token are reported, the code is not.
		$this->assertSame( array( $user_id, $user_id ), $revoked );
	}

	public function test_password_change_keeps_the_tokens() {
		$user_id = self::factory()->user->create();
		$mine    = $this->add_tokens( $user_id );

		wp_set_password( 'a-new-password', $user_id );

		foreach ( $mine as $prefix => $store ) {
			$this->assertCount( 1, $store->get_all(), $prefix );
		}
	}
}
