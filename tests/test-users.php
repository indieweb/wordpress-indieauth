<?php
class UsersTest extends WP_UnitTestCase {

	public function test_authorurl() {
		$user_id = $this->factory->user->create();
		$result = get_user_by_identifier( get_author_posts_url( $user_id ) );
		$this->assertSame( $result->ID, $user_id );
	}

	public function test_userurl() {
		$this->factory->user->create_many( 4 );
		wp_update_user( array( 'ID' => 4, 'user_url' => 'http://example.uk' ) );
		$result = get_user_by_identifier( 'http://example.uk' );
			$this->assertSame( $result->ID, 4 );
	}
}
