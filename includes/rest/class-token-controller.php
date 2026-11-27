<?php
/**
 * IndieAuth Token Controller class file.
 *
 * @package IndieAuth
 */

namespace IndieAuth\Rest;

use IndieAuth\Token\User as Token_User;
use IndieAuth\OAuth_Response;
use IndieAuth\Clients;
use function IndieAuth\get_oauth_error;
use function IndieAuth\indieauth_get_user;
use function IndieAuth\get_user_by_identifier;
use function IndieAuth\pkce_verifier;
use function IndieAuth\indieauth_validate_client_identifier;
use function IndieAuth\rest_is_valid_url;
use function IndieAuth\deprecation_notice;
use function IndieAuth\add_deprecation_headers;
use function IndieAuth\same_url;
use function IndieAuth\code_binding_failure;
use function IndieAuth\add_token_properties;
use function IndieAuth\get_extra_token_properties;
use function IndieAuth\get_visible_token_properties;

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
		return array_values( array_unique( \apply_filters( 'indieauth_grant_types_supported', array( 'authorization_code', 'refresh_token' ) ) ) );
	}

	/**
	 * Add token endpoint metadata.
	 *
	 * @param array $metadata Server metadata.
	 * @return array Modified metadata.
	 */
	public function metadata( $metadata ) {
		// All clients are public clients, so there is no client authentication.
		$metadata['token_endpoint']                        = $this->get_endpoint();
		$metadata['token_endpoint_auth_methods_supported'] = array( 'none' );
		$metadata['grant_types_supported']                 = $this->get_grant_types();
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
							'validate_callback' => 'IndieAuth\rest_is_valid_redirect_uri',
							'sanitize_callback' => 'IndieAuth\sanitize_redirect_uri',
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
			return add_deprecation_headers(
				new OAuth_Response(
					'parameter_absent',
					\__(
						'Bearer Token Not Supplied or Server Misconfigured to Not Pass Token. Run diagnostic script in WordPress Admin
				IndieAuth Settings Page',
						'indieauth'
					),
					400
				)
			);
		}
		$token = $this->get_token( $access_token, true, 'access_token' );
		if ( ! $token ) {
			return add_deprecation_headers( new OAuth_Response( 'invalid_token', \__( 'Invalid access token', 'indieauth' ), 401 ) );
		}
		// Only once the caller proved it holds a valid token, so an anonymous
		// requester cannot fill the log by looping on this endpoint.
		deprecation_notice(
			'IndieAuth\Rest\Token_Controller::get',
			\__( 'Token verification via a GET request to the token endpoint was removed from the IndieAuth specification. Use the introspection endpoint instead.', 'indieauth' ),
			'indieauth 4.8.0'
		);
		$token['active'] = true;
		return add_deprecation_headers( \rest_ensure_response( $token ) );
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

		// The defaults below may be replaced by a filter or never be sent, so they are only reported when returned.
		$resp = new OAuth_Response( 'invalid_request', \__( 'Invalid Request', 'indieauth' ), 400, null, false );

		// Action Handler.
		if ( isset( $params['action'] ) ) {
			switch ( $params['action'] ) {
				// Revoke Token.
				case 'revoke':
					if ( isset( $params['token'] ) ) {
						// Only for a token that existed, so an anonymous requester
						// cannot fill the log by looping on this endpoint.
						if ( $this->delete_token( $params['token'] ) ) {
							deprecation_notice(
								'IndieAuth\Rest\Token_Controller::revoke_action',
								\__( 'Token revocation via action=revoke on the token endpoint was removed from the IndieAuth specification. Use the revocation endpoint instead.', 'indieauth' ),
								'indieauth 4.8.0'
							);
						}
						return add_deprecation_headers( \rest_ensure_response( \__( 'The Token Provided is No Longer Valid', 'indieauth' ) ) );
					} else {
						return add_deprecation_headers( new OAuth_Response( 'invalid_request', \__( 'Revoke is Missing Required Parameter token', 'indieauth' ), 400 ) );
					}
				default:
					$resp = new OAuth_Response( 'unsupported_action', \__( 'Unsupported Action', 'indieauth' ), 400, null, false );
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
					$resp = new OAuth_Response( 'unsupported_grant_type', \__( 'Unsupported grant_type.', 'indieauth' ), 400, null, false );
			}
			// Allows for adding custom grant type handling.
			$resp = \apply_filters( 'indieauth_token_grant_type_handler', $resp, $params );
		}

		// Everything Failed.
		if ( $resp instanceof OAuth_Response ) {
			$resp->log();
		}
		return $resp;
	}


	/**
	 * Handle refresh token grant type.
	 *
	 * The refresh token is bound to the client it was issued to, so the request
	 * must include that client_id. IndieAuth section 5.5.1.
	 *
	 * A request without client_id is rejected before the token is looked up, so
	 * the response does not show whether the token is valid, and the token is
	 * kept. A token presented by a different client is destroyed, the same as an
	 * authorization code that fails its binding.
	 *
	 * @param array $params Request parameters.
	 * @return \WP_REST_Response|OAuth_Response Token response or error.
	 */
	public function refresh_token( $params ) {

		$diff = array_diff( array( 'refresh_token', 'client_id' ), array_keys( $params ) );
		if ( ! empty( $diff ) ) {
			return new OAuth_Response( 'invalid_request', \__( 'The request is missing one or more required parameters', 'indieauth' ), 400 );
		}
		$refresh = $this->refresh_tokens->get( $params['refresh_token'] );
		if ( ! $refresh ) {
			return new OAuth_Response( 'invalid_grant', \__( 'Invalid Token', 'indieauth' ), 400 );
		}

		// Destroy the refresh token. It is single use, and a token presented by
		// another client may have been stolen, so it is destroyed on a mismatch too.
		$this->refresh_tokens->destroy( $params['refresh_token'] );

		// Compared with same_url(), so a trailing slash does not cost the client its token.
		if ( ! isset( $refresh['client_id'] ) || ! same_url( $refresh['client_id'], $params['client_id'] ) ) {
			return new OAuth_Response( 'invalid_grant', \__( 'The client_id does not match the one the refresh token was issued to', 'indieauth' ), 400 );
		}

		return $this->generate_token_response( $refresh, 'refresh_token' );
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
	 * @param array  $response   Authorization response data.
	 * @param string $grant_type The grant the token is issued for, `authorization_code` or `refresh_token`.
	 * @return \WP_REST_Response|OAuth_Response Token response or error.
	 */
	public function generate_token_response( $response, $grant_type = 'authorization_code' ) {
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
				$client = Clients::add_client( $response['client_id'] );
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

				// Carry the user's login time through unchanged. Unlike iat, it is not reset on refresh.
				if ( isset( $response['auth_time'] ) ) {
					$return['auth_time'] = (int) $response['auth_time'];
				}

				$expires = (int) \get_option( 'indieauth_expires_in' );

				$return = array_filter( $return );

				// Extension properties of the code, or of the refresh token, carry over unchanged.
				$return = add_token_properties( $return, get_extra_token_properties( $response ) );

				/**
				 * Filters the extra properties added to an access token when it is issued.
				 *
				 * Only adds: the extra properties carried over from the authorization
				 * code or refresh token cannot be changed or removed here, and neither
				 * can the properties the plugin uses itself, see reserved_token_properties().
				 * Added properties go with any refresh token, like the carried ones.
				 *
				 * @param array  $properties Extra properties to add, keyed by name. Default empty.
				 * @param array  $token      The token data so far, including the carried properties.
				 * @param array  $response   The authorization code or refresh token data the token is issued from.
				 * @param string $grant_type The grant the token is issued for, `authorization_code` or `refresh_token`.
				 * @param int    $user_id    The user the token belongs to.
				 */
				$extra  = \apply_filters( 'indieauth_access_token_properties', array(), $return, $response, $grant_type, (int) $response['user'] );
				$return = add_token_properties( $return, $extra );

				$return['access_token'] = $this->set_token( $return, $expires, $response['user'] );

				// Do not add expires_in for the return until after it is saved to the database.
				if ( 0 !== $expires ) {
					$return['expires_in']    = $expires;
					$return['refresh_token'] = $this->set_refresh_token( $return, $response['user'] );
				}

				if ( $return['access_token'] ) {
					// The hooks get what is stored with the token, not the token values.
					$token = array_diff_key( $return, array_flip( array( 'access_token', 'refresh_token', 'profile' ) ) );

					if ( 'refresh_token' === $grant_type ) {
						/**
						 * Fires when a refresh token was used to issue a new access token.
						 *
						 * The `uuid` is the same as on the token that was refreshed.
						 *
						 * @param array $token   The token data, without the token values.
						 * @param int   $user_id The user the token belongs to.
						 */
						\do_action( 'indieauth_token_refreshed', $token, (int) $response['user'] );
					} else {
						/**
						 * Fires when an authorization code was exchanged for an access token.
						 *
						 * The `uuid` is the same as on the authorization code.
						 *
						 * @param array $token   The token data, without the token values.
						 * @param int   $user_id The user the token belongs to.
						 */
						\do_action( 'indieauth_token_issued', $token, (int) $response['user'] );
					}
				}
			}
		}

		if ( $return ) {
			// Of the plugin's own properties, return only the standard keys.
			$body = \wp_array_slice_assoc(
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
			);
			// Extension properties are returned unless an extension hides them.
			$body += get_visible_token_properties( array_diff_key( $return, array_flip( array( 'access_token', 'refresh_token' ) ) ), 'token_response' );

			return new \WP_REST_Response(
				$body,
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
			return new OAuth_Response( 'invalid_grant', \__( 'Invalid authorization code', 'indieauth' ), 400 );
		}
		// A code that fails its binding is destroyed, including when the parameter
		// is simply absent: answering differently would tell an attacker holding a
		// stolen code that it is still live, without spending it.
		$failed = code_binding_failure( $return, $args );
		if ( null !== $failed ) {
			$codes->destroy( $args['code'] );
			return new OAuth_Response(
				'invalid_grant',
				sprintf(
					// translators: Name of the parameter that did not match.
					\__( 'The %s does not match the authorization request', 'indieauth' ),
					$failed
				),
				400
			);
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
