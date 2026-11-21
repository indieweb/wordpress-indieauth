<div class="wrap">
	<h1><?php esc_html_e( 'IndieAuth Settings', 'indieauth' ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'indieauth' ); ?>
		<?php do_settings_sections( 'indieauth' ); ?>
		<?php submit_button(); ?>
	</form>
</div>
