<?php

class WP_OAuth_Response extends WP_REST_Response {

	public function __construct( $error, $error_description, $code = 200 ) {
		$this->set_status( $code );
		$this->set_data(
			array(
				'error'             => $error,
				'error_description' => $error_description,
			)
		);
	}

	public function set_debug( $array ) {
		$data = $this->get_data();
		$this->set_data( array_merge( $data, $array ) );
	}

}

function get_oauth_error( $obj ) {
	if ( is_array( $obj ) ) {
		// When checking the result of wp_remote_post
		if ( isset( $obj['body'] ) ) {
			$body = json_decode( $obj['body'], true );
			if ( isset( $body['error'] ) ) {
				return new WP_OAuth_Response(
					$body['error'],
					isset( $body['error_description'] ) ? $body['error_description'] : null,
					$obj['response']['code']
				);
			}
		}
	} elseif ( is_object( $obj ) && 'WP_OAuth_Response' === get_class( $obj ) ) {
		$data = $obj->get_data();
		if ( isset( $data['error'] ) ) {
			return $obj;
		}
	}
	return false;
}
