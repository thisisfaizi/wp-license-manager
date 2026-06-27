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
		// Add migration methods here as the schema evolves, e.g.:
		// if ( version_compare( $this->installed_version, '1.1.0', '<' ) ) {
		// $this->migrate_to_1_1_0();
		// }
	}
}
