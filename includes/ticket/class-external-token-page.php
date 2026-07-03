<?php
/**
 * External Token Page class file.
 *
 * @package IndieAuth
 */

namespace IndieAuth\Ticket;

use IndieAuth\IndieAuth;
use IndieAuth\WP_Admin\External_Token_List_Table;

/**
 * Generates page for external token UI.
 *
 * @since 1.0.0
 */
class External_Token_Page {
	/**
	 * Function to Initialize the Configuration.
	 *
	 * @access public
	 */
	public function __construct() {
		\add_action( 'admin_init', array( $this, 'admin_init' ) );
		\add_action( 'admin_menu', array( $this, 'admin_menu' ), 11 );
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
		\add_users_page( \__( 'Manage External Tokens', 'indieauth' ), \__( 'Manage External Tokens', 'indieauth' ), 'read', 'indieauth_external_token', array( $this, 'options_form' ) );
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
		// Check to see if the cleanup function is scheduled.
		IndieAuth::schedule();

		$token_table = new External_Token_List_Table();
		echo '<div class="wrap"><h2>' . \esc_html__( 'Manage External Tokens', 'indieauth' ) . '</h2>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="indieauth_external_token" />';
		$token_table->prepare_items();
		$token_table->display();
		echo '</form>';
		?>
		</div>
		<?php
	}
} // End Class
