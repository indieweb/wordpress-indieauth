<?php
$errors = new WP_Error();
login_header(
	__( 'Authorize', 'indieauth' ),
	'',
	$errors
);
?>
<form method="post" action="<?php echo $url; ?>">
	<div class="login-info">
		<?php if ( ! empty( $client_icon ) ) { ?>
			<img src="<?php echo $client_icon; ?>" height="78" width="78" />
		<?php } ?>
		<?php
			printf(
				'<p>' . __( 'The app <strong>%1$s</strong> would like to access your site, <strong>%2$s</strong> using the credentials of <strong>%3$s</strong> (%4$s).', 'indieauth' ) . '</p>',
				empty( $client_name ) ? $client_id : $client_name,
				get_bloginfo( 'url' ),
				$current_user->display_name,
				$current_user->user_nicename
			);
			echo get_avatar( $current_user->ID, '78' );


		if ( wp_parse_url( $client_id, PHP_URL_HOST ) !== wp_parse_url( $redirect_uri, PHP_URL_HOST ) ) {
		?>
		<p class="redirect">
			<?php _e( '<strong>Warning</strong>: The redirect URL this app is using does not match the domain of the client ID.', 'indieauth' ); ?>
		</p>
		<?php } ?>
	</div>
	<div class="scope-info">
		<?php _e( 'The app is requesting the following <a href="https://indieweb.org/scope">scopes</a>', 'indieauth' ); ?>
		<ul>
		<?php
		if ( ! empty( $scopes ) ) {
			foreach ( $scopes as $s ) {
				printf( '<li><input type="checkbox" name="scope[]" value="%1$s" %2$s /><strong>%1$s</strong> - %3$s</li>', $s, checked( true, true, false ), self::scopes( $s ) );
			}
		}
		?>
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
		<button name="wp-submit" value="authorize" class="button button-primary button-large"><?php _e( 'Authorize', 'indieauth' ); ?></button>
		<a name="wp-submit" value="cancel" class="button button-large" href="<?php echo home_url(); ?>"><?php _e( 'Cancel', 'indieauth' ); ?></a>
	</p>
</form>
<p class="redirect-info"><?php printf( __( 'You will be redirected to <code>%1$s</code> after authorizing this application.', 'indieauth' ), $redirect_uri ); ?></p>
