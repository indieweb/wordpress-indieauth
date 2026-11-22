<?php
/**
 * Tokens class file.
 *
 * @package IndieAuth
 */

namespace IndieAuth;

use IndieAuth\Token\User as Token_User;

/**
 * The access tokens and refresh tokens of the site.
 *
 * Stored as posts of a private post type, one post per token. The post name
 * is the hash of the token, the author is the user the token belongs to, and
 * the status tells access tokens and refresh tokens apart.
 */
class Tokens {

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
	 * The user meta prefixes tokens were stored under before they became posts.
	 *
	 * @var string[]
	 */
	const LEGACY_PREFIXES = array(
		'access'  => '_indieauth_token_',
		'refresh' => '_indieauth_refresh_',
	);

	/**
	 * Kind of token, `access` or `refresh`.
	 *
	 * @var string
	 */
	private $type;

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
		$this->type    = $type;
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
	private function hash( $token_string ) {
		return bin2hex( indieauth_hash( $token_string ) );
	}

	/**
	 * Turns a stored hash into the post name of the token.
	 *
	 * @param string $key Hash of a token, as hex or in the base64 form user meta used.
	 * @return string The hash as hex, or the key itself if it is no hash.
	 */
	private function name( $key ) {
		$binary = is_string( $key ) ? base64_decode( $key, true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		return false !== $binary && 32 === strlen( $binary ) ? bin2hex( $binary ) : $key;
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
		foreach ( array( 'exp', 'expiration' ) as $field ) {
			if ( isset( $info[ $field ] ) && is_numeric( $info[ $field ] ) && $info[ $field ] <= time() ) {
				return true;
			}
		}
		return false;
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
			$info['exp'] = time() + (int) $expiration;
		}

		$key = \wp_generate_password( 128, false );

		return $this->insert( $this->hash( $key ), $this->user_id, $info ) ? $key : false;
	}

	/**
	 * Creates the post of a token.
	 *
	 * @param string $name    Hash of the token.
	 * @param int    $user_id The user the token belongs to.
	 * @param array  $info    Token data.
	 * @return bool Whether the post was created.
	 */
	private function insert( $name, $user_id, $info ) {
		// `issued_at` is the name tokens used before 4.1.0.
		$issued = isset( $info['iat'] ) ? $info['iat'] : ( isset( $info['issued_at'] ) ? $info['issued_at'] : null );

		$post_id = \wp_insert_post(
			array(
				'post_type'     => self::POST_TYPE,
				'post_status'   => $this->status,
				'post_author'   => $user_id,
				'post_name'     => $name,
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', is_numeric( $issued ) ? (int) $issued : time() ),
			)
		);
		if ( ! $post_id ) {
			return false;
		}
		// WordPress changes the name if a post with it exists already, and a token
		// under another name can never be found.
		if ( \get_post_field( 'post_name', $post_id ) !== $name ) {
			\wp_delete_post( $post_id, true );
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

		return true;
	}

	/**
	 * The store tokens were kept in before they became posts.
	 *
	 * @return Token_User|null The user meta store, or null for an unknown kind of token.
	 */
	private function legacy() {
		if ( ! $this->is_ready() || ! isset( self::LEGACY_PREFIXES[ $this->type ] ) ) {
			return null;
		}

		return new Token_User( self::LEGACY_PREFIXES[ $this->type ], $this->user_id );
	}

	/**
	 * Checks if a token from user meta may be moved to this site.
	 *
	 * User meta is shared by all sites of a network, posts are not. So on a
	 * network a token is only moved by the site that issued it.
	 *
	 * @param array $info   Token data.
	 * @param bool  $in_use Whether the token is being used right now. A token that does
	 *                      not say where it was issued is only moved by the site it is used on.
	 * @return bool Whether the token belongs to this site.
	 */
	private function belongs_here( $info, $in_use ) {
		if ( ! \is_multisite() ) {
			return true;
		}
		if ( empty( $info['issued_by'] ) ) {
			return $in_use;
		}

		return \rest_url( 'indieauth/1.0/token' ) === $info['issued_by'];
	}

	/**
	 * Moves a token from user meta to a post.
	 *
	 * @param string $legacy_key The hash the token is stored under in user meta, base64.
	 * @param array  $info       Token data, with the user it belongs to.
	 * @param bool   $in_use     Whether the token is being used right now.
	 * @return array|false Token data, or false if the token was not moved.
	 */
	private function import( $legacy_key, $info, $in_use = false ) {
		$name = bin2hex( (string) base64_decode( $legacy_key, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( empty( $info['user'] ) || 64 !== strlen( $name ) || ! $this->belongs_here( $info, $in_use ) ) {
			return false;
		}
		// The post can exist already: the user meta could not be removed last
		// time, or another request is moving the token right now.
		if ( ! $this->find( $name ) && ! $this->insert( $name, (int) $info['user'], $info ) && ! $this->find( $name ) ) {
			return false;
		}

		$legacy = new Token_User( self::LEGACY_PREFIXES[ $this->type ], (int) $info['user'] );
		$legacy->destroy( $legacy_key );

		$info['user'] = (int) $info['user'];
		return $info;
	}

	/**
	 * Moves all tokens that are still in user meta to posts.
	 *
	 * For the current user, or for all users if there is none.
	 */
	private function import_all() {
		$legacy = $this->legacy();
		if ( ! $legacy ) {
			return;
		}
		foreach ( $legacy->get_all() as $legacy_key => $info ) {
			$this->import( $legacy_key, $info );
		}
	}

	/**
	 * Retrieves a token.
	 *
	 * @param string $key  Token.
	 * @param bool   $hash If the token still has to be hashed.
	 * @return array|false Token data, or false if there is no such token.
	 */
	public function get( $key, $hash = true ) {
		$post = $this->find( $hash ? $this->hash( $key ) : $this->name( $key ) );
		if ( ! $post ) {
			// A token from before tokens became posts is moved over when it is used.
			// Without hashing, the key is the hash a token list from before the update sent.
			$legacy = $this->legacy();
			$info   = $legacy ? $legacy->get( $key, $hash ) : false;

			return $info ? $this->import( $hash ? base64_encode( indieauth_hash( $key ) ) : $key, $info, true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
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

		$post = $this->find( $hash ? $this->name( $key ) : $this->hash( $key ) );
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
		$post = $this->find( $this->name( $key ) );
		if ( ! $post ) {
			$post = $this->find( $this->hash( $key ) );
		}

		if ( $post ) {
			return (bool) \wp_delete_post( $post->ID, true );
		}

		// It may still be in user meta.
		$legacy = $this->legacy();
		return $legacy ? (bool) $legacy->destroy( $key ) : false;
	}

	/**
	 * Revokes a token.
	 *
	 * Like destroy(), but for a token that is taken away by a client or a user.
	 * A rotated refresh token or an expired token is not revoked, those go
	 * through destroy().
	 *
	 * @param string $key Token, hashed or not.
	 * @return bool Whether a token was revoked.
	 */
	public function revoke( $key ) {
		$token = $this->get( $key, false );
		if ( ! $token ) {
			$token = $this->get( $key );
		}

		$destroyed = $this->destroy( $key );

		if ( $token && $destroyed ) {
			/**
			 * Fires when an access or refresh token was revoked.
			 *
			 * @param array $token   The data stored with the token, without the token value.
			 * @param int   $user_id The user the token belonged to.
			 */
			\do_action( 'indieauth_token_revoked', $token, (int) $token['user'] );
		}

		return $destroyed;
	}

	/**
	 * Extends the lifetime of a token by the default lifetime.
	 *
	 * @param string $key Hash of the token.
	 */
	public function renew( $key ) {
		$token   = $this->get( $key, false );
		$expires = (int) \get_option( 'indieauth_expires_in' );
		// Tokens do not expire if the default lifetime is 0.
		if ( ! $token || 0 === $expires ) {
			return;
		}

		// `expiration` is the name tokens used before 4.1.0.
		if ( isset( $token['expiration'] ) ) {
			$token['exp'] = $token['expiration'];
			unset( $token['expiration'] );
		}
		$token['exp'] = ( isset( $token['exp'] ) ? (int) $token['exp'] : time() ) + $expires;

		$this->update( $key, $token, true );
	}

	/**
	 * Removes the expiry of a token.
	 *
	 * @param string $key Hash of the token.
	 */
	public function noexpire( $key ) {
		$token = $this->get( $key, false );
		if ( ! $token ) {
			return;
		}

		unset( $token['exp'], $token['expiration'], $token['expires_in'] );

		$this->update( $key, $token, true );
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
		// The ones still in user meta go too, unless another site of the network issued them.
		$legacy = $this->legacy();
		foreach ( $legacy ? $legacy->get_all() : array() as $legacy_key => $info ) {
			if ( $this->belongs_here( $info, true ) ) {
				$legacy->destroy( $legacy_key );
			}
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
		$this->import_all();

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
