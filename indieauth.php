<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Plugin Name: IndieAuth
 * Plugin URI: https://github.com/indieweb/wordpress-indieauth/
 * Description: IndieAuth is a way to allow users to use their own domain to sign into other websites and services
 * Version: 4.6.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires CP: 2.1
 * Author: IndieWeb WordPress Outreach Club
 * Author URI: https://indieweb.org/WordPress_Outreach_Club
 * License: MIT
 * License URI: http://opensource.org/licenses/MIT
 * Text Domain: indieauth
 * Domain Path: /languages
 *
 * @package IndieAuth
 */

// If this is set then it will enable the experimental Ticket Endpoint.
if ( ! defined( 'INDIEAUTH_TICKET_ENDPOINT' ) ) {
	define( 'INDIEAUTH_TICKET_ENDPOINT', 0 );
}

defined( 'INDIEAUTH_ICON_QUALITY' ) || define( 'INDIEAUTH_ICON_QUALITY', null );
defined( 'INDIEAUTH_ICON_SIZE' ) || define( 'INDIEAUTH_ICON_SIZE', 256 );

register_activation_hook( __FILE__, array( 'IndieAuth_Plugin', 'activation' ) );
register_deactivation_hook( __FILE__, array( 'IndieAuth_Plugin', 'deactivation' ) );


add_action( 'upgrader_process_complete', array( 'IndieAuth_Plugin', 'upgrader_process_complete' ), 10, 2 );
add_action( 'indieauth_cleanup', array( 'IndieAuth_Plugin', 'expires' ) );

/**
 * IndieAuth Plugin class.
 *
 * Main plugin class for IndieAuth.
 *
 * @since 1.0.0
 */
class IndieAuth_Plugin {

	/**
	 * Loaded instance of authorize class.
	 *
	 * @var IndieAuth_Authorize|null
	 */
	public static $indieauth = null;

	/**
	 * Loaded instance of metadata class.
	 *
	 * @var IndieAuth_Metadata_Endpoint|null
	 */
	public static $metadata = null;

	/**
	 * Loaded instance of scopes class.
	 *
	 * @var IndieAuth_Scopes|null
	 */
	public static $scopes = null;

	/**
	 * Process to trigger on plugin update.
	 *
	 * @param WP_Upgrader $upgrade_object Upgrader object.
	 * @param array       $options        Upgrade options.
	 */
	public static function upgrader_process_complete( $upgrade_object, $options ) {
		$current_plugin_path_name = plugin_basename( __FILE__ );
		if ( ( 'update' === $options['action'] ) && ( 'plugin' === $options['type'] ) ) {
			foreach ( $options['plugins'] as $each_plugin ) {
				if ( $each_plugin === $current_plugin_path_name ) {
					self::schedule();
				}
			}
		}
	}

	/**
	 * Plugin deactivation.
	 */
	public static function deactivation() {
		self::cancel_schedule();
	}

	/**
	 * Cancel scheduled cleanup event.
	 */
	public static function cancel_schedule() {
		$timestamp = wp_next_scheduled( 'indieauth_cleanup', array( false ) );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'indieauth_cleanup', array( false ) );
		}
	}

	/**
	 * Plugin activation.
	 */
	public static function activation() {
		self::schedule();

		// Flush rewrite rules for FedCM well-known endpoint.
		// Load the class file explicitly since activation runs before init.
		$webidentity_file = plugin_dir_path( __FILE__ ) . 'includes/class-indieauth-webidentity.php';
		if ( file_exists( $webidentity_file ) ) {
			require_once $webidentity_file;
			if ( class_exists( 'IndieAuth_WebIdentity' ) ) {
				IndieAuth_WebIdentity::flush_rewrite_rules();
			}
		}
	}

	/**
	 * Schedule cleanup event.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'indieauth_cleanup', array( false ) ) ) {
			return wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'indieauth_cleanup', array( false ) );
		}
		return true;
	}

	/**
	 * Expires authorization codes in the event any are left in the system.
	 */
	public static function expires() {
		// The get_all function retrieves all tokens and destroys any expired token.
		$t = new Token_User( '_indieauth_token_' );
		$t->get_all();
		$t = new Token_User( '_indieauth_code_' );
		$t->get_all();
		$t = new Token_User( '_indieauth_refresh_token_' );
		$t->get_all();
		if ( class_exists( 'External_User_Token' ) ) {
			$t = new External_User_Token();
			$t->expire_all_tokens();
		}
	}

	/**
	 * Initialize the plugin.
	 */
	public static function init() {
		// Load core classes that are always loaded.
		self::load(
			array(
				'functions.php', // Global functions.
				'class-oauth-response.php', // OAuth REST error class.
				'class-indieauth-client.php', // IndieAuth client class.
				'class-indieauth-metadata-endpoint.php', // Metadata endpoint.
				'class-token-generic.php', // Token base class.
				'class-token-user.php',
				'class-indieauth-scope.php', // Scope class.
				'class-indieauth-scopes.php', // Scopes class.
				'class-indieauth-authorize.php', // IndieAuth authorization base class.
				'class-token-transient.php',
				'class-web-signin.php',
				'class-indieauth-admin.php', // Administration class.
			)
		);

		static::$scopes = new IndieAuth_Scopes();

		new IndieAuth_Admin();

		// Classes required for the local endpoint.
		$localfiles = array(
			'class-indieauth-client-discovery.php', // Client discovery.
			'class-indieauth-client-taxonomy.php', // Client taxonomy.
			'class-indieauth-endpoint.php', // Endpoint base class.
			'class-indieauth-token-endpoint.php', // Token endpoint.
			'class-indieauth-authorization-endpoint.php', // Authorization endpoint.
			'class-indieauth-revocation-endpoint.php', // Revocation endpoint.
			'class-indieauth-introspection-endpoint.php', // Introspection endpoint.
			'class-indieauth-userinfo-endpoint.php', // User info endpoint.
			'class-indieauth-fedcm-endpoint.php', // FedCM endpoint.
			'class-indieauth-webidentity.php', // Web Identity well-known handler.
			'class-token-list-table.php', // Token management UI.
			'class-indieauth-token-ui.php',
		);

		self::load( $localfiles );
		static::$indieauth = new IndieAuth_Authorize();
		static::$metadata  = new IndieAuth_Metadata_Endpoint();
		new IndieAuth_Authorization_Endpoint();
		new IndieAuth_Token_Endpoint();
		new IndieAuth_Revocation_Endpoint();
		new IndieAuth_Introspection_Endpoint();
		new IndieAuth_Userinfo_Endpoint();
		new IndieAuth_FedCM_Endpoint();
		new IndieAuth_WebIdentity();

		if ( WP_DEBUG ) {
			self::load( 'class-indieauth-debug.php' );
			new IndieAuth_Debug();
		}

		if ( INDIEAUTH_TICKET_ENDPOINT ) {
			$ticket_load = array(
				'class-external-user-token.php', // External token class.
				'class-external-token-table.php', // Token management UI.
				'class-external-token-page.php',
				'class-indieauth-ticket-endpoint.php',
			);
			self::load( $ticket_load );
			new IndieAuth_Ticket_Endpoint();
		}
	}

	/**
	 * Check that a file exists before loading it and if it does not print to the error log.
	 *
	 * @param string|array $files Files to load.
	 * @param string       $dir   Directory prefix.
	 */
	public static function load( $files, $dir = 'includes/' ) {
		if ( empty( $files ) ) {
			return;
		}
		$path = plugin_dir_path( __FILE__ ) . $dir;

		if ( is_string( $files ) ) {
			$files = array( $files );
		}
		foreach ( $files as $file ) {
			if ( file_exists( $path . $file ) ) {
				require_once $path . $file;
			} else {
				error_log( // phpcs:ignore
					// translators: 1. Path to file unable to load.
					sprintf( __( 'Unable to load: %1s', 'indieauth' ), $path . $file )
				);
			}
		}
	}
}

add_action( 'init', array( 'IndieAuth_Plugin', 'init' ), 2 );
