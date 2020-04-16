<?php

/**
 * Micropub IndieAuth Authorization Class
 */
class IndieAuth_Remote_Authorize extends IndieAuth_Authorize {


	public function __construct( $load = true ) {
		$this->register_settings();
		// Load the hooks for this class only if true. This allows for debugging of the functions
		if ( true === $load ) {
			add_action( 'admin_init', array( get_called_class(), 'admin_init' ) );
			$this->load();
		}
	}

	public function register_settings() {
		// Register Setting
		register_setting(
			'indieauth',
			'indieauth_authorization_endpoint', // Setting Name
			array(
				'type'              => 'string',
				'description'       => 'IndieAuth Authorization Endpoint',
				'sanitize_callback' => 'esc_url',
				'show_in_rest'      => true,
			)
		);
		// Register Setting
		register_setting(
			'indieauth',
			'indieauth_token_endpoint', // Setting Name
			array(
				'type'              => 'string',
				'description'       => 'IndieAuth Token Endpoint',
				'sanitize_callback' => 'esc_url',
				'show_in_rest'      => true,
			)
		);

	}

	public static function admin_init() {
		$cls  = get_called_class();
		$page = 'indieauth';

		add_settings_section(
			'indieauth',
			'Remote IndieAuth Endpoint Settings',
			array( $cls, 'auth_settings' ),
			$page
		);
		add_settings_field(
			'indieauth_authorization_endpoint',
			__( 'Authorization Endpoint', 'indieauth' ),
			array( $cls, 'endpoint_field' ),
			$page,
			'indieauth',
			array(
				'label_for' => 'indieauth_authorization_endpoint',
				'class'     => 'widefat',
				'default'   => 'https://indieauth.com/auth',
			)
		);
		add_settings_field(
			'indieauth_token_endpoint',
			__( 'Token Endpoint', 'indieauth' ),
			array( $cls, 'endpoint_field' ),
			$page,
			'indieauth',
			array(
				'label_for' => 'indieauth_token_endpoint',
				'class'     => 'widefat',
				'default'   => 'https://tokens.indieauth.com/token',
			)
		);
	}

	public static function endpoint_field( $args ) {
		printf( '<label for="%1$s"><input id="%1$s" name="%1$s" type="url" value="%2$s" placeholder="%3$s" />', esc_attr( $args['label_for'] ), esc_url( get_option( $args['label_for'], $args['default'] ) ), esc_url( $args['default'] ) );
	}

	public static function auth_settings() {
		esc_html_e( 'Please specify a remote indieauth authorization and token endpoint.', 'indieauth' );
	}

	public static function get_authorization_endpoint() {
		$return = get_option( 'indieauth_authorization_endpoint', 'https://indieauth.com/auth' );
		// Sanity Check
		if ( empty( $return ) ) {
			delete_option( 'indieauth_authorization_endpoint' );
			$return = 'https://indieauth.com/auth';
		}
		return $return;
	}

	public static function get_token_endpoint() {
		$return = get_option( 'indieauth_token_endpoint', 'https://tokens.indieauth.com/token' );
		// Sanity Check
		if ( empty( $return ) ) {
			delete_option( 'indieauth_token_endpoint' );
			$return = 'https://tokens.indieauth.com/token';
		}
		return $return;
	}

	public function verify_access_token( $token ) {
		$resp = wp_remote_get(
			$this->get_token_endpoint(),
			array(
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code   = (int) wp_remote_retrieve_response_code( $resp );
		$body   = wp_remote_retrieve_body( $resp );
		$params = json_decode( $body, true );

		if ( ( $code / 100 ) !== 2 ) {
			return new WP_OAuth_Response( 'invalid_request', 'invalid access token', 403 );
		}

		// look for a user with the same url as the token's `me` value.
		$user = get_user_by_identifier( $params['me'] );
		if ( $user instanceof WP_User ) {
			$params['user'] = $user->ID;
		} else {
			return new WP_OAuth_Response( 'unauthorized', __( 'Unable to Find User', 'indieauth' ), 401 );
		}
		return $params;
	}

	public static function verify_authorization_code( $code ) {
		$args  = array(
			'headers' => array(
				'Accept' => 'application/json',
			),
		);
		$query = build_query(
			array(
				'code'         => rawurlencode( $code ),
				'redirect_uri' => wp_login_url( $redirect_uri ),
				'client_id'    => home_url(),
			)
		);
		$resp  = wp_safe_remote_post( static::get_authorization_endpoint() . '?' . $query, $args );
		if ( is_wp_error( $resp ) ) {
			return wp_error_to_oauth_response( $resp );
		}
		$code   = wp_remote_retrieve_response_code( $resp );
		$body   = wp_remote_retrieve_body( $resp );
		$params = json_decode( $response, true );

		// check if response was json or not
		if ( ! is_array( $response ) ) {
			return new WP_OAuth_Response( 'indieauth_response_error', __( 'IndieAuth.com seems to have some hiccups, please try it again later.', 'indieauth' ), 401 );
		}

		if ( 2 === (int) ( $code / 100 ) && isset( $params['me'] ) ) {
			return $params;
		}
		if ( array_key_exists( 'error', $response ) ) {
			return new WP_Error( 'indieauth_' . $response['error'], esc_html( $response['error_description'] ) );
		}

		return new WP_OAuth_Response(
			'indieauth.invalid_access_token',
			__( 'Supplied Token is Invalid', 'indieauth' ),
			$code,
			$response
		);
	}
}
