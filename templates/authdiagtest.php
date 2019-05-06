<?php
$errors = new WP_Error();
login_header(
	__( 'Authorization Header Test', 'indieauth' ),
	'',
	$errors
);


$response = wp_remote_post(
	$url,
	array(
		'method'  => 'POST',
		'headers' => array(
			'Authorization' => 'Bearer abc123',
		),
	)
);
if ( ! is_wp_error( $response ) ) {
	echo $response['body'];
}

