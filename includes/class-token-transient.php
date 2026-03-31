<?php
/**
 * Token Transient class file.
 *
 * @package IndieAuth
 */

/**
 * Class for Generating Tokens Stored using the Transient API.
 *
 * @since 1.0.0
 */
class Token_Transient extends Token_Generic {

	/**
	 * Prefix for transient keys.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Constructor.
	 *
	 * @param string $prefix The prefix to use to store unique keys.
	 */
	public function __construct( $prefix ) {
		$this->prefix = $prefix;
	}

	/**
	 * Set a token.
	 *
	 * @param array $info       Token to hash.
	 * @param int   $expiration Time in seconds to expire the token.
	 * @return string|bool The pre-hashed key or false if there is an error.
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

	/**
	 * Set a token and store key in cookie.
	 *
	 * @param array $info       Token data.
	 * @param int   $expiration Time in seconds to expire.
	 * @return string Token key.
	 */
	public function set_with_cookie( $info, $expiration = 120 ) {
		$token = $this->set( $info, $expiration );
		setcookie( $this->prefix, $token, $this->expires( $expiration ), '/', false, true );
		return $token;
	}

	/**
	 * Get token using cookie.
	 *
	 * @return array|bool Token data or false.
	 */
	public function get_with_cookie() {
		if ( ! isset( $_COOKIE[ $this->prefix ] ) ) {
			return false;
		}
		return $this->get( sanitize_text_field( wp_unslash( $_COOKIE[ $this->prefix ] ) ) );
	}

	/**
	 * Verify a token against cookie.
	 *
	 * @param string $key Token key to verify.
	 * @return array|bool Token data or false.
	 */
	public function verify( $key ) {
		if ( ! isset( $_COOKIE[ $this->prefix ] ) ) {
			return false;
		}
		$cookie = $this->hash( sanitize_text_field( wp_unslash( $_COOKIE[ $this->prefix ] ) ) );
		$key    = $this->hash( $key );
		if ( $key === $cookie ) {
			return $this->get_with_cookie();
		}
		return false;
	}

	/**
	 * Destroy token and clear cookie.
	 *
	 * @param string $key Token key to destroy.
	 */
	public function destroy_with_cookie( $key ) {
		if ( isset( $_COOKIE[ $this->prefix ] ) ) {
			setcookie( $this->prefix, '', time() - 1000, '/', false, true );
		}
		$this->destroy( $key );
	}

	/**
	 * Destroys a token.
	 *
	 * @param string $key Token to destroy.
	 * @return bool Return if successfully destroyed or not.
	 */
	public function destroy( $key ) {
		$id = $this->hash( $key );
		return delete_transient( $this->prefix . $id );
	}

	/**
	 * Retrieves a token.
	 *
	 * @param string $key  Token to retrieve.
	 * @param bool   $hash Whether or not the key should be hashed.
	 * @return array|bool Token or false if not found.
	 */
	public function get( $key, $hash = true ) {
		// Either token is already hashed or is not.
		$key   = $hash ? $this->hash( $key ) : $key;
		$key   = $this->prefix . $key;
		$value = get_transient( $key );
		if ( empty( $value ) ) {
			return false;
		}

		// Even though WordPress should do it for us, if this token has expired destroy the token and return false.
		if ( ( isset( $value['expiration'] ) && $this->is_expired( $value['expiration'] ) ) || ( isset( $value['exp'] ) && $this->is_expired( $value['exp'] ) ) ) {
			$this->destroy( $key );
			return false;
		}

		return $value;
	}

	/**
	 * Updates an existing token.
	 *
	 * @param string $key  Token. Must not be hashed.
	 * @param array  $info An array that will be stored under the token name.
	 * @return bool Whether update was successful.
	 */
	public function update( $key, $info ) {
		$key = $this->hash( $key );
		$key = $this->prefix . $key;
		$old = get_transient( $key );

		// This function will only update if there is an existing value.
		if ( ! $old ) {
			return false;
		}
		$expires = $old['exp'] - $this->time();
		return set_transient( $key, $info, $expires );
	}
}
