<?php
/**
 * PHPUnit bootstrap: real WordPress (wp-phpunit) + WooCommerce + WPLM.
 *
 * @package WPLM\Tests
 */

$wplm_root = dirname( __DIR__ );

require_once $wplm_root . '/vendor/autoload.php';

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}

$wplm_wp_tests = getenv( 'WP_TESTS_DIR' ) ?: $wplm_root . '/vendor/wp-phpunit/wp-phpunit';

require_once $wplm_wp_tests . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $wplm_root ) {
		$wc = getenv( 'WC_PLUGIN_DIR' ) ?: ABSPATH . 'wp-content/plugins/woocommerce';
		if ( ! file_exists( $wc . '/woocommerce.php' ) ) {
			fwrite( STDERR, "WooCommerce not found at {$wc} — set WC_PLUGIN_DIR (see tests/README.md).\n" );
			exit( 1 );
		}
		require_once $wc . '/woocommerce.php';
		require_once $wplm_root . '/wp-license-manager.php';
		// Secrets are options; seed them before WPLM boots on plugins_loaded.
		( new \WPLM\Install\Seeder() )->run();
	}
);

// Install WooCommerce's and WPLM's tables into the test database once per run.
tests_add_filter(
	'setup_theme',
	static function () {
		WC_Install::install();
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();
		\WPLM\Plugin::activate();
	}
);

require_once $wplm_wp_tests . '/includes/bootstrap.php';
require_once __DIR__ . '/TestCase.php';
