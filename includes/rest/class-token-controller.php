<?php
/**
 * IndieAuth Token Controller class file.
 *
 * @package IndieAuth
 */

namespace IndieAuth\Rest;

use IndieAuth\Token\User as Token_User;
use IndieAuth\OAuth_Response;
use IndieAuth\Client_Taxonomy;
use function IndieAuth\get_oauth_error;
use function IndieAuth\indieauth_get_user;
use function IndieAuth\get_user_by_identifier;
use function IndieAuth\pkce_verifier;
use function IndieAuth\indieauth_validate_client_identifier;
use function IndieAuth\rest_is_valid_url;
use function IndieAuth\same_url;

/**
 * IndieAuth Token Controller class.
 *
 * Implements the IndieAuth Token Controller for issuing and managing access tokens.
 *
 * @since 1.0.0
 */
class Token_Controller extends \WP_REST_Controller {

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
	protected $rest_base = 'token';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->init_tokens();
	}

	/**
	 * Get the token endpoint URL.
	 *
	 * @return string The token endpoint URL.
	 */
	public function get_endpoint() {
		return \rest_url( $this->namespace . '/' . $this->rest_base );
	}

	/**
	 * Add token endpoint to REST index.
	 *
	 * @param array $index REST API index.
	 * @return array Modified index.
	 */
	public function rest_index( $index ) {
		$index['token'] = $this->get_endpoint();
		return $index;
	}

	/**
	 * Get supported grant types.
	 *
	 * @return array List of supported grant types.
	 */
	public static function get_grant_types() {
		return array_unique( \apply_filters( 'indieauth_grant_types_supported', array( 'authorization_code', 'refresh_token' ) ) );
	}

	/**
	 * Add token endpoint metadata.
	 *
	 * @param array $metadata Server metadata.
	 * @return array Modified metadata.
	 */
	public function metadata( $metadata ) {
		$metadata['token_endpoint']        = $this->get_endpoint();
		$metadata['grant_types_supported'] = $this->get_grant_types();
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
						'grant_type'    => array(),
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
						// Currently only used for token revocation as action=revoke.
						'action'        => array(),
						// Paired with action for token revocation.
						'token'         => array(),
					),
					'permission_callback' => '__return_true',
				),
			)
		);
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'args'                => array(),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Token Endpoint GET Handler.
	 *
	 * @param \WP_REST_Request $request The Request Object.
	 * @return \WP_REST_Response|OAuth_Response Response to return to the REST Server.
	 */
	public function get( $request ) {
		$header = $request->get_header( 'Authorization' );
		if ( ! $header && ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = \wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		$access_token = $this->get_token_from_bearer_header( $header );
		if ( ! $access_token ) {
			return new OAuth_Response(
				'parameter_absent',
				\__(
					'Bearer Token Not Supplied or Server Misconfigured to Not Pass Token. Run diagnostic script in WordPress Admin
				IndieAuth Settings Page',
					'indieauth'
				),
				400
			);
		}
		$token = $this->get_token( $access_token );
		if ( ! $token ) {
			return new OAuth_Response( 'invalid_token', \__( 'Invalid access token', 'indieauth' ), 401 );
		}
		$token['active'] = 'true';
		return \rest_ensure_response( $token );
	}

	/**
	 * Token Endpoint POST Handler.
	 *
	 * @param \WP_REST_Request $request The Request Object.
	 * @return \WP_REST_Response|OAuth_Response|string Response to return to the REST Server.
	 */
	public function post( $request ) {
		$params = $request->get_params();

		// You cannot have both an action and a grant_type parameter.
		if ( isset( $params['action'] ) && isset( $params['grant_type'] ) ) {
			return new OAuth_Response( 'invalid_request', \__( 'Please choose either an action or a grant_type', 'indieauth' ) );
		}

		$resp = new OAuth_Response( 'invalid_request', \__( 'Invalid Request', 'indieauth' ), 400 );

		// Action Handler.
		if ( isset( $params['action'] ) ) {
			switch ( $params['action'] ) {
				// Revoke Token.
				case 'revoke':
					if ( isset( $params['token'] ) ) {
						$this->delete_token( $params['token'] );
						return \__( 'The Token Provided is No Longer Valid', 'indieauth' );
					} else {
						return new OAuth_Response( 'invalid_request', \__( 'Revoke is Missing Required Parameter token', 'indieauth' ), 400 );
					}
				default:
					$resp = new OAuth_Response( 'unsupported_action', \__( 'Unsupported Action', 'indieauth' ), 400 );
			}

			// Allows for adding custom actions.
			$resp = \apply_filters( 'indieauth_token_action_handler', $resp, $params );
		}

		// Grant Type Handler.
		if ( isset( $params['grant_type'] ) ) {
			switch ( $params['grant_type'] ) {
				// Request Token.
				case 'authorization_code':
					return $this->authorization_code( $params );
				case 'refresh_token':
					return $this->refresh_token( $params );
				default:
					$resp = new OAuth_Response( 'unsupported_grant_type', \__( 'Unsupported grant_type.', 'indieauth' ), 400 );
			}
			// Allows for adding custom grant type handling.
			$resp = \apply_filters( 'indieauth_token_grant_type_handler', $resp, $params );
		}

		// Everything Failed.
		return $resp;
	}


	/**
	 * Handle refresh token grant type.
	 *
	 * @param array $params Request parameters.
	 * @return \WP_REST_Response|OAuth_Response Token response or error.
	 */
	public function refresh_token( $params ) {

		$diff = array_diff( array( 'refresh_token' ), array_keys( $params ) );
		if ( ! empty( $diff ) ) {
			return new OAuth_Response( 'invalid_request', \__( 'The request is missing one or more required parameters', 'indieauth' ), 400 );
		}
		$refresh = $this->refresh_tokens->get( $params['refresh_token'] );
		if ( ! $refresh ) {
			return new OAuth_Response( 'invalid_grant', \__( 'Invalid Token', 'indieauth' ), 400 );
		}

		// Destroy the refresh token.
		$this->refresh_tokens->destroy( $params['refresh_token'] );

		return $this->generate_token_response( $refresh );
	}

	/**
	 * Handle authorization code grant type.
	 *
	 * @param array $params Request parameters.
	 * @return \WP_REST_Response|OAuth_Response Token response or error.
	 */
	public function authorization_code( $params ) {
		// redirect_uri is validated against the stored code, which knows whether one was used.
		$diff = array_diff( array( 'code', 'client_id' ), array_keys( $params ) );
		if ( ! empty( $diff ) ) {
			return new OAuth_Response( 'invalid_request', \__( 'The request is missing one or more required parameters', 'indieauth' ), 400 );
		}
		$args     = array_filter(
			array(
				'code'          => $params['code'],
				'redirect_uri'  => isset( $params['redirect_uri'] ) ? $params['redirect_uri'] : null,
				'client_id'     => $params['client_id'],
				'code_verifier' => isset( $params['code_verifier'] ) ? $params['code_verifier'] : null,
			)
		);
		$response = $this->verify_local_authorization_code( $args );

		$error = get_oauth_error( $response );
		if ( $error ) {
			return $error;
		}

		return $this->generate_token_response( $response );
	}

	/**
	 * Generate token response from authorization data.
	 *
	 * @param array $response Authorization response data.
	 * @return \WP_REST_Response|OAuth_Response Token response or error.
	 */
	public function generate_token_response( $response ) {
		$return = array(
			'me' => $response['me'],
		);

		if ( isset( $response['scope'] ) ) {
			$scopes = array_filter( explode( ' ', $response['scope'] ) );
			if ( ! array_key_exists( 'user', $response ) ) {
				$user             = get_user_by_identifier( $response['me'] );
				$response['user'] = $user->ID;
			}
			if ( in_array( 'profile', $scopes, true ) ) {
				$return['profile'] = indieauth_get_user( $response['user'], in_array( 'email', $scopes, true ) );
			}

			// Issue a token.
			if ( ! empty( $scopes ) ) {
				$client = Client_Taxonomy::add_client( $response['client_id'] );
				if ( \is_wp_error( $client ) ) {
					$client = array( 'id' => $client->get_error_message() );
				}

				$return['token_type'] = 'Bearer';

				if ( ! array_key_exists( 'uuid', $response ) ) {
					// Add UUID for reference. In case you'd like to build infrastructure for additional properties and store them in an alternate location.
					// As of 4.1.0, the uuid is passed from the authorization code to the access token and refresh token. But if not, it is added here.
					// This idea came from the core application password implementation.
					$return['uuid'] = \wp_generate_uuid4();
				} else {
					$return['uuid'] = $response['uuid'];
				}

				$return['scope']     = $response['scope'];
				$return['issued_by'] = \rest_url( 'indieauth/1.0/token' );
				$return['client_id'] = $response['client_id'];
				if ( array_key_exists( 'id', $client ) ) {
					$return['client_uid'] = $client['id'];
				}

				$return['iat'] = time();

				$expires = (int) \get_option( 'indieauth_expires_in' );

				$return = array_filter( $return );

				$return['access_token'] = $this->set_token( $return, $expires, $response['user'] );

				// Do not add expires_in for the return until after it is saved to the database.
				if ( 0 !== $expires ) {
					$return['expires_in']    = $expires;
					$return['refresh_token'] = $this->set_refresh_token( $return, $response['user'] );
				}
			}
		}

		if ( $return ) {
			// Return only the standard keys in the response.
			return new \WP_REST_Response(
				\wp_array_slice_assoc(
					$return,
					array(
						'access_token',
						'token_type',
						'scope',
						'me',
						'profile',
						'expires_in',
						'refresh_token',
					)
				),
				200,
				array(
					'Cache-Control' => 'no-store',
					'Pragma'        => 'no-cache',
				)
			);
		}
		return new OAuth_Response( 'server_error', \__( 'There was an error in response.', 'indieauth' ), 500 );
	}

	/**
	 * Verify local authorization code.
	 *
	 * @param array $args Arguments including code, redirect_uri, client_id, code_verifier.
	 * @return array|OAuth_Response Authorization data or error.
	 */
	public function verify_local_authorization_code( $args ) {
		$codes  = new Token_User( '_indieauth_code_' );
		$return = $codes->get( $args['code'] );
		if ( ! $return ) {
			return new OAuth_Response( 'invalid_code', \__( 'Invalid authorization code', 'indieauth' ), 401 );
		}
		if ( ! isset( $args['client_id'] ) || ! isset( $return['client_id'] ) || ! same_url( $return['client_id'], $args['client_id'] ) ) {
			$codes->destroy( $args['code'] );
			return new OAuth_Response( 'invalid_grant', \__( 'The client_id does not match the authorization request', 'indieauth' ), 400 );
		}
		// FedCM codes are issued without a redirect_uri; every other code is bound
		// to one. A code that fails the binding is destroyed either way, including
		// when the parameter is absent: answering differently would tell an
		// attacker holding a stolen code that it is still live, without spending it.
		if ( empty( $return['fedcm'] ) ) {
			if ( ! isset( $args['redirect_uri'] ) || ! isset( $return['redirect_uri'] ) || ! same_url( $return['redirect_uri'], $args['redirect_uri'] ) ) {
				$codes->destroy( $args['code'] );
				return new OAuth_Response( 'invalid_grant', \__( 'The redirect_uri does not match the authorization request', 'indieauth' ), 400 );
			}
		}
		if ( isset( $return['code_challenge'] ) ) {
			if ( ! isset( $args['code_verifier'] ) ) {
				$codes->destroy( $args['code'] );
				return new OAuth_Response( 'invalid_grant', \__( 'Failed PKCE Validation', 'indieauth' ), 400 );
			}
			if ( ! pkce_verifier( $return['code_challenge'], $args['code_verifier'], $return['code_challenge_method'] ) ) {
				$codes->destroy( $args['code'] );
				return new OAuth_Response( 'invalid_grant', \__( 'Failed PKCE Validation', 'indieauth' ), 400 );
			}
			unset( $return['code_challenge'] );
			unset( $return['code_challenge_method'] );
		}

		$codes->destroy( $args['code'] );
		return $return;
	}
}
