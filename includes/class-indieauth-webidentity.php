<?php
/**
 * Web Identity Well-Known Handler file.
 *
 * @package IndieAuth
 */

/**
 * Handles the /.well-known/web-identity endpoint for FedCM discovery.
 *
 * @package IndieAuth
 * @see https://indieweb.org/FedCM_for_IndieAuth
 */
class IndieAuth_WebIdentity {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_action( 'template_redirect', array( $this, 'handle_request' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
	}

	/**
	 * Add rewrite rules for well-known endpoint.
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule(
			'^\.well-known/web-identity$',
			'index.php?well-known=web-identity',
			'top'
		);
	}

	/**
	 * Add query vars.
	 *
	 * @param array $vars Existing query vars.
	 * @return array Modified query vars.
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'well-known';
		return $vars;
	}

	/**
	 * Handle the well-known request.
	 */
	public function handle_request() {
		$well_known = get_query_var( 'well-known' );

		if ( 'web-identity' !== $well_known ) {
			return;
		}

		$provider_urls = array(
			IndieAuth_FedCM_Endpoint::get_config_endpoint(),
		);

		/**
		 * Filter the FedCM provider URLs.
		 *
		 * @param array $provider_urls The provider URLs.
		 */
		$provider_urls = apply_filters( 'indieauth_fedcm_provider_urls', $provider_urls );

		$response = array(
			'provider_urls' => $provider_urls,
		);

		header( 'Content-Type: application/json' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $response );
		exit;
	}

	/**
	 * Flush rewrite rules on activation.
	 */
	public static function flush_rewrite_rules() {
		$instance = new self();
		$instance->add_rewrite_rules();
		flush_rewrite_rules();
	}
}
