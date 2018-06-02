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
						'response_type' => array(),
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
						'state'         => array(),
					),
				),
				array(
					'methods'  => WP_REST_Server::CREATABLE,
					'callback' => array( $this, 'verify' ),
					'args'     => array(
						'code'         => array(),
						'client_id'    => array(
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
						'redirect_uri' => array(
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
		if ( ! isset( $params['response_type'] ) ) {
			$params['response_type'] = 'id';
		}
		if ( 'code' !== $params['response_type'] && 'id' !== $params['response_type'] ) {
			return new WP_OAuth_Response( 'unsupported_response_type', __( 'Unsupported Response Type', 'indieauth' ), 400 );
		}
		$required = array( 'redirect_uri', 'client_id', 'state', 'me' );
		foreach ( $required as $require ) {
			if ( ! isset( $params[ $require ] ) ) {
				return new WP_OAuth_Response( 'parameter_absent', sprintf( __( 'Missing Parameter: %1$s', 'indieauth' ), $require ), 400 );
			}
		}
		$url  = wp_login_url( $params['redirect_uri'], true );
		$args = array(
			'action'        => 'indieauth',
			'_wpnonce'      => wp_create_nonce( 'wp_rest' ),
			'response_type' => $params['response_type'],
			'client_id'     => $params['client_id'],
			'me'            => $params['me'],
			'state'         => rawurlencode( $params['state'] ),
		);
		if ( 'code' === $params['response_type'] ) {
			$args['scope'] = rawurlencode( isset( $params['scope'] ) ? $params['scope'] : 'create update' );
		}
		$url = add_query_arg( $args, $url );

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
		$params   = $request->get_params();
		$required = array( 'redirect_uri', 'client_id', 'code' );
		foreach ( $required as $require ) {
			if ( ! isset( $params[ $require ] ) ) {
				return new WP_OAuth_Response( 'parameter_absent', sprintf( __( 'Missing Parameter: %1$s', 'indieauth' ), $require ), 400 );
			}
		}
		$params = wp_array_slice_assoc( $params, array( 'client_id', 'redirect_uri' ) );
		$code   = $request->get_param( 'code' );
		$token  = $this->get_code( $code );
		if ( ! $token ) {
			return new WP_OAuth_Response( 'invalid_grant', __( 'Invalid authorization code', 'indieauth' ), 400 );
		}
		$user = get_user_by( 'id', $token['user'] );
		if ( $token['expiration'] <= time() ) {
			$this->delete_code( $code, $token['user'] );
			return new WP_OAuth_Response( 'invalid_grant', __( 'The authorization code expired', 'indieauth' ), 400 );
		}
		unset( $token['expiration'] );

		if ( array() === array_diff_assoc( $params, $token ) ) {
			$this->delete_code( $code );
			// Return the user profile URL and scope
			if ( ! empty( $user->user_url ) ) {
				$return = array( 'me' => $user->user_url );
			} else {
				$return = array( 'me' => get_author_posts_url( $user->ID ) );
			}
			if ( isset( $token['scope'] ) ) {
				$return['scope'] = $token['scope'];
			}
			return $return;
		}
		return new WP_OAuth_Response( 'invalid_grant', __( 'There was an error verifying the authorization code. Check that the client_id and redirect_uri match the original request.', 'indieauth' ), 400 );
	}


	public function login_form_indieauth() {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		if ( 'GET' === $_SERVER['REQUEST_METHOD'] ) {
			$this->authorize();
		} elseif ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$this->confirmed();
		}
		exit;
	}

	public function authorize() {
		$current_user  = wp_get_current_user();
		$client_id     = wp_unslash( $_GET['client_id'] ); // WPCS: CSRF OK
		$redirect_uri  = isset( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : null;
		$scope         = isset( $_GET['scope'] ) ? wp_unslash( $_GET['scope'] ) : null;
		$state         = isset( $_GET['state'] ) ? wp_unslash( $_GET['state'] ) : null;
		$me            = isset( $_GET['me'] ) ? wp_unslash( $_GET['me'] ) : null;
		$response_type = isset( $_GET['response_type'] ) ? wp_unslash( $_GET['response_type'] ) : null;
		$action        = 'indieauth';
		$url           = add_query_arg(
			compact(
				'client_id',
				'redirect_uri',
				'scope',
				'state',
				'me',
				'response_type',
				'action'
			),
			wp_login_url()
		);
		if ( 'code' === $_GET['response_type'] ) {
			include plugin_dir_path( __DIR__ ) . 'templates/indieauth-authorize-form.php';
		} elseif ( 'id' === $_GET['response_type'] ) {
			include plugin_dir_path( __DIR__ ) . 'templates/indieauth-authenticate-form.php';
		}
		include plugin_dir_path( __DIR__ ) . 'templates/indieauth-auth-footer.php';
	}

	public function confirmed() {
		$current_user  = wp_get_current_user();
		$client_id     = wp_unslash( $_POST['client_id'] ); // WPCS: CSRF OK
		$redirect_uri  = isset( $_POST['redirect_uri'] ) ? wp_unslash( $_POST['redirect_uri'] ) : null;
		$scope         = isset( $_POST['scope'] ) ? wp_unslash( $_POST['scope'] ) : null;
		$state         = isset( $_POST['state'] ) ? wp_unslash( $_POST['state'] ) : null;
		$me            = isset( $_POST['me'] ) ? wp_unslash( $_POST['me'] ) : null;
		$response_type = isset( $_POST['response_type'] ) ? wp_unslash( $_POST['response_type'] ) : null;
		$token         = compact( 'response_type', 'client_id', 'redirect_uri', 'scope', 'me' );
		$token         = array_filter( $token );
		$code          = IndieAuth_Authorization_Endpoint::set_code( $current_user->ID, $token );
		$url           = add_query_arg(
			array(
				'code'  => $code,
				'state' => $state,
			),
			$redirect_uri
		);
		wp_redirect( $url );
	}
}

new IndieAuth_Authorization_Endpoint();
