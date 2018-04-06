<fieldset id="indieauth">
	<label for="indieauth_show_login_form">
		<input type="checkbox" name="indieauth_show_login_form" id="indieauth_show_login_form" value="1" <?php
			echo checked( true, get_option( 'indieauth_show_login_form' ) );  ?> />
		<?php _e( 'Add a checkbox to the login form to authenticate using an IndieAuth endpoint.', 'indieauth' ); ?>
	</label>
	<BR />
	<label for="indieauth_authorization_endpoint">
		<input type="text" name="indieauth_authorization_endpoint" id="indieauth_authorization_endpoint" size="60" value="<?php
			echo get_option( 'indieauth_authorization_endpoint' );
			?>" />
		<br /><?php _e( 'IndieAuth Authorization Endpoint.', 'indieauth' ); ?>
	</label>
	<BR />
	<label for="indieauth_token_endpoint">
		<input type="text" name="indieauth_token_endpoint" id="indieauth_token_endpoint" size=60" value="<?php
			echo get_option( 'indieauth_token_endpoint' );
			?>" />
		<br /><?php _e( 'IndieAuth Token Endpoint.', 'indieauth' ); ?>
	</label>

	<br />
</fieldset>
