<?php

use IndieAuth\Token\User as Token_User;

class Test_Token_Controller extends WP_UnitTestCase {

	protected static $author_id;
	protected static $subscriber_id;


	protected static $test_token = array(
		'token_type' => 'Bearer',
		'scope'      => 'create update media',
		'issued_by'  => 'https://example.org/wp-json/indieauth/1.0/token',
		'client_id'  => 'https://quill.p3k.io/',
	        'uuid' => '5e97048d-460c-4fb8-af0e-74db61d4b419',
		'iat'  => 1532569712,
	);

	protected static $refresh_token = array (
	    'scope' => 'create update media',
	    'client_id' => 'https://quill.p3k.io/',
	    'uuid' => '5e97048d-460c-4fb8-af0e-74db61d4b419',
	    'iat' => 1632019982,
	 );


	protected static $test_auth_code = array(
		 'client_id' => 'https://app.example.com',
		 'redirect_uri' => 'https://app.example.com/redirect',
		 'scope' => 'create',
	);

	public function set_up() {
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server;
		do_action( 'rest_api_init', $wp_rest_server );
		parent::set_up();
	}

	public static function wpSetUpBeforeClass( $factory ) {
		static::$author_id = $factory->user->create(
			array(
				'role' => 'author',
			)
		);
		static::$test_auth_code['me'] = get_author_posts_url( static::$author_id );
		static::$test_token['me'] = get_author_posts_url( static::$author_id );
		static::$refresh_token['me'] = get_author_posts_url( static::$author_id );
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

	// Sets a test access token
	public function set_refresh_token() {
		$tokens    = new Token_User( '_indieauth_refresh_' );
		$tokens->set_user( self::$author_id );
		return $tokens->set( static::$refresh_token );
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

	// Gets a test refresh  token
	public function get_refresh_token( $token ) {
		$tokens    = new Token_User( '_indieauth_refresh_' );
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

	// Check For an Unsupported Grant Type
	public function test_unsupported_grant_type() {
		$code = $this->set_auth_code();
		$response = $this->create_form( 'POST', 
				array(
					'grant_type' => 'foo',
				)
		);
		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$data = $response->get_data();
		$this->assertEquals( 'unsupported_grant_type', $data['error'], wp_json_encode( $data ) );
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
		$this->assertArrayHasKey( 'refresh_token', $data );
		$this->assertNotFalse( $data['refresh_token'] );
		unset( $data['refresh_token'] );
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

	// An unknown code is an invalid grant, RFC 6749 section 5.2.
	public function test_auth_code_redemption_rejects_unknown_code() {
		$response = $this->create_form(
			'POST',
			array(
				'grant_type'   => 'authorization_code',
				'code'         => 'not-a-real-code',
				'client_id'    => 'https://app.example.com',
				'redirect_uri' => 'https://app.example.com/redirect',
			)
		);
		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_grant', $data['error'], wp_json_encode( $data ) );
	}

	// Redemption must fail when the client_id does not match the stored code.
	public function test_auth_code_redemption_rejects_client_id_mismatch() {
		$code     = $this->set_auth_code();
		$response = $this->create_form(
			'POST',
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'client_id'    => 'https://evil.example.com',
				'redirect_uri' => 'https://app.example.com/redirect',
			)
		);
		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_grant', $data['error'], wp_json_encode( $data ) );
	}

	// Redemption must fail when the redirect_uri does not match the stored code.
	public function test_auth_code_redemption_rejects_redirect_uri_mismatch() {
		$code     = $this->set_auth_code();
		$response = $this->create_form(
			'POST',
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'client_id'    => 'https://app.example.com',
				'redirect_uri' => 'https://evil.example.com/redirect',
			)
		);
		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_grant', $data['error'], wp_json_encode( $data ) );
	}

	/**
	 * A missing redirect_uri must destroy the code, like any other binding failure.
	 *
	 * Answering differently for a live code than for a dead one, without
	 * spending the code, would let anyone holding a stolen code confirm it is
	 * still redeemable and keep probing. client_id is the client's public URL,
	 * so that probe costs an attacker nothing.
	 */
	public function test_auth_code_destroyed_when_redirect_uri_missing() {
		$code     = $this->set_auth_code();
		$response = $this->create_form(
			'POST',
			array(
				'grant_type' => 'authorization_code',
				'code'       => $code,
				'client_id'  => 'https://app.example.com',
			)
		);
		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_grant', $data['error'], wp_json_encode( $data ) );
		$this->assertFalse( $this->get_auth_code( $code ) );
	}

	/**
	 * The probe must not be repeatable.
	 *
	 * A single request can still tell a live code from one that never existed,
	 * the same as any other binding failure. What matters is that the attempt
	 * spends the code, so an attacker cannot poll a set of harvested codes and
	 * keep the live ones intact for later.
	 */
	public function test_missing_redirect_uri_probe_is_not_repeatable() {
		$code = $this->set_auth_code();

		$probe = function ( $candidate ) {
			return $this->create_form(
				'POST',
				array(
					'grant_type' => 'authorization_code',
					'code'       => $candidate,
					'client_id'  => 'https://app.example.com',
				)
			);
		};

		$probe( $code );

		// The code is gone, so a second probe looks like one for a code that
		// was never issued.
		$second      = $probe( $code );
		$nonexistent = $probe( 'a-code-that-was-never-issued' );

		$this->assertEquals( $nonexistent->get_status(), $second->get_status() );
		$this->assertEquals( $nonexistent->get_data()['error'], $second->get_data()['error'] );
	}

	// A cosmetic client_id difference is the same URL and must still redeem.
	public function test_auth_code_redemption_tolerates_equivalent_client_id() {
		$code     = $this->set_auth_code();
		$response = $this->create_form(
			'POST',
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'client_id'    => 'https://app.example.com/',
				'redirect_uri' => 'https://app.example.com/redirect',
			)
		);
		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
	}

	// An actual mismatch may mean the code leaked, so the code is destroyed.
	public function test_auth_code_destroyed_on_redirect_uri_mismatch() {
		$code     = $this->set_auth_code();
		$response = $this->create_form(
			'POST',
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'client_id'    => 'https://app.example.com',
				'redirect_uri' => 'https://evil.example.com/redirect',
			)
		);
		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_grant', $data['error'], wp_json_encode( $data ) );
		$this->assertFalse( $this->get_auth_code( $code ) );
	}

	// FedCM codes are issued without a redirect, so redemption works without redirect_uri.
	public function test_fedcm_auth_code_redemption_without_redirect_uri() {
		$code_verifier  = 'a6128783714cfda1d388e2e98b6ae8221ac31aca31959e59512c59f5';
		$code_challenge = base64_urlencode( hash( 'sha256', $code_verifier, true ) );

		$tokens = new Token_User( '_indieauth_code_' );
		$tokens->set_user( self::$author_id );
		$code = $tokens->set(
			array(
				'client_id'             => 'https://app.example.com/',
				'scope'                 => 'profile',
				'me'                    => get_author_posts_url( static::$author_id ),
				'user'                  => static::$author_id,
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => 'S256',
				'fedcm'                 => true,
			),
			600
		);

		$response = $this->create_form(
			'POST',
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => 'https://app.example.com/',
				'code_verifier' => $code_verifier,
			)
		);
		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'access_token', $data );
		$this->assertEquals( 'profile', $data['scope'] );
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
		$this->assertArrayHasKey( 'refresh_token', $data );
		$this->assertNotFalse( $data['refresh_token'] );
		unset( $data['refresh_token'] );
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


	// Sets an Auth Code and Redeems it at the Token Endpoint with Profile
		public function test_auth_code_redemption_with_just_profile() {
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
		$this->assertArrayHasKey( 'access_token', $data );
		$this->assertNotFalse( $data['access_token'] );
		unset( $data['access_token'] );
		$this->assertArrayHasKey( 'refresh_token', $data );
		$this->assertNotFalse( $data['refresh_token'] );
		unset( $data['refresh_token'] );
		$this->assertEquals( 
			array( 
				'me' => get_author_posts_url( static::$author_id ),
				'token_type' => 'Bearer',
				'scope' => 'profile',
				'expires_in' => 1209600,
				'profile' => indieauth_get_user( static::$author_id )
			), 
			$data, 
			'Response: ' . wp_json_encode( $data ) 
		);
		static::$test_auth_code['scope'] = 'create';
	}

	// Sets a Refresh Token Then Redeems it Using the Grant Type.
		public function test_refresh_token_grant_type() {
		$refresh_token = $this->set_refresh_token();
		$response = $this->create_form( 'POST', 
				array(
					'grant_type' => 'refresh_token',
					'refresh_token' => $refresh_token,
					'client_id'     => static::$refresh_token['client_id'],
				)
		);
		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'access_token', $data, wp_json_encode( $data ) );
		$this->assertNotFalse( $data['access_token'], wp_json_encode( $data ) );
		unset( $data['access_token'] );
		$this->assertArrayHasKey( 'refresh_token', $data, wp_json_encode( $data ) );
		$this->assertNotFalse( $data['refresh_token'] );
		unset( $data['refresh_token'] );
		$this->assertEquals( 
			array( 
				'me' => get_author_posts_url( static::$author_id ),
				'token_type' => 'Bearer',
				'scope' => 'create update media',
				'expires_in' => 1209600
			), 
			$data, 
			'Response: ' . wp_json_encode( $data ) 
		);
	}


	// Redeems a code carrying auth_time and returns the response data.
	protected function redeem_code_with( $extra ) {
		$tokens = new Token_User( '_indieauth_code_' );
		$tokens->set_user( self::$author_id );
		$code = $tokens->set( array_merge( static::$test_auth_code, $extra ), 600 );

		$response = $this->create_form( 'POST',
			array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'client_id'    => 'https://app.example.com',
				'redirect_uri' => 'https://app.example.com/redirect',
			)
		);
		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	// auth_time on the code is stored on the access and refresh tokens, but not returned to the client.
	public function test_auth_code_redemption_carries_auth_time() {
		$data = $this->redeem_code_with( array( 'auth_time' => 1700000000 ) );

		$this->assertArrayNotHasKey( 'auth_time', $data );

		$access = $this->get_access_token( $data['access_token'] );
		$this->assertSame( 1700000000, $access['auth_time'] );

		$refresh = $this->get_refresh_token( $data['refresh_token'] );
		$this->assertSame( 1700000000, $refresh['auth_time'] );
	}

	// A code without auth_time gives tokens without it.
	public function test_auth_code_redemption_without_auth_time() {
		$data = $this->redeem_code_with( array() );

		$this->assertArrayNotHasKey( 'auth_time', $this->get_access_token( $data['access_token'] ) );
		$this->assertArrayNotHasKey( 'auth_time', $this->get_refresh_token( $data['refresh_token'] ) );
	}

	// auth_time is kept unchanged through refreshes, while iat is reset.
	public function test_refresh_token_keeps_auth_time() {
		$tokens = new Token_User( '_indieauth_refresh_' );
		$tokens->set_user( self::$author_id );
		$refresh_token = $tokens->set( array_merge( static::$refresh_token, array( 'auth_time' => 1700000000 ) ) );

		for ( $i = 0; $i < 2; $i++ ) {
			$response = $this->create_form( 'POST',
				array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
					'client_id'     => static::$refresh_token['client_id'],
				)
			);
			$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
			$data = $response->get_data();

			$this->assertArrayNotHasKey( 'auth_time', $data );

			$access = $this->get_access_token( $data['access_token'] );
			$this->assertSame( 1700000000, $access['auth_time'] );
			$this->assertGreaterThan( static::$refresh_token['iat'], $access['iat'] );

			$refresh_token = $data['refresh_token'];
			$refresh       = $this->get_refresh_token( $refresh_token );
			$this->assertSame( 1700000000, $refresh['auth_time'] );
		}
	}

	/**
	 * Calls of the token and error hooks, hook name => list of argument lists.
	 *
	 * @var array
	 */
	protected $hook_calls = array();

	// Collects the calls of the token and error hooks.
	protected function record_hooks() {
		$this->hook_calls = array(
			'indieauth_token_issued'    => array(),
			'indieauth_token_refreshed' => array(),
			'indieauth_error'           => array(),
		);
		foreach ( array_keys( $this->hook_calls ) as $hook ) {
			add_action(
				$hook,
				function ( ...$args ) use ( $hook ) {
					$this->hook_calls[ $hook ][] = $args;
				},
				10,
				2
			);
		}
	}

	// Exchanging a code fires the issued hook, without the token values and without an error.
	public function test_code_redemption_fires_token_issued() {
		$this->record_hooks();
		$data  = $this->redeem_code_with( array( 'uuid' => 'd2a4f9c0-0000-4000-8000-000000000001' ) );

		$this->assertCount( 1, $this->hook_calls['indieauth_token_issued'] );
		$this->assertCount( 0, $this->hook_calls['indieauth_token_refreshed'] );
		$this->assertCount( 0, $this->hook_calls['indieauth_error'] );

		list( $token, $user_id ) = $this->hook_calls['indieauth_token_issued'][0];
		$this->assertSame( self::$author_id, $user_id );
		$this->assertSame( 'd2a4f9c0-0000-4000-8000-000000000001', $token['uuid'] );
		$this->assertSame( 'https://app.example.com', $token['client_id'] );
		$this->assertSame( 'create', $token['scope'] );
		$this->assertArrayNotHasKey( 'access_token', $token );
		$this->assertArrayNotHasKey( 'refresh_token', $token );
		$this->assertNotContains( $data['access_token'], $token );
		$this->assertNotContains( $data['refresh_token'], $token );
	}

	// A refresh request without client_id is rejected, and the refresh token is kept. IndieAuth section 5.5.1.
	public function test_refresh_token_requires_client_id() {
		$refresh_token = $this->set_refresh_token();
		$response      = $this->create_form( 'POST',
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
			)
		);
		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'invalid_request', $response->get_data()['error'] );
		$this->assertNotFalse( $this->get_refresh_token( $refresh_token ), 'A request without client_id must not cost the client its token.' );
	}

	// A refresh token presented by another client is rejected and destroyed.
	public function test_refresh_token_client_id_mismatch_destroys_token() {
		$refresh_token = $this->set_refresh_token();
		$response      = $this->create_form( 'POST',
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
				'client_id'     => 'https://evil.example.net/',
			)
		);
		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
		$this->assertFalse( $this->get_refresh_token( $refresh_token ) );

		// The real client cannot use it afterwards either.
		$response = $this->create_form( 'POST',
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
				'client_id'     => static::$refresh_token['client_id'],
			)
		);
		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
	}

	// An equivalent client_id, here without the trailing slash, is accepted.
	public function test_refresh_token_tolerates_equivalent_client_id() {
		$refresh_token = $this->set_refresh_token();
		$response      = $this->create_form( 'POST',
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
				'client_id'     => untrailingslashit( static::$refresh_token['client_id'] ),
			)
		);
		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );
	}

	// Using a refresh token fires the refreshed hook and keeps the uuid.
	public function test_refresh_fires_token_refreshed() {
		$tokens = new Token_User( '_indieauth_refresh_' );
		$tokens->set_user( self::$author_id );
		$refresh_token = $tokens->set( static::$refresh_token );

		$this->record_hooks();
		$response = $this->create_form( 'POST',
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
				'client_id'     => static::$refresh_token['client_id'],
			)
		);
		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );

		$this->assertCount( 0, $this->hook_calls['indieauth_token_issued'] );
		$this->assertCount( 1, $this->hook_calls['indieauth_token_refreshed'] );
		$this->assertCount( 0, $this->hook_calls['indieauth_error'] );

		list( $token, $user_id ) = $this->hook_calls['indieauth_token_refreshed'][0];
		$this->assertSame( self::$author_id, $user_id );
		$this->assertSame( static::$refresh_token['uuid'], $token['uuid'] );
		$this->assertArrayNotHasKey( 'access_token', $token );
	}

	// A wrong code is an error a logger wants to see.
	public function test_invalid_code_fires_error() {
		$this->record_hooks();
		$response = $this->create_form( 'POST',
			array(
				'grant_type'   => 'authorization_code',
				'code'         => 'not-a-code',
				'client_id'    => 'https://app.example.com',
				'redirect_uri' => 'https://app.example.com/redirect',
			)
		);

		$this->assertEquals( 400, $response->get_status() );
		$this->assertCount( 1, $this->hook_calls['indieauth_error'] );
		$this->assertSame( 'invalid_grant', $this->hook_calls['indieauth_error'][0][0]->get_data()['error'] );
	}

	// The default errors of the endpoint are only reported when they are returned.
	public function test_unsupported_grant_type_fires_error_once() {
		$this->record_hooks();
		$response = $this->create_form( 'POST', array( 'grant_type' => 'password' ) );

		$this->assertEquals( 400, $response->get_status() );
		$this->assertCount( 1, $this->hook_calls['indieauth_error'] );
		$this->assertSame( 'unsupported_grant_type', $this->hook_calls['indieauth_error'][0][0]->get_data()['error'] );
	}

	// A grant type handled by a filter is not an error.
	public function test_filtered_grant_type_fires_no_error() {
		add_filter(
			'indieauth_token_grant_type_handler',
			function () {
				return new WP_REST_Response( array( 'handled' => true ), 200 );
			}
		);

		$this->record_hooks();
		$response = $this->create_form( 'POST', array( 'grant_type' => 'custom' ) );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 0, $this->hook_calls['indieauth_error'] );
	}

	/**
	 * Sets a token and verifies it using Access Token Verification.
	 *
	 * @expectedIncorrectUsage IndieAuth\Rest\Token_Controller::get
	 */
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
		$this->assertTrue( $response_token['active'] );
		unset( $response_token['user'] );
		unset( $response_token['active'] );
		$this->assertEquals( static::$test_token, $response_token );
	}

	/**
	 * Token verification via GET is no longer part of the spec and must send deprecation signals.
	 *
	 * @expectedIncorrectUsage IndieAuth\Rest\Token_Controller::get
	 */
	public function test_token_verification_sends_deprecation_signals() {
		$token    = self::set_access_token();
		$response = $this->create_form(
				'GET',
				array(),
				array(
					'Authorization' => 'Bearer ' . $token
				)
			);
		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Deprecation', $headers );
		$this->assertArrayHasKey( 'Link', $headers );
		$this->assertStringContainsString( 'rel="deprecation"', $headers['Link'] );
	}

	/**
	 * The deprecation headers belong on the error responses too, not only on success.
	 *
	 * The _doing_it_wrong() notice is deliberately not emitted here: this path is
	 * reachable without any credential, so emitting would let anyone fill the log.
	 */
	public function test_token_verification_sends_deprecation_signals_on_error() {
		$response = $this->create_form( 'GET' );

		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Deprecation', $headers );
		$this->assertArrayHasKey( 'Link', $headers );
		$this->assertStringContainsString( 'rel="deprecation"', $headers['Link'] );
	}

	// To Make Sure that Revokation Works, Test the Helper Function First.
	public function test_test_get_access_token() {
		$token   = self::set_access_token();
		$check = self::get_access_token( $token );
		unset( $check['user'] );
		$this->assertEquals( static::$test_token, $check );
	}

	// Test Unsupported Action
	public function test_unsupported_action() {
		$token   = self::set_access_token();
		$response = $this->create_form( 'POST', 
			array( 
				'action' => 'foo',
			) 
		);
		$data = $response->get_data();
		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$data = $response->get_data();
		$this->assertEquals( 'unsupported_action', $data['error'], wp_json_encode( $data ) );
	}



	/**
	 * Token revocation via action=revoke is no longer part of the spec and must send deprecation signals.
	 *
	 * @expectedIncorrectUsage IndieAuth\Rest\Token_Controller::revoke_action
	 */
	public function test_token_revocation_action_sends_deprecation_signals() {
		$token    = self::set_access_token();
		$response = $this->create_form( 'POST',
			array(
				'action' => 'revoke',
				'token'  => $token,
			)
		);
		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Deprecation', $headers );
		$this->assertArrayHasKey( 'Link', $headers );
		$this->assertStringContainsString( 'rel="deprecation"', $headers['Link'] );
		$this->assertFalse( self::get_access_token( $token ) );
	}

	/**
	 * A revoke call without a token is still a deprecated call and must say so.
	 *
	 * Headers only, for the same reason as the token verification error path.
	 */
	public function test_token_revocation_action_sends_deprecation_signals_on_error() {
		$response = $this->create_form( 'POST',
			array(
				'action' => 'revoke',
			)
		);

		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Deprecation', $headers );
		$this->assertArrayHasKey( 'Link', $headers );
		$this->assertStringContainsString( 'rel="deprecation"', $headers['Link'] );
	}

	/**
	 * Revoking an unknown token keeps its response, but logs nothing.
	 *
	 * Anyone can send any token value here, so only a real revocation may
	 * emit the _doing_it_wrong() notice.
	 */
	public function test_token_revocation_action_with_unknown_token_does_not_log() {
		$response = $this->create_form( 'POST',
			array(
				'action' => 'revoke',
				'token'  => 'not-a-real-token',
			)
		);

		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$this->assertArrayHasKey( 'Deprecation', $response->get_headers() );
	}

	/**
	 * Sets a token, revokes it, then verifies it is not there.
	 *
	 * @expectedIncorrectUsage IndieAuth\Rest\Token_Controller::revoke_action
	 */
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
