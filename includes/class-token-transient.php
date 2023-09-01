<?php

/* Class for Generating Tokens Stored using the Transient API */
class Token_Transient extends Token_Generic {
	private $prefix;

	/**
	 * Constructor
	 *
	 * @param $prefix The prefix to use to store unique keys in user meta
	 */
	public function __construct( $prefix ) {
		$this->prefix = $prefix;
	}

	/**
	 * Set a token.
	 *
	 * @param array $info token to hash.
	 * @param int   $expiration Time in seconds to expire the token
	 * @return string|boolean The pre-hashed key or false if there is an error.
	 */
	public function set( $info, $expiration = 120 ) {
		if ( ! is_array( $info ) ) {
			return false;
		}
		if ( $expiration ) {
			$info['exp'] = $this->expires( $expiration );
		}
		$key = $this->generate_token();

		$return = set_transient( $this->prefix . $this->hash( $key ), $info, $expiration );
		if ( $return ) {
			return $key;
		}
		return false;
	}

	public function set_with_cookie( $info, $expiration = 120 ) {
		$token = $this->set( $info, $expiration );
		setcookie( $this->prefix, $token, $this->expires( $expiration ), '/', false, true );
		return $token;
	}

	public function get_with_cookie() {
		if ( ! isset( $_COOKIE[ $this->prefix ] ) ) {
			return false;
		}
		return $this->get( $_COOKIE[ $this->prefix ] );
	}

	public function verify( $key ) {
		if ( ! isset( $_COOKIE[ $this->prefix ] ) ) {
			return false;
		}
		$cookie = $this->hash( $_COOKIE[ $this->prefix ] );
		$key    = $this->hash( $key );
		if ( $key === $cookie ) {
			return $this->get_with_cookie();
		}
		return false;
	}

	public function destroy_with_cookie( $key ) {
		if ( isset( $_COOKIE[ $this->prefix ] ) ) {
			setcookie( $this->prefix, '', time() - 1000, '/', false, true );
		}
		$this->destroy( $key );
	}

	/**
	 * Destroys a token
	 *
	 * @param string $key token to destroy.
	 * @return boolean Return if successfully destroyed or not
	 */
	public function destroy( $key ) {
		$id = $this->hash( $key );
		return delete_transient( $this->prefix . $id );
	}

	/**
	 * Retrieves a token
	 *
	 * @param string  $key token to retrieve.
	 * @param boolean $hash Whether or not the key should be hashed
	 * @return array|boolean Token or false if not found
	 */
	public function get( $key, $hash = true ) {
		// Either token is already hashed or is not
		$key   = $hash ? $this->hash( $key ) : $key;
		$key   = $this->prefix . $key;
		$value = get_transient( $key );
		if ( empty( $value ) ) {
			return false;
		}

		// Even though WordPress should do it for us, if this token has expired destroy the token and return false;
		if ( ( isset( $value['expiration'] ) && $this->is_expired( $value['expiration'] ) ) || ( isset( $value['exp'] ) && $this->is_expired( $value['exp'] ) ) ) {
			$this->destroy( $key );
			return false;
		}

		return $value;
	}

	/**
	 * Updates an existing token
	 *
	 * @param string $key token. Must not be hashed
	 * @param array  $info An array that will be stored under the token name
	 * @return boolean
	 */
	public function update( $key, $info ) {
		$key = $this->hash( $key );
		$key = $this->prefix . $key;
		$old = get_transient( $key );

		// This function will only update if there is an existing value
		if ( ! $old ) {
			return false;
		}
		$expires = $old['exp'] - $this->time();
		return set_transient( $key, $info, $expires );
	}
}
