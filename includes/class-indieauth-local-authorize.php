<?php
/**
 * Authorize class
 * Helper functions for extracting tokens from the WP-API team Oauth2 plugin
 */
class IndieAuth_Local_Authorize extends IndieAuth_Authorize {

	public function __construct( $load = true ) {
		$this->register_settings();
		// Load the hooks for this class only if true. This allows for debugging of the functions
		if ( true === $load ) {
			add_action( 'admin_init', array( get_called_class(), 'admin_init' ) );
			$this->load();
		}
	}

	public function register_settings() {
		register_setting(
			'indieauth',
			'indieauth_expires_in',
			array(
				'type'         => 'number',
				'description'  => __( 'IndieAuth Default Expiry Time', 'indieauth' ),
				'show_in_rest' => true,
				'default'      => 1209600, // Two Weeks.
			)
		);
	}

	public static function admin_init() {
		$cls  = get_called_class();
		$page = 'indieauth';

		add_settings_section(
			'indieauth',
			'IndieAuth Endpoint Settings',
			array( $cls, 'endpoint_settings' ),
			$page
		);
		add_settings_field(
			'indieauth_expires_in',
			__( 'Default Token Expiration Time', 'indieauth' ),
			array( $cls, 'numeric_field' ),
			$page,
			'indieauth',
			array(
				'label_for'   => 'indieauth_expires_in',
				'class'       => 'widefat',
				'description' => __( 'Set the Number of Seconds until a Token expires (Default is Two Weeks). 0 to Disable Expiration.', 'indieauth' ),
				'default'     => '',
				'min'         => 0,
			)
		);
	}


	public static function endpoint_settings() {
		esc_html_e( 'These settings control the behavior of the endpoints', 'indieauth' );
	}


	public static function numeric_field( $args ) {
		$props = array();
		if ( array_key_exists( 'min', $args ) && is_numeric( $args['min'] ) ) {
			$props[] = 'min=' . $args['min'];
		}
		if ( array_key_exists( 'max', $args ) && is_numeric( $args['max'] ) ) {
			$props[] = 'max=' . $args['max'];
		}
		if ( array_key_exists( 'step', $args ) && is_numeric( $args['step'] ) ) {
			$props[] = 'step=' . $args['step'];
		}
		$props = implode( ' ', $props );
		printf( '<label for="%1$s"><input id="%1$s" name="%1$s" type="number" value="%2$s" %3$s />', esc_attr( $args['label_for'] ), esc_attr( get_option( $args['label_for'], $args['default'] ) ), esc_html( $props ) );
		if ( array_key_exists( 'description', $args ) && ! empty( $args['description'] ) ) {
			printf( '<p>%1$s</p>', esc_html( $args['description'] ) );
		}
	}

	public static function get_authorization_endpoint() {
		return rest_url( '/indieauth/1.0/auth' );
	}

	public static function get_token_endpoint() {
		return rest_url( '/indieauth/1.0/token' );
	}


	public function verify_access_token( $token ) {
		$tokens = new Token_User( '_indieauth_token_' );
		$return = $tokens->get( $token );
		if ( empty( $return ) ) {
			return new WP_OAuth_Response(
				'invalid_token',
				__( 'Invalid access token', 'indieauth' ),
				401
			);
		}
		if ( is_oauth_error( $return ) ) {
			return $return;
		}
		$return['last_accessed'] = time();
		$return['last_ip']       = $_SERVER['REMOTE_ADDR'];
		$tokens->update( $token, $return );
		if ( array_key_exists( 'exp', $return ) ) {
			$return['expires_in'] = $return['exp'] - time();
		}
		return $return;
	}

	public static function verify_authorization_code( $code ) {
		$tokens = new Token_User( '_indieauth_code_' );
		$return = $tokens->get( $code );
		if ( empty( $return ) ) {
			return new WP_OAuth_Response(
				'invalid_code',
				__( 'Invalid authorization code', 'indieauth' ),
				401
			);
		}
		// Once the code is verified destroy it
		$tokens->destroy( $code );
		return $return;
	}

}

