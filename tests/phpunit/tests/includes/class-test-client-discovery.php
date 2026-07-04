<?php

use IndieAuth\Client_Discovery;

class Test_Client_Discovery extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function mock_http( $headers, $body ) {
		add_filter(
			'pre_http_request',
			function () use ( $headers, $body ) {
				return array(
					'headers'  => $headers,
					'body'     => $body,
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	public function test_extracts_redirect_uris_from_json_metadata() {
		$this->mock_http(
			array( 'content-type' => 'application/json' ),
			wp_json_encode(
				array(
					'client_id'     => 'https://app.example.com/id',
					'client_name'   => 'Example App',
					'redirect_uris' => array( 'https://other.example.net/redirect', 'https://app.example.com/callback' ),
				)
			)
		);
		$discovery = new Client_Discovery( 'https://app.example.com/id' );
		$discovery->discover();
		$this->assertEquals(
			array( 'https://other.example.net/redirect', 'https://app.example.com/callback' ),
			$discovery->get_redirect_uris()
		);
	}

	public function test_extracts_redirect_uris_from_html_links() {
		$this->mock_http(
			array( 'content-type' => 'text/html' ),
			'<html><head><title>Example App</title><link rel="redirect_uri" href="/callback" /><link rel="redirect_uri" href="https://other.example.net/redirect" /></head><body></body></html>'
		);
		$discovery = new Client_Discovery( 'https://app.example.com/' );
		$discovery->discover();
		$this->assertEquals(
			array( 'https://app.example.com/callback', 'https://other.example.net/redirect' ),
			$discovery->get_redirect_uris()
		);
	}

	public function test_extracts_redirect_uris_from_link_headers() {
		$this->mock_http(
			array(
				'content-type' => 'text/html',
				'link'         => '<https://other.example.net/cb>; rel="redirect_uri"',
			),
			'<html><head><title>Example App</title></head><body></body></html>'
		);
		$discovery = new Client_Discovery( 'https://app.example.com/' );
		$discovery->discover();
		$this->assertContains( 'https://other.example.net/cb', $discovery->get_redirect_uris() );
	}

	public function test_returns_empty_redirect_uris_when_none_published() {
		$this->mock_http(
			array( 'content-type' => 'text/html' ),
			'<html><head><title>Example App</title></head><body></body></html>'
		);
		$discovery = new Client_Discovery( 'https://app.example.com/' );
		$discovery->discover();
		$this->assertSame( array(), $discovery->get_redirect_uris() );
	}
}
