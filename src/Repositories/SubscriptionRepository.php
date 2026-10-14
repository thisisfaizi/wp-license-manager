<?php
/**
 * Subscription repository — all $wpdb access for wplm_subscriptions.
 *
 * @package WPLM\Repositories
 */

namespace WPLM\Repositories;

use WPLM\Models\Subscription;
use WPLM\Models\SubscriptionItem;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for the wplm_subscriptions table.
 */
class SubscriptionRepository {

	// -------------------------------------------------------------------------
	// Finders
	// -------------------------------------------------------------------------

	/**
	 * Find a subscription by its primary key.
	 *
	 * @param int $id Row id.
	 * @return Subscription|null
	 */
	public function find_by_id( int $id ): ?Subscription {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_subscriptions';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return Subscription::from_row( $row );
	}

	/**
	 * Return all subscriptions belonging to a user.
	 *
	 * @param int $user_id WordPress user id.
	 * @return Subscription[]
	 */
	public function get_by_user( int $user_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_subscriptions';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE user_id = %d ORDER BY created_at DESC",
				$user_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( Subscription::class, 'from_row' ), $rows );
	}

	/**
	 * Return all subscriptions, newest first.
	 *
	 * @return Subscription[]
	 */
	public function get_all(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_subscriptions';
		$rows  = $wpdb->get_results(
			"SELECT * FROM `{$table}` ORDER BY id DESC",
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( Subscription::class, 'from_row' ), $rows );
	}

	/**
	 * Return subscriptions whose next payment is due now (or overdue).
	 * Includes active and trial subscriptions.
	 *
	 * @return Subscription[]
	 */
	public function get_due_for_renewal(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_subscriptions';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}`
				 WHERE status IN (%s, %s)
				   AND next_payment IS NOT NULL
				   AND next_payment <= UTC_TIMESTAMP()
				 ORDER BY next_payment ASC",
				'active',
				'trial'
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( Subscription::class, 'from_row' ), $rows );
	}

	/**
	 * Whether the 1.3.0 database upgrade (the `addons_license_id` column) has run. Between uploading the
	 * plugin and the next admin page load it has not, and the add-ons lookups must not query it.
	 *
	 * @return bool
	 */
	public static function has_addons_column(): bool {
		return version_compare( (string) get_option( 'wplm_db_version', '0' ), '1.3.0', '>=' );
	}

	/**
	 * The open add-ons-only subscription of a lifetime licence (the newest that is active, in trial,
	 * pending, on hold or pending cancellation), or null.
	 *
	 * @param int $license_id Licence id.
	 * @return Subscription|null
	 */
	public function find_addons_for_license( int $license_id ): ?Subscription {
		if ( ! self::has_addons_column() ) {
			return null;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wplm_subscriptions';
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a constant.
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE addons_license_id = %d AND status IN ('active','trial','pending','on-hold','pending-cancel') ORDER BY id DESC LIMIT 1", $license_id ),
			ARRAY_A
		);
		return is_array( $row ) ? Subscription::from_row( $row ) : null;
	}

	/**
	 * Every add-ons-only subscription of a licence, newest first (any status).
	 *
	 * @param int $license_id Licence id.
	 * @return Subscription[]
	 */
	public function get_addons_for_license( int $license_id ): array {
		if ( ! self::has_addons_column() ) {
			return array();
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wplm_subscriptions';
		$rows  = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a constant.
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE addons_license_id = %d ORDER BY id DESC", $license_id ),
			ARRAY_A
		);
		return is_array( $rows ) ? array_map( array( Subscription::class, 'from_row' ), $rows ) : array();
	}

	// -------------------------------------------------------------------------
	// Mutations
	// -------------------------------------------------------------------------

	/**
	 * Insert a new subscription row.
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

		$result = $wpdb->insert(
			$wpdb->prefix . 'wplm_subscriptions',
			$data
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'WPLM SubscriptionRepository: INSERT failed — ' . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing subscription row.
	 * Always sets updated_at to the current time.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Column => value pairs.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update(
			$wpdb->prefix . 'wplm_subscriptions',
			$data,
			array( 'id' => $id ),
			null,
			array( '%d' )
		);

		return $result !== false && $result > 0;
	}

	/**
	 * Update only the status column of a subscription.
	 *
	 * @param int    $id     Row id.
	 * @param string $status New status string.
	 * @return bool
	 */
	public function update_status( int $id, string $status ): bool {
		return $this->update( $id, array( 'status' => $status ) );
	}

	// -------------------------------------------------------------------------
	// Items (what one cycle bills)
	// -------------------------------------------------------------------------

	/**
	 * Every item of a subscription, oldest first.
	 *
	 * @param int $subscription_id Subscription id.
	 * @return SubscriptionItem[]
	 */
	public function get_items( int $subscription_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wplm_subscription_items';
		$rows  = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a constant.
			$wpdb->prepare( "SELECT id, subscription_id, product_id, variation_id, quantity, line_total, meta FROM `{$table}` WHERE subscription_id = %d ORDER BY id ASC", $subscription_id ),
			ARRAY_A
		);
		return is_array( $rows ) ? array_map( array( SubscriptionItem::class, 'from_row' ), $rows ) : array();
	}

	/**
	 * Add an item to a subscription.
	 *
	 * @param int   $subscription_id Subscription id.
	 * @param array $data            product_id, variation_id, quantity, line_total (the whole line), meta (array).
	 * @return int Item id.
	 * @throws \RuntimeException On DB error.
	 */
	public function add_item( int $subscription_id, array $data ): int {
		global $wpdb;
		$ok = $wpdb->insert(
			$wpdb->prefix . 'wplm_subscription_items',
			array(
				'subscription_id' => $subscription_id,
				'product_id'      => (int) ( $data['product_id'] ?? 0 ),
				'variation_id'    => isset( $data['variation_id'] ) ? (int) $data['variation_id'] : null,
				'quantity'        => (int) ( $data['quantity'] ?? 1 ),
				'line_total'      => (float) ( $data['line_total'] ?? 0.0 ),
				'meta'            => wp_json_encode( (array) ( $data['meta'] ?? array() ) ),
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'WPLM SubscriptionRepository: item INSERT failed — ' . $wpdb->last_error );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Change an item's total and/or meta.
	 *
	 * @param int   $item_id Item id.
	 * @param array $data    line_total and/or meta (array).
	 * @return bool
	 */
	public function update_item( int $item_id, array $data ): bool {
		global $wpdb;
		$update = array();
		if ( array_key_exists( 'line_total', $data ) ) {
			$update['line_total'] = (float) $data['line_total'];
		}
		if ( array_key_exists( 'meta', $data ) ) {
			$update['meta'] = wp_json_encode( (array) $data['meta'] );
		}
		if ( array() === $update ) {
			return false;
		}
		return false !== $wpdb->update( $wpdb->prefix . 'wplm_subscription_items', $update, array( 'id' => $item_id ) );
	}

	/**
	 * Remove an item.
	 *
	 * @param int $item_id Item id.
	 * @return bool True when a row was removed.
	 */
	public function delete_item( int $item_id ): bool {
		global $wpdb;
		return (int) $wpdb->delete( $wpdb->prefix . 'wplm_subscription_items', array( 'id' => $item_id ), array( '%d' ) ) > 0;
	}

	// -------------------------------------------------------------------------
	// Reporting / aggregates
	// -------------------------------------------------------------------------

	/**
	 * Return a paginated, filtered list of subscriptions for the admin screen.
	 *
	 * @param array $args per_page, page, orderby, order, status, user_id.
	 * @return array{items: Subscription[], total: int}
	 */
	public function get_list( array $args ): array {
		global $wpdb;

		$table    = $wpdb->prefix . 'wplm_subscriptions';
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$allowed_orderby = array( 'id', 'status', 'created_at', 'next_payment', 'user_id' );
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$order           = 'ASC' === strtoupper( $args['order'] ?? '' ) ? 'ASC' : 'DESC';

		$conditions = array();
		$cond_vals  = array();

		if ( ! empty( $args['status'] ) ) {
			$conditions[] = 'status = %s';
			$cond_vals[]  = $args['status'];
		}
		if ( ! empty( $args['user_id'] ) ) {
			$conditions[] = 'user_id = %d';
			$cond_vals[]  = (int) $args['user_id'];
		}

		$where = $conditions ? implode( ' AND ', $conditions ) : '1=1';

		if ( $cond_vals ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}", ...$cond_vals ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE {$where} ORDER BY `{$orderby}` {$order} LIMIT %d OFFSET %d",
					...array_merge( $cond_vals, array( $per_page, $offset ) )
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` ORDER BY `{$orderby}` {$order} LIMIT %d OFFSET %d",
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		return array(
			'items' => array_map( array( Subscription::class, 'from_row' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Return the monthly-recurring-revenue total for monthly active subscriptions.
	 * Normalisation across billing periods is intentionally left to AnalyticsService.
	 *
	 * @return float
	 */
	public function get_mrr_total(): float {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_subscriptions';

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(recurring_total) FROM `{$table}`
				 WHERE status = %s
				   AND billing_period = %s",
				'active',
				'month'
			)
		);

		return (float) $result;
	}

	/**
	 * Count subscriptions by status.
	 *
	 * @param string $status Status string.
	 * @return int
	 */
	public function count_by_status( string $status ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_subscriptions';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE status = %s",
				$status
			)
		);
	}
}
