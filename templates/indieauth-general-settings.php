<?php
$authorization = get_indieauth_authorization_endpoint();
$token = get_indieauth_token_endpoint();
$config = get_option( 'indieauth_config' );
$other = ( 'custom' === $config );
if ( ! $other ) {
	$authorization = '';
	$token = '';
}

?>
<fieldset id="indieauth">
	<label for="indieauth_show_login_form">
		<input type="checkbox" name="indieauth_show_login_form" id="indieauth_show_login_form" value="1" <?php
			echo checked( true, get_option( 'indieauth_show_login_form' ) );  ?> />
		<?php _e( 'Add a link to the login form to authenticate using an IndieAuth endpoint.', 'indieauth' ); ?>
	</label>
<br />
	<label for="indieauth_config">
		<input type="radio" name="indieauth_config" id="indieauth_config" value="local" <?php
			checked( $config, 'local' );  ?> />
		<?php _e( 'Local Endpoint', 'indieauth' ); ?><br />
		<input type="radio" name="indieauth_config" id="indieauth_config" value="indieauth" <?php
			checked( $config, 'indieauth' );  ?> />
		<?php _e( 'Indieauth.com', 'indieauth' ); ?><br />
		<input type="radio" name="indieauth_config" id="indieauth_config" value="custom" <?php
			checked( $config, 'custom' );  ?> />
		<?php _e( 'Custom', 'indieauth' ); ?><br />
	</label>
<br />

	<label for="indieauth_authorization_endpoint">
		<br /><?php _e( 'IndieAuth Authorization Endpoint', 'indieauth' ); ?>
		<br />
		<input type="text" name="indieauth_authorization_endpoint" id="indieauth_authorization_endpoint" size="60" value="<?php echo $authorization; ?>" />
	</label>
	<label for="indieauth_token_endpoint">
		<br /><?php _e( 'IndieAuth Token Endpoint', 'indieauth' ); ?>
		<br />
		<input type="text" name="indieauth_token_endpoint" id="indieauth_token_endpoint" size="60" value="<?php echo $token; ?>" />
	</label>
</fieldset>
