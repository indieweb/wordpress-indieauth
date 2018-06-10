<?php
class AuthenticateTest extends WP_UnitTestCase {

	public function headers() {
		$headers = array(
			'server'       => 'nginx/1.9.15',
			'date'         => 'Mon, 16 May 2016 01:21:08 GMT',
			'content-type' => 'application/json; charset=UTF-8',
		);
		return $headers;
	}

	public function response( $code, $response ) {
		$response = array(
			'code'     => $code,
			'response' => $response,
		);
		return $response;
	}

	public function httpreturn( $headers, $response, $body ) {
		return compact( 'headers', 'response', 'body' );
	}

	public function verification_token() {
		return array(
			'me'        => 'http://example.com/',
			'issued_by' => 'https://tokens.example.com/token',
			'client_id' => 'http://example.com/',
			'issued_at' => 1514701054,
			'scope'     => 'create update',
			'nonce'     => 161808633,
		);
	}


	public function verification_token_response() {
		$headers  = $this->headers();
		$response = $this->response( 200, 'OK' );
		$body     = wp_json_encode( $this->verification_token() );
		return $this->httpreturn( $headers, $response, $body );
	}

	public function test_verify_remote_access_token() {
		update_option( 'indieauth_config', 'indieauth' );
		$authenticate = new IndieAuth_Authorize();
		add_filter( 'pre_http_request', array( $this, 'verification_token_response' ) );
		$me = $authenticate->verify_access_token( 'test' );
		$this->assertEquals( 'http://example.com/', $me );
	}
}
