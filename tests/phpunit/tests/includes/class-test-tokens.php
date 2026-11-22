<?php
/**
 * Tests for tokens stored as posts.
 *
 * @package IndieAuth
 */

use IndieAuth\Client_Taxonomy;
use IndieAuth\Token\User as Token_User;
use IndieAuth\Tokens;

class Test_Tokens extends WP_UnitTestCase {

	// Tokens must not show up anywhere a post can be read.
	public function test_post_type_is_private() {
		$type = get_post_type_object( Tokens::POST_TYPE );

		$this->assertNotNull( $type );
		$this->assertFalse( $type->public );
		$this->assertFalse( $type->publicly_queryable );
		$this->assertTrue( $type->exclude_from_search );
		$this->assertFalse( $type->show_ui );
		$this->assertFalse( $type->show_in_rest );
		$this->assertFalse( $type->can_export );
		$this->assertFalse( post_type_supports( Tokens::POST_TYPE, 'revisions' ) );
		$this->assertArrayNotHasKey( '/wp/v2/' . Tokens::POST_TYPE, rest_get_server()->get_routes() );
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
			'issued_by' => rest_url( 'indieauth/1.0/token' ),
		);
	}

	public function test_set_and_get() {
		$user_id = self::factory()->user->create();
		$tokens  = new Tokens( 'access', $user_id );
		$data    = $this->token_data();
		$key     = $tokens->set( $data );

		$this->assertSame( 128, strlen( $key ) );
		$this->assertSame( $data + array( 'user' => $user_id ), $tokens->get( $key ) );

		// A store without a user finds the token too, as a request only brings the token.
		$this->assertSame( $data + array( 'user' => $user_id ), ( new Tokens( 'access' ) )->get( $key ) );
	}

	public function test_set_needs_a_user_and_an_array() {
		$this->assertFalse( ( new Tokens( 'access' ) )->set( $this->token_data() ) );
		$this->assertFalse( ( new Tokens( 'access', self::factory()->user->create() ) )->set( 'token' ) );
	}

	// Only the hash of the token is stored, as the post name.
	public function test_token_is_stored_as_a_hash() {
		$user_id = self::factory()->user->create();
		$tokens  = new Tokens( 'access', $user_id );
		$key     = $tokens->set( $this->token_data() );
		$posts   = get_posts(
			array(
				'post_type'   => Tokens::POST_TYPE,
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
		$access  = new Tokens( 'access', $user_id );
		$refresh = new Tokens( 'refresh', $user_id );
		$key     = $access->set( $this->token_data() );

		$this->assertFalse( $refresh->get( $key ) );
		$this->assertFalse( $refresh->destroy( $key ) );
		$this->assertSame( array(), $refresh->get_all() );
		$this->assertCount( 1, $access->get_all() );
	}

	public function test_expired_token_is_destroyed() {
		$user_id = self::factory()->user->create();
		$tokens  = new Tokens( 'access', $user_id );
		$key     = $tokens->set( $this->token_data(), -30 );

		$this->assertFalse( $tokens->get( $key ) );
		$this->assertSame( array(), get_posts( array( 'post_type' => Tokens::POST_TYPE, 'post_status' => 'indieauth_access' ) ) );
	}

	public function test_destroy() {
		$user_id = self::factory()->user->create();
		$tokens  = new Tokens( 'access', $user_id );
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
		$tokens  = new Tokens( 'access', $user_id );
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
		$tokens  = new Tokens( 'access', $user_id );
		$data    = $this->token_data();
		$key     = $tokens->set( $data, 300 );

		$changed = array( 'scope' => 'read' ) + $data;

		$this->assertFalse( ( new Tokens( 'access' ) )->update( $key, $changed ) );
		$this->assertFalse( ( new Tokens( 'access', $other ) )->update( $key, $changed ) );
		$this->assertSame( 'create update', $tokens->get( $key )['scope'] );

		$this->assertTrue( $tokens->update( $key, $changed ) );
		$this->assertSame( $changed + array( 'user' => $user_id ), $tokens->get( $key ) );
	}

	// renew() and noexpire() work on the hash, as the token list has no token.
	public function test_renew_and_noexpire() {
		update_option( 'indieauth_expires_in', 3600 );
		$user_id = self::factory()->user->create();
		$tokens  = new Tokens( 'access', $user_id );
		$key     = $tokens->set( $this->token_data(), 300 );
		$hash    = hash( 'sha256', $key );
		$before  = $tokens->get( $key )['exp'];

		$tokens->renew( $hash );
		$this->assertSame( $before + 3600, $tokens->get( $key )['exp'] );

		// Another user cannot change the lifetime of the token.
		$other = new Tokens( 'access', self::factory()->user->create() );
		$other->renew( $hash );
		$other->noexpire( $hash );
		$this->assertSame( $before + 3600, $tokens->get( $key )['exp'] );

		$tokens->noexpire( $hash );
		$this->assertArrayNotHasKey( 'exp', $tokens->get( $key ) );
		$this->assertSame( array(), get_post_meta( get_posts( array( 'post_type' => Tokens::POST_TYPE, 'post_status' => 'indieauth_access' ) )[0]->ID, '_indieauth_token_exp' ) );
	}

	public function test_get_all_and_destroy_all() {
		$user_id = self::factory()->user->create();
		$other   = self::factory()->user->create();
		$mine    = new Tokens( 'access', $user_id );
		$theirs  = new Tokens( 'access', $other );
		$key     = $mine->set( $this->token_data() );
		$mine->set( $this->token_data(), -30 );
		$theirs->set( $this->token_data() );

		$this->assertSame( array( hash( 'sha256', $key ) ), array_keys( $mine->get_all() ) );
		$this->assertCount( 2, ( new Tokens( 'access' ) )->get_all() );

		$this->assertFalse( ( new Tokens( 'access' ) )->destroy_all() );
		$this->assertTrue( $mine->destroy_all() );
		$this->assertSame( array(), $mine->get_all() );
		$this->assertCount( 1, $theirs->get_all() );
	}

	public function test_check_expires() {
		$user_id = self::factory()->user->create();
		$tokens  = new Tokens( 'access', $user_id );
		$key     = $tokens->set( $this->token_data(), 300 );

		$this->assertFalse( $tokens->check_expires() );

		$tokens->set( $this->token_data(), -30 );
		$this->assertTrue( $tokens->check_expires() );
		$this->assertFalse( $tokens->check_expires() );
		$this->assertIsArray( $tokens->get( $key ) );
	}

	// The client and the uuid can be queried, which user meta could not do.
	public function test_tokens_can_be_found_by_client_and_uuid() {
		$client  = Client_Taxonomy::add_client( 'https://app.example.com/', 'Example App' );
		$user_id = self::factory()->user->create();
		$data    = $this->token_data();

		// The access token knows the term, the refresh token only the URL.
		( new Tokens( 'access', $user_id ) )->set( array( 'client_uid' => $client['id'] ) + $data );
		( new Tokens( 'refresh', $user_id ) )->set( $data );
		( new Tokens( 'access', $user_id ) )->set( array( 'client_id' => 'https://other.example.com/' ) + $this->token_data() );

		$query = array(
			'post_type'   => Tokens::POST_TYPE,
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
		$client  = Client_Taxonomy::add_client( 'https://app.example.com/', 'Example App' );
		$user_id = self::factory()->user->create();
		( new Tokens( 'access', $user_id ) )->set( array( 'client_uid' => $client['id'] ) + $this->token_data() );

		$this->assertSame( array(), get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'author' => $user_id ) ) );
		$this->assertSame( 0, get_term( $client['id'], 'indieauth_client' )->count );
	}

	// Deleting a user can hand their posts to someone else. Tokens must not go along.
	public function test_tokens_are_deleted_before_posts_are_reassigned() {
		$user_id = self::factory()->user->create();
		$heir    = self::factory()->user->create();
		$post_id = self::factory()->post->create( array( 'post_author' => $user_id ) );
		$access  = ( new Tokens( 'access', $user_id ) )->set( $this->token_data() );
		$refresh = ( new Tokens( 'refresh', $user_id ) )->set( $this->token_data() );

		wp_delete_user( $user_id, $heir );

		// The reassign did happen, so the tokens were at risk.
		$this->assertSame( $heir, (int) get_post( $post_id )->post_author );
		$this->assertFalse( ( new Tokens( 'access' ) )->get( $access ) );
		$this->assertFalse( ( new Tokens( 'refresh' ) )->get( $refresh ) );
		$this->assertSame( array(), ( new Tokens( 'access', $heir ) )->get_all() );
	}

	// Nobody may touch a token as a post, or an editor could hand a token to an administrator.
	public function test_no_role_can_edit_tokens_as_posts() {
		$owner   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$tokens  = new Tokens( 'access', $owner );
		$post_id = get_posts(
			array(
				'post_type'   => Tokens::POST_TYPE,
				'post_status' => 'indieauth_access',
				'name'        => hash( 'sha256', $tokens->set( $this->token_data() ) ),
				'fields'      => 'ids',
			)
		)[0];
		$type    = get_post_type_object( Tokens::POST_TYPE );

		foreach ( array( $owner, $admin ) as $user_id ) {
			foreach ( array( 'edit_post', 'delete_post', 'read_post' ) as $cap ) {
				$this->assertFalse( user_can( $user_id, $cap, $post_id ), $cap );
			}
			foreach ( get_object_vars( $type->cap ) as $name => $cap ) {
				$this->assertFalse( user_can( $user_id, $cap ), $name );
			}
		}
	}

	// A post without token data is not a token.
	public function test_post_without_token_data_is_no_token() {
		$user_id = self::factory()->user->create();
		$name    = hash( 'sha256', 'made-up' );
		wp_insert_post(
			array(
				'post_type'   => Tokens::POST_TYPE,
				'post_status' => 'indieauth_access',
				'post_author' => $user_id,
				'post_name'   => $name,
			)
		);
		$tokens = new Tokens( 'access', $user_id );

		$this->assertFalse( $tokens->get( 'made-up' ) );
		$this->assertFalse( $tokens->get( $name, false ) );
		$this->assertSame( array(), $tokens->get_all() );
	}

	// A kind that does not exist must not fall through to the tokens of every kind.
	public function test_unknown_kind_finds_and_stores_nothing() {
		$user_id = self::factory()->user->create();
		$access  = new Tokens( 'access', $user_id );
		$key     = $access->set( $this->token_data(), 300 );
		$unknown = new Tokens( 'code', $user_id );

		$this->assertFalse( $unknown->set( $this->token_data() ) );
		$this->assertFalse( $unknown->get( $key ) );
		$this->assertSame( array(), $unknown->get_all() );
		$this->assertFalse( $unknown->check_expires() );
		$unknown->destroy_all();

		$this->assertIsArray( $access->get( $key ) );
	}

	// Deleting a token removes its link to the client.
	public function test_destroy_removes_the_client_link() {
		global $wpdb;

		$client  = Client_Taxonomy::add_client( 'https://app.example.com/', 'Example App' );
		$user_id = self::factory()->user->create();
		$tokens  = new Tokens( 'access', $user_id );
		$key     = $tokens->set( array( 'client_uid' => $client['id'] ) + $this->token_data() );
		$linked  = "SELECT COUNT(*) FROM $wpdb->term_relationships tr JOIN $wpdb->term_taxonomy tt USING ( term_taxonomy_id ) WHERE tt.term_id = %d";

		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( $linked, $client['id'] ) ) );
		$tokens->destroy( $key );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( $linked, $client['id'] ) ) );
	}

	// Tokens from before 4.1.0 carry their expiry as `expiration`.
	public function test_old_expiration_key_expires_a_token() {
		$user_id = self::factory()->user->create();
		$tokens  = new Tokens( 'access', $user_id );
		$key     = $tokens->set( array( 'expiration' => time() - 30 ) + $this->token_data() );

		$this->assertFalse( $tokens->get( $key ) );
	}

	public function test_update_needs_an_array() {
		$user_id = self::factory()->user->create();
		$tokens  = new Tokens( 'access', $user_id );
		$key     = $tokens->set( $this->token_data() );

		$this->assertFalse( $tokens->update( $key, 'scope' ) );
		$this->assertSame( 'create update', $tokens->get( $key )['scope'] );
	}

	/**
	 * Counts the tokens of a user that are still in user meta.
	 *
	 * @param int    $user_id User ID.
	 * @param string $prefix  Meta key prefix.
	 * @return int Number of tokens.
	 */
	private function count_legacy( $user_id, $prefix = '_indieauth_token_' ) {
		return count( ( new Token_User( $prefix, $user_id ) )->get_all() );
	}

	// A token from before tokens became posts keeps working and is moved when it is used.
	public function test_legacy_token_is_moved_when_it_is_used() {
		$user_id = self::factory()->user->create();
		$data    = $this->token_data();
		$key     = ( new Token_User( '_indieauth_token_', $user_id ) )->set( $data, 300 );
		$tokens  = new Tokens( 'access' );

		$first = $tokens->get( $key );

		$this->assertSame( $data['uuid'], $first['uuid'] );
		$this->assertSame( $user_id, $first['user'] );
		$this->assertSame( 0, $this->count_legacy( $user_id ) );
		$this->assertSame( array( hash( 'sha256', $key ) ), array_keys( ( new Tokens( 'access', $user_id ) )->get_all() ) );

		// The second read comes from the post and returns the same data.
		$this->assertSame( $first, $tokens->get( $key ) );
	}

	public function test_legacy_tokens_keep_their_kind() {
		$user_id = self::factory()->user->create();
		$refresh = ( new Token_User( '_indieauth_refresh_', $user_id ) )->set( $this->token_data(), 300 );
		$code    = ( new Token_User( '_indieauth_code_', $user_id ) )->set( $this->token_data(), 300 );

		$this->assertFalse( ( new Tokens( 'access' ) )->get( $refresh ) );
		$this->assertFalse( ( new Tokens( 'access' ) )->get( $code ) );
		$this->assertFalse( ( new Tokens( 'refresh' ) )->get( $code ) );
		$this->assertSame( 1, $this->count_legacy( $user_id, '_indieauth_refresh_' ) );
		$this->assertSame( 1, $this->count_legacy( $user_id, '_indieauth_code_' ) );

		$this->assertIsArray( ( new Tokens( 'refresh' ) )->get( $refresh ) );
		$this->assertSame( 0, $this->count_legacy( $user_id, '_indieauth_refresh_' ) );
	}

	public function test_expired_legacy_token_is_not_moved() {
		$user_id = self::factory()->user->create();
		$key     = ( new Token_User( '_indieauth_token_', $user_id ) )->set( $this->token_data(), -30 );

		$this->assertFalse( ( new Tokens( 'access' ) )->get( $key ) );
		$this->assertSame( array(), ( new Tokens( 'access', $user_id ) )->get_all() );
	}

	// Listing the tokens moves all of them, for one user or for all.
	public function test_get_all_moves_legacy_tokens() {
		$user_id = self::factory()->user->create();
		$other   = self::factory()->user->create();
		$mine    = ( new Token_User( '_indieauth_token_', $user_id ) )->set( $this->token_data() );
		$theirs  = ( new Token_User( '_indieauth_token_', $other ) )->set( $this->token_data() );

		$this->assertSame( array( hash( 'sha256', $mine ) ), array_keys( ( new Tokens( 'access', $user_id ) )->get_all() ) );
		$this->assertSame( 1, $this->count_legacy( $other ) );

		$this->assertCount( 2, ( new Tokens( 'access' ) )->get_all() );
		$this->assertSame( 0, $this->count_legacy( $other ) );
		$this->assertSame( $other, ( new Tokens( 'access' ) )->get( $theirs )['user'] );
	}

	public function test_destroy_and_revoke_reach_legacy_tokens() {
		$user_id = self::factory()->user->create();
		$legacy  = new Token_User( '_indieauth_token_', $user_id );
		$first   = $legacy->set( $this->token_data() );
		$second  = $legacy->set( $this->token_data() );
		$third   = $legacy->set( $this->token_data() );
		$tokens  = new Tokens( 'access', $user_id );

		$this->assertTrue( $tokens->destroy( $first ) );
		$this->assertFalse( $tokens->get( $first ) );

		$revoked = 0;
		add_action(
			'indieauth_token_revoked',
			function () use ( &$revoked ) {
				$revoked++;
			}
		);
		$this->assertTrue( $tokens->revoke( $second ) );
		$this->assertSame( 1, $revoked );
		$this->assertFalse( $tokens->get( $second ) );

		$this->assertTrue( $tokens->destroy_all() );
		$this->assertFalse( $tokens->get( $third ) );
		$this->assertSame( 0, $this->count_legacy( $user_id ) );
	}

	// A token that was copied but not removed from user meta must not get a second post.
	public function test_legacy_token_is_not_moved_twice() {
		$user_id = self::factory()->user->create();
		$data    = $this->token_data();
		$key     = ( new Token_User( '_indieauth_token_', $user_id ) )->set( $data );
		( new Tokens( 'access' ) )->get( $key );

		// Put the user meta row back, as if removing it had failed.
		add_user_meta( $user_id, '_indieauth_token_' . base64_encode( hash( 'sha256', $key, true ) ), $data, true );

		$this->assertCount( 1, ( new Tokens( 'access', $user_id ) )->get_all() );
		$this->assertSame( 0, $this->count_legacy( $user_id ) );
		$this->assertIsArray( ( new Tokens( 'access' ) )->get( $key ) );
	}

	// A token list that was loaded before the update still sends the old hashes.
	public function test_old_hash_from_the_token_list_still_works() {
		update_option( 'indieauth_expires_in', 3600 );
		$user_id = self::factory()->user->create();
		$legacy  = new Token_User( '_indieauth_token_', $user_id );
		$renew   = $legacy->set( $this->token_data(), 300 );
		$revoke  = $legacy->set( $this->token_data(), 300 );
		$tokens  = new Tokens( 'access', $user_id );
		$before  = $legacy->get( $renew )['exp'];

		$tokens->renew( base64_encode( hash( 'sha256', $renew, true ) ) );
		$this->assertEquals( $before + 3600, $tokens->get( $renew )['exp'] );

		$revoked = 0;
		add_action(
			'indieauth_token_revoked',
			function () use ( &$revoked ) {
				$revoked++;
			}
		);
		$this->assertTrue( $tokens->revoke( base64_encode( hash( 'sha256', $revoke, true ) ) ) );
		$this->assertSame( 1, $revoked );
		$this->assertFalse( $tokens->get( $revoke ) );
	}

	// Tokens from before 4.1.0 use other names for the issue date and the expiry.
	public function test_legacy_token_with_old_field_names() {
		$client  = Client_Taxonomy::add_client( 'https://app.example.com/', 'Example App' );
		$user_id = self::factory()->user->create();
		$key     = ( new Token_User( '_indieauth_token_', $user_id ) )->set(
			array(
				'scope'      => 'create',
				'client_id'  => 'https://app.example.com/',
				'issued_at'  => 1600000000,
				'expiration' => time() + 300,
				'issued_by'  => rest_url( 'indieauth/1.0/token' ),
			)
		);

		$this->assertSame( 'create', ( new Tokens( 'access' ) )->get( $key )['scope'] );

		$posts = get_posts(
			array(
				'post_type'   => Tokens::POST_TYPE,
				'post_status' => 'indieauth_access',
				'author'      => $user_id,
			)
		);
		$this->assertSame( '2020-09-13 12:26:40', $posts[0]->post_date_gmt );
		$this->assertSame( array( $client['id'] ), wp_get_object_terms( $posts[0]->ID, 'indieauth_client', array( 'fields' => 'ids' ) ) );
	}

	// User meta is shared by a network, so a site must not take the tokens of another site.
	public function test_network_site_only_moves_its_own_tokens() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a network.' );
		}

		$user_id = self::factory()->user->create();
		$legacy  = new Token_User( '_indieauth_token_', $user_id );
		$mine    = $legacy->set( array( 'issued_by' => rest_url( 'indieauth/1.0/token' ) ) + $this->token_data() );
		$theirs  = $legacy->set( array( 'issued_by' => 'https://other.example.org/wp-json/indieauth/1.0/token' ) + $this->token_data() );
		$unknown = $legacy->set( array_diff_key( $this->token_data(), array( 'issued_by' => '' ) ) );
		$tokens  = new Tokens( 'access', $user_id );

		// Listing only moves what this site issued.
		$this->assertSame( array( hash( 'sha256', $mine ) ), array_keys( $tokens->get_all() ) );
		$this->assertCount( 2, $legacy->get_all() );

		// Using a token of another site does not work here and leaves it alone.
		$this->assertFalse( $tokens->get( $theirs ) );
		$this->assertIsArray( $legacy->get( $theirs ) );

		// A token that does not say where it is from is moved by the site it is used on.
		$this->assertIsArray( $tokens->get( $unknown ) );

		// Removing the user here keeps the token of the other site.
		$tokens->destroy_all();
		$this->assertSame( array(), $tokens->get_all() );
		$this->assertCount( 1, $legacy->get_all() );
	}
}
