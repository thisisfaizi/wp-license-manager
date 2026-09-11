<?php
/**
 * Package repository — all $wpdb access for wplm_packages.
 *
 * @package WPLM\Repositories
 */

namespace WPLM\Repositories;

use WPLM\Models\Package;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for the wplm_packages table.
 */
class PackageRepository {

	/** @return string Fully-qualified table name. */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wplm_packages';
	}

	/**
	 * Find a package by primary key.
	 *
	 * @param int $id Row id.
	 * @return Package|null
	 */
	public function find_by_id( int $id ): ?Package {
		global $wpdb;
		$table = $this->table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);
		return $row ? Package::from_row( $row ) : null;
	}

	/**
	 * Return all packages for a plan, ordered by sort order.
	 *
	 * @param int  $plan_id     Plan id.
	 * @param bool $active_only When true, only active packages are returned.
	 * @return Package[]
	 */
	public function get_by_plan( int $plan_id, bool $active_only = false ): array {
		global $wpdb;
		$table = $this->table();

		if ( $active_only ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE plan_id = %d AND status = 1 ORDER BY sort_order ASC, id ASC",
					$plan_id
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE plan_id = %d ORDER BY sort_order ASC, id ASC",
					$plan_id
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? array_map( array( Package::class, 'from_row' ), $rows ) : array();
	}

	/**
	 * Insert a package row.
	 *
	 * @param array $data Column => value pairs.
	 * @return int Inserted row id.
	 * @throws \RuntimeException On DB error.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$data['created_at'] = $data['created_at'] ?? current_time( 'mysql' );
		if ( isset( $data['benefits'] ) && is_array( $data['benefits'] ) ) {
			$data['benefits'] = wp_json_encode( array_values( $data['benefits'] ) );
		}
		$data = $this->encode_entitlements( $data );

		$result = $wpdb->insert( $this->table(), $data );
		if ( false === $result ) {
			throw new \RuntimeException( 'WPLM PackageRepository: INSERT failed — ' . $wpdb->last_error );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a package row.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Column => value pairs.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;
		if ( isset( $data['benefits'] ) && is_array( $data['benefits'] ) ) {
			$data['benefits'] = wp_json_encode( array_values( $data['benefits'] ) );
		}
		$data   = $this->encode_entitlements( $data );
		$result = $wpdb->update( $this->table(), $data, array( 'id' => $id ), null, array( '%d' ) );
		return false !== $result;
	}

	/**
	 * Store an entitlement template array as JSON (NULL when empty).
	 *
	 * @param array $data Column => value pairs.
	 * @return array
	 */
	private function encode_entitlements( array $data ): array {
		if ( array_key_exists( 'entitlements', $data ) && is_array( $data['entitlements'] ) ) {
			$data['entitlements'] = empty( $data['entitlements'] ) ? null : wp_json_encode( array_values( $data['entitlements'] ) );
		}
		return $data;
	}

	/**
	 * Delete a package by id.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Delete every package belonging to a plan.
	 *
	 * @param int $plan_id Plan id.
	 * @return int Rows deleted.
	 */
	public function delete_by_plan( int $plan_id ): int {
		global $wpdb;
		$result = $wpdb->delete( $this->table(), array( 'plan_id' => $plan_id ), array( '%d' ) );
		return false === $result ? 0 : (int) $result;
	}
}
