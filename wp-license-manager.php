<?php
/**
 * Plugin Name:       WP License Manager
 * Plugin URI:        https://github.com/thisisfaizi/wp-license-manager
 * Description:       Industry-grade software licensing for WooCommerce. Issue, validate, activate, monitor, and revoke license keys with a full REST API and native subscription engine.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      8.0
 * Author:            WPLM Contributors
 * Author URI:        https://github.com/thisisfaizi
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-license-manager
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   9.0
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'WPLM_VERSION', '1.1.0' );
define( 'WPLM_DB_VERSION', '1.1.0' );
define( 'WPLM_PLUGIN_FILE', __FILE__ );
define( 'WPLM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPLM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPLM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WPLM_MIN_PHP', '8.0' );
define( 'WPLM_MIN_WP', '5.8' );

// PHP version gate — bail early with an admin notice rather than a fatal.
if ( version_compare( PHP_VERSION, WPLM_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' .
				esc_html(
					sprintf(
						/* translators: 1: required PHP version 2: current PHP version */
						__( 'WP License Manager requires PHP %1$s or higher. You are running PHP %2$s.', 'wp-license-manager' ),
						WPLM_MIN_PHP,
						PHP_VERSION
					)
				) .
				'</p></div>';
		}
	);
	return;
}

// Autoloader — prefer Composer; fall back to a simple PSR-4 map.
if ( file_exists( WPLM_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once WPLM_PLUGIN_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		function ( string $class ) {
			if ( strpos( $class, 'WPLM\\' ) !== 0 ) {
				return;
			}
			$relative = substr( $class, strlen( 'WPLM\\' ) );
			$file     = WPLM_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

// Activation / deactivation / uninstall hooks.
register_activation_hook( __FILE__, array( 'WPLM\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPLM\\Plugin', 'deactivate' ) );

// Boot the plugin after all plugins are loaded so WooCommerce is available.
add_action( 'plugins_loaded', array( 'WPLM\\Plugin', 'get_instance' ) );
