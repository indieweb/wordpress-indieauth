<?php
/**
 * Web Sign In
 */
class Web_Signin {

	public function __construct() {
		add_action( 'login_form', array( $this, 'login_form' ) );
		add_filter( 'login_form_defaults', array( $this, 'login_form_defaults' ), 10, 1 );
		add_filter( 'gettext', array( $this, 'register_text' ), 10, 3 );
		add_action( 'login_form_websignin', array( $this, 'login_form_websignin' ) );

		add_action( 'authenticate', array( $this, 'authenticate_url_password' ), 20, 3 );
	}

	/**
	 * Redirect to Authorization Endpoint for Authentication
	 *
	 * @param string $me URL parameter
	 * @param string $redirect_uri where to redirect
	 *
	 */
	public function websignin_redirect( $me, $redirect_uri ) {
		setcookie( 'indieauth_identifier', $me, current_time( 'timestamp', 1 ) + 120, '/', false, true );
		$authorization_endpoint = find_rels( $me, 'authorization_endpoint' );
		if ( ! $authorization_endpoint ) {
			return new WP_Error(
				'authentication_failed',
				__( '<strong>ERROR</strong>: Could not discover endpoints', 'indieauth' ),
				array(
					'status' => 401,
				)
			);
		}
		setcookie( 'indieauth_authorization_endpoint', $authorization_endpoint, current_time( 'timestamp', 1 ) + 120, '/', false, true );

		$state = $this->generate_state();
		$query = add_query_arg(
			array(
				'me'            => rawurlencode( $me ),
				'redirect_uri'  => rawurlencode( $redirect_uri ),
				'client_id'     => rawurlencode( home_url() ),
				'state'         => $state,
				'response_type' => 'id',
			),
			$authorization_endpoint
		);
		// redirect to authentication endpoint
		wp_redirect( $query );
	}

	/**
	 * Authenticate user to WordPress using URL and Password
	 *
	 */
	public function authenticate_url_password( $user, $url, $password ) {
		if ( $user instanceof WP_User ) {
			return $user;
		}
		if ( empty( $url ) || empty( $password ) ) {
			if ( is_wp_error( $user ) ) {
				return $user;
			}
			if ( is_oauth_error( $user ) ) {
				return $user->to_wp_error();
			}
			$error = new WP_Error();

			if ( empty( $url ) ) {
				$error->add( 'empty_username', __( '<strong>ERROR</strong>: The URL field is empty.', 'indieauth' ) ); // Uses 'empty_username' for back-compat with wp_signon()
			}

			if ( empty( $password ) ) {
				$error->add( 'empty_password', __( '<strong>ERROR</strong>: The password field is empty.', 'indieauth' ) );
			}

			return $error;
		}

		if ( ! wp_http_validate_url( $url ) ) {
			return $user;
		}
		$user = get_user_by_identifier( $url );

		if ( ! $user ) {
			return new WP_Error(
				'invalid_url',
				__( '<strong>ERROR</strong>: Invalid URL.', 'indieauth' ) .
				' <a href="' . wp_lostpassword_url() . '">' .
				__( 'Lost your password?', 'indieauth' ) .
				'</a>'
			);
		}

		/** This filter is documented in wp-includes/user.php */
		$user = apply_filters( 'wp_authenticate_user', $user, $password );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			return new WP_Error(
				'incorrect_password',
				sprintf(
					/* translators: %s: url */
					__( '<strong>ERROR</strong>: The password you entered for the URL %s is incorrect.', 'indieauth' ),
					'<strong>' . $url . '</strong>'
				) .
				' <a href="' . wp_lostpassword_url() . '">' .
				__( 'Lost your password?', 'indieauth' ) .
				'</a>'
			);
		}

		return $user;
	}


	public static function generate_state() {
		$state = wp_generate_password( 128, false );
		$value = wp_hash( $state, 'nonce' );
		setcookie( 'indieauth_state', $value, current_time( 'timestamp', 1 ) + 120, '/', false, true );
		return $state;
	}

		/**
		 * render the login form
		 */
	public function login_form() {
			$template = plugin_dir_path( __DIR__ ) . 'templates/websignin-link.php';
		if ( 1 === (int) get_option( 'indieauth_show_login_form' ) ) {
				load_template( $template );
		}
	}

	public function login_form_defaults( $defaults ) {
		$defaults['label_username'] = __( 'Username, Email Address, or URL', 'indieauth' );
		return $defaults;
	}

	public function register_text( $translated_text, $untranslated_text, $domain ) {
		if ( 'Username or Email Address' === $untranslated_text ) {
			$translated_text = __( 'Username, Email Address, or URL', 'indieauth' );
		}
		return $translated_text;
	}

	public function login_form_websignin() {
		if ( 'GET' === $_SERVER['REQUEST_METHOD'] ) {
			include plugin_dir_path( __DIR__ ) . 'templates/websignin-form.php';
		}
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$redirect_to = array_key_exists( 'redirect_to', $_REQUEST ) ? $_REQUEST['redirect_to'] : null;
			$redirect_to = rawurldecode( $redirect_to );

			if ( array_key_exists( 'websignin_identifier', $_POST ) ) {
				$me = esc_url_raw( $_POST['websignin_identifier'] );
				// Check for valid URLs
				if ( ! wp_http_validate_url( $me ) ) {
					return new WP_Error( 'websignin_invalid_url', __( 'Invalid User Profile URL', 'indieauth' ) );
				}

				$return = $this->websignin_redirect( $me, wp_login_url( $redirect_to ) );
				if ( is_wp_error( $return ) ) {
					return $return;
				}
				if ( is_oauth_error( $return ) ) {
					return $return->to_wp_error();
				}
			}
		}
		exit;
	}

}

new Web_Signin();
