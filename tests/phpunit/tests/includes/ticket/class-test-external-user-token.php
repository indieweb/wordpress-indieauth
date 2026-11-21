<?php

use IndieAuth\Ticket\External_User_Token;

class Test_External_User_Token extends WP_UnitTestCase {

	// The first token is stored at index 0, which must still count as found.
	public function test_update_does_not_duplicate_the_first_token() {
		$user_id = self::factory()->user->create();
		$tokens  = new External_User_Token( $user_id );

		$tokens->update( array( 'access_token' => 'abc' ) );
		$tokens->update(
			array(
				'access_token' => 'abc',
				'scope'        => 'read',
			)
		);

		$stored = get_user_meta( $user_id, 'indieauth_external_tokens', true );
		$this->assertCount( 1, $stored );
		$this->assertSame( 'read', $stored[0]['scope'] );
	}

	// Without stored tokens the user meta is an empty string, not an array.
	public function test_destroy_without_stored_tokens() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$tokens = new External_User_Token( $user_id );

		$this->assertFalse( $tokens->destroy( 'abc', false ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, 'indieauth_external_tokens' ) );
	}

	/**
	 * Calls the protected refresh_token() and returns the body it sent.
	 *
	 * The endpoint is a TEST-NET address, so wp_http_validate_url() accepts it
	 * without a DNS lookup, and the request is answered by pre_http_request.
	 *
	 * @param array $token Stored token data.
	 * @return array|null The request body, or null if no request was sent.
	 */
	protected function capture_refresh_body( $token ) {
		$sent    = null;
		$capture = function ( $preempt, $args ) use ( &$sent ) {
			$sent = $args['body'];
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'access_token' => 'new' ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $capture, 10, 2 );

		$method = new ReflectionMethod( External_User_Token::class, 'refresh_token' );
		$method->setAccessible( true );
		$method->invoke( new External_User_Token( 1 ), $token );

		remove_filter( 'pre_http_request', $capture, 10 );
		return $sent;
	}

	// The client_id the issuer returned is sent back when refreshing. IndieAuth section 5.5.1.
	public function test_refresh_sends_stored_client_id() {
		$body = $this->capture_refresh_body(
			array(
				'access_token'   => 'old',
				'refresh_token'  => 'r1',
				'client_id'      => 'https://bob.example.org/',
				'token_endpoint' => 'https://203.0.113.10/token',
			)
		);

		$this->assertSame( 'refresh_token', $body['grant_type'] );
		$this->assertSame( 'r1', $body['refresh_token'] );
		$this->assertSame( 'https://bob.example.org/', $body['client_id'] );
	}

	// A ticket is redeemed without a client_id, so without one from the issuer none is sent.
	public function test_refresh_omits_unknown_client_id() {
		$body = $this->capture_refresh_body(
			array(
				'access_token'   => 'old',
				'refresh_token'  => 'r1',
				'token_endpoint' => 'https://203.0.113.10/token',
			)
		);

		$this->assertSame( 'r1', $body['refresh_token'] );
		$this->assertArrayNotHasKey( 'client_id', $body );
	}
}
