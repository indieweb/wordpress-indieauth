<?php
/**
 * IndieAuth Ticket Controller class file.
 *
 * @package IndieAuth
 */

namespace IndieAuth\Rest;

use IndieAuth\Client;
use IndieAuth\OAuth_Response;
use IndieAuth\Ticket\External_User_Token;
use function IndieAuth\indieauth_validate_user_identifier;
use function IndieAuth\indieauth_validate_issuer_identifier;
use function IndieAuth\rest_is_valid_url;
use function IndieAuth\get_user_by_identifier;
use function IndieAuth\is_oauth_error;
use function IndieAuth\find_rels;

/**
 * IndieAuth Ticket Controller class.
 *
 * Implements the IndieAuth Ticket Controller for receiving and redeeming tickets.
 *
 * @since 1.0.0
 */
class Ticket_Controller extends \WP_REST_Controller {

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
	protected $rest_base = 'ticket';


	/**
	 * Get the ticket endpoint URL.
	 *
	 * @return string The ticket endpoint URL.
	 */
	public function get_endpoint() {
		return \rest_url( $this->namespace . '/' . $this->rest_base );
	}

	/**
	 * Add ticket endpoint to metadata.
	 *
	 * @param array $metadata Server metadata.
	 * @return array Modified metadata.
	 */
	public function metadata( $metadata ) {
		$metadata['ticket_endpoint'] = $this->get_endpoint();
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
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post' ),
					'args'                => array(
						// A random string that can be redeemed for an access token.
						'ticket'   => array(
							'required' => true,
						),
						// The access token will work at this URL.
						'resource' => array(
							'validate_callback' => 'IndieAuth\rest_is_valid_url',
							'sanitize_callback' => 'esc_url_raw',
							'required'          => true,
						),
						// The access token is used when acting on behalf of this URL.
						'subject'  => array(
							'validate_callback' => 'IndieAuth\indieauth_validate_user_identifier',
							'sanitize_callback' => 'esc_url_raw',
							'required'          => true,
						),
						// The server issuer identifier.
						'iss'      => array(
							'validate_callback' => 'IndieAuth\indieauth_validate_issuer_identifier',
							'sanitize_callback' => 'esc_url_raw',
						),
					),
					'permission_callback' => '__return_true',
				),
			)
		);
	}


	/**
	 * Handle ticket endpoint POST request.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|OAuth_Response Response object.
	 */
	public function post( $request ) {
		$params       = $request->get_params();
		$clean_params = \wp_array_slice_assoc( $params, array( 'subject', 'resource', 'iss' ) );
		// Fires when a ticket is received with the parameters. Excludes ticket code itself.
		\do_action( 'indieauth_ticket_received', $clean_params );
		$client    = new Client();
		$endpoints = false;

		if ( array_key_exists( 'subject', $params ) ) {
			$user = get_user_by_identifier( $params['subject'] );
			if ( ! $user instanceof \WP_User ) {
				return new OAuth_Response( 'invalid_request', \__( 'Subject is not a user on this site', 'indieauth' ), 400 );
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
			return new OAuth_Response( 'invalid_request', \__( 'Missing Parameters', 'indieauth' ), 400 );
		}

		if ( ! $endpoints ) {
			return new OAuth_Response( 'invalid_request', \__( 'Unable to Find Endpoints', 'indieauth' ), 400 );
		}

		if ( is_oauth_error( $endpoints ) ) {
			return $endpoints;
		}

		if ( ! \wp_http_validate_url( $client->meta['token_endpoint'] ) ) {
			return new OAuth_Response( 'invalid_request', \__( 'Invalid Token Endpoint URL', 'indieauth' ), 400 );
		}

		$return = $this->request_token( $client->meta['token_endpoint'], $params );

		if ( is_oauth_error( $return ) ) {
			\do_action( 'indieauth_ticket_redemption_failed', $clean_params, $return );
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

			// Store the token endpoint so it does not have to be discovered again.
			$return['token_endpoint'] = $client->meta['token_endpoint'];

			$save = $this->save_token( $return );
			if ( is_oauth_error( $save ) ) {
				return $save;
			}

			// Fires when ticket is successfully redeemed, omits token info.
			\do_action( 'indieauth_ticket_redeemed', \wp_array_slice_assoc( $return, array( 'me', 'expires_in', 'iat', 'expiration', 'resource', 'iss', 'token_endpoint', 'uuid' ) ) );
			return new \WP_REST_Response(
				array(
					'success' => \__( 'Your Ticket Has Been Redeemed. Thank you for your trust!', 'indieauth' ),
				),
				200
			);
		}

		// If nothing works, return an error.
		return new OAuth_Response( 'invalid_request', \__( 'Invalid Request', 'indieauth' ), 400 );
	}

	/**
	 * Save token from ticket redemption.
	 *
	 * @param array $token Token data.
	 * @return true|OAuth_Response True on success, error on failure.
	 */
	public function save_token( $token ) {
		if ( ! array_key_exists( 'me', $token ) ) {
			return new OAuth_Response( 'invalid_request', \__( 'Me Property Missing From Response', 'indieauth' ), 400 );
		}

		if ( ! indieauth_validate_user_identifier( $token['me'] ) ) {
			return new OAuth_Response( 'invalid_request', \__( 'Invalid Me Property', 'indieauth' ), 400, $token['me'] );
		}
		$user = get_user_by_identifier( $token['me'] );

		if ( ! $user instanceof \WP_User ) {
			return new OAuth_Response( 'unknown', \__( 'Unable to Identify User Associated with Me Property', 'indieauth' ), 500, $token['me'] );
		}

		$tokens = new External_User_Token( $user->ID );
		$tokens->update( $token );

		return true;
	}

	/**
	 * Request token from token endpoint.
	 *
	 * @param string $url    Token endpoint URL.
	 * @param array  $params Request parameters.
	 * @return array|OAuth_Response Token data or error.
	 */
	public function request_token( $url, $params ) {
		$client = new Client();
		return $client->remote_post(
			$url,
			array(
				'grant_type' => 'ticket',
				'ticket'     => $params['ticket'],
			)
		);
	}

	/**
	 * Notify user of successful ticket redemption.
	 *
	 * @param array $params Token parameters.
	 */
	public function notify( $params ) {
		$user = get_user_by_identifier( $params['me'] );
		if ( ! $user ) {
			return;
		}
		$body = \__( 'A new ticket was received and successfully redeemed', 'indieauth' ) . "\r\n";
		foreach ( $params as $key => $value ) {
			switch ( $key ) {
				case 'iat':
					$iat = new \DateTime( 'now', \wp_timezone() );
					$iat->setTimeStamp( $value );
					$body .= sprintf( 'Issued at: %s', $iat->format( DATE_W3C ) ) . "\r\n";
					break;
				case 'expires_in':
					break;
				default:
					$body .= sprintf( '%s: %s', $key, $value ) . "\r\n";
			}
		}
		\wp_mail(
			$user->user_email,
			\wp_specialchars_decode( \__( 'IndieAuth Ticket Redeemed', 'indieauth' ) ),
			$body,
			''
		);
	}
}
