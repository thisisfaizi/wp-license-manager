<?php
/**
 * Storefront + checkout integration for plan-assigned WooCommerce products.
 *
 * @package WPLM\Integrations\WooCommerce
 */

namespace WPLM\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WPLM\Licensing\ProfileRegistry;
use WPLM\Models\Package;
use WPLM\Services\EntitlementService;
use WPLM\Services\GeneratorService;
use WPLM\Services\LicenseService;
use WPLM\Services\PlanService;
use WPLM\Services\Subscriptions\SubscriptionService;

/**
 * Drives the full lifecycle for any native WooCommerce product that has a WPLM
 * subscription plan assigned (_wplm_plan_id):
 *
 *   1. Renders the plan's packages as selectable options on the product page.
 *   2. Validates a package is chosen and stores it in the cart item.
 *   3. Overrides the cart line price to the chosen package's price (+ sign-up fee).
 *   4. Persists the package on the order line item.
 *   5. On payment, creates the subscription (recurring) and/or issues the license
 *      using the package's billing + licensing rules.
 *
 * Products stay 100% native WooCommerce — no custom product types — so none of
 * the WC variable-product internals are involved.
 */
class PlanCheckout {

	/** @var PlanService */
	private PlanService $plans;

	/** @var GeneratorService */
	private GeneratorService $generators;

	/** @var LicenseService */
	private LicenseService $licenses;

	/** @var SubscriptionService */
	private SubscriptionService $subscriptions;

	/** @var EntitlementService */
	private EntitlementService $entitlements;

	/** @var ProfileRegistry */
	private ProfileRegistry $profiles;

	/** @var array<int,\WPLM\Models\Plan|null> Per-request plan cache. */
	private array $plan_cache = array();

	/**
	 * @param PlanService         $plans
	 * @param GeneratorService    $generators
	 * @param LicenseService      $licenses
	 * @param SubscriptionService $subscriptions
	 * @param EntitlementService  $entitlements
	 * @param ProfileRegistry     $profiles
	 */
	public function __construct(
		PlanService $plans,
		GeneratorService $generators,
		LicenseService $licenses,
		SubscriptionService $subscriptions,
		EntitlementService $entitlements,
		ProfileRegistry $profiles
	) {
		$this->plans         = $plans;
		$this->generators    = $generators;
		$this->licenses      = $licenses;
		$this->subscriptions = $subscriptions;
		$this->entitlements  = $entitlements;
		$this->profiles      = $profiles;
	}

	/** Register all storefront + checkout hooks. */
	public function register(): void {
		// Storefront: package selector on the product page.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_package_options' ) );

		// Price display + purchasability driven by the plan's packages, not the
		// (now redundant) WooCommerce product price.
		add_filter( 'woocommerce_get_price_html', array( $this, 'plan_price_html' ), 20, 2 );
		add_filter( 'woocommerce_is_purchasable', array( $this, 'plan_is_purchasable' ), 20, 2 );

		// Cart capture + pricing + display.
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'restore_cart_item' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_package_price' ), 20, 1 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );

		// Persist the package on the order line item.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_line_item' ), 10, 4 );

		// Fulfilment on payment.
		add_action( 'woocommerce_order_status_completed', array( $this, 'fulfill_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'fulfill_order' ), 10, 1 );
	}

	// -------------------------------------------------------------------------
	// Storefront
	// -------------------------------------------------------------------------

	/** Render the package radio options on the single-product page. */
	public function render_package_options(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$plan = $this->plan_for_product( $product );
		if ( null === $plan ) {
			return;
		}

		$packages = array_filter( $plan->packages, static fn( Package $p ) => 1 === (int) $p->status );
		if ( empty( $packages ) ) {
			return;
		}

		echo '<div class="wplm-packages" style="margin:16px 0;">';
		echo '<p style="font-weight:600;margin-bottom:8px;">' . esc_html__( 'Choose a license type', 'wp-license-manager' ) . '</p>';

		$first = true;
		foreach ( $packages as $pkg ) {
			$input_id = 'wplm-pkg-' . (int) $pkg->id;
			echo '<label for="' . esc_attr( $input_id ) . '" style="display:block;border:1px solid #dcdcde;border-radius:6px;padding:12px;margin-bottom:8px;cursor:pointer;">';
			printf(
				'<input type="radio" id="%1$s" name="wplm_package_id" value="%2$d" %3$s style="margin-right:8px;"> <strong>%4$s</strong> — %5$s',
				esc_attr( $input_id ),
				(int) $pkg->id,
				checked( $first, true, false ),
				esc_html( $pkg->name ),
				wp_kses_post( $this->price_label( $pkg ) )
			);

			if ( ! empty( $pkg->benefits ) ) {
				echo '<ul style="margin:8px 0 0 28px;">';
				foreach ( $pkg->benefits as $benefit ) {
					echo '<li>' . esc_html( $benefit ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</label>';
			$first = false;
		}
		echo '</div>';
	}

	/**
	 * Human-readable price + billing label for a package.
	 *
	 * @param Package $pkg Package.
	 * @return string
	 */
	private function price_label( Package $pkg ): string {
		$price = function_exists( 'wc_price' ) ? wc_price( $pkg->price ) : number_format_i18n( $pkg->price, 2 );

		if ( ! $pkg->is_recurring() ) {
			$suffix = Package::TYPE_LIFETIME === $pkg->billing_type
				? __( 'one-time, lifetime', 'wp-license-manager' )
				: __( 'one-time', 'wp-license-manager' );
			return $price . ' <small>(' . esc_html( $suffix ) . ')</small>';
		}

		$every = $pkg->billing_interval > 1
			? sprintf(
				/* translators: 1: interval number, 2: period */
				__( 'every %1$d %2$s', 'wp-license-manager' ),
				$pkg->billing_interval,
				$pkg->billing_period
			)
			: sprintf(
				/* translators: %s: period */
				__( 'per %s', 'wp-license-manager' ),
				$pkg->billing_period
			);

		$out = $price . ' <small>' . esc_html( $every ) . '</small>';
		if ( $pkg->trial_days > 0 ) {
			/* translators: %d: trial days */
			$out .= ' <small>' . esc_html( sprintf( __( '· %d-day free trial', 'wp-license-manager' ), $pkg->trial_days ) ) . '</small>';
		}
		if ( $pkg->signup_fee > 0 ) {
			$fee  = function_exists( 'wc_price' ) ? wc_price( $pkg->signup_fee ) : (string) $pkg->signup_fee;
			$out .= ' <small>· ' . wp_kses_post( $fee ) . ' ' . esc_html__( 'sign-up fee', 'wp-license-manager' ) . '</small>';
		}
		return $out;
	}

	/**
	 * Replace the product price display with "From {min package price}" for
	 * plan products (the per-package prices are shown in the selector below).
	 *
	 * @param string      $html    Existing price HTML.
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function plan_price_html( $html, $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return $html;
		}
		$packages = $this->active_packages( $product );
		if ( empty( $packages ) ) {
			return $html;
		}

		$min   = min( array_map( static fn( Package $p ) => (float) $p->price, $packages ) );
		$price = function_exists( 'wc_price' ) ? wc_price( $min ) : (string) $min;

		return '<span class="wplm-from-price">' . esc_html__( 'From', 'wp-license-manager' ) . ' ' . $price . '</span>';
	}

	/**
	 * Make plan products purchasable even when no WooCommerce price is set —
	 * the package defines the price.
	 *
	 * @param bool        $purchasable Current state.
	 * @param \WC_Product $product     Product.
	 * @return bool
	 */
	public function plan_is_purchasable( $purchasable, $product ) {
		if ( $product instanceof \WC_Product && ! empty( $this->active_packages( $product ) ) ) {
			return true;
		}
		return $purchasable;
	}

	/**
	 * Active packages for a product's assigned plan (empty when none).
	 *
	 * @param \WC_Product $product Product.
	 * @return Package[]
	 */
	private function active_packages( \WC_Product $product ): array {
		$plan = $this->plan_for_product( $product );
		if ( null === $plan ) {
			return array();
		}
		return array_values( array_filter( $plan->packages, static fn( Package $p ) => 1 === (int) $p->status ) );
	}

	// -------------------------------------------------------------------------
	// Cart
	// -------------------------------------------------------------------------

	/**
	 * Require a valid package selection for plan products.
	 *
	 * @param bool $passed     Current validation state.
	 * @param int  $product_id Product being added.
	 * @return bool
	 */
	public function validate_add_to_cart( $passed, $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return $passed;
		}
		$plan = $this->plan_for_product( $product );
		if ( null === $plan ) {
			return $passed;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC add-to-cart form, validated by WC.
		$package_id = isset( $_POST['wplm_package_id'] ) ? absint( wp_unslash( $_POST['wplm_package_id'] ) ) : 0;
		if ( ! $package_id || null === $this->find_package( $plan, $package_id ) ) {
			wc_add_notice( __( 'Please choose a license type before adding to cart.', 'wp-license-manager' ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Store the chosen package id in the cart item data.
	 *
	 * @param array $data       Cart item data.
	 * @param int   $product_id Product id.
	 * @return array
	 */
	public function add_cart_item_data( $data, $product_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC add-to-cart form, validated by WC.
		$package_id = isset( $_POST['wplm_package_id'] ) ? absint( wp_unslash( $_POST['wplm_package_id'] ) ) : 0;
		if ( $package_id ) {
			$data['wplm_package_id'] = $package_id;
			// Ensure each package selection is a distinct cart line.
			$data['wplm_unique'] = $package_id . '-' . microtime();
		}
		return $data;
	}

	/**
	 * Restore the package id when the cart is loaded from the session.
	 *
	 * @param array $item    Cart item.
	 * @param array $session Session values.
	 * @return array
	 */
	public function restore_cart_item( $item, $session ) {
		if ( isset( $session['wplm_package_id'] ) ) {
			$item['wplm_package_id'] = $session['wplm_package_id'];
		}
		return $item;
	}

	/**
	 * Override the cart line price with the chosen package's first-payment amount.
	 *
	 * @param \WC_Cart $cart Cart instance.
	 * @return void
	 */
	public function apply_package_price( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['wplm_package_id'] ) || empty( $cart_item['data'] ) ) {
				continue;
			}
			$pkg = $this->lookup_package( (int) $cart_item['wplm_package_id'] );
			if ( null === $pkg ) {
				continue;
			}
			$cart_item['data']->set_price( $this->first_payment_amount( $pkg ) );
		}
	}

	/**
	 * Show the chosen package in cart/checkout line item meta.
	 *
	 * @param array $items     Existing item data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function display_cart_item_data( $items, $cart_item ) {
		if ( ! empty( $cart_item['wplm_package_id'] ) ) {
			$pkg = $this->lookup_package( (int) $cart_item['wplm_package_id'] );
			if ( $pkg ) {
				$items[] = array(
					'key'   => __( 'License Type', 'wp-license-manager' ),
					'value' => wp_strip_all_tags( $pkg->name . ' — ' . $this->price_label( $pkg ) ),
				);
			}
		}
		return $items;
	}

	/**
	 * Persist the package id (+ plan) on the order line item.
	 *
	 * @param \WC_Order_Item_Product $item          Order line item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array                  $values        Cart item values.
	 * @param \WC_Order              $order         Order.
	 * @return void
	 */
	public function save_order_line_item( $item, $cart_item_key, $values, $order ): void {
		if ( ! empty( $values['wplm_package_id'] ) ) {
			$item->add_meta_data( '_wplm_package_id', (int) $values['wplm_package_id'], true );
			$pkg = $this->lookup_package( (int) $values['wplm_package_id'] );
			if ( $pkg ) {
				$item->add_meta_data( '_wplm_plan_id', (int) $pkg->plan_id, true );
				$item->add_meta_data( __( 'License Type', 'wp-license-manager' ), $pkg->name, true );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Fulfilment
	// -------------------------------------------------------------------------

	/**
	 * Create subscriptions / issue licenses for every package line item.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function fulfill_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( '1' === $order->get_meta( '_wplm_plan_fulfilled' ) ) {
			return;
		}

		$issued = array();
		$failed = 0;

		/** @var \WC_Order_Item_Product $item */
		foreach ( $order->get_items() as $item ) {
			$package_id = (int) $item->get_meta( '_wplm_package_id' );
			if ( ! $package_id ) {
				continue;
			}
			// Already issued on an earlier attempt: never issue a second licence.
			if ( '' !== (string) $item->get_meta( '_wplm_license_ids' ) ) {
				continue;
			}
			$pkg = $this->lookup_package( $package_id );
			if ( null === $pkg ) {
				++$failed;
				$order->add_order_note(
					/* translators: %d: package id */
					sprintf( __( 'WPLM: a licence could not be issued — package %d no longer exists.', 'wp-license-manager' ), $package_id )
				);
				continue;
			}

			try {
				$license = $this->fulfill_item( $order, $item, $pkg );
				if ( $license ) {
					$issued[] = $license;
					$item->update_meta_data( '_wplm_license_ids', wp_json_encode( array( $license->id ) ) );
					$item->save_meta_data();
				}
			} catch ( \Throwable $e ) {
				++$failed;
				\WPLM\Support\Logger::error( 'PlanCheckout: fulfilment failed for package ' . $package_id . ' — ' . $e->getMessage() );
				$order->add_order_note(
					sprintf(
						/* translators: 1: package name, 2: error message */
						__( 'WPLM: a licence could not be issued for "%1$s" — %2$s. Fix the cause, then set the order to Processing or Completed again to retry.', 'wp-license-manager' ),
						$pkg->name,
						$e->getMessage()
					)
				);
			}
		}

		// Mark done only when every package line was issued, so a failure stays retryable
		// instead of silently leaving a paying customer without a licence (audit F10).
		if ( 0 === $failed ) {
			$order->update_meta_data( '_wplm_plan_fulfilled', '1' );
		}
		$order->save();

		if ( ! empty( $issued ) ) {
			do_action( 'wplm_order_licenses_issued', $order_id, $issued );
		}
	}

	/**
	 * Fulfil a single package line item: create the subscription (recurring) and
	 * issue the bound license using the package's rules.
	 *
	 * @param \WC_Order              $order Order.
	 * @param \WC_Order_Item_Product $item  Line item.
	 * @param Package                $pkg   Chosen package.
	 * @return \WPLM\Models\License|null
	 */
	private function fulfill_item( \WC_Order $order, $item, Package $pkg ) {
		$generator_id = $pkg->generator_id ?: (int) get_option( 'wplm_default_generator_id', 0 );
		if ( $generator_id <= 0 ) {
			throw new \RuntimeException( 'no key generator is configured (WPLM → Generators)' );
		}
		// A plan that sells a licence profile (e.g. Super Ledger) issues an entitlement licence. Check
		// its template before anything is created, so a bad template leaves nothing half-issued.
		$plan         = $this->plans->get( $pkg->plan_id );
		$profile_code = null !== $plan ? $plan->profile : null;
		if ( null !== $profile_code ) {
			$profile = $this->profiles->get( $profile_code );
			if ( null === $profile ) {
				throw new \RuntimeException( sprintf( 'the plan sells an unknown licence profile "%s"', $profile_code ) );
			}
			$this->entitlements->normalize_template( $profile, $pkg->entitlements );
		}

		$keys = $this->generators->generate_batch( $generator_id, 1 );
		if ( empty( $keys ) ) {
			throw new \RuntimeException( 'No key generated for package ' . $pkg->id );
		}

		// All subscription/licence dates are UTC; current_time( 'mysql' ) is site-local (audit F7).
		$now     = gmdate( 'Y-m-d H:i:s' );
		$max_act = $pkg->max_activations;
		$sub_id  = 0;
		$expires = null;
		$status  = 'active';

		if ( $pkg->is_recurring() ) {
			$trial_end    = $pkg->trial_days > 0 ? $this->add_period( $now, $pkg->trial_days, 'day' ) : null;
			$next_payment = $trial_end ?: $this->add_period( $now, $pkg->billing_interval, $pkg->billing_period );
			$end_date     = null;
			if ( $pkg->length_cycles > 0 ) {
				$end = $now;
				for ( $i = 0; $i < $pkg->length_cycles; $i++ ) {
					$end = $this->add_period( $end, $pkg->billing_interval, $pkg->billing_period );
				}
				$end_date = $end;
			}

			$token_id = null;
			if ( class_exists( '\WC_Payment_Tokens' ) ) {
				$token    = \WC_Payment_Tokens::get_customer_default_token( $order->get_customer_id() );
				$token_id = $token ? $token->get_id() : null;
			}

			$sub    = $this->subscriptions->create(
				array(
					'user_id'          => $order->get_customer_id(),
					'billing_interval' => $pkg->billing_interval,
					'billing_period'   => $pkg->billing_period,
					'recurring_total'  => $pkg->price,
					'currency'         => $order->get_currency(),
					'signup_fee'       => $pkg->signup_fee,
					'trial_end'        => $trial_end,
					'next_payment'     => $next_payment,
					'parent_order_id'  => $order->get_id(),
					'payment_method'   => $order->get_payment_method(),
					'payment_token_id' => $token_id,
					'end_date'         => $end_date,
					'items'            => array(
						array(
							'product_id'   => $item->get_product_id(),
							'variation_id' => $item->get_variation_id() ?: null,
							'quantity'     => (int) $item->get_quantity(),
							'line_total'   => $pkg->price,
							'meta'         => array(
								'package_id'    => $pkg->id,
								'order_item_id' => $item->get_id(),
							),
						),
					),
				)
			);
			$sub_id = $sub->id;
			$status = ( null !== $trial_end ) ? 'trial' : 'active';
			$item->update_meta_data( '_wplm_subscription_id', $sub_id );
			$item->save_meta_data();

			// The licence covers exactly what has been paid for (or the trial). Renewals extend
			// it; an unpaid renewal lets it lapse after grace. Previously a recurring licence was
			// perpetual and only a subscription status change could end it (audit F1/F2).
			$expires = $next_payment;
		} elseif ( Package::TYPE_ONETIME === $pkg->billing_type && $pkg->valid_for_days ) {
			$expires = $this->add_period( $now, $pkg->valid_for_days, 'day' );
		}
		// Lifetime: $expires stays null (perpetual).

		$license = $this->licenses->create(
			array(
				'key_string'       => $keys[0],
				'product_id'       => $item->get_product_id(),
				'order_id'         => $order->get_id(),
				'user_id'          => $order->get_customer_id(),
				'max_activations'  => $max_act,
				'overage_strategy' => $pkg->overage_strategy,
				'expires_at'       => $expires,
				'grace_days'       => $pkg->effective_grace_days(),
				'source'           => 3,
				'profile'          => $profile_code,
			)
		);

		// The entitlement licence itself never expires (LicenseRepository drops the expiry); its lines
		// are paid through the last day of the paid term, or lifetime.
		if ( null !== $profile_code ) {
			$this->entitlements->grant_package(
				$license,
				$pkg,
				$sub_id > 0 ? $sub_id : null,
				null !== $expires ? EntitlementService::paid_through_for( $expires ) : null
			);
		}

		if ( $sub_id > 0 ) {
			$this->subscriptions->bind_license( $sub_id, $license->id );
			$this->subscriptions->update_status( $sub_id, $status );
		}

		return $license;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve the plan assigned to a product (parent for variations).
	 *
	 * @param \WC_Product $product Product.
	 * @return \WPLM\Models\Plan|null
	 */
	private function plan_for_product( \WC_Product $product ) {
		$plan_id = (int) $product->get_meta( '_wplm_plan_id' );
		if ( ! $plan_id && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent instanceof \WC_Product ) {
				$plan_id = (int) $parent->get_meta( '_wplm_plan_id' );
			}
		}
		if ( ! $plan_id ) {
			return null;
		}

		// Per-request memo: the price filter fires for every product on archives.
		if ( ! isset( $this->plan_cache[ $plan_id ] ) ) {
			$this->plan_cache[ $plan_id ] = $this->plans->get( $plan_id, true );
		}
		return $this->plan_cache[ $plan_id ];
	}

	/**
	 * Find a package within a plan by id.
	 *
	 * @param \WPLM\Models\Plan $plan       Plan.
	 * @param int               $package_id Package id.
	 * @return Package|null
	 */
	private function find_package( $plan, int $package_id ) {
		foreach ( $plan->packages as $pkg ) {
			if ( (int) $pkg->id === $package_id ) {
				return $pkg;
			}
		}
		return null;
	}

	/**
	 * Look up a package by id directly (loads its plan for context).
	 *
	 * @param int $package_id Package id.
	 * @return Package|null
	 */
	private function lookup_package( int $package_id ) {
		try {
			$repo = \WPLM\Plugin::get_instance()->container()->make( \WPLM\Repositories\PackageRepository::class );
			return $repo->find_by_id( $package_id );
		} catch ( \Throwable $e ) {
			unset( $e );
			return null;
		}
	}

	/**
	 * First-order amount: sign-up fee + (trial ? 0 : price). Lifetime/one-time
	 * charge the full price.
	 *
	 * @param Package $pkg Package.
	 * @return float
	 */
	private function first_payment_amount( Package $pkg ): float {
		$amount = $pkg->signup_fee;
		if ( $pkg->is_recurring() && $pkg->trial_days > 0 ) {
			return $amount; // Trial: only the sign-up fee now.
		}
		return $amount + $pkg->price;
	}

	/**
	 * Add a period to a UTC datetime string.
	 *
	 * @param string $from   Base datetime (Y-m-d H:i:s).
	 * @param int    $n      Number of units.
	 * @param string $period day|week|month|year.
	 * @return string
	 */
	private function add_period( string $from, int $n, string $period ): string {
		$spec = array(
			'day'   => "P{$n}D",
			'week'  => "P{$n}W",
			'month' => "P{$n}M",
			'year'  => "P{$n}Y",
		);
		$dt   = new \DateTime( $from, new \DateTimeZone( 'UTC' ) );
		$dt->add( new \DateInterval( $spec[ $period ] ?? "P{$n}M" ) );
		return $dt->format( 'Y-m-d H:i:s' );
	}
}
