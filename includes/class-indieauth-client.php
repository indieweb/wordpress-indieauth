<?php
/**
 * IndieAuth Client
 */
class IndieAuth_Client {

	/*
	 * Metadata including endpoints
	 */
	public $meta;

	/*
	 * Client ID. Defaults to Home URL.
	 */
	public $client_id;

	public function __construct() {
		$this->client_id = trailingslashit( home_url() );
	}

	/**
	 * Discover IndieAuth Metadata either from a Metadata Endpoint or Otherwise.
	 *
	 * @param string $url URL
	 */
	public function discover_endpoints( $url ) {
		$endpoints = get_transient( 'indieauth_discovery_' . base64_urlencode( $url ) );
		if ( $endpoints ) {
			$this->meta = $endpoints;
			return true;
		}
		$endpoints = find_rels( $url, array( 'indieauth-metadata', 'authorization_endpoint', 'token_endpoint', 'ticket_endpoint', 'micropub', 'microsub' ) );

		if ( ! $endpoints ) {
			return false;
		} elseif ( array_key_exists( 'indieauth-metadata', $endpoints ) ) {
			$resp = wp_remote_get(
				$endpoints['indieauth-metadata'],
				array(
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			);
			if ( is_wp_error( $resp ) ) {
				return $resp;
			}

			$code = (int) wp_remote_retrieve_response_code( $resp );

			if ( ( $code / 100 ) !== 2 ) {
				return new WP_Error( 'no_metadata_endpoint', __( 'No Metadata Endpoint Found', 'indieauth' ) );
			}

			$body       = wp_remote_retrieve_body( $resp );
			$this->meta = json_decode( $body, true );
			// Store endpoint discovery results for this URL for 3 hours.
			set_transient( 'indieauth_discovery_' . base64_urlencode( $url ), $this->meta, 10800 );
			return true;
		} elseif ( array_key_exists( 'authorization_endpoint', $endpoints ) && array_key_exists( 'token_endpoint', $endpoints ) ) {
			$this->meta = $endpoints;
			// Store endpoint discovery results for this URL for 3 hours.
			set_transient( 'indieauth_discovery_' . base64_urlencode( $url ), $this->meta, 10800 );
			return true;
		}
		return false;
	}

	/*
	 * Redeem Authorization Code
	 *
	 * @param array $post_args {
	 *	Array of Arguments to Be Passed to the the Redemption Request.
	 * 	@type string $code Authorizaton Code to be redeemed.
	 *      @type string $redirect_uri The client's redirect URI
	 *  @type string $code_verifier
	 * }
	 * @param boolean $token Redeem For a Token or User Profile.
	 * @return WP_OAuth_Response|array Return Error or Response Array.
	 */
	public function redeem_authorization_code( $post_args, $token = true ) {
		if ( empty( $this->meta ) ) {
			return new WP_OAuth_Response( 'server_error', __( 'Valid Endpoint Not Provided', 'indieauth' ), 500 );
		}

		$endpoint = $token ? $this->meta['token_endpoint'] : $this->meta['authorization_endpoint'];

		$defaults = array(
			'client_id'  => $this->client_id,
			'grant_type' => 'authorization_code',
		);

		$post_args = wp_parse_args( $post_args, $defaults );

		if ( ! empty( array_diff( array( 'redirect_uri', 'code', 'code_verifier', 'client_id', 'grant_type' ), array_keys( $post_args ) ) ) ) {
			return new WP_OAuth_Response( 'missing_arguments', __( 'Arguments are missing from redemption flow', 'indieauth' ), 500 );
		}

		$args     = array(
			'headers' => array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'    => $post_args,
		);
		$response = wp_remote_post( $endpoint, $args );
		$error    = get_oauth_error( $response );
		if ( is_oauth_error( $error ) ) {
			// Pass through well-formed error messages from the endpoint
			return $error;
		}
		$code     = wp_remote_retrieve_response_code( $response );
		$response = wp_remote_retrieve_body( $response );

		$response = json_decode( $response, true );
		// check if response was json or not
		if ( ! is_array( $response ) ) {
				return new WP_OAuth_Response( 'server_error', __( 'The authorization endpoint did not return a JSON response', 'indieauth' ), 500 );
		}

		// The endpoint acknowledged that the authorization code is valid and returned a me property.
		if ( 2 === (int) ( $code / 100 ) && isset( $response['me'] ) ) {
			// If this redemption is at the token endpoint
			if ( $token ) {
				if ( ! array_key_exists( 'access_token', $response ) ) {
					return new WP_OAuth_Response( 'unknown_error', __( 'Token Endpoint did Not Return a Token', 'indieauth' ), 500 );
				}
			}
			return $response;
		}

		$error = new WP_OAuth_Response( 'server_error', __( 'There was an error verifying the authorization code, the authorization server returned an expected response', 'indieauth' ), 500 );
		$error->set_debug( array( 'debug' => $response ) );
		return $error;
	}
}
