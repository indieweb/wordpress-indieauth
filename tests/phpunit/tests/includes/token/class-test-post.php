<?php
/**
 * Tests for tokens stored as posts.
 *
 * @package IndieAuth
 */

use IndieAuth\Token\Post as Token_Post;

class Test_Token_Post extends WP_UnitTestCase {

	// Tokens must not show up anywhere a post can be read.
	public function test_post_type_is_private() {
		$type = get_post_type_object( Token_Post::POST_TYPE );

		$this->assertNotNull( $type );
		$this->assertFalse( $type->public );
		$this->assertFalse( $type->publicly_queryable );
		$this->assertTrue( $type->exclude_from_search );
		$this->assertFalse( $type->show_ui );
		$this->assertFalse( $type->show_in_rest );
		$this->assertFalse( $type->can_export );
		$this->assertFalse( post_type_supports( Token_Post::POST_TYPE, 'revisions' ) );
		$this->assertArrayNotHasKey( '/wp/v2/' . Token_Post::POST_TYPE, rest_get_server()->get_routes() );
	}

	/**
	 * Token data as the token endpoint stores it.
	 *
	 * @return array Token data.
	 */
	private function token_data() {
		return array(
			'scope'     => 'create update',
			'client_id' => 'https://app.example.com/',
			'me'        => 'https://example.org/author/me/',
			'uuid'      => wp_generate_uuid4(),
			'iat'       => 1700000000,
		);
	}

	public function test_set_and_get() {
		$user_id = self::factory()->user->create();
		$tokens  = new Token_Post( 'access', $user_id );
		$data    = $this->token_data();
		$key     = $tokens->set( $data );

		$this->assertSame( 128, strlen( $key ) );
		$this->assertSame( $data + array( 'user' => $user_id ), $tokens->get( $key ) );

		// A store without a user finds the token too, as a request only brings the token.
		$this->assertSame( $data + array( 'user' => $user_id ), ( new Token_Post( 'access' ) )->get( $key ) );
	}

	public function test_set_needs_a_user_and_an_array() {
		$this->assertFalse( ( new Token_Post( 'access' ) )->set( $this->token_data() ) );
		$this->assertFalse( ( new Token_Post( 'access', self::factory()->user->create() ) )->set( 'token' ) );
	}

	// Only the hash of the token is stored, as the post name.
	public function test_token_is_stored_as_a_hash() {
		$user_id = self::factory()->user->create();
		$tokens  = new Token_Post( 'access', $user_id );
		$key     = $tokens->set( $this->token_data() );
		$posts   = get_posts(
			array(
				'post_type'   => Token_Post::POST_TYPE,
				'post_status' => 'indieauth_access',
				'author'      => $user_id,
			)
		);

		$this->assertCount( 1, $posts );
		$this->assertSame( hash( 'sha256', $key ), $posts[0]->post_name );
		$this->assertSame( '2023-11-14 22:13:20', $posts[0]->post_date_gmt );
		$this->assertStringNotContainsString( $key, wp_json_encode( $posts[0] ) . wp_json_encode( get_post_meta( $posts[0]->ID ) ) );

		// The hash finds the token as well, the token list works with it.
		$this->assertSame( $user_id, $tokens->get( $posts[0]->post_name, false )['user'] );
	}

	public function test_access_and_refresh_tokens_are_kept_apart() {
		$user_id = self::factory()->user->create();
		$access  = new Token_Post( 'access', $user_id );
		$refresh = new Token_Post( 'refresh', $user_id );
		$key     = $access->set( $this->token_data() );

		$this->assertFalse( $refresh->get( $key ) );
		$this->assertFalse( $refresh->destroy( $key ) );
		$this->assertSame( array(), $refresh->get_all() );
		$this->assertCount( 1, $access->get_all() );
	}

	public function test_expired_token_is_destroyed() {
		$user_id = self::factory()->user->create();
		$tokens  = new Token_Post( 'access', $user_id );
		$key     = $tokens->set( $this->token_data(), -30 );

		$this->assertFalse( $tokens->get( $key ) );
		$this->assertSame( array(), get_posts( array( 'post_type' => Token_Post::POST_TYPE, 'post_status' => 'indieauth_access' ) ) );
	}

	public function test_destroy() {
		$user_id = self::factory()->user->create();
		$tokens  = new Token_Post( 'access', $user_id );
		$key     = $tokens->set( $this->token_data(), 300 );
		$hashed  = $tokens->set( $this->token_data(), 300 );

		$this->assertTrue( $tokens->destroy( $key ) );
		$this->assertFalse( $tokens->get( $key ) );
		$this->assertFalse( $tokens->destroy( $key ) );

		// By the hash, as the token list does it.
		$this->assertTrue( $tokens->destroy( hash( 'sha256', $hashed ) ) );
		$this->assertFalse( $tokens->get( $hashed ) );
	}

	public function test_revoke_fires_the_hook() {
		$user_id = self::factory()->user->create();
		$tokens  = new Token_Post( 'access', $user_id );
		$data    = $this->token_data();
		$key     = $tokens->set( $data );

		$calls = array();
		add_action(
			'indieauth_token_revoked',
			function ( $token, $revoked_user ) use ( &$calls ) {
				$calls[] = array( $token['uuid'], $revoked_user );
			},
			10,
			2
		);

		$this->assertTrue( $tokens->revoke( $key ) );
		$this->assertFalse( $tokens->revoke( $key ) );
		$this->assertSame( array( array( $data['uuid'], $user_id ) ), $calls );
	}

	public function test_update_is_limited_to_the_user() {
		$user_id = self::factory()->user->create();
		$other   = self::factory()->user->create();
		$tokens  = new Token_Post( 'access', $user_id );
		$data    = $this->token_data();
		$key     = $tokens->set( $data, 300 );

		$changed = array( 'scope' => 'read' ) + $data;

		$this->assertFalse( ( new Token_Post( 'access' ) )->update( $key, $changed ) );
		$this->assertFalse( ( new Token_Post( 'access', $other ) )->update( $key, $changed ) );
		$this->assertSame( 'create update', $tokens->get( $key )['scope'] );

		$this->assertTrue( $tokens->update( $key, $changed ) );
		$this->assertSame( $changed + array( 'user' => $user_id ), $tokens->get( $key ) );
	}

	// renew() and noexpire() come from the base class and work on the hash.
	public function test_renew_and_noexpire() {
		update_option( 'indieauth_expires_in', 3600 );
		$user_id = self::factory()->user->create();
		$tokens  = new Token_Post( 'access', $user_id );
		$key     = $tokens->set( $this->token_data(), 300 );
		$hash    = hash( 'sha256', $key );
		$before  = $tokens->get( $key )['exp'];

		$tokens->renew( $hash );
		$this->assertSame( $before + 3600, $tokens->get( $key )['exp'] );

		$tokens->noexpire( $hash );
		$this->assertArrayNotHasKey( 'exp', $tokens->get( $key ) );
		$this->assertSame( array(), get_post_meta( get_posts( array( 'post_type' => Token_Post::POST_TYPE, 'post_status' => 'indieauth_access' ) )[0]->ID, '_indieauth_token_exp' ) );
	}

	public function test_get_all_and_destroy_all() {
		$user_id = self::factory()->user->create();
		$other   = self::factory()->user->create();
		$mine    = new Token_Post( 'access', $user_id );
		$theirs  = new Token_Post( 'access', $other );
		$key     = $mine->set( $this->token_data() );
		$mine->set( $this->token_data(), -30 );
		$theirs->set( $this->token_data() );

		$this->assertSame( array( hash( 'sha256', $key ) ), array_keys( $mine->get_all() ) );
		$this->assertCount( 2, ( new Token_Post( 'access' ) )->get_all() );

		$this->assertFalse( ( new Token_Post( 'access' ) )->destroy_all() );
		$this->assertTrue( $mine->destroy_all() );
		$this->assertSame( array(), $mine->get_all() );
		$this->assertCount( 1, $theirs->get_all() );
	}

	public function test_check_expires() {
		$user_id = self::factory()->user->create();
		$tokens  = new Token_Post( 'access', $user_id );
		$key     = $tokens->set( $this->token_data(), 300 );

		$this->assertFalse( $tokens->check_expires() );

		$tokens->set( $this->token_data(), -30 );
		$this->assertTrue( $tokens->check_expires() );
		$this->assertFalse( $tokens->check_expires() );
		$this->assertIsArray( $tokens->get( $key ) );
	}

	// The client and the uuid can be queried, which user meta could not do.
	public function test_tokens_can_be_found_by_client_and_uuid() {
		$client  = IndieAuth\Client_Taxonomy::add_client( 'https://app.example.com/', 'Example App' );
		$user_id = self::factory()->user->create();
		$data    = $this->token_data();

		// The access token knows the term, the refresh token only the URL.
		( new Token_Post( 'access', $user_id ) )->set( array( 'client_uid' => $client['id'] ) + $data );
		( new Token_Post( 'refresh', $user_id ) )->set( $data );
		( new Token_Post( 'access', $user_id ) )->set( array( 'client_id' => 'https://other.example.com/' ) + $this->token_data() );

		$query = array(
			'post_type'   => Token_Post::POST_TYPE,
			'post_status' => array( 'indieauth_access', 'indieauth_refresh' ),
			'fields'      => 'ids',
		);

		$by_client = get_posts(
			$query + array(
				'tax_query' => array(
					array(
						'taxonomy' => 'indieauth_client',
						'terms'    => $client['id'],
					),
				),
			)
		);
		$this->assertCount( 2, $by_client );

		$by_uuid = get_posts(
			$query + array(
				'meta_key'   => '_indieauth_token_uuid',
				'meta_value' => $data['uuid'],
			)
		);
		$this->assertEqualSets( $by_client, $by_uuid );
	}

	// Tokens are no content: not in the search and not counted for the client.
	public function test_tokens_do_not_show_up_as_content() {
		$client  = IndieAuth\Client_Taxonomy::add_client( 'https://app.example.com/', 'Example App' );
		$user_id = self::factory()->user->create();
		( new Token_Post( 'access', $user_id ) )->set( array( 'client_uid' => $client['id'] ) + $this->token_data() );

		$this->assertSame( array(), get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'author' => $user_id ) ) );
		$this->assertSame( 0, get_term( $client['id'], 'indieauth_client' )->count );
	}

	// Deleting a user can hand their posts to someone else. Tokens must not go along.
	public function test_tokens_are_deleted_before_posts_are_reassigned() {
		$user_id = self::factory()->user->create();
		$heir    = self::factory()->user->create();
		$post_id = self::factory()->post->create( array( 'post_author' => $user_id ) );
		$access  = ( new Token_Post( 'access', $user_id ) )->set( $this->token_data() );
		$refresh = ( new Token_Post( 'refresh', $user_id ) )->set( $this->token_data() );

		wp_delete_user( $user_id, $heir );

		// The reassign did happen, so the tokens were at risk.
		$this->assertSame( $heir, (int) get_post( $post_id )->post_author );
		$this->assertFalse( ( new Token_Post( 'access' ) )->get( $access ) );
		$this->assertFalse( ( new Token_Post( 'refresh' ) )->get( $refresh ) );
		$this->assertSame( array(), ( new Token_Post( 'access', $heir ) )->get_all() );
	}
}
