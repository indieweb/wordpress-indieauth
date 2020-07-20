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
						'grant_type'    => array(),
						'code'          => array(),
						'code_verifier' => array(),
						'client_id'     => array(
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
						'redirect_uri'  => array(
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
						'me'            => array(
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
						'action'        => array(),
						'token'         => array(),
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
		$params = $request->get_params();
		$header = $request->get_header( 'Authorization' );
		if ( ! $header && ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}
		$access_token = $this->get_token_from_bearer_header( $header );
		if ( ! $access_token ) {
			return new WP_OAuth_Response(
				'parameter_absent',
				__(
					'Bearer Token Not Supplied or Server Misconfigured to Not Pass Token. Run diagnostic script in WordPress Admin 
				IndieAuth Settings Page',
					'indieauth'
				),
				400
			);
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
		$args     = array_filter(
			array(
				'code'          => $params['code'],
				'redirect_uri'  => $params['redirect_uri'],
				'client_id'     => $params['client_id'],
				'code_verifier' => isset( $params['code_verifier'] ) ? $params['code_verifier'] : null,
			)
		);
		$response = $this->verify_local_authorization_code( $args );
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
				'issued_at'   => time(),
			);
			$token = array_filter( $token );

			$token['access_token'] = $this->set_token( $token );
			$user                  = get_user_by_identifier( $response['me'] );
			if ( $user ) {
				$token['profile'] = indieauth_get_user( $user->ID );
			}
			if ( $token ) {
				// Return only the standard keys in the response
				return new WP_REST_Response(
					wp_array_slice_assoc(
						$token,
						array(
							'access_token',
							'token_type',
							'scope',
							'me',
							'profile',
						)
					),
					200, // Status Code
					array(
						'Cache-Control' => 'no-store',
						'Pragma'        => 'no-cache',
					)
				);
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
		if ( isset( $return['code_challenge'] ) ) {
			if ( ! isset( $post_args['code_verifier'] ) ) {
				$tokens->destroy( $post_args['code'] );
				return new WP_OAuth_Response( 'invalid_grant', __( 'Failed PKCE Validation', 'indieauth' ), 400 );
			}
			if ( ! pkce_verifier( $return['code_challenge'], $post_args['code_verifier'], $return['code_challenge_method'] ) ) {
				$tokens->destroy( $post_args['code'] );
				return new WP_OAuth_Response( 'invalid_grant', __( 'Failed PKCE Validation', 'indieauth' ), 400 );
			}
			unset( $return['code_challenge'] );
			unset( $return['code_challenge_method'] );
		}
		$return['me'] = get_url_from_user( $return['user'] );

		$user = get_user_by( 'id', $return['user'] );
		if ( $user ) {
			$return['profile'] = indieauth_get_user( $user );
		}

		$tokens->destroy( $post_args['code'] );
		return $return;
	}

}

