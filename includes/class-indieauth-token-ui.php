<?php
/**
 * Generates page for token UI.
 *
 * @package IndieAuth
 */
class IndieAuth_Token_UI {
	/**
	 * Function to Initialize the Configuration.
	 *
	 * @access public
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 11 );
	}

	/**
	 * Function to Set up Settings.
	 *
	 * @access public
	 */
	public function admin_init() {
	}

	/**
	 * Adds Options Page for Plugin Options.
	 *
	 * @access public
	 */
	public function admin_menu() {
		add_users_page( __( 'Manage IndieAuth Tokens', 'indieauth' ), __( 'Manage Tokens', 'indieauth' ), 'read', 'indieauth_user_token', array( $this, 'options_form' ) );
	}

	/**
	 * Callback for Options on Options Page.
	 *
	 * @access public
	 */
	public function options_callback() {
	}


	/**
	 * Generate Options Form.
	 *
	 * @access public
	 */
	public function options_form() {
		// As a precaution every time the Token UI page is lost it will check for any expired auth codes and purge them
		$codes = new Token_User( '_indieauth_code_', get_current_user_id() );
		$codes->check_expires();
		load_template( plugin_dir_path( __DIR__ ) . 'templates/indieauth-token-ui.php' );
	}

	/**
	 * Is prefix in string.
	 *
	 * @param  string $source The source string.
	 * @param  string $prefix The prefix you wish to check for in source.
	 * @return boolean The result.
	 */
	public static function str_prefix( $source, $prefix ) {
		return strncmp( $source, $prefix, strlen( $prefix ) ) === 0;
	}

	public static function token_form_table() {
		$t      = new Token_User( '_indieauth_token_', get_current_user_id() );
		$tokens = $t->get_all();
		if ( ! is_array( $tokens ) ) {
			return;
		}
		echo '<div>';
		foreach ( $tokens as $key => $value ) {
			// phpcs:ignore
			echo '<input type="radio" name="token" value="' . $key . '" />';
			// translators: Issued for client id url at date
			echo esc_html( sprintf( __( 'Issued with %1$s for %2$s at %3$s', 'indieauth' ), $value['scope'], $value['client_id'], date_i18n( DATE_W3C, $value['issued_at'] ) ) );
			echo PHP_EOL . '<br />';
		}
		echo '</div>';
	}
} // End Class

new IndieAuth_Token_UI();

