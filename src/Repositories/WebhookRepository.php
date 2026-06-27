<?php
/**
 * Webhook repository — all $wpdb access for wplm_webhooks and wplm_webhook_log.
 *
 * @package WPLM\Repositories
 */

namespace WPLM\Repositories;

use WPLM\Models\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for wplm_webhooks and wplm_webhook_log.
 * The `events` column is stored as a JSON array.
 */
class WebhookRepository {

	// -------------------------------------------------------------------------
	// Finders
	// -------------------------------------------------------------------------

	/**
	 * Find a webhook by its primary key.
	 *
	 * @param int $id Row id.
	 * @return Webhook|null
	 */
	public function find_by_id( int $id ): ?Webhook {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_webhooks';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return Webhook::from_row( $row );
	}

	/**
	 * Return all active webhooks (status = 1).
	 *
	 * @return Webhook[]
	 */
	public function get_active(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_webhooks';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE status = %d ORDER BY id ASC",
				1
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( Webhook::class, 'from_row' ), $rows );
	}

	/**
	 * Return all webhook rows.
	 *
	 * @return Webhook[]
	 */
	public function get_all(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_webhooks';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			"SELECT * FROM `{$table}` ORDER BY id ASC",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( Webhook::class, 'from_row' ), $rows );
	}

	// -------------------------------------------------------------------------
	// Mutations
	// -------------------------------------------------------------------------

	/**
	 * Insert a new webhook row.
	 * The `events` key in $data must be a PHP array; it will be JSON-encoded.
	 *
	 * @param array $data Column => value pairs.
	 * @return int Inserted row id.
	 * @throws \RuntimeException On DB error.
	 */
	public function create( array $data ): int {
		global $wpdb;

		if ( isset( $data['events'] ) && is_array( $data['events'] ) ) {
			$data['events'] = wp_json_encode( $data['events'] );
		}

		$data['created_at'] = $data['created_at'] ?? current_time( 'mysql' );

		$result = $wpdb->insert(
			$wpdb->prefix . 'wplm_webhooks',
			$data
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'WPLM WebhookRepository: INSERT failed — ' . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing webhook row.
	 * If $data contains an `events` array it will be JSON-encoded automatically.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Column => value pairs.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		if ( isset( $data['events'] ) && is_array( $data['events'] ) ) {
			$data['events'] = wp_json_encode( $data['events'] );
		}

		$result = $wpdb->update(
			$wpdb->prefix . 'wplm_webhooks',
			$data,
			array( 'id' => $id ),
			null,
			array( '%d' )
		);

		return $result !== false && $result > 0;
	}

	/**
	 * Delete a webhook by primary key.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete(
			$wpdb->prefix . 'wplm_webhooks',
			array( 'id' => $id ),
			array( '%d' )
		);

		return $result !== false && $result > 0;
	}

	/**
	 * Alias for find_by_id() — satisfies controllers that call find().
	 *
	 * @param int $id Row id.
	 * @return Webhook|null
	 */
	public function find( int $id ): ?Webhook {
		return $this->find_by_id( $id );
	}

	/**
	 * Alias for create() — satisfies controllers that call insert().
	 *
	 * @param array $data Column => value pairs.
	 * @return int Inserted row id.
	 */
	public function insert( array $data ): int {
		return $this->create( $data );
	}

	// -------------------------------------------------------------------------
	// Delivery log
	// -------------------------------------------------------------------------

	/**
	 * Insert a delivery log entry into wplm_webhook_log.
	 *
	 * Expected $data keys:
	 *   webhook_id     int
	 *   event          string
	 *   payload        array|string  (array will be JSON-encoded)
	 *   response_code  int
	 *   attempts       int
	 *   delivered_at   string|null
	 *   created_at     string  (defaults to current_time)
	 *
	 * @param array $data Column => value pairs.
	 * @return int Inserted row id.
	 * @throws \RuntimeException On DB error.
	 */
	public function log_delivery( array $data ): int {
		global $wpdb;

		if ( isset( $data['payload'] ) && is_array( $data['payload'] ) ) {
			$data['payload'] = wp_json_encode( $data['payload'] );
		}

		$data['created_at'] = $data['created_at'] ?? current_time( 'mysql' );

		$result = $wpdb->insert(
			$wpdb->prefix . 'wplm_webhook_log',
			$data
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'WPLM WebhookRepository: log INSERT failed — ' . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}
}
