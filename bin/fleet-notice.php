<?php
/**
 * Sign a Super Ledger fleet extension notice from the offline key backup — no WordPress needed.
 *
 * Usage:
 *   php bin/fleet-notice.php --until=YYYY-MM-DD --keypair-file=/path/to/keypair.json
 *                            [--timezone=Asia/Karachi] [--now=<unix>]
 *
 * The keypair file is the JSON WPLM stores (`{"sec": base64, "pub": base64}`, the value of the
 * WPLM_SIGNING_KEYPAIR constant). A bare date means the end of that day in --timezone. The notice may
 * extend check-in by at most 30 days from now. Prints the notice on stdout; publish it at the fixed
 * static URL described in docs/super-ledger-deployment.md.
 *
 * Needs PHP 8.0+ with the sodium extension. Exit code 0 on success, 1 on any error.
 *
 * @package WPLM
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- runs without WordPress by design.

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

define( 'WPLM_STANDALONE', true );

require __DIR__ . '/../src/Crypto/CompactToken.php';
require __DIR__ . '/../src/Licensing/FleetNotice.php';

use WPLM\Crypto\CompactToken;
use WPLM\Licensing\FleetNotice;

$options = getopt( '', array( 'until:', 'keypair-file:', 'timezone::', 'now::' ) );

try {
	if ( ! extension_loaded( 'sodium' ) ) {
		throw new RuntimeException( 'The sodium PHP extension is required.' );
	}
	if ( empty( $options['until'] ) || empty( $options['keypair-file'] ) ) {
		throw new InvalidArgumentException( 'Usage: php bin/fleet-notice.php --until=YYYY-MM-DD --keypair-file=/path/to/keypair.json [--timezone=Asia/Karachi]' );
	}
	$keypair_json = is_readable( $options['keypair-file'] ) ? file_get_contents( $options['keypair-file'] ) : false;
	if ( false === $keypair_json ) {
		throw new InvalidArgumentException( sprintf( 'Cannot read the keypair file "%s".', $options['keypair-file'] ) );
	}

	$keypair = CompactToken::keypair_from_json( $keypair_json );
	$now     = isset( $options['now'] ) && is_numeric( $options['now'] ) ? (int) $options['now'] : time();
	$until   = FleetNotice::parse_until( (string) $options['until'], (string) ( $options['timezone'] ?? 'Asia/Karachi' ) );
	$notice  = FleetNotice::build( $until, $now );

	fwrite( STDERR, sprintf( "Fleet notice: check-in may stretch to %s UTC (%.1f days).\n", gmdate( 'Y-m-d H:i:s', $until ), ( $until - $now ) / 86400 ) );
	fwrite( STDOUT, FleetNotice::sign( $notice, $keypair['sec'] ) . "\n" );
	exit( 0 );
} catch ( Throwable $e ) {
	fwrite( STDERR, 'Error: ' . $e->getMessage() . "\n" );
	exit( 1 );
}
