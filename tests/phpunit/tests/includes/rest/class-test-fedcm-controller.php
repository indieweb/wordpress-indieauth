<?php
/**
 * FedCM Endpoint Tests file.
 *
 * @package IndieAuth
 */

use IndieAuth\Token\User as Token_User;

/**
 * FedCM Endpoint Tests.
 */
class Test_FedCM_Controller extends WP_UnitTestCase {

	protected static $author_id;
	protected static $author_url;

	protected $original_request_uri;

	public function set_up() {
		global $wp_rest_server;
		parent::set_up();

		$this->original_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		// Set the author URL to match what get_url_from_user() returns.
		static::$author_url = get_url_from_user( static::$author_id );

		// Override indieauth_validate_user_identifier for tests.
		add_filter( 'rest_request_before_callbacks', array( $this, 'allow_test_user_identifier' ), 10, 3 );
	}

	/**
	 * Allow test user identifiers to pass validation.
	 *
	 * @param WP_REST_Response|WP_Error $response Result to send.
	 * @param array                     $handler  Route handler used.
	 * @param WP_REST_Request           $request  Request used.
	 * @return WP_REST_Response|WP_Error
	 */
	public function allow_test_user_identifier( $response, $handler, $request ) {
		// If validation failed for account_id, check if it's our test URL.
		if ( is_wp_error( $response ) && 'rest_invalid_param' === $response->get_error_code() ) {
			$error_data = $response->get_error_data();
			if ( isset( $error_data['params']['account_id'] ) ) {
				$account_id = $request->get_param( 'account_id' );
				// Allow test URLs that contain the author ID.
				if ( $account_id === static::$author_url ) {
					// Only clear the error if account_id is the only invalid param.
					if ( 1 === count( $error_data['params'] ) ) {
						return null;
					}
				}
			}
		}
		return $response;
	}

	public function tear_down() {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '' );
		$wp_rewrite->flush_rules();
		unset( $GLOBALS['wp_rest_auth_cookie'] );
		unset( $GLOBALS['wp']->query_vars['rest_route'] );
		unset( $_SERVER['HTTP_SEC_FETCH_DEST'] );
		$_SERVER['REQUEST_URI'] = $this->original_request_uri;
		parent::tear_down();
	}

	public static function wpSetUpBeforeClass( $factory ) {
		static::$author_id = $factory->user->create(
			array(
				'role'         => 'author',
				'display_name' => 'Test Author',
				'user_nicename' => 'testauthor',
			)
		);
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
	 * Send an assertion request with valid PKCE parameters and FedCM headers.
	 *
	 * @param array  $body_overrides   Overrides merged into the request body.
	 * @param array  $params_overrides Overrides merged into the JSON params field.
	 * @param string $origin           Origin header value.
	 * @return WP_REST_Response
	 */
	public function assertion_request( $body_overrides = array(), $params_overrides = array(), $origin = 'https://app.example.com' ) {
		$code_verifier = 'a6128783714cfda1d388e2e98b6ae8221ac31aca31959e59512c59f5';

		$params = wp_json_encode(
			array_merge(
				array(
					'code_challenge'        => base64_urlencode( hash( 'sha256', $code_verifier, true ) ),
					'code_challenge_method' => 'S256',
				),
				$params_overrides
			)
		);

		return $this->create_request(
			'POST',
			'assertion',
			array_merge(
				array(
					'client_id'  => 'https://app.example.com/',
					'account_id' => self::$author_url,
					'params'     => $params,
				),
				$body_overrides
			),
			array(
				'Sec-Fetch-Dest' => 'webidentity',
				'Origin'         => $origin,
			)
		);
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
	 * Test config endpoint sends public CORS headers without credentials.
	 */
	public function test_config_endpoint_uses_public_cors() {
		$response = $this->create_request(
			'GET',
			'config.json',
			array(),
			array( 'Origin' => 'https://app.example.com' )
		);

		$headers = $response->get_headers();
		$this->assertEquals( '*', $headers['Access-Control-Allow-Origin'] );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Credentials', $headers );
	}

	/**
	 * Test client_metadata endpoint sends public CORS headers without credentials.
	 */
	public function test_client_metadata_endpoint_uses_public_cors() {
		$response = $this->create_request(
			'GET',
			'client_metadata',
			array( 'client_id' => 'https://unknown-client.example.com/' ),
			array( 'Origin' => 'https://app.example.com' )
		);

		$headers = $response->get_headers();
		$this->assertEquals( '*', $headers['Access-Control-Allow-Origin'] );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Credentials', $headers );
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
				'account_id' => self::$author_url,
				'params'     => $params,
			)
		);

		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();
		$this->assertEquals( 'invalid_request', $data['error']['code'] );
	}

	/**
	 * Test assertion endpoint requires Origin header matching client_id.
	 */
	public function test_assertion_endpoint_requires_matching_origin() {
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
				'account_id' => self::$author_url,
				'params'     => $params,
			),
			array(
				'Sec-Fetch-Dest' => 'webidentity',
				'Origin'         => 'https://different-origin.example.com',
			)
		);

		$this->assertEquals( 403, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();
		$this->assertEquals( 'unauthorized_client', $data['error']['code'] );
	}

	/**
	 * Test assertion endpoint requires Origin scheme to match client_id scheme.
	 */
	public function test_assertion_endpoint_requires_matching_origin_scheme() {
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
				'account_id' => self::$author_url,
				'params'     => $params,
			),
			array(
				'Sec-Fetch-Dest' => 'webidentity',
				'Origin'         => 'http://app.example.com', // HTTP instead of HTTPS.
			)
		);

		$this->assertEquals( 403, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();
		$this->assertEquals( 'unauthorized_client', $data['error']['code'] );
	}

	/**
	 * Test assertion endpoint requires Origin port to match client_id port.
	 */
	public function test_assertion_endpoint_requires_matching_origin_port() {
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
				'client_id'  => 'https://app.example.com:8080/',
				'account_id' => self::$author_url,
				'params'     => $params,
			),
			array(
				'Sec-Fetch-Dest' => 'webidentity',
				'Origin'         => 'https://app.example.com', // Missing port.
			)
		);

		$this->assertEquals( 403, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();
		$this->assertEquals( 'unauthorized_client', $data['error']['code'] );
	}

	/**
	 * Test assertion endpoint matches the origin case-insensitively.
	 *
	 * Browsers send a lowercased Origin, but a client can register a client_id
	 * with any casing. Schemes and hosts are case-insensitive, so the two still
	 * have to be treated as the same origin.
	 */
	public function test_assertion_endpoint_matches_origin_case_insensitively() {
		wp_set_current_user( self::$author_id );

		$response = $this->assertion_request(
			array( 'client_id' => 'HTTPS://App.Example.com/' ),
			array(),
			'https://app.example.com'
		);

		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
	}

	/**
	 * Test assertion endpoint treats an explicit default port as equal to no port.
	 *
	 * Browsers omit default ports from the Origin header, so a client_id
	 * registered as https://app.example.com:443/ must still match.
	 */
	public function test_assertion_endpoint_accepts_explicit_default_port() {
		wp_set_current_user( self::$author_id );

		$response = $this->assertion_request( array( 'client_id' => 'https://app.example.com:443/' ) );

		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
	}

	/**
	 * Test assertion endpoint requires login.
	 */
	public function test_assertion_endpoint_requires_login() {
		wp_set_current_user( 0 );

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
				'account_id' => self::$author_url,
				'params'     => $params,
			),
			array(
				'Sec-Fetch-Dest' => 'webidentity',
				'Origin'         => 'https://app.example.com',
			)
		);

		$this->assertEquals( 401, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data = $response->get_data();
		$this->assertEquals( 'access_denied', $data['error']['code'] );
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
				'params'     => wp_json_encode( array() ), // Empty params - missing PKCE.
			),
			array(
				'Sec-Fetch-Dest' => 'webidentity',
				'Origin'         => 'https://app.example.com',
			)
		);

		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response->get_data() ) );

		$data = $response->get_data();
		$this->assertEquals( 'rest_invalid_param', $data['error'] );
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
		$this->assertEquals( 'rest_invalid_param', $data['error'] );
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
		$this->assertEquals( 'rest_invalid_param', $data['error'] );
	}

	/**
	 * Test assertion endpoint issues valid authorization code.
	 */
	public function test_assertion_endpoint_issues_authorization_code() {
		wp_set_current_user( self::$author_id );

		$response = $this->assertion_request();

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
		$this->assertEquals( 'access_denied', $data['error']['code'] );
	}

	/**
	 * Test assertion endpoint stores nonce in authorization code.
	 */
	public function test_assertion_endpoint_stores_nonce() {
		wp_set_current_user( self::$author_id );

		$response = $this->assertion_request( array( 'nonce' => 'test-nonce-12345' ) );

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
	 * Test assertion endpoint clamps RP-requested scopes to identity scopes.
	 *
	 * The FedCM browser UI never displays scopes, so scopes beyond
	 * profile/email must not be granted without a real consent screen.
	 */
	public function test_assertion_endpoint_clamps_scope_to_identity_scopes() {
		wp_set_current_user( self::$author_id );

		$response = $this->assertion_request( array(), array( 'scope' => 'create update profile email' ) );

		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data       = $response->get_data();
		$token_data = json_decode( $data['token'], true );

		$tokens    = new Token_User( '_indieauth_code_' );
		$code_data = $tokens->get( $token_data['code'] );

		$this->assertEquals( 'profile email', $code_data['scope'] );
	}

	/**
	 * An explicit empty scope must not turn into a profile access token.
	 *
	 * A client that asks for nothing gets an identity response, the same as
	 * before the clamp existed. Falling back to 'profile' would hand out a
	 * bearer token the client never requested.
	 */
	public function test_assertion_endpoint_grants_no_scope_when_none_requested() {
		wp_set_current_user( self::$author_id );

		$response = $this->assertion_request( array(), array( 'scope' => '' ) );

		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data       = $response->get_data();
		$token_data = json_decode( $data['token'], true );

		$tokens    = new Token_User( '_indieauth_code_' );
		$code_data = $tokens->get( $token_data['code'] );

		$this->assertArrayNotHasKey( 'scope', $code_data );
	}

	/**
	 * A request for only non-identity scopes must not become a profile token.
	 *
	 * The clamp cannot grant 'create', and quietly substituting 'profile'
	 * hands the client authority it did not ask for.
	 */
	public function test_assertion_endpoint_grants_no_scope_when_none_survive_the_clamp() {
		wp_set_current_user( self::$author_id );

		$response = $this->assertion_request( array(), array( 'scope' => 'create update' ) );

		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data       = $response->get_data();
		$token_data = json_decode( $data['token'], true );

		$tokens    = new Token_User( '_indieauth_code_' );
		$code_data = $tokens->get( $token_data['code'] );

		$this->assertArrayNotHasKey( 'scope', $code_data );
	}

	/**
	 * Test the stored scope is canonical when a scope is requested twice.
	 *
	 * A repeated scope grants nothing extra, so it must not end up in the
	 * stored scope string either.
	 */
	public function test_assertion_endpoint_stores_each_scope_once() {
		wp_set_current_user( self::$author_id );

		$response = $this->assertion_request( array(), array( 'scope' => 'profile email profile' ) );

		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );

		$data       = $response->get_data();
		$token_data = json_decode( $data['token'], true );

		$tokens    = new Token_User( '_indieauth_code_' );
		$code_data = $tokens->get( $token_data['code'] );

		$this->assertEquals( 'profile email', $code_data['scope'] );
	}

	/**
	 * Data provider for the REST nonce exemption checks.
	 *
	 * @return array[] Sec-Fetch-Dest header (null to omit), dispatched route, whether a valid auth cookie was sent, whether the cookie user is kept.
	 */
	public function rest_nonce_exemption_provider() {
		return array(
			'FedCM request to a FedCM route is exempt'     => array( 'webidentity', '/indieauth/1.0/fedcm/accounts', true, true ),
			'request without FedCM header is not exempt'   => array( null, '/wp/v2/posts', true, false ),
			'FedCM header on another route is not exempt'  => array( 'webidentity', '/wp/v2/posts', true, false ),
			'FedCM path in a query string is not exempt'   => array( 'webidentity', '/wp/v2/posts?s=/indieauth/1.0/fedcm/accounts', true, false ),
		);
	}

	/**
	 * Test which cookie-authenticated requests without a nonce keep their user.
	 *
	 * The browser's FedCM machinery cannot send a REST nonce, so FedCM
	 * requests to FedCM routes are exempt from the nonce check; everything
	 * else must stay unauthenticated.
	 *
	 * @dataProvider rest_nonce_exemption_provider
	 *
	 * @param string|null $sec_fetch_dest Sec-Fetch-Dest header value, or null to omit the header.
	 * @param string      $rest_route     The route the REST server dispatched.
	 * @param bool        $auth_cookie    Whether the request carried a valid auth cookie.
	 * @param bool        $exempt         Whether the cookie-authenticated user should be kept.
	 */
	public function test_rest_nonce_exemption( $sec_fetch_dest, $rest_route, $auth_cookie, $exempt ) {
		wp_set_current_user( self::$author_id );

		// Simulate a request without a nonce.
		if ( $auth_cookie ) {
			$GLOBALS['wp_rest_auth_cookie'] = true;
		}
		if ( null === $sec_fetch_dest ) {
			unset( $_SERVER['HTTP_SEC_FETCH_DEST'] );
		} else {
			$_SERVER['HTTP_SEC_FETCH_DEST'] = $sec_fetch_dest;
		}
		$GLOBALS['wp']->query_vars['rest_route'] = $rest_route;

		$result = apply_filters( 'rest_authentication_errors', null );

		$this->assertNotWPError( $result );
		$this->assertEquals( $exempt ? self::$author_id : 0, get_current_user_id() );
	}

	/**
	 * Test that a FedCM request without a valid auth cookie is not exempted.
	 *
	 * The exemption exists to keep a cookie-authenticated user that cannot send
	 * a nonce. With no cookie there is nothing to preserve, so the request has
	 * to fall through to the normal REST authentication pipeline.
	 */
	public function test_rest_nonce_exemption_requires_auth_cookie() {
		$controller = new IndieAuth\Rest\FedCM_Controller();

		wp_set_current_user( self::$author_id );
		$_SERVER['HTTP_SEC_FETCH_DEST']          = 'webidentity';
		$GLOBALS['wp']->query_vars['rest_route'] = '/indieauth/1.0/fedcm/accounts';

		// No valid auth cookie was collected for this request.
		unset( $GLOBALS['wp_rest_auth_cookie'] );
		$controller->rest_authentication_errors( null );
		$this->assertNotFalse( has_filter( 'rest_authentication_errors', 'rest_cookie_check_errors' ) );

		// A cookie that failed validation must not qualify either.
		$GLOBALS['wp_rest_auth_cookie'] = 'bad_hash';
		$controller->rest_authentication_errors( null );
		$this->assertNotFalse( has_filter( 'rest_authentication_errors', 'rest_cookie_check_errors' ) );

		$GLOBALS['wp_rest_auth_cookie'] = true;
		$controller->rest_authentication_errors( null );
		$this->assertFalse( has_filter( 'rest_authentication_errors', 'rest_cookie_check_errors' ) );
	}

	/**
	 * The exemption must not end the filter chain for other plugins.
	 *
	 * Returning true from rest_authentication_errors stops every later callback,
	 * which would silently skip a REST hardening plugin's policy on FedCM routes.
	 */
	public function test_rest_nonce_exemption_leaves_other_auth_filters_running() {
		$controller = new IndieAuth\Rest\FedCM_Controller();

		wp_set_current_user( self::$author_id );
		$_SERVER['HTTP_SEC_FETCH_DEST']          = 'webidentity';
		$GLOBALS['wp']->query_vars['rest_route'] = '/indieauth/1.0/fedcm/accounts';
		$GLOBALS['wp_rest_auth_cookie']          = true;

		$denied = new WP_Error( 'rest_forbidden', 'Blocked by policy.' );
		$deny   = function () use ( $denied ) {
			return $denied;
		};
		add_filter( 'rest_authentication_errors', $deny, 20 );

		$result = apply_filters( 'rest_authentication_errors', null );

		remove_filter( 'rest_authentication_errors', $deny, 20 );

		$this->assertWPError( $result );
		$this->assertEquals( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that FedCM-issued codes are marked as such.
	 */
	public function test_assertion_endpoint_marks_code_as_fedcm() {
		wp_set_current_user( self::$author_id );

		$response = $this->assertion_request();

		$this->assertEquals( 200, $response->get_status() );

		$data       = $response->get_data();
		$token_data = json_decode( $data['token'], true );

		// Verify the code is marked as FedCM-issued.
		$tokens    = new Token_User( '_indieauth_code_' );
		$code_data = $tokens->get( $token_data['code'] );

		$this->assertArrayHasKey( 'fedcm', $code_data );
		$this->assertTrue( $code_data['fedcm'] );
	}

	public function unusable_code_challenge_provider() {
		return array(
			'array'         => array( array( 'abc' ) ),
			'nested array'  => array( array( 'a' => 'b' ) ),
			'boolean true'  => array( true ),
			'whitespace'    => array( '   ' ),
		);
	}

	/**
	 * A code_challenge that cannot be used must be rejected at the assertion.
	 *
	 * `empty()` accepts a non-empty array, `sanitize_text_field()` turns it into
	 * an empty string, and `array_filter()` then drops the key entirely. The
	 * stored code would carry no challenge, and the token endpoint only verifies
	 * PKCE when the code has one, so the binding would be gone with no error
	 * anywhere along the way.
	 *
	 * @dataProvider unusable_code_challenge_provider
	 *
	 * @param mixed $code_challenge The code_challenge an RP sends.
	 */
	public function test_assertion_endpoint_rejects_unusable_code_challenge( $code_challenge ) {
		wp_set_current_user( self::$author_id );

		$response = $this->create_request(
			'POST',
			'assertion',
			array(
				'client_id'  => 'https://app.example.com/',
				'account_id' => self::$author_url,
				'params'     => array(
					'code_challenge'        => $code_challenge,
					'code_challenge_method' => 'S256',
				),
			),
			array(
				'Sec-Fetch-Dest' => 'webidentity',
				'Origin'         => 'https://app.example.com',
			)
		);

		$this->assertNotEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
	}

	/**
	 * No FedCM code may ever be stored without its PKCE challenge.
	 *
	 * This is the property that matters: the token endpoint skips PKCE
	 * verification for a code that has no challenge, so a code stored without
	 * one is redeemable by anyone who obtains it.
	 */
	public function test_assertion_endpoint_never_stores_a_code_without_a_challenge() {
		wp_set_current_user( self::$author_id );

		$response = $this->create_request(
			'POST',
			'assertion',
			array(
				'client_id'  => 'https://app.example.com/',
				'account_id' => self::$author_url,
				'params'     => array(
					'code_challenge'        => array( 'abc' ),
					'code_challenge_method' => 'S256',
				),
			),
			array(
				'Sec-Fetch-Dest' => 'webidentity',
				'Origin'         => 'https://app.example.com',
			)
		);

		$data = $response->get_data();
		if ( ! isset( $data['token'] ) ) {
			// Rejected outright, which is the expected outcome.
			$this->assertNotEquals( 200, $response->get_status() );
			return;
		}

		$token_data = json_decode( $data['token'], true );
		$tokens     = new Token_User( '_indieauth_code_' );
		$code_data  = $tokens->get( $token_data['code'] );

		$this->assertArrayHasKey( 'code_challenge', $code_data, 'Code was stored with no PKCE challenge.' );
		$this->assertNotEmpty( $code_data['code_challenge'] );
	}
}
