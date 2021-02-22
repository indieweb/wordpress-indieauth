<?php
$errors = new WP_Error();
login_header(
	sprintf( __( 'Authorize %1$s', 'indieauth' ), empty( $client_name ) ? $client_id : $client_name ),
	'',
	$errors
);
?>
<form method="post" action="<?php echo $url; ?>">
	<div class="client-info">
		<?php if ( ! empty( $client_icon ) ) { ?>
			<img src="<?php echo $client_icon; ?>" />
		<?php } ?>
		<?php
			printf(
				__( '<strong>%1$s wants to access your site.</strong>', 'indieauth' ), 
				$client
			);
		?>
		</div>
		<div class="user-info">
		<?php

			echo get_avatar( $current_user->ID, '48' );
			printf( 
				__( 'The app will use credentials of <strong>%1$s</strong> (%2$s). You can revoke access at any time.', 'indieauth' ),
				$current_user->display_name,
				$current_user->user_nicename
			);
		?>

	</div>
	<div class="notices">
		<?php if ( wp_parse_url( $client_id, PHP_URL_HOST ) !== wp_parse_url( $redirect_uri, PHP_URL_HOST ) ) {
		?>
		<p class="redirect">
			<?php _e( '⚠️ <strong>Warning</strong>: The redirect URL this app is using does not match the domain of the client ID.', 'indieauth' ); ?>
		</p>
		<?php } 

		if ( ! is_null( $code_challenge ) && 'S256' === $code_challenge_method ) { 
		?>
		<p class="pkce">
			<?php _e( '🔒 <strong>This app is using <a href="https://indieweb.org/PKCE">PKCE</a> for security.</strong>', 'indieauth' ); ?>
		</p>
		<?php } ?>
	</div>
	<div class="scope-info">
		<?php _e( 'Below select the <a href="https://indieweb.org/scope">privileges</a> you would like to grant the application.', 'indieauth' ); ?>
		<ul>
		<?php self::scope_list( $scopes ); ?>
		</ul>
	</div>
	<p class="submit">
	<?php
		// Hook to allow adding to form
		do_action( 'indieauth_authorization_form', $current_user->user_id, $client_id );
	?>
		<input type="hidden" name="client_id" value="<?php echo $client_id; ?>" />
		<input type="hidden" name="redirect_uri" value="<?php echo $redirect_uri; ?>" />
		<input type="hidden" name="state" value="<?php echo $state; ?>" />
		<input type="hidden" name="me" value="<?php echo $me; ?>" />
		<input type="hidden" name="response_type" value="<?php echo $response_type; ?>" />

		<?php if ( ! is_null( $code_challenge ) ) { ?>
			<input type="hidden" name="code_challenge" value="<?php echo $code_challenge; ?>" />
			<input type="hidden" name="code_challenge_method" value="<?php echo $code_challenge_method; ?>" />
		<?php } ?>
		<button name="wp-submit" value="authorize" class="button button-primary button-large"><?php _e( 'Approve', 'indieauth' ); ?></button>
		<a name="wp-submit" value="cancel" class="button button-large" href="<?php echo home_url(); ?>"><?php _e( 'Cancel', 'indieauth' ); ?></a>
	</p>
</form>
<p class="redirect-info"><?php printf( __( 'You will be redirected to <code>%1$s</code> after approving this application.', 'indieauth' ), $redirect_uri ); ?></p>
