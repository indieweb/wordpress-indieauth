<?php
class TokensTest extends WP_UnitTestCase {
	 
	public function test_set_and_get_user_token() {
		$user_id = self::factory()->user->create();
		$tokens = new Token_User( '_indieauth_code_', $user_id );
		$token = array( 'foo' => 'foo', 'bar' => 'bar' );
		$key = $tokens->set( $token );
		$get = $tokens->get( $key );
		unset( $get['user'] );
		$this->assertEquals( $token, $get );
	}


	public function test_expired_token() {
		$user_id = self::factory()->user->create();
		$tokens = new Token_User( '_indieauth_code_', $user_id );
		$token = array( 'foo' => 'foo', 'bar' => 'bar' );
		$key = $tokens->set( $token, -30 );
		$get = $tokens->get( $key );
		$this->assertFalse( $get );
	}

}
