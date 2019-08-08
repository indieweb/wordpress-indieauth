<?php
/**
 *
 *
 * Implements IndieAuth Authorization Endpoint
 */

class IndieAuth_Authorization_Endpoint {
	private $tokens;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'login_form_indieauth', array( $this, 'login_form_indieauth' ) );

		$this->tokens = new Token_User( '_indieauth_code_' );
	}

	/**
	 * Register the Route.
	 */
	public function register_routes() {
		register_rest_route(
			'indieauth/1.0',
			'/auth',
			array(
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

	// Get Scope Descriptions
	public static function scopes( $scope = 'all' ) {
		$scopes = array(
			// Micropub Scopes
			'post'     => __( 'Legacy Scope (Deprecated)', 'indieauth' ),
			'create'   => __( 'Allows the application to create posts and upload to the Media Endpoint', 'indieauth' ),
			'update'   => __( 'Allows the application to update posts', 'indieauth' ),
			'delete'   => __( 'Allows the application to delete posts', 'indieauth' ),
			'undelete' => __( 'Allows the application to undelete posts', 'indieauth' ),
			'media'    => __( 'Allows the application to upload to the media endpoint', 'indieauth' ),
			// Microsub Scopes
			'read'     => __( 'Allows the application read access to channels', 'indieauth' ),
			'follow'   => __( 'Allows the application to manage a follow list', 'indieauth' ),
			'mute'     => __( 'Allows the application to mute and unmute users', 'indieauth' ),
			'block'    => __( 'Allows the application to block and unlock users', 'indieauth' ),
			'channels' => __( 'Allows the application to manage channels', 'indieauth' ),
			'save'     => __( 'Allows the application to save content for later retrieval', 'indieauth' ),
			// Profile
			'profile'  => __( 'Returns a complete profile to the application. Without this only a display name, avatar, and url will be returned', 'indieauth' ),
		);
		if ( 'all' === $scope ) {
			return $scopes;
		}
		$description = isset( $scopes[ $scope ] ) ? $scopes[ $scope ] : __( 'No Description Available', 'indieauth' );
		return apply_filters( 'indieauth_scope_description', $description, $scope );
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
				// translators: Name of missing parameter
				return new WP_OAuth_Response( 'parameter_absent', sprintf( __( 'Missing Parameter: %1$s', 'indieauth' ), $require ), 400 );
			}
		}
		$url  = wp_login_url( $params['redirect_uri'], true );
		$args = array_filter(
			array(
				'action'                => 'indieauth',
				'_wpnonce'              => wp_create_nonce( 'wp_rest' ),
				'response_type'         => $params['response_type'],
				'client_id'             => $params['client_id'],
				'me'                    => $params['me'],
				'state'                 => $params['state'],
				'code_challenge'        => isset( $params['code_challenge'] ) ? $params['code_challenge'] : null,
				'code_challenge_method' => isset( $params['code_challenge_method'] ) ? $params['code_challenge_method'] : null,
			)
		);
		if ( 'code' === $params['response_type'] ) {
			$args['scope'] = isset( $params['scope'] ) ? $params['scope'] : 'create update';
		}
		$url = add_query_params_to_url( $args, $url );

		return new WP_REST_Response( array( 'url' => $url ), 302, array( 'Location' => $url ) );
	}

	public function set_code( $user_id, $token ) {
		$this->tokens->set_user( $user_id );
		return $this->tokens->set( $token, 600 );
	}

	public function get_code( $code, $hash = true ) {
		$token = $this->tokens->get( $code, $hash );
		return $token;
	}

	public function delete_code( $code, $user_id = null ) {
		$this->tokens->set_user( $user_id );
		return $this->tokens->destroy( $code );
	}

	public function verify( $request ) {
		$params   = $request->get_params();
		$required = array( 'redirect_uri', 'client_id', 'code' );
		foreach ( $required as $require ) {
			if ( ! isset( $params[ $require ] ) ) {
				// translators: Name of missing parameter
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
		// If there is a code challenge
		if ( isset( $token['code_challenge'] ) ) {
			$code_verifier = $request->get_param( 'code_verifier' );
			if ( ! $code_verifier ) {
				$this->delete_code( $code, $token['user'] );
				return new WP_OAuth_Response( 'invalid_grant', __( 'Failed PKCE Validation', 'indieauth' ), 400 );
			}
			if ( ! pkce_verifier( $token['code_challenge'], $code_verifier, $token['code_challenge_method'] ) ) {
				$this->delete_code( $code, $token['user'] );
				return new WP_OAuth_Response( 'invalid_grant', __( 'Failed PKCE Validation', 'indieauth' ), 400 );
			}
			unset( $token['code_challenge'] );
			unset( $token['code_challenge_method'] );
		}

		if ( array() === array_diff_assoc( $params, $token ) ) {
			$this->delete_code( $code, $token['user'] );

			$return = array( 'me' => get_url_from_user( $user->ID ) );

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
		$current_user = wp_get_current_user();
		// phpcs:disable
		$client_id     = wp_unslash( $_GET['client_id'] ); // WPCS: CSRF OK
		$info = new IndieAuth_Client_Discovery( $client_id );
		$client_name = $info->get_name();
		$client_icon = $info->get_icon();
		$redirect_uri  = isset( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : null;
		$scope         = isset( $_GET['scope'] ) ? wp_unslash( $_GET['scope'] ) : null;
		$scopes        = explode( ' ', $scope );
		$state         = isset( $_GET['state'] ) ? $_GET['state'] : null;
		$me            = isset( $_GET['me'] ) ? wp_unslash( $_GET['me'] ) : null;
		$response_type = isset( $_GET['response_type'] ) ? wp_unslash( $_GET['response_type'] ) : null;
		$code_challenge = isset( $_GET['code_challenge'] ) ? wp_unslash( $_GET['code_challenge'] ) : null;
		$code_challenge_method = isset( $_GET['code_challenge_method'] ) ? wp_unslash( $_GET['code_challenge_method'] ) : null;

		// phpcs:enable
		$action = 'indieauth';
		$args   = array_filter(
			compact(
				'client_id',
				'redirect_uri',
				'state',
				'me',
				'response_type',
				'action'
			)
		);
		$url    = add_query_params_to_url( $args, wp_login_url() );
		if ( 'code' === $_GET['response_type'] ) {
			include plugin_dir_path( __DIR__ ) . 'templates/indieauth-authorize-form.php';
		} elseif ( 'id' === $_GET['response_type'] ) {
			include plugin_dir_path( __DIR__ ) . 'templates/indieauth-authenticate-form.php';
		}
		include plugin_dir_path( __DIR__ ) . 'templates/indieauth-auth-footer.php';
	}

	public function confirmed() {
		$current_user = wp_get_current_user();
		// phpcs:disable
		$client_id     = wp_unslash( $_POST['client_id'] ); // WPCS: CSRF OK
		$redirect_uri  = isset( $_POST['redirect_uri'] ) ? wp_unslash( $_POST['redirect_uri'] ) : null;
		$scope         = isset( $_POST['scope'] ) ? $_POST['scope'] : array();
		$code_challenge  = isset( $_POST['code_challenge'] ) ? wp_unslash( $_POST['code_challenge'] ) : null;
		$code_challenge_method  = isset( $_POST['code_challenge_method'] ) ? wp_unslash( $_POST['code_challenge_method'] ) : null;
		$search = array_search( 'post', $scope, true );
		if ( is_numeric( $search ) ) {
			unset( $scope[ $search ] );
			$scope = array_unique( array_merge( $scope, array( 'create', 'update' ) ) );
		}
		$scope = implode( ' ', $scope );

		$state         = isset( $_POST['state'] ) ? $_POST['state'] : null;
		$me            = isset( $_POST['me'] ) ? wp_unslash( $_POST['me'] ) : null;
		$response_type = isset( $_POST['response_type'] ) ? wp_unslash( $_POST['response_type'] ) : null;
		/// phpcs:enable
		$token = compact( 'response_type', 'client_id', 'redirect_uri', 'scope', 'me', 'code_challenge', 'code_challenge_method' );
		$token = array_filter( $token );
		$code  = self::set_code( $current_user->ID, $token );
		$url   = add_query_params_to_url(
			array(
				'code'  => $code,
				'state' => $state,
			),
			$redirect_uri
		);
		wp_redirect( $url ); // phpcs:ignore
	}
}

new IndieAuth_Authorization_Endpoint();
