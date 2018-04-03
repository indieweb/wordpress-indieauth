<?php
/**
 *
 *
 * Implements IndieAuth Authorization Endpoint
 */

class IndieAuth_Authorization_Endpoint {
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'login_form_indieauth', array( $this, 'login_form_indieauth' ) );
	}

	/**
	 * Register the Route.
	 */
	public function register_routes() {
		register_rest_route(
			'indieauth/1.0', '/auth', array(
				array(
					'methods'  => WP_REST_Server::READABLE,
					'callback' => array( $this, 'request' ),
					'args'     => array(
						'response_type' => array(
							'required' => true,
						),
						'client_id'     => array(
							'required'          => true,
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
						'redirect_uri'  => array(
							'required'          => true,
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
						'me'            => array(
							'required'          => true,
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
						'state'         => array(
							'required' => true,
						),
					),
				),
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'verify' ),
					'args'     => array(
						'code'         => array(
							'required' => true,
						),
						'client_id'    => array(
							'required'          => true,
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
						'redirect_uri' => array(
							'required'          => true,
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
					),
				),
			)
		);
	}

	public function request( $request ) {
		$params = $request->get_params();
		if ( 'code' !== $params['response_type'] && 'id' !== $params['response_type'] ) {
			return new WP_Error( 'unsupported_response_type', __( 'Unsupported Response Type', 'indieauth' ), array( 'status' => 400 ) );
		}
		$url = wp_login_url( $params['redirect_uri'], true );
		$url = add_query_arg(
			array(
				'action'    => 'indieauth',
				'client_id' => $params['client_id'],
				'state'     => $params['state'],
				'scope'     => isset( $params['scope'] ) ? $params['scope'] : 'create update',
				'me'        => $params['me'],
			), $url
		);
		// Valid parameters, ensure the user is logged in.
		if ( ! is_user_logged_in() ) {
			$url = wp_login_url( $url );
			wp_safe_redirect( $url );
			exit;
		}
		return new WP_REST_Response( array( 'url' => $url ), 302, array( 'Location' => $url ) );
	}

	public static function set_code( $user_id, $token ) {
		$code                = indieauth_generate_token();
		$token['expiration'] = time() + 600;
		set_indieauth_user_token( $user_id, '_indieauth_code_', indieauth_hash_token( $code ), $token );
		return $code;
	}

	public static function get_code( $code, $hash = true ) {
		$token = get_indieauth_user_token( '_indieauth_code_', $code, $hash );
		return $token;
	}

	public static function delete_code( $code, $user_id = null ) {
		if ( ! $user_id ) {
				$token = IndieAuth_Authorization_Endpoint::get_code( $code );
			if ( isset( $token['user'] ) ) {
					$user_id = $token['user'];
			} else {
					return false;
			}
		}
				$id = indieauth_hash_token( $code );
				return delete_user_meta( $user_id, '_indieauth_code_' . $id );

	}

	public function verify( $request ) {
		$params = wp_array_slice_assoc( $request->get_params(), array( 'client_id', 'redirect_uri' ) );
		$code   = $request->get_param( 'code' );
		$token  = $this->get_code( $code );
		$user   = get_user_by( 'id', $token['user'] );
		if ( $token['expiration'] <= time() ) {
			$this->delete_code( $code, $token['user'] );
			return array( 'error' => 'Failure' );
		}
		unset( $token['expiration'] );

		if ( array() === array_diff_assoc( $params, $token ) ) {
			$this->delete_code( $code );
			return array(
				'scope' => $token['scope'],
				'me'    => $user->user_url,
			);
		}
		// Look up what Failure looks like
		return array( 'error' => 'Failure' );
	}


	public function login_form_indieauth() {
		load_template( plugin_dir_path( __DIR__ ) . 'templates/indieauth-authorize-form.php' );
		exit;
	}
}

