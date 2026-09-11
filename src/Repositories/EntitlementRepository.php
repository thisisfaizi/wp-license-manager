<?php
/**
 * Entitlement repository — all $wpdb access for wplm_entitlements.
 *
 * @package WPLM\Repositories
 */

namespace WPLM\Repositories;

use WPLM\Models\Entitlement;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for entitlement lines. Validation lives in EntitlementService; this class only
 * reads and writes rows.
 */
class EntitlementRepository {

	/** Columns selected on every read. */
	private const COLUMNS = 'id, license_id, kind, code, qty, paid_through, source, subscription_id, created_at, updated_at';

	/** @return string Fully-qualified table name. */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wplm_entitlements';
	}

	/**
	 * Find a line by id.
	 *
	 * @param int $id Row id.
	 * @return Entitlement|null
	 */
	public function find_by_id( int $id ): ?Entitlement {
		global $wpdb;
		$table = $this->table();
		$cols  = self::COLUMNS;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and column list are constants.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT {$cols} FROM `{$table}` WHERE id = %d LIMIT 1", $id ), ARRAY_A );
		return $row ? Entitlement::from_row( $row ) : null;
	}

	/**
	 * Every line of a licence, modules first, then by code and id.
	 *
	 * @param int $license_id Licence id.
	 * @return Entitlement[]
	 */
	public function get_by_license( int $license_id ): array {
		global $wpdb;
		$table = $this->table();
		$cols  = self::COLUMNS;
		$rows  = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and column list are constants.
			$wpdb->prepare( "SELECT {$cols} FROM `{$table}` WHERE license_id = %d ORDER BY kind DESC, code ASC, id ASC", $license_id ),
			ARRAY_A
		);
		return is_array( $rows ) ? array_map( array( Entitlement::class, 'from_row' ), $rows ) : array();
	}

	/**
	 * Every line created by a subscription.
	 *
	 * @param int $subscription_id Subscription id.
	 * @return Entitlement[]
	 */
	public function get_by_subscription( int $subscription_id ): array {
		global $wpdb;
		$table = $this->table();
		$cols  = self::COLUMNS;
		$rows  = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and column list are constants.
			$wpdb->prepare( "SELECT {$cols} FROM `{$table}` WHERE subscription_id = %d ORDER BY id ASC", $subscription_id ),
			ARRAY_A
		);
		return is_array( $rows ) ? array_map( array( Entitlement::class, 'from_row' ), $rows ) : array();
	}

	/**
	 * Licences of one profile that have a module line paid through a date in a range, excluding
	 * licences in the given statuses. Used to find modules about to go read-only.
	 *
	 * @param string $profile       Licence profile code.
	 * @param string $from          First paid-through date (Y-m-d, inclusive).
	 * @param string $to            Last paid-through date (Y-m-d, inclusive).
	 * @param int[]  $skip_statuses Licence statuses to leave out.
	 * @return int[] Licence ids, ascending.
	 */
	public function license_ids_with_modules_through( string $profile, string $from, string $to, array $skip_statuses ): array {
		global $wpdb;
		$table    = $this->table();
		$licenses = $wpdb->prefix . 'wplm_licenses';
		$skip     = implode( ',', array_map( 'intval', $skip_statuses ?: array( -1 ) ) );
		$ids      = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are constants and $skip is a list of integers.
				"SELECT DISTINCT e.license_id FROM `{$table}` e INNER JOIN `{$licenses}` l ON l.id = e.license_id WHERE e.kind = 'module' AND e.paid_through BETWEEN %s AND %s AND l.profile = %s AND l.status NOT IN ({$skip}) ORDER BY e.license_id ASC",
				$from,
				$to,
				$profile
			)
		);
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Insert a line.
	 *
	 * @param array $data Column => value pairs.
	 * @return int Inserted id.
	 * @throws \RuntimeException On DB error.
	 */
	public function create( array $data ): int {
		global $wpdb;
		$now                = gmdate( 'Y-m-d H:i:s' );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		if ( false === $wpdb->insert( $this->table(), $data ) ) {
			throw new \RuntimeException( 'WPLM EntitlementRepository: INSERT failed — ' . $wpdb->last_error );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a line.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Column => value pairs.
	 * @return bool
	 * @throws \RuntimeException On DB error.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		if ( false === $wpdb->update( $this->table(), $data, array( 'id' => $id ) ) ) {
			throw new \RuntimeException( 'WPLM EntitlementRepository: UPDATE failed — ' . $wpdb->last_error );
		}
		return true;
	}

	/**
	 * Delete a line.
	 *
	 * @param int $id Row id.
	 * @return bool True when a row was removed.
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		return (int) $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) ) > 0;
	}
}
