<div class="wrap">
	<h1><?php esc_html_e( 'IndieAuth Settings', 'indieauth' ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'indieauth' ); ?>

		<h2 class="title"><?php _e( 'IndieAuth', 'indieauth' ); ?></h2>

		<p><?php _e( 'With IndieAuth, you can use your blog, to log into sites like the IndieWeb-Wiki.', 'indieauth' ); ?></p>

		<table class="form-table">
			<tbody>
				<tr>
					<th>
						<?php _e( 'Endpoints', 'indieauth' ); ?>
					</th>
					<td>
						<p>
							<?php _e( 'Authorization Endpoint:', 'indieauth' ); ?><br />
							<code><?php echo rest_url( '/indieauth/1.0/auth' ); ?></code>
						</p>
						<p>
							<?php _e( 'Token Endpoint:', 'indieauth' ); ?><br />
							<code><?php echo rest_url( '/indieauth/1.0/token' ); ?></code>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<h2 class="title"><?php _e( 'Web Sign-In', 'indieauth' ); ?></h2>

		<p><?php _e( 'Enable Web Sign-In for your blog, so others can use IndieAuth or RelMeAuth to log into this site.', 'indieauth' ); ?></p>

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
							<?php _e( 'Add a link to the login form to authenticate using an IndieAuth endpoint.', 'indieauth' ); ?>
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
