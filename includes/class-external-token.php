<?php

/* Class for managing external tokens in user meta */
class External_User_Token {

	protected $user_id;

	public function __construct( $user_id = null ) {
		if ( is_numeric( $user_id ) ) {
			$this->user_id = $user_id;
		} else {
			$this->user_id = get_current_user_id();
		}
	}

	/**
	 *
	 */
	public function expire_all_tokens() {
		$args     = array(
			'count_total' => false,
			'fields'      => 'ID',
			'meta_query'  => array(
				array(
					'key'         => 'indieauth_external_tokens',
					'compare_key' => 'EXISTS',
				),
			),
		);
		$user_ids = array_unique( get_users( $args ) );
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
	 *
	 */
	protected function expire_tokens() {
		$tokens = $this->get_all();
		foreach ( $tokens as $key => $token ) {
			if ( array_key_exists( 'expiration', $token ) && $this->is_expired( $token['expiration'] ) ) {
				if ( array_key_exists( 'refresh_token' ) ) {
					$refresh = $this->refresh_token( $token );
					if ( is_array( $refresh ) ) {
						$token[ $key ] = array_merge( $token[ $key ], $refresh );
						if ( array_key_exists( 'expires_in', $token[ $key ] ) ) {
							$token[ $key ]['expiration'] = time() + $token[ $key ]['expires_in'];
						}
					}
				} else {
					unset( $token[ $key ] );
				}
			}
		}
		update_user_meta( $this->user_id, 'indieauth_external_tokens', $tokens );
		return true;
	}

	/**
	 *
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

		if ( ! wp_http_validate_url( $token_endpoint ) ) {
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

		$resp = wp_safe_remote_post(
			$token_endpoint,
			$args
		);

		$code = wp_remote_retrieve_response_code( $resp );

		if ( 2 !== (int) ( $code / 100 ) ) {
			return $false;
		}

		$body = wp_remote_retrieve_body( $resp );
		return json_decode( $body, true );
	}

	/**
	 * Retrieves a token
	 *
	 * @param string  $key token to retrieve.
	 * @return array|boolean Token data or false if not found
	 */
	public function get( $key ) {
		if ( ! is_string( $key ) ) {
			return false;
		}

		if ( ! current_user_can( 'edit_user', $this->user_id ) ) {
			return false;
		}

		$tokens = get_user_meta( $this->user_id, 'indieauth_external_tokens', true );
		foreach ( $tokens as $key => $token ) {
			if ( in_array( $token['access_token'], $key, true ) ) {
				return $tokens[ $key ];
			}
		}
		return false;
	}

	/**
	 * Retrieves a token
	 *
	 * @param string  $key token to retrieve.
	 * @return array|boolean Token data or false if not found
	 */
	public function get_all() {
		if ( ! current_user_can( 'edit_user', $this->user_id ) ) {
			return false;
		}

		return get_user_meta( $this->user_id, 'indieauth_external_tokens', true );
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

		$tokens = get_user_meta( $this->user_id, 'indieauth_external_tokens', true );
		if ( ! is_array( $tokens ) ) {
			$tokens = array();
		}

		$found = null;
		foreach ( $tokens as $key => $token ) {
			if ( in_array( $info['access_token'], $token, true ) ) {
				$found = $key;
			}
		}
		if ( ! $found ) {
			// Add local unique identifier to distinguish if multiple tokens are issued.
			$info['uuid'] = wp_generate_uuid4();

			$tokens[] = $info;
		} else {
			$tokens[ $found ] = array_merge( $tokens[ $found ], $info );
		}

		return update_user_meta( $this->user_id, 'indieauth_external_tokens', $tokens );
	}

	/**
	 * Destroys a token.
	 *
	 * @param array|string $tokens Token to destroy. Will also accept an array of tokens to destroy.
	 * @param boolean $revoke Whether to send revoke request to token endpoint.
	 */
	public function destroy( $destroy, $revoke = true ) {
		if ( ! current_user_can( 'edit_user', $this->user_id ) ) {
			return false;
		}

		$tokens = get_user_meta( $this->user_id, 'indieauth_external_tokens', true );

		if ( is_string( $destroy ) ) {
			$destroy = array( $destroy );
		}

		foreach ( $tokens as $key => $token ) {
			if ( in_array( $token['access_token'], $destroy, true ) ) {
				unset( $tokens[ $key ] );
				if ( $revoke ) {
					$resp = $this->revoke_external_token( $token );
				}
			}
		}

		update_user_meta( $this->user_id, 'indieauth_external_tokens', $tokens );
	}

	/**
	 * Revokes an External token.
	 *
	 * @param array $token Token to destroy. This is the token info stored in the database.
	 * @param boolean $revoke Whether to send revoke request to token endpoint.
	 * @return boolean|array Either false or the response from the token endpoint.
	 */
	protected function revoke_external_token( $token ) {
		// Use stored token endpoint or discover it.
		if ( array_key_exists( 'token_endpoint', $token ) ) {
			$token_endpoint = $token['token_endpoint'];
		} else {
			$token_endpoint = find_rels( $token['resource'], 'token_endpoint' );
		}

		if ( ! wp_http_validate_url( $token_endpoint ) ) {
			return false;
		}

		$resp = wp_safe_remote_post(
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
	 * Revokes an External token.
	 *
	 * @param array $key Token to verify.
	 * @param boolean $revoke Whether to send revoke request to token endpoint.
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

		if ( ! wp_http_validate_url( $token_endpoint ) ) {
			return false;
		}

		$resp = wp_safe_remote_get(
			$token_endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer: ' . $token['access_token'],
					'Accept'        => 'application/json',
				),
			)
		);

		$code = wp_remote_retrieve_response_code( $resp );

		if ( 2 !== (int) ( $code / 100 ) ) {
			return $false;
		}

		$body = wp_remote_retrieve_body( $resp );
		return json_decode( $body, true );
	}

	/**
	 * Is Expired.
	 *
	 * @param int $expiration Time to check against current time
	 * @return boolean
	 */
	public function is_expired( $expiration ) {
		if ( ! is_numeric( $expiration ) ) {
			return false;
		}
		return ( $expiration <= time() );
	}
}
