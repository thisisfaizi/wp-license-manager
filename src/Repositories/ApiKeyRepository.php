<?php
/**
 * API Key repository — all $wpdb access for wplm_api_keys.
 *
 * @package WPLM\Repositories
 */

namespace WPLM\Repositories;

use WPLM\Models\ApiKey;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for the wplm_api_keys table.
 * consumer_key and consumer_secret are stored as SHA-256 hashes; this
 * repository works only with the hashed values — hashing itself is the
 * responsibility of the caller.
 */
class ApiKeyRepository {

	// -------------------------------------------------------------------------
	// Finders
	// -------------------------------------------------------------------------

	/**
	 * Find an API key row by its hashed consumer key.
	 *
	 * @param string $hashed_key 64-character SHA-256 hex hash of the consumer key.
	 * @return ApiKey|null
	 */
	public function find_by_consumer_key( string $hashed_key ): ?ApiKey {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_api_keys';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE consumer_key = %s LIMIT 1",
				$hashed_key
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return ApiKey::from_row( $row );
	}

	/**
	 * Return all API key rows ordered by creation date.
	 *
	 * @return ApiKey[]
	 */
	public function get_all(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_api_keys';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			"SELECT * FROM `{$table}` ORDER BY created_at DESC",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( ApiKey::class, 'from_row' ), $rows );
	}

	// -------------------------------------------------------------------------
	// Mutations
	// -------------------------------------------------------------------------

	/**
	 * Insert a new API key row.
	 *
	 * @param array $data Column => value pairs (consumer_key/secret already hashed).
	 * @return int Inserted row id.
	 * @throws \RuntimeException On DB error.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$data['created_at'] = $data['created_at'] ?? current_time( 'mysql' );

		$result = $wpdb->insert(
			$wpdb->prefix . 'wplm_api_keys',
			$data
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'WPLM ApiKeyRepository: INSERT failed — ' . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete an API key by primary key.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete(
			$wpdb->prefix . 'wplm_api_keys',
			array( 'id' => $id ),
			array( '%d' )
		);

		return $result !== false && $result > 0;
	}

	/**
	 * Update the last_access_at timestamp to the current time.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public function touch_last_access( int $id ): bool {
		global $wpdb;

		$table  = $wpdb->prefix . 'wplm_api_keys';
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET last_access_at = NOW() WHERE id = %d",
				$id
			)
		);

		return $result !== false && $result > 0;
	}
}
