<?php

class WP_OAuth_Response extends WP_REST_Response {

	public function __construct( $error, $error_description, $code = 200, $debug = null ) {
		$this->set_status( $code );
		$this->set_data(
			array(
				'error'             => $error,
				'error_description' => $error_description,
			)
		);
		if ( is_array( $debug ) ) {
			$this->set_debug( $debug );
		}
		if ( WP_DEBUG ) {
			error_log( $this->to_log() ); // phpcs:ignore
		}
	}

	public function set_debug( $array ) {
		$data = $this->get_data();
		$this->set_data( array_merge( $data, $array ) );
	}

	public function to_wp_error() {
		$data   = $this->get_data();
		$status = $this->get_status();
		return new WP_Error(
			$data['error'],
			$data['error_description'],
			array(
				'status' => $status,
				'data'   => $data,
			)
		);
	}

	public function to_log() {
		$data   = $this->get_data();
		$status = $this->get_status();
		return sprintf( 'IndieAuth Error: %1$s %2$s - %3$s %4$s', $status, $data['error'], $data['error_description'], wp_json_encode( $data ) );
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

function is_oauth_error( $obj ) {
	return ( $obj instanceof WP_OAuth_Response );
}
