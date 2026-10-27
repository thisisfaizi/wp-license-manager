<?php
/**
 * Plugin uninstall routine.
 *
 * Keeps all data unless Settings → Uninstall → "Delete all data" is ticked (see Uninstaller).
 *
 * @package WPLM
 */

// Only run if called by WordPress uninstall machinery.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// WordPress loads only this file when a plugin is deleted: no autoloader.
require_once __DIR__ . '/src/Install/Tables.php';
require_once __DIR__ . '/src/Install/Uninstaller.php';

\WPLM\Install\Uninstaller::run();
