<?php

use IndieAuth\Token\User as Token_User;
use IndieAuth\Authorize as Indieauth_Authorize;

class Test_Authorize extends WP_UnitTestCase {

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

	public function tear_down() {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_POST['access_token'], $_REQUEST['micropub'] );
		parent::tear_down();
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

	/*
	 * Do not name this method `test_authorize`. A method with the same name as the class
	 * (case-insensitive) is treated as a PHP4-style constructor on PHP < 8.0, which
	 * bypasses the PHPUnit TestCase constructor and breaks the whole test class.
	 */
	public function test_authorize_with_access_token() {
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
		wp_set_current_user( $user_id );
		// A token that is not ours may belong to another plugin, so it must not fail authentication by itself.
		$this->assertNull( $authorize->rest_authentication_errors() );
		$this->assertTrue( is_oauth_error( $authorize->deferred_error ) );
	}

	/**
	 * Registers two routes for the unknown token tests: one public, one requiring authentication.
	 */
	private function register_test_routes() {
		register_rest_route(
			'indieauth-test/1.0',
			'/public',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return array( 'ok' => true );
				},
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'indieauth-test/1.0',
			'/protected',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return array( 'ok' => true );
				},
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);
		register_rest_route(
			'indieauth-test/1.0',
			'/custom-error',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return array( 'ok' => true );
				},
				'permission_callback' => function () {
					return new WP_Error( 'other_plugin_error', 'Custom', array( 'status' => 403 ) );
				},
			)
		);
	}

	/**
	 * Authenticates the current request with an unknown Bearer token.
	 *
	 * @return Indieauth_Authorize
	 */
	private function authorize_with_unknown_token() {
		$this->register_test_routes();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ema-health-check';
		$authorize = new Indieauth_Authorize();
		$authorize->load();
		$user_id = apply_filters( 'determine_current_user', false );
		$this->assertFalse( $user_id );
		wp_set_current_user( 0 );
		return $authorize;
	}

	public function test_unknown_token_does_not_break_public_route() {
		$this->authorize_with_unknown_token();
		$response = rest_do_request( new WP_REST_Request( 'GET', '/indieauth-test/1.0/public' ) );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( array( 'ok' => true ), $response->get_data() );
	}

	public function test_unknown_token_on_protected_route_returns_invalid_token() {
		$this->authorize_with_unknown_token();
		$response = rest_do_request( new WP_REST_Request( 'GET', '/indieauth-test/1.0/protected' ) );
		$this->assertEquals( 401, $response->get_status() );
		$this->assertEquals( 'invalid_token', $response->get_data()['code'] );
	}

	public function test_unknown_token_keeps_other_plugins_error() {
		$this->authorize_with_unknown_token();
		$response = rest_do_request( new WP_REST_Request( 'GET', '/indieauth-test/1.0/custom-error' ) );
		$this->assertEquals( 403, $response->get_status() );
		$this->assertEquals( 'other_plugin_error', $response->get_data()['code'] );
	}

	public function test_unknown_token_does_not_replace_error_for_authenticated_user() {
		$this->register_test_routes();
		$self_author_id = self::$author_id;
		add_filter( 'determine_current_user', function( $user_id ) use ( $self_author_id ) {
			if ( 'Bearer other-valid-token' === $_SERVER['HTTP_AUTHORIZATION'] ) {
				return $self_author_id;
			}
			return $user_id;
		} );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer other-valid-token';
		$authorize = new Indieauth_Authorize();
		$authorize->load();
		wp_set_current_user( apply_filters( 'determine_current_user', false ) );
		$this->assertTrue( is_user_logged_in() );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/indieauth-test/1.0/protected' ) );
		$this->assertEquals( 200, $response->get_status() );
	}

	public function test_valid_token_on_protected_route() {
		$this->register_test_routes();
		$token = self::set_token();
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$authorize = new Indieauth_Authorize();
		$authorize->load();
		wp_set_current_user( apply_filters( 'determine_current_user', false ) );
		$this->assertNull( $authorize->deferred_error );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/indieauth-test/1.0/protected' ) );
		$this->assertEquals( 200, $response->get_status() );
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
