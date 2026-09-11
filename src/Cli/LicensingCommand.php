<?php
/**
 * WP-CLI commands for entitlement licences: offline codes, fleet notices, contract fixtures.
 *
 * @package WPLM\Cli
 */

namespace WPLM\Cli;

use WPLM\Crypto\CompactToken;
use WPLM\Licensing\ContractFixtures;
use WPLM\Licensing\FleetNotice;
use WPLM\Licensing\OfflineCodeService;
use WPLM\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Licence tooling for Super Ledger (M5-27a).
 */
class LicensingCommand {

	/**
	 * Sign a fleet extension notice from a keypair file.
	 *
	 * Reads the keypair from a file (the offline key backup), not from the site, so it signs with the
	 * key you hold. With WordPress down, run bin/fleet-notice.php instead — same arguments.
	 *
	 * ## OPTIONS
	 *
	 * --until=<date>
	 * : YYYY-MM-DD (end of that day in --timezone) or an ISO 8601 time with an offset. At most 30 days from now.
	 *
	 * --keypair-file=<path>
	 * : JSON {"sec": base64, "pub": base64}.
	 *
	 * [--timezone=<tz>]
	 * : Time zone for a bare date.
	 * ---
	 * default: Asia/Karachi
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplm fleet-notice --until=2026-09-30 --keypair-file=/secure/wplm-keypair.json
	 *
	 * @subcommand fleet-notice
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function fleet_notice( array $args, array $assoc_args ): void {
		try {
			$file = (string) $assoc_args['keypair-file'];
			$json = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $json ) {
				\WP_CLI::error( sprintf( 'Cannot read the keypair file "%s".', $file ) );
			}
			$keypair = CompactToken::keypair_from_json( $json );
			$now     = time();
			$until   = FleetNotice::parse_until( (string) $assoc_args['until'], (string) ( $assoc_args['timezone'] ?? 'Asia/Karachi' ) );
			$token   = FleetNotice::sign( FleetNotice::build( $until, $now ), $keypair['sec'] );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		\WP_CLI::log( sprintf( 'Check-in may stretch to %s UTC.', gmdate( 'Y-m-d H:i:s', $until ) ) );
		\WP_CLI::line( $token );
	}

	/**
	 * Issue an offline renewal code for one machine.
	 *
	 * ## OPTIONS
	 *
	 * <machine-id>
	 * : The machine row id.
	 *
	 * [--days=<days>]
	 * : Days until the office must check in again (1–365).
	 * ---
	 * default: 30
	 * ---
	 *
	 * @subcommand offline-code
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function offline_code( array $args, array $assoc_args ): void {
		$service = Plugin::get_instance()->container()->make( OfflineCodeService::class );
		$code    = $service->issue( (int) $args[0], (int) ( $assoc_args['days'] ?? 30 ), get_current_user_id() );
		if ( is_wp_error( $code ) ) {
			\WP_CLI::error( $code->get_error_message() );
		}
		\WP_CLI::log( sprintf( 'Valid until the office must check in: %s UTC.', gmdate( 'Y-m-d H:i:s', $code['check_in_by'] ) ) );
		\WP_CLI::line( $code['token'] );
	}

	/**
	 * Write the Super Ledger contract fixtures (signed with a fixed TEST key, never the site key).
	 *
	 * ## OPTIONS
	 *
	 * --out=<dir>
	 * : Output directory, e.g. Super Ledger's app/packages/core/test/fixtures/licensing.
	 *
	 * @subcommand contract-fixtures
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function contract_fixtures( array $args, array $assoc_args ): void {
		$files = Plugin::get_instance()->container()->make( ContractFixtures::class )->write( (string) $assoc_args['out'] );
		\WP_CLI::success( sprintf( 'Wrote %d files to %s.', count( $files ), $assoc_args['out'] ) );
	}
}
