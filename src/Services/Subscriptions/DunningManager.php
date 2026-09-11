<?php
/**
 * Dunning manager — failed-payment retry schedule for the native subscription
 * engine.
 *
 * @package WPLM\Services\Subscriptions
 */

namespace WPLM\Services\Subscriptions;

use WPLM\Models\Subscription;
use WPLM\Repositories\SubscriptionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the dunning cycle after a renewal charge fails.
 *
 * The schedule lists the retry delays in days after each failure (default 1, 3, 5: three
 * retries). While retrying, the subscription stays `active` so the due-renewal query picks
 * the retry up. When every retry has failed, the subscription goes `on-hold` (awaiting a
 * manual payment) and the customer is invoiced to pay by hand.
 *
 * **Nothing here changes the licence.** Access ends only because the paid term
 * (expires_at + grace) runs out; locking a customer is an owner action. The previous
 * behaviour — suspend the licence on the first failure, park the subscription where the
 * retry query never saw it, revoke on the last — locked out customers who then could not
 * be recovered automatically (audit F3).
 *
 * The schedule comes from the `wplm_dunning_schedule` setting and is filterable via
 * `wplm_dunning_retry_schedule`.
 */
class DunningManager {

	/** @var SubscriptionRepository */
	private SubscriptionRepository $sub_repo;

	/** @var SubscriptionService */
	private SubscriptionService $sub_service;

	/**
	 * @param SubscriptionRepository $sub_repo    Subscription data-access layer.
	 * @param SubscriptionService    $sub_service Subscription lifecycle service.
	 */
	public function __construct(
		SubscriptionRepository $sub_repo,
		SubscriptionService $sub_service
	) {
		$this->sub_repo    = $sub_repo;
		$this->sub_service = $sub_service;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * The retry delays, in days, one per retry.
	 *
	 * @return int[]
	 */
	public function retry_schedule(): array {
		$stored   = json_decode( (string) get_option( 'wplm_dunning_schedule', '' ), true );
		$schedule = is_array( $stored ) && ! empty( $stored ) ? $stored : array( 1, 3, 5 );

		/**
		 * Filter the dunning retry schedule.
		 *
		 * Each element is the number of days after a failure on which to retry.
		 *
		 * @param int[] $schedule Retry delays in days.
		 */
		$schedule = (array) apply_filters( 'wplm_dunning_retry_schedule', $schedule );

		return array_values( array_filter( array_map( 'absint', $schedule ) ) );
	}

	/**
	 * Handle a failed renewal charge attempt.
	 *
	 * @param Subscription $sub The subscription whose renewal just failed.
	 * @return void
	 */
	public function handle_failure( Subscription $sub ): void {
		$schedule = $this->retry_schedule();
		$attempts = $sub->failed_attempts + 1;

		if ( $attempts > count( $schedule ) ) {
			$this->sub_repo->update( $sub->id, array( 'failed_attempts' => $attempts ) );

			if ( 'on-hold' !== $sub->status ) {
				$this->sub_service->update_status( $sub->id, 'on-hold' );
			}

			$updated = $this->sub_repo->find_by_id( $sub->id ) ?? $sub;

			/** This action is documented in RenewalProcessor::process_single(). */
			do_action( 'wplm_subscription_manual_renewal_due', $updated );

			/**
			 * Fires after every retry has failed and the subscription awaits a manual payment.
			 *
			 * @param Subscription $updated The on-hold subscription.
			 */
			do_action( 'wplm_subscription_payment_failed_final', $updated );

			return;
		}

		$day_offset = (int) $schedule[ $attempts - 1 ];
		$next_retry = gmdate( 'Y-m-d H:i:s', time() + $day_offset * DAY_IN_SECONDS );

		$this->sub_repo->update(
			$sub->id,
			array(
				'next_payment'    => $next_retry,
				'failed_attempts' => $attempts,
			)
		);

		/**
		 * Fires after a renewal charge fails and a retry is scheduled.
		 *
		 * @param Subscription $sub      The subscription (before the retry date was stored).
		 * @param int          $attempts The total number of failed attempts so far (1-based).
		 */
		do_action( 'wplm_subscription_payment_failed', $sub, $attempts );
	}
}
