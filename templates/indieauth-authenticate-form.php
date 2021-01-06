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
				get_url_from_user( $current_user->ID )
				
			);

		if ( wp_parse_url( $client_id, PHP_URL_HOST ) !== wp_parse_url( $redirect_uri, PHP_URL_HOST ) ) {
		?>
		<p class="redirect">
			<?php _e( '<strong>Warning</strong>: The redirect URL this app is using does not match the domain of the client ID.', 'indieauth' ); ?>
		</p>
		<?php } ?>
	</div>
	<div class="scope-info">
		<?php _e( 'In addition, the app is requesting access to additional user profile information', 'indieauth' ); ?>
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
		do_action( 'indieauth_authentication_form', $current_user->ID, $client_id );
	?>
		<input type="hidden" name="client_id" value="<?php echo $client_id; ?>" />
		<input type="hidden" name="redirect_uri" value="<?php echo $redirect_uri; ?>" />
		<input type="hidden" name="me" value="<?php echo $me; ?>" />
		<input type="hidden" name="response_type" value="<?php echo $response_type; ?>" />
		<input type="hidden" name="state" value="<?php echo $state; ?>" />
		<button name="wp-submit" value="authorize" class="button button-primary button-large"><?php _e( 'Authenticate', 'indieauth' ); ?></button>
		<a name="wp-submit" value="cancel" class="button button-large" href="<?php echo home_url(); ?>"><?php _e( 'Cancel', 'indieauth' ); ?></a>
	</p>
</form>
<p class="redirect-info"><?php printf( __( 'You will be redirected to <code>%1$s</code> after authenticating.', 'indieauth' ), $redirect_uri ); ?></p>
