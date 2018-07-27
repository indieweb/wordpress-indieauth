<?php
class AuthenticateTest extends WP_UnitTestCase {

	protected static $author_id;

	protected static $test_token = array(
		'token_type' => 'Bearer',
		'scope'      => 'create update media',
		'me'         => 'http://example.org',
		'issued_by'  => 'http://example.org/wp-json/indieauth/1.0/token',
		'client_id'  => 'https://quill.p3k.io/',
		'issued_at'  => 1532569712,
	);

	public static function wpSetUpBeforeClass( $factory ) {
		static::$author_id = $factory->user->create(
			array(
				'role' => 'author',
			)
		);
	}

	public static function pTearDownAfterClass() {
		self::delete_user( self::$author_id );
	}

	// Sets a test token
	public function set_token() {
		$tokens    = new Token_User( '_indieauth_token_' );
		$tokens->set_user( self::$author_id );
		return $tokens->set( static::$test_token );
	}

	// Sets a token and verifies it
	public function test_verify_token() {
		$token   = self::set_token();
		$request = new WP_REST_Request( 'GET', '/indieauth/1.0/token' );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		$response       = rest_get_server()->dispatch( $request );
		$response_token = $response->get_data();
		unset( $response_token['user'] );
		$this->assertEquals( static::$test_token, $response_token );
	}

	public function test_authorize() {
		$token = self::set_token();
		$_REQUEST['micropub']       = 'endpoint';
		$_REQUEST['access_token'] = $token;
		$authorize = new Indieauth_Authorize();
		$user_id = $authorize->determine_current_user( 0 );
		$this->assertEquals( $user_id, self::$author_id );
	}


}
