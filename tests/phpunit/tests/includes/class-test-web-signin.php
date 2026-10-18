<?php

use IndieAuth\Web_Signin;

class Test_Web_Signin extends WP_UnitTestCase {

	// Pages of this site can carry links set by any author, so they must not pick the authorization server.
	public function test_redirect_rejects_own_host() {
		// The test site runs on a port, which is not allowed in an identifier, so only the host is taken from it.
		$host       = wp_parse_url( home_url(), PHP_URL_HOST );
		$web_signin = new Web_Signin();
		$result     = $web_signin->websignin_redirect( 'https://' . $host . '/?x=/author/admin/', wp_login_url() );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'username and password', $result->get_error_message() );

		$result = $web_signin->websignin_redirect( 'https://' . $host . '/', wp_login_url() );
		$this->assertStringContainsString( 'username and password', $result->get_error_message() );
	}
}
