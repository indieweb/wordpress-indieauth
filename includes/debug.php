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
