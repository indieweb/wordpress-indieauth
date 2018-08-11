<?php
/**
 * Authorize class
 * Helper functions for extracting tokens from the WP-API team Oauth2 plugin
 */
class IndieAuth_Authorize {

	public $error    = null;
	public $scopes   = null;
	public $response = null;

	public function __construct() {
		add_filter( 'determine_current_user', array( $this, 'determine_current_user' ), 30 );
		add_filter( 'rest_authentication_errors', array( $this, 'rest_authentication_errors' ) );
		add_filter( 'rest_index', array( $this, 'register_index' ) );

		add_action( 'send_headers', array( $this, 'http_header' ) );
		add_action( 'wp_head', array( $this, 'html_header' ) );

		add_filter( 'indieauth_scopes', array( $this, 'get_indieauth_scopes' ), 9 );
		add_filter( 'indieauth_response', array( $this, 'get_indieauth_response' ), 9 );
	}

	public static function register_index( WP_REST_Response $response ) {
		$data                                = $response->get_data();
		$data['authentication']['indieauth'] = array(
			'endpoints' => array(
				'authorization' => rest_url( '/indieauth/1.0/auth' ),
				'token'         => rest_url( '/indieauth/1.0/token' ),
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
		header( sprintf( 'Link: <%s>; rel="authorization_endpoint"', rest_url( '/indieauth/1.0/auth' ), false ) );
		header( sprintf( 'Link: <%s>; rel="token_endpoint"', rest_url( '/indieauth/1.0/token' ), false ) );
	}
	public static function html_header() {
		printf( '<link rel="authorization_endpoint" href="%s" />' . PHP_EOL, rest_url( '/indieauth/1.0/auth' ) ); // phpcs:ignore
		printf( '<link rel="token_endpoint" href="%s" />' . PHP_EOL, rest_url( '/indieauth/1.0/token' ) ); //phpcs:ignore
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
		// If this is not a REST request or a Micropub request then do not continue
		if ( ! strpos( $_SERVER['REQUEST_URI'], rest_get_url_prefix() ) && ! isset( $_REQUEST['micropub'] ) ) {
			return $user_id;
		}
		$token = $this->get_provided_token();
		if ( ! $token ) {
			if ( defined( 'INDIEAUTH_TOKEN_ERROR' ) && INDIEAUTH_TOKEN_ERROR ) {
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

		$params = $this->verify_access_token( $token );
		if ( ! $params ) {
			return $user_id;
		}
		if ( is_oauth_error( $params ) ) {
			$this->error = $params->to_wp_error();
			return $user_id;
		}
		if ( is_array( $params ) ) {
			// If the user ID is passed in the token use it
			if ( isset( $params['user'] ) ) {
				return $params['user'];
			} elseif ( isset( $params['me'] ) ) {
				$user = get_user_by_identifier( $me );
				if ( $user instanceof WP_User ) {
					return $user->ID;
				}
			}
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
		$params = $this->verify_local_access_token( $token );

		if ( is_oauth_error( $params ) ) {
			$this->error = $params->to_wp_error();
			return $params;
		}

		$this->scopes   = explode( ' ', $params['scope'] );
		$this->response = $params;

		return $params;
	}

	public function verify_local_access_token( $token ) {
		$tokens = new Token_User( '_indieauth_token_' );
		$return = $tokens->get( $token );
		if ( ! $return ) {
			return new WP_OAuth_Response( 'invalid_token', __( 'Invalid access token', 'indieauth' ), 401 );
		}
		return $return;
	}

	public static function verify_authorization_code( $post_args ) {
		$tokens = new Token_User( '_indieauth_code_' );
		$return = $tokens->get( $post_args['code'] );
		if ( ! $return ) {
			return new WP_OAuth_Response( 'invalid_code', __( 'Invalid authorization code', 'indieauth' ), 401 );
		}
		// Once the code is verified destroy it
		$tokens->destroy( $post_args['code'] );
		return $return;
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

new IndieAuth_Authorize();
