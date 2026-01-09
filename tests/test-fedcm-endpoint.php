<?php
/**
 * FedCM Endpoint Tests
 *
 * @package IndieAuth
 */
class FedCMEndpointTest extends WP_UnitTestCase {

	protected static $author_id;
	protected static $author_url;

	public function set_up() {
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		parent::set_up();
	}

	public static function wpSetUpBeforeClass( $factory ) {
		static::$author_id  = $factory->user->create(
			array(
				'role'         => 'author',
				'display_name' => 'Test Author',
			)
		);
		static::$author_url = get_author_posts_url( static::$author_id );
	}

	public static function wpTearDownAfterClass() {
		self::delete_user( self::$author_id );
	}

	/**
	 * Create a REST request for FedCM endpoints.
	 *
	 * @param string $method  HTTP method.
	 * @param string $route   Endpoint route.
	 * @param array  $params  Request parameters.
	 * @param array  $headers Request headers.
	 * @return WP_REST_Response
	 */
	public function create_request( $method, $route, $params = array(), $headers = array() ) {
		$request = new WP_REST_Request( $method, '/indieauth/1.0/fedcm/' . $route );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );

		if ( ! empty( $params ) ) {
			if ( 'GET' === $method ) {
				$request->set_query_params( $params );
			} else {
				$request->set_body_params( $params );
			}
		}

		if ( ! empty( $headers ) ) {
			$request->set_headers( $headers );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Test config endpoint returns valid configuration.
	 */
	public function test_config_endpoint_returns_valid_config() {
		$response = $this->create_request( 'GET', 'config.json' );

		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'accounts_endpoint', $data );
		$this->assertArrayHasKey( 'client_metadata_endpoint', $data );
		$this->assertArrayHasKey( 'id_assertion_endpoint', $data );
		$this->assertArrayHasKey( 'login_url', $data );

		// Verify URLs are absolute.
		$this->assertStringStartsWith( 'http', $data['accounts_endpoint'] );
		$this->assertStringStartsWith( 'http', $data['client_metadata_endpoint'] );
		$this->assertStringStartsWith( 'http', $data['id_assertion_endpoint'] );
	}

	/**
	 * Test accounts endpoint returns 400 without Sec-Fetch-Dest header.
	 */
	public function test_accounts_endpoint_requires_sec_fetch_dest_header() {
		wp_set_current_user( self::$author_id );

		$response = $this->create_request( 'GET', 'accounts' );

		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();
		$this->assertEquals( 'Missing or invalid Sec-Fetch-Dest header', $data['error'] );
	}

	/**
	 * Test accounts endpoint returns 401 when not logged in.
	 */
	public function test_accounts_endpoint_requires_login() {
		wp_set_current_user( 0 );

		$response = $this->create_request(
			'GET',
			'accounts',
			array(),
			array( 'Sec-Fetch-Dest' => 'webidentity' )
		);

		$this->assertEquals( 401, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();
		$this->assertEquals( 'Not logged in', $data['error'] );
	}

	/**
	 * Test accounts endpoint returns user data when properly authenticated.
	 */
	public function test_accounts_endpoint_returns_user_data() {
		wp_set_current_user( self::$author_id );

		$response = $this->create_request(
			'GET',
			'accounts',
			array(),
			array( 'Sec-Fetch-Dest' => 'webidentity' )
		);

		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'accounts', $data );
		$this->assertCount( 1, $data['accounts'] );

		$account = $data['accounts'][0];
		$this->assertArrayHasKey( 'id', $account );
		$this->assertArrayHasKey( 'name', $account );
		$this->assertEquals( 'Test Author', $account['name'] );
	}

	/**
	 * Test client_metadata endpoint returns empty object for unknown client.
	 */
	public function test_client_metadata_endpoint_returns_empty_for_unknown_client() {
		$response = $this->create_request(
			'GET',
			'client_metadata',
			array( 'client_id' => 'https://unknown-client.example.com/' )
		);

		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();
		$this->assertIsArray( $data );
	}

	/**
	 * Test assertion endpoint requires Sec-Fetch-Dest header.
	 */
	public function test_assertion_endpoint_requires_sec_fetch_dest_header() {
		wp_set_current_user( self::$author_id );

		$response = $this->create_request(
			'POST',
			'assertion',
			array(
				'client_id'  => 'https://app.example.com/',
				'account_id' => self::$author_url,
			)
		);

		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();
		$this->assertEquals( 'Missing or invalid Sec-Fetch-Dest header', $data['error'] );
	}

	/**
	 * Test assertion endpoint requires Origin header matching client_id.
	 */
	public function test_assertion_endpoint_requires_matching_origin() {
		wp_set_current_user( self::$author_id );

		$response = $this->create_request(
			'POST',
			'assertion',
			array(
				'client_id'  => 'https://app.example.com/',
				'account_id' => self::$author_url,
			),
			array(
				'Sec-Fetch-Dest' => 'webidentity',
				'Origin'         => 'https://different-origin.example.com',
			)
		);

		$this->assertEquals( 403, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();
		$this->assertEquals( 'Origin mismatch', $data['error'] );
	}

	/**
	 * Test assertion endpoint requires Origin scheme to match client_id scheme.
	 */
	public function test_assertion_endpoint_requires_matching_origin_scheme() {
		wp_set_current_user( self::$author_id );

		$response = $this->create_request(
			'POST',
			'assertion',
			array(
				'client_id'  => 'https://app.example.com/',
				'account_id' => self::$author_url,
			),
			array(
				'Sec-Fetch-Dest' => 'webidentity',
				'Origin'         => 'http://app.example.com', // HTTP instead of HTTPS.
			)
		);

		$this->assertEquals( 403, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();
		$this->assertEquals( 'Origin mismatch', $data['error'] );
	}

	/**
	 * Test assertion endpoint requires login.
	 */
	public function test_assertion_endpoint_requires_login() {
		wp_set_current_user( 0 );

		$response = $this->create_request(
			'POST',
			'assertion',
			array(
				'client_id'  => 'https://app.example.com/',
				'account_id' => self::$author_url,
			),
			array(
				'Sec-Fetch-Dest' => 'webidentity',
				'Origin'         => 'https://app.example.com',
			)
		);

		$this->assertEquals( 401, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();
		$this->assertEquals( 'Not logged in', $data['error'] );
	}

	/**
	 * Test assertion endpoint requires PKCE parameters.
	 */
	public function test_assertion_endpoint_requires_pkce() {
		wp_set_current_user( self::$author_id );

		$response = $this->create_request(
			'POST',
			'assertion',
			array(
				'client_id'  => 'https://app.example.com/',
				'account_id' => self::$author_url,
			),
			array(
				'Sec-Fetch-Dest' => 'webidentity',
				'Origin'         => 'https://app.example.com',
			)
		);

		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();
		$this->assertEquals( 'PKCE parameters required', $data['error'] );
	}

	/**
	 * Test assertion endpoint requires S256 code_challenge_method.
	 */
	public function test_assertion_endpoint_requires_s256_method() {
		wp_set_current_user( self::$author_id );

		$params = wp_json_encode(
			array(
				'code_challenge'        => 'test_challenge',
				'code_challenge_method' => 'plain',
			)
		);

		$response = $this->create_request(
			'POST',
			'assertion',
			array(
				'client_id'  => 'https://app.example.com/',
				'account_id' => self::$author_url,
				'params'     => $params,
			),
			array(
				'Sec-Fetch-Dest' => 'webidentity',
				'Origin'         => 'https://app.example.com',
			)
		);

		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();
		$this->assertEquals( 'Unsupported code_challenge_method', $data['error'] );
	}

	/**
	 * Test assertion endpoint rejects invalid JSON in params.
	 */
	public function test_assertion_endpoint_rejects_invalid_json() {
		wp_set_current_user( self::$author_id );

		$response = $this->create_request(
			'POST',
			'assertion',
			array(
				'client_id'  => 'https://app.example.com/',
				'account_id' => self::$author_url,
				'params'     => '{invalid json}',
			),
			array(
				'Sec-Fetch-Dest' => 'webidentity',
				'Origin'         => 'https://app.example.com',
			)
		);

		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();
		$this->assertEquals( 'Invalid JSON in params', $data['error'] );
	}

	/**
	 * Test assertion endpoint issues valid authorization code.
	 */
	public function test_assertion_endpoint_issues_authorization_code() {
		wp_set_current_user( self::$author_id );

		$code_verifier  = 'a6128783714cfda1d388e2e98b6ae8221ac31aca31959e59512c59f5';
		$code_challenge = base64_urlencode( hash( 'sha256', $code_verifier, true ) );

		$params = wp_json_encode(
			array(
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => 'S256',
			)
		);

		$response = $this->create_request(
			'POST',
			'assertion',
			array(
				'client_id'  => 'https://app.example.com/',
				'account_id' => self::$author_url,
				'params'     => $params,
			),
			array(
				'Sec-Fetch-Dest' => 'webidentity',
				'Origin'         => 'https://app.example.com',
			)
		);

		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'token', $data );

		$token_data = json_decode( $data['token'], true );
		$this->assertArrayHasKey( 'code', $token_data );
		$this->assertArrayHasKey( 'metadata_endpoint', $token_data );
		$this->assertNotEmpty( $token_data['code'] );
	}

	/**
	 * Test assertion endpoint verifies account_id matches current user.
	 */
	public function test_assertion_endpoint_verifies_account_id() {
		wp_set_current_user( self::$author_id );

		$params = wp_json_encode(
			array(
				'code_challenge'        => 'test_challenge',
				'code_challenge_method' => 'S256',
			)
		);

		$response = $this->create_request(
			'POST',
			'assertion',
			array(
				'client_id'  => 'https://app.example.com/',
				'account_id' => 'https://different-user.example.com/',
				'params'     => $params,
			),
			array(
				'Sec-Fetch-Dest' => 'webidentity',
				'Origin'         => 'https://app.example.com',
			)
		);

		$this->assertEquals( 403, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();
		$this->assertEquals( 'Account mismatch', $data['error'] );
	}

	/**
	 * Test assertion endpoint stores nonce in authorization code.
	 */
	public function test_assertion_endpoint_stores_nonce() {
		wp_set_current_user( self::$author_id );

		$code_verifier  = 'a6128783714cfda1d388e2e98b6ae8221ac31aca31959e59512c59f5';
		$code_challenge = base64_urlencode( hash( 'sha256', $code_verifier, true ) );

		$params = wp_json_encode(
			array(
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => 'S256',
			)
		);

		$response = $this->create_request(
			'POST',
			'assertion',
			array(
				'client_id'  => 'https://app.example.com/',
				'account_id' => self::$author_url,
				'params'     => $params,
				'nonce'      => 'test-nonce-12345',
			),
			array(
				'Sec-Fetch-Dest' => 'webidentity',
				'Origin'         => 'https://app.example.com',
			)
		);

		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data       = $response->get_data();
		$token_data = json_decode( $data['token'], true );

		// Verify the code was stored with the nonce.
		$tokens    = new Token_User( '_indieauth_code_' );
		$code_data = $tokens->get( $token_data['code'] );

		$this->assertArrayHasKey( 'nonce', $code_data );
		$this->assertEquals( 'test-nonce-12345', $code_data['nonce'] );
	}

	/**
	 * Test that FedCM-issued codes are marked as such.
	 */
	public function test_assertion_endpoint_marks_code_as_fedcm() {
		wp_set_current_user( self::$author_id );

		$code_verifier  = 'a6128783714cfda1d388e2e98b6ae8221ac31aca31959e59512c59f5';
		$code_challenge = base64_urlencode( hash( 'sha256', $code_verifier, true ) );

		$params = wp_json_encode(
			array(
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => 'S256',
			)
		);

		$response = $this->create_request(
			'POST',
			'assertion',
			array(
				'client_id'  => 'https://app.example.com/',
				'account_id' => self::$author_url,
				'params'     => $params,
			),
			array(
				'Sec-Fetch-Dest' => 'webidentity',
				'Origin'         => 'https://app.example.com',
			)
		);

		$this->assertEquals( 200, $response->get_status() );

		$data       = $response->get_data();
		$token_data = json_decode( $data['token'], true );

		// Verify the code is marked as FedCM-issued.
		$tokens    = new Token_User( '_indieauth_code_' );
		$code_data = $tokens->get( $token_data['code'] );

		$this->assertArrayHasKey( 'fedcm', $code_data );
		$this->assertTrue( $code_data['fedcm'] );
	}
}
