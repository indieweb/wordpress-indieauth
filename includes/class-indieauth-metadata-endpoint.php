<?php
/**
 * Metadata Endpoint Functionality
 */
class IndieAuth_Metadata_Endpoint {

	public function __construct() {
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_request' ), 11, 4 );
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


	public static function get_issuer() {
		return rest_url( '/indieauth/1.0' );
	}


	/*
	 * Outputs a marked up Http link header.
	 * 
	 * @param string $url URL for the link
	 * @param string $rel Rel property for the link
	 * @param boolean $replace Passes the value of replace through to the header PHP
	 */
	public static function set_http_header( $url, $rel, $replace = false ) {
		header( sprintf( 'Link: <%s>; rel="%s"', $url, $rel ), $replace );
	}

	/*
	 * Returns a marked up HTML link header.
	 * 
	 * @param string $url URL for the link
	 * @param string $rel Rel property for the link
	 * @return string Marked up HTML link to add to head
	 */
	public static function get_html_header( $url, $rel ) {
		return sprintf( '<link rel="%s" href="%s" />' . PHP_EOL, $rel, $url );
	}

	/**
	 * Hooks into the REST API output to add a metadata header to the Issuer URL.
 	 *
 	 * @param bool                      $served  Whether the request has already been served.
 	 * @param WP_HTTP_ResponseInterface $result  Result to send to the client. Usually a WP_REST_Response.
 	 * @param WP_REST_Request           $request Request used to generate the response.
 	 * @param WP_REST_Server            $server  Server instance.
	 *
	 * @return true
	 */
	public static function serve_request( $served, $result, $request, $server ) {
		if ( '/indieauth/1.0' !== $request->get_route() ) {
			return $served;
		}
		static::set_http_header( static::get_metadata_endpoint(), 'indieauth-metadata' );

		return $served;
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
		$metadata = array(
			'issuer'                                     => indieauth_get_issuer(),
			'scopes_supported'                           => IndieAuth_Plugin::$scopes->get_names(),
			'service_documentation'                      => 'https://indieauth.spec.indieweb.org',
			'code_challenge_methods_supported'           => array( 'S256' ),
		);

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
