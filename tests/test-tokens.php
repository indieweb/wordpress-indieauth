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

	public function test_find_token_users() {
		$user_id_1 = self::factory()->user->create();
		$user_id_2 = self::factory()->user->create();
		$tokens = new Token_User( '_indieauth_code_', $user_id_1 );
		$token = array( 'foo' => 'foo', 'bar' => 'bar' );
		$tokens->set( $token );
		$tokens->set_user( $user_id_2 );
		$key = $tokens->set( $token );
		$users = $tokens->find_token_users();
		$this->assertEquals( $users, array( $user_id_1, $user_id_2 ) );
	}

	public function test_find_by_uuid() {
		$user_id_1 = self::factory()->user->create();
		$tokens = new Token_User( '_indieauth_code_', $user_id_1 );
		$uuid = wp_generate_uuid4();
		$token = array( 'foo' => 'foo', 'bar' => 'bar', 'uuid' => $uuid );
		$access_token = $tokens->set( $token );
		$return = $tokens->find_by_field( 'uuid', $uuid, $user_id_1 );
		$first = reset( $return );
		$this->assertEquals( $uuid, $first['uuid'], wp_json_encode( $return ) );
	}



	public function test_expired_token() {
		$user_id = self::factory()->user->create();
		$tokens = new Token_User( '_indieauth_code_', $user_id );
		$token = array( 'foo' => 'foo', 'bar' => 'bar' );
		$key = $tokens->set( $token, -30 );
		$get = $tokens->get( $key );
		$this->assertFalse( $get );
	}

	public function test_destroy_token() {
		$user_id = self::factory()->user->create();
		$tokens = new Token_User( '_indieauth_code_', $user_id );
		$token = array( 'foo' => 'foo', 'bar' => 'bar' );
		$key = $tokens->set( $token, 300 );
		$get = $tokens->get( $key );
		unset( $get['user'] );
		unset( $get['exp' ] );
		$this->assertEquals( $get, $token );
		$destroy = $tokens->destroy( $key );
		$this->assertTrue( $destroy );
		$get = $tokens->get( $key );
		$this->assertFalse( $get );
	}




}
