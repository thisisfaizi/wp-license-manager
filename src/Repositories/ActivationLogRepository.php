<?php
/**
 * Activation log repository — all $wpdb access for wplm_activation_log.
 *
 * @package WPLM\Repositories
 */

namespace WPLM\Repositories;

use WPLM\Models\ActivationLog;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for the wplm_activation_log table.
 *
 * The ip_address column is VARBINARY(16). We write it using MySQL's built-in
 * INET6_ATON() function (supports both IPv4 and IPv6 without PHP inet_pton()
 * binary-string hazards), and read it back with INET6_NTOA() so PHP always
 * sees a printable IP string.
 *
 * The meta column is stored as JSON via wp_json_encode().
 */
class ActivationLogRepository {

	// -------------------------------------------------------------------------
	// Mutations
	// -------------------------------------------------------------------------

	/**
	 * Insert an activation log entry.
	 *
	 * Expected $data keys:
	 *   license_id  int       (required)
	 *   machine_id  int|null
	 *   event       string    validate|activate|deactivate|heartbeat|revoke|deny
	 *   result      string    success|fail|limit_exceeded|expired|revoked
	 *   ip_address  string    Printable IPv4 or IPv6 string (stored via INET6_ATON)
	 *   country     string    ISO 3166-1 alpha-2 (optional)
	 *   meta        array     Arbitrary key/value detail (encoded to JSON)
	 *   created_at  string    Datetime string; defaults to NOW() if omitted
	 *
	 * @param array $data Column => value pairs.
	 * @return int Inserted row id.
	 * @throws \RuntimeException On DB error.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_activation_log';

		// Encode meta to JSON before storage.
		$meta = isset( $data['meta'] ) && is_array( $data['meta'] )
			? wp_json_encode( $data['meta'] )
			: ( $data['meta'] ?? '{}' );

		$ip = $data['ip_address'] ?? null;

		// Resolve country from IP when not explicitly provided. A pluggable
		// resolver may be supplied via the 'wplm_resolve_geo' filter; it never
		// blocks the request path and falls back to NULL on any failure.
		$country = $data['country'] ?? null;
		if ( empty( $country ) && ! empty( $ip ) ) {
			/**
			 * Filter the resolved ISO-3166 alpha-2 country for a request IP.
			 *
			 * @param string|null $country Resolved country code, or null.
			 * @param string      $ip      The request IP address.
			 */
			$resolved = apply_filters( 'wplm_resolve_geo', null, (string) $ip );
			if ( is_string( $resolved ) && 2 === strlen( $resolved ) ) {
				$country = strtoupper( $resolved );
			}
		}

		// Build row without ip_address and meta (handled separately).
		$row = array(
			'license_id' => isset( $data['license_id'] ) ? (int) $data['license_id'] : 0,
			'machine_id' => isset( $data['machine_id'] ) ? (int) $data['machine_id'] : null,
			'event'      => $data['event'] ?? '',
			'result'     => $data['result'] ?? '',
			'country'    => $country,
			'meta'       => $meta,
			'created_at' => $data['created_at'] ?? current_time( 'mysql' ),
		);

		// Build dynamic column + placeholder lists.
		$columns  = array_keys( $row );
		$values   = array_values( $row );
		$col_list = implode( ', ', array_map( fn( $c ) => "`{$c}`", $columns ) );
		$fmt_list = implode( ', ', array_fill( 0, count( $columns ), '%s' ) );

		if ( null !== $ip ) {
			$col_list .= ', `ip_address`';
			$fmt_list .= ', INET6_ATON(%s)';
			$values[]  = (string) $ip;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table}` ({$col_list}) VALUES ({$fmt_list})",
				...$values
			)
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'WPLM ActivationLogRepository: INSERT failed — ' . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	// -------------------------------------------------------------------------
	// Finders
	// -------------------------------------------------------------------------

	/**
	 * Get activation log entries for a specific license, newest first.
	 *
	 * @param int $license_id License row id.
	 * @param int $limit      Maximum rows to return (default 50).
	 * @return ActivationLog[]
	 */
	public function get_by_license( int $license_id, int $limit = 50 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_activation_log';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *, INET6_NTOA(ip_address) AS ip_address FROM `{$table}`
				 WHERE license_id = %d
				 ORDER BY created_at DESC
				 LIMIT %d",
				$license_id,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( ActivationLog::class, 'from_row' ), $rows );
	}

	/**
	 * Count log events for a license/event type since a given timestamp.
	 *
	 * @param int    $license_id      License row id.
	 * @param string $event           Event name (validate|activate|…).
	 * @param int    $since_timestamp Unix timestamp; count rows on or after this time.
	 * @return int
	 */
	public function count_events_since( int $license_id, string $event, int $since_timestamp ): int {
		global $wpdb;

		$table     = $wpdb->prefix . 'wplm_activation_log';
		$since_str = gmdate( 'Y-m-d H:i:s', $since_timestamp );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}`
				 WHERE license_id = %d
				   AND event = %s
				   AND created_at >= %s",
				$license_id,
				$event,
				$since_str
			)
		);
	}

	// -------------------------------------------------------------------------
	// Maintenance
	// -------------------------------------------------------------------------

	/**
	 * Delete log rows created before a given datetime string (housekeeping).
	 *
	 * @param string $cutoff_datetime MySQL datetime string, e.g. '2024-01-01 00:00:00'.
	 * @return int Number of rows deleted.
	 */
	public function purge_before( string $cutoff_datetime ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_activation_log';

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE created_at < %s",
				$cutoff_datetime
			)
		);

		return ( false === $result ) ? 0 : (int) $result;
	}
}
