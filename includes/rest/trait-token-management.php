<?php
/**
 * Token trait file.
 *
 * @package IndieAuth
 */

namespace IndieAuth\Rest;

use IndieAuth\Token\User as Token_User;
use function IndieAuth\get_user_by_identifier;

/**
 * Trait providing shared token management for REST controllers.
 *
 * @since 5.0.0
 */
trait Token_Management {

	/**
	 * Token storage.
	 *
	 * @var Token_User
	 */
	protected $tokens;

	/**
	 * Refresh token storage.
	 *
	 * @var Token_User
	 */
	protected $refresh_tokens;

	/**
	 * Initialize token storage.
	 */
	protected function init_tokens() {
		$this->tokens         = new Token_User( '_indieauth_token_' );
		$this->refresh_tokens = new Token_User( '_indieauth_refresh_' );
	}

	/**
	 * Extracts the token from the given authorization header.
	 *
	 * @param string $header Authorization header.
	 * @return string|null Token on success, null on failure.
	 */
	public function get_token_from_bearer_header( $header ) {
		if ( is_string( $header ) && preg_match( '/Bearer ([\x20-\x7E]+)/', trim( $header ), $matches ) ) {
			return $matches[1];
		}
		return null;
	}

	/**
	 * Get a token by its ID.
	 *
	 * @param string      $token Token ID.
	 * @param bool        $hash  Whether to hash the token.
	 * @param string|null $type  Token type (access_token, refresh_token, or null for both).
	 * @return array|false Token data or false.
	 */
	public function get_token( $token, $hash = true, $type = null ) {
		switch ( $type ) {
			case 'access_token':
				return $this->tokens->get( $token, $hash );
			case 'refresh_token':
				return $this->refresh_tokens->get( $token, $hash );
			default:
				$token = $this->tokens->get( $token, $hash );
				if ( $token ) {
					return $token;
				}
				$refresh = $this->refresh_tokens->get( $token, $hash );
				return $refresh;
		}
	}

	/**
	 * Delete a token.
	 *
	 * @param string      $id      Token ID.
	 * @param int|null    $user_id User ID.
	 * @param string|null $type    Token type.
	 * @return bool|mixed Result of deletion.
	 */
	public function delete_token( $id, $user_id = null, $type = null ) {
		switch ( $type ) {
			case 'access_token':
				$this->tokens->set_user( $user_id );
				return $this->tokens->destroy( $id );
			case 'refresh_token':
				$this->refresh_tokens->set_user( $user_id );
				return $this->refresh_tokens->destroy( $id );
			default:
				$this->tokens->set_user( $user_id );
				$token = $this->tokens->destroy( $id );
				if ( $token ) {
					return $token;
				}
				$this->refresh_tokens->set_user( $user_id );
				return $this->refresh_tokens->destroy( $id );
		}
	}

	/**
	 * Set/store a token.
	 *
	 * @param array    $token      Token data.
	 * @param int|null $expiration Expiration time.
	 * @param int|null $user_id    User ID.
	 * @return string|false Token key or false.
	 */
	public function set_token( $token, $expiration = null, $user_id = null ) {
		if ( ! isset( $token['me'] ) ) {
			return false;
		}
		if ( ! $user_id ) {
			$user = get_user_by_identifier( $token['me'] );
			if ( $user instanceof \WP_User ) {
				$user_id = $user->ID;
			} else {
				return false;
			}
		}

		$this->tokens->set_user( $user_id );

		return $this->tokens->set( $token, $expiration );
	}

	/**
	 * Sets a refresh token based on an access token.
	 *
	 * @param array $token Access Token Return.
	 * @param int   $user  User ID.
	 * @return string Refresh Token.
	 */
	public function set_refresh_token( $token, $user ) {
		$refresh = array(
			'scope'     => $token['scope'],
			'client_id' => $token['client_id'],
			'iat'       => time(),
			'me'        => $token['me'],
			'uuid'      => $token['uuid'], // Uses the token UUID from the access token and adds it to the refresh token allowing them to be associated.
		);
		$this->refresh_tokens->set_user( $user );
		$expires_in = array_key_exists( 'expires_in', $token ) ? $token['expires_in'] : null;

		return $this->refresh_tokens->set( $refresh, $expires_in + 300 );
	}

	/**
	 * Delete a refresh token.
	 *
	 * @param string   $id      Token ID.
	 * @param int|null $user_id User ID.
	 * @return bool Result of deletion.
	 */
	public function delete_refresh_token( $id, $user_id = null ) {
		$this->refresh_tokens->set_user( $user_id );
		return $this->refresh_tokens->destroy( $id );
	}
}
