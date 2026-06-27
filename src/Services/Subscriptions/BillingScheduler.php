<?php
/**
 * Billing scheduler — next-payment date computation, synchronisation, and
 * first-payment proration for the native subscription engine.
 *
 * @package WPLM\Services\Subscriptions
 */

namespace WPLM\Services\Subscriptions;

use WPLM\Models\Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helper that converts billing interval + period into concrete
 * datetime strings and optionally prorates the first payment to a fixed
 * sync day of month.
 */
class BillingScheduler {

	// -------------------------------------------------------------------------
	// Next-payment computation
	// -------------------------------------------------------------------------

	/**
	 * Compute the next payment date for a subscription.
	 *
	 * Base date selection priority (first non-null wins):
	 *   1. $from_date (explicit override)
	 *   2. $sub->last_payment
	 *   3. $sub->trial_end
	 *   4. current_time( 'mysql' )
	 *
	 * All arithmetic is performed in UTC so the stored DATETIME is always UTC.
	 *
	 * @param Subscription $sub       The subscription record.
	 * @param string|null  $from_date Optional override base date (Y-m-d H:i:s, UTC).
	 * @return string Next payment datetime (Y-m-d H:i:s, UTC).
	 */
	public function next_payment( Subscription $sub, ?string $from_date = null ): string {
		$base = $from_date
			?? $sub->last_payment
			?? $sub->trial_end
			?? current_time( 'mysql' );

		$dt = new \DateTime( $base, new \DateTimeZone( 'UTC' ) );
		$dt->add( new \DateInterval( $this->period_to_interval( $sub->billing_interval, $sub->billing_period ) ) );

		$computed = $dt->format( 'Y-m-d H:i:s' );

		return $this->apply_filters_next_payment( $sub, $computed );
	}

	/**
	 * Convert a billing interval + period pair into a PHP DateInterval spec.
	 *
	 * @param int    $n      Number of periods (e.g. 1, 3, 6).
	 * @param string $period Period unit: day | week | month | year.
	 * @return string DateInterval duration string (e.g. 'P1M', 'P2W').
	 * @throws \InvalidArgumentException For an unrecognised period unit.
	 */
	public function period_to_interval( int $n, string $period ): string {
		switch ( $period ) {
			case 'day':
				return "P{$n}D";
			case 'week':
				return "P{$n}W";
			case 'month':
				return "P{$n}M";
			case 'year':
				return "P{$n}Y";
			default:
				throw new \InvalidArgumentException(
					sprintf( 'WPLM BillingScheduler: unrecognised billing period "%s".', $period )
				);
		}
	}

	// -------------------------------------------------------------------------
	// First-payment proration
	// -------------------------------------------------------------------------

	/**
	 * Calculate the first (potentially prorated) payment amount and period bounds.
	 *
	 * When $sync_day_of_month is empty or '0', the full recurring amount is
	 * charged immediately and the period runs for one full billing cycle.
	 *
	 * When a sync day is provided, the first payment covers only the partial
	 * period from today until the next occurrence of that day, and the amount
	 * is prorated proportionally.
	 *
	 * @param float  $recurring_total    Full recurring charge for one cycle.
	 * @param int    $billing_interval   Every N periods.
	 * @param string $billing_period     day | week | month | year.
	 * @param string $sync_day_of_month  Day of month to align to (e.g. '1', '15').
	 *                                   Pass '' or '0' to disable synchronisation.
	 * @return array {
	 *     @type float  $amount       Amount due for the first payment.
	 *     @type string $period_start Start of the billing period (Y-m-d H:i:s, UTC).
	 *     @type string $period_end   End of the billing period / next sync date (Y-m-d H:i:s, UTC).
	 * }
	 */
	public function prorate_first_payment(
		float $recurring_total,
		int $billing_interval,
		string $billing_period,
		string $sync_day_of_month = ''
	): array {
		$now = current_time( 'mysql' );

		// No sync — full cycle from now.
		if ( '' === $sync_day_of_month || '0' === $sync_day_of_month ) {
			return array(
				'amount'       => $recurring_total,
				'period_start' => $now,
				'period_end'   => $this->next_payment_from_now( $billing_interval, $billing_period ),
			);
		}

		$sync_day = (int) $sync_day_of_month;

		// Build the next occurrence of the sync day in UTC.
		$today = new \DateTime( $now, new \DateTimeZone( 'UTC' ) );
		$today->setTime( 0, 0, 0 );

		$sync_date = $this->next_sync_date( $today, $sync_day );

		// Days remaining until the sync date.
		$days_remaining = (int) $today->diff( $sync_date )->days;

		// Total days in one full billing period from now.
		$full_period_end = new \DateTime( $now, new \DateTimeZone( 'UTC' ) );
		$full_period_end->add( new \DateInterval( $this->period_to_interval( $billing_interval, $billing_period ) ) );
		$total_days = (int) ( new \DateTime( $now, new \DateTimeZone( 'UTC' ) ) )->diff( $full_period_end )->days;

		// Guard against zero-day edge cases.
		if ( $total_days <= 0 ) {
			$prorated = $recurring_total;
		} else {
			$prorated = round( $recurring_total * ( $days_remaining / $total_days ), 2 );
		}

		return array(
			'amount'       => $prorated,
			'period_start' => $now,
			'period_end'   => $sync_date->format( 'Y-m-d H:i:s' ),
		);
	}

	// -------------------------------------------------------------------------
	// Filter hook
	// -------------------------------------------------------------------------

	/**
	 * Pass the computed next-payment date through the wplm_subscription_next_payment
	 * filter so third-party code can override it.
	 *
	 * @param Subscription $sub      The subscription record.
	 * @param string       $computed The date string computed by this scheduler.
	 * @return string Filtered next-payment date.
	 */
	public function apply_filters_next_payment( Subscription $sub, string $computed ): string {
		/**
		 * Filter the computed next payment date for a subscription.
		 *
		 * @param string       $computed The date computed by BillingScheduler (Y-m-d H:i:s, UTC).
		 * @param Subscription $sub      The subscription record.
		 */
		return (string) apply_filters( 'wplm_subscription_next_payment', $computed, $sub );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Compute the next payment date starting from the current time.
	 *
	 * @param int    $n      Number of periods.
	 * @param string $period Period unit.
	 * @return string Next payment datetime (Y-m-d H:i:s, UTC).
	 */
	private function next_payment_from_now( int $n, string $period ): string {
		$dt = new \DateTime( current_time( 'mysql' ), new \DateTimeZone( 'UTC' ) );
		$dt->add( new \DateInterval( $this->period_to_interval( $n, $period ) ) );
		return $dt->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Return the next calendar date on which the given day-of-month falls.
	 * If today IS already the sync day, we advance to next month's occurrence
	 * so that a subscription created on the sync day gets a full partial period.
	 *
	 * @param \DateTime $from     Reference date (midnight UTC, modified in-place clone not needed).
	 * @param int       $sync_day Target day of month (1–31).
	 * @return \DateTime The next sync date (midnight UTC).
	 */
	private function next_sync_date( \DateTime $from, int $sync_day ): \DateTime {
		$candidate = clone $from;
		$candidate->setDate(
			(int) $candidate->format( 'Y' ),
			(int) $candidate->format( 'n' ),
			min( $sync_day, (int) $candidate->format( 't' ) ) // clamp to last day of month
		);
		$candidate->setTime( 0, 0, 0 );

		// If the candidate is today or in the past, move to next month.
		if ( $candidate <= $from ) {
			$candidate->modify( 'first day of next month' );
			$candidate->setDate(
				(int) $candidate->format( 'Y' ),
				(int) $candidate->format( 'n' ),
				min( $sync_day, (int) $candidate->format( 't' ) )
			);
			$candidate->setTime( 0, 0, 0 );
		}

		return $candidate;
	}
}
