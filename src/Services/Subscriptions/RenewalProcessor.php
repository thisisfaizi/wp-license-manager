<?php
/**
 * Renewal processor — WP-Cron driven automatic rebilling for the native
 * subscription engine.
 *
 * @package WPLM\Services\Subscriptions
 */

namespace WPLM\Services\Subscriptions;

use WPLM\Models\Renewal;
use WPLM\Models\Subscription;
use WPLM\Repositories\RenewalRepository;
use WPLM\Repositories\SubscriptionRepository;
use WPLM\Services\LicenseService;
use WPLM\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Processes all subscriptions that are due for renewal.
 *
 * Called from Cron\Scheduler on the `wplm_process_renewals` hook (hourly).
 * Each subscription whose next_payment ≤ now (UTC_TIMESTAMP(), whatever the MySQL time zone) and status ∈ {active, trial}
 * is processed individually in a try/catch so one failure does not block the
 * rest of the batch.
 */
class RenewalProcessor {

	/** @var SubscriptionRepository */
	private SubscriptionRepository $sub_repo;

	/** @var RenewalRepository */
	private RenewalRepository $renewal_repo;

	/** @var GatewayBridge */
	private GatewayBridge $gateway;

	/** @var DunningManager */
	private DunningManager $dunning;

	/** @var LicenseService */
	private LicenseService $license_service;

	/** @var BillingScheduler */
	private BillingScheduler $scheduler;

	/** @var SubscriptionService */
	private SubscriptionService $sub_service;

	/**
	 * @param SubscriptionRepository $sub_repo        Subscription data-access layer.
	 * @param RenewalRepository      $renewal_repo    Renewal data-access layer.
	 * @param GatewayBridge          $gateway         Payment gateway abstraction.
	 * @param DunningManager         $dunning         Failed-payment retry handler.
	 * @param LicenseService         $license_service License lifecycle service.
	 * @param BillingScheduler       $scheduler       Next-payment date computation.
	 * @param SubscriptionService    $sub_service     Subscription lifecycle service.
	 */
	public function __construct(
		SubscriptionRepository $sub_repo,
		RenewalRepository $renewal_repo,
		GatewayBridge $gateway,
		DunningManager $dunning,
		LicenseService $license_service,
		BillingScheduler $scheduler,
		SubscriptionService $sub_service
	) {
		$this->sub_repo        = $sub_repo;
		$this->renewal_repo    = $renewal_repo;
		$this->gateway         = $gateway;
		$this->dunning         = $dunning;
		$this->license_service = $license_service;
		$this->scheduler       = $scheduler;
		$this->sub_service     = $sub_service;
	}

	// -------------------------------------------------------------------------
	// Batch processing
	// -------------------------------------------------------------------------

	/**
	 * Query and process all subscriptions that are due for renewal right now.
	 *
	 * Each subscription is wrapped in an individual try/catch so an error on
	 * one subscription does not prevent others from being processed.
	 *
	 * @return void
	 */
	public function process_due_renewals(): void {
		$subscriptions = $this->sub_repo->get_due_for_renewal();

		foreach ( $subscriptions as $sub ) {
			try {
				$this->process_single( $sub );
			} catch ( \Throwable $e ) {
				// Log silently so the batch continues; the WP admin will see
				// the failed_attempts counter on the subscription screen.
				Logger::error(
					sprintf(
						'RenewalProcessor: unhandled exception for subscription %d — %s',
						$sub->id,
						$e->getMessage()
					)
				);
			}
		}
	}

	// -------------------------------------------------------------------------
	// Single-subscription processing
	// -------------------------------------------------------------------------

	/**
	 * Process a single due subscription.
	 *
	 * Flow:
	 *   1. No payment token → the customer pays by hand: fire the manual-renewal action
	 *      (the WooCommerce integration issues and emails one renewal invoice per cycle).
	 *   2. Token present → charge via GatewayBridge.
	 *   3. Success → record the renewal and apply the payment (see apply_payment()).
	 *   4. Failure → hand off to DunningManager.
	 *
	 * @param Subscription $sub Subscription to process.
	 * @return void
	 */
	public function process_single( Subscription $sub ): void {
		// No token stored — fall back to manual invoice flow.
		if ( null === $sub->payment_token_id ) {
			/**
			 * Fires when a subscription with no stored payment token is due, or when every card
			 * retry has failed. Fires on every cron run while the cycle is unpaid; listeners must
			 * be idempotent per cycle.
			 *
			 * @param Subscription $sub The subscription awaiting manual renewal.
			 */
			do_action( 'wplm_subscription_manual_renewal_due', $sub );
			return;
		}

		// Attempt the automatic charge.
		$result = $this->gateway->charge(
			$sub->payment_token_id,
			$sub->recurring_total,
			$sub->currency,
			$sub->id
		);

		if ( is_wp_error( $result ) ) {
			// Delegate to dunning so the retry schedule is applied.
			$this->dunning->handle_failure( $sub );
			return;
		}

		// --- Success path ---

		/**
		 * Filter: create a WooCommerce renewal order for this subscription charge.
		 *
		 * @param int          $order_id Default 0 (no WC order created).
		 * @param Subscription $sub      The subscription being renewed.
		 */
		$order_id = (int) apply_filters( 'wplm_create_renewal_order', 0, $sub );

		$renewal = $this->apply_payment( $sub, $order_id > 0 ? $order_id : null, $sub->recurring_total, (string) ( $result['txn'] ?? '' ) );

		/**
		 * Fires after a subscription renewal charge is successfully processed.
		 *
		 * @param Subscription $sub     The renewed subscription.
		 * @param Renewal      $renewal The renewal record.
		 */
		do_action( 'wplm_subscription_renewed', $sub, $renewal );
	}

	/**
	 * Apply a confirmed renewal payment: extend the licence by one period (LicenseService::
	 * extend_term()), align next_payment with the new paid-through date, clear dunning, bring an
	 * on-hold subscription back to active, and record the renewal.
	 *
	 * Shared by the cron card charge and paid renewal invoices, so both produce identical state.
	 *
	 * @param Subscription $sub      The subscription being paid.
	 * @param int|null     $order_id WooCommerce order that carried the payment, if any.
	 * @param float        $amount   Amount received.
	 * @param string       $txn      Gateway transaction reference.
	 * @return Renewal The recorded renewal.
	 */
	public function apply_payment( Subscription $sub, ?int $order_id, float $amount, string $txn = '' ): Renewal {
		$now      = time();
		$now_utc  = gmdate( 'Y-m-d H:i:s', $now );
		$interval = $this->scheduler->period_to_interval( $sub->billing_interval, $sub->billing_period );

		$next = null;
		if ( null !== $sub->license_id ) {
			$next = $this->license_service->extend_term( $sub->license_id, $interval, $now );
		}
		if ( null === $next ) {
			$next = $this->scheduler->next_payment( $sub, $now_utc );
		}

		$this->sub_repo->update(
			$sub->id,
			array(
				'last_payment'    => $now_utc,
				'next_payment'    => $next,
				'failed_attempts' => 0,
			)
		);

		if ( ! in_array( $sub->status, array( 'active', 'trial' ), true ) ) {
			$this->sub_service->update_status( $sub->id, 'active' );
		}

		$renewal_id = $this->renewal_repo->create(
			array(
				'subscription_id' => $sub->id,
				'order_id'        => $order_id,
				'type'            => 'renewal',
				'amount'          => $amount,
				'status'          => 'success',
				'gateway_txn'     => $txn,
				'scheduled_for'   => $sub->next_payment ?? $now_utc,
				'processed_at'    => $now_utc,
				'created_at'      => $now_utc,
			)
		);

		$renewal                  = new Renewal();
		$renewal->id              = (int) $renewal_id;
		$renewal->subscription_id = $sub->id;
		$renewal->order_id        = $order_id;
		$renewal->amount          = $amount;
		$renewal->status          = 'success';

		return $renewal;
	}
}
