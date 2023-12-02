<?php
class IndieAuthFunctionsTest extends WP_UnitTestCase {

	protected static $author_id;

        public static function wpSetUpBeforeClass( $factory ) {
                static::$author_id = $factory->user->create(
                        array(
                                'role' => 'author',
                        )
                );
        }

        public static function wpTearDownAfterClass() {
                self::delete_user( self::$author_id );
        }

	// Test Getting the Author URL thorugh get_user_by_identifier
	public function test_authorurl() {
		$result = get_user_by_identifier( get_author_posts_url( static::$author_id ) );
		$this->assertSame( $result->ID, static::$author_id );
	}

	// Test Getting the Author URL through the url_to_author function directly
	public function test_urltoauthor() {
		$result = url_to_author( get_author_posts_url( static::$author_id ) );
		$this->assertSame( $result->ID, static::$author_id );
	}

	// Test that Profile Return Function is Compliant with IndieAuth Return Spec.
	public function test_profile_return() {

		$author = get_user_by( 'ID', static::$author_id );
		
		$expected = array(
			'name'  => $author->display_name,
			'url'   => empty( $author->user_url ) ? get_author_posts_url( $author->ID ) : $author->user_url,
			'photo' => get_avatar_url(
				$author->ID,
				array(
					'size'    => 125,
					'default' => '404',
				)
			),
		);

		$profile = indieauth_get_user( static::$author_id );
		$this->assertEquals( $profile, $expected );

		$expected['email'] = $author->user_email;

		$profile = indieauth_get_user( static::$author_id, true );
		$this->assertEquals( $profile, $expected );


	}

	public function test_validate_user_identifier() {
		foreach( 
			array( 'https://example.com/', 'https://example.com/username', 'https://example.com/users?id=100' ) as $pass ) {
			$this->assertNotEquals( false, indieauth_validate_user_identifier( $pass ) );
		}
		foreach( 
			array( 
				'example.com', // schemeless
				'mailto:user@example.com', // invalid scheme
				'https://example.com/foo/./bar',  // single dot
				'https://example.com/foo/../bar',  // double dot
				'https://example.com/#me',  // fragment
				'https://user:pass@example.com/', // contains a username and password
				'https://example.com:8443/', // contains a port
				'https://172.28.92.51/' //  host is an IP address
			) as $fail ) {
			$this->assertEquals( false, indieauth_validate_user_identifier( $fail ) );
		}
	}

	public function test_validate_client_identifier() {
		foreach( 
			array( 'https://example.com/', 'https://example.com/application', 'https://example.com/app?id=100', 'https://127.0.0.1', 'http://::1', 'https://localhost', 'https://example.com:8443' ) as $pass ) {
			$this->assertNotEquals( false, indieauth_validate_client_identifier( $pass ) );
		}
		foreach( 
			array( 
				'example.com', // schemeless
				'mailto:user@example.com', // invalid scheme
				'https://example.com/foo/./bar',  // single dot
				'https://example.com/foo/../bar',  // double dot
				'https://example.com/#me',  // fragment
				'https://user:pass@example.com/', // contains a username and password
				'https://172.28.92.51/' //  host is an IP address
			) as $fail ) {
			$this->assertEquals( false, indieauth_validate_client_identifier( $fail ) );
		}
	}

	public function test_validate_issuer_identifier() {
		foreach( 
			array( 'https://example.com/', 'https://example.com/application', 'https://127.0.0.1',  'https://localhost', 'https://example.com:8443' ) as $pass ) {
			$this->assertNotEquals( false, indieauth_validate_issuer_identifier( $pass ) );
		}
		foreach( 
			array( 
				'example.com', // schemeless
				'http://example.com', // http scheme
				'mailto:user@example.com', // invalid scheme
				'https://example.com/foo/./bar',  // single dot
				'https://example.com/foo/../bar',  // double dot
				'https://example.com/#me',  // fragment
				'https://user:pass@example.com/', // contains a username and password
				'https://example.com/?id=100', // contains a query
			) as $fail ) {
			$this->assertEquals( false, indieauth_validate_issuer_identifier( $fail ) );
		}
	}

}
