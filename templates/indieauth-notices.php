<div class="notices">
	<?php
	if ( wp_parse_url( $client_id, PHP_URL_HOST ) !== wp_parse_url( $redirect_uri, PHP_URL_HOST ) ) {
		?>
	<p class="redirect">
		<strong> ⚠️  <?php esc_html_e( 'The redirect URL this app is using does not match the domain of the client ID.', 'indieauth' ); ?> </strong>
	</p>
		<?php
	}
	if ( is_null( $code_challenge ) && 'S256' !== $code_challenge_method ) {
		?>
	<p class="pkce notice notice-error">
		<strong> 🛡️ 
		<?php
			echo wp_kses(
				/* translators: PKCE specification link */
				sprintf( __( 'This app is not using %s for security which is now required for IndieAuth', 'indieauth' ), '<a href="https://indieweb.org/PKCE">PKCE</a>' ),
				array(
					'a' => array(
						'href' => array(),
					),
				)
			);
		?>
			 
		</strong>
	</p>
	<?php } ?>
</div>
