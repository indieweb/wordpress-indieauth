<div class="wrap">
	<h1><?php esc_html_e( 'IndieAuth Settings', 'indieauth' ); ?></h1>

<?php $checked = get_option( 'indieauth_config', 'local' ); ?>

	<div class="notice-warning notice">
		<p><?php esc_html_e( 'Some host configurations can block the ability of this site to work and may require change. Please run the Site Health check to ensure this will work with your site.', 'indieauth' ); ?></p>
		<p><a href="<?php echo esc_url( admin_url( 'site-health.php' ) ); ?>"><?php esc_html_e( 'Click Here', 'indieauth' ); ?></a></p>
	</div>
	<form method="post" action="options.php">
		<?php settings_fields( 'indieauth' ); ?>

		<h2 class="title"><?php esc_html_e( 'IndieAuth', 'indieauth' ); ?></h2>

		<p><?php esc_html_e( 'With IndieAuth, you can use your blog, to log into sites like the IndieWeb-Wiki.', 'indieauth' ); ?></p>


		<table class="form-table">
			<tbody>

				<tr>
					<th>
						<?php esc_html_e( 'Endpoints', 'indieauth' ); ?>
					</th>
					<td>
						<p>
							<?php esc_html_e( 'Authorization Endpoint:', 'indieauth' ); ?><br />
							<code><?php echo esc_url( indieauth_get_authorization_endpoint() ); ?></code>
						</p>
						<p>
							<?php esc_html_e( 'Token Endpoint:', 'indieauth' ); ?><br />
							<code><?php echo esc_url( indieauth_get_token_endpoint() ); ?></code>
						</p>
					</td>
				</tr>
				<tr>
					<th>
						<?php esc_html_e( 'Set User to Represent Site URL', 'indieauth' ); ?>
					</th>
					<td>
						<label for="indieauth_root_user">
							<?php
							wp_dropdown_users(
								array(
									'show_option_all' => __( 'None', 'indieauth' ),
									'name'            => 'indieauth_root_user',
									'id'              => 'indieauth_root_user',
									'show'            => 'display_name_with_login',
									'selected'        => get_option( 'indieauth_root_user' ),
								)
							);
							?>
							<?php esc_html_e( 'Set a User who will represent the URL of the site', 'indieauth' ); ?>
						</label>
					</td>
				</tr>
			</tbody>
		</table>

		<h2 class="title"><?php esc_html_e( 'Web Sign-In', 'indieauth' ); ?></h2>

		<p><?php esc_html_e( 'Enable Web Sign-In for your blog, so others can use IndieAuth or RelMeAuth to log into this site.', 'indieauth' ); ?></p>

		<table class="form-table">
			<tbody>
				<tr>
					<th>
						<?php esc_html_e( 'Use IndieAuth login', 'indieauth' ); ?>
					</th>
					<td>
						<label for="indieauth_show_login_form">
							<input type="checkbox" name="indieauth_show_login_form" id="indieauth_show_login_form" value="1" 
							<?php
								echo checked( true, get_option( 'indieauth_show_login_form' ) );
							?>
								 />
							<?php esc_html_e( 'Add a link to the login form to authenticate using an IndieAuth endpoint.', 'indieauth' ); ?>
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
