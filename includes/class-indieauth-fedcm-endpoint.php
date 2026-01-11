<?php
/**
 * FedCM Endpoint for IndieAuth
 *
 * Implements the Federated Credential Management (FedCM) API endpoints
 * for IndieAuth authentication.
 *
 * @package IndieAuth
 * @see https://indieweb.org/FedCM_for_IndieAuth
 */
class IndieAuth_FedCM_Endpoint {

	/**
	 * Authorization codes storage.
	 *
	 * @var Token_User
	 */
	private $codes;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->codes = new Token_User( '_indieauth_code_' );

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'indieauth_metadata', array( $this, 'metadata' ) );
		add_filter( 'rest_index_indieauth_endpoints', array( $this, 'rest_index' ) );

		// Login Status API hooks.
		add_action( 'set_logged_in_cookie', array( $this, 'set_login_status_logged_in' ) );
		add_action( 'clear_auth_cookie', array( $this, 'set_login_status_logged_out' ) );

		// FedCM IdP registration script.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue FedCM registration script.
	 *
	 * Automatically registers this site as a FedCM Identity Provider
	 * when the user visits specific admin pages.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		// Only register on dashboard or IndieAuth settings pages.
		$allowed_pages = array(
			'index.php',
			'settings_page_indieauth',
			'indieweb_page_indieauth',
		);

		if ( ! in_array( $hook_suffix, $allowed_pages, true ) ) {
			return;
		}

		$script_path = plugin_dir_path( __DIR__ ) . 'js/fedcm-register.js';
		$version     = file_exists( $script_path ) ? (string) filemtime( $script_path ) : '1.0.0';

		wp_enqueue_script(
			'indieauth-fedcm-register',
			plugins_url( 'js/fedcm-register.js', __DIR__ ),
			array(),
			$version,
			true
		);

		wp_localize_script(
			'indieauth-fedcm-register',
			'indieAuthFedCM',
			array(
				'configUrl' => self::get_config_endpoint(),
			)
		);
	}

	/**
	 * Set Login Status API header to logged-in.
	 *
	 * Sends the FedCM Login Status API header when user logs in.
	 *
	 * @see https://fedidcg.github.io/FedCM/#login-status
	 */
	public function set_login_status_logged_in() {
		if ( ! headers_sent() ) {
			header( 'Set-Login: logged-in' );
		}
	}

	/**
	 * Set Login Status API header to logged-out.
	 *
	 * Sends the FedCM Login Status API header when user logs out.
	 *
	 * @see https://fedidcg.github.io/FedCM/#login-status
	 */
	public function set_login_status_logged_out() {
		if ( ! headers_sent() ) {
			header( 'Set-Login: logged-out' );
		}
	}

	/**
	 * Get the FedCM config endpoint URL.
	 *
	 * @return string The config endpoint URL.
	 */
	public static function get_config_endpoint() {
		return rest_url( '/indieauth/1.0/fedcm/config.json' );
	}

	/**
	 * Get the accounts endpoint URL.
	 *
	 * @return string The accounts endpoint URL.
	 */
	public static function get_accounts_endpoint() {
		return rest_url( '/indieauth/1.0/fedcm/accounts' );
	}

	/**
	 * Get the client metadata endpoint URL.
	 *
	 * @return string The client metadata endpoint URL.
	 */
	public static function get_client_metadata_endpoint() {
		return rest_url( '/indieauth/1.0/fedcm/client_metadata' );
	}

	/**
	 * Get the assertion endpoint URL.
	 *
	 * @return string The assertion endpoint URL.
	 */
	public static function get_assertion_endpoint() {
		return rest_url( '/indieauth/1.0/fedcm/assertion' );
	}

	/**
	 * Get the login URL.
	 *
	 * @return string The login URL.
	 */
	public static function get_login_url() {
		return wp_login_url();
	}

	/**
	 * Add FedCM endpoints to REST index.
	 *
	 * @param array $index The REST index endpoints.
	 * @return array Modified index.
	 */
	public function rest_index( $index ) {
		$index['fedcm_config'] = self::get_config_endpoint();
		return $index;
	}

	/**
	 * Add FedCM metadata to IndieAuth metadata endpoint.
	 *
	 * @param array $metadata The metadata array.
	 * @return array Modified metadata.
	 */
	public function metadata( $metadata ) {
		$metadata['fedcm_config_url'] = self::get_config_endpoint();
		return $metadata;
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// FedCM Config endpoint.
		register_rest_route(
			'indieauth/1.0',
			'/fedcm/config.json',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'config' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// Accounts endpoint.
		register_rest_route(
			'indieauth/1.0',
			'/fedcm/accounts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'accounts' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// Client metadata endpoint.
		register_rest_route(
			'indieauth/1.0',
			'/fedcm/client_metadata',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'client_metadata' ),
					'args'                => array(
						'client_id' => array(
							'validate_callback' => 'indieauth_validate_client_identifier',
							'sanitize_callback' => 'esc_url_raw',
						),
					),
					'permission_callback' => '__return_true',
				),
			)
		);

		// ID assertion endpoint.
		register_rest_route(
			'indieauth/1.0',
			'/fedcm/assertion',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'assertion' ),
					'args'                => array(
						'client_id'  => array(
							'required'          => true,
							'validate_callback' => 'indieauth_validate_client_identifier',
							'sanitize_callback' => 'esc_url_raw',
						),
						'account_id' => array(
							'required'          => true,
							'validate_callback' => 'indieauth_validate_user_identifier',
							'sanitize_callback' => 'esc_url_raw',
						),
						'nonce'      => array(),
						'params'     => array(),
					),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Check if the request has the required FedCM header.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool True if valid FedCM request.
	 */
	private function is_valid_fedcm_request( $request ) {
		$sec_fetch_dest = $request->get_header( 'Sec-Fetch-Dest' );
		return 'webidentity' === $sec_fetch_dest;
	}

	/**
	 * Validate Origin header matches client_id scheme, hostname, and port.
	 *
	 * Per FedCM spec, the full origin (scheme + host + port) must match.
	 *
	 * @param WP_REST_Request $request   The request object.
	 * @param string          $client_id The client ID.
	 * @return bool True if valid.
	 */
	private function validate_origin( $request, $client_id ) {
		$origin = $request->get_header( 'Origin' );
		if ( ! $origin ) {
			return false;
		}

		$origin_parts    = wp_parse_url( $origin );
		$client_id_parts = wp_parse_url( $client_id );

		if ( ! is_array( $origin_parts ) || ! is_array( $client_id_parts ) ) {
			return false;
		}

		$origin_scheme    = isset( $origin_parts['scheme'] ) ? $origin_parts['scheme'] : null;
		$origin_host      = isset( $origin_parts['host'] ) ? $origin_parts['host'] : null;
		$origin_port      = isset( $origin_parts['port'] ) ? (int) $origin_parts['port'] : null;
		$client_id_scheme = isset( $client_id_parts['scheme'] ) ? $client_id_parts['scheme'] : null;
		$client_id_host   = isset( $client_id_parts['host'] ) ? $client_id_parts['host'] : null;
		$client_id_port   = isset( $client_id_parts['port'] ) ? (int) $client_id_parts['port'] : null;

		if ( null === $origin_scheme || null === $origin_host || null === $client_id_scheme || null === $client_id_host ) {
			return false;
		}

		// Scheme and host must match.
		if ( $origin_scheme !== $client_id_scheme || $origin_host !== $client_id_host ) {
			return false;
		}

		// Port must match (null means default port for scheme).
		return $origin_port === $client_id_port;
	}

	/**
	 * Add CORS headers for FedCM responses.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param WP_REST_Request  $request  The request object.
	 * @return WP_REST_Response Modified response.
	 */
	private function add_cors_headers( $response, $request ) {
		$origin = $request->get_header( 'Origin' );

		if ( $origin ) {
			$response->header( 'Access-Control-Allow-Origin', $origin );
			$response->header( 'Access-Control-Allow-Credentials', 'true' );
		}

		return $response;
	}

	/**
	 * FedCM Config endpoint handler.
	 *
	 * Returns the IdP configuration for FedCM.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response The config response.
	 */
	public function config( $request ) {
		$config = array(
			'accounts_endpoint'        => self::get_accounts_endpoint(),
			'client_metadata_endpoint' => self::get_client_metadata_endpoint(),
			'id_assertion_endpoint'    => self::get_assertion_endpoint(),
			'login_url'                => self::get_login_url(),
		);

		/**
		 * Filter the FedCM config response.
		 *
		 * @param array           $config  The config array.
		 * @param WP_REST_Request $request The request object.
		 */
		$config = apply_filters( 'indieauth_fedcm_config', $config, $request );

		$response = new WP_REST_Response( $config, 200 );

		// Config endpoint must be accessible cross-origin per FedCM spec.
		return $this->add_cors_headers( $response, $request );
	}

	/**
	 * Accounts endpoint handler.
	 *
	 * Returns the list of accounts for the current logged-in user.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The accounts response or error.
	 */
	public function accounts( $request ) {
		// Validate FedCM request header.
		if ( ! $this->is_valid_fedcm_request( $request ) ) {
			return new WP_REST_Response(
				array( 'error' => 'Missing or invalid Sec-Fetch-Dest header' ),
				400
			);
		}

		// Check if user is logged in.
		if ( ! is_user_logged_in() ) {
			return new WP_REST_Response(
				array( 'error' => 'Not logged in' ),
				401
			);
		}

		$user = wp_get_current_user();
		$me   = get_url_from_user( $user->ID );

		// Build the account object.
		$account = array(
			'id'         => $me,
			'name'       => $user->display_name,
			'email'      => $me, // Use URL as email placeholder for IndieAuth.
			'given_name' => $user->first_name ? $user->first_name : $user->display_name,
		);

		// Add picture if available.
		$avatar = get_avatar_url(
			$user->ID,
			array(
				'size'    => 256,
				'default' => '404',
			)
		);
		if ( $avatar ) {
			$account['picture'] = $avatar;
		}

		/**
		 * Filter the FedCM account data.
		 *
		 * @param array   $account The account data.
		 * @param WP_User $user    The WordPress user.
		 */
		$account = apply_filters( 'indieauth_fedcm_account', $account, $user );

		$response = new WP_REST_Response(
			array( 'accounts' => array( $account ) ),
			200,
			array()
		);

		return $this->add_cors_headers( $response, $request );
	}

	/**
	 * Client metadata endpoint handler.
	 *
	 * Returns metadata about the requesting client.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response The client metadata response.
	 */
	public function client_metadata( $request ) {
		$client_id = $request->get_param( 'client_id' );

		$metadata = array();

		// Try to discover client metadata.
		if ( $client_id ) {
			$client = IndieAuth_Client_Taxonomy::get_client( $client_id );
			if ( $client && ! is_wp_error( $client ) ) {
				if ( ! empty( $client['privacy_policy'] ) ) {
					$metadata['privacy_policy_url'] = $client['privacy_policy'];
				}
				if ( ! empty( $client['terms_of_service'] ) ) {
					$metadata['terms_of_service_url'] = $client['terms_of_service'];
				}
			}
		}

		/**
		 * Filter the FedCM client metadata.
		 *
		 * @param array  $metadata  The metadata array.
		 * @param string $client_id The client ID.
		 */
		$metadata = apply_filters( 'indieauth_fedcm_client_metadata', $metadata, $client_id );

		$response = new WP_REST_Response(
			$metadata,
			200,
			array()
		);

		return $this->add_cors_headers( $response, $request );
	}

	/**
	 * ID assertion endpoint handler.
	 *
	 * Issues an authorization code for FedCM authentication.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error The assertion response or error.
	 */
	public function assertion( $request ) {
		// Validate FedCM request header.
		if ( ! $this->is_valid_fedcm_request( $request ) ) {
			return new WP_REST_Response(
				array( 'error' => 'Missing or invalid Sec-Fetch-Dest header' ),
				400
			);
		}

		$client_id  = $request->get_param( 'client_id' );
		$account_id = $request->get_param( 'account_id' );
		$nonce      = $request->get_param( 'nonce' );
		$params     = $request->get_param( 'params' );

		// Validate origin.
		if ( ! $this->validate_origin( $request, $client_id ) ) {
			return new WP_REST_Response(
				array( 'error' => 'Origin mismatch' ),
				403
			);
		}

		// Check if user is logged in.
		if ( ! is_user_logged_in() ) {
			return new WP_REST_Response(
				array( 'error' => 'Not logged in' ),
				401
			);
		}

		$user = wp_get_current_user();
		$me   = get_url_from_user( $user->ID );

		// Verify account_id matches the current user.
		if ( normalize_url( $account_id ) !== normalize_url( $me ) ) {
			return new WP_REST_Response(
				array( 'error' => 'Account mismatch' ),
				403
			);
		}

		// Parse PKCE params if provided.
		$code_challenge        = null;
		$code_challenge_method = null;

		if ( $params ) {
			if ( is_string( $params ) ) {
				$params = json_decode( $params, true );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					return new WP_REST_Response(
						array( 'error' => 'Invalid JSON in params' ),
						400
					);
				}
			}
			if ( ! is_array( $params ) ) {
				return new WP_REST_Response(
					array( 'error' => 'Invalid params format' ),
					400
				);
			}
			$code_challenge        = isset( $params['code_challenge'] ) ? sanitize_text_field( $params['code_challenge'] ) : null;
			$code_challenge_method = isset( $params['code_challenge_method'] ) ? sanitize_text_field( $params['code_challenge_method'] ) : null;
		}

		// PKCE is required for IndieAuth.
		if ( ! $code_challenge || ! $code_challenge_method ) {
			return new WP_REST_Response(
				array( 'error' => 'PKCE parameters required' ),
				400
			);
		}

		// Validate supported PKCE method.
		if ( 'S256' !== $code_challenge_method ) {
			return new WP_REST_Response(
				array( 'error' => 'Unsupported code_challenge_method' ),
				400
			);
		}

		// Generate authorization code.
		$uuid = wp_generate_uuid4();

		// Determine scope - default to 'profile' for FedCM.
		$scope = 'profile';
		if ( is_array( $params ) && isset( $params['scope'] ) ) {
			$scope = sanitize_text_field( $params['scope'] );
		}

		/**
		 * Filter the scope used for FedCM-issued authorization codes.
		 *
		 * @param string          $scope   The scope to be stored with the authorization code.
		 * @param WP_REST_Request $request The REST request object.
		 * @param WP_User         $user    The authenticated WordPress user.
		 */
		$scope = apply_filters( 'indieauth_fedcm_scope', $scope, $request, $user );

		$token = array(
			'response_type'         => 'code',
			'client_id'             => $client_id,
			'redirect_uri'          => 'urn:ietf:wg:oauth:2.0:oob', // FedCM doesn't redirect; use OOB constant.
			'scope'                 => $scope,
			'me'                    => $me,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => $code_challenge_method,
			'user'                  => $user->ID,
			'uuid'                  => $uuid,
			'fedcm'                 => true, // Mark as FedCM-issued code.
		);

		if ( $nonce ) {
			$token['nonce'] = sanitize_text_field( $nonce );
		}

		$token = array_filter( $token );

		$this->codes->set_user( $user->ID );
		$code = $this->codes->set( $token, 600 );

		// Build the token response.
		$token_response = array(
			'code'              => $code,
			'metadata_endpoint' => IndieAuth_Metadata_Endpoint::get_endpoint(),
		);

		/**
		 * Filter the FedCM assertion token response.
		 *
		 * @param array           $token_response The token response.
		 * @param WP_REST_Request $request        The request object.
		 * @param WP_User         $user           The WordPress user.
		 */
		$token_response = apply_filters( 'indieauth_fedcm_assertion_token', $token_response, $request, $user );

		// Add client to taxonomy for tracking.
		IndieAuth_Client_Taxonomy::add_client( $client_id );

		$response = new WP_REST_Response(
			array( 'token' => wp_json_encode( $token_response ) ),
			200,
			array()
		);

		return $this->add_cors_headers( $response, $request );
	}
}
