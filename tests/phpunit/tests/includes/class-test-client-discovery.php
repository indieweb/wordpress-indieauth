<?php

use IndieAuth\Client_Discovery;

class Test_Client_Discovery extends WP_UnitTestCase {

	protected $http_mock;

	public function tear_down() {
		if ( $this->http_mock ) {
			remove_filter( 'pre_http_request', $this->http_mock, 10 );
			$this->http_mock = null;
		}
		parent::tear_down();
	}

	public function mock_http( $headers, $body ) {
		$this->http_mock = function () use ( $headers, $body ) {
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
		};
		add_filter( 'pre_http_request', $this->http_mock, 10, 3 );
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

	public function link_header_rel_provider() {
		return array(
			'single rel'            => array( '<https://other.example.net/cb>; rel="redirect_uri"' ),
			'rel listed first'      => array( '<https://other.example.net/cb>; rel="redirect_uri me"' ),
			'rel listed last'       => array( '<https://other.example.net/cb>; rel="me redirect_uri"' ),
			'rel in the middle'     => array( '<https://other.example.net/cb>; rel="me redirect_uri author"' ),
			'unquoted rel'          => array( '<https://other.example.net/cb>; rel=redirect_uri' ),
			'uppercased rel'        => array( '<https://other.example.net/cb>; rel="ME REDIRECT_URI"' ),
			'other params first'    => array( '<https://other.example.net/cb>; type="text/html"; rel="me redirect_uri"' ),
		);
	}

	/**
	 * A Link header rel is a space-separated list, so redirect_uri does not
	 * have to be the only or the first type listed.
	 *
	 * @dataProvider link_header_rel_provider
	 *
	 * @param string $link The Link header the client publishes.
	 */
	public function test_extracts_redirect_uris_from_multi_token_rel( $link ) {
		$this->mock_http(
			array(
				'content-type' => 'text/html',
				'link'         => $link,
			),
			'<html><head><title>Example App</title></head><body></body></html>'
		);
		$discovery = new Client_Discovery( 'https://app.example.com/' );
		$discovery->discover();

		$this->assertContains( 'https://other.example.net/cb', $discovery->get_redirect_uris() );
	}

	// A rel that merely contains the string must not match.
	public function test_ignores_link_header_with_unrelated_rel() {
		$this->mock_http(
			array(
				'content-type' => 'text/html',
				'link'         => '<https://other.example.net/cb>; rel="my_redirect_uris"',
			),
			'<html><head><title>Example App</title></head><body></body></html>'
		);
		$discovery = new Client_Discovery( 'https://app.example.com/' );
		$discovery->discover();

		$this->assertSame( array(), $discovery->get_redirect_uris() );
	}

	public function content_type_provider() {
		return array(
			'plain media type'    => array( 'application/json' ),
			'with charset'        => array( 'application/json; charset=utf-8' ),
			'with spacing'        => array( 'application/json ;charset=UTF-8' ),
			'uppercased'          => array( 'Application/JSON; charset=utf-8' ),
		);
	}

	/**
	 * Most servers send parameters along with the media type, so the header
	 * cannot be compared to "application/json" as-is.
	 *
	 * @dataProvider content_type_provider
	 *
	 * @param string $content_type The Content-Type header the client sends.
	 */
	public function test_parses_json_metadata_regardless_of_content_type_parameters( $content_type ) {
		$this->mock_http(
			array( 'content-type' => $content_type ),
			wp_json_encode(
				array(
					'client_id'     => 'https://app.example.com/id',
					'client_name'   => 'Example App',
					'redirect_uris' => array( 'https://other.example.net/redirect' ),
				)
			)
		);
		$discovery = new Client_Discovery( 'https://app.example.com/id' );
		$discovery->discover();

		$this->assertEquals( array( 'https://other.example.net/redirect' ), $discovery->get_redirect_uris() );
		$this->assertEquals( 'Example App', $discovery->get_name() );
	}
}
