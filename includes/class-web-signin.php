<?php
/**
 * Web Sign In
 */
class Web_Signin {

	public function __construct() {
		add_action( 'init', array( $this, 'settings' ) );

		add_action( 'login_form', array( $this, 'login_form' ) );
		add_action( 'login_form_websignin', array( $this, 'login_form_websignin' ) );

		add_action( 'authenticate', array( $this, 'authenticate' ), 20, 2 );
	}

	public function settings() {

		register_setting(
			'indieauth',
			'indieauth_show_login_form',
			array(
				'type'         => 'boolean',
				'description'  => __( 'Offer IndieAuth on Login Form', 'indieauth' ),
				'show_in_rest' => true,
				'default'      => 0,
			)
		);
	}

	/**
	 * Redirect to Authorization Endpoint for Authentication
	 *
	 * @param string $me URL parameter
	 * @param string $redirect_uri where to redirect
	 */
	public function websignin_redirect( $me, $redirect_uri ) {
		$me = indieauth_validate_user_identifier( $me );
		if ( ! $me ) {
			return new WP_Error(
				'authentication_failed',
				__( '<strong>ERROR</strong>: Invalid URL', 'indieauth' ),
				array(
					'status' => 401,
				)
			);
		}
		$endpoints = find_rels( $me, array( 'indieauth-metadata', 'authorization_endpoint' ) );

		if ( ! $endpoints ) {
			return new WP_Error(
				'authentication_failed',
				__( '<strong>ERROR</strong>: Could not discover endpoints', 'indieauth' ),
				array(
					'status' => 401,
				)
			);
		} elseif ( array_key_exists( 'indieauth-metadata', $endpoints ) ) {
			$state = $this->get_indieauth_metadata( $endpoints['indieauth-metadata'] );
		} elseif ( ! array_key_exists( 'authorization_endpoint', $endpoints ) ) {
			return new WP_Error(
				'authentication_failed',
				__( '<strong>ERROR</strong>: Could not discover endpoints', 'indieauth' ),
				array(
					'status' => 401,
				)
			);
		} else {
			$state = array(
				'me'                     => $me,
				'authorization_endpoint' => $endpoints['authorization_endpoint'],
			);
		}
		$state['me']            = $me;
		$state['code_verifier'] = wp_generate_password( 128, false );

		$token = new Token_Transient( 'indieauth_state' );
		$query = add_query_arg(
			array(
				'response_type'         => 'code', // In earlier versions of the specification this was ID.
				'client_id'             => rawurlencode( home_url() ),
				'redirect_uri'          => rawurlencode( $redirect_uri ),
				'state'                 => $token->set_with_cookie( $state, 120 ),
				'code_challenge'        => base64_urlencode( indieauth_hash( $state['code_verifier'] ) ),
				'code_challenge_method' => 'S256',
				'me'                    => rawurlencode( $me ),
			),
			$endpoints['authorization_endpoint']
		);
		// redirect to authentication endpoint
		wp_redirect( $query );
	}

	// Retrieves the Metadata from an IndieAuth Metadata Endpoint.
	public function get_indieauth_metadata( $url ) {
		$resp = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );

		if ( ( $code / 100 ) !== 2 ) {
			return new WP_Error( 'no_metadata_endpoint', __( 'No Metadata Endpoint Found', 'indieauth' ) );
		}

		$body = wp_remote_retrieve_body( $resp );
		return json_decode( $body, true );
	}



	// $args must consist of redirect_uri, client_id, and code
	public function verify_authorization_code( $post_args, $endpoint ) {
		if ( ! wp_http_validate_url( $endpoint ) ) {
				return new WP_OAuth_Response( 'server_error', __( 'Did Not Receive a Valid Authorization Endpoint', 'indieauth' ), 500 );
		}

		$defaults = array(
			'client_id'  => home_url(),
			'grant_type' => 'authorization_code',
		);

		$post_args = wp_parse_args( $post_args, $defaults );
		$args      = array(
			'headers' => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'    => $post_args,
		);
		$response  = wp_remote_post( $endpoint, $args );
		$error     = get_oauth_error( $response );
		if ( is_oauth_error( $error ) ) {
				// Pass through well-formed error messages from the authorization endpoint
				return $error;
		}
		$code     = wp_remote_retrieve_response_code( $response );
		$response = wp_remote_retrieve_body( $response );

		$response = json_decode( $response, true );
		// check if response was json or not
		if ( ! is_array( $response ) ) {
				return new WP_OAuth_Response( 'server_error', __( 'The authorization endpoint did not return a JSON response', 'indieauth' ), 500 );
		}

		if ( 2 === (int) ( $code / 100 ) && isset( $response['me'] ) ) {
				// The authorization endpoint acknowledged that the authorization code
				// is valid and returned the authorization info
				return $response;
		}

		$error = new WP_OAuth_Response( 'server_error', __( 'There was an error verifying the authorization code, the authorization server return an expected response', 'indieauth' ), 500 );
		$error->set_debug( array( 'debug' => $response ) );
		return $error;
	}



	/**
	 * Authenticate user to WordPress using IndieAuth.
	 *
	 * @action: authenticate
	 * @param mixed $user authenticated user object, or WP_Error or null
	 * @return mixed authenticated user object, or WP_Error or null
	 */
	public function authenticate( $user, $url ) {
		if ( $user instanceof WP_User ) {
			return $user;
		}
		$redirect_to = array_key_exists( 'redirect_to', $_REQUEST ) ? $_REQUEST['redirect_to'] : '';
		$redirect_to = rawurldecode( $redirect_to );
		$token       = new Token_Transient( 'indieauth_state' );
		if ( array_key_exists( 'code', $_REQUEST ) && array_key_exists( 'state', $_REQUEST ) ) {
			$state = $token->verify( $_REQUEST['state'] );
			if ( ! $state ) {
				return new WP_Error( 'indieauth_state_error', __( 'IndieAuth Server did not return the same state parameter', 'indieauth' ) );
			}
			if ( ! isset( $state['authorization_endpoint'] ) ) {
				return new WP_Error( 'indieauth_missing_endpoint', __( 'Cannot Find IndieAuth Endpoint Cookie', 'indieauth' ) );
			}
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			if ( array_key_exists( 'iss', $_REQUEST ) ) {
				$iss = rawurldecode( $_REQUEST['iss'] );
				if ( $iss !== $state['issuer'] ) {
					return new WP_Error( 'indieauth_iss_error', __( 'Issuer Parameter does not Match Server Metadata', 'indieauth' ) );
				}
			} elseif ( array_key_exists( 'issuer', $state ) ) {
				return new WP_Error( 'indieauth_iss_error', __( 'Issuer Parameter Present in Metadata Endpoint But Not Returned by Authorization Endpoint', 'indieauth' ) );
			}

			$response = $this->verify_authorization_code(
				array(
					'code'          => $_REQUEST['code'],
					'redirect_uri'  => wp_login_url( $redirect_to ),
					'code_verifier' => $state['code_verifier'],
				),
				$state['authorization_endpoint']
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			if ( is_oauth_error( $response ) ) {
				return $response->to_wp_error();
			}
			if ( trailingslashit( $state['me'] ) !== trailingslashit( $response['me'] ) ) {
				return new WP_Error( 'indieauth_registration_failure', __( 'The domain does not match the domain you used to start the authentication.', 'indieauth' ) );
			}
			$user = get_user_by_identifier( $response['me'] );
			if ( ! $user ) {
				$user = new WP_Error( 'indieauth_registration_failure', __( 'Your have entered a valid Domain, but you have no account on this blog.', 'indieauth' ) );
			}
		}
		return $user;
	}


	/**
	 * render the login form
	 */
	public function login_form() {
		$template = plugin_dir_path( __DIR__ ) . 'templates/websignin-link.php';
		if ( 1 === (int) get_option( 'indieauth_show_login_form' ) ) {
			load_template( $template );
		}
	}

	public function login_form_websignin() {
		$login_errors = null;
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$redirect_to = array_key_exists( 'redirect_to', $_REQUEST ) ? $_REQUEST['redirect_to'] : '';
			$redirect_to = rawurldecode( $redirect_to );

			if ( array_key_exists( 'websignin_identifier', $_POST ) ) { // phpcs:ignore
				$me = esc_url_raw( $_POST['websignin_identifier'] ); //phpcs:ignore
				$return = $this->websignin_redirect( $me, wp_login_url( $redirect_to ) );
				if ( is_wp_error( $return ) ) {
					$login_errors = $return;
				}
				if ( is_oauth_error( $return ) ) {
					$login_errors = $return->to_wp_error();
				}
			}
		}

		include plugin_dir_path( __DIR__ ) . 'templates/websignin-form.php';
		exit;
	}
}

new Web_Signin();
