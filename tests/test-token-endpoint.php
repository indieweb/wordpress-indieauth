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


	protected static $test_auth_code = array(
		 'client_id' => 'https://app.example.com',
		 'redirect_uri' => 'https://app.example.com/redirect',
		 'scope' => 'create',
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
		static::$test_auth_code['me'] = get_author_posts_url( static::$author_id );
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

	// Sets a test access token
	public function set_access_token() {
		$tokens    = new Token_User( '_indieauth_token_' );
		$tokens->set_user( self::$author_id );
		return $tokens->set( static::$test_token );
	}

	// Sets a test auth code
	public function set_auth_code() {
		$tokens = new Token_User( '_indieauth_code_' );
		$tokens->set_user( self::$author_id );
		return $tokens->set( static::$test_auth_code, 600 );
	}

	// Gets a test access token
	public function get_auth_code( $code ) {
		$tokens    = new Token_User( '_indieauth_code_' );
		return $tokens->get( $code );
	}

	// Gets a test access token
	public function get_access_token( $token ) {
		$tokens    = new Token_User( '_indieauth_token_' );
		return $tokens->get( $token );
	}

	// Form Encoded Request
	public function create_form( $method, $params = array(), $headers = array() ) {
		$request = new WP_REST_Request( $method, '/indieauth/1.0/token' );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		if ( ! empty( $params ) ) {
			$request->set_body_params( $params );
		}

		if ( ! empty( $headers ) ) {
			$request->set_headers( $headers );
		}
		return rest_get_server()->dispatch( $request );
	}

	// Sets an Auth Code and Redeems it at the Token Endpoint
	public function test_auth_code_redemption() {
		$code = $this->set_auth_code();
		$response = $this->create_form( 'POST', 
				array(
					'grant_type' => 'authorization_code',
					'code' => $code,
					'client_id' => 'https://app.example.com',
					'redirect_uri' => 'https://app.example.com/redirect',
				)
		);
		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'access_token', $data );
		$this->assertNotFalse( $data['access_token'] );
		unset( $data['access_token'] );
		$this->assertEquals( 
			array( 
				'me' => get_author_posts_url( static::$author_id ),
				'token_type' => 'Bearer',
				'scope' => 'create',
				'expires_in' => 1209600
			), 
			$data, 
			'Response: ' . wp_json_encode( $data ) 
		);
	}

	// Sets an Auth Code and Redeems it at the Token Endpoint with Profile
	public function test_auth_code_redemption_with_profile() {
		static::$test_auth_code['scope'] = 'create profile';
		$code = $this->set_auth_code();
		$response = $this->create_form( 'POST', 
				array(
					'grant_type' => 'authorization_code',
					'code' => $code,
					'client_id' => 'https://app.example.com',
					'redirect_uri' => 'https://app.example.com/redirect',
				)
		);
		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'access_token', $data );
		$this->assertNotFalse( $data['access_token'] );
		unset( $data['access_token'] );
		$this->assertEquals( 
			array( 
				'me' => get_author_posts_url( static::$author_id ),
				'token_type' => 'Bearer',
				'scope' => 'create profile',
				'expires_in' => 1209600,
				'profile' => indieauth_get_user( static::$author_id )
			), 
			$data, 
			'Response: ' . wp_json_encode( $data ) 
		);
		static::$test_auth_code['scope'] = 'create';
	}

	// Sets a token and verifies it using Access Token Verification
	public function test_token_verification() {
		$token   = self::set_access_token();
		$response = $this->create_form( 
				'GET', 
				array(), 
				array(
					'Authorization' => 'Bearer ' . $token
				)
			);
		$response_token = $response->get_data();
		unset( $response_token['user'] );
		unset( $response_token['active'] );
		$this->assertEquals( static::$test_token, $response_token );
	}

	// Sets a token and verifies it using Access Token Introspection
	public function test_token_introspection() {
		$token   = self::set_access_token();
		$response = $this->create_form( 'POST',
				array( 
					'token' => $token
				)
			);
		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$response_token = $response->get_data();
		unset( $response_token['user'] );
		unset( $response_token['active'] );
		$this->assertEquals( static::$test_token, $response_token );
	}


	// To Make Sure that Revokation Works, Test the Helper Function First.
	public function test_test_get_access_token() {
		$token   = self::set_access_token();
		$check = self::get_access_token( $token );
		unset( $check['user'] );
		$this->assertEquals( static::$test_token, $check );
	}

	// Sets a token, revokes it, then verifies it is not there.
	public function test_token_revokation() {
		$token   = self::set_access_token();
		$response = $this->create_form( 'POST', 
			array( 
				'action' => 'revoke',
				'token' => $token 
			) 
		);
		$response_token = $response->get_data();
		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$check = self::get_access_token( $token );
		$this->assertFalse( $check, $check );
	}
}