<?php
/**
 * Web Sign In class file.
 *
 * @package IndieAuth
 */

namespace IndieAuth;

use IndieAuth\Token\Transient as Token_Transient;

/**
 * Web Sign In class.
 *
 * Handles web sign-in functionality for WordPress login.
 *
 * @since 1.0.0
 */
class Web_Signin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		\add_action( 'init', array( $this, 'settings' ) );

		\add_action( 'login_form', array( $this, 'login_form' ) );
		\add_action( 'login_form_websignin', array( $this, 'login_form_websignin' ) );

		\add_action( 'authenticate', array( $this, 'authenticate' ), 20, 2 );
	}

	/**
	 * Register settings for web sign-in.
	 */
	public function settings() {
		\register_setting(
			'indieauth',
			'indieauth_show_login_form',
			array(
				'type'         => 'boolean',
				'description'  => \__( 'Offer IndieAuth on Login Form', 'indieauth' ),
				'show_in_rest' => true,
				'default'      => 0,
			)
		);
	}

	/**
	 * Redirect to Authorization Endpoint for Authentication.
	 *
	 * @param string $me           URL parameter.
	 * @param string $redirect_uri Where to redirect.
	 * @return \WP_Error|void Error on failure, redirects on success.
	 */
	public function websignin_redirect( $me, $redirect_uri ) {
		$me = indieauth_validate_user_identifier( $me );
		if ( ! $me ) {
			return new \WP_Error(
				'authentication_failed',
				\__( '<strong>ERROR</strong>: Invalid URL', 'indieauth' ),
				array(
					'status' => 401,
				)
			);
		}
		$client    = new Client();
		$endpoints = $client->discover_endpoints( $me );
		if ( ! $endpoints ) {
			return new \WP_Error(
				'authentication_failed',
				\__( '<strong>ERROR</strong>: Could not discover endpoints', 'indieauth' ),
				array(
					'status' => 401,
				)
			);
		}

		$state                  = $client->meta;
		$state['me']            = $me;
		$state['code_verifier'] = \wp_generate_password( 128, false );

		$token = new Token_Transient( 'indieauth_state' );
		$query = \add_query_arg(
			array(
				'response_type'         => 'code', // In earlier versions of the specification this was ID.
				'client_id'             => rawurlencode( $client->client_id ),
				'redirect_uri'          => rawurlencode( $redirect_uri ),
				'state'                 => $token->set_with_cookie( $state, 120 ),
				'code_challenge'        => base64_urlencode( indieauth_hash( $state['code_verifier'] ) ),
				'code_challenge_method' => 'S256',
				'me'                    => rawurlencode( $me ),
			),
			$state['authorization_endpoint']
		);
		// Redirect to authentication endpoint.
		\wp_redirect( $query ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
	}

	/**
	 * Authenticate user to WordPress using IndieAuth.
	 *
	 * @param \WP_User|\WP_Error|null $user Authenticated user object, or WP_Error or null.
	 * @param string                  $url  URL parameter (unused but required by filter).
	 * @return \WP_User|\WP_Error|null Authenticated user object, or WP_Error or null.
	 */
	public function authenticate( $user, $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( $user instanceof \WP_User ) {
			return $user;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirect_to = array_key_exists( 'redirect_to', $_REQUEST ) ? \sanitize_text_field( \wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		$redirect_to = rawurldecode( $redirect_to );
		$token       = new Token_Transient( 'indieauth_state' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( array_key_exists( 'code', $_REQUEST ) && array_key_exists( 'state', $_REQUEST ) ) {
			$state = $token->verify( \sanitize_text_field( \wp_unslash( $_REQUEST['state'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! $state ) {
				return new \WP_Error( 'indieauth_state_error', \__( 'IndieAuth Server did not return the same state parameter', 'indieauth' ) );
			}
			if ( ! isset( $state['authorization_endpoint'] ) ) {
				return new \WP_Error( 'indieauth_missing_endpoint', \__( 'Cannot Find IndieAuth Endpoint Cookie', 'indieauth' ) );
			}
			if ( \is_wp_error( $state ) ) {
				return $state;
			}
			if ( array_key_exists( 'iss', $_REQUEST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$iss = rawurldecode( \sanitize_text_field( \wp_unslash( $_REQUEST['iss'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( ! indieauth_validate_issuer_identifier( $iss ) ) {
					return new \WP_Error( 'indieauth_iss_error', \__( 'Issuer Parameter is Not Valid', 'indieauth' ) );
				}
				if ( $iss !== $state['issuer'] ) {
					return new \WP_Error( 'indieauth_iss_error', \__( 'Issuer Parameter does not Match Server Metadata', 'indieauth' ) );
				}
			} elseif ( array_key_exists( 'issuer', $state ) ) {
				return new \WP_Error( 'indieauth_iss_error', \__( 'Issuer Parameter Present in Metadata Endpoint But Not Returned by Authorization Endpoint', 'indieauth' ) );
			}

			$client       = new Client();
			$client->meta = $state;
			$response     = $client->redeem_authorization_code(
				array(
					'code'          => \sanitize_text_field( \wp_unslash( $_REQUEST['code'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					'redirect_uri'  => \wp_login_url( $redirect_to ),
					'code_verifier' => $state['code_verifier'],
				),
				false // Redeem at Authorization Endpoint.
			);

			if ( \is_wp_error( $response ) ) {
				return $response;
			}
			if ( is_oauth_error( $response ) ) {
				return $response->to_wp_error();
			}
			if ( \trailingslashit( $state['me'] ) !== \trailingslashit( $response['me'] ) ) {
				return new \WP_Error( 'indieauth_registration_failure', \__( 'The domain does not match the domain you used to start the authentication.', 'indieauth' ) );
			}
			$user = get_user_by_identifier( $response['me'] );
			if ( ! $user ) {
				$user = new \WP_Error( 'indieauth_registration_failure', \__( 'Your have entered a valid Domain, but you have no account on this blog.', 'indieauth' ) );
			}
		}
		return $user;
	}


	/**
	 * Render the login form.
	 */
	public function login_form() {
		$template = \plugin_dir_path( __DIR__ ) . 'templates/websignin-link.php';
		if ( 1 === (int) \get_option( 'indieauth_show_login_form' ) ) {
			\load_template( $template );
		}
	}

	/**
	 * Handle web sign-in form submission.
	 */
	public function login_form_websignin() {
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$redirect_to = array_key_exists( 'redirect_to', $_REQUEST ) ? \sanitize_text_field( \wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
			$redirect_to = rawurldecode( $redirect_to );

			if ( array_key_exists( 'websignin_identifier', $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$me = \esc_url_raw( \wp_unslash( $_POST['websignin_identifier'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$this->websignin_redirect( $me, \wp_login_url( $redirect_to ) );
			}
		}

		include \plugin_dir_path( __DIR__ ) . 'templates/websignin-form.php';
		exit;
	}
}
