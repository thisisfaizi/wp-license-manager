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
 *
 * Add-ons (1.3.0): a subscription that also bills add-ons — stored add-ons or package extras, items
 * whose meta has `addon` — keeps billing them.
 *
 * - **`$new_plan['recurring_total']` changes meaning on such a subscription: it is the new plan's
 *   OWN price, without the add-ons** (on a subscription with no add-on items it is still the whole
 *   recurring charge, as before). Omitted, it is the current total minus the add-ons.
 * - The plan's item is set to that price, a "not itemised" adjustment is dropped, the add-on items are
 *   carried forward unchanged, and the recurring total is re-derived from the items (the action's
 *   `$new_plan` carries `addons_total`). The proration charge is the new plan plus the add-ons.
 * - An add-on the new plan already grants is not billed twice: pass `$new_plan['package_id']` (the
 *   licence type switched to) or `$new_plan['modules']` (the module codes it grants). An add-on item
 *   whose lines are all modules the new plan grants leaves the subscription, and its lines stay on the
 *   licence as plan lines (`source` plan, no order), renewed with the plan. Nothing else is written to
 *   the licence's lines: the switch never grants or removes modules itself.
 * - Add-ons are priced per billing period, so a switch that changes the period or interval while
 *   add-on items remain is refused (`wplm_switch_addons_billing`): remove them first, or re-sell them
 *   at the new period.
 * - A subscription with no add-on items switches exactly as before.
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

		// Add-ons keep being billed: carried forward, and the total re-derived from the items. One the
		// new plan grants itself leaves the billing (it is part of the plan now).
		$items    = $this->sub_repo->get_items( $sub->id );
		$addons   = array_values( array_filter( $items, static fn( $i ) => ! empty( $i->meta['addon'] ) ) );
		$itemised = array() !== $addons;
		$absorbed = $this->absorbed_items( $addons, $this->new_plan_modules( $new_plan ) );
		$addons   = array_values( array_filter( $addons, static fn( $i ) => ! in_array( $i->id, array_keys( $absorbed ), true ) ) );
		$extra    = round( array_sum( array_map( static fn( $i ) => (float) $i->line_total, $addons ) ), 4 );
		if ( $itemised ) {
			$interval = (int) ( $new_plan['billing_interval'] ?? $sub->billing_interval );
			$period   = (string) ( $new_plan['billing_period'] ?? $sub->billing_period );
			if ( array() !== $addons && ( $interval !== (int) $sub->billing_interval || $period !== $sub->billing_period ) ) {
				return new \WP_Error(
					'wplm_switch_addons_billing',
					__( 'This subscription also bills add-ons priced for its current billing period. Remove the add-ons before switching to another period.', 'wp-license-manager' )
				);
			}
			if ( ! isset( $new_plan['recurring_total'] ) ) {
				$all                         = round( array_sum( array_map( static fn( $i ) => (float) $i->line_total, array_filter( $items, static fn( $i ) => ! empty( $i->meta['addon'] ) ) ) ), 4 );
				$new_plan['recurring_total'] = max( 0.0, round( $sub->recurring_total - $all, 4 ) );
			}
			$new_plan['addons_total'] = $extra;
		}

		// Capture the old plan for the fired action.
		$old_plan = array(
			'product_id'       => null, // not stored on the subscription directly
			'recurring_total'  => $sub->recurring_total,
			'billing_interval' => $sub->billing_interval,
			'billing_period'   => $sub->billing_period,
		);

		// Compute proration and allow override via filter.
		$calculated_proration = $this->calculate_proration(
			$sub,
			array_merge( $new_plan, array( 'recurring_total' => (float) ( $new_plan['recurring_total'] ?? 0.0 ) + $extra ) )
		);

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
				'scheduled_for'   => current_time( 'mysql', true ),
				'processed_at'    => current_time( 'mysql', true ),
				'created_at'      => current_time( 'mysql', true ),
			)
		);

		// Update the subscription with the new billing details.
		$update_data = array(
			'recurring_total'  => (float) ( $new_plan['recurring_total'] ?? $sub->recurring_total ),
			'billing_interval' => (int) ( $new_plan['billing_interval'] ?? $sub->billing_interval ),
			'billing_period'   => (string) ( $new_plan['billing_period'] ?? $sub->billing_period ),
		);
		if ( $itemised ) {
			foreach ( $absorbed as $item_id => $line_ids ) {
				$this->sub_repo->delete_item( (int) $item_id );
				foreach ( $line_ids as $line_id ) {
					$this->entitlements()->update_line(
						(int) $line_id,
						array(
							'source'   => 'plan',
							'order_id' => null,
						)
					);
				}
			}
			$update_data['recurring_total'] = $this->rewrite_plan_items( $sub->id, $this->sub_repo->get_items( $sub->id ), $new_plan );
		}
		$this->sub_repo->update( $sub->id, $update_data );

		// Immediately update the license seat limit if max_activations changed.
		if (
			null !== $sub->license_id &&
			! $sub->is_addons_only() &&
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
	 * The module codes the plan switched to grants: `modules`, else the template of `package_id`.
	 *
	 * @param array $new_plan The new plan.
	 * @return string[]
	 */
	private function new_plan_modules( array $new_plan ): array {
		if ( isset( $new_plan['modules'] ) && is_array( $new_plan['modules'] ) ) {
			return array_values( array_map( 'strval', $new_plan['modules'] ) );
		}
		$package_id = (int) ( $new_plan['package_id'] ?? 0 );
		if ( $package_id <= 0 ) {
			return array();
		}
		$package = \WPLM\Plugin::get_instance()->container()->make( \WPLM\Repositories\PackageRepository::class )->find_by_id( $package_id );
		$codes   = array();
		foreach ( null !== $package ? $package->entitlements : array() as $line ) {
			if ( 'module' === ( $line['kind'] ?? '' ) ) {
				$codes[] = (string) $line['code'];
			}
		}
		return $codes;
	}

	/**
	 * The add-on items the new plan grants in full: every line they pay for is a module the plan grants.
	 *
	 * @param \WPLM\Models\SubscriptionItem[] $addons  Add-on items.
	 * @param string[]                         $modules Module codes the new plan grants.
	 * @return array<int, int[]> Item id => its line ids.
	 */
	private function absorbed_items( array $addons, array $modules ): array {
		if ( array() === $modules ) {
			return array();
		}
		$repo = \WPLM\Plugin::get_instance()->container()->make( \WPLM\Repositories\EntitlementRepository::class );
		$out  = array();
		foreach ( $addons as $item ) {
			$ids = array_map( 'intval', (array) ( $item->meta['entitlement_ids'] ?? array() ) );
			$all = array() !== $ids;
			foreach ( $ids as $id ) {
				$line = $repo->find_by_id( $id );
				if ( null === $line || 'module' !== $line->kind || ! in_array( $line->code, $modules, true ) ) {
					$all = false;
					break;
				}
			}
			if ( $all ) {
				$out[ $item->id ] = $ids;
			}
		}
		return $out;
	}

	/** The entitlement service (from the container: the constructor is unchanged). */
	private function entitlements(): \WPLM\Services\EntitlementService {
		return \WPLM\Plugin::get_instance()->container()->make( \WPLM\Services\EntitlementService::class );
	}

	/**
	 * Put the new plan's price on the plan's own item (the first item that is neither an add-on nor an
	 * adjustment; one is added when there is none), drop adjustments, keep every add-on item, and return
	 * the new recurring total: the sum of the items.
	 *
	 * @param int                                $subscription_id Subscription id.
	 * @param \WPLM\Models\SubscriptionItem[] $items           Its items before the switch.
	 * @param array                              $new_plan        The new plan (recurring_total = its own price).
	 * @return float
	 */
	private function rewrite_plan_items( int $subscription_id, array $items, array $new_plan ): float {
		$price = round( (float) $new_plan['recurring_total'], 4 );
		$base  = null;
		foreach ( $items as $item ) {
			if ( ! empty( $item->meta['addon'] ) ) {
				continue;
			}
			if ( null === $base && empty( $item->meta['adjustment'] ) ) {
				$base = $item;
				continue;
			}
			$this->sub_repo->delete_item( $item->id ); // An adjustment, or a second plan item.
		}
		if ( null !== $base ) {
			$this->sub_repo->update_item(
				$base->id,
				array(
					'line_total' => $price,
					'meta'       => array_merge( $base->meta, array( 'switched' => true ) ),
				)
			);
		} else {
			$this->sub_repo->add_item(
				$subscription_id,
				array(
					'product_id'   => (int) ( $new_plan['product_id'] ?? 0 ),
					'variation_id' => ! empty( $new_plan['variation_id'] ) ? (int) $new_plan['variation_id'] : null,
					'quantity'     => 1,
					'line_total'   => $price,
					'meta'         => array( 'switched' => true ),
				)
			);
		}
		$sum = 0.0;
		foreach ( $this->sub_repo->get_items( $subscription_id ) as $item ) {
			$sum += (float) $item->line_total;
		}
		return round( $sum, 4 );
	}

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
			$now   = new \DateTime( current_time( 'mysql', true ), new \DateTimeZone( 'UTC' ) );
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
