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

		add_action( 'authenticate', array( $this, 'authenticate' ), 20, 2 );
		add_action( 'authenticate', array( $this, 'authenticate_url_password' ), 10, 3 );
	}

	/**
	 * Redirect to Authorization Endpoint for Authentication
	 *
	 * @param string $me URL parameter
	 * @param string $redirect_uri where to redirect
	 */
	public function websignin_redirect( $me, $redirect_uri ) {
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
		$state = compact( 'me', 'authorization_endpoint' );
		$token = new Token_Transient( 'indieauth_state' );
		$query = add_query_arg(
			array(
				'me'            => rawurlencode( $me ),
				'redirect_uri'  => rawurlencode( $redirect_uri ),
				'client_id'     => rawurlencode( home_url() ),
				'state'         => $token->set_with_cookie( $state, 120 ),
				'response_type' => 'id',
			),
			$authorization_endpoint
		);
		// redirect to authentication endpoint
		wp_redirect( $query );
	}

	// $args must consist of redirect_uri, client_id, and code
	public function verify_authorization_code( $post_args, $endpoint ) {
		if ( ! wp_http_validate_url( $endpoint ) ) {
				return new WP_OAuth_Response( 'server_error', __( 'Did Not Receive a Valid Authorization Endpoint', 'indieauth' ), 500 );
		}

		$defaults = array(
			'client_id' => home_url(),
		);

		$post_args = wp_parse_args( $post_args, $defaults );
		$args      = array(
			'headers' => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'    => $post_args,
		);
		$response  = wp_remote_post( $endpoint, $args );
		$error     = get_oauth_error( $response );
		if ( is_oauth_error( $error ) ) {
				// Pass through well-formed error messages from the authorization endpoint
				return $error;
		}
		$code     = wp_remote_retrieve_response_code( $response );
		$response = wp_remote_retrieve_body( $response );

		$response = json_decode( $response, true );
		// check if response was json or not
		if ( ! is_array( $response ) ) {
				return new WP_OAuth_Response( 'server_error', __( 'The authorization endpoint did not return a JSON response', 'indieauth' ), 500 );
		}

		if ( 2 === (int) ( $code / 100 ) && isset( $response['me'] ) ) {
				// The authorization endpoint acknowledged that the authorization code
				// is valid and returned the authorization info
				return $response;
		}

		$error = new WP_OAuth_Response( 'server_error', __( 'There was an error verifying the authorization code, the authorization server return an expected response', 'indieauth' ), 500 );
		$error->set_debug( array( 'debug' => $response ) );
		return $error;
	}



	/**
	 * Authenticate user to WordPress using IndieAuth.
	 *
	 * @action: authenticate
	 * @param mixed $user authenticated user object, or WP_Error or null
	 * @return mixed authenticated user object, or WP_Error or null
	 */
	public function authenticate( $user, $url ) {
		if ( $user instanceof WP_User ) {
			return $user;
		}
		$redirect_to = array_key_exists( 'redirect_to', $_REQUEST ) ? $_REQUEST['redirect_to'] : null;
		$redirect_to = rawurldecode( $redirect_to );
		$token       = new Token_Transient( 'indieauth_state' );
		if ( array_key_exists( 'code', $_REQUEST ) && array_key_exists( 'state', $_REQUEST ) ) {
			$state = $token->verify( $_REQUEST['state'] );
			if ( ! $state ) {
				return new WP_Error( 'indieauth_state_error', __( 'IndieAuth Server did not return the same state parameter', 'indieauth' ) );
			}
			if ( ! isset( $state['authorization_endpoint'] ) ) {
				return new WP_Error( 'indieauth_missing_endpoint', __( 'Cannot Find IndieAuth Endpoint Cookie', 'indieauth' ) );
			}
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$response = $this->verify_authorization_code(
				array(
					'code'         => $_REQUEST['code'],
					'redirect_uri' => wp_login_url( $redirect_to ),
				),
				$state['authorization_endpoint']
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			if ( is_oauth_error( $response ) ) {
				return $response->to_wp_error();
			}
			if ( trailingslashit( $state['me'] ) !== trailingslashit( $response['me'] ) ) {
				return new WP_Error( 'indieauth_registration_failure', __( 'The domain does not match the domain you used to start the authentication.', 'indieauth' ) );
			}
			$user = get_user_by_identifier( $response['me'] );
			if ( ! $user ) {
				$user = new WP_Error( 'indieauth_registration_failure', __( 'Your have entered a valid Domain, but you have no account on this blog.', 'indieauth' ) );
			}
		}
		return $user;
	}



	/**
	 * Authenticate user to WordPress using URL and Password
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

			if ( array_key_exists( 'websignin_identifier', $_POST ) ) { // phpcs:ignore
				$me = esc_url_raw( $_POST['websignin_identifier'] );
				// Check for valid URLs
				if ( ! wp_http_validate_url( $me ) ) {
					return new WP_Error( 'websignin_invalid_url', __( 'Invalid User Profile URL', 'indieauth' ) );
				}

				$return = $this->websignin_redirect( $me, wp_login_url( $redirect_to ) );
				if ( is_wp_error( $return ) ) {
					echo '<div id="login_error">' . esc_html( $return->get_error_message() ) . "</div>\n";
					return $return;
				}
				if ( is_oauth_error( $return ) ) {
					$return = $return->to_wp_error();
					echo '<div id="login_error">' . esc_html( $return->get_error_message() ) . "</div>\n";
					return $return;
				}
			}
		}
		exit;
	}
}

new Web_Signin();
