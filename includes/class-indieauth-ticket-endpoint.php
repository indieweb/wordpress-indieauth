<?php
/**
 *
 *
 * Implements IndieAuth Ticket Endpoint
 */

class IndieAuth_Ticket_Endpoint {
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'template_redirect', array( $this, 'http_header' ) );
		add_action( 'wp_head', array( $this, 'html_header' ) );
	}

	public static function get_ticket_endpoint() {
		return rest_url( '/indieauth/1.0/ticket' );
	}

	public function http_header() {
		header( sprintf( 'Link: <%s>; rel="ticket_endpoint"', static::get_ticket_endpoint() ), false );
	}
	public static function html_header() {
		$kses = array(
			'link' => array(
				'href' => array(),
				'rel'  => array(),
			),
		);
			echo wp_kses( sprintf( '<link rel="ticket_endpoint" href="%s" />' . PHP_EOL, static::get_ticket_endpoint() ), $kses );
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
						),
						/* The access token is used when acting on behalf of this URL
						 */
						'subject'  => array(
							'validate_callback' => 'rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
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

		$token_endpoint = find_rels( $params['resource'], 'token_endpoint' );

		//If there is no token endpoint found return an error.
		if ( ! wp_http_validate_url( $token_endpoint ) ) {
			return new WP_OAuth_Response( 'invalid_request', __( 'Cannot Find Token Endpoint', 'indieauth' ), 400 );
		}

		$return = $this->request_token( $token_endpoint, $params );
		if ( $return ) {
			if ( ! array_key_exists( 'resource', $return ) ) {
				$return['resource'] = $params['resource'];
			}

			// Add time this token was issued.
			$return['issued_at'] = time();

			// Store the Token Endpoint so it does not have to be discovered again.
			$return['token_endpoint'] = $token_endpoint;

			if ( $this->save_token( $return ) ) {
				return new WP_REST_Response( array(), 200 );
			} else {
				return new WP_OAuth_Response( 'unknown', __( 'Unable to Store External Token', 'indieauth' ), 500 );
			}
		}

		// If nothing works, return an error.
		return new WP_OAuth_Response( 'invalid_request', __( 'Invalid Request', 'indieauth' ), 400 );

	}

	public function save_token( $token ) {
		if ( ! array_key_exists( 'me', $token ) ) {
			return false;
		}

		$user = get_user_by_identifier( $token['me'] );

		if ( ! $user instanceof WP_User ) {
			return false;
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
			return new WP_OAuth_Response( 'indieauth_response_error', __( 'This is not working correctly', 'indieauth' ), 401, $body );
		}

		if ( array_key_exists( 'error', $return ) ) {
			return new WP_Error( 'indieauth_' . $return['error'], esc_html( $return['error_description'] ) );
		}

		if ( 2 === (int) ( $code / 100 ) ) {
			return $return;
		}

		return new WP_OAuth_Response(
			'indieauth.invalid_access_token',
			__( 'Unable to Redeem Ticket', 'indieauth' ),
			$code
		);
	}
}

