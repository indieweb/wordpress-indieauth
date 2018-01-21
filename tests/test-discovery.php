<?php
class DiscoveryTest extends WP_UnitTestCase {

	public function headers( $link ) {
		$headers = array(
			'server' => 'nginx/1.9.15',
			'date' => 'Mon, 16 May 2016 01:21:08 GMT',
			'content-type' => 'text/html; charset=UTF-8',
			'link' => $link,
		);
		return $headers;
	}

	public function response( $code, $response ) {
		$response = array(
			'code' => $code,
			'response' => $response,
		);
		return $response;
	}

	public function httpreturn( $headers, $response, $body ) {
		return compact( 'headers', 'response', 'body' );
	}

	public function discover_relative_httplink() {
		$link = array( 
			'</test/1/authorization_endpoint>; rel=authorization_endpoint',
			'</test/1/token_endpoint>; rel=token_endpoint',

		);
		$headers = $this->headers( $link );
		$response = $this->response( 200, 'OK' );
		$body = '<html></html>';
		return $this->httpreturn( $headers, $response, $body );
	}

	public function test_discover_relative_httplink() {
		$url = 'http://www.example.com/test/1';
		$return = array( 
			'authorization_endpoint' => 'http://www.example.com/test/1/authorization_endpoint',
			'token_endpoint' => 'http://www.example.com/test/1/token_endpoint'
		);

		add_filter( 'pre_http_request', array( $this, 'discover_relative_httplink' ) );
		$endpoint = indieauth_discover_endpoint( $url );
		$this->assertSame( $return, $endpoint );
	}

	public function discover_absolute_httplink() {
		$link = array( 
			'<http://www.example.com/test/2/authorization_endpoint>; rel=authorization_endpoint',
			'<http://www.example.com/test/2/token_endpoint>; rel=token_endpoint',

		);
		$headers = $this->headers( $link );
		$response = $this->response( 200, 'OK' );
		$body = '<html></html>';
		return $this->httpreturn( $headers, $response, $body );
	}

	public function test_discover_absolute_httplink() {
		$url = 'http://www.example.com/test/2';
		$return = array( 
			'authorization_endpoint' => 'http://www.example.com/test/2/authorization_endpoint',
			'token_endpoint' => 'http://www.example.com/test/2/token_endpoint'
		);
		add_filter( 'pre_http_request', array( $this, 'discover_absolute_httplink' ) );
		$endpoint = indieauth_discover_endpoint( $url );
		$this->assertSame( $return, $endpoint );
	}


	public function discover_quoted_httplink() {
		$link = array( 
			'<http://www.example.com/test/8/authorization_endpoint>; rel="authorization_endpoint"',
			'<http://www.example.com/test/8/token_endpoint>; rel="token_endpoint"',

		);
		$headers = $this->headers( $link );
		$response = $this->response( 200, 'OK' );
		$body = '<html></html>';
		return $this->httpreturn( $headers, $response, $body );
	}

	public function test_discover_quoted_httplink() {
		$url = 'http://www.example.com/test/2';
		$return = array( 
			'authorization_endpoint' => 'http://www.example.com/test/8/authorization_endpoint',
			'token_endpoint' => 'http://www.example.com/test/8/token_endpoint'
		);
		add_filter( 'pre_http_request', array( $this, 'discover_quoted_httplink' ) );
		$endpoint = indieauth_discover_endpoint( $url );
		$this->assertSame( $return, $endpoint );
	}

	public function discover_multiple_httplink() {
		$link = array( 
			'<http://www.example.com/test/10/authorization_endpoint>; rel="authorization_endpoint somethingelse"',
			'<http://www.example.com/test/10/token_endpoint>; rel="token_endpoint somethingelse"',

		);
		$headers = $this->headers( $link );
		$response = $this->response( 200, 'OK' );
		$body = '<html></html>';
		return $this->httpreturn( $headers, $response, $body );
	}

	public function test_discover_multiple_httplink() {
		$url = 'http://www.example.com/test/10';
		$return = array( 
			'authorization_endpoint' => 'http://www.example.com/test/10/authorization_endpoint',
			'token_endpoint' => 'http://www.example.com/test/10/token_endpoint'
		);
		add_filter( 'pre_http_request', array( $this, 'discover_multiple_httplink' ) );
		$endpoint = indieauth_discover_endpoint( $url );
		$this->assertSame( $return, $endpoint );
	}

	public function discover_relative_htmllink() {
		$headers = $this->headers( array() );
		$response = $this->response( 200, 'OK' );

		$body = '<!DOCTYPE html><html lang="en"><head><link rel="authorization_endpoint" href="/test/3/authorization_endpoint">';
		$body .= '<link rel="token_endpoint" href="/test/3/token_endpoint"></head><body>This is a test</body></html>';
		return $this->httpreturn( $headers, $response, $body );
	}

	public function test_discover_relative_htmllink() {
		$url = 'http://www.example.com/test/3';
		$return = array( 
			'authorization_endpoint' => 'http://www.example.com/test/3/authorization_endpoint',
			'token_endpoint' => 'http://www.example.com/test/3/token_endpoint'
		);
		add_filter( 'pre_http_request', array( $this, 'discover_relative_htmllink' ) );
		$endpoint = indieauth_discover_endpoint( $url );
		$this->assertSame( $return, $endpoint );
	}

	public function discover_absolute_htmllink() {
		$headers = $this->headers( array() );
		$response = $this->response( 200, 'OK' );
		$body = '<!DOCTYPE html><html lang="en"><head><link rel="authorization_endpoint" href="http://www.example.com/test/4/authorization_endpoint">';
		$body .= '<link rel="token_endpoint" href="http://www.example.com/test/4/token_endpoint"></head><body>This is a test</body></html>';
		return $this->httpreturn( $headers, $response, $body );
	}

	public function test_discover_absolute_htmllink() {
		$url = 'http://www.example.com/test/4';
		$return = array( 
			'authorization_endpoint' => 'http://www.example.com/test/4/authorization_endpoint',
			'token_endpoint' => 'http://www.example.com/test/4/token_endpoint'
		);
		add_filter( 'pre_http_request', array( $this, 'discover_absolute_htmllink' ) );
		$endpoint = indieauth_discover_endpoint( $url );
		$this->assertSame( $return, $endpoint );
	}

	public function discover_redirect() {
		$link = array( 
			'<https://www.example.com/test/2/authorization_endpoint>; rel=authorization_endpoint',
			'<https://www.example.com/test/2/token_endpoint>; rel=token_endpoint',

		);
		$headers = $this->headers( $link );
		$headers['Location'] = 'https://www.example.com/test/2';
		$response = $this->response( 301, 'Permanent Redirect' );
		$body = '<html></html>';
		return $this->httpreturn( $headers, $response, $body );
	}

	public function test_discover_redirect() {
		$url = 'http://www.example.com/test/2';
		$return = array( 
			'me' => 'https://www.example.com/test/2',
			'authorization_endpoint' => 'https://www.example.com/test/2/authorization_endpoint',
			'token_endpoint' => 'https://www.example.com/test/2/token_endpoint'
		);
		add_filter( 'pre_http_request', array( $this, 'discover_redirect' ) );
		$endpoint = indieauth_discover_endpoint( $url );
		$this->assertSame( $return, $endpoint );
	}




}
