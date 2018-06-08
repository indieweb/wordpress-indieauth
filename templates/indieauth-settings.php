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

<div class="wrap">
	<h1><?php esc_html_e( 'IndieAuth Settings', 'indieauth' ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'indieauth' ); ?>

		<h2 class="title"><?php _e( 'IndieAuth', 'indieauth' ); ?></h2>

		<p><?php _e( 'With IndieAuth, you can use your blog, to log into sites like the IndieWeb-Wiki.', 'indieauth' ); ?></p>

		<h3><?php _e( 'Endpoint', 'indieauth' ); ?></h3>

		<p><?php _e( 'Use the endpoint built into the plugin, IndieAuth.com, or any custom IndieAuth endpoint to support logins into your site. If you change your endpoint, you will have to reauthorize any sites that you may have previously granted access', 'indieauth' ); ?></p>

		<table class="form-table">
			<tbody>
				<tr>
					<th>
						<label for="indieauth_config_local">
							<input type="radio" name="indieauth_config" id="indieauth_config_local" value="local" <?php checked( $config, 'local' );  ?> />
							<?php _e( 'Built-In Endpoint', 'indieauth' ); ?>
						</label>
					</th>
					<td>
						<p>
							<?php _e( 'Authorization Endpoint', 'indieauth' ); ?>
							<code><?php echo rest_url( '/indieauth/1.0/auth' ); ?></code>
						</p>
						<p>
							<?php _e( 'Token Endpoint', 'indieauth' ); ?>
							<code><?php echo rest_url( '/indieauth/1.0/token' ); ?></code>
						</p>
					</td>
				</tr>

				<tr>
					<th>
						<label for="indieauth_config_indieauth">
							<input type="radio" name="indieauth_config" id="indieauth_config_indieauth" value="indieauth" <?php checked( $config, 'indieauth' );  ?> />
							<?php _e( 'indieauth.com', 'indieauth' ); ?>
						</label>
					</th>
					<td>
						<p>
							<?php _e( 'Authorization Endpoint', 'indieauth' ); ?>
							<code>https://indieauth.com/auth</code>
						</p>
						<p>
							<?php _e( 'Token Endpoint', 'indieauth' ); ?>
							<code>https://tokens.indieauth.com/token</code>
						</p>
					</td>
				</tr>

				<tr>
					<th>
						<label for="indieauth_config_custom">
							<input type="radio" name="indieauth_config" id="indieauth_config_custom" value="custom" <?php checked( $config, 'custom' );  ?> />
							<?php _e( 'Custom', 'indieauth' ); ?>
						</label>
					</th>
					<td>
						<p>
							<label for="indieauth_authorization_endpoint">
								<?php _e( 'Authorization Endpoint', 'indieauth' ); ?>
								<input type="text" name="indieauth_authorization_endpoint" id="indieauth_authorization_endpoint" size="60" value="<?php echo $authorization; ?>" />
							</label>
						</p>
						<p>
							<label for="indieauth_token_endpoint">
								<?php _e( 'Token Endpoint', 'indieauth' ); ?>
								<input type="text" name="indieauth_token_endpoint" id="indieauth_token_endpoint" size="60" value="<?php echo $token; ?>" />
							</label>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<?php do_settings_fields( 'indieauth', 'indieauth' ); ?>

		<h2 class="title"><?php _e( 'Web Sign-In', 'indieauth' ); ?></h2>

		<p><?php _e( 'Enable the Web Sign-In for your blog, so others can use IndieAuth or RelMeAuth to log into this site.', 'indieauth' ); ?></p>

		<table class="form-table">
			<tbody>
				<tr>
					<th>
						<?php _e( 'Use IndieAuth login', 'indieauth' ); ?>
					</th>
					<td>
						<label for="indieauth_show_login_form">
							<input type="checkbox" name="indieauth_show_login_form" id="indieauth_show_login_form" value="1" <?php
								echo checked( true, get_option( 'indieauth_show_login_form' ) );  ?> />
							<?php _e( 'Add a link to the login form to authenticate using an IndieAuth endpoint. This is not necessary if you choose the endpoint built into your site.', 'indieauth' ); ?>
						</label>
					</td>
				</tr>
			</tbody>
		</table>

		<?php do_settings_fields( 'indieauth', 'web_signin' ); ?>

		<?php do_settings_sections( 'indieauth' ); ?>

		<?php submit_button(); ?>
	</form>
</div>
