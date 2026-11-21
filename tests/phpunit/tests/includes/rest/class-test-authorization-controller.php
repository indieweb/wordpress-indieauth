<?php

use IndieAuth\Token\User as Token_User;

// The consent templates use these functions of wp-login.php, which cannot be loaded in a test.
if ( ! function_exists( 'login_header' ) ) {
	function login_header() {}
}
if ( ! function_exists( 'login_footer' ) ) {
	function login_footer() {}
}

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

	public function tear_down() {
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Submit the consent form as the author and return the stored authorization code.
	 *
	 * The redirect is captured through the wp_redirect filter, which also stops
	 * the header from being sent.
	 *
	 * @return array Stored code data.
	 */
	protected function submit_consent_and_get_code() {
		$location = null;
		$capture  = function ( $url ) use ( &$location ) {
			$location = $url;
			return false;
		};
		add_filter( 'wp_redirect', $capture );

		// The nonce depends on the session token, so it is created after the cookie is set.
		$_POST = array(
			'_wpnonce'              => wp_create_nonce( 'indieauth_authorize' ),
			'client_id'             => 'https://app.example.com/',
			'redirect_uri'          => 'https://app.example.com/redirect',
			'scope'                 => array( 'create' ),
			'state'                 => 'abc123',
			'response_type'         => 'code',
			'code_challenge'        => 'OfYAxt8zU2dAPDWQxTAUIteRzMsoj9QBdMIVEDOErUo',
			'code_challenge_method' => 'S256',
		);

		$controller = new IndieAuth\Rest\Authorization_Controller();
		$controller->confirmed();

		remove_filter( 'wp_redirect', $capture );

		$this->assertNotNull( $location, 'confirmed() did not redirect' );
		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$this->assertArrayHasKey( 'code', $query );

		return $this->get_auth_code( $query['code'] );
	}

	// The consent form stores the session login time with the code.
	public function test_confirmed_stores_auth_time() {
		wp_set_current_user( self::$author_id );

		$manager = WP_Session_Tokens::get_instance( self::$author_id );
		$session = $manager->create( time() + DAY_IN_SECONDS );
		$data    = $manager->get( $session );

		$data['login'] = 1700000000;
		$manager->update( $session, $data );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( self::$author_id, time() + DAY_IN_SECONDS, 'logged_in', $session );

		$code = $this->submit_consent_and_get_code();

		$this->assertSame( 'create', $code['scope'] );
		$this->assertSame( 1700000000, $code['auth_time'] );
	}

	// Without a session login time, auth_time is left out of the code.
	public function test_confirmed_omits_auth_time_without_session() {
		wp_set_current_user( self::$author_id );

		$code = $this->submit_consent_and_get_code();

		$this->assertSame( 'create', $code['scope'] );
		$this->assertArrayNotHasKey( 'auth_time', $code );
	}

	// The hook gets what is stored with the code, so a logger can follow it by its uuid.
	public function test_confirmed_fires_authorization_approved() {
		wp_set_current_user( self::$author_id );

		$calls = array();
		add_action(
			'indieauth_authorization_approved',
			function ( $token, $user_id ) use ( &$calls ) {
				$calls[] = compact( 'token', 'user_id' );
			},
			10,
			2
		);

		$code = $this->submit_consent_and_get_code();

		$this->assertCount( 1, $calls );
		$this->assertSame( self::$author_id, $calls[0]['user_id'] );
		$this->assertSame( 'https://app.example.com/', $calls[0]['token']['client_id'] );
		$this->assertSame( 'create', $calls[0]['token']['scope'] );
		$this->assertSame( $code['uuid'], $calls[0]['token']['uuid'] );
	}

	// The consent form hands the user and the client to plugins that add their own fields.
	public function test_authorization_form_action_gets_the_user_and_client() {
		wp_set_current_user( self::$author_id );

		$calls = array();
		add_action(
			'indieauth_authorization_form',
			function ( $user_id, $client_id ) use ( &$calls ) {
				$calls[] = array( $user_id, $client_id );
			},
			10,
			2
		);

		// A client that is known already, so nothing is fetched.
		IndieAuth\Client_Taxonomy::add_client( 'https://app.example.com/', 'Example App' );

		$_GET = array(
			'client_id'             => 'https://app.example.com/',
			'redirect_to'           => 'https://app.example.com/redirect',
			'scope'                 => 'create',
			'state'                 => 'abc123',
			'response_type'         => 'code',
			'code_challenge'        => 'OfYAxt8zU2dAPDWQxTAUIteRzMsoj9QBdMIVEDOErUo',
			'code_challenge_method' => 'S256',
		);

		ob_start();
		( new IndieAuth\Rest\Authorization_Controller() )->authorize();
		$output = ob_get_clean();

		$_GET = array();

		$this->assertSame( array( array( self::$author_id, 'https://app.example.com/' ) ), $calls );
		$this->assertStringContainsString( get_userdata( self::$author_id )->display_name, $output );
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

	/**
	 * An invalid legacy request still gets the headers, but logs nothing.
	 *
	 * The endpoint is public, so the _doing_it_wrong() notice may only fire
	 * once the request passed validation.
	 */
	public function test_invalid_legacy_request_does_not_log() {
		$none = function () {
			return array();
		};
		add_filter( 'pre_indieauth_client_redirect_uris', $none );

		// A cross-origin redirect_uri the client never published.
		$response = $this->create_get(
			array(
				'response_type'         => 'id',
				'client_id'             => 'https://app.example.com',
				'redirect_uri'          => 'https://evil.example.net/cb',
				'state'                 => '12345',
				'code_challenge'        => 'OfYAxt8zU2dAPDWQxTAUIteRzMsoj9QBdMIVEDOErUo',
				'code_challenge_method' => 'S256',
			)
		);
		remove_filter( 'pre_indieauth_client_redirect_uris', $none );

		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$this->assertArrayHasKey( 'Deprecation', $response->get_headers() );
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

	// FedCM codes are issued without a redirect, so redemption works without redirect_uri.
	public function test_fedcm_auth_code_redemption_without_redirect_uri() {
		$tokens = new Token_User( '_indieauth_code_' );
		$tokens->set_user( self::$author_id );
		$code = $tokens->set(
			array(
				'client_id' => 'https://app.example.com/',
				'scope'     => 'profile',
				'me'        => get_author_posts_url( static::$author_id ),
				'user'      => static::$author_id,
				'fedcm'     => true,
			),
			600
		);

		$response = $this->create_form(
			'POST',
			array(
				'grant_type' => 'authorization_code',
				'code'       => $code,
				'client_id'  => 'https://app.example.com/',
			)
		);
		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'access_token', $data );
		$this->assertEquals( get_author_posts_url( static::$author_id ), $data['me'] );
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
	/**
	 * An equivalent client_id must not burn the code at this endpoint either.
	 *
	 * The token endpoint compares these as URLs. This endpoint destroys the code
	 * on a mismatch, so comparing as raw strings would mean a trailing slash
	 * costs the user a restart of the whole flow.
	 */
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

	/**
	 * This endpoint must destroy a code on a binding failure too.
	 *
	 * It accepts the same codes as the token endpoint. If only that one
	 * revoked, an attacker could simply probe client_id and redirect_uri here
	 * instead, without ever spending the code.
	 */
	public function test_auth_code_destroyed_on_binding_failure() {
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
		$this->assertFalse( $this->get_auth_code( $code ) );
	}

}
