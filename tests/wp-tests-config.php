<?php
/**
 * WordPress test-suite configuration, driven entirely by environment variables so
 * the same file serves a developer machine and CI.
 *
 * @package WPLM\Tests
 */

$wplm_env = static function ( string $name, string $default = '' ): string {
	$value = getenv( $name );
	return false === $value || '' === $value ? $default : $value;
};

$wplm_core = $wplm_env( 'WP_CORE_DIR' );
if ( '' === $wplm_core ) {
	fwrite( STDERR, "WP_CORE_DIR is not set — point it at a WordPress core directory (see tests/README.md).\n" );
	exit( 1 );
}

define( 'ABSPATH', rtrim( str_replace( '\\', '/', $wplm_core ), '/' ) . '/' );

define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', $wplm_env( 'WPLM_TESTS_DB_NAME', 'wplm_tests' ) );
define( 'DB_USER', $wplm_env( 'WPLM_TESTS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', $wplm_env( 'WPLM_TESTS_DB_PASSWORD', '' ) );
define( 'DB_HOST', $wplm_env( 'WPLM_TESTS_DB_HOST', '127.0.0.1' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

// Guard against the one mistake that destroys data: running the suite on a site DB.
if ( in_array( strtolower( DB_NAME ), array( 'wordpress', 'site', 'local', 'wp' ), true ) && '1' !== $wplm_env( 'WPLM_TESTS_ALLOW_DB_NAME' ) ) {
	fwrite( STDERR, 'Refusing to run: WPLM_TESTS_DB_NAME "' . DB_NAME . "\" looks like a site database. The test installer drops its tables.\n" );
	exit( 1 );
}

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'WPLM Tests' );

// The installer runs in a child PHP process; it needs the same binary and ini.
define( 'WP_PHP_BINARY', $wplm_env( 'WP_PHP_BINARY', PHP_BINARY ) );

define( 'WPLANG', '' );
