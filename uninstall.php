<?php
/**
 * Plugin uninstall routine.
 *
 * @package WPLM
 */

// Only run if called by WordPress uninstall machinery.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Honor the "keep data on uninstall" option so admins don't lose license records
// by accident. Default: remove everything.
if ( get_option( 'wplm_keep_data_on_uninstall' ) ) {
	return;
}

global $wpdb;

$tables = array(
	'wplm_subscription_notes',
	'wplm_subscription_renewals',
	'wplm_subscription_items',
	'wplm_subscriptions',
	'wplm_license_meta',
	'wplm_blacklist',
	'wplm_webhook_log',
	'wplm_webhooks',
	'wplm_releases',
	'wplm_api_keys',
	'wplm_generators',
	'wplm_activation_log',
	'wplm_machine_components',
	'wplm_machines',
	'wplm_licenses',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names cannot be parameterised
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
}

// Remove all options.
$option_keys = array(
	'wplm_db_version',
	'wplm_signing_keypair',
	'wplm_encryption_key',
	'wplm_fingerprint_hmac',
	'wplm_webhook_secret',
	'wplm_default_max_activations',
	'wplm_default_overage_strategy',
	'wplm_heartbeat_interval',
	'wplm_zombie_window',
	'wplm_telemetry_retention_days',
	'wplm_order_complete_status',
	'wplm_hide_key_in_email',
	'wplm_force_ssl',
	'wplm_sub_engine_enabled',
	'wplm_dunning_schedule',
	'wplm_qr_enabled',
	'wplm_qr_size',
	'wplm_webhook_retry_attempts',
	'wplm_keep_data_on_uninstall',
);

foreach ( $option_keys as $key ) {
	delete_option( $key );
}

// Remove scheduled cron events.
$hooks = array( 'wplm_process_renewals', 'wplm_cull_zombies', 'wplm_retry_webhooks' );
foreach ( $hooks as $hook ) {
	wp_clear_scheduled_hook( $hook );
}
