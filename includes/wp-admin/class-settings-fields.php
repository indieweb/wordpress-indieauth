<?php
/**
 * IndieAuth Settings Fields class file.
 *
 * @package IndieAuth
 */

namespace IndieAuth\WP_Admin;

/**
 * Registers and renders settings sections and fields.
 */
class Settings_Fields {

	/**
	 * Initialize. Called on the settings page load hook.
	 */
	public static function init() {
		// General section.
		\add_settings_section(
			'indieauth_general',
			\__( 'IndieAuth', 'indieauth' ),
			array( self::class, 'render_general_description' ),
			'indieauth'
		);

		\add_settings_field(
			'indieauth_root_user',
			\__( 'Site User', 'indieauth' ),
			array( self::class, 'render_root_user_field' ),
			'indieauth',
			'indieauth_general',
			array( 'label_for' => 'indieauth_root_user' )
		);

		// Web Sign-In section.
		\add_settings_section(
			'indieauth_web_signin',
			\__( 'Web Sign-In', 'indieauth' ),
			array( self::class, 'render_web_signin_description' ),
			'indieauth'
		);

		\add_settings_field(
			'indieauth_show_login_form',
			\__( 'Login Form', 'indieauth' ),
			array( self::class, 'render_login_form_field' ),
			'indieauth',
			'indieauth_web_signin',
			array( 'label_for' => 'indieauth_show_login_form' )
		);

		// Endpoint section.
		\add_settings_section(
			'indieauth_endpoint',
			\__( 'Endpoint Settings', 'indieauth' ),
			array( self::class, 'render_endpoint_description' ),
			'indieauth'
		);

		\add_settings_field(
			'indieauth_expires_in',
			\__( 'Token Expiration', 'indieauth' ),
			array( self::class, 'render_expires_in_field' ),
			'indieauth',
			'indieauth_endpoint',
			array( 'label_for' => 'indieauth_expires_in' )
		);
	}

	/**
	 * Render general section description.
	 */
	public static function render_general_description() {
		echo '<p>' . \esc_html__( 'With IndieAuth, you can use your blog, to log into sites like the IndieWeb-Wiki.', 'indieauth' ) . '</p>';
	}

	/**
	 * Render web sign-in section description.
	 */
	public static function render_web_signin_description() {
		echo '<p>' . \esc_html__( 'Enable Web Sign-In for your blog, so others can use IndieAuth or RelMeAuth to log into this site.', 'indieauth' ) . '</p>';
	}

	/**
	 * Render endpoint section description.
	 */
	public static function render_endpoint_description() {
		\esc_html_e( 'These settings control the behavior of the endpoints', 'indieauth' );
	}

	/**
	 * Render root user dropdown field.
	 */
	public static function render_root_user_field() {
		\wp_dropdown_users(
			array(
				'show_option_all' => \__( 'None', 'indieauth' ),
				'name'            => 'indieauth_root_user',
				'id'              => 'indieauth_root_user',
				'show'            => 'display_name_with_login',
				'selected'        => \get_option( 'indieauth_root_user' ),
			)
		);
		echo '<p class="description">' . \esc_html__( 'Set a User who will represent the URL of the site', 'indieauth' ) . '</p>';
	}

	/**
	 * Render login form checkbox field.
	 */
	public static function render_login_form_field() {
		\printf(
			'<label for="indieauth_show_login_form"><input type="checkbox" name="indieauth_show_login_form" id="indieauth_show_login_form" value="1" %s /> %s</label>',
			\checked( 1, (int) \get_option( 'indieauth_show_login_form' ), false ),
			\esc_html__( 'Add a link to the login form to authenticate using an IndieAuth endpoint.', 'indieauth' )
		);
	}

	/**
	 * Render token expiration field.
	 */
	public static function render_expires_in_field() {
		\printf(
			'<input id="indieauth_expires_in" name="indieauth_expires_in" type="number" min="0" value="%s" />',
			\esc_attr( \get_option( 'indieauth_expires_in', 2 * WEEK_IN_SECONDS ) )
		);
		echo '<p class="description">' . \esc_html__( 'Set the Number of Seconds until a Token expires (Default is Two Weeks). 0 to Disable Expiration.', 'indieauth' ) . '</p>';
	}
}
