<?php
/**
 * IndieAuth Admin Class
 *
 * @author Matthias Pfefferle
 */
class IndieAuth_Admin {
	public function __construct() {
		// initialize admin settings
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'init', array( $this, 'settings' ) );

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
	}

	public function settings() {
		register_setting(
			'indieauth', 'indieauth_show_login_form', array(
				'type'         => 'boolean',
				'description'  => __( 'Offer IndieAuth on Login Form', 'indieauth' ),
				'show_in_rest' => true,
				'default'      => 0,
			)
		);
		register_setting(
			'indieauth', 'indieauth_config', array(
				'type'         => 'string',
				'description'  => __( 'Indieauth Configuration Setting', 'indieauth' ),
				'show_in_rest' => true,
				'default'      => 'local',
			)
		);

		register_setting(
			'indieauth', 'indieauth_authorization_endpoint', array(
				'type'              => 'string',
				'description'       => __( 'IndieAuth Authorization Endpoint', 'indieauth' ),
				'show_in_rest'      => true,
				'sanitize_callback' => 'esc_url_raw',
				'default'           => get_indieauth_authorization_endpoint(),
			)
		);

		register_setting(
			'indieauth', 'indieauth_token_endpoint', array(
				'type'              => 'string',
				'description'       => __( 'IndieAuth Token Endpoint', 'indieauth' ),
				'show_in_rest'      => true,
				'sanitize_callback' => 'esc_url_raw',
				'default'           => get_indieauth_token_endpoint(),
			)
		);
	}

	public function admin_init() {
		add_settings_field( 'indieauth_general_settings', __( 'IndieAuth Settings', 'indieauth' ), array( $this, 'general_settings' ), 'general', 'default' );
	}

	/**
	 * Add IndieAuth options to the WordPress general settings page.
	 */
	public function general_settings() {
		if ( class_exists( 'Indieweb_Plugin' ) ) {
			$path = 'admin.php?page=indieauth';
		} else {
			$path = 'options-general.php?page=indieauth';
		}

		printf( __( 'Based on your feedback and to improve the user experience, we decided to move the settings to a separate <a href="%1$s">settings-page</a>.', 'indieauth' ), $path );
	}

	/**
	 * Add admin menu entry
	 */
	public function admin_menu() {
		$title = __( 'IndieAuth', 'indieauth' );
		// If the IndieWeb Plugin is installed use its menu.
		if ( class_exists( 'IndieWeb_Plugin' ) ) {
			$options_page = add_submenu_page(
				'indieweb',
				$title,
				$title,
				'manage_options',
				'indieauth',
				array( $this, 'settings_page' )
			);
		} else {
			$options_page = add_options_page(
				$title,
				$title,
				'manage_options',
				'indieauth',
				array( $this, 'settings_page' )
			);
		}
		add_action( 'load-' . $options_page, array( $this, 'add_help_tab' ) );
	}

	/**
	 * Load settings page
	 */
	public function settings_page() {
		load_template( plugin_dir_path( __DIR__ ) . '/templates/indieauth-settings.php' );
	}


	public function add_help_tab() {
		get_current_screen()->add_help_tab(
			array(
				'id'      => 'overview',
				'title'   => __( 'Overview', 'indieauth' ),
				'content' =>
					'<p>' . __( 'IndieAuth is a way for doing Web sign-in, where you use your own homepage to sign in to other places.', 'indieauth' ) . '</p>' .
					'<p>' . __( 'IndieAuth was build on ideas and technology from existing proven technologies like OAuth and OpenID but aims at making it easier for users as well as developers. It also decentralises much of the process so completely separate implementations and services can be used for each part.', 'indieauth' ) . '</p>',

			)
		);

		get_current_screen()->add_help_tab(
			array(
				'id'      => 'indieweb',
				'title'   => __( 'The IndieWeb', 'indieauth' ),
				'content' =>
					'<p>' . __( 'The IndieWeb is a people-focused alternative to the "corporate web".', 'indieauth' ) . '</p>' .
					'<p>
						<strong>' . __( 'Your content is yours', 'indieauth' ) . '</strong><br />' .
						__( 'When you post something on the web, it should belong to you, not a corporation. Too many companies have gone out of business and lost all of their users’ data. By joining the IndieWeb, your content stays yours and in your control.', 'indieauth' ) .
					'</p>' .
					'<p>
						<strong>' . __( 'You are better connected', 'indieauth' ) . '</strong><br />' .
						__( 'Your articles and status messages can go to all services, not just one, allowing you to engage with everyone. Even replies and likes on other services can come back to your site so they’re all in one place.', 'indieauth' ) .
					'</p>' .
					'<p>
						<strong>' . __( 'You are in control', 'indieauth' ) . '</strong><br />' .
						__( 'You can post anything you want, in any format you want, with no one monitoring you. In addition, you share simple readable links such as example.com/ideas. These links are permanent and will always work.', 'indieauth' ) .
					'</p>',
			)
		);

		get_current_screen()->set_help_sidebar(
			'<p><strong>' . __( 'For more information:', 'indieauth' ) . '</strong></p>' .
			'<p>' . __( '<a href="https://indieweb.org/IndieAuth">IndieWeb Wiki page</a>', 'indieauth' ) . '</p>' .
			'<p>' . __( '<a href="https://indieauth.rocks/">Test suite</a>', 'indieauth' ) . '</p>' .
			'<p>' . __( '<a href="https://www.w3.org/TR/indieauth/">W3C Spec</a>', 'indieauth' ) . '</p>'
		);
	}
}

new IndieAuth_Admin();
