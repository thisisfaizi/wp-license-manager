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
 * Each subscription whose next_payment ≤ NOW() and status ∈ {active, trial}
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

	/**
	 * @param SubscriptionRepository $sub_repo        Subscription data-access layer.
	 * @param RenewalRepository      $renewal_repo    Renewal data-access layer.
	 * @param GatewayBridge          $gateway         Payment gateway abstraction.
	 * @param DunningManager         $dunning         Failed-payment retry handler.
	 * @param LicenseService         $license_service License lifecycle service.
	 * @param BillingScheduler       $scheduler       Next-payment date computation.
	 */
	public function __construct(
		SubscriptionRepository $sub_repo,
		RenewalRepository $renewal_repo,
		GatewayBridge $gateway,
		DunningManager $dunning,
		LicenseService $license_service,
		BillingScheduler $scheduler
	) {
		$this->sub_repo        = $sub_repo;
		$this->renewal_repo    = $renewal_repo;
		$this->gateway         = $gateway;
		$this->dunning         = $dunning;
		$this->license_service = $license_service;
		$this->scheduler       = $scheduler;
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
	 *   1. No payment token → send manual renewal invoice and return early.
	 *   2. Token present → charge via GatewayBridge.
	 *   3. Success → record renewal, advance next_payment, update last_payment,
	 *      extend license expiry, fire wplm_subscription_renewed.
	 *   4. Failure → hand off to DunningManager.
	 *
	 * @param Subscription $sub Subscription to process.
	 * @return void
	 */
	public function process_single( Subscription $sub ): void {
		// No token stored — fall back to manual invoice flow.
		if ( null === $sub->payment_token_id ) {
			/**
			 * Fires when a subscription has no stored payment token and a manual
			 * renewal invoice should be emailed to the customer.
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

		// (a) Create a stub WooCommerce renewal order (extensible via filter).
		/**
		 * Filter: create a WooCommerce renewal order for this subscription charge.
		 *
		 * Third-party code (e.g. the WooCommerce integration) should hook here
		 * to create an actual WC_Order and return its integer id.
		 *
		 * @param int          $order_id        Default 0 (no WC order created).
		 * @param Subscription $sub             The subscription being renewed.
		 */
		$order_id = (int) apply_filters( 'wplm_create_renewal_order', 0, $sub );

		// (b) Write the renewal record.
		$renewal_id = $this->renewal_repo->create(
			array(
				'subscription_id' => $sub->id,
				'order_id'        => $order_id > 0 ? $order_id : null,
				'type'            => 'renewal',
				'amount'          => $sub->recurring_total,
				'status'          => 'success',
				'gateway_txn'     => $result['txn'] ?? '',
				'scheduled_for'   => $sub->next_payment ?? current_time( 'mysql' ),
				'processed_at'    => current_time( 'mysql' ),
				'created_at'      => current_time( 'mysql' ),
			)
		);

		// Load the renewal model for the fired action.
		$renewal_rows = $this->renewal_repo->get_by_subscription( $sub->id );
		$renewal      = null;
		foreach ( $renewal_rows as $row ) {
			if ( $row->id === $renewal_id ) {
				$renewal = $row;
				break;
			}
		}
		// Fallback: construct a minimal Renewal object if we can't find it.
		if ( null === $renewal ) {
			$renewal                  = new Renewal();
			$renewal->id              = $renewal_id;
			$renewal->subscription_id = $sub->id;
			$renewal->amount          = $sub->recurring_total;
			$renewal->status          = 'success';
		}

		// (c) Advance the next_payment date.
		$next = $this->scheduler->next_payment( $sub, current_time( 'mysql' ) );

		// (d) Update subscription record.
		$this->sub_repo->update(
			$sub->id,
			array(
				'last_payment'    => current_time( 'mysql' ),
				'next_payment'    => $next,
				'failed_attempts' => 0,
			)
		);

		// (e) Extend the license expiry by one billing period.
		if ( null !== $sub->license_id ) {
			$this->extend_license_expiry( $sub );
		}

		// (f) Fire the renewed action.
		/**
		 * Fires after a subscription renewal charge is successfully processed.
		 *
		 * @param Subscription $sub     The renewed subscription.
		 * @param Renewal      $renewal The renewal record.
		 */
		do_action( 'wplm_subscription_renewed', $sub, $renewal );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Extend the bound license expiry by one billing period.
	 *
	 * Computes the new expiry by advancing the current expires_at (or now if
	 * the license is perpetual) by one billing interval/period and then calls
	 * LicenseService to persist the change.
	 *
	 * @param Subscription $sub Subscription with a non-null license_id.
	 * @return void
	 */
	private function extend_license_expiry( Subscription $sub ): void {
		$license = $this->license_service->get_by_id( $sub->license_id );

		if ( null === $license ) {
			return;
		}

		// Base: current expiry or now (for perpetual licenses that we're now
		// converting to a timed cycle).
		$base = $license->expires_at ?? current_time( 'mysql' );

		try {
			$dt = new \DateTime( $base, new \DateTimeZone( 'UTC' ) );
			$dt->add(
				new \DateInterval(
					$this->scheduler->period_to_interval( $sub->billing_interval, $sub->billing_period )
				)
			);
			$new_expiry = $dt->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			// Non-fatal; log and skip the expiry extension.
			Logger::error(
				sprintf(
					'WPLM RenewalProcessor: could not extend license %d expiry — %s',
					$sub->license_id,
					$e->getMessage()
				)
			);
			return;
		}

		// Persist via LicenseService so any hooks on change_status etc. fire.
		$this->license_service->update( $sub->license_id, array( 'expires_at' => $new_expiry ) );
	}
}
