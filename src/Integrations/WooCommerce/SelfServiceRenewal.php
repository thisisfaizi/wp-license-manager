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

		// A full refund of a renewal order takes its period back; a partial one only says it does not.
		// Priority 10: before the add-ons' listeners (20), which then see the rolled-back dates.
		add_action( 'woocommerce_order_status_refunded', array( $this, 'handle_refunded_order' ), 10, 1 );
		add_action( 'woocommerce_order_partially_refunded', array( $this, 'handle_partial_refund' ), 10, 2 );
	}

	/**
	 * `woocommerce_order_status_refunded`: a renewal order refunded in full takes back the period it paid
	 * for ({@see RenewalProcessor::roll_back()}). Any other order is left alone.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function handle_refunded_order( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof \WC_Order ) {
			$this->renewals->roll_back( $order );
		}
	}

	/**
	 * `woocommerce_order_partially_refunded`: a renewal order keeps its period; the note says so
	 * ({@see RenewalProcessor::note_partial_refund()}).
	 *
	 * @param int $order_id  WooCommerce order ID.
	 * @param int $refund_id Refund ID.
	 * @return void
	 */
	public function handle_partial_refund( $order_id, $refund_id = 0 ): void {
		unset( $refund_id );
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof \WC_Order ) {
			$this->renewals->note_partial_refund( $order );
		}
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

		// An add-on already paid through the period this renewal buys is not invoiced this time
		// (RenewalProcessor::items_paid_ahead()); the order records it, so paying it extends the rest only.
		$skip   = $this->renewals->items_paid_ahead( $sub );
		$amount = $this->renewals->renewal_amount( $sub, $skip );

		// Let third-party code (e.g. a gateway module) build the WC order, billed at $amount. It leaves the
		// paid-ahead add-ons out only when it billed that amount.
		$external_id = (int) apply_filters( 'wplm_create_renewal_order', 0, $sub, $amount );
		if ( $external_id > 0 ) {
			$ext_order = wc_get_order( $external_id );
			if ( $ext_order instanceof \WC_Order ) {
				$this->stamp_renewal( $ext_order, $sub );
				if ( array() !== $skip && abs( (float) $ext_order->get_total() - $amount ) < 0.005 ) {
					$this->renewals->stamp_skipped( $ext_order, $skip );
				}
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
		// An add-ons-only subscription bills the add-ons of a lifetime licence: the invoice lists them,
		// never the product the licence was bought as (that was paid once, for life).
		$item_added = $sub->is_addons_only() && $this->add_addons_lines( $order, $sub, array_keys( $skip ) );
		$product_id = $item_added || $sub->is_addons_only() ? 0 : $this->resolve_product_id( $sub );

		if ( $product_id > 0 ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof \WC_Product ) {
				$order->add_product(
					$product,
					1,
					array(
						'subtotal' => $amount,
						'total'    => $amount,
					)
				);
				$item_added = true;
			}
		}

		if ( ! $item_added ) {
			$fee = new \WC_Order_Item_Fee();
			$fee->set_name( $sub->is_addons_only() ? \WPLM\Services\AddonService::label_for( $sub ) : __( 'License Renewal', 'wp-license-manager' ) );
			$fee->set_total( $amount );
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
		$this->renewals->stamp_skipped( $order, $skip );
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
		$renewed = $this->sub_repo->find_by_id( $sub->id );
		if ( null !== $license && null !== $license->expires_at ) {
			/* translators: %s: new paid-through date (UTC) */
			$note = sprintf( __( 'WPLM: renewal applied — licence paid through %s (UTC).', 'wp-license-manager' ), $license->expires_at );
		} elseif ( null !== $license && null !== $license->profile && null !== $renewed && null !== $renewed->next_payment ) {
			/* translators: %s: new inclusive paid-through date (site time zone) */
			$note = sprintf( __( 'WPLM: renewal applied — modules paid through %s. The customer unlocks at the next check-in.', 'wp-license-manager' ), \WPLM\Services\EntitlementService::paid_through_for( $renewed->next_payment ) );
		} else {
			$note = __( 'WPLM: renewal applied.', 'wp-license-manager' );
		}
		$order->add_order_note( $note );

		/**
		 * Fires after a paid renewal order is applied. Same signature as the cron path so all
		 * listeners (mail, webhooks, add-ons) work without change.
		 *
		 * @param Subscription            $sub     The renewed subscription.
		 * @param \WPLM\Models\Renewal    $renewal The renewal record.
		 */
		do_action( 'wplm_subscription_renewed', $sub, $renewal );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/** Mark a renewal order (shared with the card path: {@see RenewalProcessor::stamp_renewal_order()}). */
	private function stamp_renewal( \WC_Order $order, Subscription $sub ): void {
		$this->renewals->stamp_renewal_order( $order, $sub );
	}

	/** The paid-through date (UTC) that identifies the cycle being renewed ({@see RenewalProcessor::cycle_key()}). */
	private function cycle_key( Subscription $sub ): string {
		return $this->renewals->cycle_key( $sub );
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
	 * One fee line per add-on an add-ons-only subscription bills ("FBR — Add-ons for licence ending
	 * ABCD"), at what it bills. Nothing is added (false) when its items do not add up to its recurring
	 * total: the caller then bills the total on one line.
	 *
	 * Items this renewal does not charge for (already paid ahead) are left off.
	 *
	 * @param \WC_Order     $order The renewal order.
	 * @param Subscription $sub   The add-ons-only subscription.
	 * @param int[]        $skip  Items not charged this time.
	 * @return bool Whether the lines were added.
	 */
	private function add_addons_lines( \WC_Order $order, Subscription $sub, array $skip = array() ): bool {
		$items = $this->sub_repo->get_items( $sub->id );
		$sum   = 0.0;
		foreach ( $items as $sub_item ) {
			$sum += $sub_item->line_total;
		}
		if ( array() === $items || abs( round( $sum - (float) $sub->recurring_total, 4 ) ) >= 0.00005 ) {
			return false;
		}
		$for = \WPLM\Services\AddonService::label_for( $sub );
		foreach ( $items as $sub_item ) {
			if ( in_array( (int) $sub_item->id, array_map( 'intval', $skip ), true ) ) {
				continue;
			}
			$label = (string) ( $sub_item->meta['label'] ?? '' );
			if ( '' === $label ) {
				$label = __( 'Add-on', 'wp-license-manager' );
			}
			if ( $sub_item->quantity > 1 ) {
				/* translators: 1: quantity, 2: add-on name */
				$label = sprintf( __( '%1$d × %2$s', 'wp-license-manager' ), $sub_item->quantity, $label );
			}
			$fee = new \WC_Order_Item_Fee();
			/* translators: 1: add-on, 2: "Add-ons for licence ending ABCD" */
			$fee->set_name( '' !== $for ? sprintf( __( '%1$s — %2$s', 'wp-license-manager' ), $label, $for ) : $label );
			$fee->set_total( $sub_item->line_total );
			// Taxed as the product it was bought on would be (a fee is taxable by default).
			$product = $sub_item->product_id > 0 ? wc_get_product( $sub_item->product_id ) : null;
			$fee->set_tax_status( $product instanceof \WC_Product ? $product->get_tax_status() : 'none' );
			$fee->set_tax_class( $product instanceof \WC_Product ? $product->get_tax_class() : '' );
			$order->add_item( $fee );
		}
		return true;
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
}
