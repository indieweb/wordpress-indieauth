<?php
/**
 * Plugin Name: IndieAuth
 * Plugin URI: https://github.com/indieweb/wordpress-indieauth/
 * Description: IndieAuth is a way to allow users to use their own domain to sign into other websites and services
 * Version: 3.4.0
 * Author: IndieWebCamp WordPress Outreach Club
 * Author URI: https://indieweb.org/WordPress_Outreach_Club
 * License: MIT
 * License URI: http://opensource.org/licenses/MIT
 * Text Domain: indieauth
 * Domain Path: /languages
 */

class IndieAuth_Plugin {

	public function __construct() {

		// Global Functions
		require_once plugin_dir_path( __FILE__ ) . 'includes/functions.php';

		// Client Discovery
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-indieauth-client-discovery.php';

		// Token Management
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-token-generic.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-token-user.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-token-transient.php';

		// OAuth REST Error Class
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-oauth-response.php';

		// Indieauth Authorize Functions
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-indieauth-authorize.php';

		// Web Sign-In
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-web-signin.php';

		// Token Endpoint
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-indieauth-token-endpoint.php';

		// Authorization Endpoint
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-indieauth-authorization-endpoint.php';

		// Token Endpoint UI
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-token-list-table.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-indieauth-token-ui.php';

		// IndieAuth Admin
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-indieauth-admin.php';

		if ( WP_DEBUG ) {
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-indieauth-debug.php';
		}

		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	public function admin_notices() {
		if ( class_exists( 'Indieweb_Plugin' ) ) {
			$path = 'admin.php?page=indieauth';
		} else {
			$path = 'options-general.php?page=indieauth';
		}
		if ( ! get_option( 'indieauth_header_check', 0 ) ) {
			echo '<p class="notice notice-warning">';
			esc_html_e( 'In order to ensure IndieAuth tokens will work please visit the settings page to check:', 'indieauth' );
			printf( ' <a href="%1s">%2$s</a>', esc_url( $path ), esc_html__( 'Visit Settings Page', 'indieauth' ) );
			echo '</p>';
		}
	}
}

new IndieAuth_Plugin();
