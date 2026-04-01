<?php
/**
 * IndieAuth main class file.
 *
 * @package IndieAuth
 */

namespace IndieAuth;

use IndieAuth\WP_Admin\Admin;
use IndieAuth\WP_Admin\Token_UI;
use IndieAuth\Rest\Authorization_Controller;
use IndieAuth\Rest\FedCM_Controller;
use IndieAuth\Rest\Introspection_Controller;
use IndieAuth\Rest\Metadata_Controller;
use IndieAuth\Rest\Revocation_Controller;
use IndieAuth\Rest\Ticket_Controller;
use IndieAuth\Rest\Token_Controller;
use IndieAuth\Rest\Userinfo_Controller;
use IndieAuth\WebIdentity;
use IndieAuth\Ticket\External_User_Token;
use IndieAuth\Token\User as Token_User;

/**
 * IndieAuth Plugin class.
 */
class IndieAuth {

	/**
	 * Instance of the class.
	 *
	 * @var IndieAuth
	 */
	private static $instance;

	/**
	 * Loaded instance of scopes class.
	 *
	 * @var Scopes|null
	 */
	public static $scopes = null;

	/**
	 * Loaded instance of metadata controller.
	 *
	 * @var Metadata_Controller|null
	 */
	public static $metadata = null;

	/**
	 * Loaded instance of authorization controller.
	 *
	 * @var Authorization_Controller|null
	 */
	private $authorization = null;

	/**
	 * Loaded instance of token controller.
	 *
	 * @var Token_Controller|null
	 */
	private $token = null;

	/**
	 * Loaded instance of revocation controller.
	 *
	 * @var Revocation_Controller|null
	 */
	private $revocation = null;

	/**
	 * Loaded instance of introspection controller.
	 *
	 * @var Introspection_Controller|null
	 */
	private $introspection = null;

	/**
	 * Loaded instance of userinfo controller.
	 *
	 * @var Userinfo_Controller|null
	 */
	private $userinfo = null;

	/**
	 * Loaded instance of FedCM controller.
	 *
	 * @var FedCM_Controller|null
	 */
	private $fedcm = null;

	/**
	 * Loaded instance of ticket controller.
	 *
	 * @var Ticket_Controller|null
	 */
	private $ticket = null;

	/**
	 * Whether the class has been initialized.
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Get the instance of the class.
	 *
	 * @return IndieAuth
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Do not allow multiple instances of the class.
	 */
	private function __construct() {
		// Do nothing.
	}

	/**
	 * Initialize the plugin.
	 */
	public function init() {
		if ( $this->initialized ) {
			return;
		}

		$this->register_hooks();
		$this->register_rest_routes();
		$this->register_admin_hooks();

		$this->initialized = true;
	}

	/**
	 * Register hooks.
	 */
	private function register_hooks() {
		\add_action( 'init', array( $this, 'init_plugin' ), 2 );
	}

	/**
	 * Initialize plugin components on init hook.
	 *
	 * Runs at priority 2 to match original plugin behavior. Components that
	 * use translations or register filters must be created on init, not earlier.
	 */
	public function init_plugin() {
		static::$scopes = new Scopes();
		static::$scopes->init();

		new Authorize();
		Client_Taxonomy::init();
		new Web_Signin();

		if ( \WP_DEBUG ) {
			new Debug();
		}
	}

	/**
	 * Register REST API routes and controller hooks.
	 */
	private function register_rest_routes() {
		// Instantiate controllers so hooks can reference them.
		static::$metadata    = new Metadata_Controller();
		$this->authorization = new Authorization_Controller();
		$this->token         = new Token_Controller();
		$this->revocation    = new Revocation_Controller();
		$this->introspection = new Introspection_Controller();
		$this->userinfo      = new Userinfo_Controller();
		$this->fedcm         = new FedCM_Controller();

		// Metadata Controller hooks.
		\add_filter( 'rest_pre_serve_request', array( static::$metadata, 'serve_request' ), 11, 4 );
		\add_filter( 'rest_index', array( static::$metadata, 'register_index' ) );
		\add_action( 'rest_api_init', array( static::$metadata, 'register_routes' ) );

		// Authorization Controller hooks.
		\add_action( 'rest_api_init', array( $this->authorization, 'register_routes' ) );
		\add_action( 'login_form_indieauth', array( $this->authorization, 'login_form_indieauth' ) );
		\add_filter( 'indieauth_metadata', array( $this->authorization, 'metadata' ) );
		\add_filter( 'rest_index_indieauth_endpoints', array( $this->authorization, 'rest_index' ) );

		// Token Controller hooks.
		\add_action( 'rest_api_init', array( $this->token, 'register_routes' ) );
		\add_filter( 'indieauth_metadata', array( $this->token, 'metadata' ) );
		\add_filter( 'rest_index_indieauth_endpoints', array( $this->token, 'rest_index' ) );

		// Revocation Controller hooks.
		\add_action( 'rest_api_init', array( $this->revocation, 'register_routes' ) );
		\add_filter( 'indieauth_metadata', array( $this->revocation, 'metadata' ) );
		\add_filter( 'rest_index_indieauth_endpoints', array( $this->revocation, 'rest_index' ) );

		// Introspection Controller hooks.
		\add_action( 'rest_api_init', array( $this->introspection, 'register_routes' ) );
		\add_filter( 'indieauth_metadata', array( $this->introspection, 'metadata' ) );
		\add_filter( 'rest_index_indieauth_endpoints', array( $this->introspection, 'rest_index' ) );

		// Userinfo Controller hooks.
		\add_action( 'rest_api_init', array( $this->userinfo, 'register_routes' ) );
		\add_filter( 'indieauth_metadata', array( $this->userinfo, 'metadata' ) );
		\add_filter( 'rest_index_indieauth_endpoints', array( $this->userinfo, 'rest_index' ) );

		// FedCM Controller hooks.
		\add_action( 'rest_api_init', array( $this->fedcm, 'register_routes' ) );
		\add_filter( 'indieauth_metadata', array( $this->fedcm, 'metadata' ) );
		\add_filter( 'rest_index_indieauth_endpoints', array( $this->fedcm, 'rest_index' ) );
		\add_action( 'set_logged_in_cookie', array( $this->fedcm, 'set_login_status_logged_in' ) );
		\add_action( 'clear_auth_cookie', array( $this->fedcm, 'set_login_status_logged_out' ) );
		\add_action( 'admin_enqueue_scripts', array( $this->fedcm, 'enqueue_scripts' ) );

		// Centralized HTTP and HTML header output.
		\add_action( 'wp_head', array( $this, 'html_headers' ) );
		\add_action( 'template_redirect', array( $this, 'http_headers' ) );

		// WebIdentity uses rewrite rules, not REST.
		WebIdentity::init();

		// Ticket Controller (conditional).
		if ( INDIEAUTH_TICKET_ENDPOINT ) {
			$this->ticket = new Ticket_Controller();
			\add_action( 'rest_api_init', array( $this->ticket, 'register_routes' ) );
			\add_filter( 'indieauth_metadata', array( $this->ticket, 'metadata' ) );
			\add_action( 'indieauth_ticket_redeemed', array( $this->ticket, 'notify' ) );
		}
	}

	/**
	 * Output HTTP Link headers on author and front pages.
	 */
	public function http_headers() {
		if ( ! \is_author() && ! \is_front_page() ) {
			return;
		}

		$headers = array(
			'indieauth-metadata'     => static::$metadata,
			'authorization_endpoint' => $this->authorization,
			'token_endpoint'         => $this->token,
		);

		if ( INDIEAUTH_TICKET_ENDPOINT && $this->ticket ) {
			$headers['ticket_endpoint'] = $this->ticket;
		}

		foreach ( $headers as $rel => $controller ) {
			header( sprintf( 'Link: <%s>; rel="%s"', $controller->get_endpoint(), $rel ), false );
		}
	}

	/**
	 * Output HTML link headers on author and front pages.
	 */
	public function html_headers() {
		if ( ! \is_author() && ! \is_front_page() ) {
			return;
		}

		$headers = array(
			'indieauth-metadata'     => static::$metadata,
			'authorization_endpoint' => $this->authorization,
			'token_endpoint'         => $this->token,
		);

		if ( INDIEAUTH_TICKET_ENDPOINT && $this->ticket ) {
			$headers['ticket_endpoint'] = $this->ticket;
		}

		$kses = array(
			'link' => array(
				'href' => array(),
				'rel'  => array(),
			),
		);

		foreach ( $headers as $rel => $controller ) {
			echo \wp_kses( sprintf( '<link rel="%s" href="%s" />' . PHP_EOL, $rel, $controller->get_endpoint() ), $kses );
		}
	}

	/**
	 * Register admin hooks.
	 */
	private function register_admin_hooks() {
		new Admin();
		new Token_UI();

		if ( INDIEAUTH_TICKET_ENDPOINT ) {
			new Ticket\External_Token_Page();
		}
	}

	/**
	 * Plugin activation.
	 */
	public static function activation() {
		self::schedule();

		// Flush rewrite rules for FedCM well-known endpoint.
		WebIdentity::flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation.
	 */
	public static function deactivation() {
		self::cancel_schedule();
	}

	/**
	 * Process to trigger on plugin update.
	 *
	 * @param \WP_Upgrader $upgrade_object Upgrader object.
	 * @param array        $options        Upgrade options.
	 */
	public static function upgrader_process_complete( $upgrade_object, $options ) {
		if ( ( 'update' === $options['action'] ) && ( 'plugin' === $options['type'] ) ) {
			$current_plugin_path_name = \plugin_basename( INDIEAUTH_PLUGIN_FILE );
			foreach ( $options['plugins'] as $each_plugin ) {
				if ( $each_plugin === $current_plugin_path_name ) {
					self::schedule();
				}
			}
		}
	}

	/**
	 * Schedule cleanup event.
	 *
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public static function schedule() {
		if ( ! \wp_next_scheduled( 'indieauth_cleanup', array( false ) ) ) {
			return \wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'indieauth_cleanup', array( false ) );
		}
		return true;
	}

	/**
	 * Cancel scheduled cleanup event.
	 */
	public static function cancel_schedule() {
		$timestamp = \wp_next_scheduled( 'indieauth_cleanup', array( false ) );
		if ( $timestamp ) {
			\wp_unschedule_event( $timestamp, 'indieauth_cleanup', array( false ) );
		}
	}

	/**
	 * Expires authorization codes in the event any are left in the system.
	 */
	public static function expires() {
		$t = new Token_User( '_indieauth_token_' );
		$t->get_all();
		$t = new Token_User( '_indieauth_code_' );
		$t->get_all();
		$t = new Token_User( '_indieauth_refresh_token_' );
		$t->get_all();
		if ( INDIEAUTH_TICKET_ENDPOINT ) {
			$t = new External_User_Token();
			$t->expire_all_tokens();
		}
	}
}
