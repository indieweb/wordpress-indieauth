<div class="notices">
	<?php
	if ( wp_parse_url( $client_id, PHP_URL_HOST ) !== wp_parse_url( $redirect_uri, PHP_URL_HOST ) ) {
		?>
	<p class="redirect">
		<strong> ⚠️  <?php esc_html_e( 'The redirect URL this app is using does not match the domain of the client ID.', 'indieauth' ); ?> </strong>
	</p>
		<?php
	}
	if ( ! is_null( $code_challenge ) && 'S256' === $code_challenge_method ) {
		?>
	<p class="pkce">
		<strong>🔒<?php esc_html_e( 'This app is using PKCE for security.', 'indieauth' ); ?> </strong>
	</p>
	<?php } ?>
</div>
