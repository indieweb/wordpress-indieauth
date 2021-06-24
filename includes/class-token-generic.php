<?php

/* Abstract Class for Generating Tokens of All Sorts */
abstract class Token_Generic {

	/**
	 * Set a token.
	 *
	 * @param array $info token to hash.
	 * @param int   $expiration Time to expire.
	 * @return string|boolean The pre-hashed key or false if there is an error.
	 */
	abstract public function set( $info, $expiration = null );

	/**
	 * Destroys a token.
	 *
	 * @param string $key token to destroy.
	 * @return boolean Return if successfully destroyed or not
	 */
	abstract public function destroy( $key );

	/**
	 * Renews a token.
	 *
	 * @param string $key token to renew
	 * @return boolean Return if successfully renewed
	 */
	public function renew( $key ) {
		$token = $this->get( $key, false );
		if ( ! $token ) {
			return;
		}
		$expires = (int) get_option( 'indieauth_expires_in' );
		// Ignore if the renewal time is 0.
		if ( 0 === $expires ) {
			return;
		}
		if ( array_key_exists( 'expiration', $token ) ) {
			$token['expiration'] = $token['expiration'] + $expires;
		} else {
			$token['expiration'] = time() + $expires;
		}
		$token['expires_in'] = $token['expiration'] - time();
		$this->update( $key, $token, true );
	}

	/**
	 * Disable Expiry
	 *
	 * @param string $key token to disable
	 * @return boolean Return if successful
	 */
	public function noexpire( $key ) {
		$token = $this->get( $key, false );
		if ( ! $token ) {
			return;
		}
		if ( array_key_exists( 'expiration', $token ) ) {
			unset( $token['expiration'] );
			unset( $token['expires_in'] );
		}
		$this->update( $key, $token, true );
	}

	/**
	 * Retrieves a token
	 *
	 * @param string  $key token to retrieve.
	 * @param boolean $hash Whether or not the key must be hashed
	 * @return array|boolean Token or false if not found
	 */
	abstract public function get( $key, $hash = true );

	/**
	 * Returns a randomly generated string.
	 *
	 * @return string Generated token.
	 */
	protected function generate_token() {
		return wp_generate_password( 128, false );
	}

	/**
	 * Returns an expiration time.
	 *
	 * @return int Timestamp when this will expire.
	 */
	public function expires( $expiration ) {
		return time() + $expiration;
	}

	/**
	 * Returns the correct timestamp.
	 *
	 * @return int Timestamp.
	 */
	public function time() {
		return time();
	}

	/**
	 * Returns the correct timestamp.
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


	/**
	 * Hashes a token.
	 *
	 * @param string $string string to hash.
	 * @param string $scheme Hashing sceheme
	 * @return string A hash of the string encoded to base64.
	 */
	protected function hash( $string ) {
		return base64_encode( indieauth_hash( $string ) );
	}

	/**
	 * Updates an existing token
	 *
	 * @param string $key token to hash.
	 * @param array  $info An array that will be stored under the token name
	 * @return string|boolean A hash of the session token (a verifier) or false if key does not exist.
	 */
	abstract public function update( $key, $info );
}
