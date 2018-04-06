<fieldset id="indieauth">
	<label for="indieauth_show_login_form">
		<input type="checkbox" name="indieauth_show_login_form" id="indieauth_show_login_form" value="1" <?php
			echo checked( true, get_option( 'indieauth_show_login_form' ) );  ?> />
		<?php _e( 'Add a link to the login form to authenticate using an IndieAuth endpoint.', 'indieauth' ); ?>
	</label>
	<BR />
<?php
$authorization = get_option( 'indieauth_authorization_endpoint' );
$other         = ( rest_url( 'indieauth/1.0/auth' ) !== $authorization && 'https://indieauth.com/auth' !== $authorization );
?>
	<label for="indieauth_authorization_endpoint">
	<br /><?php _e( 'IndieAuth Authorization Endpoint', 'indieauth' ); ?>
	<br />
		<input type="radio" name="indieauth_authorization_endpoint" id="indieauth_authorization_endpoint" value="" <?php checked( $other ); ?> /><?php _e( 'Custom', 'indieauth' ); ?>
		<input type="text" name="indieauth_authorization_endpoint" id="indieauth_authorization_endpoint" size="60" value="<?php echo $other ? $authorization : ''; ?>" />
	<br />
	<input type="radio" name="indieauth_authorization_endpoint" id="indieauth_authorization_endpoint" value="<?php echo rest_url( '/indieauth/1.0/auth' ); ?>" <?php checked( $authorization, rest_url( '/indieauth/1.0/auth' ) ); ?> /> <?php _e( 'Built in Authorization Endpoint', 'indieauth' ); ?>
	<br />
	<input type="radio" name="indieauth_authorization_endpoint" id="indieauth_authorization_endpoint" value="https://indieauth.com/auth" <?php checked( $authorization, 'https://indieauth.com/auth' ); ?> /> <?php _e( 'Indieauth.com', 'indieauth' ); ?>
	<br />

	</label>
	<BR />
<?php
$token = get_option( 'indieauth_token_endpoint' );
$other = ( rest_url( 'indieauth/1.0/token' ) !== $token && 'https://tokens.indieauth.com/token' !== $authorization );
?>
	<label for="indieauth_token_endpoint">
	<br /><?php _e( 'IndieAuth Token Endpoint', 'indieauth' ); ?>
	<br />
		<input type="radio" name="indieauth_token_endpoint" id="indieauth_token_endpoint" value="" <?php checked( $other ); ?> /><?php _e( 'Custom', 'indieauth' ); ?>
		<input type="text" name="indieauth_token_endpoint" id="indieauth_token_endpoint" size="60" value="<?php echo $other ? $token : ''; ?>" />
	<br />
	<input type="radio" name="indieauth_token_endpoint" id="indieauth_token_endpoint" value="<?php echo rest_url( '/indieauth/1.0/token' ); ?>" <?php checked( $token, rest_url( '/indieauth/1.0/token' ) ); ?> /> <?php _e( 'Built in token Endpoint', 'indieauth' ); ?>
	<br />
	<input type="radio" name="indieauth_token_endpoint" id="indieauth_token_endpoint" value="https://tokens.indieauth.com/token" <?php checked( $token, 'https://tokens.indieauth.com/token' ); ?> /> <?php _e( 'Indieauth.com', 'indieauth' ); ?>
	</label>
	<BR />
</fieldset>
