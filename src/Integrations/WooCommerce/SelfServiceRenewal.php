<?php
/**
 * Renewal orders — the WooCommerce order a customer pays to renew, whether they clicked
 * "Renew now" or were sent an invoice because a manual renewal fell due. The licence is
 * extended when the order reaches `completed` (a gateway payment, or the owner confirming a
 * bank transfer / JazzCash / Easypaisa payment).
 *
 * The same license key stays: only expires_at moves forward.
 *
 * @package WPLM\Integrations\WooCommerce
 */

namespace WPLM\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WPLM\Models\Subscription;
use WPLM\Repositories\RenewalRepository;
use WPLM\Repositories\SubscriptionRepository;
use WPLM\Services\LicenseService;
use WPLM\Services\Subscriptions\RenewalProcessor;

class SelfServiceRenewal {

	/** Renewal-order statuses that still stand for their cycle (not abandoned). */
	private const LIVE_STATUSES = array( 'pending', 'on-hold', 'processing', 'completed' );

	private LicenseService $license_service;
	private SubscriptionRepository $sub_repo;
	private RenewalProcessor $renewals;
	private RenewalRepository $renewal_repo;

	public function __construct(
		LicenseService $license_service,
		SubscriptionRepository $sub_repo,
		RenewalProcessor $renewals,
		RenewalRepository $renewal_repo
	) {
		$this->license_service = $license_service;
		$this->sub_repo        = $sub_repo;
		$this->renewals        = $renewals;
		$this->renewal_repo    = $renewal_repo;
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

		// A due manual renewal (or exhausted card retries): one emailed invoice per cycle.
		add_action( 'wplm_subscription_manual_renewal_due', array( $this, 'invoice_due_renewal' ), 10, 1 );
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
	 * Create a pending renewal order for the subscription and return its pay URL.
	 *
	 * @param int $sub_id Subscription row ID.
	 * @return string|false Pay URL on success, false on failure.
	 */
	public function create_renewal_order( int $sub_id ): string|false {
		// Reuse the unpaid invoice already issued for this cycle rather than opening a second
		// payable order for the same period.
		$sub = $this->sub_repo->find_by_id( $sub_id );
		if ( null !== $sub ) {
			$existing = $this->find_cycle_order( $sub->id, $this->cycle_key( $sub ) );
			if ( null !== $existing && $existing->needs_payment() ) {
				return $existing->get_checkout_payment_url();
			}
		}

		$order = $this->create_renewal_order_object( $sub_id );
		return $order ? $order->get_checkout_payment_url() : false;
	}

	/**
	 * Issue — once per billing cycle — a renewal invoice for a subscription whose manual
	 * renewal is due, and email it to the customer.
	 *
	 * The cycle is identified by the licence's paid-through date (or next_payment when there
	 * is no licence), which does not move while card retries run. Cron fires the due action
	 * every hour, so this must be idempotent.
	 *
	 * @param Subscription $sub The due subscription.
	 * @return void
	 */
	public function invoice_due_renewal( $sub ): void {
		if ( ! $sub instanceof Subscription || $sub->user_id <= 0 ) {
			return;
		}

		$cycle = $this->cycle_key( $sub );
		if ( null !== $this->find_cycle_order( $sub->id, $cycle ) ) {
			return;
		}

		$order = $this->create_renewal_order_object( $sub->id );
		if ( ! $order ) {
			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: paid-through date (UTC) */
				__( 'WPLM: renewal invoice for the period after %s (UTC), sent to the customer.', 'wp-license-manager' ),
				$cycle
			)
		);
		$order->save();

		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			$emails = WC()->mailer()->get_emails();
			if ( isset( $emails['WC_Email_Customer_Invoice'] ) ) {
				$emails['WC_Email_Customer_Invoice']->trigger( $order->get_id(), $order );
			}
		}

		/**
		 * Fires after a renewal invoice has been issued for a due manual renewal.
		 *
		 * @param \WC_Order    $order The pending renewal order.
		 * @param Subscription $sub   The subscription.
		 */
		do_action( 'wplm_subscription_renewal_invoiced', $order, $sub );
	}

	/**
	 * Build a pending WooCommerce renewal order for the subscription.
	 *
	 * @param int $sub_id Subscription row ID.
	 * @return \WC_Order|null
	 */
	public function create_renewal_order_object( int $sub_id ): ?\WC_Order {
		$sub = $this->sub_repo->find_by_id( $sub_id );
		if ( ! $sub || $sub->user_id <= 0 ) {
			return null;
		}

		// Let third-party code (e.g. a gateway module) build the WC order.
		$external_id = (int) apply_filters( 'wplm_create_renewal_order', 0, $sub );
		if ( $external_id > 0 ) {
			$ext_order = wc_get_order( $external_id );
			if ( $ext_order instanceof \WC_Order ) {
				$this->stamp_renewal( $ext_order, $sub );
				$ext_order->save();
				$this->record_invoice( $ext_order, $sub );
				return $ext_order;
			}
		}

		$order = wc_create_order(
			array(
				'customer_id' => $sub->user_id,
				'status'      => 'pending',
			)
		);

		if ( is_wp_error( $order ) ) {
			\WPLM\Support\Logger::error(
				sprintf( 'SelfServiceRenewal: wc_create_order failed for subscription %d — %s', $sub_id, $order->get_error_message() )
			);
			return null;
		}

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

		if ( ! $item_added ) {
			$fee = new \WC_Order_Item_Fee();
			$fee->set_name( __( 'License Renewal', 'wp-license-manager' ) );
			$fee->set_total( $sub->recurring_total );
			$order->add_item( $fee );
		}

		$customer = new \WC_Customer( $sub->user_id );
		$order->set_billing_first_name( $customer->get_billing_first_name() );
		$order->set_billing_last_name( $customer->get_billing_last_name() );
		$order->set_billing_email( $customer->get_billing_email() ?: $customer->get_email() );
		$order->set_billing_address_1( $customer->get_billing_address_1() );
		$order->set_billing_city( $customer->get_billing_city() );
		$order->set_billing_country( $customer->get_billing_country() );
		$order->set_billing_phone( $customer->get_billing_phone() );

		$order->set_currency( $sub->currency ?: get_woocommerce_currency() );
		$this->stamp_renewal( $order, $sub );
		$order->calculate_totals();
		$order->save();

		$this->record_invoice( $order, $sub );

		return $order;
	}

	/**
	 * Apply the payment when a renewal order is completed.
	 *
	 * This is the ONLY gate: money confirmed (admin-confirmed or gateway payment_complete) →
	 * the licence is extended through RenewalProcessor::apply_payment(), the same rule the cron
	 * card charge uses. Never fires on creation, pending, on-hold or processing.
	 *
	 * Idempotency: `_wplm_renewal_applied` prevents a second extension if the order is
	 * completed again.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function handle_completed_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( '1' !== $order->get_meta( '_wplm_is_renewal' ) || $order->get_meta( '_wplm_renewal_applied' ) ) {
			return;
		}

		$sub_id = (int) $order->get_meta( '_wplm_subscription_id' );
		$sub    = $sub_id > 0 ? $this->sub_repo->find_by_id( $sub_id ) : null;
		if ( null === $sub ) {
			\WPLM\Support\Logger::error( sprintf( 'SelfServiceRenewal: subscription %d not found (order %d).', $sub_id, $order_id ) );
			$order->add_order_note( __( 'WPLM: this renewal could not be applied — its subscription no longer exists.', 'wp-license-manager' ) );
			return;
		}

		// Stamp first: a completed status is re-entrant (admin re-saves), the extension is not.
		$order->update_meta_data( '_wplm_renewal_applied', '1' );
		$order->save();

		$renewal = $this->renewals->apply_payment( $sub, $order_id, (float) $order->get_total(), (string) $order->get_transaction_id() );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'wplm_subscription_renewals',
			array(
				'status'       => 'paid',
				'processed_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'order_id' => $order_id,
				'type'     => 'invoice',
			)
		);

		$license = null !== $sub->license_id ? $this->license_service->get_by_id( $sub->license_id ) : null;
		$order->add_order_note(
			null !== $license && null !== $license->expires_at
				/* translators: %s: new paid-through date (UTC) */
				? sprintf( __( 'WPLM: renewal applied — licence paid through %s (UTC).', 'wp-license-manager' ), $license->expires_at )
				: __( 'WPLM: renewal applied.', 'wp-license-manager' )
		);

		/**
		 * Fires after a paid renewal order is applied. Same signature as the cron path so all
		 * listeners (mail, Karobar credits, LiteLLM) work without change.
		 *
		 * @param Subscription            $sub     The renewed subscription.
		 * @param \WPLM\Models\Renewal    $renewal The renewal record.
		 */
		do_action( 'wplm_subscription_renewed', $sub, $renewal );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function stamp_renewal( \WC_Order $order, Subscription $sub ): void {
		$order->update_meta_data( '_wplm_is_renewal', '1' );
		$order->update_meta_data( '_wplm_subscription_id', (string) $sub->id );
		$order->update_meta_data( '_wplm_renewal_term_days', (string) $this->term_days( $sub ) );
		$order->update_meta_data( '_wplm_renewal_cycle', $this->cycle_key( $sub ) );
	}

	/** The paid-through date (UTC) that identifies the cycle being renewed. */
	private function cycle_key( Subscription $sub ): string {
		if ( null !== $sub->license_id ) {
			$license = $this->license_service->get_by_id( $sub->license_id );
			if ( null !== $license && null !== $license->expires_at ) {
				return (string) $license->expires_at;
			}
		}
		return (string) ( $sub->next_payment ?? gmdate( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Record the invoice in WPLM's own renewals table (type `invoice`, status `pending`, keyed by
	 * cycle). WooCommerce's meta queries only work on HPOS stores, so the per-cycle idempotency
	 * cannot rely on order meta lookups.
	 */
	private function record_invoice( \WC_Order $order, Subscription $sub ): void {
		$this->renewal_repo->create(
			array(
				'subscription_id' => $sub->id,
				'order_id'        => $order->get_id(),
				'type'            => 'invoice',
				'amount'          => (float) $order->get_total(),
				'status'          => 'pending',
				'scheduled_for'   => $this->cycle_key( $sub ),
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/** A live renewal order already issued for this cycle, if any. */
	private function find_cycle_order( int $sub_id, string $cycle ): ?\WC_Order {
		global $wpdb;
		$order_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT order_id FROM {$wpdb->prefix}wplm_subscription_renewals
				 WHERE subscription_id = %d AND type = 'invoice' AND scheduled_for = %s AND order_id IS NOT NULL
				 ORDER BY id DESC",
				$sub_id,
				$cycle
			)
		);
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( $order instanceof \WC_Order && in_array( $order->get_status(), self::LIVE_STATUSES, true ) ) {
				return $order;
			}
		}
		return null;
	}

	/**
	 * Find the WooCommerce product ID for the subscription so the renewal order carries a
	 * recognisable line item: the item that spawned it on the parent order, else the licence's
	 * product.
	 */
	private function resolve_product_id( Subscription $sub ): int {
		if ( isset( $sub->parent_order_id ) && $sub->parent_order_id > 0 ) {
			$parent = wc_get_order( $sub->parent_order_id );
			if ( $parent instanceof \WC_Order ) {
				foreach ( $parent->get_items() as $item ) {
					if ( (int) $item->get_meta( '_wplm_subscription_id' ) === $sub->id ) {
						return (int) $item->get_product_id();
					}
				}
				foreach ( $parent->get_items() as $item ) {
					$pid = (int) $item->get_product_id();
					if ( $pid > 0 ) {
						return $pid;
					}
				}
			}
		}

		if ( $sub->license_id > 0 ) {
			$license = $this->license_service->get_by_id( $sub->license_id );
			if ( $license && isset( $license->product_id ) && $license->product_id > 0 ) {
				return (int) $license->product_id;
			}
		}

		return 0;
	}

	/** Approximate billing term in days, stored as order meta for display only. */
	private function term_days( Subscription $sub ): int {
		$map = array(
			'day'   => 1,
			'week'  => 7,
			'month' => 30,
			'year'  => 365,
		);
		return (int) $sub->billing_interval * ( $map[ $sub->billing_period ] ?? 30 );
	}
}
