<div class="wrap">
	<h2><?php esc_html_e( 'IndieAuth Tokens', 'indieauth' ); ?></h2>
	<hr />

	<form method="post" action="<?php echo rest_url( 'indieauth/1.0/token' ); ?>" enctype="application/x-www-form-urlencoded">
		<?php IndieAuth_Token_UI::token_form_table(); ?>
		<input type="hidden" name="action" value="revoke" />
		<?php submit_button( __( 'Revoke', 'indieauth' ) ); ?>
	</form>
</div>
