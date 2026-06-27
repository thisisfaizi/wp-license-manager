<?php
/**
 * Blacklist repository — all $wpdb access for wplm_blacklist.
 *
 * @package WPLM\Repositories
 */

namespace WPLM\Repositories;

use WPLM\Models\Blacklist;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for the wplm_blacklist table.
 */
class BlacklistRepository {

	// -------------------------------------------------------------------------
	// Finders
	// -------------------------------------------------------------------------

	/**
	 * Find a blacklist entry by type and value.
	 *
	 * @param string $type  fingerprint | ip | email
	 * @param string $value The blacklisted value.
	 * @return Blacklist|null
	 */
	public function find( string $type, string $value ): ?Blacklist {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_blacklist';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE type = %s AND value = %s LIMIT 1",
				$type,
				$value
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return Blacklist::from_row( $row );
	}

	/**
	 * Check whether a given type/value pair is blacklisted.
	 *
	 * @param string $type  fingerprint | ip | email
	 * @param string $value The value to test.
	 * @return bool
	 */
	public function is_blacklisted( string $type, string $value ): bool {
		return null !== $this->find( $type, $value );
	}

	/**
	 * Return all blacklist entries, optionally filtered by type.
	 *
	 * @param string $type Leave empty to return all types.
	 * @return Blacklist[]
	 */
	public function get_all( string $type = '' ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_blacklist';

		if ( '' !== $type ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE type = %s ORDER BY created_at DESC",
					$type
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results(
				"SELECT * FROM `{$table}` ORDER BY created_at DESC",
				ARRAY_A
			);
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( Blacklist::class, 'from_row' ), $rows );
	}

	// -------------------------------------------------------------------------
	// Mutations
	// -------------------------------------------------------------------------

	/**
	 * Add a new blacklist entry.
	 *
	 * @param string $type   fingerprint | ip | email
	 * @param string $value  The value to blacklist.
	 * @param string $reason Human-readable reason (optional).
	 * @return int Inserted row id.
	 * @throws \RuntimeException On DB error.
	 */
	public function add( string $type, string $value, string $reason = '' ): int {
		global $wpdb;

		$result = $wpdb->insert(
			$wpdb->prefix . 'wplm_blacklist',
			array(
				'type'       => $type,
				'value'      => $value,
				'reason'     => $reason,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'WPLM BlacklistRepository: INSERT failed — ' . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Remove a blacklist entry by primary key.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public function remove( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete(
			$wpdb->prefix . 'wplm_blacklist',
			array( 'id' => $id ),
			array( '%d' )
		);

		return $result !== false && $result > 0;
	}
}
