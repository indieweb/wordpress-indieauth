<?php
class AuthorizeTest extends WP_UnitTestCase {

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

	public function test_authorize() {
		$token = self::set_token();
		$_REQUEST['micropub']       = 'endpoint';
		$_POST['access_token'] = $token;
		$authorize = new Indieauth_Authorize();
		$user_id = $authorize->determine_current_user( false );
		$this->assertEquals( $user_id, self::$author_id );
	}

	public function test_authorize_bearer() {
		$token = self::set_token();
		$_REQUEST['micropub']       = 'endpoint';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$authorize = new Indieauth_Authorize();
		$authorize->load();
		$user_id = apply_filters( 'determine_current_user', false );
		$this->assertEquals( $user_id, self::$author_id );
		wp_set_current_user( $user_id );
		$this->assertNull( $authorize->rest_authentication_errors() );
	}

	public function test_authorize_bearer_other_non_matching_provider() {
		$token = self::set_token();
		$self_author_id = self::$author_id;
		add_filter( 'determine_current_user', function( $user_id ) use ( $self_author_id ) {
			if ( 'Bearer other-valid-token' === $_SERVER['HTTP_AUTHORIZATION'] ) {
				return $self_author_id + 1;
			}
			return $user_id;
		} );
		$_REQUEST['micropub']       = 'endpoint';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$authorize = new Indieauth_Authorize();
		$authorize->load();
		$user_id = apply_filters( 'determine_current_user', false );
		$this->assertEquals( $user_id, self::$author_id );
		wp_set_current_user( $user_id );
		$this->assertNull( $authorize->rest_authentication_errors() );
	}

	public function test_authorize_bearer_other_provider() {
		$self_author_id = self::$author_id;
		add_filter( 'determine_current_user', function( $user_id ) use ( $self_author_id ) {
			if ( 'Bearer other-valid-token' === $_SERVER['HTTP_AUTHORIZATION'] ) {
				return $self_author_id;
			}
			return $user_id;
		} );
		$_REQUEST['micropub']       = 'endpoint';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer other-valid-token';
		$authorize = new Indieauth_Authorize();
		$authorize->load();
		$user_id = apply_filters( 'determine_current_user', false );
		$this->assertEquals( $user_id, self::$author_id );
		wp_set_current_user( $user_id );
		$this->assertNull( $authorize->rest_authentication_errors() );
	}

	public function test_authorize_bearer_no_valid_token_other_provider() {
		$self_author_id = self::$author_id;
		add_filter( 'determine_current_user', function( $user_id ) use ( $self_author_id ) {
			if ( 'Bearer other-valid-token' === $_SERVER['HTTP_AUTHORIZATION'] ) {
				return $self_author_id;
			}
			return $user_id;
		} );
		$_REQUEST['micropub']       = 'endpoint';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer other-invalid-token';
		$authorize = new Indieauth_Authorize();
		$authorize->load();
		$user_id = apply_filters( 'determine_current_user', false );
		$this->assertFalse( $user_id );
		$this->assertTrue( is_wp_error( $authorize->rest_authentication_errors() ) );
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
