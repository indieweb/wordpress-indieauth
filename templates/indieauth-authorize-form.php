<?php
$errors = new WP_Error();
login_header(
	__( 'Authorize', 'indieauth' ),
	'',
	$errors
);
?>
<div class="login-info">
<?php echo get_avatar( $current_user->ID, '78' ); ?>
<?php
	printf(
		'<p>' . __( 'The app <strong>%1$s</strong> would like to access your site, <strong>%2$s</strong> using the credentials of <strong>%3$s</strong> (%4$s).', 'indieauth' ) . '</p>',
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
<p class="redirect-info"><?php printf( __( 'You will be redirected to <code>%1$s</code> after authorizing this application.', 'indieauth' ), $redirect_uri ); ?></p>
