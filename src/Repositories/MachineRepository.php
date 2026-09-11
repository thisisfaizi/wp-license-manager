<?php
/**
 * Machine repository — all $wpdb access for wplm_machines.
 *
 * @package WPLM\Repositories
 */

namespace WPLM\Repositories;

use WPLM\Models\Machine;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for the wplm_machines table.
 * ip_address is stored as VARBINARY(16) using MySQL's INET6_ATON() function and
 * read back via INET6_NTOA() so that the PHP layer always works with printable strings.
 */
class MachineRepository {

	// -------------------------------------------------------------------------
	// Finders
	// -------------------------------------------------------------------------

	/**
	 * Find a machine by its primary key.
	 *
	 * @param int $id Row id.
	 * @return Machine|null
	 */
	public function find_by_id( int $id ): ?Machine {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_machines';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *, INET6_NTOA(ip_address) AS ip_address FROM `{$table}` WHERE id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return Machine::from_row( $row );
	}

	/**
	 * Find a machine by its (license_id, fingerprint) unique key.
	 *
	 * @param int    $license_id  License row id.
	 * @param string $fingerprint HMAC-SHA256 fingerprint.
	 * @return Machine|null
	 */
	public function find_by_license_and_fingerprint( int $license_id, string $fingerprint ): ?Machine {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_machines';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *, INET6_NTOA(ip_address) AS ip_address FROM `{$table}`
				 WHERE license_id = %d AND fingerprint = %s LIMIT 1",
				$license_id,
				$fingerprint
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return Machine::from_row( $row );
	}

	/**
	 * Get all machines for a license, optionally filtered by status.
	 *
	 * @param int $license_id License row id.
	 * @param int $status     Machine status (1 active, 2 deactivated, 3 revoked). Pass -1 to get all.
	 * @return Machine[]
	 */
	public function get_by_license( int $license_id, int $status = 1 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_machines';

		if ( -1 === $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT *, INET6_NTOA(ip_address) AS ip_address FROM `{$table}`
					 WHERE license_id = %d ORDER BY created_at ASC",
					$license_id
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT *, INET6_NTOA(ip_address) AS ip_address FROM `{$table}`
					 WHERE license_id = %d AND status = %d ORDER BY created_at ASC",
					$license_id,
					$status
				),
				ARRAY_A
			);
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( Machine::class, 'from_row' ), $rows );
	}

	// -------------------------------------------------------------------------
	// Mutations
	// -------------------------------------------------------------------------

	/**
	 * Insert a new machine row.
	 * Handles ip_address via INET6_ATON() at the SQL level.
	 *
	 * @param array $data Column => value pairs. 'ip_address' should be a printable IP string.
	 * @return int Inserted row id.
	 * @throws \RuntimeException On DB error.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$now                  = current_time( 'mysql' );
		$data['activated_at'] = $data['activated_at'] ?? $now;
		$data['created_at']   = $now;

		$ip = $data['ip_address'] ?? null;
		unset( $data['ip_address'] );

		$table   = $wpdb->prefix . 'wplm_machines';
		$columns = array_keys( $data );
		$formats = array_fill( 0, count( $columns ), '%s' );

		if ( null !== $ip ) {
			$columns[] = 'ip_address';
			// Build the column and placeholder lists.
			$col_list = implode( ', ', array_map( fn( $c ) => "`{$c}`", $columns ) );
			$fmt_list = implode( ', ', $formats ) . ', INET6_ATON(%s)';
			$values   = array_values( $data );
			$values[] = $ip;
		} else {
			$col_list = implode( ', ', array_map( fn( $c ) => "`{$c}`", $columns ) );
			$fmt_list = implode( ', ', $formats );
			$values   = array_values( $data );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table}` ({$col_list}) VALUES ({$fmt_list})",
				...$values
			)
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'WPLM MachineRepository: INSERT failed — ' . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing machine row.
	 * Handles ip_address via INET6_ATON() when present.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Column => value pairs.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$ip = null;
		if ( array_key_exists( 'ip_address', $data ) ) {
			$ip = $data['ip_address'];
			unset( $data['ip_address'] );
		}

		$table  = $wpdb->prefix . 'wplm_machines';
		$sets   = array();
		$values = array();

		foreach ( $data as $col => $val ) {
			$sets[]   = "`{$col}` = %s";
			$values[] = $val;
		}

		if ( null !== $ip ) {
			$sets[]   = '`ip_address` = INET6_ATON(%s)';
			$values[] = $ip;
		}

		if ( empty( $sets ) ) {
			return false;
		}

		$set_sql  = implode( ', ', $sets );
		$values[] = $id;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET {$set_sql} WHERE id = %d",
				...$values
			)
		);

		return $result !== false && $result > 0;
	}

	/**
	 * Deactivate a machine (status → 2) and record the deactivation timestamp.
	 *
	 * @param int $id Machine row id.
	 * @return bool
	 */
	public function deactivate( int $id ): bool {
		global $wpdb;

		$table  = $wpdb->prefix . 'wplm_machines';
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = 2, deactivated_at = %s WHERE id = %d",
				current_time( 'mysql' ),
				$id
			)
		);

		return $result !== false && $result > 0;
	}

	/**
	 * Reactivate a previously deactivated machine (status → 1).
	 *
	 * Only transitions machines in status 2 (deactivated); revoked machines (3)
	 * cannot be reactivated via this path.
	 *
	 * @param int $id Machine row id.
	 * @return bool True when a row was updated.
	 */
	public function reactivate( int $id ): bool {
		global $wpdb;

		$table  = $wpdb->prefix . 'wplm_machines';
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = 1, deactivated_at = NULL WHERE id = %d AND status = 2",
				$id
			)
		);

		return $result !== false && $result > 0;
	}

	/**
	 * Revoke a machine (status → 3).
	 *
	 * @param int $id Machine row id.
	 * @return bool
	 */
	public function revoke( int $id ): bool {
		global $wpdb;

		$table  = $wpdb->prefix . 'wplm_machines';
		$result = $wpdb->query(
			$wpdb->prepare( "UPDATE `{$table}` SET status = 3 WHERE id = %d", $id )
		);

		return $result !== false && $result > 0;
	}

	/**
	 * Touch last_heartbeat_at and optionally update the floating lease expiry.
	 *
	 * @param int         $id              Machine row id.
	 * @param string|null $lease_expires_at New lease expiry datetime string or null.
	 * @return bool
	 */
	public function update_heartbeat( int $id, ?string $lease_expires_at = null ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_machines';

		if ( null !== $lease_expires_at ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}` SET last_heartbeat_at = UTC_TIMESTAMP(), lease_expires_at = %s WHERE id = %d",
					$lease_expires_at,
					$id
				)
			);
		} else {
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}` SET last_heartbeat_at = UTC_TIMESTAMP() WHERE id = %d",
					$id
				)
			);
		}

		return $result !== false && $result > 0;
	}

	/**
	 * Return a paginated, filtered list of machines for the admin screen.
	 *
	 * @param array $args per_page, page, orderby, order, status, license_id.
	 * @return array{items: Machine[], total: int}
	 */
	public function get_list( array $args ): array {
		global $wpdb;

		$table    = $wpdb->prefix . 'wplm_machines';
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$allowed_orderby = array( 'id', 'status', 'created_at', 'last_heartbeat_at', 'license_id' );
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$order           = 'ASC' === strtoupper( $args['order'] ?? '' ) ? 'ASC' : 'DESC';

		$conditions = array();
		$cond_vals  = array();

		if ( isset( $args['status'] ) && '' !== $args['status'] ) {
			$conditions[] = 'status = %d';
			$cond_vals[]  = (int) $args['status'];
		}
		if ( ! empty( $args['license_id'] ) ) {
			$conditions[] = 'license_id = %d';
			$cond_vals[]  = (int) $args['license_id'];
		}

		$where = $conditions ? implode( ' AND ', $conditions ) : '1=1';

		if ( $cond_vals ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}", ...$cond_vals ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT *, INET6_NTOA(ip_address) AS ip_address FROM `{$table}` WHERE {$where} ORDER BY `{$orderby}` {$order} LIMIT %d OFFSET %d",
					...array_merge( $cond_vals, array( $per_page, $offset ) )
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT *, INET6_NTOA(ip_address) AS ip_address FROM `{$table}` ORDER BY `{$orderby}` {$order} LIMIT %d OFFSET %d",
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		return array(
			'items' => array_map( array( Machine::class, 'from_row' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Get active machines whose heartbeat has gone stale (potential zombies).
	 *
	 * @param int $cutoff_timestamp Unix timestamp; machines last seen before this are stale.
	 * @return Machine[]
	 */
	public function get_stale_machines( int $cutoff_timestamp ): array {
		global $wpdb;

		$table      = $wpdb->prefix . 'wplm_machines';
		$cutoff_str = gmdate( 'Y-m-d H:i:s', $cutoff_timestamp );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *, INET6_NTOA(ip_address) AS ip_address FROM `{$table}`
				 WHERE status = 1
				   AND last_heartbeat_at IS NOT NULL
				   AND last_heartbeat_at < %s
				   AND lease_expires_at IS NOT NULL",
				$cutoff_str
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( Machine::class, 'from_row' ), $rows );
	}
}
