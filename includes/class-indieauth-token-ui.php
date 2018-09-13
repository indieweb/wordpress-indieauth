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
		add_action( 'admin_action_indieauth_newtoken', array( $this, 'new_token' ) );
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

	public function new_token() {
		if ( ! isset( $_POST['indieauth_nonce'] )
				|| ! wp_verify_nonce( $_POST['indieauth_nonce'], 'indieauth_newtoken' )
		) {
			esc_html_e( 'Invalid Nonce', 'indieauth' );
			exit;
		}
		$GLOBALS['title'] = esc_html__( 'Add IndieAuth Token', 'indieauth' ); // phpcs:ignore
		if ( empty( $_REQUEST['client_name'] ) ) {
			esc_html_e( 'A Name Must be Set for Your Token', 'indieauth' );
			exit;
		}
		require ABSPATH . 'wp-admin/admin-header.php';
		$token = self::generate_local_token( $_REQUEST['client_name'] );
		?>
	<p><?php esc_html_e( 'A token has been generated and appears below. This token will not be stored anywhere. Please copy and store it.', 'indieauth' ); ?></p>
	<h3><?php echo $token; // phpcs:ignore 
	?>
	</h3> 
		<?php
		require ABSPATH . 'wp-admin/admin-footer.php';
		exit;
	}

	private function generate_local_token( $name ) {
		$user_id = get_current_user_id();
		$tokens  = new Token_User( '_indieauth_token_' );
		$tokens->set_user( $user_id );
		$token = array(
			'token_type'  => 'Bearer',
			'scope'       => 'create update',
			'me'          => get_the_author_meta( 'user_url', $user_id ) ? get_the_author_meta( 'user_url', $user_id ) : get_author_posts_url( $user_id ),
			'issued_by'   => rest_url( 'indieauth/1.0/token' ),
			'user'        => $user_id,
			'client_id'   => admin_url(),
			'client_name' => wp_strip_all_tags( $name ),
			'client_icon' => get_avatar_url( $user_id ),
			'issued_at'   => current_time( 'timestamp', 1 ),
		);
		return $tokens->set( $token );
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
		$token_table = new Token_List_Table();
		echo '<div class="wrap"><h2>' . esc_html__( 'Manage IndieAuth Tokens', 'indieauth' ) . '</h2>';
		$token_table->prepare_items();
		$token_table->display();
		?>
		</div>
		<div> 
		<h3><?php esc_html_e( 'Add Token', 'indieauth' ); ?></h3>
		<form method="post" action="admin.php">
		<label for="client_name"><?php esc_html_e( 'Name for Token', 'indieauth' ); ?></label><input type="text" class="widefat" id="client_name" name="client_name" />
			<?php wp_nonce_field( 'indieauth_newtoken', 'indieauth_nonce' ); ?>
			<input type="hidden" name="action" id="action" value="indieauth_newtoken" />
			<p><button class="button-primary"><?php esc_html_e( 'Add New Token', 'indieauth' ); ?></button></p>
		</form>
		</div>
		<?php
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

} // End Class

new IndieAuth_Token_UI();

