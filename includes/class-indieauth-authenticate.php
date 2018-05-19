<?php
/**
 * Authentication class
 * Helper functions for extracting tokens from the WP-API team Oauth2 plugin
 */
class IndieAuth_Authenticate {

	public $error    = null;
	public $scopes   = null;
	public $response = null;
	public function __construct() {
		add_filter( 'determine_current_user', array( $this, 'determine_current_user' ), 11 );
		add_filter( 'rest_authentication_errors', array( $this, 'rest_authentication_errors' ) );
		add_filter( 'login_form_defaults', array( $this, 'login_form_defaults' ), 10, 1 );
		add_filter( 'gettext', array( $this, 'register_text' ), 10, 3 );
		add_action( 'login_form_indielogin', array( $this, 'login_form_indielogin' ) );

		add_action( 'authenticate', array( $this, 'authenticate' ), 10, 2 );
		add_action( 'authenticate', array( $this, 'authenticate_url_password' ), 20, 3 );

		add_action( 'send_headers', array( $this, 'http_header' ) );
		add_action( 'wp_head', array( $this, 'html_header' ) );

		add_filter( 'indieauth_scopes', array( $this, 'get_indieauth_scopes' ), 9 );
		add_filter( 'indieauth_response', array( $this, 'get_indieauth_response' ), 9 );
	}

	public static function get_indieauth_scopes( $scopes ) {
		return $scopes ? $scopes : $this->scopes;
	}

	public static function get_indieauth_response( $response ) {
		return $response ? $response : $this->response;
	}

	public static function http_header() {
		header( sprintf( 'Link: <%s>; rel="authorization_endpoint"', get_indieauth_authorization_endpoint(), false ) );
		header( sprintf( 'Link: <%s>; rel="token_endpoint"', get_indieauth_token_endpoint(), false ) );
	}
	public static function html_header() {
		printf( '<link rel="authorization_endpoint" href="%s" />' . PHP_EOL, get_indieauth_authorization_endpoint() );
		printf( '<link rel="token_endpoint" href="%s" />' . PHP_EOL, get_indieauth_token_endpoint() );
	}


	/**
	 * Report our errors, if we have any.
	 *
	 * Attached to the rest_authentication_errors filter. Passes through existing
	 * errors registered on the filter.
	 *
	 * @param WP_Error|null Current error, or null.
	 *
	 * @return WP_Error|null Error if one is set, otherwise null.
	 */
	public function rest_authentication_errors( $error = null ) {
		if ( ! empty( $error ) ) {
			return $error;
		}
		return $this->error;
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

	public function login_form_indielogin() {
		if ( 'GET' === $_SERVER['REQUEST_METHOD'] ) {
			include plugin_dir_path( __DIR__ ) . 'templates/indieauth-login-form.php';
			include plugin_dir_path( __DIR__ ) . 'templates/indieauth-auth-footer.php';
		}
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$redirect_to = array_key_exists( 'redirect_to', $_REQUEST ) ? $_REQUEST['redirect_to'] : null;
			$redirect_to = rawurldecode( $redirect_to );

			if ( array_key_exists( 'indieauth_identifier', $_POST ) ) {
				$me = esc_url_raw( $_POST['indieauth_identifier'] );
				// Check for valid URLs https://indieauth.spec.indieweb.org/#user-profile-url
				if ( ! wp_http_validate_url( $me ) ) {
					return new WP_Error( 'indieauth_invalid_url', __( 'Invalid User Profile URL', 'indieauth' ) );
				}
					$return = $this->authorization_redirect( $me, wp_login_url( $redirect_to ) );
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


	public function determine_current_user( $user_id ) {
		// If the Indieauth endpoint is being requested do not use this authentication method
		if ( strpos( $_SERVER['REQUEST_URI'], '/indieauth/1.0' ) ) {
			return $user_id;
		}
		$token = $this->get_provided_token();
		if ( ! $token ) {
			return $user_id;
		}
		$me = $this->verify_access_token( $token );
		if ( ! $me ) {
			return $user_id;
		}
		if ( is_oauth_error( $me ) ) {
			$this->error = $me->to_wp_error();
			return $user_id;
		}
		$user = get_user_by_identifier( $me );
		if ( $user instanceof WP_User ) {
			return $user->ID;
		}
		$this->error = new WP_Error(
			'indieauth.user_not_found', __( 'User Not Found on this Site', 'indieauth' ),
			array(
				'status'   => '401',
				'response' => $me,
			)
		);
		return $user_id;

	}

	public function verify_access_token( $token ) {
		$option = get_option( 'indieauth_config' );
		if ( 'local' === $option ) {
			$params = $this->verify_local_access_token( $token );
		} else {
			$params = $this->verify_remote_access_token( $token );
		}
		if ( is_oauth_error( $params ) ) {
			$this->error = $params->to_wp_error();
			return $params;
		}

		$this->scopes   = explode( ' ', $params['scope'] );
		$this->response = $params;
		return $params['me'];
	}

	public function verify_remote_access_token( $token ) {
		$endpoint = get_indieauth_token_endpoint();
		$args     = array(
			'headers' => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			),
		);
		$response = wp_safe_remote_get( $endpoint, $args );
		if ( is_oauth_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( 2 !== (int) ( $code / 100 ) ) {
			return new WP_OAuth_Response( 'invalid_token', __( 'Invalid access token', 'indieauth' ), 401 );
		}
		return json_decode( $body, true );
	}

	public function verify_local_access_token( $token ) {
		$return = get_indieauth_user_token( '_indieauth_token_', $token );
		if ( ! $return ) {
			return new WP_OAuth_Response( 'invalid_token', __( 'Invalid access token', 'indieauth' ), 401 );
		}
		return $return;
	}

	public static function generate_state() {
		$state = wp_generate_password( 128, false );
		$value = wp_hash( $state, 'nonce' );
		setcookie( 'indieauth_state', $value, current_time( 'timestamp', 1 ) + 120, '/', false, true );
		return $state;
	}

	/**
	 * Redirect to Authorization Endpoint for Authentication
	 *
	 * @param string $me URL parameter
	 * @param string $redirect_uri where to redirect
	 *
	 */
	public function authorization_redirect( $me, $redirect_uri ) {
		$endpoints = indieauth_discover_endpoint( $me );
		if ( ! $endpoints ) {
			return new WP_Error(
				'authentication_failed',
				__( '<strong>ERROR</strong>: Could not discover endpoints', 'indieauth' ),
				array(
					'status' => 401,
				)
			);
		}
		$authorization_endpoint = null;
		if ( isset( $endpoints['authorization_endpoint'] ) ) {
			$authorization_endpoint = $endpoints['authorization_endpoint'];
			setcookie( 'indieauth_authorization_endpoint', $authorization_endpoint, current_time( 'timestamp', 1 ) + 120, '/', false, true );
		}
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

	public static function verify_authorization_code( $post_args, $endpoint ) {
		$option = get_option( 'indieauth_config' );
		if ( 'local' === $option ) {
			$params = self::verify_local_authorization_code( $post_args );
		}
		else {
			$params = self::verify_remote_authorization_code( $post_args, $endpoint );
		}
		return $params;
	}

	public static function verify_local_authorization_code( $post_args ) {
		$return = get_indieauth_user_token( '_indieauth_code_', $post_args['code'] );
		if ( ! $return ) {
			return new WP_OAuth_Response( 'invalid_code', __( 'Invalid authorization code', 'indieauth' ), 401 );
		}
		return $return;
	}

	// $args must consist of redirect_uri, client_id, and code
	public static function verify_remote_authorization_code( $post_args, $endpoint ) {

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

		// got an unexpected response from the authorization endpoint
		$error = new WP_OAuth_Response( 'server_error', __( 'There was an error verifying the authorization code, the authorization server return an expected response', 'indieauth' ), 500 );
		$error->set_debug( array( 'debug' => $response ) );
		return $error;
	}

	/**
	 * Verify State
	 *
	 * @param string $state
	 *
	 * @return boolean|WP_Error
	 */
	public function verify_state( $state ) {
		if ( ! isset( $_COOKIE['indieauth_state'] ) ) {
			return false;
		}
		$value = $_COOKIE['indieauth_state'];
		setcookie( 'indieauth_state', '', current_time( 'timestamp' ) - 1000, '/', false, true );
		if ( wp_hash( $state, 'nonce' ) === $value ) {
			return true;
		}
		return new WP_Error( 'indieauth_state_error', __( 'IndieAuth Server did not return the same state parameter', 'indieauth' ) );
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
		if ( ! isset( $_COOKIE['indieauth_authorization_endpoint'] ) ) {
			return new WP_Error( 'indieauth_missing_endpoint', __( 'Cannot Find IndieAuth Endpoint Cookie', 'indieauth' ) );
		}

		if ( ! empty( $url ) && array_key_exists( 'indieauth_identifier', $_POST ) ) {
			$me = esc_url_raw( $url );
			// Check for valid URLs https://indieauth.spec.indieweb.org/#user-profile-url
			if ( ! wp_http_validate_url( $me ) ) {
				return new WP_Error( 'indieauth_invalid_url', __( 'Invalid User Profile URL', 'indieauth' ) );
			}
			$return = $this->authorization_redirect( $me, wp_login_url( $redirect_to ) );
			if ( is_wp_error( $return ) ) {
				return $return;
			}
		} elseif ( array_key_exists( 'code', $_REQUEST ) && array_key_exists( 'state', $_REQUEST ) ) {
			$state = $this->verify_state( $_REQUEST['state'] );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$response = $this->verify_authorization_code(
				array(
					'code'         => $_REQUEST['code'],
					'redirect_uri' => wp_login_url( $redirect_to ),
				),
				$_COOKIE['indieauth_authorization_endpoint']
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			if ( is_oauth_error( $response ) ) {
				return $response->to_wp_error();
			}
			$user = get_user_by_identifier( $response['me'] );
			if ( ! $user ) {
				$user = new WP_Error( 'indieauth_registration_failure', __( 'Your have entered a valid Domain, but you have no account on this blog.', 'indieauth' ) );
			}
		}
		return $user;
	}

	/**
	 * Get the authorization header
	 *
	 * On certain systems and configurations, the Authorization header will be
	 * stripped out by the server or PHP. Typically this is then used to
	 * generate `PHP_AUTH_USER`/`PHP_AUTH_PASS` but not passed on. We use
	 * `getallheaders` here to try and grab it out instead.
	 *
	 * @return string|null Authorization header if set, null otherwise
	 */
	public function get_authorization_header() {
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		}

		// When Apache speaks via FastCGI with PHP, then the authorization header is often available as REDIRECT_HTTP_AUTHORIZATION.
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}
		$headers = getallheaders();
		// Check for the authorization header case-insensitively
		foreach ( $headers as $key => $value ) {
			if ( strtolower( $key ) === 'authorization' ) {
				return $value;
			}
		}
		return null;
	}

			/**
	 * Extracts the token from the authorization header or the current request.
	 *
	 * @return string|null Token on success, null on failure.
	 */
	public function get_provided_token() {
		$header = $this->get_authorization_header();
		if ( $header ) {
			return $this->get_token_from_bearer_header( $header );
		}
		$token = $this->get_token_from_request();
		if ( $token ) {
			return $token;
		}
		return null;
	}
			/**
	 * Extracts the token from the given authorization header.
	 *
	 * @param string $header Authorization header.
	 *
	 * @return string|null Token on success, null on failure.
	 */
	public function get_token_from_bearer_header( $header ) {
		if ( is_string( $header ) && preg_match( '/Bearer ([\x20-\x7E]+)/', trim( $header ), $matches ) ) {
			return $matches[1];
		}
		return null;
	}
			/**
	 * Extracts the token from the current request.
	 *
	 * @return string|null Token on success, null on failure.
	 */
	public function get_token_from_request() {
		if ( empty( $_GET['access_token'] ) ) {
			return null;
		}
		$token = $_GET['access_token'];
		if ( is_string( $token ) ) {
			return $token;
		}
		return null;
	}
}
