<div class="notices">
	<?php
	$redirect_scheme = \IndieAuth\get_redirect_uri_scheme( $redirect_uri );
	if ( $redirect_scheme && ! in_array( $redirect_scheme, array( 'http', 'https' ), true ) ) {
		// A custom scheme has no domain to compare, so the mismatch notice would be misleading.
		?>
	<p class="notice notice-warning redirect custom-scheme">
		<strong> 📱
		<?php
			printf(
				/* translators: %s: URL scheme, such as com.example.app */
				esc_html__( 'This app will return you to an application on your device using the %s: scheme. Only continue if you started this sign-in from that application.', 'indieauth' ),
				'<code>' . esc_html( $redirect_scheme ) . '</code>'
			);
		?>
		</strong>
	</p>
		<?php
	} elseif ( wp_parse_url( $client_id, PHP_URL_HOST ) !== wp_parse_url( $redirect_uri, PHP_URL_HOST ) ) {
		?>
	<p class="notice notice-error redirect">
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
