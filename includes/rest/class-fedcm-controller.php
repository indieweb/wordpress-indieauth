<?php
/**
 * FedCM Controller for IndieAuth file.
 *
 * @package IndieAuth
 */

namespace IndieAuth\Rest;

use IndieAuth\Token\User as Token_User;
use IndieAuth\Client_Taxonomy;
use function IndieAuth\indieauth_validate_client_identifier;
use function IndieAuth\indieauth_validate_user_identifier;
use function IndieAuth\get_url_from_user;
use function IndieAuth\normalize_url;

/**
 * Implements the Federated Credential Management (FedCM) API endpoints
 * for IndieAuth authentication.
 *
 * @see https://indieweb.org/FedCM_for_IndieAuth
 */
class FedCM_Controller extends \WP_REST_Controller {

	/**
	 * The namespace for the REST route.
	 *
	 * @var string
	 */
	protected $namespace = 'indieauth/1.0';

	/**
	 * The base of the REST route.
	 *
	 * @var string
	 */
	protected $rest_base = 'fedcm';

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

		$script_path = INDIEAUTH_PLUGIN_DIR . 'js/fedcm-register.js';
		$version     = file_exists( $script_path ) ? (string) filemtime( $script_path ) : '1.0.0';

		\wp_enqueue_script(
			'indieauth-fedcm-register',
			\plugins_url( 'js/fedcm-register.js', INDIEAUTH_PLUGIN_FILE ),
			array(),
			$version,
			true
		);

		\wp_localize_script(
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
		return \rest_url( '/indieauth/1.0/fedcm/config.json' );
	}

	/**
	 * Get the accounts endpoint URL.
	 *
	 * @return string The accounts endpoint URL.
	 */
	public static function get_accounts_endpoint() {
		return \rest_url( '/indieauth/1.0/fedcm/accounts' );
	}

	/**
	 * Get the client metadata endpoint URL.
	 *
	 * @return string The client metadata endpoint URL.
	 */
	public static function get_client_metadata_endpoint() {
		return \rest_url( '/indieauth/1.0/fedcm/client_metadata' );
	}

	/**
	 * Get the assertion endpoint URL.
	 *
	 * @return string The assertion endpoint URL.
	 */
	public static function get_assertion_endpoint() {
		return \rest_url( '/indieauth/1.0/fedcm/assertion' );
	}

	/**
	 * Get the login URL.
	 *
	 * @return string The login URL.
	 */
	public static function get_login_url() {
		return \wp_login_url();
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
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/config.json',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'config' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// Accounts endpoint.
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/accounts',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'accounts' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		// Client metadata endpoint.
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/client_metadata',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'client_metadata' ),
					'args'                => array(
						'client_id' => array(
							'validate_callback' => 'IndieAuth\indieauth_validate_client_identifier',
							'sanitize_callback' => 'esc_url_raw',
						),
					),
					'permission_callback' => '__return_true',
				),
			)
		);

		// ID assertion endpoint.
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/assertion',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'assertion' ),
					'args'                => array(
						'client_id'  => array(
							'required'          => true,
							'validate_callback' => 'IndieAuth\indieauth_validate_client_identifier',
							'sanitize_callback' => 'esc_url_raw',
						),
						'account_id' => array(
							'required'          => true,
							'validate_callback' => 'IndieAuth\indieauth_validate_user_identifier',
							'sanitize_callback' => 'esc_url_raw',
						),
						'nonce'      => array(
							'sanitize_callback' => 'sanitize_text_field',
						),
						'params'     => array(
							'required'          => true, // PKCE params are required for IndieAuth.
							'validate_callback' => array( $this, 'validate_params' ),
						),
					),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Validate the params argument contains required PKCE fields.
	 *
	 * @param mixed $value The params value.
	 * @return bool|\WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_params( $value ) {
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new \WP_Error( 'invalid_params', \__( 'Invalid JSON in params', 'indieauth' ) );
			}
		}

		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'invalid_params', \__( 'Invalid params format', 'indieauth' ) );
		}

		if ( empty( $value['code_challenge'] ) || empty( $value['code_challenge_method'] ) ) {
			return new \WP_Error( 'invalid_params', \__( 'PKCE parameters required', 'indieauth' ) );
		}

		if ( 'S256' !== $value['code_challenge_method'] ) {
			return new \WP_Error( 'invalid_params', \__( 'Unsupported code_challenge_method', 'indieauth' ) );
		}

		return true;
	}

	/**
	 * Check if the request has the required FedCM header.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return bool True if valid FedCM request.
	 */
	private function is_valid_fedcm_request( $request ) {
		$sec_fetch_dest = $request->get_header( 'Sec-Fetch-Dest' );
		return 'webidentity' === $sec_fetch_dest;
	}

	/**
	 * Exempt FedCM requests from the REST cookie nonce check.
	 *
	 * The browser's FedCM machinery sends the auth cookie but cannot include a
	 * REST nonce, so `rest_cookie_check_errors()` would treat the request as
	 * unauthenticated. `Sec-` prefixed headers are forbidden for cross-site
	 * JavaScript, so requiring `Sec-Fetch-Dest: webidentity` rules out CSRF.
	 *
	 * The exemption is deliberately narrow: it only applies to a request that
	 * is actually cookie-authenticated and that the REST server dispatched to
	 * one of this plugin's FedCM routes. Everything else falls through to the
	 * normal REST authentication pipeline.
	 *
	 * Core's nonce check is removed rather than answering `true`, because `true`
	 * would end the whole filter chain and silently skip any other plugin's REST
	 * authentication policy, not just the nonce check.
	 *
	 * @param \WP_Error|null|true $result Current authentication result.
	 * @return \WP_Error|null|true The result, unchanged.
	 */
	public function rest_authentication_errors( $result ) {
		global $wp_rest_auth_cookie;

		if ( null !== $result ) {
			return $result;
		}

		if ( ! isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) || 'webidentity' !== \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ) ) {
			return $result;
		}

		// Match the route the REST server dispatched, not the raw request URI,
		// so an unrelated route cannot carry the FedCM path in its query string.
		if ( ! $this->is_fedcm_route() ) {
			return $result;
		}

		// There is only a nonce-less user to preserve when the request really is
		// cookie-authenticated. `is_user_logged_in()` resolves the current user,
		// which is what populates `$wp_rest_auth_cookie`.
		if ( ! \is_user_logged_in() || true !== $wp_rest_auth_cookie ) {
			return $result;
		}

		// Only core's nonce check gets skipped. Everything else on this filter
		// still runs, and the request is left unauthenticated so far as this
		// callback is concerned.
		\remove_filter( 'rest_authentication_errors', 'rest_cookie_check_errors', 100 );

		return $result;
	}

	/**
	 * Check whether the REST server is serving one of this plugin's FedCM routes.
	 *
	 * @return bool True if the dispatched route belongs to the FedCM namespace.
	 */
	private function is_fedcm_route() {
		if ( empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			return false;
		}

		$route  = '/' . ltrim( $GLOBALS['wp']->query_vars['rest_route'], '/' );
		$prefix = '/' . $this->namespace . '/' . $this->rest_base . '/';

		return 0 === strpos( $route, $prefix );
	}

	/**
	 * Validate Origin header matches client_id scheme, hostname, and port.
	 *
	 * Per FedCM spec, the full origin (scheme + host + port) must match.
	 *
	 * @param \WP_REST_Request $request   The request object.
	 * @param string           $client_id The client ID.
	 * @return bool True if valid.
	 */
	private function validate_origin( $request, $client_id ) {
		$origin = $request->get_header( 'Origin' );
		if ( ! $origin ) {
			return false;
		}

		$origin_parts    = \wp_parse_url( $origin );
		$client_id_parts = \wp_parse_url( $client_id );

		if ( ! is_array( $origin_parts ) || ! is_array( $client_id_parts ) ) {
			return false;
		}

		// Schemes and hosts are case-insensitive.
		$origin_scheme    = isset( $origin_parts['scheme'] ) ? strtolower( $origin_parts['scheme'] ) : null;
		$origin_host      = isset( $origin_parts['host'] ) ? strtolower( $origin_parts['host'] ) : null;
		$client_id_scheme = isset( $client_id_parts['scheme'] ) ? strtolower( $client_id_parts['scheme'] ) : null;
		$client_id_host   = isset( $client_id_parts['host'] ) ? strtolower( $client_id_parts['host'] ) : null;

		if ( null === $origin_scheme || null === $origin_host || null === $client_id_scheme || null === $client_id_host ) {
			return false;
		}

		// Scheme and host must match.
		if ( $origin_scheme !== $client_id_scheme || $origin_host !== $client_id_host ) {
			return false;
		}

		// Port must match; a missing port means the default port for the scheme.
		$defaults       = array(
			'http'  => 80,
			'https' => 443,
		);
		$default_port   = isset( $defaults[ $origin_scheme ] ) ? $defaults[ $origin_scheme ] : null;
		$origin_port    = isset( $origin_parts['port'] ) ? (int) $origin_parts['port'] : $default_port;
		$client_id_port = isset( $client_id_parts['port'] ) ? (int) $client_id_parts['port'] : $default_port;

		return $origin_port === $client_id_port;
	}

	/**
	 * Let the FedCM routes send their own CORS headers.
	 *
	 * Core's `rest_send_cors_headers()` runs on `rest_pre_serve_request`, which
	 * fires after the response's own headers have gone out, and it echoes the
	 * request Origin with `Access-Control-Allow-Credentials: true`. That would
	 * overwrite whatever these endpoints set, so it is taken out of the way for
	 * FedCM routes only. Removing it here affects just the request being served.
	 *
	 * @param mixed            $result  Response to replace the requested version with.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request Request used to generate the response.
	 * @return mixed The result, unchanged.
	 */
	public function rest_pre_dispatch( $result, $server, $request ) {
		$prefix = '/' . $this->namespace . '/' . $this->rest_base . '/';

		if ( 0 === strpos( $request->get_route(), $prefix ) ) {
			\remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		}

		return $result;
	}

	/**
	 * Add public CORS headers for endpoints that serve no user data.
	 *
	 * @param \WP_REST_Response $response The response object.
	 * @return \WP_REST_Response Modified response.
	 */
	private function add_public_cors_headers( $response ) {
		$response->header( 'Access-Control-Allow-Origin', '*' );

		return $response;
	}

	/**
	 * Add credentialed CORS headers for FedCM responses.
	 *
	 * @param \WP_REST_Response $response The response object.
	 * @param \WP_REST_Request  $request  The request object.
	 * @return \WP_REST_Response Modified response.
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
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response The config response.
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
		 * @param array            $config  The config array.
		 * @param \WP_REST_Request $request The request object.
		 */
		$config = \apply_filters( 'indieauth_fedcm_config', $config, $request );

		$response = new \WP_REST_Response( $config, 200 );

		// Config endpoint must be accessible cross-origin per FedCM spec.
		return $this->add_public_cors_headers( $response );
	}

	/**
	 * Accounts endpoint handler.
	 *
	 * Returns the list of accounts for the current logged-in user.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error The accounts response or error.
	 */
	public function accounts( $request ) {
		// Validate FedCM request header.
		if ( ! $this->is_valid_fedcm_request( $request ) ) {
			return new \WP_REST_Response(
				array( 'error' => 'Missing or invalid Sec-Fetch-Dest header' ),
				400
			);
		}

		// Check if user is logged in.
		if ( ! \is_user_logged_in() ) {
			return new \WP_REST_Response(
				array( 'error' => 'Not logged in' ),
				401
			);
		}

		$user = \wp_get_current_user();
		$me   = get_url_from_user( $user->ID );

		// Build the account object.
		$account = array(
			'id'         => $me,
			'name'       => $user->display_name,
			'email'      => $user->user_email,
			'given_name' => $user->first_name ? $user->first_name : $user->display_name,
		);

		// Add picture if available.
		$avatar = \get_avatar_url(
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
		 * @param array    $account The account data.
		 * @param \WP_User $user    The WordPress user.
		 */
		$account = \apply_filters( 'indieauth_fedcm_account', $account, $user );

		$response = new \WP_REST_Response(
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
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response The client metadata response.
	 */
	public function client_metadata( $request ) {
		$client_id = $request->get_param( 'client_id' );

		$metadata = array();

		// Try to discover client metadata.
		if ( $client_id ) {
			$client = Client_Taxonomy::get_client( $client_id );
			if ( $client && ! \is_wp_error( $client ) ) {
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
		$metadata = \apply_filters( 'indieauth_fedcm_client_metadata', $metadata, $client_id );

		$response = new \WP_REST_Response(
			$metadata,
			200,
			array()
		);

		return $this->add_public_cors_headers( $response );
	}

	/**
	 * Build an ID assertion error response per the FedCM Error API.
	 *
	 * @see https://fedidcg.github.io/FedCM/#idp-api-error-response
	 *
	 * @param string           $code    One of the error codes defined by the FedCM specification.
	 * @param int              $status  HTTP status code.
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response The error response.
	 */
	private function assertion_error( $code, $status, $request ) {
		$response = new \WP_REST_Response(
			array(
				'error' => array(
					'code' => $code,
				),
			),
			$status
		);

		// The browser drops the body without these, so the error never surfaces.
		return $this->add_cors_headers( $response, $request );
	}

	/**
	 * ID assertion endpoint handler.
	 *
	 * Issues an authorization code for FedCM authentication.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error The assertion response or error.
	 */
	public function assertion( $request ) {
		// Validate FedCM request header.
		if ( ! $this->is_valid_fedcm_request( $request ) ) {
			return $this->assertion_error( 'invalid_request', 400, $request );
		}

		$client_id  = $request->get_param( 'client_id' );
		$account_id = $request->get_param( 'account_id' );
		$nonce      = $request->get_param( 'nonce' );
		$params     = $request->get_param( 'params' );

		// Validate origin.
		if ( ! $this->validate_origin( $request, $client_id ) ) {
			return $this->assertion_error( 'unauthorized_client', 403, $request );
		}

		// Check if user is logged in.
		if ( ! \is_user_logged_in() ) {
			return $this->assertion_error( 'access_denied', 401, $request );
		}

		$user = \wp_get_current_user();
		$me   = get_url_from_user( $user->ID );

		// Verify account_id matches the current user.
		if ( normalize_url( $account_id ) !== normalize_url( $me ) ) {
			return $this->assertion_error( 'access_denied', 403, $request );
		}

		// Parse PKCE params (already validated by validate_params callback).
		if ( is_string( $params ) ) {
			$params = json_decode( $params, true );
		}

		$code_challenge        = \sanitize_text_field( $params['code_challenge'] );
		$code_challenge_method = \sanitize_text_field( $params['code_challenge_method'] );

		// Generate authorization code.
		$uuid = \wp_generate_uuid4();

		// Determine scope - default to 'profile' when the client does not ask.
		$scope = 'profile';
		if ( is_array( $params ) && isset( $params['scope'] ) ) {
			// The FedCM browser UI never displays scopes, so only identity
			// scopes may be granted without a real consent screen.
			$requested = array_filter( explode( ' ', \sanitize_text_field( $params['scope'] ) ) );
			// array_intersect() keeps every occurrence, so a repeated scope has to be dropped.
			$granted = array_values( array_unique( array_intersect( $requested, array( 'profile', 'email' ) ) ) );

			// Whatever the client asked for, it only gets what survived the clamp.
			// An empty result means no access token at all, rather than a
			// profile token the client never requested.
			$scope = implode( ' ', $granted );
		}

		/**
		 * Filter the scope used for FedCM-issued authorization codes.
		 *
		 * Scopes requested by the client are clamped to 'profile' and 'email'
		 * before this filter runs, because the FedCM flow has no consent
		 * screen where a user could review other scopes.
		 *
		 * @param string           $scope   The scope to be stored with the authorization code.
		 * @param \WP_REST_Request $request The REST request object.
		 * @param \WP_User         $user    The authenticated WordPress user.
		 */
		$scope = \apply_filters( 'indieauth_fedcm_scope', $scope, $request, $user );

		$token = array(
			'response_type'         => 'code',
			'client_id'             => $client_id,
			'scope'                 => $scope,
			'me'                    => $me,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => $code_challenge_method,
			'user'                  => $user->ID,
			'uuid'                  => $uuid,
			'fedcm'                 => true, // Mark as FedCM-issued code; these are not bound to a redirect_uri.
		);

		if ( $nonce ) {
			$token['nonce'] = \sanitize_text_field( $nonce );
		}

		$token = array_filter( $token );

		$this->codes->set_user( $user->ID );
		$code = $this->codes->set( $token, 600 );

		// Build the token response.
		$token_response = array(
			'code'              => $code,
			'metadata_endpoint' => \rest_url( 'indieauth/1.0/metadata' ),
		);

		/**
		 * Filter the FedCM assertion token response.
		 *
		 * @param array            $token_response The token response.
		 * @param \WP_REST_Request $request        The request object.
		 * @param \WP_User         $user           The WordPress user.
		 */
		$token_response = \apply_filters( 'indieauth_fedcm_assertion_token', $token_response, $request, $user );

		// Add client to taxonomy for tracking.
		Client_Taxonomy::add_client( $client_id );

		$response = new \WP_REST_Response(
			array( 'token' => \wp_json_encode( $token_response ) ),
			200,
			array()
		);

		return $this->add_cors_headers( $response, $request );
	}
}
