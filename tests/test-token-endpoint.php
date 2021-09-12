<?php
class TokenEndpointTest extends WP_UnitTestCase {

	protected static $author_id;
	protected static $subscriber_id;


	protected static $test_token = array(
		'token_type' => 'Bearer',
		'scope'      => 'create update media',
		'me'         => 'http://example.org',
		'issued_by'  => 'http://example.org/wp-json/indieauth/1.0/token',
		'client_id'  => 'https://quill.p3k.io/',
		'iat'  => 1532569712,
	);

	public function setUp() {
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server;
		do_action( 'rest_api_init', $wp_rest_server );
		parent::setUp();
	}

	public static function wpSetUpBeforeClass( $factory ) {
		static::$author_id = $factory->user->create(
			array(
				'role' => 'author',
			)
		);
		static::$subscriber_id = $factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
	}

	public static function wpTearDownAfterClass() {
		self::delete_user( self::$author_id );
		self::delete_user( self::$subscriber_id );
	}

	// Sets a test token
	public function set_token() {
		$tokens    = new Token_User( '_indieauth_token_' );
		$tokens->set_user( self::$author_id );
		return $tokens->set( static::$test_token );
	}

	// Sets a token and verifies it using Access Token Verification
	public function test_token_verification() {
		$token   = self::set_token();
		$request = new WP_REST_Request( 'GET', '/indieauth/1.0/token' );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		$response       = rest_get_server()->dispatch( $request );
		$response_token = $response->get_data();
		unset( $response_token['user'] );
		unset( $response_token['active'] );
		$this->assertEquals( static::$test_token, $response_token );
	}

	// Sets a token and verifies it using Access Token Introspection
	public function test_token_introspection() {
		$token   = self::set_token();
		$request = new WP_REST_Request( 'POST', '/indieauth/1.0/token' );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$request->set_body_params( 
			array( 
				'token' => $token 
			) 
		);
		$response       = rest_get_server()->dispatch( $request );
		$response_token = $response->get_data();
		unset( $response_token['user'] );
		unset( $response_token['active'] );
		$this->assertEquals( static::$test_token, $response_token );
	}
}
