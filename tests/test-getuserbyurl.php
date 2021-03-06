<?php
class UsersTest extends WP_UnitTestCase {

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
}
