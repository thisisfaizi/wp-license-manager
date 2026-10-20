<?php
/**
 * Packages built at purchase (WPLM 1.2.7): a `dynamic` plan has no stored packages; a product add-on
 * builds the package from what the customer chose (`wplm_dynamic_package`), WPLM holds it to what a
 * package may be, prices the cart from it on every pass (never from the browser), keeps a snapshot on
 * the order line and issues the licence — and any subscription — from that snapshot.
 *
 * The add-on here is a fictional one for the test profile: 1000 a month per module (base included),
 * 100 a month per user; a year is ten months; lifetime is 30 months.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Licensing;

use WPLM\Admin\PlanAdminActions;
use WPLM\Integrations\WooCommerce\PlanCheckout;
use WPLM\Licensing\ProfileRegistry;
use WPLM\Models\Package;
use WPLM\Models\Plan;
use WPLM\Repositories\EntitlementRepository;
use WPLM\Repositories\LicenseRepository;
use WPLM\Repositories\SubscriptionRepository;
use WPLM\Services\AddonService;
use WPLM\Services\EntitlementService;
use WPLM\Services\GeneratorService;
use WPLM\Services\LicenseService;
use WPLM\Services\PlanService;
use WPLM\Services\Subscriptions\RenewalProcessor;
use WPLM\Services\Subscriptions\SubscriptionService;
use WPLM\Tests\TestCase;

class DynamicPackageTest extends TestCase {

	/** @var int */
	private int $plan_id = 0;

	/** @var \WC_Product_Simple */
	private \WC_Product_Simple $product;

	/** @var int Monthly price per module; changed by tests to simulate a new price list. */
	private int $module_price = 1000;

	public function set_up(): void {
		parent::set_up();
		$this->plan_id = $this->make( PlanService::class )->create_plan(
			array(
				'name'    => 'Acme — Build your own',
				'profile' => 'acme-office',
				'kind'    => Plan::KIND_DYNAMIC,
			)
		);
		$this->product = $this->create_plan_product( $this->plan_id );
		add_filter( 'wplm_dynamic_package', array( $this, 'build' ), 10, 4 );
		wc_load_cart();
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tear_down(): void {
		unset( $_POST['wplm_dynamic'] );
		if ( function_exists( 'WC' ) && null !== WC()->cart ) {
			WC()->cart->empty_cart();
		}
		wc_clear_notices();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// ---------------------------------------------------------------------------------------------
	// The fictional add-on
	// ---------------------------------------------------------------------------------------------

	/**
	 * `wplm_dynamic_package` for the test profile. The spec: modules (comma-separated), users,
	 * billing (month|year|lifetime). Any `price` in it is ignored, as a real add-on must.
	 */
	public function build( $package, $spec, $plan, $product ) {
		unset( $product );
		if ( null !== $package ) {
			return $package;
		}
		$modules = array_filter( array_map( 'trim', explode( ',', (string) ( $spec['modules'] ?? '' ) ) ) );
		if ( ! in_array( 'base', $modules, true ) ) {
			return new \WP_Error( 'acme_no_base', 'Choose a starter.' );
		}
		$users   = max( 0, (int) ( $spec['users'] ?? 0 ) );
		$billing = (string) ( $spec['billing'] ?? 'month' );
		$monthly = count( $modules ) * $this->module_price + $users * 100;

		$pkg          = new Package();
		$pkg->plan_id = $plan->id;
		$pkg->name    = 'Build your own — ' . implode( ' + ', $modules ) . ' — ' . $billing;
		if ( 'lifetime' === $billing ) {
			$pkg->billing_type = Package::TYPE_LIFETIME;
			$pkg->price        = $monthly * 30;
		} else {
			$pkg->billing_type   = Package::TYPE_RECURRING;
			$pkg->billing_period = 'year' === $billing ? 'year' : 'month';
			$pkg->price          = 'year' === $billing ? $monthly * 10 : $monthly;
		}
		$lines = array();
		foreach ( $modules as $code ) {
			$lines[] = array(
				'kind' => 'module',
				'code' => $code,
			);
		}
		if ( $users > 0 ) {
			$lines[] = array(
				'kind' => 'limit',
				'code' => 'users',
				'qty'  => $users,
			);
		}
		$pkg->entitlements = $lines;
		$pkg->benefits     = array( 'Built for you' );
		return $pkg;
	}

	// ---------------------------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------------------------

	private function checkout(): PlanCheckout {
		return new PlanCheckout(
			$this->make( PlanService::class ),
			$this->make( GeneratorService::class ),
			$this->make( LicenseService::class ),
			$this->make( SubscriptionService::class ),
			$this->make( EntitlementService::class ),
			$this->make( ProfileRegistry::class ),
			$this->make( AddonService::class )
		);
	}

	private function plan(): Plan {
		return $this->make( PlanService::class )->get( $this->plan_id );
	}

	/**
	 * Through the storefront as WooCommerce runs it: validate, add to cart, total, then the order line
	 * items made by WooCommerce's own checkout, then payment confirmed (on-hold → Completed).
	 *
	 * @return array{order:\WC_Order, item:\WC_Order_Item_Product, license_id:int, subscription_id:int}
	 */
	private function buy_through_cart( array $spec, int $customer, bool $complete = true ): array {
		wp_set_current_user( $customer );
		$_POST['wplm_dynamic'] = $spec;
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product->get_id(), 1 ), 'The choice is accepted.' );
		$key = WC()->cart->add_to_cart( $this->product->get_id(), 1 );
		$this->assertNotFalse( $key );
		unset( $_POST['wplm_dynamic'] );
		WC()->cart->calculate_totals();

		$order = new \WC_Order();
		$order->set_customer_id( $customer );
		$order->set_currency( get_woocommerce_currency() );
		WC()->checkout()->create_order_line_items( $order, WC()->cart );
		$order->set_payment_method( 'bacs' );
		$order->set_billing_email( 'customer' . $customer . '@example.org' );
		$order->calculate_totals();
		$order->save();
		WC()->cart->empty_cart();
		if ( $complete ) {
			$order->update_status( 'on-hold' );
			$order->update_status( 'completed' );
		}

		$order = wc_get_order( $order->get_id() );
		$items = array_values( $order->get_items() );
		$ids   = json_decode( (string) $items[0]->get_meta( '_wplm_license_ids' ), true );
		return array(
			'order'           => $order,
			'item'            => $items[0],
			'license_id'      => (int) ( $ids[0] ?? 0 ),
			'subscription_id' => (int) $items[0]->get_meta( '_wplm_subscription_id' ),
		);
	}

	/** @return array<string, \WPLM\Models\Entitlement> Lines by code. */
	private function lines_by_code( int $license_id ): array {
		$out = array();
		foreach ( $this->make( EntitlementRepository::class )->get_by_license( $license_id ) as $line ) {
			$out[ $line->code ] = $line;
		}
		return $out;
	}

	// ---------------------------------------------------------------------------------------------
	// The plan
	// ---------------------------------------------------------------------------------------------

	public function test_a_dynamic_plan_loads_as_dynamic_and_needs_a_licence_profile(): void {
		$this->assertTrue( $this->plan()->is_dynamic() );
		$this->assertSame( array(), $this->plan()->packages );

		$this->expectException( \InvalidArgumentException::class );
		$this->make( PlanService::class )->create_plan(
			array(
				'name' => 'Classic built',
				'kind' => Plan::KIND_DYNAMIC,
			)
		);
	}

	public function test_the_plan_editor_saves_the_kind_ignores_rows_and_still_reads_the_old_checkbox(): void {
		$admin  = new PlanAdminActions( $this->make( PlanService::class ), $this->make( ProfileRegistry::class ), $this->make( EntitlementService::class ) );
		$result = $admin->save_plan(
			array(
				'plan_name'    => 'Built',
				'plan_profile' => 'acme-office',
				'plan_status'  => '1',
				'plan_kind'    => 'dynamic',
				'packages'     => array(
					array(
						'name'         => 'Ignored',
						'billing_type' => 'lifetime',
						'price'        => '5',
					),
				),
			)
		);
		$this->assertTrue( $result['ok'], $result['message'] );
		$saved = $this->make( PlanService::class )->get( $result['plan_id'] );
		$this->assertSame( Plan::KIND_DYNAMIC, $saved->kind );
		$this->assertSame( array(), $saved->packages, 'A dynamic plan stores no licence types.' );
		$this->assertSame( array(), $result['warnings'], 'A builder is hooked in, so nothing to warn about.' );

		$classic = $admin->save_plan(
			array(
				'plan_name' => 'Built classic',
				'plan_kind' => 'dynamic',
			)
		);
		$this->assertFalse( $classic['ok'], 'A dynamic plan needs a licence type with modules and limits.' );

		$legacy = $admin->save_plan(
			array(
				'plan_name'    => 'Old editor',
				'plan_profile' => 'acme-office',
				'plan_addon'   => '1',
				'packages'     => array(
					array(
						'name'         => 'FBR lifetime',
						'billing_type' => 'lifetime',
						'price'        => '5',
						'entitlements' => array( 'modules' => array( 'fbr' ) ),
					),
				),
			)
		);
		$this->assertTrue( $legacy['ok'], $legacy['message'] );
		$this->assertSame( Plan::KIND_ADDON, $this->make( PlanService::class )->get( $legacy['plan_id'] )->kind, 'The pre-1.2.7 checkbox still makes an add-on plan.' );
	}

	public function test_the_product_licensing_tab_offers_the_plan(): void {
		if ( ! function_exists( 'woocommerce_wp_select' ) ) {
			require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
		}
		$post            = get_post( $this->product->get_id() );
		$GLOBALS['post'] = $post;
		ob_start();
		( new \WPLM\Integrations\WooCommerce\ProductPanel() )->render_license_panel();
		$html = ob_get_clean();
		unset( $GLOBALS['post'] );
		$this->assertMatchesRegularExpression( '/<option value="' . $this->plan_id . '"[^>]*>Acme — Build your own \(built at purchase\)</', $html );
	}

	// ---------------------------------------------------------------------------------------------
	// The product page
	// ---------------------------------------------------------------------------------------------

	public function test_the_product_page_shows_the_add_ons_form_and_price_and_is_purchasable(): void {
		$seen = array();
		add_action(
			'wplm_dynamic_package_form',
			static function ( $plan, $product ) use ( &$seen ): void {
				$seen[] = array( $plan->id, $product->get_id() );
				echo '<div class="acme-builder"></div>';
			},
			10,
			2
		);
		add_filter( 'wplm_dynamic_price_html', static fn( $html, $plan ) => 'From 1,000 (' . $plan->id . ')', 10, 2 );

		$GLOBALS['product'] = $this->product;
		ob_start();
		$this->checkout()->render_package_options();
		$html = ob_get_clean();
		unset( $GLOBALS['product'] );

		$this->assertSame( array( array( $this->plan_id, $this->product->get_id() ) ), $seen );
		$this->assertStringContainsString( 'acme-builder', $html );
		$this->assertStringNotContainsString( 'wplm_package_id', $html, 'No stored licence types are listed.' );
		$this->assertSame( 'From 1,000 (' . $this->plan_id . ')', $this->checkout()->plan_price_html( '<span>10</span>', $this->product ) );
		$this->assertTrue( $this->checkout()->plan_is_purchasable( false, $this->product ) );
		$this->assertTrue( $this->product->is_sold_individually(), 'One licence per line: no quantity box.' );
		$this->assertSame( 1, $this->checkout()->dynamic_quantity( 3, $this->product->get_id() ) );
	}

	// ---------------------------------------------------------------------------------------------
	// What the filter may return
	// ---------------------------------------------------------------------------------------------

	public function test_a_wp_error_from_the_filter_refuses_add_to_cart_with_its_message(): void {
		$_POST['wplm_dynamic'] = array( 'modules' => 'distribution' );
		$this->assertFalse( $this->checkout()->validate_add_to_cart( true, $this->product->get_id(), 1 ) );
		$notices = wc_get_notices( 'error' );
		$this->assertCount( 1, $notices );
		$this->assertStringContainsString( 'Choose a starter.', $notices[0]['notice'] );
	}

	public function test_a_package_wplm_cannot_sell_is_refused_whatever_the_filter_says(): void {
		$broken = array(
			'unknown module'    => static function ( Package $p ) {
				$p->entitlements[] = array(
					'kind' => 'module',
					'code' => 'teleport',
				);
			},
			'another plan'      => static function ( Package $p ) {
				$p->plan_id = $p->plan_id + 1000;
			},
			'one-time'          => static function ( Package $p ) {
				$p->billing_type   = Package::TYPE_ONETIME;
				$p->valid_for_days = 30;
			},
			'every 2 months'    => static function ( Package $p ) {
				$p->billing_interval = 2;
			},
			'weekly'            => static function ( Package $p ) {
				$p->billing_period = 'week';
			},
			'negative price'    => static function ( Package $p ) {
				$p->price = -1.0;
			},
			'not a number'      => static function ( Package $p ) {
				$p->price = NAN;
			},
			'no lines'          => static function ( Package $p ) {
				$p->entitlements = array();
			},
		);
		$plan = $this->plan();
		foreach ( $broken as $what => $break ) {
			$filter = static function ( $pkg ) use ( $break ) {
				if ( $pkg instanceof Package ) {
					$break( $pkg );
				}
				return $pkg;
			};
			add_filter( 'wplm_dynamic_package', $filter, 20 );
			$built = $this->checkout()->dynamic_package( array( 'modules' => 'base' ), $plan, $this->product );
			remove_filter( 'wplm_dynamic_package', $filter, 20 );
			$this->assertWPError( $built, "Refused: {$what}." );
			$this->assertSame( 'wplm_dynamic_unavailable', $built->get_error_code(), $what );
		}

		remove_filter( 'wplm_dynamic_package', array( $this, 'build' ), 10 );
		$this->assertWPError( $this->checkout()->dynamic_package( array( 'modules' => 'base' ), $plan, $this->product ), 'No builder: nothing to sell.' );
	}

	public function test_wplm_forces_what_a_built_package_may_not_choose(): void {
		add_filter(
			'wplm_dynamic_package',
			static function ( $pkg ) {
				if ( $pkg instanceof Package ) {
					$pkg->id            = 77;
					$pkg->signup_fee    = 500.0;
					$pkg->trial_days    = 14;
					$pkg->length_cycles = 3;
					$pkg->price         = 1234.5678;
				}
				return $pkg;
			},
			20
		);
		$built = $this->checkout()->dynamic_package( array( 'modules' => 'base' ), $this->plan(), $this->product );
		$this->assertInstanceOf( Package::class, $built );
		$this->assertSame( 0, $built->id );
		$this->assertSame( 0.0, $built->signup_fee );
		$this->assertSame( 0, $built->trial_days );
		$this->assertSame( 0, $built->length_cycles );
		$this->assertSame( 1, $built->max_activations, 'One computer per key unless the builder says otherwise.' );
		$this->assertSame( 1234.57, $built->price, 'Rounded to the shop\'s decimals.' );
	}

	// ---------------------------------------------------------------------------------------------
	// Cart and order line
	// ---------------------------------------------------------------------------------------------

	public function test_the_cart_charges_the_built_price_and_never_one_the_browser_sent(): void {
		$customer = $this->create_customer();
		wp_set_current_user( $customer );
		$_POST['wplm_dynamic'] = array(
			'modules' => 'base,distribution',
			'users'   => '2',
			'billing' => 'month',
			'price'   => '1',
			'total'   => '1',
		);
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product->get_id(), 1 ) );
		$key = WC()->cart->add_to_cart( $this->product->get_id(), 5, 0, array(), array( 'price' => 1 ) );
		unset( $_POST['wplm_dynamic'] );
		$this->assertNotFalse( $key );
		WC()->cart->calculate_totals();

		$line = WC()->cart->get_cart()[ $key ];
		$this->assertSame( 1, (int) $line['quantity'], 'One licence per line.' );
		$this->assertSame( 2200.0, (float) $line['data']->get_price(), '2 modules × 1000 + 2 users × 100.' );
		$this->assertSame( 2200.0, (float) WC()->cart->get_subtotal() );

		$shown = $this->checkout()->display_cart_item_data( array(), $line );
		$this->assertSame( 'License Type', $shown[0]['key'] );
		$this->assertStringContainsString( 'Build your own — base + distribution — month', $shown[0]['value'] );
	}

	public function test_checkout_stops_when_the_choice_can_no_longer_be_built(): void {
		$key = WC()->cart->add_to_cart( $this->product->get_id(), 1, 0, array(), array( 'wplm_dynamic_spec' => array( 'modules' => 'base' ) ) );
		$this->assertNotFalse( $key );
		$this->checkout()->check_cart_dynamic();
		$this->assertSame( 0, wc_notice_count( 'error' ) );

		add_filter( 'wplm_dynamic_package', static fn() => new \WP_Error( 'acme_gone', 'Base is no longer sold alone.' ), 20 );
		$this->checkout()->check_cart_dynamic();
		$this->assertSame( 1, wc_notice_count( 'error' ) );
		$this->assertStringContainsString( 'Base is no longer sold alone.', wc_get_notices( 'error' )[0]['notice'] );
	}

	public function test_the_order_line_keeps_a_snapshot_and_the_choice_but_no_package_id(): void {
		add_filter(
			'wplm_dynamic_package_snapshot',
			static function ( array $snapshot ) {
				$snapshot['price_list_version'] = 7;
				$snapshot['price']              = 1; // Cannot override the package's own fields.
				return $snapshot;
			}
		);
		$bought = $this->buy_through_cart(
			array(
				'modules' => 'base,pos',
				'billing' => 'month',
			),
			$this->create_customer(),
			false
		);
		$item = $bought['item'];

		$this->assertSame( '', (string) $item->get_meta( '_wplm_package_id' ) );
		$this->assertSame( $this->plan_id, (int) $item->get_meta( '_wplm_plan_id' ) );
		$this->assertSame(
			array(
				'modules' => 'base,pos',
				'billing' => 'month',
			),
			json_decode( (string) $item->get_meta( '_wplm_dynamic_spec' ), true )
		);
		$snapshot = json_decode( (string) $item->get_meta( '_wplm_dynamic_package' ), true );
		$this->assertSame( 7, $snapshot['price_list_version'] );
		$this->assertEquals( 2000, $snapshot['price'] );
		$this->assertSame( 'recurring', $snapshot['billing_type'] );
		$this->assertSame( 'Build your own — base + pos — month', $item->get_meta( 'License Type' ) );
		$this->assertSame( 2000.0, (float) $bought['order']->get_total() );
	}

	// ---------------------------------------------------------------------------------------------
	// Fulfilment
	// ---------------------------------------------------------------------------------------------

	public function test_a_monthly_package_issues_one_licence_and_a_subscription_at_its_price(): void {
		$bought = $this->buy_through_cart(
			array(
				'modules' => 'base,distribution',
				'users'   => '3',
				'billing' => 'month',
			),
			$this->create_customer()
		);
		$this->assertGreaterThan( 0, $bought['license_id'] );
		$this->assertGreaterThan( 0, $bought['subscription_id'] );

		$license = $this->make( LicenseRepository::class )->find_by_id( $bought['license_id'] );
		$this->assertSame( 'acme-office', $license->profile );
		$this->assertSame( 1, (int) $license->max_activations );

		$lines = $this->lines_by_code( $bought['license_id'] );
		$this->assertEqualsCanonicalizing( array( 'base', 'distribution', 'users' ), array_keys( $lines ) );
		$this->assertSame( 3, $lines['users']->qty );
		$this->assertNotNull( $lines['base']->paid_through );
		$this->assertSame( $bought['subscription_id'], $lines['base']->subscription_id );

		$sub = $this->subscription_row( $bought['subscription_id'] );
		$this->assertEquals( 2300, (float) $sub['recurring_total'] );
		$this->assertSame( 'month', $sub['billing_period'] );
		$items = $this->make( SubscriptionRepository::class )->get_items( $bought['subscription_id'] );
		$this->assertCount( 1, $items );
		$this->assertSame( 0, (int) $items[0]->meta['package_id'] );
		$this->assertSame( '3', $items[0]->meta['dynamic_spec']['users'] );
	}

	public function test_a_lifetime_package_writes_undated_lines_and_no_subscription(): void {
		$bought = $this->buy_through_cart(
			array(
				'modules' => 'base,factory',
				'billing' => 'lifetime',
			),
			$this->create_customer()
		);
		$this->assertGreaterThan( 0, $bought['license_id'] );
		$this->assertSame( 0, $bought['subscription_id'] );
		$this->assertSame( 60000.0, (float) $bought['order']->get_total() );
		foreach ( $this->lines_by_code( $bought['license_id'] ) as $line ) {
			$this->assertNull( $line->paid_through, "{$line->code} is for life." );
		}
	}

	public function test_completing_the_order_again_issues_nothing_more(): void {
		$customer = $this->create_customer();
		$bought   = $this->buy_through_cart(
			array(
				'modules' => 'base',
				'billing' => 'year',
			),
			$customer
		);
		$order = $bought['order'];
		$order->delete_meta_data( '_wplm_plan_fulfilled' );
		$order->save();
		$order->update_status( 'processing' );
		$order->update_status( 'completed' );

		$this->assertSame( 1, (int) $this->make( LicenseRepository::class )->get_list( array( 'user_id' => $customer ) )['total'] );
		$this->assertCount( 1, $this->make( SubscriptionRepository::class )->get_by_user( $customer ) );
	}

	public function test_a_renewal_extends_the_lines_at_the_price_paid(): void {
		$bought = $this->buy_through_cart(
			array(
				'modules' => 'base,pos',
				'billing' => 'month',
			),
			$this->create_customer()
		);
		$sub    = $this->make( SubscriptionRepository::class )->find_by_id( $bought['subscription_id'] );
		$before = $this->lines_by_code( $bought['license_id'] )['pos']->paid_through;

		$this->make( RenewalProcessor::class )->apply_payment( $sub, null, 2000.0 );

		$lines = $this->lines_by_code( $bought['license_id'] );
		$this->assertSame( EntitlementService::add_period( $before, 1, 'month' ), $lines['pos']->paid_through );
		$this->assertSame( $lines['base']->paid_through, $lines['pos']->paid_through );
	}

	public function test_a_refund_cancels_the_subscription_and_suspends_the_licence(): void {
		$bought = $this->buy_through_cart(
			array(
				'modules' => 'base',
				'billing' => 'month',
			),
			$this->create_customer()
		);
		$bought['order']->update_status( 'refunded' );

		$this->assertSame( 'cancelled', $this->subscription_row( $bought['subscription_id'] )['status'] );
		$this->assertSame( 'suspended', strtolower( $this->make( LicenseRepository::class )->find_by_id( $bought['license_id'] )->status_label() ) );
	}

	public function test_a_price_change_after_the_order_alters_neither_the_sale_nor_its_renewals(): void {
		$bought = $this->buy_through_cart(
			array(
				'modules' => 'base,distribution',
				'billing' => 'month',
			),
			$this->create_customer(),
			false
		);
		$this->module_price = 5000; // A new price list, after the order was placed but before it was confirmed.
		$order              = $bought['order'];
		$order->update_status( 'on-hold' );
		$order->update_status( 'completed' );

		$order = wc_get_order( $order->get_id() );
		$items = array_values( $order->get_items() );
		$sub   = (int) $items[0]->get_meta( '_wplm_subscription_id' );
		$this->assertEquals( 2000, (float) $this->subscription_row( $sub )['recurring_total'], 'The subscription renews at the price sold.' );
		$this->assertEquals( 2000, json_decode( (string) $items[0]->get_meta( '_wplm_dynamic_package' ), true )['price'] );
	}

	public function test_a_snapshot_for_another_plan_issues_nothing_and_says_why(): void {
		$bought = $this->buy_through_cart(
			array(
				'modules' => 'base',
				'billing' => 'lifetime',
			),
			$this->create_customer(),
			false
		);
		$item = $bought['item'];
		$item->update_meta_data( '_wplm_plan_id', $this->plan_id + 999 );
		$item->save_meta_data();
		$order = $bought['order'];
		$order->update_status( 'on-hold' );
		$order->update_status( 'completed' );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '', (string) array_values( $order->get_items() )[0]->get_meta( '_wplm_license_ids' ) );
		$this->assertNotSame( '1', $order->get_meta( '_wplm_plan_fulfilled' ), 'Left retryable.' );
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertNotEmpty( array_filter( $notes, static fn( $n ) => false !== strpos( $n, 'does not belong to its plan' ) ) );
	}
}
