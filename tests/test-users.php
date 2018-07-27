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

        public static function pTearDownAfterClass() {
                self::delete_user( self::$author_id );
        }


	public function test_authorurl() {
		$result = get_user_by_identifier( get_author_posts_url( static::$author_id ) );
		$this->assertSame( $result, static::$author_id );
	}

	public function test_userurl() {
		$this->factory->user->create_many( 4 );
		wp_update_user( array( 'ID' => 4, 'user_url' => 'http://example.uk' ) );
		$result = get_user_by_identifier( 'http://example.uk' );
		$this->assertSame( $result->ID, 4 );
	}
}
