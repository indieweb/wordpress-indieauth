<?php

use IndieAuth\Ticket\External_User_Token;

class Test_External_User_Token extends WP_UnitTestCase {

	// The first token is stored at index 0, which must still count as found.
	public function test_update_does_not_duplicate_the_first_token() {
		$user_id = self::factory()->user->create();
		$tokens  = new External_User_Token( $user_id );

		$tokens->update( array( 'access_token' => 'abc' ) );
		$tokens->update(
			array(
				'access_token' => 'abc',
				'scope'        => 'read',
			)
		);

		$stored = get_user_meta( $user_id, 'indieauth_external_tokens', true );
		$this->assertCount( 1, $stored );
		$this->assertSame( 'read', $stored[0]['scope'] );
	}

	// Without stored tokens the user meta is an empty string, not an array.
	public function test_destroy_without_stored_tokens() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$tokens = new External_User_Token( $user_id );

		$this->assertFalse( $tokens->destroy( 'abc', false ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, 'indieauth_external_tokens' ) );
	}
}
