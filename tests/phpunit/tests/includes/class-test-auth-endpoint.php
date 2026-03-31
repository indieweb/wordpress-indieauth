<?php
class AuthEndpointTest extends WP_UnitTestCase {

	protected static $author_id;

	protected static $test_auth_code = array(
		 'client_id' => 'https://app.example.com',
		 'redirect_uri' => 'https://app.example.com/redirect',
	);

	public static function wpSetUpBeforeClass( $factory ) {
		static::$author_id = $factory->user->create(
			array(
				'role' => 'author',
			)
		);
		static::$test_auth_code['me'] = get_author_posts_url( static::$author_id );
	}

	public static function wpTearDownAfterClass() {
		self::delete_user( self::$author_id );
	}

	// Form Encoded Request
	public function create_form( $method, $params = array(), $headers = array() ) {
		$request = new WP_REST_Request( $method, '/indieauth/1.0/auth' );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		if ( ! empty( $params ) ) {
			$request->set_body_params( $params );
		}

		if ( ! empty( $headers ) ) {
			$request->set_headers( $headers );
		}
		return rest_get_server()->dispatch( $request );
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


	// Check For an Invalid Grant Type.
	public function test_invalid_grant_type() {
		$code = $this->set_auth_code();
		$response = $this->create_form( 'POST', 
				array(
					'grant_type' => 'foo',
					'code' => 'foo',
					'client_id' => 'https://app.example.com',
					'redirect_uri' => 'https://app.example.com/redirect',
				)
		);
		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$data = $response->get_data();
		$this->assertEquals( 'unsupported_grant_type', $data['error'], wp_json_encode( $data ) );
	}

	// Sets an Auth Code and Redeems it at the Auth Endpoint
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
		$this->assertArrayNotHasKey( 'access_token', $data );
		$this->assertEquals( 
			array( 
				'me' => get_author_posts_url( static::$author_id ),
			), 
			$data, 
			'Response: ' . wp_json_encode( $data ) 
		);
	}

	// Tests to Make Sure the Auth Endpoint Does Not Return a Token
	public function test_auth_code_redemption_with_scope() {
		static::$test_auth_code['scope'] = 'create update';
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
		$this->assertArrayNotHasKey( 'access_token', $data );
		$this->assertEquals( 
			array( 
				'me' => get_author_posts_url( static::$author_id ),
			), 
			$data, 
			'Response: ' . wp_json_encode( $data ) 
		);
		// Reset Just in Case.
		unset( static::$test_auth_code['scope'] );
	}

	// Tests to Make Sure the Auth Endpoint Returns a Profile
	public function test_auth_code_redemption_with_profile() {
		static::$test_auth_code['scope'] = 'profile';
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
		$this->assertArrayNotHasKey( 'access_token', $data );
		$this->assertEquals( 
			array( 
				'me' => get_author_posts_url( static::$author_id ),
				'profile' => indieauth_get_user( static::$author_id )
			), 
			$data, 
			'Response: ' . wp_json_encode( $data ) 
		);
		// Reset Just in Case.
		unset( static::$test_auth_code['scope'] );
	}


	// Tests to Make Sure the Auth Endpoint Returns a Profile with Email
	public function test_auth_code_redemption_with_email() {
		static::$test_auth_code['scope'] = 'profile email';
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
		$this->assertArrayNotHasKey( 'access_token', $data );
		$this->assertEquals( 
			array( 
				'me' => get_author_posts_url( static::$author_id ),
				'profile' => indieauth_get_user( static::$author_id, true )
			), 
			$data, 
			'Response: ' . wp_json_encode( $data ) 
		);
		// Reset Just in Case.
		unset( static::$test_auth_code['scope'] );
	}

	public function test_pkce_verifier_true() {
	 	$code_challenge = "OfYAxt8zU2dAPDWQxTAUIteRzMsoj9QBdMIVEDOErUo";   
	 	$code_verifier  = "a6128783714cfda1d388e2e98b6ae8221ac31aca31959e59512c59f5";
		$this->assertTrue( pkce_verifier( $code_challenge, $code_verifier, 'S256' ) );
	}

	public function test_pkce_verifier_false() {
	 	$code_challenge = "OfYAxt8zU2dAPDWQxTAUIteRzMsoj9QBdMIVEDOErUo";   
	 	$code_verifier  = "a612878371009ghja1d388e2e98b6ae8221ac31aca31959e59512c59f5";
		$this->assertFalse( pkce_verifier( $code_challenge, $code_verifier, 'S256' ) );
	}
}
