<?php
/**
 * The fleet extension notice, with no WordPress dependency.
 *
 * @package WPLM\Licensing
 */

namespace WPLM\Licensing;

use WPLM\Crypto\CompactToken;

// Loadable without WordPress (bin/fleet-notice.php runs from an offline key backup).
defined( 'ABSPATH' ) || defined( 'WPLM_STANDALONE' ) || exit;

/**
 * When the licence site is down, check-ins fail everywhere at once, and after the check-in window
 * every paying machine of an entitlement licence would stop. The owner signs this notice from the
 * **offline key backup** and publishes it on a second host; a client that cannot check in fetches it
 * and moves its check-in deadline to `min(until, token.iat + 30 days)`. It never touches paid-through
 * dates. `pid` is the profile code, so one notice serves one product.
 *
 *     {"v": 1, "pid": "<profile code>", "kind": "extend-check-in", "until": <unix>, "iat": <unix>}
 */
final class FleetNotice {

	/** The longest extension a notice may grant, in days. */
	public const MAX_DAYS = 30;

	/**
	 * Build a notice payload.
	 *
	 * @param int    $until Unix time the deadline may stretch to.
	 * @param int    $iat   Unix time of issue.
	 * @param string $pid   Profile code of the product the notice applies to. There is no default: a
	 *                      notice with the wrong pid is rejected by every client, during an outage.
	 * @return array{v: int, pid: string, kind: string, until: int, iat: int}
	 * @throws \InvalidArgumentException When the pid is not a profile code, or `until` is not after `iat`, or more than 30 days after it.
	 */
	public static function build( int $until, int $iat, string $pid ): array {
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,31}$/', $pid ) ) {
			throw new \InvalidArgumentException( sprintf( 'The product id "%s" is not a licence profile code (lower-case letters, digits and hyphens).', $pid ) );
		}
		if ( $until <= $iat ) {
			throw new \InvalidArgumentException( 'The notice must extend to a time after now.' );
		}
		if ( $until > $iat + self::MAX_DAYS * 86400 ) {
			throw new \InvalidArgumentException(
				sprintf( 'A fleet notice may extend check-in by at most %d days from now (requested %.1f days).', self::MAX_DAYS, ( $until - $iat ) / 86400 )
			);
		}
		return array(
			'v'     => 1,
			'pid'   => $pid,
			'kind'  => 'extend-check-in',
			'until' => $until,
			'iat'   => $iat,
		);
	}

	/**
	 * Sign a notice with a raw Ed25519 secret key.
	 *
	 * @param array  $notice     Built notice.
	 * @param string $secret_key Raw 64-byte secret key.
	 * @return string
	 */
	public static function sign( array $notice, string $secret_key ): string {
		return CompactToken::sign( $notice, $secret_key );
	}

	/**
	 * Parse the owner's `--until`: a date (`YYYY-MM-DD`, meaning the **end** of that day in the given
	 * time zone) or a full ISO 8601 date-time with an offset.
	 *
	 * @param string $value    The input.
	 * @param string $timezone Time zone for a bare date (e.g. Asia/Karachi).
	 * @return int Unix time.
	 * @throws \InvalidArgumentException On anything else.
	 */
	public static function parse_until( string $value, string $timezone ): int {
		$value = trim( $value );
		if ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return ( new \DateTimeImmutable( $value . ' 23:59:59', new \DateTimeZone( $timezone ) ) )->getTimestamp();
		}
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})$/', $value ) ) {
			return ( new \DateTimeImmutable( $value ) )->getTimestamp();
		}
		throw new \InvalidArgumentException( sprintf( 'Could not read --until "%s": use YYYY-MM-DD or an ISO 8601 time with an offset, e.g. 2026-09-30T18:00:00+05:00.', $value ) );
	}
}
