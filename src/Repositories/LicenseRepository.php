<?php
/**
 * License repository — all $wpdb access for wplm_licenses.
 *
 * @package WPLM\Repositories
 */

namespace WPLM\Repositories;

use WPLM\Crypto\KeyVault;
use WPLM\Crypto\Fingerprint;
use WPLM\Models\License;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for the wplm_licenses table.
 * All queries use $wpdb->prepare() — zero string interpolation of user data.
 */
class LicenseRepository {

	/** @var KeyVault */
	private KeyVault $vault;

	/**
	 * @param KeyVault $vault Injected encryption vault.
	 */
	public function __construct( KeyVault $vault ) {
		$this->vault = $vault;
	}

	// -------------------------------------------------------------------------
	// Finders
	// -------------------------------------------------------------------------

	/**
	 * Find a license by its primary key.
	 *
	 * @param int $id Row id.
	 * @return License|null
	 */
	public function find_by_id( int $id ): ?License {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_licenses';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * Find a license by its SHA-256 key hash.
	 *
	 * @param string $hash 64-character hex hash.
	 * @return License|null
	 */
	public function find_by_hash( string $hash ): ?License {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_licenses';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE hash = %s LIMIT 1", $hash ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * Find a license by its plaintext key (computes hash then delegates).
	 *
	 * @param string $plaintext_key The raw license key string.
	 * @return License|null
	 */
	public function find_by_key( string $plaintext_key ): ?License {
		$hash = Fingerprint::sha256( $plaintext_key );
		return $this->find_by_hash( $hash );
	}

	// -------------------------------------------------------------------------
	// Paginated list
	// -------------------------------------------------------------------------

	/**
	 * Return a paginated, filtered list of licenses.
	 *
	 * Supported $args keys:
	 *   status      int
	 *   product_id  int
	 *   order_id    int
	 *   user_id     int
	 *   per_page    int  (default 20)
	 *   page        int  (default 1)
	 *   orderby     string (default 'id')
	 *   order       string ASC|DESC (default 'DESC')
	 *
	 * @param array $args Filter/pagination arguments.
	 * @return array{ items: License[], total: int }
	 */
	public function get_list( array $args = array() ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_licenses';

		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		// Whitelist orderby to prevent injection.
		$allowed_orderby = array( 'id', 'status', 'product_id', 'order_id', 'user_id', 'created_at', 'expires_at', 'activation_count' );
		$orderby         = in_array( $args['orderby'] ?? 'id', $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$order           = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

		$where  = array();
		$values = array();

		if ( isset( $args['status'] ) ) {
			$where[]  = 'status = %d';
			$values[] = (int) $args['status'];
		}

		if ( isset( $args['product_id'] ) ) {
			$where[]  = 'product_id = %d';
			$values[] = (int) $args['product_id'];
		}

		if ( isset( $args['order_id'] ) ) {
			$where[]  = 'order_id = %d';
			$values[] = (int) $args['order_id'];
		}

		if ( isset( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$values[] = (int) $args['user_id'];
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		// Total count.
		$count_sql = "SELECT COUNT(*) FROM `{$table}` {$where_sql}";
		if ( $values ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$values ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $count_sql );
		}

		// Rows.
		$data_sql = "SELECT * FROM `{$table}` {$where_sql} ORDER BY `{$orderby}` {$order} LIMIT %d OFFSET %d";
		$all_vals = array_merge( $values, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$all_vals ), ARRAY_A );

		$items = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$items[] = $this->hydrate( $row );
			}
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	// -------------------------------------------------------------------------
	// Mutations
	// -------------------------------------------------------------------------

	/**
	 * Insert a new license row.
	 * The $data['license_key'] must already be encrypted by the caller.
	 *
	 * @param array $data Column => value pairs.
	 * @return int Inserted row id.
	 * @throws \RuntimeException On DB error.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$now                = current_time( 'mysql' );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		$data               = $this->without_profile_expiry( $data, null );

		$result = $wpdb->insert(
			$wpdb->prefix . 'wplm_licenses',
			$data
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'WPLM LicenseRepository: INSERT failed — ' . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing license row.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Column => value pairs to update.
	 * @return bool True if at least one row was affected.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql' );
		$data               = $this->without_profile_expiry( $data, $id );

		$result = $wpdb->update(
			$wpdb->prefix . 'wplm_licenses',
			$data,
			array( 'id' => $id )
		);

		return $result !== false && $result > 0;
	}

	/**
	 * A profile licence never stores an expiry: its entitlement lines carry the dates, and the app
	 * computes grace and read-only from the token (Super Ledger D7). If one were written, classic
	 * validation would mark the licence expired and activation/check-in would refuse a customer who
	 * can only recover by paying — the lock-out D2 forbids.
	 *
	 * Enforced here, the one place every path writes through (checkout, renewals, subscription
	 * cancellation, first activation, admin, REST, CSV import), rather than in each caller.
	 *
	 * @param array    $data Column => value pairs about to be written.
	 * @param int|null $id   Row id for an update; null for an insert.
	 * @return array
	 */
	private function without_profile_expiry( array $data, ?int $id ): array {
		global $wpdb;

		if ( array_key_exists( 'profile', $data ) ) {
			$profile = $data['profile'];
			if ( '' === $profile ) {
				$data['profile'] = null;
				$profile         = null;
			}
			if ( null !== $profile ) {
				$data['expires_at'] = null; // Becoming a profile licence clears any expiry.
			}
			return $data;
		}

		if ( ! array_key_exists( 'expires_at', $data ) || null === $data['expires_at'] || null === $id ) {
			return $data;
		}

		$profile = $wpdb->get_var(
			$wpdb->prepare( "SELECT profile FROM `{$wpdb->prefix}wplm_licenses` WHERE id = %d", $id )
		);
		if ( null !== $profile && '' !== $profile ) {
			$data['expires_at'] = null;
		}
		return $data;
	}

	/**
	 * Delete a license by id.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete(
			$wpdb->prefix . 'wplm_licenses',
			array( 'id' => $id ),
			array( '%d' )
		);

		return $result !== false && $result > 0;
	}

	// -------------------------------------------------------------------------
	// Activation accounting
	// -------------------------------------------------------------------------

	/**
	 * Atomically increment the activation_count by 1.
	 *
	 * @param int $id License id.
	 * @return bool
	 */
	public function increment_activation_count( int $id ): bool {
		global $wpdb;

		$table  = $wpdb->prefix . 'wplm_licenses';
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET activation_count = activation_count + 1, updated_at = %s WHERE id = %d",
				current_time( 'mysql' ),
				$id
			)
		);

		return $result !== false && $result > 0;
	}

	/**
	 * Atomically decrement the activation_count by 1, clamped to zero.
	 *
	 * @param int $id License id.
	 * @return bool
	 */
	public function decrement_activation_count( int $id ): bool {
		global $wpdb;

		$table  = $wpdb->prefix . 'wplm_licenses';
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET activation_count = GREATEST(0, activation_count - 1), updated_at = %s WHERE id = %d",
				current_time( 'mysql' ),
				$id
			)
		);

		return $result !== false && $result > 0;
	}

	/**
	 * Recompute activation_count from the actual number of ACTIVE machines and
	 * store it. This is drift-proof: unlike increment/decrement it cannot leave
	 * the seat count out of sync after an error, retry, or race.
	 *
	 * @param int $id License id.
	 * @return int The reconciled active-device count.
	 */
	public function sync_activation_count( int $id ): int {
		global $wpdb;

		$machines = $wpdb->prefix . 'wplm_machines';
		$licenses = $wpdb->prefix . 'wplm_licenses';

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$machines}` WHERE license_id = %d AND status = 1",
				$id
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$licenses}` SET activation_count = %d, updated_at = %s WHERE id = %d",
				$count,
				current_time( 'mysql' ),
				$id
			)
		);

		return $count;
	}

	// -------------------------------------------------------------------------
	// Reporting
	// -------------------------------------------------------------------------

	/**
	 * Count active licenses expiring within the next N days.
	 *
	 * @param int $days Look-ahead window in days.
	 * @return int
	 */
	public function count_expiring_soon( int $days ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_licenses';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}`
				 WHERE status = 1
				   AND expires_at BETWEEN UTC_TIMESTAMP() AND DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$days
			)
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Decrypt the stored license_key and hydrate a License model.
	 *
	 * @param array $row Raw DB row (ARRAY_A).
	 * @return License
	 */
	private function hydrate( array $row ): License {
		if ( ! empty( $row['license_key'] ) ) {
			$row['license_key'] = $this->vault->decrypt( $row['license_key'] );
		}

		return License::from_row( $row );
	}
}
