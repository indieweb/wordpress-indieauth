<?php
$errors = new WP_Error();
login_header(
	sprintf( __( 'Authenticate %1$s', 'indieauth' ), empty( $client_name ) ? $client_id : $client_name ),
	'',
	$errors
);
$user_id = get_url_from_user( $current_user->ID );
if ( ! $user_id ) {
	__e( 'The application cannot sign you in as WordPress cannot determine the current user', 'indieauth' );
	exit;
}
	
?>
<form method="post" action="<?php echo $url; ?>">
	<div class="user-info">
		<?php echo get_avatar( $current_user->ID, '48' ); ?>
		<?php
			printf(
				'<p>' . __( 'The app <strong>%1$s</strong> would like to identify you as <strong>%2$s</strong>, which is user %3$s(%4$s).', 'indieauth' ) . '</p>',
				$client,
				$user_id,
				$current_user->display_name,
				$current_user->user_nicename
				
			);
		?>
	</div>
	<div class="notices">
		<?php if ( wp_parse_url( $client_id, PHP_URL_HOST ) !== wp_parse_url( $redirect_uri, PHP_URL_HOST ) ) {
		?>
		<p class="redirect">
			<?php _e( '⚠️  <strong>Warning</strong>: The redirect URL this app is using does not match the domain of the client ID.', 'indieauth' ); ?>
		</p>
		<?php } 

		if ( ! is_null( $code_challenge ) && 'S256' === $code_challenge_method ) { 
		?>
		<p class="pkce">
			<?php _e( '🔒 <strong>This app is using <a href="https://indieweb.org/PKCE">PKCE</a> for security.</strong>', 'indieauth' ); ?>
		</p>
		<?php } ?>
	</div>
	<?php if ( ! empty( $scopes ) ) { ?>
			<div class="scope-info">
			<?php _e( 'The app will have no access to your site, but is requesting access to the following information:', 'indieauth' ); ?>
			<ul>
			<?php self::scope_list( $scopes ); ?>
			</ul>
		</div>
	<?php } ?>
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
		<button name="wp-submit" value="authorize" class="button button-primary button-large"><?php _e( 'Allow', 'indieauth' ); ?></button>
		<a name="wp-submit" value="cancel" class="button button-large" href="<?php echo home_url(); ?>"><?php _e( 'Cancel', 'indieauth' ); ?></a>
	</p>
</form>
<p class="redirect-info"><?php printf( __( 'You will be redirected to <code>%1$s</code> after authenticating.', 'indieauth' ), $redirect_uri ); ?></p>
