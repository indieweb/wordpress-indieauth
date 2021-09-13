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

}
