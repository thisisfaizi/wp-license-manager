<?php
/**
 * WooCommerce order hooks for WPLM license and subscription issuance.
 *
 * @package WPLM\Integrations\WooCommerce
 */

namespace WPLM\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WPLM\Services\LicenseService;
use WPLM\Services\Subscriptions\SubscriptionService;

/**
 * Issues license keys for standard (non-plan) licensed products on order
 * completion, and revokes/suspends + cancels bound subscriptions on refund.
 *
 * Plan/package products (recurring subscriptions, lifetime, one-time) are
 * handled separately by {@see PlanCheckout}; items carrying a `_wplm_package_id`
 * are skipped here to avoid double processing.
 *
 * Delivery is idempotent: once `_wplm_keys_delivered` = '1' on the order,
 * subsequent status changes are ignored.
 */
class CheckoutHandler {

	/** @var LicenseService */
	private LicenseService $license_service;

	/** @var SubscriptionService */
	private SubscriptionService $subscription_service;

	/**
	 * @param LicenseService      $license_service
	 * @param SubscriptionService $subscription_service
	 */
	public function __construct(
		LicenseService $license_service,
		SubscriptionService $subscription_service
	) {
		$this->license_service      = $license_service;
		$this->subscription_service = $subscription_service;
	}

	/** Register WooCommerce order hooks. */
	public function register(): void {
		// Priority 5: WooCommerce sends the completed-order email on this same hook at 10, and that
		// email now carries the keys (see OrderEmailKeys), so they must exist before it renders.
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_completed' ), 5, 1 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'handle_order_refunded' ), 10, 1 );

		// Force account creation at checkout when the cart contains a licensed
		// or plan product, so every license is bound to a real account.
		add_filter( 'woocommerce_checkout_registration_required', array( $this, 'maybe_require_registration' ) );
		add_filter( 'woocommerce_checkout_registration_enabled', array( $this, 'maybe_enable_registration' ) );
	}

	/**
	 * Require registration at checkout when the cart holds a licensed product.
	 *
	 * @param bool $required Current "registration required" flag.
	 * @return bool
	 */
	public function maybe_require_registration( $required ) {
		return $this->cart_has_licensed_product() ? true : (bool) $required;
	}

	/**
	 * Ensure the registration form is enabled when needed (some themes/settings
	 * hide it for guests otherwise).
	 *
	 * @param bool $enabled Current "registration enabled on checkout" flag.
	 * @return bool
	 */
	public function maybe_enable_registration( $enabled ) {
		return $this->cart_has_licensed_product() ? true : (bool) $enabled;
	}

	/**
	 * Whether the current cart contains at least one licensed or plan product.
	 *
	 * @return bool
	 */
	private function cart_has_licensed_product(): bool {
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			// Resolve variations to their parent for the licensing meta.
			$config = $product;
			if ( $product->get_parent_id() ) {
				$parent = wc_get_product( $product->get_parent_id() );
				if ( $parent instanceof \WC_Product ) {
					$config = $parent;
				}
			}

			if ( 'yes' === $config->get_meta( '_wplm_is_licensed' )
				|| (int) $config->get_meta( '_wplm_plan_id' ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Order completed
	// -------------------------------------------------------------------------

	/**
	 * Issue license keys (and create subscription rows for subscription products)
	 * for every licensed item in the order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function handle_order_completed( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( '1' === $order->get_meta( '_wplm_keys_delivered' ) ) {
			return;
		}

		$licenses_all = array();
		$failed       = 0;

		/** @var \WC_Order_Item_Product $item */
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			// Plan/package-driven items are fulfilled by PlanCheckout — skip them
			// here so they are not processed twice.
			if ( $item->get_meta( '_wplm_package_id' ) ) {
				continue;
			}

			// Already issued on an earlier attempt: never issue a second key.
			if ( '' !== (string) $item->get_meta( '_wplm_license_ids' ) ) {
				continue;
			}

			// For a purchased variation, licensing configuration (is-licensed,
			// generator, seats, product type) lives on the parent product, while
			// per-variation billing lives on the variation itself.
			$config = $product;
			if ( $product->is_type( 'variation' ) ) {
				$parent = wc_get_product( $product->get_parent_id() );
				if ( $parent instanceof \WC_Product ) {
					$config = $parent;
				}
			}

			if ( 'yes' !== $config->get_meta( '_wplm_is_licensed' ) ) {
				continue;
			}

			$generator_id = (int) $config->get_meta( '_wplm_generator_id' );
			$quantity     = (int) $item->get_quantity();
			$single_key   = 'yes' === $config->get_meta( '_wplm_single_key_multi_seat' );

			$keys_to_issue   = $single_key ? 1 : $quantity;
			$max_activations = $single_key
				? $quantity
				: ( (int) $config->get_meta( '_wplm_max_activations' ) ?: 1 );

			$gen_service = \WPLM\Plugin::get_instance()->container()->make(
				\WPLM\Services\GeneratorService::class
			);

			// Standard one-time licensed product: issue keys immediately.
			// (Recurring subscriptions are handled by PlanCheckout via plans/packages.)
			$licenses = array();

			try {
				for ( $i = 0; $i < $keys_to_issue; $i++ ) {
					$key_strings = $gen_service->generate_batch( $generator_id, 1 );

					if ( empty( $key_strings ) ) {
						continue;
					}

					$license = $this->license_service->create(
						array(
							'key_string'      => $key_strings[0],
							'product_id'      => $item->get_product_id(),
							'order_id'        => $order_id,
							'user_id'         => $order->get_customer_id(),
							'max_activations' => $max_activations,
							'source'          => 3,
						)
					);

					$licenses[] = $license;
				}
			} catch ( \Throwable $e ) {
				\WPLM\Support\Logger::error(
					'CheckoutHandler: issuance failed for order ' . $order_id . ' — ' . $e->getMessage()
				);
			}

			// A licensed line that produced fewer keys than it owes leaves the order
			// retryable, so a paying customer is never left without a key (audit F10).
			if ( count( $licenses ) < $keys_to_issue ) {
				++$failed;
				$order->add_order_note(
					sprintf(
						/* translators: 1: product name, 2: keys issued, 3: keys owed */
						__( 'WPLM: only %2$d of %3$d keys could be issued for "%1$s". Fix the cause, then set the order to Completed again to retry.', 'wp-license-manager' ),
						$product->get_name(),
						count( $licenses ),
						$keys_to_issue
					)
				);
			}

			if ( ! empty( $licenses ) ) {
				$item->update_meta_data(
					'_wplm_license_ids',
					wp_json_encode( array_column( $licenses, 'id' ) )
				);
				$item->save_meta_data();
			}

			$licenses_all = array_merge( $licenses_all, $licenses );
		}

		if ( 0 === $failed ) {
			$order->update_meta_data( '_wplm_keys_delivered', '1' );
		}
		$order->save();

		if ( ! empty( $licenses_all ) ) {
			// The keys reach the customer in WooCommerce's completed-order email
			// (see OrderEmailKeys); this action is for everything else that reacts
			// to issuance, such as provisioning an office address.
			do_action( 'wplm_order_licenses_issued', $order_id, $licenses_all );
		}
	}

	// -------------------------------------------------------------------------
	// Order refunded
	// -------------------------------------------------------------------------

	/**
	 * Suspend or revoke licenses issued for a refunded order.
	 *
	 * @param int $order_id
	 */
	public function handle_order_refunded( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$action = get_option( 'wplm_refund_action', 'suspend' );

		/** @var \WC_Order_Item_Product $item */
		foreach ( $order->get_items() as $item ) {
			// Also cancel any subscription bound to this item.
			$sub_id = (int) $item->get_meta( '_wplm_subscription_id' );
			if ( $sub_id > 0 ) {
				try {
					$this->subscription_service->cancel( $sub_id, false );
				} catch ( \Exception $e ) {
					unset( $e ); // Subscription already cancelled or not found.
				}
			}

			$json = $item->get_meta( '_wplm_license_ids' );
			if ( empty( $json ) ) {
				continue;
			}

			$ids = json_decode( $json, true );
			if ( ! is_array( $ids ) ) {
				continue;
			}

			foreach ( $ids as $license_id ) {
				$license = $this->license_service->get_by_id( (int) $license_id );

				if ( null === $license ) {
					continue;
				}

				if ( 'revoke' === $action ) {
					wplm_revoke_license( $license->license_key );
				} else {
					wplm_suspend_license( $license->license_key );
				}
			}
		}
	}
}
