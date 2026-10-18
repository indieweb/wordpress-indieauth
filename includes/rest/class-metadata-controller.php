<?php
/**
 * IndieAuth Metadata Controller class file.
 *
 * @package IndieAuth
 */

namespace IndieAuth\Rest;

use IndieAuth\IndieAuth;
use function IndieAuth\indieauth_get_issuer;

/**
 * Metadata Controller Functionality.
 *
 * @since 1.0.0
 */
class Metadata_Controller extends \WP_REST_Controller {

	/**
	 * The namespace for the REST route.
	 *
	 * @var string
	 */
	protected $namespace = 'indieauth/1.0';

	/**
	 * The base of the REST route.
	 *
	 * @var string
	 */
	protected $rest_base = 'metadata';


	/**
	 * Returns the URL for the metadata endpoint.
	 *
	 * @return string Endpoint URL.
	 */
	public function get_endpoint() {
		return \rest_url( $this->namespace . '/' . $this->rest_base );
	}

	/**
	 * Returns the issuer URL.
	 *
	 * @return string Issuer URL.
	 */
	public static function get_issuer() {
		return \rest_url( '/indieauth/1.0' );
	}

	/**
	 * Serve the metadata at the RFC 8414 well-known URL.
	 *
	 * The well-known string goes between the host and the path of the issuer,
	 * so the URL is /.well-known/oauth-authorization-server/wp-json/indieauth/1.0.
	 * The bare /.well-known/oauth-authorization-server belongs to an issuer
	 * without a path and is left alone.
	 *
	 * @see https://www.rfc-editor.org/rfc/rfc8414#section-3
	 */
	public static function add_rewrite_rules() {
		$issuer      = \wp_parse_url( self::get_issuer() );
		$issuer_path = isset( $issuer['path'] ) ? trim( $issuer['path'], '/' ) : '';
		$home_path   = trim( (string) \wp_parse_url( \home_url(), PHP_URL_PATH ), '/' );

		// Plain permalinks put the route in the query, and a WordPress in a
		// subdirectory cannot answer at the root of the host.
		if ( isset( $issuer['query'] ) || '' === $issuer_path || '' !== $home_path ) {
			return;
		}

		\add_rewrite_rule(
			'^\.well-known/oauth-authorization-server/' . preg_quote( $issuer_path, '#' ) . '/?$',
			'index.php?rest_route=/indieauth/1.0/metadata',
			'top'
		);
	}

	/**
	 * Hooks into the REST API output to add a metadata header to the Issuer URL.
	 *
	 * @param bool                       $served  Whether the request has already been served.
	 * @param \WP_HTTP_ResponseInterface $result  Result to send to the client. Usually a WP_REST_Response.
	 * @param \WP_REST_Request           $request Request used to generate the response.
	 * @param \WP_REST_Server            $server  Server instance.
	 * @return bool Whether the request has been served.
	 */
	public function serve_request( $served, $result, $request, $server ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( false === strpos( $request->get_route(), '/indieauth/1.0' ) ) {
			return $served;
		}
		header( sprintf( 'Link: <%s>; rel="%s"', $this->get_endpoint(), 'indieauth-metadata' ), false );

		return $served;
	}

	/**
	 * Add authentication information into the REST API Index.
	 *
	 * @param \WP_REST_Response $response REST API Response Object.
	 * @return \WP_REST_Response Response object with endpoint info added.
	 */
	public function register_index( \WP_REST_Response $response ) {
		$data      = $response->get_data();
		$endpoints = array(
			'metadata' => $this->get_endpoint(),
		);
		$endpoints = array_filter( $endpoints );
		if ( empty( $endpoints ) ) {
			return $response;
		}
		$data['authentication']['indieauth'] = array(
			'endpoints' => \apply_filters( 'rest_index_indieauth_endpoints', $endpoints ),
		);
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Register the Route.
	 */
	public function register_routes() {
		\register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'metadata' ),
					'args'                => array(),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Metadata Endpoint GET request handler.
	 *
	 * @param \WP_REST_Request $request The Request Object.
	 * @return \WP_REST_Response Response to Return to the REST Server.
	 */
	public function metadata( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$metadata = array(
			'issuer'                           => indieauth_get_issuer(),
			'scopes_supported'                 => IndieAuth::$scopes->get_names(),
			'service_documentation'            => 'https://indieauth.spec.indieweb.org',
			'code_challenge_methods_supported' => array( 'S256' ),
		);

		$metadata = \apply_filters( 'indieauth_metadata', $metadata );
		return new \WP_REST_Response(
			$metadata,
			200,
			array(
				'Content-Type' => 'application/json',
			)
		);
	}
}
