<?php
class TicketEndpointTest extends WP_UnitTestCase {

	public function set_up() {
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server;
		do_action( 'rest_api_init', $wp_rest_server );
		parent::set_up();
	}

	// Form Encoded Request
	public function create_form( $method, $params = array(), $headers = array() ) {
		$request = new WP_REST_Request( $method, '/indieauth/1.0/ticket' );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		if ( ! empty( $params ) ) {
			$request->set_body_params( $params );
		}

		if ( ! empty( $headers ) ) {
			$request->set_headers( $headers );
		}
		return rest_get_server()->dispatch( $request );
	}

	// Checks to ensure that missing parameters will not work.
	public function test_missing_parameters() {
		$response = $this->create_form( 'POST', 
			array( 
				'ticket' => 'xxxx'
			) 
		);
		$response_token = $response->get_data();
		$this->assertEquals( 400, $response->get_status(), 'Response: ' . wp_json_encode( $response ) );
	}
}
