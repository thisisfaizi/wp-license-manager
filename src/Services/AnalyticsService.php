<?php
/**
 * Analytics service — dashboard metrics, trend data, and anomaly detection.
 *
 * @package WPLM\Services
 */

namespace WPLM\Services;

use WPLM\Repositories\ActivationLogRepository;
use WPLM\Repositories\LicenseRepository;
use WPLM\Repositories\SubscriptionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregates license, machine, subscription, and log data into dashboard
 * widgets, trend series, and fraud-signal detection.
 *
 * All computed results are cached as transients with a 10-minute TTL using
 * the prefix wplm_analytics_. Call invalidate_cache() from any hook that
 * mutates license or machine data to force a fresh computation on next load.
 */
class AnalyticsService {

	/** Transient TTL in seconds (10 minutes). */
	const CACHE_TTL = 600;

	/** Transient key for the full dashboard payload. */
	const DASHBOARD_KEY = 'wplm_analytics_dashboard';

	/** @var LicenseRepository */
	private LicenseRepository $license_repo;

	/** @var ActivationLogRepository */
	private ActivationLogRepository $log_repo;

	/** @var SubscriptionRepository */
	private SubscriptionRepository $sub_repo;

	/**
	 * @param LicenseRepository       $license_repo License data-access layer.
	 * @param ActivationLogRepository $log_repo     Activation event log data-access layer.
	 * @param SubscriptionRepository  $sub_repo     Subscription data-access layer.
	 */
	public function __construct(
		LicenseRepository $license_repo,
		ActivationLogRepository $log_repo,
		SubscriptionRepository $sub_repo
	) {
		$this->license_repo = $license_repo;
		$this->log_repo     = $log_repo;
		$this->sub_repo     = $sub_repo;
	}

	// -------------------------------------------------------------------------
	// Dashboard
	// -------------------------------------------------------------------------

	/**
	 * Return all dashboard widget data as a single array.
	 *
	 * Results are cached as a transient for CACHE_TTL seconds. On a cache miss
	 * every widget method is called and the result is stored for subsequent requests.
	 *
	 * @return array {
	 *     @type array  $seat_utilization     Active/total seats and utilisation %.
	 *     @type array  $validations_per_day  Validate event counts for the last 7 days.
	 *     @type array  $status_breakdown     Map of license status code => count.
	 *     @type array  $version_distribution Top 10 app_version strings by device count.
	 *     @type array  $geographic_spread    Top 20 countries by event count (last 30 days).
	 *     @type array  $expiring_soon        Counts expiring in 7 and 30 days.
	 *     @type float  $mrr                  Monthly recurring revenue total.
	 *     @type array  $subscription_counts  Map of subscription status => count.
	 *     @type array  $top_licenses         Top 10 license IDs by event count (last 30 days).
	 * }
	 */
	public function get_dashboard_data(): array {
		$cached = get_transient( self::DASHBOARD_KEY );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$data = array(
			'seat_utilization'     => $this->get_seat_utilization(),
			'validations_per_day'  => $this->get_validations_per_day(),
			'status_breakdown'     => $this->get_status_breakdown(),
			'version_distribution' => $this->get_version_distribution(),
			'geographic_spread'    => $this->get_geographic_spread(),
			'expiring_soon'        => $this->get_expiring_soon(),
			'mrr'                  => $this->get_mrr(),
			'subscription_counts'  => $this->get_subscription_counts(),
			'top_licenses'         => $this->get_top_licenses(),
		);

		set_transient( self::DASHBOARD_KEY, $data, self::CACHE_TTL );

		return $data;
	}

	// -------------------------------------------------------------------------
	// Widget methods
	// -------------------------------------------------------------------------

	/**
	 * Compute the global seat utilisation across all active licenses.
	 *
	 * Queries the sum of activation_count (active seats) and max_activations
	 * (total seat capacity) for all licenses with status = 1 (active).
	 *
	 * @return array {
	 *     @type int   $active_seats     Total currently active seat count.
	 *     @type int   $total_seats      Total seat capacity across active licenses.
	 *     @type float $utilization_pct  Percentage of seats in use, rounded to 2 dp.
	 * }
	 */
	public function get_seat_utilization(): array {
		$cached = get_transient( 'wplm_analytics_seat_utilization' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wplm_licenses';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			"SELECT SUM(activation_count) as active_seats, SUM(max_activations) as total_seats
			 FROM `{$table}` WHERE status = 1",
			ARRAY_A
		);

		$active = (int) ( $row['active_seats'] ?? 0 );
		$total  = (int) ( $row['total_seats'] ?? 0 );
		$pct    = $total > 0 ? round( ( $active / $total ) * 100, 2 ) : 0.0;

		$result = array(
			'active_seats'    => $active,
			'total_seats'     => $total,
			'utilization_pct' => $pct,
		);

		set_transient( 'wplm_analytics_seat_utilization', $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return the per-day 'validate' event count for the last N days.
	 *
	 * @param int $days Number of days to look back (default 7).
	 * @return array[] Each element: ['date' => 'YYYY-MM-DD', 'count' => int].
	 */
	public function get_validations_per_day( int $days = 7 ): array {
		$cache_key = 'wplm_analytics_validations_' . $days;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wplm_activation_log';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as date, COUNT(*) as count
				 FROM `{$table}`
				 WHERE event = 'validate'
				   AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				 GROUP BY DATE(created_at)
				 ORDER BY date ASC",
				$days
			),
			ARRAY_A
		);

		$result = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$result[] = array(
					'date'  => (string) $row['date'],
					'count' => (int) $row['count'],
				);
			}
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return the count of licenses grouped by their status code.
	 *
	 * @return array<int, int> Map of status_code (int) => count (int).
	 */
	public function get_status_breakdown(): array {
		$cached = get_transient( 'wplm_analytics_status_breakdown' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wplm_licenses';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) as count FROM `{$table}` GROUP BY status",
			ARRAY_A
		);

		$result = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$result[ (int) $row['status'] ] = (int) $row['count'];
			}
		}

		set_transient( 'wplm_analytics_status_breakdown', $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return the top 10 app_version strings by active device count.
	 *
	 * Only active machines (status = 1) with a non-null app_version are included.
	 *
	 * @return array[] Each element: ['app_version' => string, 'count' => int].
	 */
	public function get_version_distribution(): array {
		$cached = get_transient( 'wplm_analytics_version_distribution' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wplm_machines';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT app_version, COUNT(*) as count
			 FROM `{$table}`
			 WHERE status = 1 AND app_version IS NOT NULL
			 GROUP BY app_version
			 ORDER BY count DESC
			 LIMIT 10",
			ARRAY_A
		);

		$result = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$result[] = array(
					'app_version' => (string) $row['app_version'],
					'count'       => (int) $row['count'],
				);
			}
		}

		set_transient( 'wplm_analytics_version_distribution', $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return the top 20 countries by activation-log event count for the last 30 days.
	 *
	 * Only rows with a non-null country column are included.
	 *
	 * @return array[] Each element: ['country' => string, 'count' => int].
	 */
	public function get_geographic_spread(): array {
		$cached = get_transient( 'wplm_analytics_geographic_spread' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wplm_activation_log';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT country, COUNT(*) as count
			 FROM `{$table}`
			 WHERE country IS NOT NULL
			   AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
			 GROUP BY country
			 ORDER BY count DESC
			 LIMIT 20",
			ARRAY_A
		);

		$result = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$result[] = array(
					'country' => (string) $row['country'],
					'count'   => (int) $row['count'],
				);
			}
		}

		set_transient( 'wplm_analytics_geographic_spread', $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return counts of licenses expiring within the next 7 and 30 days.
	 *
	 * Delegates to LicenseRepository::count_expiring_soon() for each window.
	 *
	 * @param int $days Primary look-ahead window in days (default 7).
	 * @return array {
	 *     @type int $in_7_days  Licenses expiring within 7 days.
	 *     @type int $in_30_days Licenses expiring within 30 days.
	 * }
	 */
	public function get_expiring_soon( int $days = 7 ): array {
		$cache_key = 'wplm_analytics_expiring_' . $days;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$result = array(
			'in_7_days'  => $this->license_repo->count_expiring_soon( 7 ),
			'in_30_days' => $this->license_repo->count_expiring_soon( 30 ),
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return the current monthly-recurring-revenue (MRR) total.
	 *
	 * Delegates to SubscriptionRepository::get_mrr_total(), which sums the
	 * recurring_total for all active monthly-billed subscriptions.
	 *
	 * @return float MRR total in the store's base currency.
	 */
	public function get_mrr(): float {
		$cached = get_transient( 'wplm_analytics_mrr' );
		if ( false !== $cached ) {
			return (float) $cached;
		}

		$mrr = $this->sub_repo->get_mrr_total();

		set_transient( 'wplm_analytics_mrr', $mrr, self::CACHE_TTL );

		return $mrr;
	}

	/**
	 * Return the count of subscriptions grouped by status string.
	 *
	 * Enumerates the seven known WPLM subscription statuses.
	 *
	 * @return array<string, int> Map of status string => count.
	 */
	public function get_subscription_counts(): array {
		$cached = get_transient( 'wplm_analytics_subscription_counts' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$statuses = array(
			'pending',
			'active',
			'trial',
			'on-hold',
			'pending-cancel',
			'cancelled',
			'expired',
		);

		$result = array();
		foreach ( $statuses as $status ) {
			$result[ $status ] = $this->sub_repo->count_by_status( $status );
		}

		set_transient( 'wplm_analytics_subscription_counts', $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return the top N license IDs by activation-log event count for the last 30 days.
	 *
	 * @param int $limit Maximum number of results to return (default 10).
	 * @return array[] Each element: ['license_id' => int, 'event_count' => int].
	 */
	public function get_top_licenses( int $limit = 10 ): array {
		$cache_key = 'wplm_analytics_top_licenses_' . $limit;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wplm_activation_log';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT license_id, COUNT(*) as event_count
				 FROM `{$table}`
				 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
				 GROUP BY license_id
				 ORDER BY event_count DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$result = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$result[] = array(
					'license_id'  => (int) $row['license_id'],
					'event_count' => (int) $row['event_count'],
				);
			}
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	// -------------------------------------------------------------------------
	// Anomaly detection
	// -------------------------------------------------------------------------

	/**
	 * Run anomaly-detection heuristics against a single license.
	 *
	 * Three signals are evaluated:
	 *
	 * 1. **Fingerprint churn** — more than 5 new device fingerprints registered
	 *    for this license in the last 24 hours.
	 *
	 * 2. **Validation spike** — validate events in the last hour exceed 3× the
	 *    average hourly rate computed over the preceding 7 days.
	 *
	 * 3. **Impossible travel** — the license appears in more than 2 distinct
	 *    countries within a single hour.
	 *
	 * When any flags are raised the `wplm_anomaly_detected` action is fired with
	 * the license model, the flag array, and an empty context array as arguments.
	 *
	 * @param int $license_id License row ID to inspect.
	 * @return array {
	 *     @type string[] $flags      Names of triggered anomaly signals (may be empty).
	 *     @type int      $license_id The inspected license ID.
	 * }
	 */
	public function detect_anomalies( int $license_id ): array {
		global $wpdb;

		$flags = array();

		// -----------------------------------------------------------------
		// 1. Fingerprint churn — more than 5 new devices in the last 24 h.
		// -----------------------------------------------------------------
		$machines_table = $wpdb->prefix . 'wplm_machines';

		$new_devices_24h = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT fingerprint) as count
				 FROM `{$machines_table}`
				 WHERE license_id = %d
				   AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
				$license_id
			)
		);

		if ( $new_devices_24h > 5 ) {
			$flags[] = 'fingerprint_churn';
		}

		// -----------------------------------------------------------------
		// 2. Validation spike — current hour vs. 7-day average hourly rate.
		// -----------------------------------------------------------------
		$log_table = $wpdb->prefix . 'wplm_activation_log';

		$current_hour_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$log_table}`
				 WHERE license_id = %d
				   AND event = 'validate'
				   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
				$license_id
			)
		);

		$seven_day_total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$log_table}`
				 WHERE license_id = %d
				   AND event = 'validate'
				   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
				$license_id
			)
		);

		// 7 days = 168 hours.
		$avg_per_hour = $seven_day_total > 0 ? ( $seven_day_total / 168 ) : 0;

		if ( $avg_per_hour > 0 && $current_hour_count > ( $avg_per_hour * 3 ) ) {
			$flags[] = 'validation_spike';
		}

		// -----------------------------------------------------------------
		// 3. Impossible travel — > 2 distinct countries in the last hour.
		// -----------------------------------------------------------------
		$countries_1h = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT country, MIN(created_at) as t
				 FROM `{$log_table}`
				 WHERE license_id = %d
				   AND country IS NOT NULL
				   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
				 GROUP BY country",
				$license_id
			),
			ARRAY_A
		);

		if ( is_array( $countries_1h ) && count( $countries_1h ) > 2 ) {
			$flags[] = 'impossible_travel';
		}

		// -----------------------------------------------------------------
		// Fire action hook when at least one anomaly was detected.
		// -----------------------------------------------------------------
		if ( ! empty( $flags ) ) {
			$license = $this->license_repo->find_by_id( $license_id );

			/**
			 * Fires when one or more anomaly signals are detected for a license.
			 *
			 * @param \WPLM\Models\License|null $license    The license model (null when not found).
			 * @param string[]                  $flags      Array of triggered anomaly signal names.
			 * @param array                     $context    Additional context (currently empty).
			 */
			do_action( 'wplm_anomaly_detected', $license, $flags, array() );
		}

		return array(
			'flags'      => $flags,
			'license_id' => $license_id,
		);
	}

	// -------------------------------------------------------------------------
	// Cache management
	// -------------------------------------------------------------------------

	/**
	 * Invalidate all analytics transients.
	 *
	 * Call this from hooks that mutate license or machine data so the next
	 * dashboard request triggers a fresh computation.
	 *
	 * @return void
	 */
	public function invalidate_cache(): void {
		delete_transient( self::DASHBOARD_KEY );
		delete_transient( 'wplm_analytics_seat_utilization' );
		delete_transient( 'wplm_analytics_status_breakdown' );
		delete_transient( 'wplm_analytics_version_distribution' );
		delete_transient( 'wplm_analytics_geographic_spread' );
		delete_transient( 'wplm_analytics_mrr' );
		delete_transient( 'wplm_analytics_subscription_counts' );

		// Clear variable-key transients for common $days and $limit values.
		foreach ( array( 7, 30 ) as $days ) {
			delete_transient( 'wplm_analytics_validations_' . $days );
			delete_transient( 'wplm_analytics_expiring_' . $days );
		}

		foreach ( array( 5, 10, 20, 25, 50 ) as $limit ) {
			delete_transient( 'wplm_analytics_top_licenses_' . $limit );
		}
	}
}
