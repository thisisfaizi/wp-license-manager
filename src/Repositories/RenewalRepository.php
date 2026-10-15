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

	/**
	 * Mark every renewal and invoice row of an order refunded (a refunded renewal order).
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return int Rows changed.
	 */
	public function mark_order_refunded( int $order_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_subscription_renewals';
		$rows  = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = 'refunded' WHERE order_id = %d AND type IN ('renewal', 'invoice')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a constant.
				$order_id
			)
		);

		return is_int( $rows ) ? $rows : 0;
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

	/**
	 * Whether a subscription was paid for, or switched, after a renewal row: a later successful renewal
	 * or switch row (by id, which never ties).
	 *
	 * @param int $subscription_id Subscription row id.
	 * @param int $renewal_id      The renewal row.
	 * @return bool
	 */
	public function has_later_payment( int $subscription_id, int $renewal_id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_subscription_renewals';
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE subscription_id = %d AND id > %d AND type IN ('renewal', 'switch') AND status = 'success' LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a constant.
				$subscription_id,
				$renewal_id
			)
		);
	}

	/**
	 * The newest renewal row an order paid for a subscription (type `renewal`), or 0.
	 *
	 * @param int $subscription_id Subscription row id.
	 * @param int $order_id        WooCommerce order id.
	 * @return int
	 */
	public function renewal_id_for_order( int $subscription_id, int $order_id ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_subscription_renewals';
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE subscription_id = %d AND order_id = %d AND type = 'renewal' ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a constant.
				$subscription_id,
				$order_id
			)
		);
	}
}
