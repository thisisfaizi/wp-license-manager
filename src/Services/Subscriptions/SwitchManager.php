<?php
/**
 * Switch manager — plan upgrade/downgrade with proration for the native
 * subscription engine.
 *
 * @package WPLM\Services\Subscriptions
 */

namespace WPLM\Services\Subscriptions;

use WPLM\Models\Subscription;
use WPLM\Repositories\RenewalRepository;
use WPLM\Repositories\SubscriptionRepository;
use WPLM\Services\LicenseService;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plan switching (upgrade and downgrade) for active subscriptions.
 *
 * Proration logic:
 *   credit  = unused days / total days in current period × old recurring total
 *   charge  = new recurring total for one full period
 *   net     = max(0, charge − credit)
 *
 * The proration array is passed through the `wplm_switch_proration` filter so
 * merchants can override or disable it. The switch is recorded as a
 * wplm_subscription_renewals row of type 'switch'.
 */
class SwitchManager {

	/** @var SubscriptionRepository */
	private SubscriptionRepository $sub_repo;

	/** @var RenewalRepository */
	private RenewalRepository $renewal_repo;

	/** @var BillingScheduler */
	private BillingScheduler $scheduler;

	/** @var LicenseService */
	private LicenseService $license_service;

	/**
	 * @param SubscriptionRepository $sub_repo        Subscription data-access layer.
	 * @param RenewalRepository      $renewal_repo    Renewal data-access layer.
	 * @param BillingScheduler       $scheduler       Billing schedule helper.
	 * @param LicenseService         $license_service License lifecycle service.
	 */
	public function __construct(
		SubscriptionRepository $sub_repo,
		RenewalRepository $renewal_repo,
		BillingScheduler $scheduler,
		LicenseService $license_service
	) {
		$this->sub_repo        = $sub_repo;
		$this->renewal_repo    = $renewal_repo;
		$this->scheduler       = $scheduler;
		$this->license_service = $license_service;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Switch a subscription to a new billing plan.
	 *
	 * @param int   $subscription_id WPLM subscription id.
	 * @param array $new_plan        {
	 *     @type int    $product_id       WooCommerce product id.
	 *     @type int    $variation_id     Variation id (0 if not applicable).
	 *     @type float  $recurring_total  New recurring charge per cycle.
	 *     @type int    $billing_interval New billing interval.
	 *     @type string $billing_period   New billing period (day|week|month|year).
	 *     @type int    $max_activations  New seat limit for the bound license.
	 * }
	 * @return Subscription|\WP_Error Updated subscription on success, WP_Error on failure.
	 */
	public function switch_plan( int $subscription_id, array $new_plan ) {
		$sub = $this->sub_repo->find_by_id( $subscription_id );

		if ( null === $sub ) {
			return new \WP_Error(
				'subscription_not_found',
				sprintf(
					/* translators: %d: subscription ID */
					__( 'Subscription %d not found.', 'wp-license-manager' ),
					$subscription_id
				)
			);
		}

		if ( ! in_array( $sub->status, array( 'active', 'on-hold' ), true ) ) {
			return new \WP_Error(
				'invalid_subscription_status',
				sprintf(
					/* translators: 1: subscription ID, 2: current status */
					__( 'Subscription %1$d cannot be switched (current status: %2$s).', 'wp-license-manager' ),
					$subscription_id,
					$sub->status
				)
			);
		}

		// Capture the old plan for the fired action.
		$old_plan = array(
			'product_id'       => null, // not stored on the subscription directly
			'recurring_total'  => $sub->recurring_total,
			'billing_interval' => $sub->billing_interval,
			'billing_period'   => $sub->billing_period,
		);

		// Compute proration and allow override via filter.
		$calculated_proration = $this->calculate_proration( $sub, $new_plan );

		/**
		 * Filter the proration amounts for a plan switch.
		 *
		 * @param array        $proration {
		 *     @type float $credit Credit for unused portion of current period.
		 *     @type float $charge Full charge for one period on the new plan.
		 *     @type float $net    Net amount due (max 0, charge − credit).
		 * }
		 * @param Subscription $sub      The subscription being switched.
		 * @param array        $new_plan The incoming plan details.
		 */
		$proration = (array) apply_filters( 'wplm_switch_proration', $calculated_proration, $sub, $new_plan );

		// Record the switch as a renewal row of type 'switch'.
		$this->renewal_repo->create(
			array(
				'subscription_id' => $sub->id,
				'order_id'        => null,
				'type'            => 'switch',
				'amount'          => (float) ( $proration['net'] ?? 0.0 ),
				'status'          => 'success',
				'gateway_txn'     => null,
				'scheduled_for'   => current_time( 'mysql' ),
				'processed_at'    => current_time( 'mysql' ),
				'created_at'      => current_time( 'mysql' ),
			)
		);

		// Update the subscription with the new billing details.
		$update_data = array(
			'recurring_total'  => (float) ( $new_plan['recurring_total'] ?? $sub->recurring_total ),
			'billing_interval' => (int) ( $new_plan['billing_interval'] ?? $sub->billing_interval ),
			'billing_period'   => (string) ( $new_plan['billing_period'] ?? $sub->billing_period ),
		);
		$this->sub_repo->update( $sub->id, $update_data );

		// Immediately update the license seat limit if max_activations changed.
		if (
			null !== $sub->license_id &&
			isset( $new_plan['max_activations'] ) &&
			(int) $new_plan['max_activations'] > 0
		) {
			$this->license_service->update(
				$sub->license_id,
				array( 'max_activations' => (int) $new_plan['max_activations'] )
			);
		}

		// Load the updated subscription for the return value and fired action.
		$updated_sub = $this->sub_repo->find_by_id( $subscription_id );
		if ( null === $updated_sub ) {
			$updated_sub = $sub; // fallback — should not happen
		}

		/**
		 * Fires after a subscription plan switch has been completed.
		 *
		 * @param Subscription $updated_sub The updated subscription record.
		 * @param array        $old_plan    The previous billing plan details.
		 * @param array        $new_plan    The new billing plan details.
		 */
		do_action( 'wplm_subscription_switched', $updated_sub, $old_plan, $new_plan );

		return $updated_sub;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Calculate the proration amounts for switching from the current plan to
	 * the new plan.
	 *
	 * credit  = (unused days in current period / total days in period) × old recurring total
	 * charge  = new recurring total for a full billing period
	 * net     = max(0.0, charge − credit), rounded to 2 decimal places
	 *
	 * If the current period bounds cannot be determined (e.g. next_payment is
	 * null), the full new recurring total is charged with no credit.
	 *
	 * @param Subscription $sub      Current subscription record.
	 * @param array        $new_plan New plan details array.
	 * @return array { credit: float, charge: float, net: float }
	 */
	private function calculate_proration( Subscription $sub, array $new_plan ): array {
		$new_recurring = (float) ( $new_plan['recurring_total'] ?? 0.0 );
		$charge        = $new_recurring;
		$credit        = 0.0;

		// We can only prorate if we know when the current period ends.
		if ( null !== $sub->next_payment && null !== $sub->last_payment ) {
			$now   = new \DateTime( current_time( 'mysql' ), new \DateTimeZone( 'UTC' ) );
			$end   = new \DateTime( $sub->next_payment, new \DateTimeZone( 'UTC' ) );
			$start = new \DateTime( $sub->last_payment, new \DateTimeZone( 'UTC' ) );

			$total_days  = (int) $start->diff( $end )->days;
			$unused_days = (int) $now->diff( $end )->days;

			if ( $total_days > 0 && $unused_days > 0 ) {
				$credit = round( $sub->recurring_total * ( $unused_days / $total_days ), 2 );
			}
		}

		$net = round( max( 0.0, $charge - $credit ), 2 );

		return array(
			'credit' => $credit,
			'charge' => $charge,
			'net'    => $net,
		);
	}
}
