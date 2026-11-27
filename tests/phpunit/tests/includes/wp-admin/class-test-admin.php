<?php

use IndieAuth\WP_Admin\Admin;

class Test_Admin extends WP_UnitTestCase {

	public function test_register_settings() {
		Admin::register_settings();
		$settings = get_registered_settings();

		$this->assertArrayHasKey( 'indieauth_config', $settings );
		$this->assertArrayHasKey( 'indieauth_root_user', $settings );
		$this->assertSame( 2 * WEEK_IN_SECONDS, $settings['indieauth_expires_in']['default'] );
	}

	// Without the IndieWeb plugin the settings page lives under Settings.
	public function test_general_settings_links_to_settings_page() {
		ob_start();
		Admin::general_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'options-general.php?page=indieauth', $output );
	}

	public function test_admin_menu_adds_settings_page() {
		global $submenu;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );

		Admin::admin_menu();

		$this->assertContains( 'indieauth', wp_list_pluck( $submenu['options-general.php'], 2 ) );
	}
}
