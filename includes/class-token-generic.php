<?php
/**
 * Token Generic abstract class file.
 *
 * @package IndieAuth
 */

/**
 * Abstract Class for Generating Tokens of All Sorts.
 *
 * @since 1.0.0
 */
abstract class Token_Generic {

	/**
	 * Set a token.
	 *
	 * @param array    $info       Token to hash.
	 * @param int|null $expiration Time to expire.
	 * @return string|bool The pre-hashed key or false if there is an error.
	 */
	abstract public function set( $info, $expiration = null );

	/**
	 * Destroys a token.
	 *
	 * @param string $key Token to destroy.
	 * @return bool Return if successfully destroyed or not.
	 */
	abstract public function destroy( $key );

	/**
	 * Renews a token.
	 *
	 * @param string $key Token to renew.
	 * @return void
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
			$token['exp'] = $token['expiration'];
			unset( $token['expiration'] );
		}

		if ( array_key_exists( 'exp', $token ) ) {
			$token['exp'] = $token['exp'] + $expires;
		} else {
			$token['exp'] = time() + $expires;
		}
		$this->update( $key, $token, true );
	}

	/**
	 * Disable Expiry.
	 *
	 * @param string $key Token to disable.
	 * @return void
	 */
	public function noexpire( $key ) {
		$token = $this->get( $key, false );
		if ( ! $token ) {
			return;
		}

		unset( $token['exp'] );
		unset( $token['expiration'] );
		unset( $token['expires_in'] );
		$this->update( $key, $token, true );
	}

	/**
	 * Retrieves a token.
	 *
	 * @param string $key  Token to retrieve.
	 * @param bool   $hash Whether or not the key must be hashed.
	 * @return array|bool Token or false if not found.
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
	 * @param int $expiration Expiration offset in seconds.
	 * @return int Timestamp when this will expire.
	 */
	public function expires( $expiration ) {
		return round( time() + $expiration );
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
	 * @param int $expiration Time to check against current time.
	 * @return bool Whether the token is expired.
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
	 * @param string $token_string String to hash.
	 * @return string A hash of the string encoded to base64.
	 */
	protected function hash( $token_string ) {
		return base64_encode( indieauth_hash( $token_string ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Updates an existing token.
	 *
	 * @param string $key  Token to hash.
	 * @param array  $info An array that will be stored under the token name.
	 * @return string|bool A hash of the session token (a verifier) or false if key does not exist.
	 */
	abstract public function update( $key, $info );
}
