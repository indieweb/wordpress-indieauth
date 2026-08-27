<?php
/**
 * Introspection Controller class file.
 *
 * @package IndieAuth
 */

namespace IndieAuth\Rest;

/**
 * Introspection Controller Functionality.
 *
 * @since 1.0.0
 */
class Introspection_Controller extends \WP_REST_Controller {

	use Token_Management;

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
	protected $rest_base = 'introspection';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->init_tokens();
	}

	/**
	 * Get the introspection endpoint URL.
	 *
	 * @return string Endpoint URL.
	 */
	public function get_endpoint() {
		return \rest_url( $this->namespace . '/' . $this->rest_base );
	}

	/**
	 * Get supported authentication methods for introspection.
	 *
	 * The endpoint MUST require some form of authorization, so the default is a
	 * Bearer access token issued by this site. Filtering this to `none` restores
	 * unauthenticated introspection.
	 *
	 * @return array Supported authentication methods.
	 */
	public function auth_methods_supported() {
		return array_unique( \apply_filters( 'indieauth_introspection_auth_methods_supported', array( 'Bearer' ) ) );
	}

	/**
	 * Permission callback for the introspection endpoint.
	 *
	 * Any authentication that establishes a WordPress user is accepted: an
	 * IndieAuth Bearer token, an application password, or a logged-in session.
	 * Whether a token may then be read is decided per token, in `post()`.
	 *
	 * @return bool Whether the request is authorized.
	 */
	public function permission_callback() {
		/**
		 * Allow unauthenticated token introspection.
		 *
		 * The endpoint requires authentication by default. This is separate from
		 * `indieauth_introspection_auth_methods_supported`, which only says what
		 * the server advertises in its metadata and must not decide access.
		 *
		 * @param bool $allow Whether to allow unauthenticated introspection.
		 */
		if ( \apply_filters( 'indieauth_allow_unauthenticated_introspection', false ) ) {
			return true;
		}

		return \is_user_logged_in();
	}

	/**
	 * Whether the current user may introspect a given token.
	 *
	 * A token says who authorized it, what it can do and when it expires, so it
	 * is only readable by the user it belongs to, or by someone who can edit
	 * that user.
	 *
	 * @param array $token The token being introspected.
	 * @return bool Whether the token may be read.
	 */
	protected function can_introspect_token( $token ) {
		$owner = isset( $token['user'] ) ? (int) $token['user'] : 0;

		if ( $owner && \get_current_user_id() === $owner ) {
			return true;
		}

		$allowed = $owner ? \current_user_can( 'edit_user', $owner ) : \current_user_can( 'edit_users' );

		/**
		 * Filter whether the current user may introspect a token.
		 *
		 * @param bool  $allowed Whether introspection is allowed.
		 * @param array $token   The token being introspected.
		 */
		return \apply_filters( 'indieauth_can_introspect_token', $allowed, $token );
	}

	/**
	 * Add introspection endpoint to metadata.
	 *
	 * @param array $metadata The metadata array.
	 * @return array Modified metadata.
	 */
	public function metadata( $metadata ) {
		$metadata['introspection_endpoint']               = $this->get_endpoint();
		$metadata['introspection_auth_methods_supported'] = $this->auth_methods_supported();
		return $metadata;
	}

	/**
	 * Add introspection endpoint to REST index.
	 *
	 * @param array $index The REST index array.
	 * @return array Modified index.
	 */
	public function rest_index( $index ) {
		$index['introspection'] = $this->get_endpoint();
		return $index;
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
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'introspection' ),
					'args'                => array(
						'token'           => array(
							'required' => true,
						),
						'token_type_hint' => array(
							'default' => 'all',
						), // A hint about the type of the token submitted for revocation, options are access_token or refresh_token.
					),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
			)
		);
	}

	/**
	 * Introspection Endpoint request handler.
	 *
	 * @param \WP_REST_Request $request The Request Object.
	 * @return \WP_REST_Response Response to return to the REST Server.
	 */
	public function introspection( $request ) {
		$params = $request->get_params();
		$token  = $this->get_token( $params['token'], $params['token_type_hint'] );

		// Someone else's token reads the same as one that does not exist, so a
		// caller cannot use this endpoint to learn which token values are real.
		if ( $token && ! $this->can_introspect_token( $token ) ) {
			$token = false;
		}

		if ( $token ) {
			$token['active'] = 'true';
		} else {
			$token = array( 'active' => false );
		}

		return \rest_ensure_response( $token );
	}
}
