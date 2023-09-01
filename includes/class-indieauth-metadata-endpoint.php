<?php
/**
 * Metadata Endpoint Functionality
 */
class IndieAuth_Metadata_Endpoint {

	public function __construct() {
		add_filter( 'rest_index', array( $this, 'register_index' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_head', array( $this, 'html_header' ) );
		add_action( 'template_redirect', array( $this, 'http_header' ) );
	}

	/*
	 * Returns the URL for the metadata endpoint.
	 */
	public static function get_metadata_endpoint() {
		return rest_url( '/indieauth/1.0/metadata' );
	}

	/**
	 * Add authentication information into the REST API Index
	 *
	 * @param WP_REST_Response $response REST API Response Object
	 *
	 * @return WP_REST_Response Response object with endpoint info added
	 **/
	public function register_index( WP_REST_Response $response ) {
		$data      = $response->get_data();
		$endpoints = array(
			'authorization' => indieauth_get_authorization_endpoint(),
			'token'         => indieauth_get_token_endpoint(),
			'metadata'      => indieauth_get_metadata_endpoint(),
			'revocation'    => rest_url( 'indieauth/1.0/revocation' ),
			'introspection' => rest_url( 'indieauth/1.0/introspection' ),
		);
		$endpoints = array_filter( $endpoints );
		if ( empty( $endpoints ) ) {
			return $response;
		}
		$data['authentication']['indieauth'] = array(
			'endpoints' => $endpoints,
		);
		$response->set_data( $data );
		return $response;
	}

	public function http_header() {
		if ( is_author() || is_front_page() ) {
			header( sprintf( 'Link: <%s>; rel="indieauth-metadata"', static::get_metadata_endpoint() ), false );
		}
	}
	public static function html_header() {
		$kses = array(
			'link' => array(
				'href' => array(),
				'rel'  => array(),
			),
		);
		if ( is_author() || is_front_page() ) {
			echo wp_kses( sprintf( '<link rel="indieauth-metadata" href="%s" />' . PHP_EOL, static::get_metadata_endpoint() ), $kses );
		}
	}

	/**
	 * Register the Route.
	 */
	public function register_routes() {
		register_rest_route(
			'indieauth/1.0',
			'/metadata',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
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
	 * @param WP_REST_Request $request The Request Object.
	 * @return Response to Return to the REST Server.
	 **/
	public function metadata( $request ) {
		$grants = array( 'authorization_code', 'refresh_token' );
		if ( class_exists( 'IndieAuth_Ticket_Endpoint' ) ) {
			$grants[] = 'ticket';
		}

		$metadata = array(
			'issuer'                                     => indieauth_get_issuer(),
			'authorization_endpoint'                     => indieauth_get_authorization_endpoint(),
			'scopes_supported'                           => IndieAuth_Plugin::$scopes->get_names(),
			'response_types_supported'                   => array( 'code' ),
			'grant_types_supported'                      => $grants,
			'service_documentation'                      => 'https://indieauth.spec.indieweb.org',
			'token_endpoint'                             => indieauth_get_token_endpoint(),
			'revocation_endpoint'                        => rest_url( '/indieauth/1.0/revocation' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none' ),
			'introspection_endpoint'                     => rest_url( '/indieauth/1.0/introspection' ),
			'introspection_endpoint_auth_methods_supported' => array( 'none' ),
			'code_challenge_methods_supported'           => array( 'S256' ),
			'authorization_response_iss_parameter_supported' => true,
		);

		if ( class_exists( 'IndieAuth_Ticket_Endpoint' ) ) {
			$metadata['ticket_endpoint'] = rest_url( 'indieauth/1.0/ticket' );
		}

		$metadata = apply_filters( 'indieauth_metadata', $metadata );
		return new WP_REST_Response(
			$metadata,
			200,
			array(
				'Content-Type' => 'application/json',
			)
		);
	}
}
