<?php
/**
 * What deleting the plugin does to its data.
 *
 * @package WPLM\Install
 */

namespace WPLM\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Deleting the plugin keeps every licence, key and setting unless the owner ticked
 * Settings → Uninstall → "Delete all data". Before 1.3.2 it deleted everything by default, and
 * replacing a copy installed under another folder name meant deleting the old one: that wiped a live
 * licence server's licences, machines and subscriptions.
 *
 * Loaded by uninstall.php without the plugin's autoloader, so it uses only WordPress and {@see Tables}.
 */
final class Uninstaller {

	/** Ticked in Settings to delete everything when the plugin is deleted. Off unless ticked. */
	public const DELETE_OPTION = 'wplm_delete_data_on_uninstall';

	/** Before 1.3.2: keep data. No screen ever set it, but a site that set it by hand keeps its data. */
	public const LEGACY_KEEP_OPTION = 'wplm_keep_data_on_uninstall';

	/** Every event the plugin schedules. Cleared either way: their handlers go with the plugin. */
	public const CRON_HOOKS = array(
		'wplm_cull_zombies',
		'wplm_five_minutes',
		'wplm_lapse_notices',
		'wplm_process_renewals',
		'wplm_retry_webhooks',
	);

	/** Whether deleting the plugin should delete its data. */
	public static function should_wipe(): bool {
		if ( get_option( self::LEGACY_KEEP_OPTION ) ) {
			return false;
		}
		return 1 === (int) get_option( self::DELETE_OPTION, 0 );
	}

	/**
	 * Run on plugin deletion.
	 *
	 * @return bool Whether the data was deleted.
	 */
	public static function run(): bool {
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		if ( ! self::should_wipe() ) {
			return false;
		}

		global $wpdb;

		foreach ( Tables::NAMES as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table names cannot be parameterised.
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wplm_{$table}`" );
		}

		// Every wplm_ option and transient, including the signing keypair and encryption key kept in
		// the database (the copies in wp-config.php are not touched).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$names = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'wplm_' ) . '%',
				$wpdb->esc_like( '_transient_wplm_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_wplm_' ) . '%'
			)
		);
		foreach ( $names as $name ) {
			delete_option( $name );
		}

		return true;
	}
}
