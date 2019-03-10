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
		return $this->time() + $expiration;
	}

	/**
	 * Returns the correct timestamp.
	 *
	 * @return int Timestamp.
	 */
	public function time() {
		return current_time( 'timestamp', 1 );
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
		return ( $expiration <= $this->time() );
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
