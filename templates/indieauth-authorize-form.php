<?php
$errors = new WP_Error();
login_header(
	__( 'Authorize', 'indieauth' ),
	'',
	$errors
);
$current_user = wp_get_current_user();
$client_id    = wp_unslash( $_GET['client_id'] ); // WPCS: CSRF OK
$redirect_uri = isset( $_GET['redirect_to'] ) ? wp_unslash( $_GET['redirect_to'] ) : null;
$scope        = isset( $_GET['scope'] ) ? wp_unslash( $_GET['scope'] ) : null;
$state        = isset( $_GET['state'] ) ? wp_unslash( $_GET['state'] ) : null;
$me           = isset( $_GET['me'] ) ? wp_unslash( $_GET['me'] ) : null;
$token        = compact( 'client_id', 'redirect_uri', 'scope', 'me' );
$code         = IndieAuth_Authorization_Endpoint::set_code( $current_user->ID, $token );
$url          = add_query_arg(
	array(
		'code'  => $code,
		'state' => $state,
	),
	$redirect_uri
);
?>
<div class="login-info">
<?php echo get_avatar( $current_user->ID, '78' ); ?>
<?php
	printf(
		'<p>' . __( 'The app <strong>%1$s</strong> would like to access your site, <strong>%2$s</strong> using the credentials of <strong>%3$s</strong>(%4$s).', 'indieauth' ) . '</p>',
		$client_id,
		get_bloginfo( 'url' ),
		$current_user->display_name,
		$current_user->user_nicename
	);
?>
</div>
<br />
<div class="scope-info">
	<?php _e( 'The app is requesting the following <a href="https://indieweb.org/scope">scopes</a>', 'indieauth' ); ?>
	<ul>
	<?php
	foreach ( explode( ' ', $scope ) as $s ) {
		printf( '<li>%1$s</li>', $s );
	}
	?>
	</ul>
</div>
<p class="submit">
		<a name="wp-submit" value="authorize" class="button button-primary button-large" href="<?php echo $url; ?>"><?php _e( 'Authorize', 'indieauth' ); ?></a>
		<a name="wp-submit" value="cancel" class="button button-large" href="<?php echo home_url(); ?>"><?php _e( 'Cancel', 'indieauth' ); ?></a>
</p>
<?php printf( __( 'You will be redirected to %1$s after authorizing this application.', 'indieauth' ), $redirect_uri ); ?>
<?php
login_footer();
