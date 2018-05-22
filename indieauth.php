<?php
/**
 * Plugin Name: IndieAuth
 * Plugin URI: https://github.com/indieweb/wordpress-indieauth/
 * Description: Login to your site using IndieAuth.com
 * Version: 2.0.3
 * Author: IndieWebCamp WordPress Outreach Club
 * Author URI: https://indieweb.org/WordPress_Outreach_Club
 * License: MIT
 * License URI: http://opensource.org/licenses/MIT
 * Text Domain: indieauth
 * Domain Path: /languages
 */

add_action( 'plugins_loaded', array( 'IndieAuth_Plugin', 'plugins_loaded' ), 9 );

class IndieAuth_Plugin {

	public static function plugins_loaded() {
		// initialize admin settings
		add_action( 'admin_init', array( 'IndieAuth_Plugin', 'admin_init' ) );
		add_action( 'init', array( 'IndieAuth_Plugin', 'init' ) );
		add_action( 'login_form', array( 'IndieAuth_Plugin', 'login_form' ) );
		add_filter( 'pre_user_url', array( 'IndieAuth_Plugin', 'pre_user_url' ) );

		// Global Functions
		require_once plugin_dir_path( __FILE__ ) . 'includes/functions.php';

		// OAuth REST Error Class
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-oauth-response.php';

		// Indieauth Authentication Functions
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-indieauth-authenticate.php';

		// Authorization Endpoint
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-indieauth-authorization-endpoint.php';

		// Token Endpoint
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-indieauth-token-endpoint.php';

		// Token Endpoint UI
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-indieauth-token-ui.php';

		if ( WP_DEBUG ) {
			require_once plugin_dir_path( __FILE__ ) . 'includes/debug.php';
		}

		new IndieAuth_Authenticate();
		new IndieAuth_Authorization_Endpoint();
		new IndieAuth_Token_Endpoint();

		// Only show token ui if local endpoint is enabled
		if ( 'local' === get_option( 'indieauth_config' ) ) {
			new IndieAuth_Token_UI();
		}
	}

	public static function init() {
		register_setting(
			'general', 'indieauth_show_login_form', array(
				'type'         => 'boolean',
				'description'  => __( 'Offer IndieAuth on Login Form', 'indieauth' ),
				'show_in_rest' => true,
				'default'      => 0,
			)
		);
		register_setting(
			'general', 'indieauth_config', array(
				'type'         => 'string',
				'description'  => __( 'Indieauth Configuration Setting', 'indieauth' ),
				'show_in_rest' => true,
				'default'      => 'local',
			)
		);

		register_setting(
			'general', 'indieauth_authorization_endpoint', array(
				'type'              => 'string',
				'description'       => __( 'IndieAuth Authorization Endpoint', 'indieauth' ),
				'show_in_rest'      => true,
				'sanitize_callback' => 'esc_url_raw',
				'default'           => get_indieauth_authorization_endpoint(),
			)
		);

		register_setting(
			'general', 'indieauth_token_endpoint', array(
				'type'              => 'string',
				'description'       => __( 'IndieAuth Token Endpoint', 'indieauth' ),
				'show_in_rest'      => true,
				'sanitize_callback' => 'esc_url_raw',
				'default'           => get_indieauth_token_endpoint(),
			)
		);
	}

	public static function admin_init() {
		add_settings_section( 'indieauth_general_settings', __( 'IndieAuth Settings', 'indieauth' ), array( 'IndieAuth_Plugin', 'general_settings' ), 'general', 'default' );
	}

		/**
		 * render the login form
		 */
	public static function login_form() {
		$template = plugin_dir_path( __FILE__ ) . 'templates/indieauth-domain-login.php';
		if ( 1 === (int) get_option( 'indieauth_show_login_form' ) ) {
			load_template( $template );
		}
	}

	/**
	 * Add Webmention options to the WordPress general settings page.
	 */
	public static function general_settings() {
		load_template( plugin_dir_path( __FILE__ ) . 'templates/indieauth-general-settings.php' );
	}

	public static function pre_user_url( $user_url ) {
		return trailingslashit( $user_url );
	}
}
