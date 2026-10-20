<?php
/**
 * Load the IndieAuth development tools.
 *
 * This directory is excluded from the release and only loaded when
 * `wp_get_environment_type()` is `local`.
 *
 * @package IndieAuth
 */

namespace IndieAuth\Development;

/**
 * Allow unsafe HTTP request URLs (localhost, private IPs, etc.) in local development.
 *
 * Disables WordPress SSRF protection (`reject_unsafe_urls`) so that
 * HTTP requests to localhost, private IPs, etc. are permitted during
 * local development. Must NOT run in production.
 *
 * @param array $parsed_args HTTP request arguments.
 *
 * @return array Filtered HTTP request arguments.
 *
 * @see wp_http_validate_url()
 */
function allow_unsafe_http_request_urls( $parsed_args ) {
	$parsed_args['reject_unsafe_urls'] = false;

	return $parsed_args;
}
\add_filter( 'http_request_args', __NAMESPACE__ . '\allow_unsafe_http_request_urls' );

/**
 * Register the Client Discovery Tester under Tools.
 */
function add_client_discovery_page() {
	\add_management_page( \__( 'IndieAuth Client Discovery', 'indieauth' ), \__( 'IndieAuth Discovery', 'indieauth' ), 'read', 'indieauth_client_discovery', __NAMESPACE__ . '\client_discovery_page' );
}
\add_action( 'admin_menu', __NAMESPACE__ . '\add_client_discovery_page' );

/**
 * Render the Client Discovery Tester.
 */
function client_discovery_page() {
	?>
	<div class="wrap">
	<h1><?php \esc_html_e( 'Client Discovery Tester', 'indieauth' ); ?></h1>
	<form method="post" action="admin.php">
	<label for="client_url"><?php \esc_html_e( 'Discovery Test', 'indieauth' ); ?></label><input type="url" class="widefat" id="client_url" name="client_url" />
	<?php \wp_nonce_field( 'indieauth_client_discovery', 'indieauth_nonce' ); ?>
		<input type="hidden" name="action" id="action" value="indieauth_client_discovery" />
		<p><button class="button-primary"><?php \esc_html_e( 'Client Discovery', 'indieauth' ); ?></button></p>
	</form>
	</div>
	<?php
}

/**
 * Handle the client discovery action.
 */
function client_discovery() {
	if ( ! isset( $_POST['indieauth_nonce'] )
			|| ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST['indieauth_nonce'] ) ), 'indieauth_client_discovery' )
	) {
		\esc_html_e( 'Invalid Nonce', 'indieauth' );
		exit;
	}
	if ( empty( $_REQUEST['client_url'] ) ) {
		$GLOBALS['title'] = \esc_html__( 'Client Discovery', 'indieauth' ); // phpcs:ignore
		\esc_html_e( 'A URL must be provided', 'indieauth' );
		exit;
	}
	\header( 'Content-Type: application/json' );
	$client_url = \sanitize_text_field( \wp_unslash( $_REQUEST['client_url'] ) );
	$client     = new \IndieAuth\Client_Discovery( $client_url );
	echo \wp_json_encode( $client->export(), JSON_PRETTY_PRINT );
	exit;
}
\add_action( 'admin_action_indieauth_client_discovery', __NAMESPACE__ . '\client_discovery' );
