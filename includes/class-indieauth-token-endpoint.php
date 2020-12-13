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
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post' ),
					'args'                => array(
						/* grant_type=authorization_code is the only one currently supported.
						 */
						'grant_type'    => array(),
						/* The authorization code received from the authorization endpoint in the redirect.
						 */
						'code'          => array(),
						/* The client's URL, which MUST match the client_id used in the authentication request.
						 */
						'client_id'     => array(
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
						/* The client's redirect URL, which MUST match the initial authentication request.
						 */
						'redirect_uri'  => array(
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
						/* The original plaintext random string generated before starting the authorization request.
						 */
						'code_verifier' => array(),
						/* Currently only Used for Token Revokation as action=revoke
						 */
						'action'        => array(),
						/* Paired with Action for Token Revokation
						 */
						'token'         => array(),
					),
					'permission_callback' => '__return_true',
				),
			)
		);
		register_rest_route(
			'indieauth/1.0',
			'/token',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'args'                => array(),
					'permission_callback' => '__return_true',
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
		$response = indieauth_verify_local_authorization_code( $args );

		$error = get_oauth_error( $response );
		if ( $error ) {
			return $error;
		}

		$return = array(
			'me' => $response['me'],
		);

		if ( isset( $response['scope'] ) ) {
			$scopes = array_filter( explode( ' ', $response['scope'] ) );
			if ( ! array_key_exists( 'user', $response ) ) {
				$user = get_user_by_identifier( $response['me'] );
				$user = $user->ID;
			}
			if ( in_array( 'profile', $scopes, true ) ) {
				$return['profile'] = indieauth_get_user( $user, in_array( 'email', $scopes, true ) );
			}

			// Issue a token
			if ( ! empty( array_diff( $scopes, array( 'profile', 'email' ) ) ) ) {
				$info                  = new IndieAuth_Client_Discovery( $params['client_id'] );
				$return['token_type']  = 'Bearer';

				/* Add UUID for reference. In case you’d like to build infrastructure for additional properties and store them in an alternate location.
				 * This idea came from the core application password implementation. 
				 */
				$return['uuid']        =  wp_generate_uuid4(); 

				$return['scope']       = $response['scope'];
				$return['issued_by']   = rest_url( 'indieauth/1.0/token' );
				$return['client_id']   = $params['client_id'];
				$return['client_name'] = $info->get_name();
				$return['client_icon'] = $info->get_icon();
				$return['issued_at']   = time();
				$return                = array_filter( $return );

				$return['access_token'] = $this->set_token( $return );
			}
		}

		if ( $return ) {
			// Return only the standard keys in the response
			return new WP_REST_Response(
				wp_array_slice_assoc(
					$return,
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
		return new WP_OAuth_Response( 'server_error', __( 'There was an error in response.', 'indieauth' ), 500 );
	}

}

