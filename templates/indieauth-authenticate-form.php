<?php
$login_errors = new WP_Error();
login_header(
	/* translators: Client Name or ID */
	sprintf( __( 'Authenticate %1$s', 'indieauth' ), empty( $client_name ) ? esc_url( $client_id ) : $client_name ),
	'',
	$login_errors
);
$user_website = esc_url( get_url_from_user( $current_user->ID ) );
if ( ! $user_website ) {
	__e( 'The application cannot sign you in as WordPress cannot determine the current user', 'indieauth' );
	exit;
}

?>
<form method="post" action="<?php echo esc_url( $url ); ?>">
	<div class="user-info">
		<?php echo get_avatar( $current_user->ID, '48' ); ?>
		<?php
			echo wp_kses(
				sprintf(
					/* translators: 1. Client with link 2. User ID 3. User Display Name 4. User Nicename */
					'<p>' . esc_html__( 'The app %1$s would like to identify you as %2$s, which is user %3$s (%4$s).', 'indieauth' ) . '</p>',
					$client,
					'<strong>' . esc_url( $user_website ) . '</strong>',
					'<strong>' . esc_html( $current_user->display_name ) . '</strong>',
					$current_user->user_nicename
				),
				array(
					'strong' => array(),
					'a'      => array(
						'href' => array(),
					),
				)
			);
			?>
	</div>

	<?php require plugin_dir_path( __FILE__ ) . 'indieauth-notices.php'; ?>
	<?php if ( ! empty( $scopes ) ) { ?>
			<div class="scope-info">
			<?php esc_html_e( 'The app will have no access to your site, but is requesting access to the following information and may request a token to refresh this information in future, which you can revoke at any time:', 'indieauth' ); ?>
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
		<input type="hidden" name="client_id" value="<?php echo esc_url( $client_id ); ?>" />
		<input type="hidden" name="redirect_uri" value="<?php echo esc_url( $redirect_uri ); ?>" />
		<input type="hidden" name="me" value="<?php echo esc_url( $me ); ?>" />
		<input type="hidden" name="response_type" value="<?php echo esc_attr( $response_type ); ?>" />
		<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>" />
		<button name="wp-submit" value="authorize" class="button button-primary button-large"><?php esc_html_e( 'Allow', 'indieauth' ); ?></button>
		<a name="wp-submit" value="cancel" class="button button-large" href="<?php echo esc_url( home_url() ); ?>"><?php esc_html_e( 'Cancel', 'indieauth' ); ?></a>
	</p>
</form>
<?php /* translators: 1. Redirect URI */ ?>
<p class="redirect-info"><?php printf( esc_html__( 'You will be redirected to %1$s after authenticating.', 'indieauth' ), '<code>' . esc_url( $redirect_uri ) . '</code>' ); ?></p>
