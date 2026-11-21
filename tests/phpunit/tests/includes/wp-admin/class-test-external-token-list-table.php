<?php

use IndieAuth\Ticket\External_User_Token;
use IndieAuth\WP_Admin\External_Token_List_Table;

class Test_External_Token_List_Table extends WP_UnitTestCase {

	/**
	 * Requests sent to the token endpoint.
	 *
	 * @var array
	 */
	protected $requests = array();

	public function tear_down() {
		unset( $_REQUEST['action'], $_REQUEST['tokens'] );
		parent::tear_down();
	}

	/**
	 * Run the Verify bulk action for one stored token and return the output.
	 *
	 * @param int $code Status code the token endpoint answers with.
	 * @return string The printed notices.
	 */
	protected function verify( $code ) {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		set_current_screen( 'users_page_indieauth_external_token' );

		$tokens = new External_User_Token( $user_id );
		$tokens->update(
			array(
				'access_token'   => 'abc',
				'resource'       => 'https://example.com/private/',
				'token_endpoint' => 'https://example.com/token',
			)
		);

		add_filter(
			'pre_http_request',
			function ( $response, $args, $url ) use ( $code ) {
				$this->requests[] = compact( 'args', 'url' );
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'me' => 'https://example.com/' ) ),
					'response' => array( 'code' => $code ),
				);
			},
			10,
			3
		);

		$_REQUEST['action'] = 'verify';
		$_REQUEST['tokens'] = array( 'abc' );

		ob_start();
		( new External_Token_List_Table() )->process_action();
		return ob_get_clean();
	}

	// The action gets a list of tokens and has to verify each of them.
	public function test_verify_action_reports_a_valid_token() {
		$output = $this->verify( 200 );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://example.com/token', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer abc', $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertStringContainsString( 'notice-success', $output );
		$this->assertStringContainsString( 'The token for example.com is valid.', $output );
	}

	public function test_verify_action_reports_a_rejected_token() {
		$output = $this->verify( 401 );

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'The token for example.com could not be verified.', $output );
	}
}
