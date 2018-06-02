<?php
/**
 * Allow localhost URLs if WP_DEBUG is true
 *
 * @param string       $url  The request URL.
 * @param string|array $args Array or string of HTTP request arguments.
 */
function indieauth_allow_localhost( $r, $url ) {
	$r['reject_unsafe_urls'] = false;

	return $r;
}
add_filter( 'http_request_args', 'indieauth_allow_localhost', 10, 2 );

/**
 * Log REST API errors
 *
 * @param WP_REST_Response $result  Result that will be sent to the client.
 * @param WP_REST_Server   $server  The API server instance.
 * @param WP_REST_Request  $request The request used to generate the response.
 */
function log_rest_api_errors( $result, $server, $request ) {
	$request_route = $request->get_route();
	$result_route  = $result->get_matched_route();
	$routes        = array( 'indieauth/1.0/auth', '/indieauth/1.0/token' );
	if ( in_array( $request_route, $routes ) || in_array( $result_route, $routes ) ) {
		return $result;
	}
	if ( $result->is_error() ) {
		$params = $request->get_params();
		if ( isset( $params['code'] ) ) {
			// Remove actual code from logs
			$params['code'] = 'XXXX';
		}
		$data = $result->get_data();
		if ( isset( $data['access_token'] ) ) {
			// Remove actual token from logs
			$data['access_token'] = 'XXXX';
		}
		error_log(
			sprintf(
				'REST request: %s: %s',
				$request->get_route(),
				wp_json_encode( $params )
			)
		);
		error_log(
			sprintf(
				'REST result: %s: %s',
				$result->get_matched_route(),
				wp_json_encode( $data )
			)
		);
	}
	return $result;
}
add_filter( 'rest_post_dispatch', 'log_rest_api_errors', 10, 3 );
