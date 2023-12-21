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
		add_action( 'indieauth_ticket_redeemed', array( $this, 'notify' ) );
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
							'validate_callback' => 'indieauth_validate_user_identifier',
							'sanitize_callback' => 'esc_url_raw',
							'required'          => true,
						),
						/* The Server Issue Identifie
						 */
						'iss'      => array(
							'validate_callback' => 'indieauth_validate_issuer_identifier',
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
		$params       = $request->get_params();
		$clean_params = wp_array_slice_assoc( $params, array( 'subject', 'resource', 'iss' ) );
		// Fires when a ticket is received with the parameters. Excludes ticket code itself
		do_action( 'indieauth_ticket_received', $clean_params );
		$client    = new IndieAuth_Client();
		$endpoints = false;

		if ( array_key_exists( 'subject', $params ) ) {
			$user = get_user_by_identifier( $params['subject'] );
			if ( ! $user instanceof WP_User ) {
				return new WP_OAuth_Response( 'invalid_request', __( 'Subject is not a user on this site', 'indieauth' ), 400 );
			}
		}
		if ( array_key_exists( 'iss', $params ) ) {
			$endpoints = $client->discover_endpoints( $params['iss'] );
		} elseif ( array_key_exists( 'resource', $params ) ) {
			if ( is_array( $params['resource'] ) ) {
				$endpoints = $client->discover_endpoints( $params['resource'][0] );
			} else {
				$endpoints = $client->discover_endpoints( $params['resource'] );
			}
		} else {
			return new WP_OAuth_Response( 'invalid_request', __( 'Missing Parameters', 'indieauth' ), 400 );
		}

		if ( ! $endpoints ) {
			return new WP_OAuth_Response( 'invalid_request', __( 'Unable to Find Endpoints', 'indieauth' ), 400 );
		}

		if ( is_oauth_error( $endpoints ) ) {
			return $endpoints;
		}

		$return = $this->request_token( $client->meta['token_endpoint'], $params );

		if ( is_oauth_error( $return ) ) {
			do_action( 'indieauth_ticket_redemption_failed', $clean_params, $return );
			return $return;
		}

		if ( $return ) {
			if ( ! array_key_exists( 'resource', $return ) ) {
				$return['resource'] = $params['resource'];
			}

			if ( ! array_key_exists( 'iss', $return ) && array_key_exists( 'iss', $params ) ) {
				$return['iss'] = $params['iss'];
			}

			// Add time this token was issued.
			$return['iat'] = time();

			// Store the Token Endpoint so it does not have to be discovered again.
			$return['token_endpoint'] = $client->meta['token_endpoint'];

			$save = $this->save_token( $return );
			if ( is_oauth_error( $save ) ) {
				return $save;
			}

			// Fires when Ticket is Successfully Redeemed, omits token info.
			do_action( 'indieauth_ticket_redeemed', wp_array_slice_assoc( $return, array( 'me', 'expires_in', 'iat', 'expiration', 'resource', 'iss', 'token_endpoint', 'uuid' ) ) );
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

		if ( ! indieauth_validate_user_identifier( $token['me'] ) ) {
			return new WP_OAuth_Response( 'invalid_request', __( 'Invalid Me Property', 'indieauth' ), 400, $token['me'] );
		}
		$user = get_user_by_identifier( $token['me'] );

		if ( ! $user instanceof WP_User ) {
			return new WP_OAuth_Response( 'unknown', __( 'Unable to Identify User Associated with Me Property', 'indieauth' ), 500, $token['me'] );
		}

		$tokens = new External_User_Token( $user->ID );
		$tokens->update( $token );

		return true;
	}

	public function request_token( $url, $params ) {
		$client = new IndieAuth_Client();
		return $client->remote_post(
			$url,
			array(
				'grant_type' => 'ticket',
				'ticket'     => $params['ticket'],
			)
		);
	}

	public function notify( $params ) {
		$user = get_user_by_identifier( $params['me'] );
		if ( ! $user ) {
			return;
		}
		$body = __( 'A new ticket was received and successfully redeemed', 'indieauth' ) . "\r\n";
		foreach ( $params as $key => $value ) {
			switch ( $key ) {
				case 'iat':
					$iat = new DateTime( 'now', wp_timezone() );
					$iat->setTimeStamp( $value );
					$body .= sprintf( 'Issued at: %s', $iat->format( DATE_W3C ) ) . "\r\n";
					break;
				case 'expires_in':
					break;
				default:
					$body .= sprintf( '%s: %s', $key, $value ) . "\r\n";
			}
		}
		wp_mail(
			$user->user_email,
			wp_specialchars_decode( __( 'IndieAuth Ticket Redeemed', 'indieauth' ) ),
			$body,
			''
		);
	}
}
