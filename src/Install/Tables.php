<?php
/**
 * The plugin's database tables, by name.
 *
 * @package WPLM\Install
 */

namespace WPLM\Install;

defined( 'ABSPATH' ) || exit;

/**
 * One list for the installer and for uninstall. It has no dependencies because uninstall.php loads it
 * without the plugin's autoloader: before 1.3.2 uninstall kept its own list, which missed the plans,
 * packages and entitlements tables.
 */
final class Tables {

	/** Table names without `{$wpdb->prefix}wplm_`, children before parents. */
	public const NAMES = array(
		'entitlements',
		'packages',
		'plans',
		'subscription_notes',
		'subscription_renewals',
		'subscription_items',
		'subscriptions',
		'license_meta',
		'blacklist',
		'webhook_log',
		'webhooks',
		'releases',
		'api_keys',
		'generators',
		'activation_log',
		'machine_components',
		'machines',
		'licenses',
	);
}
