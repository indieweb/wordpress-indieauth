<?php
$errors = new WP_Error();
login_header(
	__( 'Authenticate', 'indieauth' ),
	'',
	$errors
);
?>
<form method="post" action="<?php echo $url; ?>">
<div class="login-info">
<?php echo get_avatar( $current_user->ID, '78' ); ?>
<?php
	printf(
		'<p>' . __( 'The app <strong>%1$s</strong> would like to sign you in as <strong>%2$s</strong>.', 'indieauth' ) . '</p>',
		$client_id,
		$current_user->user_url
	);
?>
</div>
<p class="submit">
<?php
	// Hook to allow adding to form
	do_action( 'indieauth_authentication_form', $current_user->user_id, $client_id );
?>
	<input type="hidden" name="client_id" value="<?php echo $client_id; ?>" />
	<input type="hidden" name="redirect_uri" value="<?php echo $redirect_uri; ?>" />
	<input type="hidden" name="state" value="<?php echo $state; ?>" />
	<input type="hidden" name="me" value="<?php echo $me; ?>" />
	<input type="hidden" name="response_type" value="<?php echo $response_type; ?>" />
	<button name="wp-submit" value="authorize" class="button button-primary button-large"><?php _e( 'Authenticate', 'indieauth' ); ?></button>
	<a name="wp-submit" value="cancel" class="button button-large" href="<?php echo home_url(); ?>"><?php _e( 'Cancel', 'indieauth' ); ?></a>
</p>
</form>
<p class="redirect-info"><?php printf( __( 'You will be redirected to <code>%1$s</code> after authenticating.', 'indieauth' ), $redirect_uri ); ?></p>
