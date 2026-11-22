<?php
/**
 * Tests for the Scopes class.
 *
 * @package IndieAuth
 */

use IndieAuth\IndieAuth;

class Test_Scopes extends WP_UnitTestCase {

	public function for_user_provider() {
		$requested = array( 'profile', 'email', 'read', 'create', 'draft', 'update', 'delete', 'media', 'custom' );

		return array(
			'subscriber'  => array( 'subscriber', $requested, array( 'profile', 'email', 'read', 'custom' ) ),
			'contributor' => array( 'contributor', $requested, array( 'profile', 'email', 'read', 'create', 'draft', 'delete', 'custom' ) ),
			'author'      => array( 'author', $requested, $requested ),
			'no scopes'   => array( 'subscriber', array(), array() ),
		);
	}

	/**
	 * @dataProvider for_user_provider
	 */
	public function test_for_user( $role, $requested, $expected ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );

		$this->assertSame( $expected, IndieAuth::$scopes->for_user( $requested, $user_id ) );
	}

	// A user without a role on the site can still log in with an app.
	public function test_for_user_keeps_identity_scopes_without_a_role() {
		$user_id = self::factory()->user->create();
		get_userdata( $user_id )->set_role( '' );

		$this->assertSame( array( 'profile', 'email' ), IndieAuth::$scopes->for_user( array( 'profile', 'email', 'create' ), $user_id ) );
	}
}
