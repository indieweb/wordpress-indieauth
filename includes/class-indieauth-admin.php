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
		add_action( 'login_form_authdiag', array( $this, 'login_form_authdiag' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_filter( 'wp_pre_insert_user_data', array( $this, 'unique_user_url' ), 10, 3 );
		add_filter( 'manage_users_columns', array( $this, 'add_user_url_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'user_url_column' ), 10, 3 );
		add_filter( 'site_status_tests', array( $this, 'add_indieauth_test' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}


	public function admin_notices() {
		if ( ! get_option( 'indieauth_header_check', 0 ) ) {
			echo '<p class="notice notice-warning">';
			esc_html_e( 'In order to ensure IndieAuth tokens will work please visit the settings page to check:', 'indieauth' );
			printf( ' <a href="%1s">%2$s</a>', esc_url( menu_page_url( 'indieauth', false ) ), esc_html__( 'Visit Settings Page', 'indieauth' ) );
			echo '</p>';
		}
		$screen = get_current_screen();
		if ( ( 'users' === $screen->id ) && $this->check_dupe_user_urls() ) {
			echo '<p class="notice notice-error">';
			esc_html_e( 'Multiple user accounts have the same URL set. This is not permitted as this value is used by IndieAuth for login. Please resolve', 'indieauth' );
			echo '</p>';
		}
	}

	public function add_indieauth_test( $tests ) {
		$tests['direct']['indieauth_plugin'] = array(
			'label' => __( 'IndieAuth Test', 'indieauth' ),
			'test'  => array( $this, 'site_health_test' ),
		);
		return $tests;
	}

	public function site_health_test() {
		if ( class_exists( 'Indieweb_Plugin' ) ) {
			$path = 'admin.php?page=indieauth';
		} else {
			$path = 'options-general.php?page=indieauth';
		}
		$result = array(
			'label'       => __( 'Authorization Header Passed', 'indieauth' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'IndieAuth', 'indieauth' ),
				'color' => 'green',
			),
			'description' => sprintf(
				'<p>%s</p>',
				__( 'Your hosting provider allows authorization headers to pass so IndieAuth should work', 'indieauth' )
			),
			'actions'     => '',
			'test'        => 'indieauth_headers',
		);

		if ( ! self::test_auth() ) {
			$result['status']      = 'critical';
			$result['label']       = __( 'Authorization Test Failed', 'indieauth' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				__( 'Authorization Headers are being blocked by your hosting provider. This will cause IndieAuth to fail.', 'indieauth' )
			);
			$result['actions']     = sprintf( '<a href="%1$s" >%2$s</a>', $path, __( 'Visit the Settings page for guidance on how to resolve.', 'indieauth' ) );
		}

		return $result;
	}

	public function user_url_column( $val, $column_id, $user_id ) {
		if ( 'user_url' === $column_id ) {
			$user = get_user_by( 'id', $user_id );
			if ( ! wp_http_validate_url( $user->user_url ) ) {
				return __( 'None', 'indieauth' );
			}
			$url = esc_url( $user->user_url );
			return sprintf( '<a href="%1s">%1$s</a>', $url );
		}
		return $val;
	}

	public function add_user_url_column( $column ) {
		$column['user_url'] = __( 'Website', 'indieauth' );
		return $column;
	}

	/**
	 * Ensure all user URL fields are unique
	 *
	 */
	public function unique_user_url( $data, $update, $id ) {
		if ( empty( $data['user_url'] ) ) {
			return $data;
		}
		$data['user_url'] = normalize_url( $data['user_url'] );
		$users            = get_users(
			array(
				'search'         => '*' . wp_parse_url( $data['user_url'], PHP_URL_HOST ) . '*',
				'search_columns' => array( 'user_url' ),
				'fields'         => array( 'ID', 'user_url' ),
			)
		);

		$url = normalize_url( $data['user_url'], true );
		foreach ( $users as $user ) {
			if ( ( normalize_url( $user->user_url, true ) === $url ) && $user->ID !== $id ) {
				$data['user_url'] = '';
			}
		}
			return $data;
	}

	public function check_dupe_user_urls() {
		$urls = get_users(
			array(
				'fields' => array( 'user_url' ),
			)
		);
		$urls = array_filter( wp_list_pluck( $urls, 'user_url' ) );
		$urls = array_map( 'normalize_url', $urls );
		if ( count( array_unique( $urls ) ) === count( $urls ) ) {
			return false;
		}
		return true;
	}

	public function login_form_authdiag() {
		$return = '';
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) && 'Bearer abc123' === $_SERVER['HTTP_AUTHORIZATION'] ) {
				$return = '<div class="notice notice-success"><p>' . esc_html__( 'Authorization Header Found. You should be able to use all clients.', 'indieauth' ) . '</p></div>';
				update_option( 'indieauth_header_check', 1 );
			} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) && 'Bearer abc123' === $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) {
				$return = '<div class="notice-success"><p>' . esc_html__( 'Alternate Header Found. You should be able to use all clients.', 'indieauth' ) . '</p></div>';
				update_option( 'indieauth_header_check', 1 );
			}
			if ( empty( $return ) ) {
				ob_start();
				include plugin_dir_path( __DIR__ ) . 'templates/authdiagfail.php';
				$return = ob_get_contents();
				ob_end_clean();
			}
			if ( 'application/json' === $_SERVER['HTTP_ACCEPT'] ) {
				header( 'Content-Type: application/json' );
				$return = wp_json_encode( array( 'message' => $return ) );
			}
			echo $return; // phpcs:ignore
			exit;
		}
		$args = array(
			'action' => 'authdiag',
		);
		$url  = add_query_params_to_url( $args, wp_login_url() );
		include plugin_dir_path( __DIR__ ) . 'templates/authdiagtest.php';
		exit;
	}

	public function settings() {
		register_setting(
			'indieauth',
			'indieauth_show_login_form',
			array(
				'type'         => 'boolean',
				'description'  => __( 'Offer IndieAuth on Login Form', 'indieauth' ),
				'show_in_rest' => true,
				'default'      => 0,
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

		printf( __( 'Based on your feedback and to improve the user experience, we decided to move the settings to a separate <a href="%1$s">settings-page</a>.', 'indieauth' ), $path ); // phpcs:ignore
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

	public function test_auth() {
		$response = wp_remote_post(
			add_query_params_to_url(
				array(
					'action' => 'authdiag',
				),
				wp_login_url()
			),
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer abc123',
					'Accept'        => 'application/json',
				),
			)
		);
		if ( ! is_wp_error( $response ) ) {
			$json = json_decode( wp_remote_retrieve_body( $response ) );
			return $json->message;
		} else {
			return false;
		}
	}

			/**
			 * Load settings page
			 */
	public function settings_page() {
		$response = self::test_auth();
		if ( ! $response ) {
			ob_start();
			include plugin_dir_path( __DIR__ ) . 'templates/authdiagfail.php';
			$response = ob_get_contents();
			ob_end_clean();
		}
		set_query_var( 'authdiag_message', $response );
		load_template( plugin_dir_path( __DIR__ ) . '/templates/indieauth-settings.php' );
	}

	public function add_help_tab() {
		get_current_screen()->add_help_tab(
			array(
				'id'      => 'overview',
				'title'   => __( 'Overview', 'indieauth' ),
				'content' =>
						'<p>' . __( 'IndieAuth is a way for doing Web sign-in, where you use your own homepage to sign in to other places.', 'indieauth' ) . '</p>' .
						'<p>' . __( 'IndieAuth was built on ideas and technology from existing proven technologies like OAuth and OpenID but aims at making it easier for users as well as developers. It also decentralises much of the process so completely separate implementations and services can be used for each part.', 'indieauth' ) . '</p>',
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
