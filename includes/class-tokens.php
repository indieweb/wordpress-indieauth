<?php
/**
 * Tokens class file.
 *
 * @package IndieAuth
 */

namespace IndieAuth;

use IndieAuth\Token\Generic;

/**
 * The access tokens and refresh tokens of the site.
 *
 * Stored as posts of a private post type, one post per token. The post name
 * is the hash of the token, the author is the user the token belongs to, and
 * the status tells access tokens and refresh tokens apart.
 */
class Tokens extends Generic {

	/**
	 * The post type tokens are stored in.
	 *
	 * @var string
	 */
	const POST_TYPE = 'indieauth_token';

	/**
	 * The kinds of token, each one is a post status of its own.
	 *
	 * @var string[]
	 */
	const TYPES = array( 'access', 'refresh' );

	/**
	 * Post status of the tokens this store handles.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * User ID.
	 *
	 * @var int|null
	 */
	private $user_id;

	/**
	 * Constructor.
	 *
	 * @param string   $type    Kind of token, `access` or `refresh`.
	 * @param int|null $user_id Optional user ID.
	 */
	public function __construct( $type, $user_id = null ) {
		$this->status  = 'indieauth_' . $type;
		$this->user_id = $user_id;
	}

	/**
	 * Set user ID.
	 *
	 * @param int $user_id User ID.
	 */
	public function set_user( $user_id ) {
		$this->user_id = $user_id;
	}

	/**
	 * Register the post type and the post statuses.
	 */
	public static function init() {
		\register_post_type(
			self::POST_TYPE,
			array(
				'label'               => \__( 'IndieAuth Tokens', 'indieauth' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'can_export'          => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'author' ),
				'delete_with_user'    => true,
				// Nobody may read, create or change a token as a post. Without this
				// the post type would get the capabilities of posts, and an editor
				// could change the author of a token.
				'capabilities'        => array_fill_keys(
					array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'delete_posts', 'publish_posts', 'read_private_posts', 'create_posts' ),
					'do_not_allow'
				),
			)
		);

		// So the link to the client is removed with the token.
		\register_taxonomy_for_object_type( 'indieauth_client', self::POST_TYPE );

		foreach ( self::TYPES as $type ) {
			\register_post_status( 'indieauth_' . $type, array( 'internal' => true ) );
		}

		\add_action( 'delete_user', array( self::class, 'delete_user_tokens' ) );
		\add_action( 'remove_user_from_blog', array( self::class, 'delete_user_tokens' ) );
	}

	/**
	 * Delete the tokens of a user who is deleted or removed from the site.
	 *
	 * WordPress can hand the posts of that user to another user. Tokens must
	 * never change their owner, so they are deleted before that happens.
	 *
	 * @param int $user_id User ID.
	 */
	public static function delete_user_tokens( $user_id ) {
		foreach ( self::TYPES as $type ) {
			$tokens = new self( $type, $user_id );
			$tokens->destroy_all();
		}
	}

	/**
	 * Hashes a token. Hex, as a post name does not keep the case of base64.
	 *
	 * @param string $token_string Token to hash.
	 * @return string Hashed token.
	 */
	protected function hash( $token_string ) {
		return bin2hex( indieauth_hash( $token_string ) );
	}

	/**
	 * Checks that the kind of token is known and its post status is registered.
	 *
	 * A query for a status that is not registered is not limited to it, and
	 * would return tokens of every kind.
	 *
	 * @return bool Whether tokens can be read and written.
	 */
	private function is_ready() {
		return null !== \get_post_status_object( $this->status );
	}

	/**
	 * Finds the post of a token.
	 *
	 * @param string $name Hash of the token.
	 * @return \WP_Post|null The post, or null if there is none.
	 */
	private function find( $name ) {
		// Anything that is not a hash, like a token that was not hashed yet, cannot be a post name.
		if ( ! $this->is_ready() || ! is_string( $name ) || ! preg_match( '/^[0-9a-f]{64}$/', $name ) ) {
			return null;
		}

		$posts = \get_posts(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => $this->status,
				'name'                   => $name,
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		return $posts ? $posts[0] : null;
	}

	/**
	 * Finds the posts of all tokens of this kind.
	 *
	 * @param array $args Additional query arguments.
	 * @return \WP_Post[] The posts.
	 */
	private function query( $args = array() ) {
		if ( ! $this->is_ready() ) {
			return array();
		}

		$args = array_merge(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => $this->status,
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			),
			$args
		);
		if ( $this->user_id ) {
			$args['author'] = $this->user_id;
		}

		return \get_posts( $args );
	}

	/**
	 * Reads the token data of a post.
	 *
	 * @param \WP_Post $post The post of a token.
	 * @return array|null Token data, with the user it belongs to. Null for a post without token data.
	 */
	private function read( $post ) {
		$info = \get_post_meta( $post->ID, '_indieauth_token', true );
		if ( ! is_array( $info ) || empty( $info ) ) {
			return null;
		}
		$info['user'] = (int) $post->post_author;

		return $info;
	}

	/**
	 * Writes the token data of a post.
	 *
	 * The expiry and the uuid are stored a second time under their own keys, so
	 * tokens can be found by them.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $info    Token data.
	 */
	private function write( $post_id, $info ) {
		// The user is the post author.
		unset( $info['user'] );
		\update_post_meta( $post_id, '_indieauth_token', \wp_slash( $info ) );

		foreach ( array( 'exp', 'uuid' ) as $field ) {
			if ( isset( $info[ $field ] ) ) {
				\update_post_meta( $post_id, '_indieauth_token_' . $field, \wp_slash( $info[ $field ] ) );
			} else {
				\delete_post_meta( $post_id, '_indieauth_token_' . $field );
			}
		}
	}

	/**
	 * Checks if token data is expired.
	 *
	 * @param array $info Token data.
	 * @return bool Whether the token is expired.
	 */
	private function has_expired( $info ) {
		// `expiration` is the name tokens used before 4.1.0.
		return ( isset( $info['exp'] ) && $this->is_expired( $info['exp'] ) ) || ( isset( $info['expiration'] ) && $this->is_expired( $info['expiration'] ) );
	}

	/**
	 * Sets a token.
	 *
	 * @param array    $info       Token to store.
	 * @param int|null $expiration Time in seconds until the token expires.
	 * @return string|false The token, or false if it could not be stored.
	 */
	public function set( $info, $expiration = null ) {
		if ( ! $this->is_ready() || ! $this->user_id || ! is_array( $info ) ) {
			return false;
		}
		if ( $expiration ) {
			$info['exp'] = $this->expires( $expiration );
		}

		$key     = $this->generate_token();
		$post_id = \wp_insert_post(
			array(
				'post_type'     => self::POST_TYPE,
				'post_status'   => $this->status,
				'post_author'   => $this->user_id,
				'post_name'     => $this->hash( $key ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', isset( $info['iat'] ) && is_numeric( $info['iat'] ) ? (int) $info['iat'] : time() ),
			)
		);
		if ( ! $post_id ) {
			return false;
		}

		$this->write( $post_id, $info );

		// Attach the client, so the tokens of a client can be found.
		$client = isset( $info['client_uid'] ) ? (int) $info['client_uid'] : 0;
		if ( ! $client && ! empty( $info['client_id'] ) ) {
			$found = Client_Taxonomy::get_client( $info['client_id'] );
			if ( is_array( $found ) && isset( $found['id'] ) ) {
				$client = (int) $found['id'];
			}
		}
		if ( $client && \term_exists( $client, 'indieauth_client' ) ) {
			\wp_set_object_terms( $post_id, $client, 'indieauth_client' );
		}

		return $key;
	}

	/**
	 * Retrieves a token.
	 *
	 * @param string $key  Token.
	 * @param bool   $hash If the token still has to be hashed.
	 * @return array|false Token data, or false if there is no such token.
	 */
	public function get( $key, $hash = true ) {
		$post = $this->find( $hash ? $this->hash( $key ) : $key );
		if ( ! $post ) {
			return false;
		}

		$info = $this->read( $post );
		if ( ! $info ) {
			return false;
		}
		if ( $this->has_expired( $info ) ) {
			\wp_delete_post( $post->ID, true );
			return false;
		}

		return $info;
	}

	/**
	 * Updates a token of the current user.
	 *
	 * @param string $key  Token.
	 * @param array  $info Token data.
	 * @param bool   $hash If the token is hashed already.
	 * @return bool Whether the token was updated.
	 */
	public function update( $key, $info, $hash = false ) {
		if ( ! $this->user_id || ! is_array( $info ) ) {
			return false;
		}

		$post = $this->find( $hash ? $key : $this->hash( $key ) );
		if ( ! $post || (int) $post->post_author !== (int) $this->user_id ) {
			return false;
		}

		$this->write( $post->ID, $info );
		return true;
	}

	/**
	 * Destroys a token.
	 *
	 * @param string $key Token, hashed or not.
	 * @return bool Whether a token was destroyed.
	 */
	public function destroy( $key ) {
		$post = $this->find( $key );
		if ( ! $post ) {
			$post = $this->find( $this->hash( $key ) );
		}

		return $post ? (bool) \wp_delete_post( $post->ID, true ) : false;
	}

	/**
	 * Destroys all tokens of the current user.
	 *
	 * @return bool False if there is no user.
	 */
	public function destroy_all() {
		if ( ! $this->user_id ) {
			return false;
		}
		foreach ( $this->query( array( 'fields' => 'ids' ) ) as $post_id ) {
			\wp_delete_post( $post_id, true );
		}
		return true;
	}

	/**
	 * Retrieves all tokens, of the current user or of all users.
	 *
	 * Expired tokens are destroyed on the way.
	 *
	 * @return array Token data, keyed by the hash of the token.
	 */
	public function get_all() {
		$tokens = array();
		foreach ( $this->query() as $post ) {
			$info = $this->read( $post );
			if ( ! $info ) {
				continue;
			}
			if ( $this->has_expired( $info ) ) {
				\wp_delete_post( $post->ID, true );
			} else {
				$tokens[ $post->post_name ] = $info;
			}
		}
		return $tokens;
	}

	/**
	 * Destroys the expired tokens of the current user.
	 *
	 * @return bool Whether a token was destroyed.
	 */
	public function check_expires() {
		if ( ! $this->user_id ) {
			return false;
		}

		$expired = $this->query(
			array(
				'fields'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_indieauth_token_exp',
						'value'   => time(),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		foreach ( $expired as $post_id ) {
			\wp_delete_post( $post_id, true );
		}
		return ! empty( $expired );
	}
}
