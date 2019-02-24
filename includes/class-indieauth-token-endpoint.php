<?php
/**
 *
 *
 * Implements IndieAuth Token Endpoint
 */

class IndieAuth_Token_Endpoint {
	private $tokens;
	public function __construct() {
		$this->tokens = new Token_User( '_indieauth_token_' );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
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
	 * Register the Route.
	 */
	public function register_routes() {
		register_rest_route(
			'indieauth/1.0',
			'/token',
			array(
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'post' ),
					'args'     => array(
						'grant_type'   => array(),
						'code'         => array(),
						'client_id'    => array(
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
						'redirect_uri' => array(
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
						'me'           => array(
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
						'action'       => array(),
						'token'        => array(),
					),
				),
			)
		);
		register_rest_route(
			'indieauth/1.0',
			'/token',
			array(
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'get' ),
					'args'     => array(),
				),
			)
		);
	}

	public function get_token( $token, $hash = true ) {
		return $this->tokens->get( $token, $hash );
	}

	public function get( $request ) {
		$params       = $request->get_params();
		$access_token = $this->get_token_from_bearer_header( $request->get_header( 'Authorization' ) );
		if ( ! $access_token ) {
			return new WP_OAuth_Response( 'parameter_absent', __( 'Bearer Token Not Supplied', 'indieauth' ), 400 );
		}
		$token = $this->get_token( $access_token );
		if ( ! $token ) {
			return new WP_OAuth_Response( 'invalid_token', __( 'Invalid access token', 'indieauth' ), 401 );
		}
		return rest_ensure_response( $token );
	}

	public function set_token( $token ) {
		if ( ! isset( $token['me'] ) ) {
			return false;
		}
		$user = get_user_by_identifier( $token['me'] );
		if ( $user instanceof WP_User ) {
			$this->tokens->set_user( $user->ID );
			return $this->tokens->set( $token );
		}
		return false;
	}

	public function delete_token( $id, $user_id = null ) {
		$this->tokens->set_user( $user_id );
		return $this->tokens->destroy( $id );
	}

	// Request or revoke a token
	public function post( $request ) {
		$params = $request->get_params();
		// Revoke Token
		if ( isset( $params['action'] ) && isset( $params['token'] ) && 'revoke' === $params['action'] ) {
			$this->delete_token( $params['token'] );
			return __( 'The Token Provided is No Longer Valid', 'indieauth' );
		}
		// Request Token
		if ( isset( $params['grant_type'] ) && 'authorization_code' === $params['grant_type'] ) {
			return $this->request( $params );
		}
		// Everything Failed
		return new WP_OAuth_Response( 'invalid_request', __( 'Invalid Request', 'indieauth' ), 400 );
	}

	// Request a Token
	public function request( $params ) {
		$diff = array_diff( array( 'code', 'client_id', 'redirect_uri', 'me' ), array_keys( $params ) );
		if ( ! empty( $diff ) ) {
			return new WP_OAuth_Response( 'invalid_request', __( 'The request is missing one or more required parameters', 'indieauth' ), 400 );
		}
		$response = $this->verify_local_authorization_code(
			array(
				'code'         => $params['code'],
				'redirect_uri' => $params['redirect_uri'],
				'client_id'    => $params['client_id'],
			)
		);
		$error    = get_oauth_error( $response );
		if ( $error ) {
			return $error;
		}
		// Do not issue a token if the authorization code contains no scope
		if ( isset( $response['scope'] ) ) {
			$info  = new IndieAuth_Client_Discovery( $params['client_id'] );
			$token = array(
				'token_type'  => 'Bearer',
				'scope'       => $response['scope'],
				'me'          => $response['me'],
				'issued_by'   => rest_url( 'indieauth/1.0/token' ),
				'client_id'   => $params['client_id'],
				'client_name' => $info->get_name(),
				'client_icon' => $info->get_icon(),
				'issued_at'   => current_time( 'timestamp', 1 ),
			);
			$token = array_filter( $token );

			$token['access_token'] = $this->set_token( $token );
			$user                  = get_user_by_identifier( $response['me'] );
			if ( $user ) {
				$token['profile'] = indieauth_get_user( $user->ID );
			}
			if ( $token ) {
				// Return only the standard keys in the response
				return( wp_array_slice_assoc( $token, array( 'access_token', 'token_type', 'scope', 'me', 'profile' ) ) );
			}
		} else {
			return new WP_OAuth_Response( 'invalid_grant', __( 'This authorization code was issued with no scope, so it cannot be used to obtain an access token', 'indieauth' ), 400 );
		}
		return new WP_OAuth_Response( 'server_error', __( 'There was an error issuing the access token', 'indieauth' ), 500 );
	}

	public static function verify_local_authorization_code( $post_args ) {
		$tokens = new Token_User( '_indieauth_code_' );
		$return = $tokens->get( $post_args['code'] );
		if ( ! $return ) {
			return new WP_OAuth_Response( 'invalid_code', __( 'Invalid authorization code', 'indieauth' ), 401 );
		}
		if ( ( class_exists( 'Indieweb_Plugin' ) && get_option( 'iw_single_author' ) ) || ! is_multi_author() ) {
			$return['me'] = home_url( '/' );
		} else {
			// Return the user profile URL and scope
			$return['me'] = get_author_posts_url( $return['user'] );
		}
		$user = get_user_by_identifier( $return['me'] );
		if ( $user ) {
			$return['profile'] = indieauth_get_user( $user );
		}

		$tokens->destroy( $post_args['code'] );
		return $return;
	}

}

new IndieAuth_Token_Endpoint();
