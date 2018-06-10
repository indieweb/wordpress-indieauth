<?php
/**
 * Plugin Name: IndieAuth
 * Plugin URI: https://github.com/indieweb/wordpress-indieauth/
 * Description: Login to your site using IndieAuth.com
 * Version: 2.2.0
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
		require_once plugin_dir_path( __FILE__ ) . 'includes/indieauth-functions.php';

		// Token Management
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-token-generic.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-token-user.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-token-transient.php';

		// OAuth REST Error Class
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-oauth-response.php';

		// Indieauth Authentication Functions
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-indieauth-authenticate.php';

		// Web Sign-In
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-web-signin.php';

		// Token Endpoint
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-indieauth-token-endpoint.php';

		// Authorization Endpoint
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-indieauth-authorization-endpoint.php';

		// Token Endpoint UI
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-indieauth-token-ui.php';

		// Token Endpoint UI
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-indieauth-admin.php';

		if ( WP_DEBUG ) {
			require_once plugin_dir_path( __FILE__ ) . 'includes/debug.php';
		}
	}

	public function pre_user_url( $user_url ) {
		return trailingslashit( $user_url );
	}
}

new IndieAuth_Plugin();
