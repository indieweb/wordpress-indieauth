<?php
/**
 *
 *
 * Implements IndieAuth Authorization Endpoint
 */

class IndieAuth_Authorization_Endpoint extends IndieAuth_Endpoint {
	private $codes;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'login_form_indieauth', array( $this, 'login_form_indieauth' ) );
		add_filter( 'indieauth_metadata', array( $this, 'metadata' ) );
		add_filter( 'rest_index_indieauth_endpoints', array( $this, 'rest_index' ) );

		add_action( 'wp_head', array( $this, 'html_header' ) );
		add_action( 'template_redirect', array( $this, 'http_header' ) );

		$this->codes = new Token_User( '_indieauth_code_' );
	}

	public function http_header() {
		if ( is_author() || is_front_page() ) {
			$this->set_http_header( $this->get_endpoint(), 'authorization_endpoint' );
		}
	}

	public function html_header() {
		$kses = array(
			'link' => array(
				'href' => array(),
				'rel'  => array(),
			),
		);

		if ( is_author() || is_front_page() ) {
			echo wp_kses( $this->get_html_header( $this->get_endpoint(), 'authorization_endpoint' ), $kses );
		}
	}

	public static function get_endpoint() {
		return rest_url( '/indieauth/1.0/auth' );
	}

	public static function get_response_types() {
		return array_unique( apply_filters( 'indieauth_response_types_supported', array( 'code' ) ) );
	}

	public function rest_index( $index ) {
		$index['authorization'] = $this->get_endpoint();
		return $index;
	}

	public function metadata( $metadata ) {
		$metadata['authorization_endpoint']                         = $this->get_endpoint();
		$metadata['response_types_supported']                       = $this->get_response_types();
		$metadata['authorization_response_iss_parameter_supported'] = true;
		return $metadata;
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
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'args'                => array(
						/* Code is currently the only type as of IndieAuth 1.1 and a response_type is now required, but not requiring it here yet.
						 * Indicates to the authorization server that an authorization code should be returned as the response.
						 */
						'response_type'         => array(
							'default' => 'code',
						),
						// The Client URL.
						'client_id'             => array(
							'validate_callback' => 'indieauth_validate_client_identifier',
							'sanitize_callback' => 'esc_url_raw',
							'required'          => true,
						),
						// The redirect URL indicating where the user should be redirected to after approving the request.
						'redirect_uri'          => array(
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
							'required'          => true,
						),
						/* A parameter set by the client which will be included when the user is redirected back to the client.
						 * This is used to prevent CSRF attacks. The authorization server MUST return the unmodified state value back to the client.
						 */
						'state'                 => array(
							'required' => true,
						),
						/* Code Challenge.
						 * IndieAuth 1.1 requires PKCE, but for now these parameters will remain optional to give time for other implementers.
						 */
						'code_challenge'        => array(),
						/* The hashing method used to calculate the code challenge, e.g. "S256"
						 */
						'code_challenge_method' => array(),

						/* A space-separated list of scopes the client is requesting, e.g. "profile", or "profile create".
						 * If the client omits this value, the authorization server MUST NOT issue an access token for this authorization code.
						 * Only the user's profile URL may be returned without any scope requested. See Profile Information for details about
						 * which scopes to request to return user profile information. Optional.
						 */
						'scope'                 => array(),
						/* The Profile URL the user entered. Optional.
						 */
						'me'                    => array(
							'validate_callback' => 'indieauth_validate_user_identifier',
							'sanitize_callback' => 'esc_url_raw',
						),
					),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post' ),
					'args'                => array(
						/* grant_type=authorization_code is the only POST option supported right now. This remains optional as not required in
						 * the original but will eventually be required.
						 */
						'grant_type'    => array(
							'default' => 'authorization_code',
						),
						/* The authorization code received from the authorization endpoint in the redirect.
						 */
						'code'          => array(),
						/* The client's URL, which MUST match the client_id used in the authentication request.
						*/
						'client_id'     => array(
							'validate_callback' => 'indieauth_validate_client_identifier',
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
					),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	// Get Scope Descriptions
	public static function scopes( $scope = 'all' ) {
		$scopes = array(
			// Micropub Scopes
			'post'     => __( 'Legacy Scope (Deprecated)', 'indieauth' ),
			'draft'    => __( 'Allows the applicate to create posts in draft status only', 'indieauth' ),
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
			'profile'  => __( 'Allows access to the users default profile information which includes name, photo, and url', 'indieauth' ),
			'email'    => __( 'Allows access to the users email address', 'indieauth' ),
		);
		if ( 'all' === $scope ) {
			return $scopes;
		}
		$description = isset( $scopes[ $scope ] ) ? $scopes[ $scope ] : __( 'No Description Available', 'indieauth' );
		return apply_filters( 'indieauth_scope_description', $description, $scope );
	}

	/*
	 * Output a list of checkboxes to select scopes.
	 *
	 * @param array $scopes Scopes to Output.
	 */
	public static function scope_list( $scopes ) {
		if ( ! empty( $scopes ) ) {
			$create = array_search( 'create', $scopes, true );
			if ( false !== $create ) {
				unset( $scopes[ $create ] );
				$draft = array_search( 'draft', $scopes, true );
				if ( false !== $draft ) {
					unset( $scopes[ $draft ] );
				}
				$scopes = array_values( $scopes );
				echo '<div class="create_scope">';
				echo wp_kses(
					sprintf( '<li><input type="radio" name="scope[]" value="create" %1$s><strong>create</strong> - %2$s</li>', esc_attr( 'checked' ), esc_html( self::scopes( 'create' ) ) ),
					array(
						'li'     => array(),
						'strong' => array(),
						'input'  => array(
							'type'    => array(),
							'name'    => array(),
							'value'   => array(),
							'checked' => array(),
						),
					)
				);
				echo wp_kses(
					sprintf( '<li><input type="radio" name="scope[]" value="draft"><strong>draft</strong> - %1$s</li>', esc_html( self::scopes( 'draft' ) ) ),
					array(
						'li'     => array(),
						'strong' => array(),
						'input'  => array(
							'type'  => array(),
							'name'  => array(),
							'value' => array(),
						),
					)
				);
				echo wp_kses(
					sprintf( '<li><input type="radio" name="scope[]" value=""><strong>none</strong> - %1$s</li>', __( 'Token will have no privileges to create posts', 'indieauth' ) ),
					array(
						'li'     => array(),
						'strong' => array(),
						'input'  => array(
							'type'  => array(),
							'name'  => array(),
							'value' => array(),
						),
					)
				);
				echo '</div>';
			}
			foreach ( $scopes as $s ) {
				echo wp_kses(
					sprintf( '<li><input type="checkbox" name="scope[]" value="%1$s" %2$s /><strong>%1$s</strong> - %3$s</li>', $s, checked( true, true, false ), esc_html( self::scopes( $s ) ) ),
					array(
						'li'     => array(),
						'strong' => array(),
						'input'  => array(
							'type'    => array(),
							'name'    => array(),
							'value'   => array(),
							'checked' => array(),
						),
					)
				);
			}
		}
	}

	/*
	 * Authorization Endpoint GET request handler.
	 *
	 * @param WP_REST_Request $request The Request Object.
	 * @return Response to Return to the REST Server.
	 */
	public function get( $request ) {
		$params = $request->get_params();
		if ( ! isset( $params['response_type'] ) || 'id' === $params['response_type'] ) {
			$params['response_type'] = 'code';
		}
		if ( 'code' === $params['response_type'] ) {
			return $this->code( $params );
		}

		return new WP_OAuth_Response( 'unsupported_response_type', __( 'Unsupported Response Type', 'indieauth' ), 400 );
	}

	/*
	 * Handler for Response Type Code.
	 *
	 * @param array $params The Parameters Passed to the REST Server.
	 * @return Response to Return to the REST Server.
	 */
	public function code( $params ) {
		$required = array( 'redirect_uri', 'client_id', 'state' );
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
				'me'                    => isset( $params['me'] ) ? $params['me'] : null,
				'state'                 => $params['state'],
				'code_challenge'        => isset( $params['code_challenge'] ) ? $params['code_challenge'] : null,
				'code_challenge_method' => isset( $params['code_challenge_method'] ) ? $params['code_challenge_method'] : null,
			)
		);

		$args['scope'] = isset( $params['scope'] ) ? $params['scope'] : '';
		if ( ! preg_match( '@^([\x21\x23-\x5B\x5D-\x7E]+( [\x21\x23-\x5B\x5D-\x7E]+)*)?$@', $args['scope'] ) ) {
			return new WP_OAuth_Response( 'invalid_grant', __( 'Invalid scope request', 'indieauth' ), 400 );
		}

		$scopes = explode( ' ', $args['scope'] );

		if ( in_array( 'email', $scopes, true ) && ! in_array( 'profile', $scopes, true ) ) {
			return new WP_OAuth_Response( 'invalid_grant', __( 'Cannot request email scope without profile scope', 'indieauth' ), 400 );
		}

		$url = add_query_params_to_url( $args, $url );

		return new WP_REST_Response( array( 'url' => $url ), 302, array( 'Location' => $url ) );
	}

	public function set_code( $user_id, $code ) {
		$this->codes->set_user( $user_id );
		return $this->codes->set( $code, 600 );
	}

	public function get_code( $code, $hash = true ) {
		$code = $this->codes->get( $code, $hash );
		return $code;
	}

	public function delete_code( $code, $user_id = null ) {
		$this->codes->set_user( $user_id );
		return $this->codes->destroy( $code );
	}

	/*
	 * Authorization Endpoint POST request handler.
	 *
	 * @param WP_REST_Request $request The Request Object.
	 * @return Response to Return to the REST Server.
	 */

	public function post( $request ) {
		$params = $request->get_params();

		if ( 'authorization_code' === $params['grant_type'] ) {
			return $this->authorization_code( $params );
		}

		return new WP_OAuth_Response( 'unsupported_grant_type', __( 'Endpoint only accepts authorization_code grant_type', 'indieauth' ), 400 );
	}

	/*
	 * Grant Type Authorization Code Request Handler.
	 *
	 * @param array $params Parameters.
	 * @return Response to Return to the REST Server.
	 */
	public function authorization_code( $params ) {
		$required = array( 'redirect_uri', 'client_id', 'code', 'grant_type' );
		foreach ( $required as $require ) {
			if ( ! isset( $params[ $require ] ) ) {
				// translators: Name of missing parameter
				return new WP_OAuth_Response( 'parameter_absent', sprintf( __( 'Missing Parameter: %1$s', 'indieauth' ), $require ), 400 );
			}
		}

		$code          = $params['code'];
		$code_verifier = isset( $params['code_verifier'] ) ? $params['code_verifier'] : null;
		$params        = wp_array_slice_assoc( $params, array( 'client_id', 'redirect_uri' ) );
		$token         = $this->get_code( $code );
		$scopes        = isset( $token['scope'] ) ? array_filter( explode( ' ', $token['scope'] ) ) : array();

		if ( ! $token ) {
			return new WP_OAuth_Response( 'invalid_grant', __( 'Invalid authorization code', 'indieauth' ), 400 );
		}
		$user = get_user_by( 'id', $token['user'] );
		if ( $token['exp'] <= time() ) {
			$this->delete_code( $code, $token['user'] );
			return new WP_OAuth_Response( 'invalid_grant', __( 'The authorization code expired', 'indieauth' ), 400 );
		}
		unset( $token['exp'] );
		// If there is a code challenge
		if ( isset( $token['code_challenge'] ) ) {
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

			$return = array( 'me' => $token['me'] );

			if ( in_array( 'profile', $scopes, true ) ) {
				$return['profile'] = indieauth_get_user( $user, in_array( 'email', $scopes, true ) );
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
		$client_id     = esc_url_raw( wp_unslash( $_GET['client_id'] ) ); // WPCS: CSRF OK
		$client_term                 = IndieAuth_Client_Taxonomy::add_client_with_discovery( $client_id );
		if ( ! is_wp_error( $client_term ) ) {
			$client_name = $client_term['name'];
			$client_icon = $client_term['icon'];
		} else {
			$client_name = $client_term->get_error_message();
			$client_icon = null;
		}
		if ( ! empty( $client_name ) ) {
			$client = sprintf( '<a href="%1$s">%2$s</a>', $client_id, $client_name );
		} else {
			$client = sprintf( '<a href="%1$s">%1$s</a>', $client_id );
		}

		$redirect_uri  = isset( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : null;
		$scope         = isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( $_GET['scope'] ) ) : null;
		$scopes        = array_filter( explode( ' ', $scope ) );
		$state         = isset( $_GET['state'] ) ? $_GET['state'] : null;
		$me            = isset( $_GET['me'] ) ? esc_url_raw( wp_unslash( $_GET['me'] ) ) : null;
		$response_type = isset( $_GET['response_type'] ) ? sanitize_text_field( wp_unslash( $_GET['response_type'] ) ) : null;
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
		if ( empty( $scopes ) || empty( array_diff( $scopes, array( 'profile', 'email' ) ) ) ) {
			include plugin_dir_path( __DIR__ ) . 'templates/indieauth-authenticate-form.php';
		} else {
			include plugin_dir_path( __DIR__ ) . 'templates/indieauth-authorize-form.php';
		}

		include plugin_dir_path( __DIR__ ) . 'templates/indieauth-auth-footer.php';
	}

	public function confirmed() {
		$current_user = wp_get_current_user();
		$user         = $current_user->ID;
		// phpcs:disable
		$client_id     = wp_unslash( $_POST['client_id'] ); // WPCS: CSRF OK
		$redirect_uri  = isset( $_POST['redirect_uri'] ) ? wp_unslash( $_POST['redirect_uri'] ) : null;
		$scope         = isset( $_POST['scope'] ) ? $_POST['scope'] : array();
		$code_challenge  = isset( $_POST['code_challenge'] ) ? wp_unslash( $_POST['code_challenge'] ) : null;
		$code_challenge_method  = isset( $_POST['code_challenge_method'] ) ? wp_unslash( $_POST['code_challenge_method'] ) : null;

		// Do not allow the post scope as deprecated. For compatibility, instead update the offering to the more limited but functionally identical create/update.
		$search = array_search( 'post', $scope, true );
		if ( is_numeric( $search ) ) {
			unset( $scope[ $search ] );
			$scope = array_unique( array_merge( $scope, array( 'create', 'update' ) ) );
		}

		$scope = implode( ' ', $scope );

		$state         = isset( $_POST['state'] ) ? $_POST['state'] : null;
		
		// In IndieAuth 1.1, me parameter is optional. Me should actually be derived only from the logged in user not from this parameter.
		// In other implementations, there may be multiple identities permitted for a single user, but this is not currently practical on a 
		// WordPress site, so we will just ignore the optional me parameter and always return our own.
		$me = get_url_from_user( $user );


		$response_type = isset( $_POST['response_type'] ) ? wp_unslash( $_POST['response_type'] ) : null;


		/* Add UUID for reference.  
		 */
		$uuid = wp_generate_uuid4();

		/// phpcs:enable
		$token = compact( 'response_type', 'client_id', 'redirect_uri', 'scope', 'me', 'code_challenge', 'code_challenge_method', 'user', 'uuid' );
		$token = array_filter( $token );
		$code  = self::set_code( $current_user->ID, $token );
		$url   = add_query_params_to_url(
			array(
				'code'  => $code,
				'state' => $state,
				'iss'   => indieauth_get_issuer(),
			),
			$redirect_uri
		);
		wp_redirect( $url ); // phpcs:ignore
	}
}
