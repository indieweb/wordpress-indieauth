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

	/**
	 * A redirect_uri in the document body must not register a redirect target.
	 *
	 * The mf2 parser collects rels from anywhere in the document. On a client_id
	 * page that renders user content (a bio, a comment, a directory listing),
	 * anyone who can post markup could otherwise nominate where an authorization
	 * code gets delivered.
	 */
	public function test_ignores_redirect_uri_in_document_body() {
		$this->mock_http(
			array( 'content-type' => 'text/html' ),
			'<html><head><title>Example App</title></head><body>'
			. '<a rel="redirect_uri" href="https://evil.example.net/cb">hi</a>'
			. '<link rel="redirect_uri" href="https://evil.example.net/cb2" />'
			. '</body></html>'
		);
		$discovery = new Client_Discovery( 'https://app.example.com/' );
		$discovery->discover();

		$this->assertSame( array(), $discovery->get_redirect_uris() );
	}

	// A redirect_uri published in the head is the supported way to do it.
	public function test_accepts_redirect_uri_from_head() {
		$this->mock_http(
			array( 'content-type' => 'text/html' ),
			'<html><head><title>Example App</title>'
			. '<link rel="redirect_uri" href="https://other.example.net/cb" />'
			. '</head><body></body></html>'
		);
		$discovery = new Client_Discovery( 'https://app.example.com/' );
		$discovery->discover();

		$this->assertContains( 'https://other.example.net/cb', $discovery->get_redirect_uris() );
	}

	// A comma inside a quoted parameter does not separate two links.
	public function test_link_header_comma_inside_quoted_parameter() {
		$this->mock_http(
			array(
				'content-type' => 'text/html',
				'link'         => '<https://other.example.net/cb>; title="Foo, Bar"; rel="redirect_uri"',
			),
			'<html><head><title>Example App</title></head><body></body></html>'
		);
		$discovery = new Client_Discovery( 'https://app.example.com/' );
		$discovery->discover();

		$this->assertContains( 'https://other.example.net/cb', $discovery->get_redirect_uris() );
	}

	/**
	 * A rel must never be read off a neighbouring link.
	 *
	 * Two links in one header value: only the second declares redirect_uri, so
	 * only the second may be accepted.
	 */
	public function test_link_header_does_not_borrow_a_neighbours_rel() {
		$this->mock_http(
			array(
				'content-type' => 'text/html',
				// A repeated Link header arrives as an array, and one element may
				// itself hold several comma-separated links.
				'link'         => array(
					'<https://third.example.net/x>; rel="me"',
					'<https://evil.example.net/cb>; type="text/html", <https://other.example.net/cb>; rel="redirect_uri"',
				),
			),
			'<html><head><title>Example App</title></head><body></body></html>'
		);
		$discovery = new Client_Discovery( 'https://app.example.com/' );
		$discovery->discover();

		$this->assertSame( array( 'https://other.example.net/cb' ), $discovery->get_redirect_uris() );
	}

	// An empty body must not crash the parser.
	public function test_empty_body_does_not_crash() {
		$this->mock_http( array( 'content-type' => 'text/html' ), '' );
		$discovery = new Client_Discovery( 'https://app.example.com/' );
		$discovery->discover();

		$this->assertSame( array(), $discovery->get_redirect_uris() );
		$this->assertFalse( $discovery->is_discovered() );
	}

	// A non-string entry in the published JSON must not raise a warning.
	public function test_ignores_non_string_json_redirect_uris() {
		$this->mock_http(
			array( 'content-type' => 'application/json' ),
			wp_json_encode(
				array(
					'client_id'     => 'https://app.example.com/id',
					'redirect_uris' => array( array( 'https://a.example/cb' ), 'https://b.example/cb', 42 ),
				)
			)
		);
		$discovery = new Client_Discovery( 'https://app.example.com/id' );
		$discovery->discover();

		$this->assertSame( array( 'https://b.example/cb' ), $discovery->get_redirect_uris() );
	}

	// XHTML is still HTML for discovery purposes.
	public function test_parses_xhtml_content_type() {
		$this->mock_http(
			array( 'content-type' => 'application/xhtml+xml' ),
			'<html><head><link rel="redirect_uri" href="https://other.example.net/cb" /></head><body></body></html>'
		);
		$discovery = new Client_Discovery( 'https://app.example.com/' );
		$discovery->discover();

		$this->assertContains( 'https://other.example.net/cb', $discovery->get_redirect_uris() );
	}

	// A failed fetch is not the same answer as "published nothing".
	public function test_failed_fetch_is_not_reported_as_discovered() {
		$this->http_mock = function () {
			return new WP_Error( 'http_request_failed', 'Connection timed out' );
		};
		add_filter( 'pre_http_request', $this->http_mock, 10, 3 );

		$discovery = new Client_Discovery( 'https://app.example.com/' );
		$discovery->discover();

		$this->assertFalse( $discovery->is_discovered() );
		$this->assertSame( array(), $discovery->get_redirect_uris() );
	}
}
