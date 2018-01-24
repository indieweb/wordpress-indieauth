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
							'validate_callback' => array( $this, 'is_valid_url' ),
							'sanitize_callback' => 'esc_url_raw',
						),
						'redirect_uri' => array(
							'validate_callback' => array( $this, 'is_valid_url' ),
							'sanitize_callback' => 'esc_url_raw',
						),
						'me'           => array(
							'validate_callback' => array( $this, 'is_valid_url' ),
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

	/**
	 * Returns if valid URL for REST validation
	 *
	 * @param string $url
	 *
	 * @return boolean
	 */
	public static function is_valid_url( $url, $request = null, $key = null ) {
		if ( ! is_string( $url ) || empty( $url ) ) {
			return false;
		}
		return filter_var( $url, FILTER_VALIDATE_URL );
	}

	public function generate() {
		return wp_generate_password( 128, false );
	}

	public function hash( $string ) {
		return base64_encode( wp_hash( $string, 'secure_auth' ) );
	}

	public function get_token( $token, $hash = true ) {
		$key = '_indieauth_token_';
		// Either token is already hashed or is not
		$key    .= $hash ? $this->hash( $token ) : $token;
		$args    = array(
			'number'      => 1,
			'count_total' => false,
			'meta_query'  => array(
				array(
					'key'     => $key,
					'compare' => 'EXISTS',
				),
			),
		);
		$query   = new WP_User_Query( $args );
		$results = $query->get_results();
		if ( empty( $results ) ) {
			return null;
		}
		$user  = $results[0];
		$value = get_user_meta( $user->ID, $key, true );
		if ( empty( $value ) ) {
				return null;
		}
		$value['user'] = $user->ID;
		return $value;
	}

	public function get( $request ) {
		$params       = $request->get_params();
		$access_token = $this->get_token_from_bearer_header( $request->get_header( 'Authorization' ) );
		if ( ! $access_token ) {
			return new WP_Error(
				'invalid_request', __( 'Bearer Token Not Supplied', 'indieauth' ),
				array(
					'status' => '401',
				)
			);
		}
		$token = $this->get_token( $access_token );
		if ( ! $token ) {
			return new WP_Error(
				'invalid_access_token', __( 'Invalid Token', 'indieauth' ),
				array(
					'status'   => '401',
					'response' => $access_token,
				)
			);
		}
		return rest_ensure_response( $token );
	}

	public function set_token( $token ) {
		// Token consists of properties at minimum: access_token, me, scope
		if ( ! isset( $token['access_token'] ) || ! isset( $token['me'] ) ) {
			return false;
		}
		$access_token = $this->hash( $token['access_token'] );
		unset( $token['access_token'] );
		$user = get_user_by_identifier( $token['me'] );
		if ( ! $user ) {
			return false;
		}
		return add_user_meta( $user->ID, '_indieauth_token_' . $access_token, $token );
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
		$id = $hash ? $this->hash( $id ) : $id;
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
		return new WP_Error(
			'invalid_request', __( 'Invalid Request', 'indieauth' ),
			array(
				'status'   => '400',
				'response' => $params,
			)
		);

	}

	// Request a Token
	public function request( $params ) {
		$diff = array_diff( array( 'code', 'client_id', 'redirect_uri', 'me' ), array_keys( $params ) );
		if ( ! empty( $diff ) ) {
			return new WP_Error(
				'invalid_request', __( 'Invalid Request', 'indieauth' ),
				array(
					'status'   => '400',
					'response' => $params,
				)
			);
		}
		$response = IndieAuth_Authenticate::verify_authorization_code( $params['code'], $params['redirect_uri'], $params['client_id'] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$token  = array(
			'access_token' => $this->generate(),
			'token_type'   => 'Bearer',
			'scope'        => $response['scope'],
			'me'           => $response['me'],
			'issued_by'    => rest_url( 'indieauth/1.0/token' ),
			'client_id'    => $params['client_id'],
			'issued_at'    => current_time( 'timestamp', 1 ),
		);
		$return = $this->set_token( $token );
		if ( $token ) {
			return( $token );
		}
		return new WP_Error( 'error', __( 'Set Token Error', 'indieauth' ) );
	}
}

