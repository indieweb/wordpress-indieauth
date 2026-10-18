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

	// The constructor skips fetching under unit tests, so run the parser directly.
	public function discover( $client_id ) {
		$discovery = new Client_Discovery( $client_id );
		$parse     = new ReflectionMethod( $discovery, 'parse' );
		$parse->setAccessible( true );
		$parse->invoke( $discovery, $client_id );

		return $discovery;
	}

	public function test_reads_name_from_h_app() {
		$this->mock_http(
			array( 'content-type' => 'text/html' ),
			'<html><head><title>Page Title</title></head><body><div class="h-app"><a class="p-name u-url" href="/">My App</a></div></body></html>'
		);
		$discovery = $this->discover( 'https://app.example.com/' );

		$this->assertSame( 'My App', $discovery->get_name() );
	}

	public function test_falls_back_to_html_title() {
		$this->mock_http(
			array( 'content-type' => 'text/html' ),
			'<html><head><title>Page Title</title></head><body><p>No h-app here.</p></body></html>'
		);
		$discovery = $this->discover( 'https://app.example.com/' );

		$this->assertSame( 'Page Title', $discovery->get_name() );
	}

	public function test_page_without_title_or_icon_does_not_crash() {
		$this->mock_http(
			array( 'content-type' => 'text/html' ),
			'<html><head></head><body><p>Nothing to see.</p></body></html>'
		);
		$discovery = $this->discover( 'https://app.example.com/' );

		$this->assertEmpty( $discovery->get_name() );
		$this->assertSame( '', $discovery->get_icon() );
	}
}
