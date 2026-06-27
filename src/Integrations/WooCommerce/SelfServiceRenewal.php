<?php
/**
 * Self-service renewal — customer clicks "Renew now", pays via WooCommerce,
 * and the existing license expiry is extended when the order reaches `completed`.
 *
 * The same license key stays: only expires_at moves forward.
 *
 * @package WPLM\Integrations\WooCommerce
 */

namespace WPLM\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WPLM\Models\Renewal;
use WPLM\Models\Subscription;
use WPLM\Repositories\RenewalRepository;
use WPLM\Repositories\SubscriptionRepository;
use WPLM\Services\LicenseService;
use WPLM\Services\Subscriptions\BillingScheduler;

class SelfServiceRenewal {

	private LicenseService        $license_service;
	private SubscriptionRepository $sub_repo;
	private RenewalRepository     $renewal_repo;
	private BillingScheduler      $scheduler;

	public function __construct(
		LicenseService $license_service,
		SubscriptionRepository $sub_repo,
		RenewalRepository $renewal_repo,
		BillingScheduler $scheduler
	) {
		$this->license_service = $license_service;
		$this->sub_repo        = $sub_repo;
		$this->renewal_repo    = $renewal_repo;
		$this->scheduler       = $scheduler;
	}

	public function register(): void {
		// Online gateways call payment_complete(), which normally lands the order
		// at 'processing' (because line items aren't downloadable). Force renewal
		// orders to 'completed' so our handler fires. Offline methods (BACS/COD)
		// never call payment_complete() with a paid status — they stay on-hold
		// until an admin confirms, which is the desired anti-fraud gate.
		add_filter( 'woocommerce_payment_complete_order_status', array( $this, 'filter_payment_complete_status' ), 10, 3 );

		// Extend the license only on payment confirmed (admin or gateway).
		// Priority 20 runs after PlanCheckout (priority 10) so it never races
		// with the new-license fulfillment path.
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_completed_order' ), 20, 1 );
	}

	/**
	 * Push renewal orders directly to 'completed' when a gateway calls
	 * payment_complete(). Leaves all other orders unchanged.
	 *
	 * @param string    $status   Default post-payment status (usually 'processing').
	 * @param int       $order_id WooCommerce order ID.
	 * @param \WC_Order $order    Order object.
	 * @return string
	 */
	public function filter_payment_complete_status( string $status, int $order_id, \WC_Order $order ): string {
		if ( '1' === $order->get_meta( '_wplm_is_renewal' ) ) {
			return 'completed';
		}
		return $status;
	}

	/**
	 * Create a pending WooCommerce renewal order for the given subscription.
	 *
	 * Returns the WC pay-for-order URL so the caller can redirect the customer
	 * straight to the payment page. The same license key is reused — no new
	 * license is issued here; expiry is extended only on order completed.
	 *
	 * @param int $sub_id Subscription row ID.
	 * @return string|false Pay URL on success, false on failure.
	 */
	public function create_renewal_order( int $sub_id ): string|false {
		$sub = $this->sub_repo->find_by_id( $sub_id );
		if ( ! $sub || $sub->user_id <= 0 ) {
			return false;
		}

		// Let third-party code (e.g. a gateway module) build the WC order.
		// If the filter returns a positive order id, attach our renewal meta and
		// return its pay URL without building the order from scratch.
		$external_id = (int) apply_filters( 'wplm_create_renewal_order', 0, $sub );
		if ( $external_id > 0 ) {
			$ext_order = wc_get_order( $external_id );
			if ( $ext_order instanceof \WC_Order ) {
				$ext_order->update_meta_data( '_wplm_is_renewal', '1' );
				$ext_order->update_meta_data( '_wplm_subscription_id', (string) $sub_id );
				$ext_order->update_meta_data( '_wplm_renewal_term_days', (string) $this->term_days( $sub ) );
				$ext_order->save();
				return $ext_order->get_checkout_payment_url();
			}
		}

		// Build the order manually.
		$order = wc_create_order(
			array(
				'customer_id' => $sub->user_id,
				'status'      => 'pending',
			)
		);

		if ( is_wp_error( $order ) ) {
			error_log( sprintf(
				'WPLM SelfServiceRenewal: wc_create_order failed for subscription %d — %s',
				$sub_id,
				$order->get_error_message()
			) );
			return false;
		}

		// Add the plan product as the line item.
		// Deliberately NOT copying _wplm_package_id onto this line item —
		// PlanCheckout::fulfill_order() would issue a brand-new license if it saw
		// a package id. Renewal orders must NOT re-fulfill.
		$product_id = $this->resolve_product_id( $sub );
		$item_added = false;

		if ( $product_id > 0 ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof \WC_Product ) {
				$order->add_product(
					$product,
					1,
					array(
						'subtotal' => $sub->recurring_total,
						'total'    => $sub->recurring_total,
					)
				);
				$item_added = true;
			}
		}

		// Fallback to a fee line when no product can be resolved.
		if ( ! $item_added ) {
			$fee = new \WC_Order_Item_Fee();
			$fee->set_name( __( 'License Renewal', 'wp-license-manager' ) );
			$fee->set_total( $sub->recurring_total );
			$order->add_item( $fee );
		}

		// Prefill billing details from the customer's stored WooCommerce profile.
		if ( $sub->user_id > 0 ) {
			$customer = new \WC_Customer( $sub->user_id );
			$order->set_billing_first_name( $customer->get_billing_first_name() );
			$order->set_billing_last_name( $customer->get_billing_last_name() );
			$email = $customer->get_billing_email() ?: $customer->get_email();
			$order->set_billing_email( $email );
			$order->set_billing_address_1( $customer->get_billing_address_1() );
			$order->set_billing_city( $customer->get_billing_city() );
			$order->set_billing_country( $customer->get_billing_country() );
			$order->set_billing_phone( $customer->get_billing_phone() );
		}

		$order->set_currency( $sub->currency ?: get_woocommerce_currency() );
		$order->update_meta_data( '_wplm_is_renewal', '1' );
		$order->update_meta_data( '_wplm_subscription_id', (string) $sub_id );
		$order->update_meta_data( '_wplm_renewal_term_days', (string) $this->term_days( $sub ) );
		$order->calculate_totals();
		$order->save();

		return $order->get_checkout_payment_url();
	}

	/**
	 * Extend the subscription's license when the renewal WC order is completed.
	 *
	 * This is the ONLY gate: money confirmed (admin-confirmed or gateway
	 * payment_complete) → expiry extends. Never fires on order creation, pending,
	 * on-hold, or processing.
	 *
	 * Idempotency: if the order is somehow completed twice, the guard meta
	 * `_wplm_renewal_applied` prevents double-extension.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function handle_completed_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Not a self-service renewal.
		if ( '1' !== $order->get_meta( '_wplm_is_renewal' ) ) {
			return;
		}

		// Idempotency guard.
		if ( $order->get_meta( '_wplm_renewal_applied' ) ) {
			return;
		}

		$sub_id = (int) $order->get_meta( '_wplm_subscription_id' );
		if ( $sub_id <= 0 ) {
			return;
		}

		$sub = $this->sub_repo->find_by_id( $sub_id );
		if ( null === $sub || null === $sub->license_id ) {
			error_log( sprintf(
				'WPLM SelfServiceRenewal: subscription %d not found or has no license (order %d).',
				$sub_id,
				$order_id
			) );
			return;
		}

		$license = $this->license_service->get_by_id( $sub->license_id );
		if ( null === $license ) {
			error_log( sprintf(
				'WPLM SelfServiceRenewal: license %d not found for subscription %d (order %d).',
				$sub->license_id,
				$sub_id,
				$order_id
			) );
			return;
		}

		// Compute the new expiry in UTC (matches BillingScheduler's storage convention).
		// If the license is not yet expired, extend from its current expiry so
		// early renewals stack. If already expired, extend from today so the
		// customer gets a full term rather than a partial one.
		$now_utc = gmdate( 'Y-m-d H:i:s' );
		$base    = ( null !== $license->expires_at && $license->expires_at > $now_utc )
			? $license->expires_at
			: $now_utc;

		try {
			$interval   = $this->scheduler->period_to_interval( $sub->billing_interval, $sub->billing_period );
			$dt         = new \DateTime( $base, new \DateTimeZone( 'UTC' ) );
			$dt->add( new \DateInterval( $interval ) );
			$new_expiry = $dt->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			error_log( sprintf(
				'WPLM SelfServiceRenewal: could not compute new expiry for license %d — %s',
				$license->id,
				$e->getMessage()
			) );
			return;
		}

		// Extend the license. renew() sets status=1 (active) and re-signs the
		// offline payload — correct for both in-grace and already-expired licenses.
		$this->license_service->renew( (string) $license->license_key, $new_expiry );

		// Advance subscription billing dates and reactivate if needed.
		$next = $this->scheduler->next_payment( $sub, $now_utc );
		$this->sub_repo->update(
			$sub->id,
			array(
				'last_payment'    => $now_utc,
				'next_payment'    => $next,
				'failed_attempts' => 0,
				'status'          => 'active',
			)
		);

		// Write the renewal record, mirroring RenewalProcessor so cron and
		// self-service produce identical records.
		$renewal_id = $this->renewal_repo->create(
			array(
				'subscription_id' => $sub->id,
				'order_id'        => $order_id,
				'type'            => 'renewal',
				'amount'          => (float) $order->get_total(),
				'status'          => 'success',
				'gateway_txn'     => '',
				'scheduled_for'   => $sub->next_payment ?? $now_utc,
				'processed_at'    => $now_utc,
				'created_at'      => $now_utc,
			)
		);

		// Stamp the order so this handler is a no-op on any subsequent
		// completed transitions (e.g. WC admin re-saves status).
		$order->update_meta_data( '_wplm_renewal_applied', '1' );
		$order->save();

		// Build a minimal Renewal model for the action hook (mirrors the
		// fallback pattern in RenewalProcessor for when the DB row lookup fails).
		$renewal                  = new Renewal();
		$renewal->id              = (int) $renewal_id;
		$renewal->subscription_id = $sub->id;
		$renewal->order_id        = $order_id;
		$renewal->amount          = (float) $order->get_total();
		$renewal->status          = 'success';

		/**
		 * Fires after self-service renewal is applied. Same signature as the cron
		 * path so all listeners (mail, Karobar credits, LiteLLM) work without change.
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
	 * Find the WooCommerce product ID for the subscription so the renewal order
	 * carries a recognisable line item in the WC order screen.
	 *
	 * Tries the parent order first (the item that originally spawned this
	 * subscription), then falls back to the product stored on the bound license.
	 *
	 * @param Subscription $sub Subscription model.
	 * @return int Product ID, or 0 when not resolvable.
	 */
	private function resolve_product_id( Subscription $sub ): int {
		if ( isset( $sub->parent_order_id ) && $sub->parent_order_id > 0 ) {
			$parent = wc_get_order( $sub->parent_order_id );
			if ( $parent instanceof \WC_Order ) {
				foreach ( $parent->get_items() as $item ) {
					// Prefer the item that spawned this specific subscription.
					if ( (int) $item->get_meta( '_wplm_subscription_id' ) === $sub->id ) {
						return (int) $item->get_product_id();
					}
				}
				// Fallback: first product in the parent order.
				foreach ( $parent->get_items() as $item ) {
					$pid = (int) $item->get_product_id();
					if ( $pid > 0 ) {
						return $pid;
					}
				}
			}
		}

		// Try the product_id stored on the bound license.
		if ( $sub->license_id > 0 ) {
			$license = $this->license_service->get_by_id( $sub->license_id );
			if ( $license && isset( $license->product_id ) && $license->product_id > 0 ) {
				return (int) $license->product_id;
			}
		}

		return 0;
	}

	/**
	 * Approximate the subscription's billing term in days.
	 * Stored as order meta for informational display; expiry computation uses
	 * DateInterval directly so rounding here doesn't affect correctness.
	 *
	 * @param Subscription $sub Subscription model.
	 * @return int
	 */
	private function term_days( Subscription $sub ): int {
		$map = array( 'day' => 1, 'week' => 7, 'month' => 30, 'year' => 365 );
		return (int) $sub->billing_interval * ( $map[ $sub->billing_period ] ?? 30 );
	}
}
