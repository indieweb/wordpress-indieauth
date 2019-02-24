<?php
/**
 * Plugin Name: IndieAuth
 * Plugin URI: https://github.com/indieweb/wordpress-indieauth/
 * Description: IndieAuth is a way to allow users to use their own domain to sign into other websites and services
 * Version: 3.2
 * Author: IndieWebCamp WordPress Outreach Club
 * Author URI: https://indieweb.org/WordPress_Outreach_Club
 * License: MIT
 * License URI: http://opensource.org/licenses/MIT
 * Text Domain: indieauth
 * Domain Path: /languages
 */

class IndieAuth_Plugin {

	public function __construct() {
		add_filter( 'pre_user_url', array( $this, 'pre_user_url' ) );

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
	}

	public function pre_user_url( $user_url ) {
		if ( 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) && ( wp_parse_url( $user_url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			$user_url = set_url_scheme( $user_url, 'https' );
		}
		return trailingslashit( $user_url );
	}
}

new IndieAuth_Plugin();
