<?php

use IndieAuth\Rest\Authorization_Controller;
use IndieAuth\Ticket\External_Token_Page;
use IndieAuth\WP_Admin\Token_UI;

class Test_Token_UI extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_scopes_lists_every_scope() {
		ob_start();
		Token_UI::scopes();
		$output = ob_get_clean();

		foreach ( array_keys( Authorization_Controller::scopes() ) as $scope ) {
			$this->assertStringContainsString( 'name="scopes[]" value="' . esc_attr( $scope ) . '"', $output );
		}
	}

	public function test_options_form_renders_token_form() {
		set_current_screen( 'users_page_indieauth_user_token' );

		ob_start();
		Token_UI::options_form();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Manage IndieAuth Tokens', $output );
		$this->assertStringContainsString( 'name="indieauth_nonce"', $output );
		$this->assertStringContainsString( 'name="scopes[]"', $output );
	}

	public function test_admin_menu_adds_users_page() {
		global $submenu;
		set_current_screen( 'dashboard' );

		Token_UI::admin_menu();

		$this->assertContains( 'indieauth_user_token', wp_list_pluck( $submenu['users.php'], 2 ) );
	}

	public function test_str_prefix() {
		$this->assertTrue( Token_UI::str_prefix( 'indieauth_token', 'indieauth_' ) );
		$this->assertFalse( Token_UI::str_prefix( 'token', 'indieauth_' ) );
	}

	// The page is only loaded with the ticket endpoint, but the class must work on its own.
	public function test_external_token_page_renders() {
		set_current_screen( 'users_page_indieauth_external_token' );

		ob_start();
		External_Token_Page::options_form();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Manage External Tokens', $output );
	}
}
