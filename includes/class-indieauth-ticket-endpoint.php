<?php
/**
 *
 *
 * Implements IndieAuth Ticket Endpoint
 */

class IndieAuth_Ticket_Endpoint extends IndieAuth_Endpoint {
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'template_redirect', array( $this, 'http_header' ) );
		add_action( 'wp_head', array( $this, 'html_header' ) );
		add_action( 'indieauth_metadata', array( $this, 'metadata' ) );
	}

	public static function get_endpoint() {
		return rest_url( '/indieauth/1.0/ticket' );
	}

	public function metadata( $metadata ) {
		$metadata['ticket_endpoint'] = $this->get_endpoint();
		return $metadata;
	}

	public function http_header() {
		$this->set_http_header( static::get_endpoint(), 'ticket_endpoint' );
	}
	public function html_header() {
		$kses = array(
			'link' => array(
				'href' => array(),
				'rel'  => array(),
			),
		);
		echo wp_kses( $this->get_html_header( static::get_endpoint(), 'ticket_endpoint' ), $kses );
	}

	/**
	 * Register the Route.
	 */
	public function register_routes() {
		register_rest_route(
			'indieauth/1.0',
			'/ticket',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post' ),
					'args'                => array(
						/* a random string that cna be redeemed for an access token.
						 */
						'ticket'   => array(
							'required' => true,
						),
						/* the access token will work at this URL.
						 */
						'resource' => array(
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
							'required'          => true,
						),
						/* The access token is used when acting on behalf of this URL
						 */
						'subject'  => array(
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
							'required'          => true,
						),
					),
					'permission_callback' => '__return_true',
				),
			)
		);
	}


	// Request or revoke a token
	public function post( $request ) {
		$params = $request->get_params();

		if ( is_array( $params['resource'] ) ) {
			$token_endpoint = find_rels( $params['resource'][0], 'token_endpoint' );
		} else {
			$token_endpoint = find_rels( $params['resource'], 'token_endpoint' );
		}

		//If there is no token endpoint found return an error.
		if ( ! wp_http_validate_url( $token_endpoint ) ) {
			return new WP_OAuth_Response( 'invalid_request', __( 'Cannot Find Token Endpoint', 'indieauth' ), 400 );
		}

		$return = $this->request_token( $token_endpoint, $params );

		if ( is_oauth_error( $return ) ) {
			return $return;
		}

		if ( $return ) {
			if ( ! array_key_exists( 'resource', $return ) ) {
				$return['resource'] = $params['resource'];
			}

			// Add time this token was issued.
			$return['iat'] = time();

			// Store the Token Endpoint so it does not have to be discovered again.
			$return['token_endpoint'] = $token_endpoint;

			$save = $this->save_token( $return );
			if ( is_oauth_error( $save ) ) {
				return $save;
			}
			return new WP_REST_Response(
				array(
					'success' => __( 'Your Ticket Has Been Redeemed. Thank you for your trust!', 'indieauth' ),
				),
				200
			);
		}

		// If nothing works, return an error.
		return new WP_OAuth_Response( 'invalid_request', __( 'Invalid Request', 'indieauth' ), 400 );
	}

	public function save_token( $token ) {
		if ( ! array_key_exists( 'me', $token ) ) {
			return new WP_OAuth_Response( 'invalid_request', __( 'Me Property Missing From Response', 'indieauth' ), 400 );
		}

		$user = get_user_by_identifier( $token['me'] );

		if ( ! $user instanceof WP_User ) {
			return new WP_OAuth_Response( 'unknown', __( 'Unable to Identify User Associated with Me Property', 'indieauth' ), 500 );
		}

		$tokens = new External_User_Token( $user->ID );
		$tokens->update( $token );

		return true;
	}

	public function request_token( $url, $params ) {
		$resp = wp_safe_remote_post(
			$url,
			array(
				'headers' => array(
					'Accept' => 'application/json',
				),
				'body'    => array(
					'grant_type' => 'ticket',
					'ticket'     => $params['ticket'],
				),
			)
		);

		if ( is_wp_error( $resp ) ) {
			return wp_error_to_oauth_response( $resp );
		}

		$code   = wp_remote_retrieve_response_code( $resp );
		$body   = wp_remote_retrieve_body( $resp );
		$return = json_decode( $body, true );

		// check if response was json or not
		if ( ! is_array( $return ) ) {
			return new WP_OAuth_Response( 'indieauth_response_error', __( 'On Trying to Redeem a Token the Response was Invalid', 'indieauth' ), 401, $body );
		}

		if ( array_key_exists( 'error', $return ) ) {
			return new WP_OAuth_Response( 'indieauth_' . $return['error'], esc_html( $return['error_description'] ) );
		}

		if ( 2 === (int) ( $code / 100 ) ) {
			return $return;
		}

		return new WP_OAuth_Response(
			'indieauth.invalid_access_token',
			__( 'Unable to Redeem Ticket for Unknown Reasons.', 'indieauth' ),
			$code
		);
	}
}
