<?php
/**
 * Subscription repository — all $wpdb access for wplm_subscriptions.
 *
 * @package WPLM\Repositories
 */

namespace WPLM\Repositories;

use WPLM\Models\Subscription;

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
				   AND next_payment <= NOW()
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
