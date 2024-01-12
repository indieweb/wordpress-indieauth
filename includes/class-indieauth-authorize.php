<?php
/**
 * Authorize class
 */
class IndieAuth_Authorize {

	public $error    = null;
	public $scopes   = array();
	public $response = array();


	public function __construct( $load = true ) {
		// Load the hooks for this class only if true. This allows for debugging of the functions
		if ( true === $load ) {
			$this->load();
		}
	}

	public function load() {
		// do not call in CLI environment
		if ( defined( 'WP_CLI' ) ) {
			return;
		}

		// WordPress validates the auth cookie at priority 10 and this cannot be overridden by an earlier priority
		// It validates the logged in cookie at 20 and can be overridden by something with a higher priority
		add_filter( 'determine_current_user', array( $this, 'determine_current_user' ), 15 );
		add_filter( 'rest_authentication_errors', array( $this, 'rest_authentication_errors' ) );

		add_filter( 'indieauth_scopes', array( $this, 'get_indieauth_scopes' ), 9 );
		add_filter( 'indieauth_response', array( $this, 'get_indieauth_response' ), 9 );
		add_filter( 'wp_rest_server_class', array( $this, 'wp_rest_server_class' ) );
		add_filter( 'rest_request_after_callbacks', array( $this, 'return_oauth_error' ), 10, 3 );
	}


	/*
	 * Ensures responses to any IndieAuth endpoints are always OAuth Responses rather than WP_Error.
	 */
	public static function return_oauth_error( $response, $handler, $request ) {
		if ( 0 !== strpos( $request->get_route(), '/indieauth/1.0/' ) ) {
			return $response;
		}

		if ( is_wp_error( $response ) ) {
			return wp_error_to_oauth_response( $response );
		}
		return $response;
	}

	/**
	 * Prevent caching of unauthenticated status.  See comment below.
	 *
	 * We don't actually care about the `wp_rest_server_class` filter, it just
	 * happens right after the constant we do care about is defined. This is taken from the Application Passwords plugin.
	 *
	 */
	public static function wp_rest_server_class( $class ) {
		global $current_user;
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && $current_user instanceof WP_User && 0 === $current_user->ID ) {
			/*
			 * For our authentication to work, we need to remove the cached lack
			 * of a current user, so the next time it checks, we can detect that
			 * this is a rest api request and allow our override to happen.  This
			 * is because the constant is defined later than the first get current
			 * user call may run.
			 */
			$current_user = null; // phpcs:ignore
		}
		return $class;
	}

	public function get_indieauth_scopes( $scopes ) {
		return $scopes ? $scopes : $this->scopes;
	}

	public function get_indieauth_response( $response ) {
		return $response ? $response : $this->response;
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
		if ( is_user_logged_in() ) {
			// Another OAuth2 plugin successfully authenticated.
			return null;
		}

		if ( ! empty( $error ) ) {
			return $error;
		}
		if ( is_wp_error( $this->error ) ) {
			return $this->error;
		}
		if ( is_oauth_error( $this->error ) ) {
			return $this->error->to_wp_error();
		}
		return null;
	}

	/**
	 * Uses an IndieAuth token to authenticate to WordPress.
	 *
	 * @param int|bool $user_id User ID if one has been determined otherwise false.
	 *
	 * @return int|bool User ID otherwise false.
	 */
	public function determine_current_user( $user_id ) {
		$token = $this->get_provided_token();
		// If there is not a token that means this is not an attempt to log in using IndieAuth
		if ( ! isset( $token ) ) {
			return $user_id;
		}
		// If there is a token and it is invalid then reject all logins

		$params = $this->verify_access_token( $token );
		if ( ! isset( $params ) ) {
			return $user_id;
		}
		if ( is_oauth_error( $params ) ) {
			$this->error = $params;
			return $user_id;
		}
		if ( is_array( $params ) ) {
			// If this is a token auth response and not a test run, add this constant.
			if ( ! function_exists( 'tests_add_filter' ) ) {
				define( 'INDIEAUTH_TOKEN', true );
			}

			$this->response = $params;
			$this->scopes   = explode( ' ', $params['scope'] );
			// The User ID must be passed in the request
			if ( isset( $params['user'] ) ) {
				return (int) $params['user'];
			}
		}

		$this->error = new WP_OAuth_Response(
			'unauthorized',
			__( 'User Not Found on this Site', 'indieauth' ),
			401,
			array(
				'response' => $me,
			)
		);
		return $user_id;
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
		$auth = null;
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth = wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			// When Apache speaks via FastCGI with PHP, then the authorization header is often available as REDIRECT_HTTP_AUTHORIZATION.
			$auth = wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		} else {
			$headers = getallheaders();
			// Check for the authorization header case-insensitively
			foreach ( $headers as $key => $value ) {
				if ( strtolower( $key ) === 'authorization' ) {
					$auth = wp_unslash( $value );
					break;
				}
			}
		}

		return $auth;
	}

	/**
	 * Extracts the token from the authorization header or the current request.
	 *
	 * @return string|null Token on success, null on failure.
	 */
	public function get_provided_token() {
		$header = $this->get_authorization_header();
		if ( isset( $header ) ) {
			$token = $this->get_token_from_bearer_header( $header );
			if ( isset( $token ) ) {
				return $token;
			}
		}
		$token = $this->get_token_from_request();
		if ( isset( $token ) ) {
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
		if ( empty( $_POST['access_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return null;
		}
		$token = $_POST['access_token']; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( is_string( $token ) ) {
			return $token;
		}
		return null;
	}

	/**
	 * Verifies Access Token
	 *
	 * @param string $token The token to verify
	 *
	 * @return array|WP_OAuth_Response Return either the token information or an OAuth Error Object
	 **/
	public function verify_access_token( $token ) {
		$tokens = new Token_User( '_indieauth_token_' );
		$return = $tokens->get( $token );
		if ( empty( $return ) ) {
			return new WP_OAuth_Response(
				'invalid_token',
				__( 'Invalid access token', 'indieauth' ),
				401
			);
		}
		if ( is_oauth_error( $return ) ) {
			return $return;
		}
		$return['last_accessed'] = time();
		$return['last_ip']       = $_SERVER['REMOTE_ADDR'];
		$tokens->update( $token, $return );
		if ( array_key_exists( 'exp', $return ) ) {
			$return['expires_in'] = $return['exp'] - time();
		}
		return $return;
	}

	/**
	 * Verifies authorixation code.
	 *
	 * @param string $code Authorization Code
	 *
	 * @return array|WP_OAuth_Response Return either the code information or an OAuth Error object
	 **/
	public static function verify_authorization_code( $code ) {
		$tokens = new Token_User( '_indieauth_code_' );
		$return = $tokens->get( $code );
		if ( empty( $return ) ) {
			return new WP_OAuth_Response(
				'invalid_code',
				__( 'Invalid authorization code', 'indieauth' ),
				401
			);
		}
		// Once the code is verified destroy it
		$tokens->destroy( $code );
		return $return;
	}
}
