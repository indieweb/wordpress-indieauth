<?php
class AuthenticateTest extends WP_UnitTestCase {

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

	// Sets a token and verifies it
	public function test_verify_token() {
		$token   = self::set_token();
		$request = new WP_REST_Request( 'GET', '/indieauth/1.0/token' );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		$response       = rest_get_server()->dispatch( $request );
		$response_token = $response->get_data();
		unset( $response_token['user'] );
		unset( $response_token['active'] );
		$this->assertEquals( static::$test_token, $response_token );
	}

	public function test_authorize() {
		$token = self::set_token();
		$_REQUEST['micropub']       = 'endpoint';
		$_POST['access_token'] = $token;
		$authorize = new Indieauth_Local_Authorize();
		$user_id = $authorize->determine_current_user( 0 );
		$this->assertEquals( $user_id, self::$author_id );
	}

	// Tests map_meta_cap for standard permissions
	public function test_publish_posts_with_scopes() {				
		add_filter( 'indieauth_scopes', 
				function ( $scopes ) {
					return array( 'create', 'update', 'media' );
				},
				10 
			);
		$this->assertTrue( user_can( static::$author_id, 'publish_posts' ) );
	}

	// Tests map_meta_cap for delete posts
	public function test_delete_posts_without_scope() {				
		add_filter( 'indieauth_response', 
				function ( $token ) {
					return static::$test_token;
				},
				10 
			);
		add_filter( 'indieauth_scopes', 
				function ( $scopes ) {
					return array( 'media' );
				},
				10 
			);
		$this->assertFalse( user_can( static::$author_id, 'delete_posts' ) );
	}

	// Tests map_meta_cap for delete posts
	public function test_delete_posts_with_scope() {				
		add_filter( 'indieauth_response', 
				function ( $token ) {
					return static::$test_token;
				},
				10 
			);
		add_filter( 'indieauth_scopes', 
				function ( $scopes ) {
					return array( 'delete' );
				},
				10 
			);
		$this->assertTrue( user_can( static::$author_id, 'delete_posts' ) );
	}

	// Tests map_meta_cap for delete posts for a user without this permission
	public function test_delete_posts_with_scope_but_no_permission() {				
		add_filter( 'indieauth_response', 
				function ( $token ) {
					return static::$test_token;
				},
				10 
			);
		add_filter( 'indieauth_scopes', 
				function ( $scopes ) {
					return array( 'delete' );
				},
				10 
			);
		$this->assertFalse( user_can( static::$subscriber_id, 'delete_posts' ) );
	}


}
