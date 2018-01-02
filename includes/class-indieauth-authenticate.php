<?php
/**
 * Authentication class
 * Helper functions for extracting tokens from the WP-API team Oauth2 plugin
 */
class IndieAuth_Authenticate {

	public function __construct() {
		add_filter( 'determine_current_user', array( $this, 'determine_current_user' ), 11 );
		add_filter( 'rest_authentication_errors', array( $this, 'rest_authentication_errors' ) );
		add_action( 'authenticate', array( $this, 'authenticate' ) );
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
		global $indieauth_error;
		return $indieauth_error;
	}

	public function determine_current_user( $user_id ) {
		$token = $this->get_provided_token();
		if ( ! $token ) {
			return $user_id;
		}
		$args     = array(
			'headers' => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			),
		);
		$response = wp_safe_remote_get( get_option( 'indieauth_token_endpoint' ), $args );
		$code     = wp_remote_retrieve_response_code( $response );
		$body     = wp_remote_retrieve_body( $response );

		if ( 2 !== (int) ( $code / 100 ) ) {
			global $indieauth_error;
			$indieauth_error = new WP_Error(
				'indieauth.invalid_access_token',
				__( 'Supplied Token is Invalid', 'indieauth' ),
				array(
					'status'   => $code,
					'response' => $body,
				)
			);
			return $user_id;
		}
		$params = json_decode( $body, true );
		global $indieauth_scopes;
		$indieauth_scopes = explode( ' ', $params['scope'] );
		$me               = $params['me'];
		$user             = $this->get_user_by_identifier( $me );
		if ( $user ) {
			return $user->ID;
		}

		return $user_id;
	}

	/**
	 * Redirect to Authorization Endpoint for Authentication
	 *
	 * @param string $me URL parameter
	 * @param string $redirect_uri where to redirect
	 *
	 */
	public static function authorization_redirect( $me, $redirect_uri ) {
		$query = build_query(
			array(
				'me'           => rawurlencode( $me ),
				'redirect_uri' => wp_login_url( $redirect_uri ),
				'client_id'    => home_url(),
				'state'        => wp_create_nonce( 'indieauth-' . home_url() ),
			)
		);
		// redirect to authentication endpoint
		wp_redirect( get_option( 'indieauth_authorization_endpoint' ) . '?' . $query );
	}

	public function verify_authorization_token( $code, $redirect_uri ) {
		$args     = array(
			'headers' => array(
				'Accept' => 'application/json',
			),
		);
		$query    = build_query(
			array(
				'code'         => rawurlencode( $code ),
				'redirect_uri' => wp_login_url( $redirect_uri ),
				'client_id'    => home_url(),
			)
		);
		$response = wp_remote_post( get_option( 'indieauth_authorization_endpoint' ) . '?' . $query, $args );
		$code     = wp_remote_retrieve_response_code( $response );
		$response = wp_remote_retrieve_body( $response );
		$response = json_decode( $response, true );
		// check if response was json or not
		if ( ! is_array( $response ) ) {
			return new WP_Error( 'indieauth_response_error', __( 'IndieAuth.com seems to have some hiccups, please try it again later.', 'indieauth' ) );
		}

		if ( 2 === (int) ( $code / 100 ) && isset( $response['me'] ) ) {
			return $response['me'];
		}
		if ( array_key_exists( 'error', $response ) ) {
			return new WP_Error( 'indieauth_' . $response['error'], esc_html( $response['error_description'] ) );
		}
		return new WP_Error(
			'indieauth.invalid_access_token',
			__( 'Supplied Token is Invalid', 'indieauth' ),
			array(
				'status'   => $code,
				'response' => $response,
			)
		);
	}

	/**
	 * Verify State
	 *
	 * @param string $state
	 *
	 * @return boolean|WP_Error
	 */
	public function verify_state( $state ) {
		$return = wp_verify_nonce( $state, 'indieauth-' . home_url() );
		if ( ! $return ) {
			return new WP_Error( 'indieauth_state_error', __( 'IndieAuth Server did not return the same state parameter', 'indieauth' ) );
		}
		return $return;
	}


	/**
	 * Authenticate user to WordPress using IndieAuth.
	 *
	 * @action: authenticate
	 * @param mixed $user authenticated user object, or WP_Error or null
	 * @return mixed authenticated user object, or WP_Error or null
	 */
	public function authenticate( $user ) {
		$redirect_to = array_key_exists( 'redirect_to', $_REQUEST ) ? $_REQUEST['redirect_to'] : null;
		if ( array_key_exists( 'indieauth_identifier', $_POST ) && $_POST['indieauth_identifier'] ) {
			$me = esc_url_raw( $_POST['indieauth_identifier'] );
			// Check for valid URLs https://indieauth.spec.indieweb.org/#user-profile-url
			if ( ! wp_http_validate_url( $me ) ) {
				return new WP_Error( 'indieauth_invalid_url', __( 'Invalid User Profile URL', 'indieauth' ) );
			}
			$this->authorization_redirect( $me, $redirect_to );
		} elseif ( array_key_exists( 'code', $_REQUEST ) && array_key_exists( 'state', $_REQUEST ) ) {
			$state = $this->verify_state( $_REQUEST['state'] );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$me = $this->verify_authorization_token( $_REQUEST['code'], $redirect_to );
			if ( is_wp_error( $me ) ) {
				return $me;
			}
			$user = $this->get_user_by_identifier( $me );
			if ( ! $user ) {
				$user = new WP_Error( 'indieauth_registration_failure', __( 'Your have entered a valid Domain, but you have no account on this blog.', 'indieauth' ) );
			}
		}
		return $user;
	}

	/**
	 * Get the user associated with the specified Identifier-URI.
	 *
	 * @param string $$identifier identifier to match
	 * @return int|null ID of associated user, or null if no associated user
	 */
	private function get_user_by_identifier( $identifier ) {
		// try it without trailing slash
		$no_slash = untrailingslashit( $identifier );

		$args = array(
			'search'         => $no_slash,
			'search_columns' => array( 'user_url' ),
		);

		$user_query = new WP_User_Query( $args );

			// check result
		if ( ! empty( $user_query->results ) ) {
				return $user_query->results[0];
		}

		// try it with trailing slash
		$slash = trailingslashit( $identifier );

		$args = array(
			'search'         => $slash,
			'search_columns' => array( 'user_url' ),
		);

		$user_query = new WP_User_Query( $args );

		// check result
		if ( ! empty( $user_query->results ) ) {
			return $user_query->results[0];
		}

		// Check author page
		global $wp_rewrite;
		$link = $wp_rewrite->get_author_permastruct();
		if ( empty( $link ) ) {
			$login = str_replace( home_url( '/' ) . '?author=', '', $identifier );
		} else {
			$link  = str_replace( '%author%', '', $link );
			$link  = user_trailingslashit( $link );
			$login = str_replace( $link, '', $link );
		}
		$args = array(
			'login' => $login,
		);

		$user_query = new WP_User_Query( $args );

		if ( ! empty( $user_query->results ) ) {
			return $user_query->results[0];
		}

		return null;
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
		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			// Check for the authorization header case-insensitively
			foreach ( $headers as $key => $value ) {
				if ( strtolower( $key ) === 'authorization' ) {
					return $value;
				}
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
		if ( is_string( $header ) && preg_match( '/Bearer ([a-zA-Z0-9\-._~\+\/=]+)/', trim( $header ), $matches ) ) {
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
