<?php
/**
 * Schema version migrator.
 *
 * @package WPLM\Install
 */

namespace WPLM\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Runs versioned migration routines when the plugin is upgraded.
 * Add a new method `migrate_to_X_Y_Z()` for each schema version bump; the
 * constructor records the installed version and run() fires all applicable steps.
 */
class Migrator {

	private string $installed_version;

	public function __construct( string $installed_version ) {
		$this->installed_version = $installed_version;
	}

	/** Execute all pending migration routines in order. */
	public function run(): void {
		if ( version_compare( $this->installed_version, '1.2.1', '<' ) ) {
			$this->migrate_to_1_2_1();
		}
	}

	/**
	 * Clear the zero-date leases written before 1.2.1.
	 *
	 * `MachineRepository::create()` bound a null lease as '', which MySQL outside strict mode stores as
	 * 0000-00-00 00:00:00. Every non-floating machine therefore looked leased, and the zombie cull
	 * deactivated it 20 minutes after its last heartbeat. Compared with '1000-01-01' rather than a zero
	 * literal, which a strict server refuses.
	 *
	 * @return void
	 */
	private function migrate_to_1_2_1(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "UPDATE {$wpdb->prefix}wplm_machines SET lease_expires_at = NULL WHERE lease_expires_at < '1000-01-01'" );
	}
}
