<?php
/**
 * Plugin Name: IndieAuth
 * Plugin URI: https://github.com/indieweb/wordpress-indieauth/
 * Description: IndieAuth is a way to allow users to use their own domain to sign into other websites and services
 * Version: 4.3.0
 * Author: IndieWebCamp WordPress Outreach Club
 * Author URI: https://indieweb.org/WordPress_Outreach_Club
 * License: MIT
 * License URI: http://opensource.org/licenses/MIT
 * Text Domain: indieauth
 * Domain Path: /languages
 */


/* If this is set then it will activate the remote mode for delegating your login to a remote endpoint */
if ( ! defined( 'INDIEAUTH_REMOTE_MODE' ) ) {
	define( 'INDIEAUTH_REMOTE_MODE', 0 );
}


/* If this is set then it will enable the experimental Ticket Endpoint */
if ( ! defined( 'INDIEAUTH_TICKET_ENDPOINT' ) ) {
	define( 'INDIEAUTH_TICKET_ENDPOINT', 0 );
}

defined( 'INDIEAUTH_ICON_QUALITY' ) || define( 'INDIEAUTH_ICON_QUALITY', null );
defined( 'INDIEAUTH_ICON_SIZE' ) || define( 'INDIEAUTH_ICON_SIZE', 256 );

register_activation_hook( __FILE__, array( 'IndieAuth_Plugin', 'activation' ) );
register_deactivation_hook( __FILE__, array( 'IndieAuth_Plugin', 'deactivation' ) );


add_action( 'upgrader_process_complete', array( 'IndieAuth_Plugin', 'upgrader_process_complete' ), 10, 2 );
add_action( 'indieauth_cleanup', array( 'IndieAuth_Plugin', 'expires' ) );

class IndieAuth_Plugin {
	public static $indieauth = null; // Loaded instance of authorize class
	public static $metadata  = null; // Loaded instance of metadata class
	public static $scopes    = null; // Loaded instance of scopes class

	/*
	 * Process to Trigger on Plugin Update.
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

	public static function deactivation() {
		self::cancel_schedule();
	}

	public static function cancel_schedule() {
		$timestamp = wp_next_scheduled( 'indieauth_cleanup', array( false ) );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'indieauth_cleanup', array( false ) );
		}
	}

	public static function activation() {
		self::schedule();
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( 'indieauth_cleanup', array( false ) ) ) {
			return wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'indieauth_cleanup', array( false ) );
		}
		return true;
	}

	/*
	 * Expires authorization codes in the event any are left in the system.
	 *
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

	public static function plugins_loaded() {
		// Load Core Classes that are always loaded
		self::load(
			array(
				'functions.php', // Global Functions
				'class-oauth-response.php', // OAuth REST Error Class
				'class-token-generic.php', // Token Base Class
				'class-token-user.php',
				'class-indieauth-scope.php', // Scope Class
				'class-indieauth-scopes.php', // Scopes Class
				'class-indieauth-authorize.php', // IndieAuth Authorization Base Class
				'class-token-transient.php',
				'class-web-signin.php',
				'class-indieauth-admin.php', // Administration Class
			)
		);

		static::$scopes = new IndieAuth_Scopes();

		new IndieAuth_Admin();

		// Classes Required for the Local Endpoint
		$localfiles = array(
			'class-indieauth-client-discovery.php', // Client Discovery
			'class-indieauth-client-taxonomy.php', // Client Taxonomy
			'class-indieauth-endpoint.php', // Endpoint Base Class
			'class-indieauth-token-endpoint.php', // Token Endpoint
			'class-indieauth-authorization-endpoint.php', // Authorization Endpoint
			'class-indieauth-metadata-endpoint.php', // Metadata Endpoint
			'class-indieauth-revocation-endpoint.php', // Revocation Endpoint
			'class-indieauth-introspection-endpoint.php', // Introspection Endpoint
			'class-indieauth-userinfo-endpoint.php', // User Info Endpoint
			'class-token-list-table.php', // Token Management UI
			'class-indieauth-token-ui.php',
			'class-indieauth-local-authorize.php',
		);

		// Classes Require for using a Remote Endpoint
		$remotefiles = array(
			'class-indieauth-remote-authorize.php',
		);

		// $load        = get_option( 'indieauth_config', 'local' );
		$load = INDIEAUTH_REMOTE_MODE ? 'remote' : 'local';

		switch ( $load ) {
			case 'remote':
				self::load( $remotefiles );
				static::$indieauth = new IndieAuth_Remote_Authorize();
				break;
			default:
				self::load( $localfiles );
				new IndieAuth_Authorization_Endpoint();
				new IndieAuth_Token_Endpoint();
				static::$indieauth = new IndieAuth_Local_Authorize();
				static::$metadata  = new IndieAuth_Metadata_Endpoint();
				new IndieAuth_Revocation_Endpoint();
				new IndieAuth_Introspection_Endpoint();
				new IndieAuth_Userinfo_Endpoint();
				break;
		}

		if ( WP_DEBUG ) {
			self::load( 'class-indieauth-debug.php' );
			new IndieAuth_Debug();
		}

		if ( INDIEAUTH_TICKET_ENDPOINT ) {
			$ticket_load = array(
				'class-external-token.php', // External Token Class
				'class-external-token-table.php', // Token Management UI
				'class-external-token-page.php',
				'class-indieauth-ticket-endpoint.php',
			);
			self::load( $ticket_load );
			new IndieAuth_Ticket_Endpoint();
		}
	}

	// Check that a file exists before loading it and if it does not print to the error log
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
					// translators: 1. Path to file unable to load
					sprintf( __( 'Unable to load: %1s', 'indieauth' ), $path . $file )
				);
			}
		}
	}
}

add_action( 'plugins_loaded', array( 'IndieAuth_Plugin', 'plugins_loaded' ), 2 );
