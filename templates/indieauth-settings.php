<div class="wrap">
	<h1><?php esc_html_e( 'IndieAuth Settings', 'indieauth' ); ?></h1>

<?php 
$message = get_query_var( 'authdiag_message' );
if ( $message ) {
?>
<div>
<?php echo $message; ?>
</div>
<?php } else {  ?>
	<h2 class="title"><?php _e( 'Test your System', 'indieauth' ); ?></h2>
	<p><?php _e( 'If you are experiencing unauthorized as an error, click below to run a test script.', 'indieauth' ); ?></p>
	<p><a href="<?php echo add_query_arg( 'action', 'authdiag', wp_login_url() ); ?>"><?php _e( 'Diagnostic Script', 'indieauth' ); ?></a></p>
<?php } ?>
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
				<tr>
					<th>
						<?php _e( 'Set User to Represent Site URL', 'indieauth' ); ?>
					</th>
					<td>
						<label for="indieauth_root_user">
							<?php wp_dropdown_users(
								array(
									'show_option_all' => __( 'None', 'indieauth' ),
									'name' => 'indieauth_root_user',
									'id' => 'indieauth_root_user',
									'show' => 'display_name_with_login',
									'selected' => get_option( 'indieauth_root_user' )
								)
							); ?>
							<?php _e( 'Set a User who will represent the URL of the site', 'indieauth' ); ?>
						</label>
					</td>
				</tr>
				</tr>
			</tbody>
		</table>

		<?php do_settings_fields( 'indieauth', 'web_signin' ); ?>

		<?php do_settings_sections( 'indieauth' ); ?>

		<?php submit_button(); ?>
	</form>
</div>
