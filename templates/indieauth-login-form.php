<?php
$errors = new WP_Error();
login_header(
	__( 'Sign in with your website', 'indieauth' ),
	'',
	$errors
);
?>
<form name="loginform" id="loginform" action="<?php add_query_arg( 'action', 'indielogin', wp_login_url() ); ?>" method="post">
<div class="login-info">
<p><?php _e( 'Sign in with your domain', 'indieauth' ); ?></p>
	<input class="input" type="url" name="indieauth_identifier" placeholder="<?php _e( 'https://example.com', 'indieauth' ); ?>" />
</div>
<p class="submit">
<?php
	// Hook to allow adding to form
	do_action( 'indieauth_login_form' );
?>
	<input class="input" type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php _e( 'Sign in', 'indieauth' ); ?>" />
</p>
<a href="https://indieauth.net/" target="_blank"><?php _e( 'Learn about IndieAuth', 'indieauth' ); ?></a>
</form>
