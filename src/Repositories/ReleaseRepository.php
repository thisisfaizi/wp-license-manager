<?php
/**
 * Release repository — all $wpdb access for wplm_releases.
 *
 * @package WPLM\Repositories
 */

namespace WPLM\Repositories;

use WPLM\Models\Release;

defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for the wplm_releases table.
 */
class ReleaseRepository {

	// -------------------------------------------------------------------------
	// Finders
	// -------------------------------------------------------------------------

	/**
	 * Find a release by its primary key.
	 *
	 * @param int $id Row id.
	 * @return Release|null
	 */
	public function find_by_id( int $id ): ?Release {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_releases';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return Release::from_row( $row );
	}

	/**
	 * Get the most recent release for a product on a given channel.
	 *
	 * @param int    $product_id WooCommerce product id.
	 * @param string $channel    stable | beta | rc
	 * @return Release|null
	 */
	public function get_latest_for_product( int $product_id, string $channel = 'stable' ): ?Release {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_releases';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}`
				 WHERE product_id = %d AND channel = %s
				 ORDER BY released_at DESC
				 LIMIT 1",
				$product_id,
				$channel
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return Release::from_row( $row );
	}

	/**
	 * Get all releases for a product, newest first.
	 *
	 * @param int $product_id WooCommerce product id.
	 * @return Release[]
	 */
	public function get_by_product( int $product_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_releases';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE product_id = %d ORDER BY released_at DESC",
				$product_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( Release::class, 'from_row' ), $rows );
	}

	/**
	 * Return a paginated, filtered list of releases for the admin screen.
	 *
	 * @param array $args per_page, page, orderby, order, product_id, channel.
	 * @return array{items: Release[], total: int}
	 */
	public function get_list( array $args ): array {
		global $wpdb;

		$table    = $wpdb->prefix . 'wplm_releases';
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$allowed_orderby = array( 'id', 'version', 'channel', 'product_id', 'released_at' );
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'released_at';
		$order           = 'ASC' === strtoupper( $args['order'] ?? '' ) ? 'ASC' : 'DESC';

		$conditions = array();
		$cond_vals  = array();

		if ( ! empty( $args['product_id'] ) ) {
			$conditions[] = 'product_id = %d';
			$cond_vals[]  = (int) $args['product_id'];
		}
		if ( ! empty( $args['channel'] ) ) {
			$conditions[] = 'channel = %s';
			$cond_vals[]  = $args['channel'];
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
			'items' => array_map( array( Release::class, 'from_row' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	// -------------------------------------------------------------------------
	// Mutations
	// -------------------------------------------------------------------------

	/**
	 * Insert a new release row.
	 *
	 * @param array $data Column => value pairs.
	 * @return int Inserted row id.
	 * @throws \RuntimeException On DB error.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$data['released_at'] = $data['released_at'] ?? current_time( 'mysql' );

		$result = $wpdb->insert(
			$wpdb->prefix . 'wplm_releases',
			$data
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'WPLM ReleaseRepository: INSERT failed — ' . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a release by primary key.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete(
			$wpdb->prefix . 'wplm_releases',
			array( 'id' => $id ),
			array( '%d' )
		);

		return $result !== false && $result > 0;
	}
}
