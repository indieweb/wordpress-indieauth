<?php
$errors = new WP_Error();
login_header(
	__( 'Authenticate', 'indieauth' ),
	'',
	$errors
);
?>
<div class="login-info">
<?php echo get_avatar( $current_user->ID, '78' ); ?>
<?php
	printf(
		'<p>' . __( 'The app <strong>%1$s</strong> would like to sign you in using the credentials of <strong>%2$s</strong>.', 'indieauth' ) . '</p>',
		$client_id,
		$current_user->user_url
	);
?>
</div>
<br />

<p class="submit">
		<a name="wp-submit" value="authorize" class="button button-primary button-large" href="<?php echo $url; ?>"><?php _e( 'Authenticate', 'indieauth' ); ?></a>
		<a name="wp-submit" value="cancel" class="button button-large" href="<?php echo home_url(); ?>"><?php _e( 'Cancel', 'indieauth' ); ?></a>
</p>
<p class="redirect-info"><?php printf( __( 'You will be redirected to <code>%1$s</code> after authenticating.', 'indieauth' ), $redirect_uri ); ?></p>
