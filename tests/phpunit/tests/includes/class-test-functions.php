<?php
class Test_Functions extends WP_UnitTestCase {

	protected static $author_id;

	public static function wpSetUpBeforeClass( $factory ) {
		static::$author_id = $factory->user->create(
			array(
				'role'          => 'author',
				'user_nicename' => 'testauthor',
			)
		);
	}

	public static function wpTearDownAfterClass() {
		self::delete_user( self::$author_id );
	}

	// Test Getting the Author URL through get_user_by_identifier.
	public function test_authorurl() {
		// Remove port from site URL for IndieAuth URL validation compatibility.
		$strip_port = function ( $url ) {
			return preg_replace( '/:\d+/', '', $url );
		};
		add_filter( 'home_url', $strip_port );
		add_filter( 'site_url', $strip_port );

		$result = get_user_by_identifier( get_author_posts_url( static::$author_id ) );
		$this->assertSame( $result->ID, static::$author_id );

		remove_filter( 'home_url', $strip_port );
		remove_filter( 'site_url', $strip_port );
	}

	// The "None" choice in the Site User dropdown is stored as the string '0'.
	public function test_root_user_is_int_when_set_to_none() {
		update_option( 'indieauth_root_user', '0' );

		$this->assertSame( 0, indieauth_get_root_user() );
	}

	// With no Site User set, the site URL must still fall through to the user_url lookup.
	public function test_siteurl_with_no_root_user() {
		// Remove port from site URL for IndieAuth URL validation compatibility.
		$strip_port = function ( $url ) {
			return preg_replace( '/:\d+/', '', $url );
		};
		add_filter( 'home_url', $strip_port );
		add_filter( 'site_url', $strip_port );

		update_option( 'indieauth_root_user', '0' );
		wp_update_user(
			array(
				'ID'       => static::$author_id,
				'user_url' => home_url( '/' ),
			)
		);

		$result = get_user_by_identifier( home_url( '/' ) );

		remove_filter( 'home_url', $strip_port );
		remove_filter( 'site_url', $strip_port );

		$this->assertInstanceOf( 'WP_User', $result );
		$this->assertSame( static::$author_id, $result->ID );
	}

	// A logged-out user must not inherit the site URL when no Site User is set.
	public function test_url_from_user_without_a_user() {
		update_option( 'indieauth_root_user', '0' );

		$this->assertNull( get_url_from_user( 0 ) );
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


	public function code_binding_provider() {
		$stored = array(
			'client_id'    => 'https://app.example.com',
			'redirect_uri' => 'https://app.example.com/redirect',
		);

		return array(
			'exact match'                => array( $stored, array( 'client_id' => 'https://app.example.com', 'redirect_uri' => 'https://app.example.com/redirect' ), null ),
			'client_id trailing slash'   => array( $stored, array( 'client_id' => 'https://app.example.com/', 'redirect_uri' => 'https://app.example.com/redirect' ), null ),
			'client_id case'             => array( $stored, array( 'client_id' => 'HTTPS://App.Example.com', 'redirect_uri' => 'https://app.example.com/redirect' ), null ),
			'explicit default port'      => array( $stored, array( 'client_id' => 'https://app.example.com:443', 'redirect_uri' => 'https://app.example.com/redirect' ), null ),
			'client_id mismatch'         => array( $stored, array( 'client_id' => 'https://evil.example.com', 'redirect_uri' => 'https://app.example.com/redirect' ), 'client_id' ),
			'redirect_uri mismatch'      => array( $stored, array( 'client_id' => 'https://app.example.com', 'redirect_uri' => 'https://evil.example.com/cb' ), 'redirect_uri' ),
			'client_id absent'           => array( $stored, array( 'redirect_uri' => 'https://app.example.com/redirect' ), 'client_id' ),
			'redirect_uri absent'        => array( $stored, array( 'client_id' => 'https://app.example.com' ), 'redirect_uri' ),
			'different path is no match' => array( $stored, array( 'client_id' => 'https://app.example.com', 'redirect_uri' => 'https://app.example.com/other' ), 'redirect_uri' ),
			'fedcm needs no redirect'    => array( array( 'client_id' => 'https://app.example.com', 'fedcm' => true ), array( 'client_id' => 'https://app.example.com' ), null ),
			'fedcm still binds client'   => array( array( 'client_id' => 'https://app.example.com', 'fedcm' => true ), array( 'client_id' => 'https://evil.example.com' ), 'client_id' ),
		);
	}

	/**
	 * The one place that decides whether a code was redeemed correctly.
	 *
	 * Both the authorization endpoint and the token endpoint call this, so it is
	 * tested directly rather than only through either of them.
	 *
	 * @dataProvider code_binding_provider
	 *
	 * @param array       $token    The stored code data.
	 * @param array       $params   The redemption parameters.
	 * @param string|null $expected The parameter expected to fail, or null.
	 */
	public function test_code_binding_failure( $token, $params, $expected ) {
		$this->assertSame( $expected, IndieAuth\code_binding_failure( $token, $params ) );
	}

}
