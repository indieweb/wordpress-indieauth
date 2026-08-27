<?php

use IndieAuth\Token\User as Token_User;

class Test_Authorization_Controller extends WP_UnitTestCase {

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


	// Authorization Request via GET.
	public function create_get( $params = array() ) {
		$request = new WP_REST_Request( 'GET', '/indieauth/1.0/auth' );
		$request->set_query_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Data provider for legacy response_type values.
	 *
	 * @return array[] The response_type to send, or null to omit the parameter.
	 */
	public function legacy_response_type_provider() {
		return array(
			'omitted response_type (pre IndieAuth 1.1)' => array( null ),
			'removed response_type=id flow'             => array( 'id' ),
		);
	}

	/**
	 * Legacy response_type requests must still work but send deprecation signals.
	 *
	 * @dataProvider legacy_response_type_provider
	 * @expectedIncorrectUsage IndieAuth\Rest\Authorization_Controller::get
	 *
	 * @param string|null $response_type The response_type to send, or null to omit it.
	 */
	public function test_legacy_response_type_sends_deprecation_signals( $response_type ) {
		$params = array(
			'client_id'             => 'https://app.example.com',
			'redirect_uri'          => 'https://app.example.com/redirect',
			'state'                 => '12345',
			'code_challenge'        => 'OfYAxt8zU2dAPDWQxTAUIteRzMsoj9QBdMIVEDOErUo',
			'code_challenge_method' => 'S256',
		);
		if ( null !== $response_type ) {
			$params['response_type'] = $response_type;
		}

		$response = $this->create_get( $params );

		$this->assertEquals( 302, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Deprecation', $headers );
		$this->assertArrayHasKey( 'Link', $headers );
		$this->assertStringContainsString( 'rel="deprecation"', $headers['Link'] );
	}

	// A spec-conforming request must not carry deprecation signals.
	public function test_response_type_code_sends_no_deprecation_signals() {
		$response = $this->create_get(
			array(
				'response_type'         => 'code',
				'client_id'             => 'https://app.example.com',
				'redirect_uri'          => 'https://app.example.com/redirect',
				'state'                 => '12345',
				'code_challenge'        => 'OfYAxt8zU2dAPDWQxTAUIteRzMsoj9QBdMIVEDOErUo',
				'code_challenge_method' => 'S256',
			)
		);
		$this->assertEquals( 302, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$this->assertArrayNotHasKey( 'Deprecation', $response->get_headers() );
	}

	/**
	 * A redirect_uri on a different host than the client_id must be rejected
	 * when the client does not publish it as one of its redirect URLs.
	 */
	public function test_rejects_cross_host_redirect_uri_not_published_by_client() {
		$response = $this->create_get(
			array(
				'response_type'         => 'code',
				'client_id'             => 'https://app.example.com',
				'redirect_uri'          => 'https://evil.example.net/redirect',
				'state'                 => '12345',
				'code_challenge'        => 'OfYAxt8zU2dAPDWQxTAUIteRzMsoj9QBdMIVEDOErUo',
				'code_challenge_method' => 'S256',
			)
		);
		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_request', $data['error'], wp_json_encode( $data ) );
	}

	/**
	 * A redirect_uri on a different host than the client_id must be accepted
	 * when the client publishes it as one of its redirect URLs.
	 */
	public function test_allows_cross_host_redirect_uri_published_by_client() {
		$published_uris = function () {
			return array( 'https://other.example.net/redirect' );
		};
		add_filter( 'pre_indieauth_client_redirect_uris', $published_uris );
		$response = $this->create_get(
			array(
				'response_type'         => 'code',
				'client_id'             => 'https://app.example.com',
				'redirect_uri'          => 'https://other.example.net/redirect',
				'state'                 => '12345',
				'code_challenge'        => 'OfYAxt8zU2dAPDWQxTAUIteRzMsoj9QBdMIVEDOErUo',
				'code_challenge_method' => 'S256',
			)
		);
		remove_filter( 'pre_indieauth_client_redirect_uris', $published_uris );
		$this->assertEquals( 302, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
	}

	/**
	 * A native app on loopback may use any port.
	 *
	 * It listens on an ephemeral port it cannot know in advance and cannot
	 * publish metadata at localhost, so the port is not part of its identity.
	 * RFC 8252 section 7.3.
	 */
	public function test_verify_redirect_uri_allows_any_loopback_port() {
		$controller = new IndieAuth\Rest\Authorization_Controller();

		$this->assertTrue( $controller::verify_redirect_uri( 'http://127.0.0.1:8080/', 'http://127.0.0.1:53821/callback' ) );
		$this->assertTrue( $controller::verify_redirect_uri( 'http://localhost:8080/', 'http://localhost:53821/callback' ) );
		$this->assertTrue( $controller::verify_redirect_uri( 'http://[::1]:8080/', 'http://[::1]:53821/cb' ) );

		// A loopback client still may not redirect off the loopback interface.
		$this->assertFalse( $controller::verify_redirect_uri( 'http://127.0.0.1:8080/', 'https://evil.example.net/cb' ) );
		$this->assertFalse( $controller::verify_redirect_uri( 'https://app.example.com', 'http://127.0.0.1:53821/cb' ) );
	}

	// Verifies the same scheme/host/port comparison directly.
	public function test_verify_redirect_uri_same_origin() {
		$controller = new IndieAuth\Rest\Authorization_Controller();
		$this->assertTrue( $controller::verify_redirect_uri( 'https://app.example.com', 'https://app.example.com/callback' ) );
		$this->assertFalse( $controller::verify_redirect_uri( 'https://app.example.com', 'http://app.example.com/callback' ) );
		$this->assertFalse( $controller::verify_redirect_uri( 'https://app.example.com', 'https://app.example.com:8443/callback' ) );
		$this->assertFalse( $controller::verify_redirect_uri( 'https://app.example.com', 'https://evil.example.net/callback' ) );
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
