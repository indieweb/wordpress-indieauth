<div class="wrap">
	<h1><?php esc_html_e( 'IndieAuth Settings', 'indieauth' ); ?></h1>

	<div class="notice-warning notice">
		<p><?php esc_html_e( 'Some host configurations can block the ability of this site to work and may require change. Please run the Site Health check to ensure this will work with your site.', 'indieauth' ); ?></p>
		<p><a href="<?php echo esc_url( admin_url( 'site-health.php' ) ); ?>"><?php esc_html_e( 'Click Here', 'indieauth' ); ?></a></p>
	</div>

	<form method="post" action="options.php">
		<?php settings_fields( 'indieauth' ); ?>
		<?php do_settings_sections( 'indieauth' ); ?>
		<?php submit_button(); ?>
	</form>
</div>
