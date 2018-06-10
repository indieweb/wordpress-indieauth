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
		add_filter( 'determine_current_user', array( $this, 'determine_current_user' ), 30 );
		add_filter( 'rest_authentication_errors', array( $this, 'rest_authentication_errors' ) );
		add_filter( 'rest_index', array( $this, 'register_index' ) );

		add_action( 'authenticate', array( $this, 'authenticate' ), 10, 2 );

		add_action( 'send_headers', array( $this, 'http_header' ) );
		add_action( 'wp_head', array( $this, 'html_header' ) );

		add_filter( 'indieauth_scopes', array( $this, 'get_indieauth_scopes' ), 9 );
		add_filter( 'indieauth_response', array( $this, 'get_indieauth_response' ), 9 );
	}

	public static function register_index( WP_REST_Response $response ) {
		$data                                = $response->get_data();
		$data['authentication']['indieauth'] = array(
			'endpoints' => array(
				'authorization' => get_indieauth_authorization_endpoint(),
				'token'         => get_indieauth_token_endpoint(),
			),
		);
		$response->set_data( $data );
		return $response;
	}

	public function get_indieauth_scopes( $scopes ) {
		return $scopes ? $scopes : $this->scopes;
	}

	public function get_indieauth_response( $response ) {
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

	public function determine_current_user( $user_id ) {
		// Do not try to find a user if one has already been found
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}
		// If the Indieauth endpoint is being requested do not use this authentication method
		if ( strpos( $_SERVER['REQUEST_URI'], '/indieauth/1.0' ) ) {
			return $user_id;
		}
		$token = $this->get_provided_token();
		if ( ! $token ) {
			if ( is_rest_request() || is_micropub_request() ) {
				$this->error = new WP_Error(
					'missing_bearer_token',
					__( 'Missing OAuth Bearer Token', 'indieauth' ),
					array(
						'status' => '401',
						'server' => $_SERVER,
					)
				);
			}
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
		$params = $this->verify_remote_access_token( $token );

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

	public static function verify_authorization_code( $post_args, $endpoint ) {
		$params = verify_indieauth_authorization_code( $post_args, $endpoint );

		return $params;
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
			$token = $this->get_token_from_bearer_header( $header );
			if ( $token ) {
				return $token;
			}
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
		if ( empty( $_REQUEST['access_token'] ) ) {
			return null;
		}
		$token = $_REQUEST['access_token'];
		if ( is_string( $token ) ) {
			return $token;
		}
		return null;
	}
}

new IndieAuth_Authenticate();
