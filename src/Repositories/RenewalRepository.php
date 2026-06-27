<?php
/**
 * Renewal repository — all $wpdb access for wplm_subscription_renewals.
 *
 * @package WPLM\Repositories
 */

namespace WPLM\Repositories;

use WPLM\Models\Renewal;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for the wplm_subscription_renewals table.
 */
class RenewalRepository {

	// -------------------------------------------------------------------------
	// Mutations
	// -------------------------------------------------------------------------

	/**
	 * Insert a new renewal row.
	 *
	 * Expected $data keys map to wplm_subscription_renewals columns:
	 *   subscription_id  int
	 *   order_id         int|null
	 *   type             string  renewal|switch|resubscribe
	 *   amount           float
	 *   status           string  pending|success|failed
	 *   gateway_txn      string|null
	 *   scheduled_for    string  datetime
	 *   processed_at     string|null
	 *   created_at       string  (defaults to current_time if omitted)
	 *
	 * @param array $data Column => value pairs.
	 * @return int Inserted row id.
	 * @throws \RuntimeException On DB error.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$data['created_at'] = $data['created_at'] ?? current_time( 'mysql' );

		$result = $wpdb->insert(
			$wpdb->prefix . 'wplm_subscription_renewals',
			$data
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'WPLM RenewalRepository: INSERT failed — ' . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing renewal row.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Column => value pairs.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$result = $wpdb->update(
			$wpdb->prefix . 'wplm_subscription_renewals',
			$data,
			array( 'id' => $id ),
			null,
			array( '%d' )
		);

		return $result !== false && $result > 0;
	}

	// -------------------------------------------------------------------------
	// Finders
	// -------------------------------------------------------------------------

	/**
	 * Return all renewal rows for a subscription, newest first.
	 *
	 * @param int $subscription_id Subscription row id.
	 * @return Renewal[]
	 */
	public function get_by_subscription( int $subscription_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_subscription_renewals';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE subscription_id = %d ORDER BY created_at DESC",
				$subscription_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( Renewal::class, 'from_row' ), $rows );
	}
}
