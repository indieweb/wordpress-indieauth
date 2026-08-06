<?php
/**
 * IndieAuth Authorization Controller class file.
 *
 * @package IndieAuth
 */

namespace IndieAuth\Rest;

use IndieAuth\Token\User as Token_User;
use IndieAuth\OAuth_Response;
use IndieAuth\Client_Taxonomy;
use function IndieAuth\indieauth_validate_client_identifier;
use function IndieAuth\indieauth_validate_user_identifier;
use function IndieAuth\rest_is_valid_url;
use function IndieAuth\indieauth_get_issuer;
use function IndieAuth\indieauth_get_user;
use function IndieAuth\pkce_verifier;
use function IndieAuth\add_query_params_to_url;
use function IndieAuth\get_url_from_user;

/**
 * IndieAuth Authorization Controller class.
 *
 * Implements the IndieAuth Authorization Controller for handling authorization requests.
 *
 * @since 1.0.0
 */
class Authorization_Controller extends \WP_REST_Controller {

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
	protected $rest_base = 'auth';

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
	 * Get the authorization endpoint URL.
	 *
	 * @return string The authorization endpoint URL.
	 */
	public function get_endpoint() {
		return \rest_url( $this->namespace . '/' . $this->rest_base );
	}

	/**
	 * Get supported response types.
	 *
	 * @return array List of supported response types.
	 */
	public static function get_response_types() {
		return array_unique( \apply_filters( 'indieauth_response_types_supported', array( 'code' ) ) );
	}

	/**
	 * Add authorization endpoint to REST index.
	 *
	 * @param array $index REST API index.
	 * @return array Modified index.
	 */
	public function rest_index( $index ) {
		$index['authorization'] = $this->get_endpoint();
		return $index;
	}

	/**
	 * Add authorization endpoint metadata.
	 *
	 * @param array $metadata Server metadata.
	 * @return array Modified metadata.
	 */
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
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'args'                => array(
						// Code is currently the only type as of IndieAuth 1.1 and a response_type is now required.
						// Indicates to the authorization server that an authorization code should be returned as the response.
						'response_type'         => array(
							'default' => 'code',
						),
						// The client URL.
						'client_id'             => array(
							'validate_callback' => 'IndieAuth\indieauth_validate_client_identifier',
							'sanitize_callback' => 'esc_url_raw',
							'required'          => true,
						),
						// The redirect URL indicating where the user should be redirected to after approving the request.
						'redirect_uri'          => array(
							'validate_callback' => 'IndieAuth\rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
							'required'          => true,
						),
						// A parameter set by the client which will be included when the user is redirected back to the client.
						// This is used to prevent CSRF attacks.
						'state'                 => array(
							'required' => true,
						),
						// Code challenge.
						'code_challenge'        => array(
							'required' => true,
						),
						// The hashing method used to calculate the code challenge, e.g. "S256".
						'code_challenge_method' => array(
							'required' => true,
						),
						// A space-separated list of scopes the client is requesting, e.g. "profile", or "profile create".
						// Optional.
						'scope'                 => array(),
						// The profile URL the user entered. Optional.
						'me'                    => array(
							'validate_callback' => 'IndieAuth\indieauth_validate_user_identifier',
							'sanitize_callback' => 'esc_url_raw',
						),
					),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post' ),
					'args'                => array(
						// grant_type=authorization_code is the only POST option supported right now.
						'grant_type'    => array(
							'default' => 'authorization_code',
						),
						// The authorization code received from the authorization endpoint in the redirect.
						'code'          => array(),
						// The client's URL, which MUST match the client_id used in the authentication request.
						'client_id'     => array(
							'validate_callback' => 'IndieAuth\indieauth_validate_client_identifier',
							'sanitize_callback' => 'esc_url_raw',
						),
						// The client's redirect URL, which MUST match the initial authentication request.
						'redirect_uri'  => array(
							'validate_callback' => 'IndieAuth\rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
						),
						// The original plaintext random string generated before starting the authorization request.
						'code_verifier' => array(),
					),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Get scope descriptions.
	 *
	 * @param string $scope Scope name or 'all' for all scopes.
	 * @return array|string Scope descriptions or single description.
	 */
	public static function scopes( $scope = 'all' ) {
		$scopes = array(
			// Micropub Scopes.
			'post'     => \__( 'Legacy Scope (Deprecated)', 'indieauth' ),
			'draft'    => \__( 'Allows the applicate to create posts in draft status only', 'indieauth' ),
			'create'   => \__( 'Allows the application to create posts and upload to the Media Endpoint', 'indieauth' ),
			'update'   => \__( 'Allows the application to update posts', 'indieauth' ),
			'delete'   => \__( 'Allows the application to delete posts', 'indieauth' ),
			'undelete' => \__( 'Allows the application to undelete posts', 'indieauth' ),
			'media'    => \__( 'Allows the application to upload to the media endpoint', 'indieauth' ),
			// Microsub Scopes.
			'read'     => \__( 'Allows the application read access to channels', 'indieauth' ),
			'follow'   => \__( 'Allows the application to manage a follow list', 'indieauth' ),
			'mute'     => \__( 'Allows the application to mute and unmute users', 'indieauth' ),
			'block'    => \__( 'Allows the application to block and unlock users', 'indieauth' ),
			'channels' => \__( 'Allows the application to manage channels', 'indieauth' ),
			'save'     => \__( 'Allows the application to save content for later retrieval', 'indieauth' ),
			// Profile.
			'profile'  => \__( 'Allows access to the users default profile information which includes name, photo, and url', 'indieauth' ),
			'email'    => \__( 'Allows access to the users email address', 'indieauth' ),
		);
		if ( 'all' === $scope ) {
			return $scopes;
		}
		$description = isset( $scopes[ $scope ] ) ? $scopes[ $scope ] : \__( 'No Description Available', 'indieauth' );
		return \apply_filters( 'indieauth_scope_description', $description, $scope );
	}

	/**
	 * Output a list of checkboxes to select scopes.
	 *
	 * @param array $scopes Scopes to output.
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
				echo \wp_kses(
					sprintf( '<li><input type="radio" name="scope[]" value="create" %1$s><strong>create</strong> - %2$s</li>', \esc_attr( 'checked' ), \esc_html( self::scopes( 'create' ) ) ),
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
				echo \wp_kses(
					sprintf( '<li><input type="radio" name="scope[]" value="draft"><strong>draft</strong> - %1$s</li>', \esc_html( self::scopes( 'draft' ) ) ),
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
				echo \wp_kses(
					sprintf( '<li><input type="radio" name="scope[]" value=""><strong>none</strong> - %1$s</li>', \__( 'Token will have no privileges to create posts', 'indieauth' ) ),
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
				echo \wp_kses(
					sprintf( '<li><input type="checkbox" name="scope[]" value="%1$s" %2$s /><strong>%1$s</strong> - %3$s</li>', $s, \checked( true, true, false ), \esc_html( self::scopes( $s ) ) ),
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

	/**
	 * Authorization Endpoint GET request handler.
	 *
	 * @param \WP_REST_Request $request The Request Object.
	 * @return \WP_REST_Response|OAuth_Response Response to return to the REST Server.
	 */
	public function get( $request ) {
		$params = $request->get_params();
		if ( ! isset( $params['response_type'] ) || 'id' === $params['response_type'] ) {
			$params['response_type'] = 'code';
		}
		if ( 'code' === $params['response_type'] ) {
			return $this->code( $params );
		}

		return new OAuth_Response( 'unsupported_response_type', \__( 'Unsupported Response Type', 'indieauth' ), 400 );
	}

	/**
	 * Handler for Response Type Code.
	 *
	 * @param array $params The parameters passed to the REST Server.
	 * @return \WP_REST_Response|OAuth_Response Response to return to the REST Server.
	 */
	public function code( $params ) {
		$required = array( 'redirect_uri', 'client_id', 'state' );
		foreach ( $required as $require ) {
			if ( ! isset( $params[ $require ] ) ) {
				// translators: Name of missing parameter.
				return new OAuth_Response( 'parameter_absent', sprintf( \__( 'Missing Parameter: %1$s', 'indieauth' ), $require ), 400 );
			}
		}
		$url  = \wp_login_url( $params['redirect_uri'], true );
		$args = array_filter(
			array(
				'action'                => 'indieauth',
				'_wpnonce'              => \wp_create_nonce( 'wp_rest' ),
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
			return new OAuth_Response( 'invalid_grant', \__( 'Invalid scope request', 'indieauth' ), 400 );
		}

		$scopes = explode( ' ', $args['scope'] );

		if ( in_array( 'email', $scopes, true ) && ! in_array( 'profile', $scopes, true ) ) {
			return new OAuth_Response( 'invalid_grant', \__( 'Cannot request email scope without profile scope', 'indieauth' ), 400 );
		}

		$url = add_query_params_to_url( $args, $url );

		return new \WP_REST_Response( array( 'url' => $url ), 302, array( 'Location' => $url ) );
	}

	/**
	 * Set an authorization code.
	 *
	 * @param int   $user_id User ID.
	 * @param array $code    Code data.
	 * @return string|false Code key or false on failure.
	 */
	public function set_code( $user_id, $code ) {
		$this->codes->set_user( $user_id );
		return $this->codes->set( $code, 600 );
	}

	/**
	 * Get an authorization code.
	 *
	 * @param string $code Authorization code.
	 * @param bool   $hash Whether to hash the code.
	 * @return array|false Code data or false.
	 */
	public function get_code( $code, $hash = true ) {
		$code = $this->codes->get( $code, $hash );
		return $code;
	}

	/**
	 * Delete an authorization code.
	 *
	 * @param string   $code    Authorization code.
	 * @param int|null $user_id User ID.
	 * @return bool Whether deletion was successful.
	 */
	public function delete_code( $code, $user_id = null ) {
		$this->codes->set_user( $user_id );
		return $this->codes->destroy( $code );
	}

	/**
	 * Authorization Endpoint POST request handler.
	 *
	 * @param \WP_REST_Request $request The Request Object.
	 * @return \WP_REST_Response|OAuth_Response|array Response to return to the REST Server.
	 */
	public function post( $request ) {
		$params = $request->get_params();

		if ( 'authorization_code' === $params['grant_type'] ) {
			return $this->authorization_code( $params );
		}

		return new OAuth_Response( 'unsupported_grant_type', \__( 'Endpoint only accepts authorization_code grant_type', 'indieauth' ), 400 );
	}

	/**
	 * Grant Type Authorization Code Request Handler.
	 *
	 * @param array $params Parameters.
	 * @return array|OAuth_Response Response to return to the REST Server.
	 */
	public function authorization_code( $params ) {
		// redirect_uri is required conditionally below; FedCM codes are issued without one.
		$required = array( 'client_id', 'code', 'grant_type' );
		foreach ( $required as $require ) {
			if ( ! isset( $params[ $require ] ) ) {
				// translators: Name of missing parameter.
				return new OAuth_Response( 'parameter_absent', sprintf( \__( 'Missing Parameter: %1$s', 'indieauth' ), $require ), 400 );
			}
		}

		$code          = $params['code'];
		$code_verifier = isset( $params['code_verifier'] ) ? $params['code_verifier'] : null;
		$token         = $this->get_code( $code );

		if ( ! $token ) {
			return new OAuth_Response( 'invalid_grant', \__( 'Invalid authorization code', 'indieauth' ), 400 );
		}

		$scopes = isset( $token['scope'] ) ? array_filter( explode( ' ', $token['scope'] ) ) : array();

		$bound_params = array( 'client_id' );
		if ( empty( $token['fedcm'] ) ) {
			if ( ! isset( $params['redirect_uri'] ) ) {
				// translators: Name of missing parameter.
				return new OAuth_Response( 'parameter_absent', sprintf( \__( 'Missing Parameter: %1$s', 'indieauth' ), 'redirect_uri' ), 400 );
			}
			$bound_params[] = 'redirect_uri';
		}
		$params = \wp_array_slice_assoc( $params, $bound_params );
		$user   = \get_user_by( 'id', $token['user'] );
		if ( $token['exp'] <= time() ) {
			$this->delete_code( $code, $token['user'] );
			return new OAuth_Response( 'invalid_grant', \__( 'The authorization code expired', 'indieauth' ), 400 );
		}
		unset( $token['exp'] );
		// If there is a code challenge.
		if ( isset( $token['code_challenge'] ) ) {
			if ( ! $code_verifier ) {
				$this->delete_code( $code, $token['user'] );
				return new OAuth_Response( 'invalid_grant', \__( 'Failed PKCE Validation', 'indieauth' ), 400 );
			}
			if ( ! pkce_verifier( $token['code_challenge'], $code_verifier, $token['code_challenge_method'] ) ) {
				$this->delete_code( $code, $token['user'] );
				return new OAuth_Response( 'invalid_grant', \__( 'Failed PKCE Validation', 'indieauth' ), 400 );
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
		return new OAuth_Response( 'invalid_grant', \__( 'There was an error verifying the authorization code. Check that the client_id and redirect_uri match the original request.', 'indieauth' ), 400 );
	}

	/**
	 * Handle IndieAuth login form action.
	 */
	public function login_form_indieauth() {
		if ( ! \is_user_logged_in() ) {
			\auth_redirect();
		}

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' === $_SERVER['REQUEST_METHOD'] ) {
			$this->authorize();
		} elseif ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$this->confirmed();
		}
		exit;
	}

	/**
	 * Display the authorization form.
	 */
	public function authorize() {
		// phpcs:disable
		$client_id     = \esc_url_raw( \wp_unslash( $_GET['client_id'] ) );
		$client_term                 = Client_Taxonomy::add_client( $client_id );
		if ( ! \is_wp_error( $client_term ) ) {
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

		$redirect_uri  = isset( $_GET['redirect_to'] ) ? \wp_unslash( $_GET['redirect_to'] ) : null;
		$scope         = isset( $_GET['scope'] ) ? \sanitize_text_field( \wp_unslash( $_GET['scope'] ) ) : null;
		$scopes        = array_filter( explode( ' ', $scope ) );
		$state         = isset( $_GET['state'] ) ? $_GET['state'] : null;
		$me            = isset( $_GET['me'] ) ? \esc_url_raw( \wp_unslash( $_GET['me'] ) ) : null;
		$response_type = isset( $_GET['response_type'] ) ? \sanitize_text_field( \wp_unslash( $_GET['response_type'] ) ) : null;
		$code_challenge = isset( $_GET['code_challenge'] ) ? \wp_unslash( $_GET['code_challenge'] ) : null;
		$code_challenge_method = isset( $_GET['code_challenge_method'] ) ? \wp_unslash( $_GET['code_challenge_method'] ) : null;

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
		$url    = add_query_params_to_url( $args, \wp_login_url() ); // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Used in included template.
		if ( empty( $scopes ) || empty( array_diff( $scopes, array( 'profile', 'email' ) ) ) ) {
			include INDIEAUTH_PLUGIN_DIR . 'templates/indieauth-authenticate-form.php';
		} else {
			include INDIEAUTH_PLUGIN_DIR . 'templates/indieauth-authorize-form.php';
		}

		include INDIEAUTH_PLUGIN_DIR . 'templates/indieauth-auth-footer.php';
	}

	/**
	 * Process confirmed authorization.
	 */
	public function confirmed() {
		// Verify nonce for CSRF protection before processing any user input.
		$nonce = isset( $_POST['_wpnonce'] ) ? \sanitize_text_field( \wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( empty( $nonce ) || ! \wp_verify_nonce( $nonce, 'indieauth_authorize' ) ) {
			\wp_die( \esc_html__( 'Security check failed. Please try again.', 'indieauth' ) );
		}

		$current_user = \wp_get_current_user();
		$user         = $current_user->ID;
		// phpcs:disable
		$client_id     = \wp_unslash( $_POST['client_id'] );
		$redirect_uri  = isset( $_POST['redirect_uri'] ) ? \wp_unslash( $_POST['redirect_uri'] ) : null;
		$scope         = isset( $_POST['scope'] ) ? $_POST['scope'] : array();
		$code_challenge  = isset( $_POST['code_challenge'] ) ? \wp_unslash( $_POST['code_challenge'] ) : null;
		$code_challenge_method  = isset( $_POST['code_challenge_method'] ) ? \wp_unslash( $_POST['code_challenge_method'] ) : null;

		// Do not allow the post scope as deprecated.
		// For compatibility, instead update the offering to the more limited but functionally identical create/update.
		$search = array_search( 'post', $scope, true );
		if ( is_numeric( $search ) ) {
			unset( $scope[ $search ] );
			$scope = array_unique( array_merge( $scope, array( 'create', 'update' ) ) );
		}

		$scope = implode( ' ', $scope );

		$state         = isset( $_POST['state'] ) ? $_POST['state'] : null;

		// In IndieAuth 1.1, me parameter is optional.
		// Me should actually be derived only from the logged in user not from this parameter.
		// In other implementations, there may be multiple identities permitted for a single user, but this is not currently practical on a
		// WordPress site, so we will just ignore the optional me parameter and always return our own.
		$me = get_url_from_user( $user );


		$response_type = isset( $_POST['response_type'] ) ? \wp_unslash( $_POST['response_type'] ) : null;


		// Add UUID for reference.
		$uuid = \wp_generate_uuid4();

		/// phpcs:enable
		$token = compact( 'response_type', 'client_id', 'redirect_uri', 'scope', 'me', 'code_challenge', 'code_challenge_method', 'user', 'uuid' );
		$token = array_filter( $token );
		$code  = $this->set_code( $current_user->ID, $token );
		$url   = add_query_params_to_url(
			array(
				'code'  => $code,
				'state' => $state,
				'iss'   => indieauth_get_issuer(),
			),
			$redirect_uri
		);
		\wp_redirect( $url ); // phpcs:ignore
	}
}
