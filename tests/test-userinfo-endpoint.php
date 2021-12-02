<?php
class UserInfoEndpointTest extends WP_UnitTestCase {

	protected static $author_id;
	protected static $subscriber_id;


	protected static $test_token = array(
		'token_type' => 'Bearer',
		'scope'      => 'profile',
		'issued_by'  => 'https://example.org/wp-json/indieauth/1.0/token',
		'client_id'  => 'https://quill.p3k.io/',
	        'uuid' => '5e97048d-460c-4fb8-af0e-74db61d4b419',
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
		static::$test_token['me'] = get_author_posts_url( static::$author_id );
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

	// Gets a test access token
	public function get_access_token( $token ) {
		$tokens    = new Token_User( '_indieauth_token_' );
		return $tokens->get( $token );
	}

	// Form Encoded Request
	public function create_form( $method, $params = array(), $headers = array() ) {
		$request = new WP_REST_Request( $method, '/indieauth/1.0/userinfo' );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		if ( ! empty( $params ) ) {
			$request->set_body_params( $params );
		}

		if ( ! empty( $headers ) ) {
			$request->set_headers( $headers );
		}
		return rest_get_server()->dispatch( $request );
	}

	// Test UserInfo Endpoint with no Token
	public function test_userinfo_endpoint_with_no_token() {
		$response = $this->create_form( 'GET' );
		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
	}

	// Test UserInfo Endpoint with Profile
	public function test_userinfo_endpoint_with_profile() {
		$token   = self::set_access_token();

		$response = $this->create_form( 
				'GET', 
				array(), 
				array(
					'Authorization' => 'Bearer ' . $token
				)
			);
		$this->assertEquals( 200, $response->get_status(), 'Response: ' . wp_json_encode( $response) );
		$data = $response->get_data();
		$this->assertEquals( indieauth_get_user( static::$author_id ), $data, 'Response: ' . wp_json_encode( $data ) );
	}


}
