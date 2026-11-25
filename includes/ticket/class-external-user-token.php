<?php
/**
 * External User Token class file.
 *
 * @package IndieAuth
 */

namespace IndieAuth\Ticket;

use function IndieAuth\find_rels;

/**
 * Class for managing external tokens in user meta.
 */
class External_User_Token {

	/**
	 * User ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Constructor.
	 *
	 * @param int|null $user_id Optional user ID.
	 */
	public function __construct( $user_id = null ) {
		if ( is_numeric( $user_id ) ) {
			$this->user_id = $user_id;
		} else {
			$this->user_id = \get_current_user_id();
		}
	}

	/**
	 * Expire all tokens for all users.
	 *
	 * @return bool Whether tokens were expired.
	 */
	public function expire_all_tokens() {
		$args     = array(
			'count_total' => false,
			'fields'      => 'ID',
			'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'         => 'indieauth_external_tokens',
					'compare_key' => 'EXISTS',
				),
			),
		);
		$user_ids = array_unique( \get_users( $args ) );
		if ( empty( $user_ids ) ) {
			return false;
		}
		$old_user = $this->user_id;
		foreach ( $user_ids as $user_id ) {
			$this->user_id = $user_id;
			$this->expire_tokens();
		}
		$this->user_id = $old_user;
		return true;
	}

	/**
	 * Expire tokens for the current user.
	 *
	 * @return bool Whether tokens were expired.
	 */
	protected function expire_tokens() {
		$tokens = \get_user_meta( $this->user_id, 'indieauth_external_tokens', true );
		if ( empty( $tokens ) ) {
			return false;
		}
		foreach ( $tokens as $key => $token ) {
			if ( ! array_key_exists( 'expiration', $token ) || ! $this->is_expired( $token['expiration'] ) ) {
				continue;
			}

			// An expired token that cannot be refreshed is removed.
			if ( ! array_key_exists( 'refresh_token', $token ) ) {
				unset( $tokens[ $key ] );
				continue;
			}

			// A response without an access token is a failed refresh. Accepting it
			// would drop the expiry below and the token would never be checked again.
			$refresh = $this->refresh_token( $token );
			if ( ! is_array( $refresh ) || empty( $refresh['access_token'] ) ) {
				continue;
			}

			// Only take the token fields, so the response cannot overwrite
			// token_endpoint, resource or uuid. Without a new refresh_token the
			// stored one is kept, RFC 6749 section 6.
			$refresh = array_intersect_key( $refresh, array_flip( array( 'access_token', 'refresh_token', 'expires_in', 'scope' ) ) );

			// The refresh response replaces the stored expiry. Without expires_in
			// the server did not set one, so the old expiry must not be kept.
			unset( $token['expires_in'], $token['expiration'] );
			$token = array_merge( $token, $refresh );
			if ( array_key_exists( 'expires_in', $refresh ) && is_numeric( $refresh['expires_in'] ) ) {
				$token['expiration'] = time() + (int) $refresh['expires_in'];
			}

			$tokens[ $key ] = $token;
		}
		\update_user_meta( $this->user_id, 'indieauth_external_tokens', $tokens );
		return true;
	}

	/**
	 * Refresh a token.
	 *
	 * @param array $token Token data.
	 * @return array|false Refreshed token data or false on failure.
	 */
	protected function refresh_token( $token ) {
		if ( ! array_key_exists( 'refresh_token', $token ) ) {
			return false;
		}

		// Use stored token endpoint or discover it.
		if ( array_key_exists( 'token_endpoint', $token ) ) {
			$token_endpoint = $token['token_endpoint'];
		} else {
			$token_endpoint = find_rels( $token['resource'], 'token_endpoint' );
		}

		if ( ! \wp_http_validate_url( $token_endpoint ) ) {
			return false;
		}

		$args = array(
			'headers' => array(
				'Accept' => 'application/json',
			),
			'body'    => array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $token['refresh_token'],
			),
		);
		if ( array_key_exists( 'resource', $token ) ) {
			$args['body']['resource'] = $token['resource'];
		}

		// IndieAuth asks for the client_id the refresh token was issued to. A
		// ticket is redeemed without one, as client_id is optional in Ticket
		// Auth, so it is only known, and only sent, if the issuer returned it.
		if ( ! empty( $token['client_id'] ) && is_string( $token['client_id'] ) ) {
			$args['body']['client_id'] = $token['client_id'];
		}

		$resp = \wp_safe_remote_post(
			$token_endpoint,
			$args
		);

		$code = \wp_remote_retrieve_response_code( $resp );

		if ( 2 !== (int) ( $code / 100 ) ) {
			return false;
		}

		$body = \wp_remote_retrieve_body( $resp );
		return json_decode( $body, true );
	}

	/**
	 * Retrieves a token by its access token.
	 *
	 * @param string $key Access token of the token to retrieve.
	 * @return array|boolean Token data or false if not found
	 */
	public function get( $key ) {
		if ( ! is_string( $key ) || '' === $key ) {
			return false;
		}

		if ( ! \current_user_can( 'edit_user', $this->user_id ) ) {
			return false;
		}

		$tokens = \get_user_meta( $this->user_id, 'indieauth_external_tokens', true );
		if ( ! is_array( $tokens ) ) {
			return false;
		}

		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && isset( $token['access_token'] ) && hash_equals( (string) $token['access_token'], $key ) ) {
				return $token;
			}
		}
		return false;
	}

	/**
	 * Retrieves all tokens.
	 *
	 * @return array|boolean Token data or false if not found.
	 */
	public function get_all() {
		if ( ! \current_user_can( 'edit_user', $this->user_id ) ) {
			return false;
		}

		return \get_user_meta( $this->user_id, 'indieauth_external_tokens', true );
	}

	/**
	 * Add or Update a token.
	 *
	 * @param array $info token info.
	 * @return int|boolean Either false if failed or the index of the token in the database.
	 */
	public function update( $info ) {
		if ( ! array_key_exists( 'access_token', $info ) ) {
			return false;
		}

		if ( array_key_exists( 'expires_in', $info ) && is_numeric( $info['expires_in'] ) && ! array_key_exists( 'expiration', $info ) ) {
			$info['expiration'] = time() + $info['expires_in'];
		}

		$tokens = \get_user_meta( $this->user_id, 'indieauth_external_tokens', true );
		if ( ! is_array( $tokens ) ) {
			$tokens = array();
		}

		$found = null;
		foreach ( $tokens as $key => $token ) {
			if ( in_array( $info['access_token'], $token, true ) ) {
				$found = $key;
			}
		}
		if ( null === $found ) {
			// Add local unique identifier to distinguish if multiple tokens are issued.
			$info['uuid'] = \wp_generate_uuid4();

			$tokens[] = $info;
		} else {
			$tokens[ $found ] = array_merge( $tokens[ $found ], $info );
		}

		return \update_user_meta( $this->user_id, 'indieauth_external_tokens', $tokens );
	}

	/**
	 * Destroys a token.
	 *
	 * @param array|string $destroy Token to destroy. Will also accept an array of tokens to destroy.
	 * @param boolean      $revoke  Whether to send revoke request to token endpoint.
	 */
	public function destroy( $destroy, $revoke = true ) {
		if ( ! \current_user_can( 'edit_user', $this->user_id ) ) {
			return false;
		}

		$tokens = \get_user_meta( $this->user_id, 'indieauth_external_tokens', true );
		if ( ! is_array( $tokens ) ) {
			return false;
		}

		if ( is_string( $destroy ) ) {
			$destroy = array( $destroy );
		}

		foreach ( $tokens as $key => $token ) {
			if ( in_array( $token['access_token'], $destroy, true ) ) {
				unset( $tokens[ $key ] );
				if ( $revoke ) {
					$this->revoke_external_token( $token );
				}
			}
		}

		\update_user_meta( $this->user_id, 'indieauth_external_tokens', $tokens );
	}

	/**
	 * Revokes an External token.
	 *
	 * @param array $token Token to destroy. This is the token info stored in the database.
	 * @return boolean|array Either false or the response from the token endpoint.
	 */
	protected function revoke_external_token( $token ) {
		// Use stored token endpoint or discover it.
		if ( array_key_exists( 'token_endpoint', $token ) ) {
			$token_endpoint = $token['token_endpoint'];
		} else {
			$token_endpoint = find_rels( $token['resource'], 'token_endpoint' );
		}

		if ( ! \wp_http_validate_url( $token_endpoint ) ) {
			return false;
		}

		$resp = \wp_safe_remote_post(
			$token_endpoint,
			array(
				'headers' => array(
					'Accept' => 'application/json',
				),
				'body'    => array(
					'action' => 'revoke',
					'token'  => $token['access_token'],
				),
			)
		);
		return $resp;
	}


	/**
	 * Verifies an External token.
	 *
	 * @param string $key Access token of the token to verify.
	 * @return boolean|array Either false or the response from the token endpoint.
	 */
	public function verify( $key ) {

		$token = $this->get( $key );
		if ( ! $token ) {
			return false;
		}

		// Use stored token endpoint or discover it.
		if ( array_key_exists( 'token_endpoint', $token ) ) {
			$token_endpoint = $token['token_endpoint'];
		} else {
			$token_endpoint = find_rels( $token['resource'], 'token_endpoint' );
		}

		if ( ! \wp_http_validate_url( $token_endpoint ) ) {
			return false;
		}

		$resp = \wp_safe_remote_get(
			$token_endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token['access_token'],
					'Accept'        => 'application/json',
				),
			)
		);

		$code = \wp_remote_retrieve_response_code( $resp );

		if ( 2 !== (int) ( $code / 100 ) ) {
			return false;
		}

		$body = \wp_remote_retrieve_body( $resp );
		return json_decode( $body, true );
	}

	/**
	 * Is Expired.
	 *
	 * @param int $expiration Time to check against current time.
	 * @return boolean
	 */
	public function is_expired( $expiration ) {
		if ( ! is_numeric( $expiration ) ) {
			return false;
		}
		return ( $expiration <= time() );
	}
}
