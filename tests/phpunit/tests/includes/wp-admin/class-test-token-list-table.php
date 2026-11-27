<?php

use IndieAuth\Token\User as Token_User;
use IndieAuth\WP_Admin\Token_List_Table;

class Test_Token_List_Table extends WP_UnitTestCase {

	// The scope comes from the client and must not be printed as HTML.
	public function test_scope_column_is_escaped() {
		set_current_screen( 'users' );
		$table = new Token_List_Table();
		$this->assertSame(
			'create &lt;img src=x onerror=alert(1)&gt;',
			$table->column_default( array( 'scope' => 'create <img src=x onerror=alert(1)>' ), 'scope' )
		);
	}

	// Discovery that finds nothing must not wipe the name and icon of the token (#278).
	public function test_retrieve_keeps_name_and_icon_when_discovery_finds_nothing() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		set_current_screen( 'users_page_indieauth_user_token' );

		// A manual token, as created on the Manage Tokens page.
		$tokens = new Token_User( '_indieauth_token_', $user_id );
		$token  = $tokens->set(
			array(
				'token_type'  => 'Bearer',
				'scope'       => 'create',
				'me'          => home_url( '/' ),
				'client_id'   => admin_url(),
				'client_name' => 'My Token',
				'client_icon' => 'https://example.org/icon.png',
				'user'        => $user_id,
			)
		);

		$table = new Token_List_Table();
		$args  = new ReflectionProperty( $table, '_args' );
		if ( PHP_VERSION_ID < 80100 ) {
			$args->setAccessible( true );
		}

		$_REQUEST['action'] = 'retrieve';
		// The row action passes the stored, hashed key.
		$_REQUEST['tokens']   = base64_encode( \IndieAuth\indieauth_hash( $token ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'bulk-' . $args->getValue( $table )['plural'] );

		$table->process_action();

		unset( $_REQUEST['action'], $_REQUEST['tokens'], $_REQUEST['_wpnonce'] );

		$stored = $tokens->get( $token );
		$this->assertSame( 'My Token', $stored['client_name'] );
		$this->assertSame( 'https://example.org/icon.png', $stored['client_icon'] );
	}

	// A token revoked by hand in wp-admin fires the same hook as the revocation endpoint.
	public function test_revoke_action_fires_token_revoked() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		set_current_screen( 'users_page_indieauth_user_token' );

		$tokens = new Token_User( '_indieauth_token_', $user_id );
		$token  = $tokens->set(
			array(
				'token_type' => 'Bearer',
				'scope'      => 'create',
				'me'         => home_url( '/' ),
				'client_id'  => 'https://quill.p3k.io/',
				'uuid'       => 'd2a4f9c0-0000-4000-8000-000000000002',
			)
		);

		$calls = array();
		add_action(
			'indieauth_token_revoked',
			function ( $token, $user_id ) use ( &$calls ) {
				$calls[] = compact( 'token', 'user_id' );
			},
			10,
			2
		);

		$table = new Token_List_Table();
		$args  = new ReflectionProperty( $table, '_args' );
		if ( PHP_VERSION_ID < 80100 ) {
			$args->setAccessible( true );
		}

		$_REQUEST['action'] = 'revoke';
		// The row action passes the stored, hashed key.
		$_REQUEST['tokens']   = base64_encode( \IndieAuth\indieauth_hash( $token ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'bulk-' . $args->getValue( $table )['plural'] );

		$table->process_action();

		unset( $_REQUEST['action'], $_REQUEST['tokens'], $_REQUEST['_wpnonce'] );

		$this->assertFalse( $tokens->get( $token ) );
		$this->assertCount( 1, $calls );
		$this->assertSame( $user_id, $calls[0]['user_id'] );
		$this->assertSame( 'd2a4f9c0-0000-4000-8000-000000000002', $calls[0]['token']['uuid'] );
	}

	// Manual tokens have no client to ask, so there is nothing to retrieve (#278).
	public function test_manual_token_has_no_retrieve_action() {
		set_current_screen( 'users_page_indieauth_user_token' );
		$_SERVER['REQUEST_URI'] = '/wp-admin/users.php?page=indieauth_user_token';
		$table                  = new Token_List_Table();

		$manual = $table->column_client_name(
			array(
				'token'       => 'abc',
				'client_id'   => admin_url(),
				'client_name' => 'My Token',
			)
		);
		$client = $table->column_client_name(
			array(
				'token'       => 'def',
				'client_id'   => 'https://quill.p3k.io/',
				'client_name' => 'Quill',
			)
		);

		$this->assertStringNotContainsString( 'Retrieve Information', $manual );
		$this->assertStringContainsString( 'Retrieve Information', $client );
	}
}
