<?php
/**
 * Plan repository — all $wpdb access for wplm_plans.
 *
 * @package WPLM\Repositories
 */

namespace WPLM\Repositories;

use WPLM\Models\Plan;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for the wplm_plans table. Packages are loaded lazily via
 * the injected {@see PackageRepository}.
 */
class PlanRepository {

	/** @var PackageRepository */
	private PackageRepository $package_repo;

	/**
	 * @param PackageRepository $package_repo Package data-access layer.
	 */
	public function __construct( PackageRepository $package_repo ) {
		$this->package_repo = $package_repo;
	}

	/** @return string Fully-qualified table name. */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wplm_plans';
	}

	/**
	 * Find a plan by id, optionally with its packages loaded.
	 *
	 * @param int  $id            Row id.
	 * @param bool $with_packages When true, populate $plan->packages.
	 * @return Plan|null
	 */
	public function find_by_id( int $id, bool $with_packages = true ): ?Plan {
		global $wpdb;
		$table = $this->table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$plan = Plan::from_row( $row );
		if ( $with_packages ) {
			$plan->packages = $this->package_repo->get_by_plan( $plan->id );
		}
		return $plan;
	}

	/**
	 * Return all plans ordered by name.
	 *
	 * @param bool $active_only When true, only active plans are returned.
	 * @return Plan[]
	 */
	public function get_all( bool $active_only = false ): array {
		global $wpdb;
		$table = $this->table();

		if ( $active_only ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM `{$table}` WHERE status = 1 ORDER BY name ASC", ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY name ASC", ARRAY_A );
		}

		return is_array( $rows ) ? array_map( array( Plan::class, 'from_row' ), $rows ) : array();
	}

	/**
	 * Insert a plan row.
	 *
	 * @param array $data Column => value pairs.
	 * @return int Inserted row id.
	 * @throws \RuntimeException On DB error.
	 */
	public function create( array $data ): int {
		global $wpdb;
		$now                = current_time( 'mysql' );
		$data['created_at'] = $data['created_at'] ?? $now;
		$data['updated_at'] = $data['updated_at'] ?? $now;

		$result = $wpdb->insert( $this->table(), $data );
		if ( false === $result ) {
			throw new \RuntimeException( 'WPLM PlanRepository: INSERT failed — ' . $wpdb->last_error );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a plan row.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Column => value pairs.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = $data['updated_at'] ?? current_time( 'mysql' );
		$result             = $wpdb->update( $this->table(), $data, array( 'id' => $id ), null, array( '%d' ) );
		return false !== $result;
	}

	/**
	 * Delete a plan and all of its packages.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		$this->package_repo->delete_by_plan( $id );
		return false !== $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}
}
