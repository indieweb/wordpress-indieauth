<?php
/**
 *
 *
 * Implements IndieAuth Token Endpoint
 */

class IndieAuth_Token_Endpoint {
	public function __construct() {
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
			'indieauth/1.0', '/token', array(
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
			'indieauth/1.0', '/token', array(
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'get' ),
					'args'     => array(),
				),
			)
		);
	}

	public function get_token( $token, $hash = true ) {
		return get_indieauth_user_token( '_indieauth_token_', $token, $hash );
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
		// Token consists of properties at minimum: access_token, me, scope
		if ( ! isset( $token['access_token'] ) || ! isset( $token['me'] ) ) {
			return false;
		}
		$access_token = indieauth_hash_token( $token['access_token'] );
		unset( $token['access_token'] );
		$user = get_user_by_identifier( $token['me'] );
		if ( ! $user ) {
			return false;
		}
		return set_indieauth_user_token( $user->ID, '_indieauth_token_', $access_token, $token );
	}

	public function delete_token( $id, $user_id = null ) {
		if ( ! $user_id ) {
			$token = $this->get_token( $id );
			if ( ! isset( $token ) ) {
				$token = $this->get_token( $id, false );
			}
			if ( isset( $token['user'] ) ) {
				$user_id = $token['user'];
			} else {
				return false;
			}
		}
		$id = $hash ? indieauth_hash_token( $id ) : $id;
		return delete_user_meta( $user_id, '_indieauth_token_' . $id );
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
		$endpoint = indieauth_discover_endpoint( $params['me'] );
		$endpoint = isset( $endpoint['authorization_endpoint'] ) ? $endpoint['authorization_endpoint'] : null;
		$response = IndieAuth_Authenticate::verify_authorization_code(
			array(
				'code'         => $params['code'],
				'redirect_url' => $params['redirect_uri'],
				'client_id'    => $params['client_id'],
			), $endpoint
		);
		$error    = get_oauth_error( $response );
		if ( $error ) {
			return $error;
		}
		// Do not issue a token if the authorization code contains no scope
		if ( isset( $response['scope'] ) ) {
			$token  = array(
				'access_token' => indieauth_generate_token(),
				'token_type'   => 'Bearer',
				'scope'        => $response['scope'],
				'me'           => $response['me'],
				'issued_by'    => rest_url( 'indieauth/1.0/token' ),
				'client_id'    => $params['client_id'],
				'issued_at'    => current_time( 'timestamp', 1 ),
			);
			$return = $this->set_token( $token );
			if ( $token ) {
				// Return only the standard keys in the response
				return( wp_array_slice_assoc( $token, array( 'access_token', 'token_type', 'scope', 'me' ) ) );
			}
		} else {
			return new WP_OAuth_Response( 'invalid_grant', __( 'This authorization code was issued with no scope, so it cannot be used to obtain an access token', 'indieauth' ), 400 );
		}
		return new WP_OAuth_Response( 'server_error', __( 'There was an error issuing the access token', 'indieauth' ), 500 );
	}
}

