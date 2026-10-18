<?php
/**
 * Plugin Name: IndieAuth
 * Plugin URI: https://github.com/indieweb/wordpress-indieauth/
 * Description: IndieAuth is a way to allow users to use their own domain to sign into other websites and services
 * Version: 4.7.2
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

namespace IndieAuth;

\define( 'INDIEAUTH_PLUGIN_VERSION', '4.7.2' );
\define( 'INDIEAUTH_PLUGIN_DIR', \plugin_dir_path( __FILE__ ) );
\define( 'INDIEAUTH_PLUGIN_BASENAME', \plugin_basename( __FILE__ ) );
\define( 'INDIEAUTH_PLUGIN_FILE', INDIEAUTH_PLUGIN_DIR . \basename( __FILE__ ) );
\define( 'INDIEAUTH_PLUGIN_URL', \plugin_dir_url( __FILE__ ) );

// Feature flags.
\defined( 'INDIEAUTH_TICKET_ENDPOINT' ) || \define( 'INDIEAUTH_TICKET_ENDPOINT', 0 );
\defined( 'INDIEAUTH_ICON_QUALITY' ) || \define( 'INDIEAUTH_ICON_QUALITY', null );
\defined( 'INDIEAUTH_ICON_SIZE' ) || \define( 'INDIEAUTH_ICON_SIZE', 256 );

require_once __DIR__ . '/includes/class-autoloader.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/functions-api.php';

Autoloader::register_path( __NAMESPACE__, __DIR__ . '/includes' );

// Backward compatibility aliases for pre-4.7.0 class names.
require_once __DIR__ . '/includes/compat.php';

// Initialize the plugin.
$indieauth = IndieAuth::get_instance();
$indieauth->init();

\register_activation_hook( __FILE__, array( IndieAuth::class, 'activation' ) );
\register_deactivation_hook( __FILE__, array( IndieAuth::class, 'deactivation' ) );
\add_action( 'upgrader_process_complete', array( IndieAuth::class, 'upgrader_process_complete' ), 10, 2 );
\add_action( 'indieauth_cleanup', array( IndieAuth::class, 'expires' ) );
