<?php
/**
 * Generator repository — all $wpdb access for wplm_generators.
 *
 * @package WPLM\Repositories
 */

namespace WPLM\Repositories;

use WPLM\Models\Generator;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for the wplm_generators table.
 */
class GeneratorRepository {

	// -------------------------------------------------------------------------
	// Finders
	// -------------------------------------------------------------------------

	/**
	 * Find a generator by its primary key.
	 *
	 * @param int $id Row id.
	 * @return Generator|null
	 */
	public function find_by_id( int $id ): ?Generator {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_generators';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return Generator::from_row( $row );
	}

	/**
	 * Return all generator rows ordered by name.
	 *
	 * @return Generator[]
	 */
	public function get_all(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_generators';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY name ASC", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( Generator::class, 'from_row' ), $rows );
	}

	// -------------------------------------------------------------------------
	// Mutations
	// -------------------------------------------------------------------------

	/**
	 * Insert a new generator row.
	 *
	 * @param array $data Column => value pairs.
	 * @return int Inserted row id.
	 * @throws \RuntimeException On DB error.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$data['created_at'] = $data['created_at'] ?? current_time( 'mysql' );

		$result = $wpdb->insert(
			$wpdb->prefix . 'wplm_generators',
			$data
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'WPLM GeneratorRepository: INSERT failed — ' . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing generator row.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Column => value pairs.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$result = $wpdb->update(
			$wpdb->prefix . 'wplm_generators',
			$data,
			array( 'id' => $id ),
			null,
			array( '%d' )
		);

		return $result !== false && $result > 0;
	}

	/**
	 * Delete a generator by id.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete(
			$wpdb->prefix . 'wplm_generators',
			array( 'id' => $id ),
			array( '%d' )
		);

		return $result !== false && $result > 0;
	}
}
