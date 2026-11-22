<?php
/**
 * Token User class file.
 *
 * @package IndieAuth
 */

namespace IndieAuth\Token;

/**
 * Class for generating tokens stored in user meta.
 *
 * @since 1.0.0
 */
class User extends Generic {

	/**
	 * Prefix for meta keys.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * User ID.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Constructor.
	 *
	 * @param string   $prefix  The prefix to use to store unique keys in user meta.
	 * @param int|null $user_id Optional user ID.
	 */
	public function __construct( $prefix, $user_id = null ) {
		if ( $user_id ) {
			$this->user_id = $user_id;
		}
		$this->prefix = $prefix;
	}

	/**
	 * Sets the user_id.
	 *
	 * @param int $user_id Sets a User ID.
	 */
	public function set_user( $user_id ) {
		$this->user_id = $user_id;
	}

	/**
	 * Set a token.
	 *
	 * @param array    $info       Token to hash.
	 * @param int|null $expiration Time in seconds to expire the token.
	 * @return string|false The pre-hashed key or false if there is an error.
	 */
	public function set( $info, $expiration = null ) {
		// Whenever setting a token check to see if this user is one who has tokens and add to option.
		$user_ids = \get_option( $this->prefix . 'ids' );
		if ( ! $user_ids ) {
			\add_option( $this->prefix . 'ids', array( $this->user_id ) );
		}
		if ( is_array( $user_ids ) && ! array_key_exists( $this->user_id, $user_ids ) ) {
			$user_ids[] = $this->user_id;
			\update_option( $this->prefix . 'ids', $user_ids );
		}

		if ( ! is_array( $info ) ) {
			return false;
		}
		if ( $expiration ) {
			$info['exp'] = $this->expires( $expiration );
		}
		$key = $this->generate_token();
		// Will only add if value is not set.
		$return = \add_user_meta( $this->user_id, $this->prefix . $this->hash( $key ), $info, true );
		if ( $return ) {
			return $key;
		}
		return false;
	}

	/**
	 * Destroys a token.
	 *
	 * @param string $key Token to destroy.
	 * @return bool Return if successfully destroyed or not.
	 */
	public function destroy( $key ) {
		$token = $this->get( $key, false );
		if ( $token ) {
			return \delete_user_meta( $token['user'], $this->prefix . $key );
		}

		$token = $this->get( $key );
		if ( $token ) {
			return \delete_user_meta( $token['user'], $this->prefix . $this->hash( $key ) );
		}
	}

	/**
	 * Destroys all tokens.
	 *
	 * @return bool|void Return false if no user_id, void otherwise.
	 */
	public function destroy_all() {
		if ( ! $this->user_id ) {
			return false;
		}
		$meta = \get_user_meta( $this->user_id, '' ); // phpcs:ignore WordPress.WP.GetMetaSingle.Missing -- Retrieving all meta keys.
		foreach ( array_keys( $meta ) as $key ) {
			if ( 0 === strncmp( $key, $this->prefix, strlen( $this->prefix ) ) ) {
				\delete_user_meta( $this->user_id, $key );
			}
		}
	}


	/**
	 * Retrieves all tokens.
	 *
	 * @return array Tokens array.
	 */
	public function get_all() {
		if ( ! $this->user_id ) {
			$ids = $this->find_token_users();
		} else {
			$ids = array( $this->user_id );
		}

		$tokens = array();
		foreach ( $ids as $user_id ) {
			$meta = \get_user_meta( $user_id, '' ); // phpcs:ignore WordPress.WP.GetMetaSingle.Missing -- Retrieving all meta keys.
			foreach ( $meta as $key => $value ) {
				if ( 0 === strncmp( $key, $this->prefix, strlen( $this->prefix ) ) ) {
					$value         = maybe_unserialize( array_pop( $value ) );
					$key           = str_replace( $this->prefix, '', $key );
					$value['user'] = $user_id;
					// Check for old and new property.
					if ( ( isset( $value['expiration'] ) && $this->is_expired( $value['expiration'] ) ) || ( isset( $value['exp'] ) && $this->is_expired( $value['exp'] ) ) ) {
						$this->destroy( $key );
					} else {
						$tokens[ $key ] = $value;
					}
				}
			}
		}
		return $tokens;
	}

	/**
	 * Retrieves all tokens for a user and destroys the ones that are expired.
	 *
	 * @return bool Returns true if tokens were expired false if none were.
	 */
	public function check_expires() {
		if ( ! $this->user_id ) {
			return false;
		}
		$tokens = $this->get_all();
		if ( ! $tokens ) {
			return false;
		}
		$return = false;
		foreach ( $tokens as $key => $value ) {
			if ( ( isset( $value['expiration'] ) && $this->is_expired( $value['expiration'] ) ) || ( isset( $value['exp'] ) && $this->is_expired( $value['exp'] ) ) ) {
				$this->destroy( $key );
				$return = true;
			}
		}
		return $return;
	}

	/**
	 * Retrieves a token.
	 *
	 * @param string $key  Token to retrieve.
	 * @param bool   $hash Whether or not the key should be hashed.
	 * @return array|false Token or false if not found.
	 */
	public function get( $key, $hash = true ) {
		// Either token is already hashed or is not.
		$key     = $hash ? $this->hash( $key ) : $key;
		$key     = $this->prefix . $key;
		$args    = array(
			'number'      => 1,
			'count_total' => false,
			'fields'      => 'ID',
			'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => $key,
					'compare' => 'EXISTS',
				),
			),
		);
		$results = \get_users( $args );
		if ( empty( $results ) ) {
			return false;
		}
		$user_id = $results[0];

		$value = \get_user_meta( $user_id, $key, true );
		if ( empty( $value ) ) {
			return false;
		}

		// If this token has expired destroy the token and return false.
		if ( ( isset( $value['expiration'] ) && $this->is_expired( $value['expiration'] ) ) || ( isset( $value['exp'] ) && $this->is_expired( $value['exp'] ) ) ) {
			$this->destroy( $key );
			return false;
		}

		$value['user'] = $user_id;
		return $value;
	}

	/**
	 * Updates an existing token.
	 *
	 * @param string $key  Token.
	 * @param array  $info An array that will be stored under the token name.
	 * @param bool   $hash Whether key is already hashed.
	 * @return bool|int Meta ID or false on failure.
	 */
	public function update( $key, $info, $hash = false ) {
		if ( ! $this->user_id ) {
			return false;
		}
		if ( ! $hash ) {
			$key = $this->hash( $key );
		}
		$key = $this->prefix . $key;
		$old = \get_user_meta( $this->user_id, $key, true );

		// This function will only update if there is an existing value.
		if ( ! $old ) {
			return false;
		}
		return \update_user_meta( $this->user_id, $key, $info );
	}

	/**
	 * Find users who have tokens.
	 *
	 * @param bool $refresh Whether to refresh from cache.
	 * @return array User IDs.
	 */
	public function find_token_users( $refresh = false ) {
		if ( $refresh ) {
			$user_ids = \get_option( $this->prefix . 'ids' );
		} else {
			$user_ids = false;
		}
		if ( false === $user_ids ) {
			$args     = array(
				'count_total' => false,
				'fields'      => 'ID',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'         => $this->prefix,
						'compare_key' => 'LIKE',
					),
				),
			);
			$user_ids = array_unique( \get_users( $args ) );
			// Like queries can be expensive so save the results.
			\add_option( $this->prefix . 'ids', $user_ids );
		}
		return $user_ids;
	}

	/**
	 * Find tokens by field.
	 *
	 * @param string   $field Field.
	 * @param string   $value Value.
	 * @param int|null $user  User ID.
	 * @return array Results.
	 */
	public function find_by_field( $field, $value, $user = null ) {
		$tokens = $this->get_all( $user );
		$return = array();
		foreach ( $tokens as $key => $token ) {
			if ( array_key_exists( $field, $token ) && ( $value === $token[ $field ] ) ) {
				$return[ $key ] = $token;
			}
		}
		return $return;
	}
}
