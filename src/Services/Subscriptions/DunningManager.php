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
 * Default retry schedule: attempt again 1 day, 3 days, then 5 days after the
 * original failure. After all retries are exhausted the subscription is
 * cancelled immediately and the bound license is revoked.
 *
 * The schedule is filterable via `wplm_dunning_retry_schedule` so merchants
 * can configure their own retry windows.
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
	 * Handle a failed renewal charge attempt.
	 *
	 * Steps:
	 *   1. Read the configured retry schedule (filterable).
	 *   2. Increment failed_attempts on the subscription.
	 *   3. If attempts have been exhausted: cancel the subscription immediately
	 *      (which revokes the license) and fire the final-failure action.
	 *   4. Otherwise: put the subscription on-hold (which suspends the license),
	 *      schedule the next retry, and fire the per-attempt failure action.
	 *
	 * @param Subscription $sub The subscription whose renewal just failed.
	 * @return void
	 */
	public function handle_failure( Subscription $sub ): void {
		/**
		 * Filter the dunning retry schedule.
		 *
		 * Each element is the number of days after the original failure on
		 * which to retry. Default: [1, 3, 5].
		 *
		 * @param int[] $schedule Array of day offsets for retry attempts.
		 */
		$schedule = (array) apply_filters( 'wplm_dunning_retry_schedule', array( 1, 3, 5 ) );

		// Increment the attempt counter. The value already stored in DB is the
		// count of failures so far (0 = first failure happening now).
		$attempts_so_far = $sub->failed_attempts + 1;

		// Exhausted all retries?
		if ( $attempts_so_far >= count( $schedule ) ) {
			// Update the counter in DB first so it's accurate if any action
			// hook reads the record.
			$this->sub_repo->update( $sub->id, array( 'failed_attempts' => $attempts_so_far ) );

			// Immediate cancel + license revoke.
			$this->sub_service->cancel( $sub->id, false );

			/**
			 * Fires after dunning retries are exhausted and the subscription is cancelled.
			 *
			 * @param Subscription $sub The (now cancelled) subscription.
			 */
			do_action( 'wplm_subscription_payment_failed_final', $sub );

			return;
		}

		// Suspend the subscription while we wait for the retry.
		$this->sub_service->pause( $sub->id );

		// Compute the next retry date: $schedule[$attempts_so_far - 1] is the
		// day offset for this attempt (0-indexed into the schedule array).
		$day_offset = (int) ( $schedule[ $attempts_so_far - 1 ] ?? 1 );
		$next_retry = gmdate( 'Y-m-d H:i:s', strtotime( "+{$day_offset} days" ) );

		$this->sub_repo->update(
			$sub->id,
			array(
				'next_payment'    => $next_retry,
				'failed_attempts' => $attempts_so_far,
			)
		);

		/**
		 * Fires after a renewal charge fails and a retry is scheduled.
		 *
		 * @param Subscription $sub      The subscription with the updated failed_attempts count.
		 * @param int          $attempts The total number of failed attempts so far (1-based).
		 */
		do_action( 'wplm_subscription_payment_failed', $sub, $attempts_so_far );
	}
}
