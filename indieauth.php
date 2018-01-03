<?php
/**
 * Plugin Name: IndieAuth
 * Plugin URI: https://github.com/indieweb/wordpress-indieauth/
 * Description: Login to your site using IndieAuth.com
 * Version: 1.2.0
 * Author: IndieWebCamp WordPress Outreach Club
 * Author URI: https://indieweb.org/WordPress_Outreach_Club
 * License: MIT
 * License URI: http://opensource.org/licenses/MIT
 * Text Domain: indieauth
 * Domain Path: /languages
 */

add_action( 'plugins_loaded', array( 'IndieAuth_Plugin', 'init' ) );

class IndieAuth_Plugin {

	public static function init() {
		// initialize admin settings
		add_action( 'admin_init', array( 'IndieAuth_Plugin', 'admin_init' ) );

		add_action( 'login_form', array( 'IndieAuth_Plugin', 'login_form' ) );
		// Compatibility Functions
		require_once plugin_dir_path( __FILE__ ) . 'includes/compat-functions.php';
		// Indieauth Authentication Functions
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-indieauth-authenticate.php';

		register_setting(
			'general', 'indieauth_show_login_form', array(
				'type'         => 'boolean',
				'description'  => __( 'Offer IndieAuth on Login Form', 'indieauth' ),
				'show_in_rest' => true,
				'default'      => 1,
			)
		);

		register_setting(
			'general', 'indieauth_authorization_endpoint', array(
				'type'              => 'string',
				'description'       => __( 'IndieAuth Authorization Endpoint', 'indieauth' ),
				'show_in_rest'      => true,
				'sanitize_callback' => 'esc_url_raw',
				'default'           => 'https://indieauth.com/auth',
			)
		);

		register_setting(
			'general', 'indieauth_token_endpoint', array(
				'type'              => 'string',
				'description'       => __( 'IndieAuth Token Endpoint', 'indieauth' ),
				'show_in_rest'      => true,
				'sanitize_callback' => 'esc_url_raw',
				'default'           => 'https://tokens.indieauth.com/token',
			)
		);

		new IndieAuth_Authenticate();
	}

	public static function admin_init() {
		add_settings_field( 'indieauth_general_settings', __( 'IndieAuth Settings', 'indieauth' ), array( 'IndieAuth_Plugin', 'general_settings' ), 'general', 'default' );
	}

		/**
		 * render the login form
		 */
	public static function login_form() {
		$template = plugin_dir_path( __FILE__ ) . 'templates/indieauth-login-form.php';
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

}

