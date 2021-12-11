<?php
class IntrospectionEndpointTest extends WP_UnitTestCase {

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
		$request = new WP_REST_Request( $method, '/indieauth/1.0/introspection' );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		if ( ! empty( $params ) ) {
			$request->set_body_params( $params );
		}

		if ( ! empty( $headers ) ) {
			$request->set_headers( $headers );
		}
		return rest_get_server()->dispatch( $request );
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
}
