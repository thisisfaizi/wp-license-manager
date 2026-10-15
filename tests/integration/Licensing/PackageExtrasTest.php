<?php
/**
 * Package extras (WPLM 1.3.0): beside a licence type the customer ticks extras — modules and extra
 * quantities — priced by a product add-on through `wplm_package_extras`. One key, one bill: a
 * recurring licence gets one subscription billing the base and every extra (its renewal extends every
 * line), a lifetime one a single payment. The same offers price extras for a licence the customer
 * already has, through an add-on plan with no stored packages.
 *
 * The add-on here is a fictional one for the test profile. Monthly: Factory 1500, Subcontracting 800
 * (needs Factory or Point of Sale), FBR 1000, Distribution 2000 (held by every base package below),
 * a user 300 (up to 10), a phone 500 (a phone and a user, up to 5). Yearly is ten months; lifetime is
 * thirty months, and only an existing lifetime licence is offered a 365-day updates pack (a dated
 * Fixed Assets line here: the test profile has no updates module).
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Licensing;

use WPLM\Admin\LicenceAdminActions;
use WPLM\Integrations\WooCommerce\AddonSettlement;
use WPLM\Integrations\WooCommerce\PlanCheckout;
use WPLM\Integrations\WooCommerce\SelfServiceRenewal;
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
use WPLM\Services\PackageExtrasService;
use WPLM\Services\PlanService;
use WPLM\Services\Subscriptions\RenewalProcessor;
use WPLM\Services\Subscriptions\SubscriptionService;
use WPLM\Services\Subscriptions\SwitchManager;
use WPLM\Tests\TestCase;

class PackageExtrasTest extends TestCase {

	/** @var array{plan_id:int, packages:Package[]} */
	private array $plan;

	/** @var \WC_Product_Simple */
	private \WC_Product_Simple $product;

	/** @var array<int, array> Every context the filter was called with. */
	private array $calls = array();

	/** @var bool Whether the next add-on line written throws (an interrupted fulfilment). */
	private bool $break_next = false;

	public function set_up(): void {
		parent::set_up();
		$lines         = array(
			array( 'kind' => 'module', 'code' => 'base' ),
			array( 'kind' => 'module', 'code' => 'distribution' ),
			array(
				'kind' => 'limit',
				'code' => 'users',
				'qty'  => 3,
			),
		);
		$this->plan    = $this->create_plan(
			array(
				$this->recurring( 'Monthly', 'month', 5000, $lines ),
				$this->recurring( 'Yearly', 'year', 50000, $lines ),
				array(
					'name'         => 'Lifetime',
					'billing_type' => 'lifetime',
					'price'        => 100000,
					'entitlements' => $lines,
				),
				$this->recurring( 'Monthly with trial', 'month', 5000, $lines, array( 'trial_days' => 14 ) ),
				$this->recurring( 'Monthly with fee', 'month', 5000, $lines, array( 'signup_fee' => 2000 ) ),
				$this->recurring( 'Quarterly', 'month', 14000, $lines, array( 'billing_interval' => 3 ) ),
			),
			array(
				'name'    => 'Acme Office',
				'profile' => 'acme-office',
			)
		);
		$this->product = $this->create_plan_product( $this->plan['plan_id'] );
		add_filter( 'wplm_package_extras', array( $this, 'offers' ), 10, 2 );
		add_action( 'wplm_entitlement_saved', array( $this, 'maybe_break' ), 10, 2 );
		wc_load_cart();
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tear_down(): void {
		$this->make( ProfileRegistry::class )->get( 'acme-office' )->requires = array();
		unset( $_POST['wplm_extras'], $_POST['wplm_package_id'], $_POST['wplm_target_license_id'], $_POST['wplm_extras_total'], $_GET['wplm_license'] );
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

	/** `wplm_package_extras` for the test profile. */
	public function offers( $offers, $ctx ) {
		$this->calls[] = $ctx;
		$factor        = array(
			'month'    => 1,
			'year'     => 10,
			'lifetime' => 30,
		)[ $ctx['billing'] ];
		$offers[]      = $this->module_offer( 'factory', 'Factory', 1500 * $factor );
		$offers[]      = $this->module_offer( 'subcontract', 'Subcontracting', 800 * $factor );
		$offers[]      = $this->module_offer( 'distribution', 'Distribution', 2000 * $factor );
		if ( 'lifetime' === $ctx['billing'] ) {
			// No lifetime price: paid monthly or yearly on an add-ons subscription (v2).
			$offers[] = array(
				'id'         => 'module:fbr',
				'label'      => 'FBR',
				'group'      => 'module',
				'unit_price' => null,
				'recurring'  => array(
					'month' => 1000,
					'year'  => 10000,
				),
				'max_qty'    => 1,
				'lines'      => array( array( 'kind' => 'module', 'code' => 'fbr', 'qty' => 1 ) ),
				'requires'   => array( array( 'distribution', 'pos' ) ),
			);
			$offers[] = array(
				'id'         => 'module:pos',
				'label'      => 'Point of Sale',
				'group'      => 'module',
				'unit_price' => null,
				'recurring'  => array(
					'month' => null,
					'year'  => 8000,
				),
				'max_qty'    => 1,
				'lines'      => array( array( 'kind' => 'module', 'code' => 'pos', 'qty' => 1 ) ),
			);
		} else {
			$offers[] = $this->module_offer( 'fbr', 'FBR', 1000 * $factor ) + array( 'requires' => array( array( 'distribution', 'pos' ) ) );
		}
		$offers[]      = array(
			'id'         => 'user',
			'label'      => 'Extra user',
			'group'      => 'quantity',
			'unit_price' => 300 * $factor,
			'max_qty'    => 10,
			'lines'      => array( $this->limit( 'users' ) ),
		);
		$offers[]      = array(
			'id'         => 'phone',
			'label'      => 'Extra phone',
			'group'      => 'quantity',
			'unit_price' => 500 * $factor,
			'max_qty'    => 5,
			'lines'      => array( $this->limit( 'phones' ), $this->limit( 'users' ) ),
		);
		if ( 'existing' === $ctx['flow'] && 'lifetime' === $ctx['billing'] ) {
			$offers[] = array(
				'id'         => 'updates',
				'label'      => 'Desktop updates (1 year)',
				'group'      => 'term',
				'unit_price' => 5000,
				'max_qty'    => 1,
				'lines'      => array(
					array(
						'kind' => 'module',
						'code' => 'assets',
						'qty'  => 1,
					),
				),
				'term_days'  => 365,
			);
		}
		return $offers;
	}

	private function module_offer( string $code, string $label, float $price ): array {
		return array(
			'id'         => 'module:' . $code,
			'label'      => $label,
			'group'      => 'module',
			'unit_price' => $price,
			'max_qty'    => 1,
			'lines'      => array(
				array(
					'kind' => 'module',
					'code' => $code,
					'qty'  => 1,
				),
			),
		);
	}

	private function limit( string $code ): array {
		return array(
			'kind' => 'limit',
			'code' => $code,
			'qty'  => 1,
		);
	}

	/** `wplm_entitlement_saved`: break the next add-on line once (a crash mid-fulfilment). */
	public function maybe_break( $line, $op ): void {
		if ( $this->break_next && 'added' === $op && 'addon' === $line->source ) {
			$this->break_next = false;
			throw new \RuntimeException( 'database went away' );
		}
	}

	// ---------------------------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------------------------

	private function recurring( string $name, string $period, float $price, array $lines, array $more = array() ): array {
		return $more + array(
			'name'             => $name,
			'billing_type'     => 'recurring',
			'billing_period'   => $period,
			'billing_interval' => 1,
			'price'            => $price,
			'entitlements'     => $lines,
		);
	}

	private function package( string $name ): Package {
		foreach ( $this->plan['packages'] as $package ) {
			if ( $name === $package->name ) {
				return $package;
			}
		}
		$this->fail( "No package {$name}." );
	}

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

	/**
	 * Through the storefront as WooCommerce runs it: validate the posted form, add to cart, total, the
	 * order line items made by WooCommerce's own checkout, then payment confirmed (on-hold → Completed).
	 *
	 * @param array $post The posted form fields (wplm_package_id, wplm_extras, wplm_target_license_id).
	 * @return array{order:\WC_Order, item:\WC_Order_Item_Product, license_id:int, subscription_id:int}
	 */
	private function buy_through_cart( \WC_Product $product, array $post, int $customer, bool $complete = true, int $qty = 1 ): array {
		wp_set_current_user( $customer );
		foreach ( $post as $field => $value ) {
			$_POST[ $field ] = $value;
		}
		wc_clear_notices();
		$ok = apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), $qty );
		$this->assertTrue( $ok, 'The choice is accepted: ' . wp_json_encode( wc_get_notices( 'error' ) ) );
		$key = WC()->cart->add_to_cart( $product->get_id(), $qty );
		$this->assertNotFalse( $key );
		foreach ( array_keys( $post ) as $field ) {
			unset( $_POST[ $field ] );
		}
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
		return $this->result( $order->get_id() );
	}

	private function result( int $order_id ): array {
		$order = wc_get_order( $order_id );
		$items = array_values( $order->get_items() );
		$ids   = json_decode( (string) $items[0]->get_meta( '_wplm_license_ids' ), true );
		return array(
			'order'           => $order,
			'item'            => $items[0],
			'license_id'      => (int) ( $ids[0] ?? 0 ),
			'subscription_id' => (int) $items[0]->get_meta( '_wplm_subscription_id' ),
		);
	}

	/** Buy a licence type with extras (new purchase). */
	private function buy_new( string $package, array $extras, ?int $customer = null, bool $complete = true ): array {
		$customer = $customer ?? $this->create_customer();
		$bought   = $this->buy_through_cart(
			$this->product,
			array(
				'wplm_package_id' => (string) $this->package( $package )->id,
				'wplm_extras'     => $extras,
			),
			$customer,
			$complete
		);
		$bought['customer'] = $customer;
		return $bought;
	}

	/** Re-complete an order as an owner would after fixing a cause. */
	private function complete_again( \WC_Order $order ): void {
		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'processing' );
		$order->update_status( 'completed' );
	}

	/** @return \WPLM\Models\Entitlement[] */
	private function lines( int $license_id, ?string $source = null ): array {
		$lines = $this->make( EntitlementRepository::class )->get_by_license( $license_id );
		return array_values( array_filter( $lines, static fn( $l ) => null === $source || $source === $l->source ) );
	}

	private function line( int $license_id, string $code, ?string $source = null ): ?\WPLM\Models\Entitlement {
		foreach ( $this->lines( $license_id, $source ) as $line ) {
			if ( $code === $line->code ) {
				return $line;
			}
		}
		return null;
	}

	private function items( int $subscription_id ): array {
		return $this->make( SubscriptionRepository::class )->get_items( $subscription_id );
	}

	private function total( int $subscription_id ): float {
		return (float) $this->subscription_row( $subscription_id )['recurring_total'];
	}

	private function licence_count( int $customer ): int {
		return (int) $this->make( LicenseRepository::class )->get_list( array( 'user_id' => $customer ) )['total'];
	}

	private function notes( \WC_Order $order ): string {
		return implode( "\n", array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) );
	}

	private function render( \WC_Product $subject ): string {
		global $product;
		$product = $subject;
		ob_start();
		$this->checkout()->render_package_options();
		return (string) ob_get_clean();
	}

	private function validate( array $post, ?\WC_Product $product = null ): bool {
		foreach ( $post as $field => $value ) {
			$_POST[ $field ] = $value;
		}
		wc_clear_notices();
		$ok = $this->checkout()->validate_add_to_cart( true, ( $product ?? $this->product )->get_id(), 1 );
		foreach ( array_keys( $post ) as $field ) {
			unset( $_POST[ $field ] );
		}
		return $ok;
	}

	private function first_error(): string {
		$errors = wc_get_notices( 'error' );
		return (string) ( $errors[0]['notice'] ?? '' );
	}

	// ---------------------------------------------------------------------------------------------
	// The billing key
	// ---------------------------------------------------------------------------------------------

	public function test_the_billing_key_names_monthly_yearly_and_lifetime_only(): void {
		$this->assertSame( 'month', $this->package( 'Monthly' )->billing_key() );
		$this->assertSame( 'year', $this->package( 'Yearly' )->billing_key() );
		$this->assertSame( 'lifetime', $this->package( 'Lifetime' )->billing_key() );
		$this->assertSame( 'month', $this->package( 'Monthly with trial' )->billing_key(), 'A trial does not change the key.' );
		$this->assertNull( $this->package( 'Quarterly' )->billing_key() );
		$onetime                 = new Package();
		$onetime->billing_type   = Package::TYPE_ONETIME;
		$onetime->valid_for_days = 30;
		$this->assertNull( $onetime->billing_key() );
	}

	// ---------------------------------------------------------------------------------------------
	// Offers and the choice
	// ---------------------------------------------------------------------------------------------

	public function test_wplm_holds_every_offer_to_what_an_extra_may_be(): void {
		add_filter(
			'wplm_package_extras',
			static function ( $offers ) {
				$good = array(
					'unit_price' => 100,
					'max_qty'    => 3,
					'lines'      => array(
						array(
							'kind' => 'limit',
							'code' => 'seats',
							'qty'  => 1,
						),
					),
				);
				return array_merge(
					$offers,
					array(
						array( 'id' => 'Bad Id' ) + $good,
						array( 'id' => 'user' ) + $good, // Repeats an id: the first wins.
						array(
							'id'         => 'nan',
							'unit_price' => NAN,
						) + $good,
						array(
							'id'         => 'negative',
							'unit_price' => -1,
						) + $good,
						array(
							'id'      => 'zero-max',
							'max_qty' => 0,
						) + $good,
						array(
							'id'    => 'teleport',
							'lines' => array(
								array(
									'kind' => 'module',
									'code' => 'teleport',
								),
							),
						) + $good,
						array(
							'id'    => 'term-without-days',
							'group' => 'term',
						) + $good,
						array(
							'id'         => 'seat',
							'label'      => 'Extra seat',
							'unit_price' => 99.999,
						) + $good,
						array(
							'id'      => 'module:pos',
							'label'   => 'Point of Sale',
							'max_qty' => 4,
							'lines'   => array(
								array(
									'kind' => 'module',
									'code' => 'pos',
								),
							),
						) + $good,
					)
				);
			},
			20
		);
		$ctx    = $this->make( PackageExtrasService::class )->context_for_package( $this->make( PlanService::class )->get( $this->plan['plan_id'] ), $this->package( 'Monthly' ) );
		$offers = array_column( $this->make( PackageExtrasService::class )->offers( $ctx ), null, 'id' );

		$this->assertSame( array( 'module:factory', 'module:subcontract', 'module:fbr', 'user', 'phone', 'seat', 'module:pos' ), array_keys( $offers ), 'Distribution is held; the bad ones are dropped.' );
		$this->assertSame( 300.0, $offers['user']['unit_price'], 'The first "user" wins.' );
		$this->assertSame( 100.0, $offers['seat']['unit_price'], 'Rounded to the shop\'s decimals.' );
		$this->assertSame( 1, $offers['module:pos']['max_qty'], 'A module is bought once.' );
		$this->assertSame( array( array( 'distribution', 'pos' ) ), $offers['module:fbr']['requires'] );
	}

	public function test_a_trial_package_is_offered_no_extras_and_the_filter_is_not_called(): void {
		$plan = $this->make( PlanService::class )->get( $this->plan['plan_id'] );

		$this->assertNull( $this->make( PackageExtrasService::class )->context_for_package( $plan, $this->package( 'Monthly with trial' ) ) );
		$this->assertNull( $this->make( PackageExtrasService::class )->context_for_package( $plan, $this->package( 'Quarterly' ) ) );

		$this->calls = array();
		$this->assertFalse(
			$this->validate(
				array(
					'wplm_package_id' => (string) $this->package( 'Monthly with trial' )->id,
					'wplm_extras'     => array( 'module:factory' => '1' ),
				)
			)
		);
		$this->assertSame( 'Add-ons cannot be added to this licence type.', $this->first_error() );
		$this->assertSame( array(), $this->calls, 'The filter is not even called for a trial.' );

		$html = $this->render( $this->product );
		$this->assertStringNotContainsString( 'data-wplm-for="' . $this->package( 'Monthly with trial' )->id . '"', $html );
	}

	public function test_the_choice_is_refused_for_a_held_module_an_unmet_requirement_or_too_many(): void {
		$monthly = (string) $this->package( 'Monthly' )->id;

		$this->assertFalse( $this->validate( array( 'wplm_package_id' => $monthly, 'wplm_extras' => array( 'module:distribution' => '1' ) ) ) );
		$this->assertSame( 'You already have Distribution, so it cannot be added again.', $this->first_error() );

		$this->assertFalse( $this->validate( array( 'wplm_package_id' => $monthly, 'wplm_extras' => array( 'user' => '11' ) ) ) );
		$this->assertSame( 'Extra user: choose between 0 and 10.', $this->first_error() );

		$this->assertFalse( $this->validate( array( 'wplm_package_id' => $monthly, 'wplm_extras' => array( 'user' => '-2' ) ) ) );
		$this->assertFalse( $this->validate( array( 'wplm_package_id' => $monthly, 'wplm_extras' => array( 'teleport' => '1' ) ) ) );
		$this->assertSame( 'One of the add-ons you chose is not available. Please choose again.', $this->first_error() );

		// The profile's requirement: Subcontracting needs Factory or Point of Sale, held or ticked now.
		$this->make( ProfileRegistry::class )->get( 'acme-office' )->requires = array( 'subcontract' => array( 'factory', 'pos' ) );
		$this->assertFalse( $this->validate( array( 'wplm_package_id' => $monthly, 'wplm_extras' => array( 'module:subcontract' => '1' ) ) ) );
		$this->assertSame( 'Subcontracting needs Factory or Point of Sale.', $this->first_error() );
		$this->assertTrue(
			$this->validate(
				array(
					'wplm_package_id' => $monthly,
					'wplm_extras'     => array(
						'module:subcontract' => '1',
						'module:factory'     => '1',
					),
				)
			)
		);

		// The offer's own requirement (FBR needs Distribution or Point of Sale): the base holds Distribution.
		$this->assertTrue( $this->validate( array( 'wplm_package_id' => $monthly, 'wplm_extras' => array( 'module:fbr' => '1' ) ) ) );
		$this->assertSame( 0, wc_notice_count( 'error' ) );
	}

	public function test_the_cart_charges_the_rebuilt_price_never_one_the_browser_sent(): void {
		$customer = $this->create_customer();
		wp_set_current_user( $customer );
		$_POST['wplm_package_id']   = (string) $this->package( 'Monthly' )->id;
		$_POST['wplm_extras']       = array(
			'module:factory' => '1',
			'user'           => '2',
			'price'          => '1',
		);
		$_POST['wplm_extras_total'] = '1';
		unset( $_POST['wplm_extras']['price'] );
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product->get_id(), 5 ) );
		$key = WC()->cart->add_to_cart( $this->product->get_id(), 5, 0, array(), array( 'price' => 1 ) );
		unset( $_POST['wplm_package_id'], $_POST['wplm_extras'], $_POST['wplm_extras_total'] );
		$this->assertNotFalse( $key );
		WC()->cart->calculate_totals();

		$line = WC()->cart->get_cart()[ $key ];
		$this->assertSame( 1, (int) $line['quantity'], 'One licence per line.' );
		$this->assertSame(
			array(
				'module:factory' => 1,
				'user'           => 2,
			),
			$line['wplm_extras'],
			'The cart keeps the choice only.'
		);
		$this->assertSame( 7100.0, (float) $line['data']->get_price(), '5000 + Factory 1500 + 2 users × 300.' );
		$this->assertSame( 7100.0, (float) WC()->cart->get_subtotal() );

		// A price list change is picked up on the next pass: the cart is never priced from the browser.
		add_filter(
			'wplm_package_extras',
			static function ( $offers ) {
				foreach ( $offers as &$offer ) {
					if ( 'module:factory' === $offer['id'] ) {
						$offer['unit_price'] = 2000;
					}
				}
				return $offers;
			},
			20
		);
		WC()->cart->calculate_totals();
		$this->assertSame( 7600.0, (float) WC()->cart->get_subtotal() );

		$shown = $this->checkout()->display_cart_item_data( array(), WC()->cart->get_cart()[ $key ] );
		$this->assertSame( 'Add-ons', $shown[1]['key'] );
		$this->assertSame( 'Factory; 2 × Extra user', $shown[1]['value'] );
	}

	public function test_checkout_stops_when_the_extras_can_no_longer_be_sold(): void {
		$key = WC()->cart->add_to_cart(
			$this->product->get_id(),
			1,
			0,
			array(),
			array(
				'wplm_package_id' => $this->package( 'Monthly' )->id,
				'wplm_extras'     => array( 'module:factory' => 1 ),
			)
		);
		$this->assertNotFalse( $key );
		$this->checkout()->check_cart_extras();
		$this->assertSame( 0, wc_notice_count( 'error' ) );

		add_filter( 'wplm_package_extras', static fn( $offers ) => array_values( array_filter( $offers, static fn( $o ) => 'module:factory' !== $o['id'] ) ), 20 );
		$this->checkout()->check_cart_extras();
		$this->assertSame( 1, wc_notice_count( 'error' ) );
	}

	// ---------------------------------------------------------------------------------------------
	// The order line
	// ---------------------------------------------------------------------------------------------

	public function test_the_order_line_keeps_a_snapshot_with_unknown_keys_and_a_visible_summary(): void {
		add_filter(
			'wplm_package_extras_snapshot',
			static function ( array $snapshot, array $ctx ) {
				$snapshot['price_list_version'] = 4;
				$snapshot['billing']            = 'lifetime'; // Cannot override WPLM's own keys.
				$snapshot['ctx_flow']           = $ctx['flow'];
				return $snapshot;
			},
			10,
			2
		);
		$bought = $this->buy_new(
			'Monthly',
			array(
				'module:factory' => '1',
				'phone'          => '2',
			),
			null,
			false
		);
		$item   = $bought['item'];

		$snapshot = json_decode( (string) $item->get_meta( '_wplm_extras' ), true );
		$this->assertSame( 'month', $snapshot['billing'] );
		$this->assertSame( 'new', $snapshot['flow'] );
		$this->assertNull( $snapshot['cycle'], 'No recurring add-ons on a monthly licence type.' );
		$this->assertSame( 4, $snapshot['price_list_version'] );
		$this->assertSame( 'new', $snapshot['ctx_flow'] );
		$this->assertSame(
			array(
				'id'         => 'phone',
				'label'      => 'Extra phone',
				'qty'        => 2,
				'unit_price' => 500,
				'amount'     => 1000,
				'lines'      => array(
					array(
						'kind' => 'limit',
						'code' => 'phones',
						'qty'  => 2,
					),
					array(
						'kind' => 'limit',
						'code' => 'users',
						'qty'  => 2,
					),
				),
				'term_days'  => null,
				'cycle'      => null,
			),
			$snapshot['items'][1]
		);
		$this->assertEquals( 2500, $item->get_meta( '_wplm_extras_total' ) );
		$this->assertSame( 'Factory; 2 × Extra phone', $item->get_meta( 'Add-ons' ) );
		$this->assertSame( $this->package( 'Monthly' )->id, (int) $item->get_meta( '_wplm_package_id' ) );
		$this->assertSame( 1, (int) $item->get_quantity() );
		$this->assertSame( 7500.0, (float) $bought['order']->get_total() );
	}

	// ---------------------------------------------------------------------------------------------
	// Fulfilment: one key, one bill
	// ---------------------------------------------------------------------------------------------

	public function test_monthly_one_key_one_subscription_billing_the_base_and_every_extra(): void {
		$issued = did_action( 'wplm_order_licenses_issued' );
		$bought = $this->buy_new(
			'Monthly',
			array(
				'module:factory' => '1',
				'user'           => '2',
			)
		);

		$this->assertSame( 1, $this->licence_count( $bought['customer'] ), 'One key.' );
		$this->assertSame( $issued + 1, did_action( 'wplm_order_licenses_issued' ) );
		$this->assertGreaterThan( 0, $bought['subscription_id'] );
		$this->assertSame( '1', (string) $bought['order']->get_meta( '_wplm_plan_fulfilled' ) );

		$base    = $this->line( $bought['license_id'], 'base', 'plan' );
		$factory = $this->line( $bought['license_id'], 'factory', 'addon' );
		$users   = $this->line( $bought['license_id'], 'users', 'addon' );
		$this->assertNotNull( $factory );
		$this->assertSame( $bought['order']->get_id(), $factory->order_id );
		$this->assertSame( $bought['subscription_id'], $factory->subscription_id );
		$this->assertSame( $base->paid_through, $factory->paid_through, 'Paid through the same day as the base.' );
		$this->assertSame( 2, $users->qty );
		$this->assertSame( $base->paid_through, $users->paid_through );

		$items = $this->items( $bought['subscription_id'] );
		$this->assertCount( 3, $items, 'The base and one item per extra.' );
		$this->assertTrue( empty( $items[0]->meta['addon'] ) );
		$this->assertTrue( $items[1]->meta['addon'] );
		$this->assertSame( array( $factory->id ), $items[1]->meta['entitlement_ids'] );
		$this->assertSame( 'module:factory', $items[1]->meta['extra_id'] );
		$this->assertSame( 7100.0, $this->total( $bought['subscription_id'] ), 'The sum of the items.' );
		$this->assertSame( 7100.0, array_sum( array_map( static fn( $i ) => $i->line_total, $items ) ) );
		$ids = json_decode( (string) $bought['item']->get_meta( '_wplm_entitlement_ids' ), true );
		$this->assertCount( 2, $ids );
		$summary = EntitlementService::summarize_lines( $this->lines( $bought['license_id'] ), $this->make( ProfileRegistry::class )->get( 'acme-office' ), wp_date( 'Y-m-d' ), 7 );
		$this->assertSame( 5, $summary['limits']['users'] );
		$this->assertArrayHasKey( 'factory', $summary['modules'] );
	}

	public function test_the_renewal_bills_base_plus_extras_and_extends_every_line(): void {
		$bought = $this->buy_new(
			'Monthly',
			array(
				'module:factory' => '1',
				'phone'          => '1',
			)
		);
		$sub    = $this->make( SubscriptionRepository::class )->find_by_id( $bought['subscription_id'] );

		$invoice = $this->make( SelfServiceRenewal::class )->create_renewal_order_object( $sub->id );
		$this->assertSame( 7000.0, (float) $invoice->get_total(), '5000 + Factory 1500 + a phone 500.' );

		$before = $this->line( $bought['license_id'], 'base' )->paid_through;
		$this->make( RenewalProcessor::class )->apply_payment( $sub, null, 7000.0 );

		$expected = EntitlementService::add_period( $before, 1, 'month' );
		foreach ( $this->lines( $bought['license_id'] ) as $line ) {
			$this->assertSame( $expected, $line->paid_through, $line->code . ' (' . $line->source . ') is extended.' );
		}
	}

	public function test_yearly_extras_are_priced_and_paid_yearly(): void {
		$bought = $this->buy_new( 'Yearly', array( 'module:factory' => '1' ) );

		$this->assertSame( 65000.0, (float) $bought['order']->get_total(), '50000 + Factory 15000.' );
		$this->assertSame( 65000.0, $this->total( $bought['subscription_id'] ) );
		$this->assertSame( 'year', $this->subscription_row( $bought['subscription_id'] )['billing_period'] );
		$this->assertSame( $this->line( $bought['license_id'], 'base' )->paid_through, $this->line( $bought['license_id'], 'factory' )->paid_through );
	}

	public function test_lifetime_one_payment_undated_lines_and_no_subscription(): void {
		$bought = $this->buy_new(
			'Lifetime',
			array(
				'module:factory' => '1',
				'user'           => '1',
			)
		);

		$this->assertSame( 154000.0, (float) $bought['order']->get_total(), '100000 + Factory 45000 + a user 9000.' );
		$this->assertSame( 0, $bought['subscription_id'] );
		$this->assertSame( array(), $this->make( SubscriptionRepository::class )->get_by_user( $bought['customer'] ) );
		foreach ( $this->lines( $bought['license_id'] ) as $line ) {
			$this->assertNull( $line->paid_through );
			$this->assertNull( $line->subscription_id );
		}
		$this->assertCount( 2, $this->lines( $bought['license_id'], 'addon' ) );
		$this->assertSame( '1', (string) $bought['order']->get_meta( '_wplm_plan_fulfilled' ) );
	}

	public function test_a_sign_up_fee_is_charged_once_beside_base_and_extras(): void {
		$bought = $this->buy_new( 'Monthly with fee', array( 'module:factory' => '1' ) );

		$this->assertSame( 8500.0, (float) $bought['order']->get_total(), 'Fee 2000 + 5000 + Factory 1500.' );
		$this->assertSame( 6500.0, $this->total( $bought['subscription_id'] ), 'The fee is not renewed.' );
	}

	public function test_completing_the_order_again_issues_no_second_key(): void {
		$bought = $this->buy_new( 'Monthly', array( 'module:factory' => '1' ) );
		$order  = $bought['order'];
		$order->delete_meta_data( '_wplm_plan_fulfilled' );
		$order->save();

		$this->complete_again( $order );

		$this->assertSame( 1, $this->licence_count( $bought['customer'] ) );
		$this->assertCount( 1, $this->make( SubscriptionRepository::class )->get_by_user( $bought['customer'] ) );
		$this->assertCount( 1, $this->lines( $bought['license_id'], 'addon' ) );
		$this->assertCount( 2, $this->items( $bought['subscription_id'] ) );
		$this->assertSame( 6500.0, $this->total( $bought['subscription_id'] ) );
	}

	public function test_an_interrupted_extras_step_retries_only_the_extras(): void {
		$this->break_next = true;
		$bought           = $this->buy_new(
			'Monthly',
			array(
				'module:factory' => '1',
				'user'           => '2',
			)
		);
		$order            = $bought['order'];

		$this->assertSame( 1, $this->licence_count( $bought['customer'] ), 'The licence was issued.' );
		$this->assertSame( array(), $this->lines( $bought['license_id'], 'addon' ), 'Nothing half-written is left behind.' );
		$this->assertSame( '', (string) $bought['item']->get_meta( '_wplm_entitlement_ids' ) );
		$this->assertSame( '', (string) $order->get_meta( '_wplm_plan_fulfilled' ), 'Left retryable.' );
		$this->assertCount( 1, $this->items( $bought['subscription_id'] ) );
		$this->assertSame( 5000.0, $this->total( $bought['subscription_id'] ) );
		$this->assertStringContainsString( 'its add-ons could not be added — the add-on could not be added: database went away', $this->notes( $order ) );

		$this->complete_again( $order );

		$after = $this->result( $order->get_id() );
		$this->assertSame( 1, $this->licence_count( $bought['customer'] ), 'No second key.' );
		$this->assertSame( $bought['license_id'], $after['license_id'] );
		$this->assertCount( 1, $this->make( SubscriptionRepository::class )->get_by_user( $bought['customer'] ) );
		$this->assertCount( 2, $this->lines( $bought['license_id'], 'addon' ), 'The extras, once.' );
		$this->assertCount( 3, $this->items( $bought['subscription_id'] ) );
		$this->assertSame( 7100.0, $this->total( $bought['subscription_id'] ) );
		$this->assertSame( '1', (string) $after['order']->get_meta( '_wplm_plan_fulfilled' ) );
	}

	public function test_a_snapshot_priced_for_another_billing_issues_no_key(): void {
		$bought = $this->buy_new( 'Monthly', array( 'module:factory' => '1' ), null, false );
		$item   = $bought['item'];
		$snap   = json_decode( (string) $item->get_meta( '_wplm_extras' ), true );
		$snap['billing'] = 'year';
		$item->update_meta_data( '_wplm_extras', wp_json_encode( $snap ) );
		$item->save();
		$bought['order']->update_status( 'completed' );

		$this->assertSame( 0, $this->licence_count( $bought['customer'] ), 'Checked before a key is generated.' );
		$this->assertSame( '', (string) wc_get_order( $bought['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertStringContainsString( 'priced for "year" billing', $this->notes( $bought['order'] ) );
	}

	public function test_a_line_without_extras_is_fulfilled_exactly_as_before(): void {
		$customer = $this->create_customer();
		$bought   = $this->buy_through_cart( $this->product, array( 'wplm_package_id' => (string) $this->package( 'Monthly' )->id ), $customer );

		$this->assertSame( '', (string) $bought['item']->get_meta( '_wplm_extras' ) );
		$this->assertSame( '', (string) $bought['item']->get_meta( '_wplm_entitlement_ids' ) );
		$this->assertSame( array(), $this->lines( $bought['license_id'], 'addon' ) );
		$this->assertCount( 1, $this->items( $bought['subscription_id'] ) );
		$this->assertSame( 5000.0, $this->total( $bought['subscription_id'] ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Taking it back
	// ---------------------------------------------------------------------------------------------

	public function test_a_full_refund_suspends_the_licence_and_removes_the_extras(): void {
		$bought = $this->buy_new(
			'Monthly',
			array(
				'module:factory' => '1',
				'user'           => '2',
			)
		);

		$bought['order']->update_status( 'refunded' );

		$this->assertSame( 'cancelled', $this->subscription_row( $bought['subscription_id'] )['status'] );
		$this->assertSame( 'suspended', strtolower( $this->make( LicenseRepository::class )->find_by_id( $bought['license_id'] )->status_label() ) );
		$this->assertSame( array(), $this->lines( $bought['license_id'], 'addon' ) );
		$this->assertNotEmpty( $this->lines( $bought['license_id'], 'plan' ), 'The licence type\'s own lines stay on the suspended licence.' );
		$notes = $this->notes( $bought['order'] );
		$this->assertStringContainsString( 'add-on lines bought with the licence removed. The licence issued by this order is suspended.', $notes );
		$this->assertStringNotContainsString( 'The licence itself is unchanged', $notes );
	}

	public function test_removing_one_extra_in_admin_drops_its_billing(): void {
		$bought  = $this->buy_new(
			'Monthly',
			array(
				'module:factory' => '1',
				'user'           => '2',
			)
		);
		$factory = $this->line( $bought['license_id'], 'factory', 'addon' );

		$result = $this->make( LicenceAdminActions::class )->delete_line( $bought['license_id'], $factory->id );

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 2, $this->items( $bought['subscription_id'] ) );
		$this->assertSame( 5600.0, $this->total( $bought['subscription_id'] ), '5000 + 2 users × 300.' );
	}

	// ---------------------------------------------------------------------------------------------
	// Switching plans
	// ---------------------------------------------------------------------------------------------

	public function test_a_plan_switch_keeps_billing_the_extras(): void {
		$bought = $this->buy_new(
			'Monthly',
			array(
				'module:factory' => '1',
				'user'           => '2',
			)
		);
		$this->set_row( 'subscriptions', $bought['subscription_id'], array( 'status' => 'active' ) );

		$result = $this->make( SwitchManager::class )->switch_plan(
			$bought['subscription_id'],
			array(
				'recurring_total'  => 6000,
				'billing_interval' => 1,
				'billing_period'   => 'month',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 8100.0, $this->total( $bought['subscription_id'] ), 'New base 6000 + Factory 1500 + 2 users × 300.' );
		$items = $this->items( $bought['subscription_id'] );
		$this->assertCount( 3, $items );
		$this->assertSame( 6000.0, $items[0]->line_total );

		$refused = $this->make( SwitchManager::class )->switch_plan(
			$bought['subscription_id'],
			array(
				'recurring_total'  => 60000,
				'billing_interval' => 1,
				'billing_period'   => 'year',
			)
		);
		$this->assertWPError( $refused );
		$this->assertSame( 'wplm_switch_addons_billing', $refused->get_error_code() );
		$this->assertSame( 8100.0, $this->total( $bought['subscription_id'] ), 'Nothing changed.' );
	}

	// ---------------------------------------------------------------------------------------------
	// The form
	// ---------------------------------------------------------------------------------------------

	public function test_the_fallback_form_renders_when_nothing_is_hooked(): void {
		$this->assertFalse( has_action( 'wplm_package_extras_form' ) );
		$html    = $this->render( $this->product );
		$monthly = $this->package( 'Monthly' )->id;
		$yearly  = $this->package( 'Yearly' )->id;

		$this->assertMatchesRegularExpression( '/<fieldset class="wplm-extras" data-wplm-for="' . $monthly . '" style/', $html, 'The first licence type is selected: its extras are shown and enabled.' );
		$this->assertMatchesRegularExpression( '/<fieldset class="wplm-extras" data-wplm-for="' . $yearly . '" hidden disabled/', $html, 'The others are disabled, so their fields never post.' );
		$this->assertStringContainsString( 'type="checkbox" id="wplm-extra-' . $monthly . '-module-factory" name="wplm_extras[module:factory]" value="1"', $html );
		$this->assertStringContainsString( 'type="number" id="wplm-extra-' . $monthly . '-user" name="wplm_extras[user]" value="0" min="0" max="10"', $html );
		$this->assertStringNotContainsString( 'wplm_extras[module:distribution]', $html, 'A held module is not offered.' );
		$this->assertStringContainsString( '<legend', $html );
	}

	public function test_a_hooked_form_gets_the_offers_and_replaces_the_fallback(): void {
		$seen = array();
		add_action(
			'wplm_package_extras_form',
			static function ( $data ) use ( &$seen ) {
				$seen[] = $data;
				echo '<div id="configurator"></div>';
			}
		);

		$html = $this->render( $this->product );

		$this->assertStringContainsString( '<div id="configurator"></div>', $html );
		$this->assertStringNotContainsString( 'class="wplm-extras"', $html );
		$this->assertCount( 1, $seen );
		$data = $seen[0];
		$this->assertInstanceOf( Plan::class, $data['plan'] );
		$this->assertSame( 'new', $data['flow'] );
		$this->assertSame( array(), $data['offers_by_license'] );
		$this->assertEqualsCanonicalizing(
			array( $this->package( 'Monthly' )->id, $this->package( 'Yearly' )->id, $this->package( 'Lifetime' )->id, $this->package( 'Monthly with fee' )->id ),
			array_keys( $data['offers_by_package'] ),
			'No trial, no quarterly.'
		);
		$this->assertSame( 7000.0, $data['base_prices'][ $this->package( 'Monthly with fee' )->id ], 'Fee included.' );
		$this->assertSame( 0.0, $data['base_prices'][ $this->package( 'Monthly with trial' )->id ], 'A trial costs nothing now.' );
		$this->assertSame( get_woocommerce_currency(), $data['currency']['code'] );
		$this->assertArrayHasKey( 'decimals', $data['currency'] );
	}

	// ---------------------------------------------------------------------------------------------
	// An existing licence: the add-ons product, priced by the same filter
	// ---------------------------------------------------------------------------------------------

	/** An add-on plan with no stored packages, and its product. */
	private function addon_product(): \WC_Product_Simple {
		$plan = $this->create_plan(
			array(),
			array(
				'name'    => 'Acme add-ons',
				'profile' => 'acme-office',
				'kind'    => Plan::KIND_ADDON,
			)
		);
		$this->assertSame( array(), $plan['packages'] );
		return $this->create_plan_product( $plan['plan_id'] );
	}

	/** A customer who bought a licence type without extras (the 1.2.x way). */
	private function customer_with( string $package ): array {
		$customer           = $this->create_customer();
		$bought             = $this->buy( $this->product, $this->package( $package ), $customer );
		$bought['customer'] = $customer;
		$this->assertGreaterThan( 0, $bought['license_id'] );
		return $bought;
	}

	private function add_to_licence( \WC_Product $product, array $base, array $extras, bool $complete = true ): array {
		return $this->buy_through_cart(
			$product,
			array(
				'wplm_extras'            => $extras,
				'wplm_target_license_id' => (string) $base['license_id'],
			),
			$base['customer'],
			$complete
		);
	}

	public function test_the_plan_editor_saves_an_addon_plan_with_no_rows(): void {
		$saved = $this->make( \WPLM\Admin\PlanAdminActions::class )->save_plan(
			array(
				'plan_name'    => 'Acme add-ons (priced by a plugin)',
				'plan_profile' => 'acme-office',
				'plan_kind'    => 'addon',
				'plan_status'  => '1',
				'packages'     => array(
					array( 'name' => '' ), // The editor's unused blank row.
				),
			)
		);

		$this->assertTrue( $saved['ok'], $saved['message'] );
		$this->assertSame( array(), $saved['warnings'], 'A plugin prices the extras: nothing to warn about.' );
		$plan = $this->make( PlanService::class )->get( $saved['plan_id'] );
		$this->assertTrue( $plan->is_addon() );
		$this->assertSame( array(), $plan->packages );

		remove_all_filters( 'wplm_package_extras' );
		$again = $this->make( \WPLM\Admin\PlanAdminActions::class )->save_plan(
			array(
				'plan_id'      => (string) $saved['plan_id'],
				'plan_name'    => 'Acme add-ons (priced by a plugin)',
				'plan_profile' => 'acme-office',
				'plan_kind'    => 'addon',
				'plan_status'  => '1',
			)
		);
		$this->assertTrue( $again['ok'] );
		$this->assertStringContainsString( 'no active plugin prices add-ons', $again['warnings'][0] );
	}

	public function test_an_addon_plan_with_no_stored_packages_is_sold_to_a_licence_the_filter_prices(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Monthly' );

		$this->assertFalse( $this->checkout()->plan_is_purchasable( true, $product ), 'Nobody logged in: nothing to add to.' );
		wp_set_current_user( $base['customer'] );
		$this->assertTrue( $this->checkout()->plan_is_purchasable( false, $product ) );

		$_GET['wplm_license'] = (string) $base['license_id'];
		$html                 = $this->render( $product );
		$this->assertMatchesRegularExpression( '/<option value="' . $base['license_id'] . '"\s+selected/', $html );
		$this->assertStringNotContainsString( 'Choose an add-on', $html, 'No stored packages to choose from.' );
		$this->assertMatchesRegularExpression( '/<fieldset class="wplm-extras" data-wplm-for="' . $base['license_id'] . '" style/', $html );
		$this->assertStringContainsString( 'name="wplm_extras[module:factory]"', $html );
		$this->assertStringNotContainsString( 'wplm_extras[module:distribution]', $html, 'The licence has Distribution.' );
		$this->assertStringNotContainsString( 'wplm_extras[updates]', $html, 'A monthly licence is not offered the term pack.' );
	}

	public function test_a_monthly_licence_takes_monthly_extras_on_its_subscription(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Monthly' );
		$applied = array();
		add_action(
			'wplm_order_addons_applied',
			static function ( $order_id, $entries ) use ( &$applied ) {
				$applied[] = $entries;
			},
			10,
			2
		);

		$order = $this->add_to_licence(
			$product,
			$base,
			array(
				'module:factory' => '1',
				'user'           => '2',
			)
		);

		$this->assertSame( 2100.0, (float) $order['order']->get_total(), 'Factory 1500 + 2 users × 300, paid now in full.' );
		$this->assertSame( 1, $this->licence_count( $base['customer'] ), 'No new key.' );
		$this->assertSame( '', (string) $order['item']->get_meta( '_wplm_license_ids' ) );
		$this->assertSame( '', (string) $order['item']->get_meta( '_wplm_package_id' ) );
		$this->assertSame( $base['license_id'], (int) $order['item']->get_meta( '_wplm_addon_license_id' ) );
		$this->assertSame( 'existing', json_decode( (string) $order['item']->get_meta( '_wplm_extras' ), true )['flow'] );
		$this->assertSame( '1', (string) $order['order']->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertCount( 1, $applied );

		$plan_line = $this->line( $base['license_id'], 'base', 'plan' );
		$factory   = $this->line( $base['license_id'], 'factory', 'addon' );
		$this->assertSame( $base['subscription_id'], $factory->subscription_id );
		$this->assertSame( $plan_line->paid_through, $factory->paid_through );
		$this->assertSame( $order['order']->get_id(), $factory->order_id );
		$this->assertCount( 3, $this->items( $base['subscription_id'] ) );
		$this->assertSame( 7100.0, $this->total( $base['subscription_id'] ) );

		// The licence now holds Factory for its term: it is not offered again.
		$this->assertFalse(
			$this->validate(
				array(
					'wplm_extras'            => array( 'module:factory' => '1' ),
					'wplm_target_license_id' => (string) $base['license_id'],
				),
				$product
			)
		);
		$this->assertSame( 'You already have Factory, so it cannot be added again.', $this->first_error() );
	}

	public function test_a_yearly_licence_takes_yearly_prices(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Yearly' );

		$order = $this->add_to_licence( $product, $base, array( 'module:factory' => '1' ) );

		$this->assertSame( 15000.0, (float) $order['order']->get_total() );
		$this->assertSame( 65000.0, $this->total( $base['subscription_id'] ) );
		$this->assertSame( $this->line( $base['license_id'], 'base' )->paid_through, $this->line( $base['license_id'], 'factory' )->paid_through );
	}

	public function test_a_lifetime_licence_takes_lifetime_prices_for_life_and_a_dated_term_pack(): void {
		update_option( 'timezone_string', 'Asia/Karachi' );
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );

		$order = $this->add_to_licence(
			$product,
			$base,
			array(
				'module:factory' => '1',
				'updates'        => '1',
			)
		);

		$this->assertSame( 50000.0, (float) $order['order']->get_total(), 'Factory 45000 + updates 5000.' );
		$factory = $this->line( $base['license_id'], 'factory', 'addon' );
		$this->assertNull( $factory->paid_through );
		$this->assertNull( $factory->subscription_id );
		$updates = $this->line( $base['license_id'], 'assets', 'addon' );
		$this->assertSame( EntitlementService::add_period( wp_date( 'Y-m-d' ), 364, 'day' ), $updates->paid_through, 'today + 365 − 1' );
		$this->assertNull( $updates->subscription_id, 'A term never joins a subscription.' );
		$this->assertSame( '', (string) $order['item']->get_meta( '_wplm_addon_subscription_item_ids' ) );

		// A second pack continues from the first one's end (packs stack).
		$second = $this->add_to_licence( $product, $base, array( 'updates' => '1' ) );
		$mine   = array_values( array_filter( $this->lines( $base['license_id'], 'addon' ), static fn( $l ) => $l->order_id === $second['order']->get_id() ) );
		$this->assertCount( 1, $mine );
		$this->assertSame( EntitlementService::add_period( wp_date( 'Y-m-d' ), 729, 'day' ), $mine[0]->paid_through );
	}

	public function test_a_term_offered_to_a_recurring_licence_is_dated_and_never_billed_with_the_plan(): void {
		add_filter(
			'wplm_package_extras',
			static function ( $offers, $ctx ) {
				if ( 'existing' === $ctx['flow'] && 'month' === $ctx['billing'] ) {
					$offers[] = array(
						'id'         => 'assets-pack',
						'label'      => 'Fixed Assets for 30 days',
						'group'      => 'term',
						'unit_price' => 700,
						'max_qty'    => 1,
						'lines'      => array(
							array(
								'kind' => 'module',
								'code' => 'assets',
							),
						),
						'term_days'  => 30,
					);
				}
				return $offers;
			},
			20,
			2
		);
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Monthly' );

		$order = $this->add_to_licence(
			$product,
			$base,
			array(
				'assets-pack'    => '1',
				'module:factory' => '1',
			)
		);

		$this->assertSame( 2200.0, (float) $order['order']->get_total() );
		$pack = $this->line( $base['license_id'], 'assets', 'addon' );
		$this->assertSame( EntitlementService::add_period( wp_date( 'Y-m-d' ), 29, 'day' ), $pack->paid_through );
		$this->assertNull( $pack->subscription_id, 'The term never joins the plan\'s subscription.' );
		$this->assertSame( $base['subscription_id'], $this->line( $base['license_id'], 'factory', 'addon' )->subscription_id );
		$items = $this->items( $base['subscription_id'] );
		$this->assertCount( 2, $items, 'The plan and Factory: no item for the term.' );
		$this->assertSame( 6500.0, $this->total( $base['subscription_id'] ), 'The term is not renewed.' );
		$this->assertCount( 1, json_decode( (string) $order['item']->get_meta( '_wplm_addon_subscription_item_ids' ), true ) );
	}

	public function test_a_recurring_licence_is_never_offered_a_term_the_filter_does_not_offer_it(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Monthly' );
		$plan    = $this->make( PlanService::class )->get( (int) $product->get_meta( '_wplm_plan_id' ) );

		$ids = array_column( $this->make( AddonService::class )->extras_offers( $plan, $this->make( LicenseService::class )->get_by_id( $base['license_id'] ) ), 'id' );

		$this->assertNotContains( 'updates', $ids );
		$this->assertContains( 'module:factory', $ids );
		wp_set_current_user( $base['customer'] );
		$this->assertFalse(
			$this->validate(
				array(
					'wplm_extras'            => array( 'updates' => '1' ),
					'wplm_target_license_id' => (string) $base['license_id'],
				),
				$product
			)
		);
	}

	public function test_someone_elses_licence_and_an_overdue_licence_are_refused(): void {
		$addon    = $this->addon_product();
		$base     = $this->customer_with( 'Monthly' );
		$stranger = $this->create_customer();

		wp_set_current_user( $stranger );
		$this->assertFalse(
			$this->validate(
				array(
					'wplm_extras'            => array( 'module:factory' => '1' ),
					'wplm_target_license_id' => (string) $base['license_id'],
				),
				$addon
			)
		);
		$this->assertSame( 'That licence is not on your account.', $this->first_error() );

		// Overdue: the extras would be paid for again at the very next renewal.
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wplm_entitlements', array( 'paid_through' => EntitlementService::add_period( wp_date( 'Y-m-d' ), -3, 'day' ) ), array( 'license_id' => $base['license_id'] ) );
		wp_set_current_user( $base['customer'] );
		$this->assertFalse( $this->checkout()->plan_is_purchasable( true, $addon ) );
		$this->assertFalse(
			$this->validate(
				array(
					'wplm_extras'            => array( 'module:factory' => '1' ),
					'wplm_target_license_id' => (string) $base['license_id'],
				),
				$addon
			)
		);
		global $product;
		$product = $addon;
		ob_start();
		$this->checkout()->render_addon_notice();
		$this->assertStringContainsString( 'Pay your plan&#039;s renewal first, then add this.', (string) ob_get_clean() );
	}

	public function test_a_live_dated_module_on_the_licence_meets_a_requirement(): void {
		$this->make( ProfileRegistry::class )->get( 'acme-office' )->requires = array( 'subcontract' => array( 'factory', 'pos' ) );
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Monthly' );
		wp_set_current_user( $base['customer'] );
		$post = array(
			'wplm_extras'            => array( 'module:subcontract' => '1' ),
			'wplm_target_license_id' => (string) $base['license_id'],
		);

		$this->assertFalse( $this->validate( $post, $product ) );
		$this->assertSame( 'Subcontracting needs Factory or Point of Sale.', $this->first_error() );

		// Factory added by hand for a few weeks (how packs were sold before): live, so it counts.
		$this->make( EntitlementService::class )->add_line(
			$base['license_id'],
			array(
				'kind'         => 'module',
				'code'         => 'factory',
				'paid_through' => EntitlementService::add_period( wp_date( 'Y-m-d' ), 20, 'day' ),
			)
		);
		$this->assertTrue( $this->validate( $post, $product ), $this->first_error() );
	}

	public function test_completing_an_existing_licence_order_again_adds_nothing_more(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Monthly' );
		$order   = $this->add_to_licence( $product, $base, array( 'module:factory' => '1' ) );
		$o       = $order['order'];
		$o->delete_meta_data( '_wplm_plan_fulfilled' );
		$o->save();

		$this->complete_again( $o );

		$this->assertCount( 1, $this->lines( $base['license_id'], 'addon' ) );
		$this->assertSame( 6500.0, $this->total( $base['subscription_id'] ) );
	}

	public function test_a_refund_of_an_existing_licence_order_leaves_the_licence_alone(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Monthly' );
		$order   = $this->add_to_licence( $product, $base, array( 'module:factory' => '1' ) );

		$order['order']->update_status( 'refunded' );

		$this->assertSame( array(), $this->lines( $base['license_id'], 'addon' ) );
		$this->assertSame( 5000.0, $this->total( $base['subscription_id'] ) );
		$this->assertSame( 'active', $this->subscription_row( $base['subscription_id'] )['status'] );
		$this->assertStringContainsString( 'The licence itself is unchanged.', $this->notes( $order['order'] ) );
	}

	public function test_the_new_licence_email_does_not_say_its_extras_were_added_to_a_licence(): void {
		$bought = $this->buy_new( 'Monthly', array( 'module:factory' => '1' ) );

		ob_start();
		do_action( 'woocommerce_email_after_order_table', new \WC_Order( $bought['order']->get_id() ), false, false, new \WC_Email_Customer_Completed_Order() );
		$body = (string) ob_get_clean();

		$this->assertStringContainsString( 'Your licence key and setup details are in the PDF', $body );
		$this->assertStringNotContainsString( 'Added to your licence', $body );
	}

	// ---------------------------------------------------------------------------------------------
	// Review fixes (1.3.0)
	// ---------------------------------------------------------------------------------------------

	private function factory_lines( int $license_id ): array {
		return array_values( array_filter( $this->lines( $license_id, 'addon' ), static fn( $l ) => 'factory' === $l->code ) );
	}

	/** Two orders for the same module on one licence, both placed before either completes. */
	public function test_the_same_module_bought_on_two_orders_is_added_once_and_billed_once(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Monthly' );
		$extras  = array(
			'module:factory' => '1',
			'user'           => '1',
		);
		$first   = $this->add_to_licence( $product, $base, $extras, false );
		$second  = $this->add_to_licence( $product, $base, $extras, false );

		$first['order']->update_status( 'completed' );
		$second['order']->update_status( 'completed' );

		$this->assertCount( 1, $this->factory_lines( $base['license_id'] ), 'Factory once.' );
		$this->assertSame( 6800.0, $this->total( $base['subscription_id'] ), '5000 + Factory 1500 + a user 300, once.' );
		$this->assertCount( 3, $this->items( $base['subscription_id'] ) );
		$this->assertSame( '', (string) wc_get_order( $second['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ), 'The second order is left for the shop.' );
		$this->assertStringContainsString( 'already has Factory (added since this order was placed', $this->notes( $second['order'] ) );
		$this->assertSame( '', (string) $second['item']->get_meta( '_wplm_entitlement_ids' ) );
	}

	public function test_the_same_module_twice_in_one_cart_for_one_licence_is_refused(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Monthly' );
		wp_set_current_user( $base['customer'] );
		$post = array(
			'wplm_extras'            => array( 'module:factory' => '1' ),
			'wplm_target_license_id' => (string) $base['license_id'],
		);

		$this->assertTrue( $this->validate( $post, $product ) );
		$key = WC()->cart->add_to_cart(
			$product->get_id(),
			1,
			0,
			array(),
			array(
				'wplm_extras'           => array( 'module:factory' => 1 ),
				'wplm_addon_license_id' => $base['license_id'],
			)
		);
		$this->assertNotFalse( $key );

		$this->assertFalse( $this->validate( $post, $product ) );
		$this->assertStringContainsString( 'Factory is already in your cart for your licence ending', $this->first_error() );
		$this->assertTrue(
			$this->validate(
				array(
					'wplm_extras'            => array( 'user' => '2' ),
					'wplm_target_license_id' => (string) $base['license_id'],
				),
				$product
			),
			'Users add up: a second line of users is fine.'
		);

		// A second line that got in anyway (another tab) stops the checkout.
		WC()->cart->add_to_cart(
			$product->get_id(),
			1,
			0,
			array(),
			array(
				'wplm_extras'           => array(
					'module:factory' => 1,
					'user'           => 1,
				),
				'wplm_addon_license_id' => $base['license_id'],
			)
		);
		wc_clear_notices();
		$this->checkout()->check_cart_extras();
		$this->assertSame( 1, wc_notice_count( 'error' ) );
		$this->assertStringContainsString( 'Factory is already in your cart', $this->first_error() );
	}

	public function test_a_held_fulfilment_claim_makes_a_concurrent_completion_a_no_op(): void {
		global $wpdb;
		$bought = $this->buy_new( 'Monthly', array( 'module:factory' => '1' ), null, false );
		$id     = $bought['order']->get_id();
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => 'wplm_fulfil_lock_' . $id,
				'option_value' => (string) time(),
				'autoload'     => 'no',
			)
		);

		$bought['order']->update_status( 'completed' ); // Another request holds the claim.

		$this->assertSame( 0, $this->licence_count( $bought['customer'] ) );
		$this->assertSame( '', (string) wc_get_order( $id )->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertStringContainsString( 'another fulfilment of this order is in progress', $this->notes( $bought['order'] ) );

		// The holder finishes and releases; then one completion issues one key.
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'wplm_fulfil_lock_' . $id ) );
		$this->checkout()->fulfill_order( $id );
		$this->checkout()->fulfill_order( $id );
		$this->assertSame( 1, $this->licence_count( $bought['customer'] ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wplm_fulfil_lock_' . $id ) ), 'Released.' );
	}

	public function test_a_stale_fulfilment_claim_is_taken_over(): void {
		global $wpdb;
		$bought = $this->buy_new( 'Monthly', array( 'module:factory' => '1' ), null, false );
		$id     = $bought['order']->get_id();
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => 'wplm_fulfil_lock_' . $id,
				'option_value' => (string) ( time() - PlanCheckout::FULFIL_LOCK_TTL - 60 ),
				'autoload'     => 'no',
			)
		);

		$bought['order']->update_status( 'completed' );

		$this->assertSame( 1, $this->licence_count( $bought['customer'] ), 'A request that died long ago does not block the order.' );
		$this->assertCount( 1, $this->factory_lines( $this->result( $id )['license_id'] ) );
	}

	public function test_a_renewal_between_sale_and_completion_is_noted_and_the_add_on_follows_the_plan(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Monthly' );
		$order   = $this->add_to_licence( $product, $base, array( 'module:factory' => '1' ), false );
		$snap    = json_decode( (string) $order['item']->get_meta( '_wplm_extras' ), true );
		$sold    = $this->line( $base['license_id'], 'base' )->paid_through;
		$this->assertSame( $base['subscription_id'], $snap['target_subscription_id'] );
		$this->assertSame( $sold, $snap['target_paid_through'] );

		$sub = $this->make( SubscriptionRepository::class )->find_by_id( $base['subscription_id'] );
		$this->make( RenewalProcessor::class )->apply_payment( $sub, null, 5000.0 );
		$order['order']->update_status( 'completed' );

		$now = $this->line( $base['license_id'], 'base' )->paid_through;
		$this->assertNotSame( $sold, $now );
		$this->assertSame( $now, $this->line( $base['license_id'], 'factory', 'addon' )->paid_through, 'Aligned with the plan as it is now.' );
		$this->assertStringContainsString( 'the plan renewed after this sale (it was paid through ' . $sold . '). The add-ons are aligned with the plan and cover through ' . $now, $this->notes( $order['order'] ) );
	}

	public function test_another_subscription_or_an_overdue_plan_at_completion_fails_with_a_note(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Monthly' );
		$order   = $this->add_to_licence( $product, $base, array( 'module:factory' => '1' ), false );
		$snap    = json_decode( (string) $order['item']->get_meta( '_wplm_extras' ), true );
		$snap['target_subscription_id'] = 999999;
		$order['item']->update_meta_data( '_wplm_extras', wp_json_encode( $snap ) );
		$order['item']->save();

		$order['order']->update_status( 'completed' );

		$this->assertSame( array(), $this->factory_lines( $base['license_id'] ) );
		$this->assertStringContainsString( 'no longer billed on subscription #999999', $this->notes( $order['order'] ) );
		// Until the shop refunds that line, it still holds Factory: a new order for it is refused.
		wp_set_current_user( $base['customer'] );
		$factory = array(
			'wplm_extras'            => array( 'module:factory' => '1' ),
			'wplm_target_license_id' => (string) $base['license_id'],
		);
		$this->assertFalse( $this->validate( $factory, $product ) );
		$this->assertStringContainsString( 'is still adding Factory', $this->first_error() );
		$this->refund_line( wc_get_order( $order['order']->get_id() ), $order['item'] );

		$overdue = $this->add_to_licence( $product, $base, array( 'module:factory' => '1' ), false );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wplm_entitlements', array( 'paid_through' => EntitlementService::add_period( wp_date( 'Y-m-d' ), -3, 'day' ) ), array( 'license_id' => $base['license_id'] ) );
		$overdue['order']->update_status( 'completed' );
		$this->assertSame( array(), $this->factory_lines( $base['license_id'] ) );
		$this->assertStringContainsString( 'Pay your plan\'s renewal first', $this->notes( $overdue['order'] ) );
	}

	public function test_the_base_is_billed_at_the_price_sold_even_if_the_package_changed_before_completion(): void {
		$bought = $this->buy_new( 'Monthly with fee', array( 'module:factory' => '1' ), null, false );
		$snap   = json_decode( (string) $bought['item']->get_meta( '_wplm_extras' ), true );
		$this->assertEquals( 5000, $snap['base_price'] );
		$this->assertEquals( 2000, $snap['base_signup_fee'] );

		$this->set_row(
			'packages',
			$this->package( 'Monthly with fee' )->id,
			array(
				'price'      => 9999,
				'signup_fee' => 1,
			)
		);
		$bought['order']->update_status( 'completed' );

		$after = $this->result( $bought['order']->get_id() );
		$items = $this->items( $after['subscription_id'] );
		$this->assertSame( 5000.0, $items[0]->line_total, 'The base at the price sold.' );
		$this->assertSame( 6500.0, $this->total( $after['subscription_id'] ), 'Renewal = what the cart showed.' );
		$this->assertEquals( 2000, $this->subscription_row( $after['subscription_id'] )['signup_fee'] );
	}

	public function test_an_addon_plan_with_stored_addons_and_extras_offers_none_and_refuses_both(): void {
		$plan    = $this->create_plan(
			array(
				$this->recurring( 'FBR monthly', 'month', 1000, array( array( 'kind' => 'module', 'code' => 'fbr' ) ) ),
			),
			array(
				'name'    => 'Acme add-ons (mixed)',
				'profile' => 'acme-office',
				'kind'    => Plan::KIND_ADDON,
			)
		);
		$product = $this->create_plan_product( $plan['plan_id'] );
		$base    = $this->customer_with( 'Monthly' );
		wp_set_current_user( $base['customer'] );

		$html = $this->render( $product );
		$this->assertMatchesRegularExpression( '/id="wplm-pkg-none" name="wplm_package_id" value="0"\s+checked/', $html, '"None" is checked when extras are offered.' );
		$this->assertDoesNotMatchRegularExpression( '/name="wplm_package_id" value="' . $plan['packages'][0]->id . '"\s+checked/', $html );

		$both = array(
			'wplm_package_id'        => (string) $plan['packages'][0]->id,
			'wplm_extras'            => array( 'module:factory' => '1' ),
			'wplm_target_license_id' => (string) $base['license_id'],
		);
		$this->assertFalse( $this->validate( $both, $product ) );
		$this->assertSame( 'Choose either an add-on above or the add-ons ticked below, not both. Add them to the cart one at a time.', $this->first_error() );

		$both['wplm_package_id'] = '0';
		$this->assertTrue( $this->validate( $both, $product ), $this->first_error() );
		unset( $both['wplm_extras'] );
		$both['wplm_package_id'] = (string) $plan['packages'][0]->id;
		$this->assertTrue( $this->validate( $both, $product ), 'The stored add-on alone still sells.' );
	}

	public function test_a_partial_refund_changes_no_line_and_says_so(): void {
		$bought = $this->buy_new( 'Monthly', array( 'module:factory' => '1' ) );

		$refund = wc_create_refund(
			array(
				'order_id' => $bought['order']->get_id(),
				'amount'   => 100,
				'reason'   => 'Goodwill',
			)
		);

		$this->assertNotWPError( $refund );
		$this->assertCount( 1, $this->factory_lines( $bought['license_id'] ) );
		$this->assertSame( 6500.0, $this->total( $bought['subscription_id'] ) );
		$this->assertStringContainsString( 'WPLM: partial refund — the licence and its add-on lines are unchanged.', $this->notes( $bought['order'] ) );
	}

	public function test_a_refund_of_a_mixed_order_words_each_licence_correctly(): void {
		$product  = $this->addon_product();
		$base     = $this->customer_with( 'Lifetime' );
		$customer = $base['customer'];
		wp_set_current_user( $customer );

		$_POST = array(
			'wplm_package_id' => (string) $this->package( 'Lifetime' )->id,
			'wplm_extras'     => array( 'module:factory' => '1' ),
		);
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product->get_id(), 1 ) );
		$this->assertNotFalse( WC()->cart->add_to_cart( $this->product->get_id(), 1 ) );
		$_POST = array(
			'wplm_extras'            => array( 'module:pos' => '0', 'user' => '2' ),
			'wplm_target_license_id' => (string) $base['license_id'],
		);
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 1 ), $this->first_error() );
		$this->assertNotFalse( WC()->cart->add_to_cart( $product->get_id(), 1 ) );
		$_POST = array();
		WC()->cart->calculate_totals();
		$order = new \WC_Order();
		$order->set_customer_id( $customer );
		WC()->checkout()->create_order_line_items( $order, WC()->cart );
		$order->set_billing_email( 'customer' . $customer . '@example.org' );
		$order->calculate_totals();
		$order->save();
		WC()->cart->empty_cart();
		$order->update_status( 'completed' );
		$this->assertSame( 2, $this->licence_count( $customer ) );

		wc_get_order( $order->get_id() )->update_status( 'refunded' );

		$notes = $this->notes( $order );
		$this->assertStringContainsString( 'WPLM: refunded — 1 add-on line bought with the licence removed. The licence issued by this order is suspended.', $notes );
		$this->assertStringContainsString( 'WPLM: refunded — 1 add-on line removed from the licence. The licence itself is unchanged.', $notes );
		$this->assertNotSame( 'suspended', strtolower( $this->make( LicenseRepository::class )->find_by_id( $base['license_id'] )->status_label() ), 'The licence the customer already had is not suspended.' );
		$this->assertSame( array(), $this->lines( $base['license_id'], 'addon' ) );
	}

	public function test_a_switch_to_a_plan_that_grants_an_extra_stops_billing_it(): void {
		$richer = $this->create_plan(
			array(
				$this->recurring(
					'Monthly with Factory',
					'month',
					6500,
					array(
						array( 'kind' => 'module', 'code' => 'base' ),
						array( 'kind' => 'module', 'code' => 'distribution' ),
						array( 'kind' => 'module', 'code' => 'factory' ),
					)
				),
			),
			array( 'profile' => 'acme-office' )
		);
		$bought = $this->buy_new(
			'Monthly',
			array(
				'module:factory' => '1',
				'user'           => '2',
			)
		);
		$factory = $this->line( $bought['license_id'], 'factory', 'addon' );

		// Through the REST endpoint, as an integration switches a plan.
		$request = new \WP_REST_Request( 'POST', '/wplm/v1/subscriptions/' . $bought['subscription_id'] . '/switch' );
		$request->set_param( 'id', $bought['subscription_id'] );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'recurring_total'  => 6500,
					'billing_interval' => 1,
					'billing_period'   => 'month',
					'package_id'       => $richer['packages'][0]->id,
				)
			)
		);
		$result = ( new \WPLM\Rest\Controllers\SubscriptionsController( \WPLM\Plugin::get_instance()->container() ) )->switch_plan( $request );

		$this->assertNotWPError( $result );
		$this->assertSame( 7100.0, $this->total( $bought['subscription_id'] ), 'The new plan 6500 (Factory included) + 2 users × 300: Factory is not billed twice.' );
		$this->assertCount( 2, $this->items( $bought['subscription_id'] ) );
		$kept = $this->make( EntitlementRepository::class )->find_by_id( $factory->id );
		$this->assertNotNull( $kept, 'The licence keeps Factory.' );
		$this->assertSame( 'plan', $kept->source );
		$this->assertNull( $kept->order_id );
		$this->assertSame( $bought['subscription_id'], $kept->subscription_id, 'Renewed with the plan.' );

		// A refund of the original order no longer takes Factory away: it belongs to the plan now.
		$this->make( AddonService::class )->revert( wc_get_order( $bought['order']->get_id() ) );
		$this->assertNotNull( $this->make( EntitlementRepository::class )->find_by_id( $factory->id ) );
	}

	public function test_the_addon_page_prices_each_licence_once_per_request(): void {
		$addon = $this->addon_product();
		$base  = $this->customer_with( 'Monthly' );
		wp_set_current_user( $base['customer'] );
		$checkout    = $this->checkout();
		$this->calls = array();

		global $product;
		$product = $addon;
		ob_start();
		$checkout->render_addon_notice();
		$checkout->render_package_options();
		$purchasable = $checkout->plan_is_purchasable( false, $addon );
		ob_end_clean();

		$this->assertTrue( $purchasable );
		$this->assertCount( 1, $this->calls, 'The offers for the licence are computed once, not again for the blocked list.' );
	}

	// ---------------------------------------------------------------------------------------------
	// v2: recurring add-ons on a lifetime licence
	// ---------------------------------------------------------------------------------------------

	private function lifetime_ctx(): array {
		return $this->make( PackageExtrasService::class )->context_for_package( $this->make( PlanService::class )->get( $this->plan['plan_id'] ), $this->package( 'Lifetime' ) );
	}

	public function test_recurring_offers_are_accepted_only_on_lifetime_and_never_with_a_unit_price(): void {
		$service = $this->make( PackageExtrasService::class );
		add_filter(
			'wplm_package_extras',
			static function ( $offers ) {
				$base     = array(
					'max_qty' => 1,
					'lines'   => array( array( 'kind' => 'module', 'code' => 'assets' ) ),
				);
				$offers[] = array(
					'id'         => 'both-prices',
					'unit_price' => 100,
					'recurring'  => array( 'month' => 10 ),
				) + $base;
				$offers[] = array(
					'id'         => 'no-cycle-price',
					'unit_price' => null,
					'recurring'  => array(
						'month' => null,
						'year'  => null,
					),
				) + $base;
				$offers[] = array(
					'id'         => 'negative-cycle',
					'unit_price' => null,
					'recurring'  => array( 'month' => -5 ),
				) + $base;
				$offers[] = array(
					'id'         => 'recurring-term',
					'unit_price' => null,
					'recurring'  => array( 'year' => 50 ),
					'term_days'  => 365,
				) + $base;
				return $offers;
			},
			20
		);

		$lifetime = array_column( $service->offers( $this->lifetime_ctx() ), null, 'id' );
		$this->assertSame(
			array(
				'month' => 1000.0,
				'year'  => 10000.0,
			),
			$lifetime['module:fbr']['recurring']
		);
		$this->assertNull( $lifetime['module:fbr']['unit_price'] );
		$this->assertNull( $lifetime['module:factory']['recurring'], 'A lifetime-priced offer carries recurring null.' );
		foreach ( array( 'both-prices', 'no-cycle-price', 'negative-cycle', 'recurring-term' ) as $bad ) {
			$this->assertArrayNotHasKey( $bad, $lifetime );
		}

		// A recurring offer in a monthly context is rejected.
		add_filter(
			'wplm_package_extras',
			static function ( $offers, $ctx ) {
				if ( 'month' === $ctx['billing'] ) {
					$offers[] = array(
						'id'         => 'module:assets',
						'unit_price' => null,
						'recurring'  => array( 'month' => 100 ),
						'max_qty'    => 1,
						'lines'      => array( array( 'kind' => 'module', 'code' => 'assets' ) ),
					);
				}
				return $offers;
			},
			30,
			2
		);
		$monthly = $service->context_for_package( $this->make( PlanService::class )->get( $this->plan['plan_id'] ), $this->package( 'Monthly' ) );
		$this->assertNotContains( 'module:assets', array_column( $service->offers( $monthly ), 'id' ) );
		$this->assertFalse( $this->validate( array( 'wplm_package_id' => (string) $this->package( 'Monthly' )->id, 'wplm_extras' => array( 'module:assets' => '1' ) ) ) );
	}

	public function test_todays_price_is_lifetime_base_and_extras_plus_one_cycle_of_each_recurring_extra(): void {
		$customer = $this->create_customer();
		wp_set_current_user( $customer );
		foreach ( array( 'month' => 146000.0, 'year' => 155000.0 ) as $cycle => $price ) {
			WC()->cart->empty_cart();
			$_POST = array(
				'wplm_package_id'   => (string) $this->package( 'Lifetime' )->id,
				'wplm_extras'       => array(
					'module:factory' => '1',
					'module:fbr'     => '1',
				),
				'wplm_extras_cycle' => $cycle,
			);
			$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product->get_id(), 1 ), $this->first_error() );
			$key = WC()->cart->add_to_cart( $this->product->get_id(), 1 );
			$_POST = array();
			WC()->cart->calculate_totals();
			$line = WC()->cart->get_cart()[ $key ];
			$this->assertSame( $cycle, $line['wplm_extras_cycle'], 'The cart keeps the cycle.' );
			$this->assertSame( $price, (float) $line['data']->get_price(), "100000 + Factory 45000 + FBR one {$cycle}." );
		}
	}

	public function test_the_cycle_defaults_to_monthly_unless_a_ticked_extra_is_yearly_only_and_an_unpriced_cycle_is_refused(): void {
		$service = $this->make( PackageExtrasService::class );
		$ctx     = $this->lifetime_ctx();

		$this->assertSame( 'month', $service->choose( $ctx, array( 'module:fbr' => 1 ) )['cycle'] );
		$both = $service->choose(
			$ctx,
			array(
				'module:fbr' => 1,
				'module:pos' => 1,
			)
		);
		$this->assertSame( 'year', $both['cycle'], 'Point of Sale is yearly only.' );
		$this->assertSame( 18000.0, $both['total'] );

		$refused = $service->choose( $ctx, array( 'module:pos' => 1 ), 'month' );
		$this->assertWPError( $refused );
		$this->assertSame( 'Point of Sale cannot be paid monthly. Choose the other option.', $refused->get_error_message() );
	}

	public function test_the_snapshot_records_the_cycle_on_the_line_and_each_item(): void {
		$bought = $this->buy_through_cart(
			$this->product,
			array(
				'wplm_package_id'   => (string) $this->package( 'Lifetime' )->id,
				'wplm_extras'       => array(
					'module:factory' => '1',
					'module:fbr'     => '1',
				),
				'wplm_extras_cycle' => 'year',
			),
			$this->create_customer(),
			false
		);
		$snap   = json_decode( (string) $bought['item']->get_meta( '_wplm_extras' ), true );

		$this->assertSame( 'year', $snap['cycle'] );
		$items = array_column( $snap['items'], null, 'id' );
		$this->assertNull( $items['module:factory']['cycle'] );
		$this->assertSame( 'year', $items['module:fbr']['cycle'] );
		$this->assertEquals( 10000, $items['module:fbr']['unit_price'], 'The cycle price charged.' );
		$this->assertEquals( 155000, $bought['order']->get_total() );
	}

	/** A new lifetime licence bought with Factory (lifetime) and FBR (recurring, on `$cycle`). */
	private function lifetime_with_fbr( string $cycle = 'month', bool $complete = true ): array {
		$customer = $this->create_customer();
		$bought   = $this->buy_through_cart(
			$this->product,
			array(
				'wplm_package_id'   => (string) $this->package( 'Lifetime' )->id,
				'wplm_extras'       => array(
					'module:factory' => '1',
					'module:fbr'     => '1',
				),
				'wplm_extras_cycle' => $cycle,
			),
			$customer,
			$complete
		);
		$bought['customer'] = $customer;
		$bought['addons']   = (int) $bought['item']->get_meta( '_wplm_addons_subscription_id' );
		return $bought;
	}

	private function licence_state( int $license_id ): array {
		$row = $this->license_row( $license_id );
		return array( $row['status'], $row['expires_at'] );
	}

	public function test_a_lifetime_licence_with_a_monthly_extra_gets_one_addons_only_subscription(): void {
		$bought = $this->lifetime_with_fbr( 'month' );

		$this->assertSame( 146000.0, (float) $bought['order']->get_total() );
		$this->assertSame( 1, $this->licence_count( $bought['customer'] ), 'One key.' );
		$this->assertSame( 0, $bought['subscription_id'], 'The licence itself has no subscription.' );
		$this->assertGreaterThan( 0, $bought['addons'] );
		$this->assertSame( '1', (string) $bought['order']->get_meta( '_wplm_plan_fulfilled' ) );

		$sub = $this->make( SubscriptionRepository::class )->find_by_id( $bought['addons'] );
		$this->assertTrue( $sub->is_addons_only() );
		$this->assertSame( $bought['license_id'], $sub->addons_license_id );
		$this->assertNull( $sub->license_id, 'Never bound: its status can never reach the licence.' );
		$this->assertSame( 'active', $sub->status );
		$this->assertSame( 'month', $sub->billing_period );
		$this->assertSame( 1000.0, $sub->recurring_total, 'Only the add-on.' );
		$items = $this->items( $sub->id );
		$this->assertCount( 1, $items );
		$this->assertTrue( $items[0]->meta['addons_only'] );
		$this->assertSame( 'module:fbr', $items[0]->meta['extra_id'] );

		$fbr = $this->line( $bought['license_id'], 'fbr', 'addon' );
		$this->assertSame( $sub->id, $fbr->subscription_id );
		$this->assertSame( EntitlementService::paid_through_for( $sub->next_payment ), $fbr->paid_through, 'Paid through one month from today.' );
		$this->assertSame( array( $fbr->id ), $items[0]->meta['entitlement_ids'] );
		$this->assertNull( $this->line( $bought['license_id'], 'factory', 'addon' )->paid_through, 'The lifetime extra is for life.' );
		foreach ( $this->lines( $bought['license_id'], 'plan' ) as $line ) {
			$this->assertNull( $line->paid_through );
		}
		$this->assertNull( $this->license_row( $bought['license_id'] )['expires_at'] );
	}

	public function test_a_yearly_cycle_bills_yearly(): void {
		$bought = $this->lifetime_with_fbr( 'year' );
		$sub    = $this->make( SubscriptionRepository::class )->find_by_id( $bought['addons'] );

		$this->assertSame( 155000.0, (float) $bought['order']->get_total() );
		$this->assertSame( 'year', $sub->billing_period );
		$this->assertSame( 10000.0, $sub->recurring_total );
		$this->assertSame( EntitlementService::paid_through_for( $sub->next_payment ), $this->line( $bought['license_id'], 'fbr' )->paid_through );
	}

	public function test_the_addons_renewal_charges_only_the_addons_and_extends_only_their_lines(): void {
		$bought = $this->lifetime_with_fbr( 'month' );
		$sub    = $this->make( SubscriptionRepository::class )->find_by_id( $bought['addons'] );
		$state  = $this->licence_state( $bought['license_id'] );
		$before = $this->line( $bought['license_id'], 'fbr' )->paid_through;

		$invoice = $this->make( SelfServiceRenewal::class )->create_renewal_order_object( $sub->id );
		$this->assertSame( 1000.0, (float) $invoice->get_total(), 'Only FBR.' );
		$invoice->update_status( 'completed' );

		$this->assertSame( EntitlementService::add_period( $before, 1, 'month' ), $this->line( $bought['license_id'], 'fbr' )->paid_through );
		$this->assertNull( $this->line( $bought['license_id'], 'factory' )->paid_through );
		foreach ( $this->lines( $bought['license_id'], 'plan' ) as $line ) {
			$this->assertNull( $line->paid_through, 'The lifetime lines never move.' );
		}
		$this->assertSame( $state, $this->licence_state( $bought['license_id'] ), 'The licence is untouched.' );
		$this->assertSame( 1, $this->licence_count( $bought['customer'] ), 'A renewal issues no key.' );
	}

	public function test_a_failed_addons_renewal_lapses_its_lines_after_grace_and_never_the_licence(): void {
		$bought = $this->lifetime_with_fbr( 'month' );
		$state  = $this->licence_state( $bought['license_id'] );
		$sub    = $this->make( SubscriptionRepository::class )->find_by_id( $bought['addons'] );
		$dunning = $this->make( \WPLM\Services\Subscriptions\DunningManager::class );
		for ( $i = 0; $i <= count( $dunning->retry_schedule() ); $i++ ) {
			$dunning->handle_failure( $this->make( SubscriptionRepository::class )->find_by_id( $sub->id ) );
		}
		$this->assertSame( 'on-hold', $this->subscription_row( $sub->id )['status'] );

		// Time passes beyond the paid term and grace.
		$this->set_row( 'entitlements', $this->line( $bought['license_id'], 'fbr' )->id, array( 'paid_through' => EntitlementService::add_period( wp_date( 'Y-m-d' ), -30, 'day' ) ) );
		$profile = $this->make( ProfileRegistry::class )->get( 'acme-office' );
		$summary = EntitlementService::summarize_lines( $this->lines( $bought['license_id'] ), $profile, wp_date( 'Y-m-d' ), $profile->grace_days() );
		$this->assertSame( EntitlementService::STATE_READ_ONLY, EntitlementService::module_state( $summary['modules']['fbr']['until'], wp_date( 'Y-m-d' ), $profile->grace_days() ), 'FBR lapses.' );
		$this->assertSame( EntitlementService::STATE_LIFETIME, EntitlementService::module_state( $summary['modules']['base']['until'], wp_date( 'Y-m-d' ), $profile->grace_days() ) );
		$this->assertSame( $state, $this->licence_state( $bought['license_id'] ), 'The licence is still as it was.' );
	}

	public function test_cancelling_expiring_or_refunding_the_addons_subscription_never_touches_the_licence(): void {
		$bought  = $this->lifetime_with_fbr( 'month' );
		$state   = $this->licence_state( $bought['license_id'] );
		$service = $this->make( SubscriptionService::class );

		$service->pause( $bought['addons'] );
		$service->resume( $bought['addons'] );
		$service->cancel( $bought['addons'], true );
		$service->update_status( $bought['addons'], 'expired' );
		$this->assertSame( $state, $this->licence_state( $bought['license_id'] ) );

		$service->update_status( $bought['addons'], 'active' );
		$request = new \WP_REST_Request( 'POST', '/wplm/v1/subscriptions/' . $bought['addons'] . '/cancel' );
		$request->set_param( 'id', $bought['addons'] );
		( new \WPLM\Rest\Controllers\SubscriptionsController( \WPLM\Plugin::get_instance()->container() ) )->cancel( $request );
		$this->assertSame( 'cancelled', $this->subscription_row( $bought['addons'] )['status'] );
		$this->assertSame( $state, $this->licence_state( $bought['license_id'] ) );

		// A refunded renewal of the add-ons subscription.
		$service->update_status( $bought['addons'], 'active' );
		$invoice = $this->make( SelfServiceRenewal::class )->create_renewal_order_object( $bought['addons'] );
		$invoice->update_status( 'completed' );
		$invoice->update_status( 'refunded' );
		$this->assertSame( $state, $this->licence_state( $bought['license_id'] ) );
		$this->assertNotNull( $this->line( $bought['license_id'], 'fbr', 'addon' ), 'A renewal refund removes nothing either.' );

		$this->assertFalse( $service->bind_license( $bought['addons'], $bought['license_id'] ), 'It can never be bound.' );
		$this->assertNull( $this->subscription_row( $bought['addons'] )['license_id'] );
	}

	public function test_a_full_refund_of_the_original_order_suspends_the_licence_and_cancels_the_addons(): void {
		$bought = $this->lifetime_with_fbr( 'month' );

		$bought['order']->update_status( 'refunded' );

		$this->assertSame( 'suspended', strtolower( $this->make( LicenseRepository::class )->find_by_id( $bought['license_id'] )->status_label() ) );
		$this->assertSame( 'cancelled', $this->subscription_row( $bought['addons'] )['status'] );
		$this->assertSame( array(), $this->lines( $bought['license_id'], 'addon' ) );
	}

	public function test_completing_again_creates_no_second_key_or_addons_subscription(): void {
		$bought = $this->lifetime_with_fbr( 'month' );
		$order  = $bought['order'];
		$order->delete_meta_data( '_wplm_plan_fulfilled' );
		$order->save();

		$this->complete_again( $order );

		$this->assertSame( 1, $this->licence_count( $bought['customer'] ) );
		$this->assertCount( 1, $this->make( SubscriptionRepository::class )->get_addons_for_license( $bought['license_id'] ) );
		$this->assertCount( 1, array_filter( $this->lines( $bought['license_id'], 'addon' ), static fn( $l ) => 'fbr' === $l->code ) );
	}

	public function test_an_interrupted_addons_step_reuses_its_subscription_on_retry(): void {
		$this->break_next = true;
		$bought           = $this->lifetime_with_fbr( 'month' );
		$this->assertSame( array(), $this->lines( $bought['license_id'], 'addon' ) );
		$this->assertSame( 'pending', $this->subscription_row( $bought['addons'] )['status'], 'It bills nothing yet.' );

		$this->complete_again( $bought['order'] );

		$this->assertCount( 1, $this->make( SubscriptionRepository::class )->get_addons_for_license( $bought['license_id'] ) );
		$this->assertSame( 'active', $this->subscription_row( $bought['addons'] )['status'] );
		$this->assertSame( 1000.0, $this->total( $bought['addons'] ) );
	}

	public function test_an_existing_lifetime_licence_gets_an_addons_subscription_then_joins_it_at_its_cycle(): void {
		add_filter(
			'wplm_package_extras',
			static function ( $offers, $ctx ) {
				if ( 'lifetime' === $ctx['billing'] ) {
					$offers[] = array(
						'id'         => 'seat-plan',
						'label'      => 'Extra seat (billed)',
						'group'      => 'quantity',
						'unit_price' => null,
						'recurring'  => array(
							'month' => 300,
							'year'  => 3000,
						),
						'max_qty'    => 5,
						'lines'      => array( array( 'kind' => 'limit', 'code' => 'seats', 'qty' => 1 ) ),
					);
				}
				return $offers;
			},
			20,
			2
		);
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );

		// Without an add-ons subscription: one is opened.
		$first = $this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );
		$this->assertSame( 1000.0, (float) $first['order']->get_total(), 'No base: just one month of FBR.' );
		$this->assertNull( json_decode( (string) $first['item']->get_meta( '_wplm_extras' ), true )['addons_subscription_id'] );
		$addons = $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] );
		$this->assertNotNull( $addons );
		$this->assertSame( 'month', $addons->billing_period );
		$fbr = $this->line( $base['license_id'], 'fbr', 'addon' );
		$this->assertSame( $addons->id, $fbr->subscription_id );

		// With one: the cycle is forced to it, and new add-ons join it, aligned.
		$seen = array();
		add_action(
			'wplm_package_extras_form',
			static function ( $data ) use ( &$seen ) {
				$seen[] = $data;
			}
		);
		wp_set_current_user( $base['customer'] );
		$this->render( $product );
		$this->assertSame( array( $base['license_id'] => 'month' ), $seen[0]['cycle_by_license'] );
		$this->assertNotContains( 'module:fbr', array_column( $seen[0]['offers_by_license'][ $base['license_id'] ], 'id' ), 'FBR is held now.' );

		$second = $this->buy_through_cart(
			$product,
			array(
				'wplm_extras'            => array( 'seat-plan' => '2' ),
				'wplm_extras_cycle'      => 'year',
				'wplm_target_license_id' => (string) $base['license_id'],
			),
			$base['customer']
		);
		$this->assertSame( 600.0, (float) $second['order']->get_total(), 'Forced monthly although yearly was posted (yearly would be 6000).' );
		$this->assertSame( 'month', json_decode( (string) $second['item']->get_meta( '_wplm_extras' ), true )['cycle'] );
		$this->assertSame( $addons->id, json_decode( (string) $second['item']->get_meta( '_wplm_extras' ), true )['addons_subscription_id'] );
		$this->assertCount( 1, $this->make( SubscriptionRepository::class )->get_addons_for_license( $base['license_id'] ) );
		$this->assertSame( $fbr->paid_through, $this->line( $base['license_id'], 'seats', 'addon' )->paid_through, 'Aligned.' );
		$this->assertSame( 1600.0, $this->total( $addons->id ) );
		$this->assertCount( 2, $this->items( $addons->id ) );
		$this->assertNotSame( 'suspended', strtolower( $this->make( LicenseRepository::class )->find_by_id( $base['license_id'] )->status_label() ) );
	}

	public function test_a_posted_cycle_is_overridden_by_the_addons_subscription(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );
		wp_set_current_user( $base['customer'] );

		// Point of Sale is yearly only; the licence's add-ons are monthly, so it cannot be added.
		$this->assertFalse(
			$this->validate(
				array(
					'wplm_extras'            => array( 'module:pos' => '1' ),
					'wplm_extras_cycle'      => 'year',
					'wplm_target_license_id' => (string) $base['license_id'],
				),
				$product
			)
		);
		$this->assertSame( 'Point of Sale cannot be paid monthly. Choose the other option.', $this->first_error() );
	}

	public function test_refunding_the_order_that_opened_the_addons_subscription_ends_it_and_keeps_the_licence(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$state   = $this->licence_state( $base['license_id'] );
		$order   = $this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );
		$addons  = $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] );

		$order['order']->update_status( 'refunded' );

		$this->assertSame( 'cancelled', $this->subscription_row( $addons->id )['status'], 'Nothing left to bill.' );
		$this->assertSame( $state, $this->licence_state( $base['license_id'] ) );
		$this->assertNull( $this->line( $base['license_id'], 'fbr', 'addon' ) );
	}

	public function test_the_admin_and_my_account_label_the_addons_subscription(): void {
		$bought = $this->lifetime_with_fbr( 'month' );
		$sub    = $this->make( SubscriptionRepository::class )->find_by_id( $bought['addons'] );
		$tail   = strtoupper( substr( $this->make( LicenseRepository::class )->find_by_id( $bought['license_id'] )->license_key, -4 ) );

		$this->assertSame( 'Add-ons for licence ending ' . $tail, AddonService::label_for( $sub ) );

		ob_start();
		$this->make( \WPLM\Admin\Screens\EntitlementPanel::class )->render( $this->make( LicenseRepository::class )->find_by_id( $bought['license_id'] ) );
		$panel = (string) ob_get_clean();
		$this->assertStringContainsString( 'Add-ons billed monthly or yearly', $panel );
		$this->assertStringContainsString( 'subscription #' . $sub->id, $panel );

		ob_start();
		wc_get_template( 'myaccount/wplm-subscriptions.php', array( 'subscriptions' => array( $sub ) ), '', WPLM_PLUGIN_DIR . 'templates/' );
		$this->assertStringContainsString( 'Add-ons for licence ending ' . $tail, (string) ob_get_clean() );
	}

	public function test_refunding_the_order_that_opened_a_shared_addons_subscription_keeps_the_later_addons_billed(): void {
		add_filter(
			'wplm_package_extras',
			static function ( $offers, $ctx ) {
				if ( 'lifetime' === $ctx['billing'] ) {
					$offers[] = array(
						'id'         => 'seat-plan',
						'label'      => 'Extra seat (billed)',
						'unit_price' => null,
						'recurring'  => array(
							'month' => 300,
							'year'  => 3000,
						),
						'max_qty'    => 5,
						'lines'      => array( array( 'kind' => 'limit', 'code' => 'seats', 'qty' => 1 ) ),
					);
				}
				return $offers;
			},
			20,
			2
		);
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$state   = $this->licence_state( $base['license_id'] );
		$first   = $this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );
		$second  = $this->add_to_licence( $product, $base, array( 'seat-plan' => '2' ) );
		$addons  = $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] );
		$this->assertSame( 1600.0, $this->total( $addons->id ) );
		$this->assertSame( $addons->id, (int) $first['item']->get_meta( '_wplm_addons_subscription_id' ), 'The first order opened it.' );

		$first['order']->update_status( 'refunded' );

		$this->assertSame( 'active', $this->subscription_row( $addons->id )['status'], 'The second order\'s seats keep renewing.' );
		$this->assertSame( 600.0, $this->total( $addons->id ) );
		$this->assertNull( $this->line( $base['license_id'], 'fbr', 'addon' ) );
		$this->assertNotNull( $this->line( $base['license_id'], 'seats', 'addon' ) );
		$this->assertSame( $state, $this->licence_state( $base['license_id'] ) );
		unset( $second );
	}

	public function test_the_cancelled_and_expired_emails_say_the_lifetime_licence_keeps_working(): void {
		$bought       = $this->lifetime_with_fbr( 'month' );
		$subscription = $this->make( SubscriptionRepository::class )->find_by_id( $bought['addons'] );
		$license      = null;
		$email_heading = 'Heading';
		$email        = null;
		$sent_to_admin = false;
		foreach ( array( 'subscription-cancelled', 'subscription-expired', 'payment-failed' ) as $template ) {
			$at_period_end      = false;
			$attempts_remaining = 0;
			$retry_date         = '';
			ob_start();
			include WPLM_PLUGIN_DIR . 'templates/emails/' . $template . '.php';
			$body = (string) ob_get_clean();
			$this->assertStringContainsString( 'Your licence itself is for life and keeps working', $body, $template );
			$this->assertStringNotContainsString( 'licenses has been revoked', $body, $template );
			$this->assertStringNotContainsString( 'To continue using the software', $body, $template );
		}
	}

	// ---------------------------------------------------------------------------------------------
	// Review fixes (2026-10-15): retries, add-ons subscription dates, reuse
	// ---------------------------------------------------------------------------------------------

	/** A recurring-only "Extra seat (billed)" offer on lifetime (300 a month, 3000 a year). */
	private function add_seat_plan_offer(): void {
		add_filter(
			'wplm_package_extras',
			static function ( $offers, $ctx ) {
				if ( 'lifetime' === $ctx['billing'] ) {
					$offers[] = array(
						'id'         => 'seat-plan',
						'label'      => 'Extra seat (billed)',
						'group'      => 'quantity',
						'unit_price' => null,
						'recurring'  => array(
							'month' => 300,
							'year'  => 3000,
						),
						'max_qty'    => 5,
						'lines'      => array( array( 'kind' => 'limit', 'code' => 'seats', 'qty' => 1 ) ),
					);
				}
				return $offers;
			},
			20,
			2
		);
	}

	/** @return \WPLM\Models\Entitlement[] The licence's add-on lines for one code. */
	private function addon_lines( int $license_id, string $code ): array {
		return array_values( array_filter( $this->lines( $license_id, 'addon' ), static fn( $l ) => $code === $l->code ) );
	}

	/** Forget that an order was fulfilled, so completing it again retries it. */
	private function unfulfil( \WC_Order $order ): void {
		$order = wc_get_order( $order->get_id() );
		$order->delete_meta_data( '_wplm_plan_fulfilled' );
		$order->save();
	}

	/** A crash right after the add-ons were written: the item was never stamped. */
	private function lose_the_stamp( \WC_Order_Item_Product $item ): void {
		$item = \WC_Order_Factory::get_order_item( $item->get_id() );
		$item->delete_meta_data( '_wplm_entitlement_ids' );
		$item->delete_meta_data( '_wplm_addon_subscription_item_ids' );
		$item->save_meta_data();
	}

	/** @return int[] Subscriptions due for renewal now. */
	private function due_ids(): array {
		return array_map( static fn( $s ) => (int) $s->id, $this->make( SubscriptionRepository::class )->get_due_for_renewal() );
	}

	public function test_a_listener_failing_after_the_addons_are_written_never_bills_them_twice(): void {
		$throw    = true;
		$listener = static function ( $sub, $old, $new ) use ( &$throw ) {
			if ( $throw && $sub->is_addons_only() && 'pending' === $old && 'active' === $new ) {
				$throw = false;
				throw new \RuntimeException( 'listener blew up' );
			}
		};
		add_action( 'wplm_subscription_status_changed', $listener, 10, 3 );
		$bought = $this->lifetime_with_fbr( 'month' );
		remove_action( 'wplm_subscription_status_changed', $listener, 10 );

		$this->assertNotSame( '', (string) \WC_Order_Factory::get_order_item( $bought['item']->get_id() )->get_meta( '_wplm_entitlement_ids' ), 'Stamped as soon as the add-ons are written.' );
		$this->assertSame( 'active', $this->subscription_row( $bought['addons'] )['status'], 'It bills its add-on.' );

		$this->unfulfil( $bought['order'] );
		$this->complete_again( $bought['order'] );

		$this->assertCount( 1, $this->addon_lines( $bought['license_id'], 'fbr' ) );
		$this->assertCount( 1, $this->addon_lines( $bought['license_id'], 'factory' ) );
		$this->assertCount( 1, $this->items( $bought['addons'] ) );
		$this->assertSame( 1000.0, $this->total( $bought['addons'] ), 'Billed once.' );
	}

	public function test_lines_a_crash_left_unstamped_are_written_once_on_retry_new_licence(): void {
		$bought = $this->lifetime_with_fbr( 'month' );
		$this->lose_the_stamp( $bought['item'] );
		$this->unfulfil( $bought['order'] );

		$this->complete_again( $bought['order'] );

		$this->assertCount( 1, $this->addon_lines( $bought['license_id'], 'fbr' ) );
		$this->assertCount( 1, $this->addon_lines( $bought['license_id'], 'factory' ) );
		$this->assertCount( 1, $this->items( $bought['addons'] ) );
		$this->assertSame( 1000.0, $this->total( $bought['addons'] ) );
		$this->assertSame( 'active', $this->subscription_row( $bought['addons'] )['status'] );
		$this->assertSame( '1', (string) wc_get_order( $bought['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
	}

	public function test_lines_a_crash_left_unstamped_are_written_once_on_retry_existing_licence(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$order   = $this->add_to_licence(
			$product,
			$base,
			array(
				'user'       => '2',
				'module:fbr' => '1',
			)
		);
		$addons  = $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] );
		$this->lose_the_stamp( $order['item'] );
		$this->unfulfil( $order['order'] );

		$this->complete_again( $order['order'] );

		$users = $this->addon_lines( $base['license_id'], 'users' );
		$this->assertCount( 1, $users, 'The 2 users paid for, once.' );
		$this->assertSame( 2, $users[0]->qty );
		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'fbr' ) );
		$this->assertCount( 1, $this->items( $addons->id ) );
		$this->assertSame( 1000.0, $this->total( $addons->id ) );
		$this->assertSame( 'active', $this->subscription_row( $addons->id )['status'] );
		$this->assertSame( '1', (string) wc_get_order( $order['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ), 'Not stuck on "already has".' );
	}

	public function test_a_retry_rewrites_only_the_unstamped_line_of_two_for_one_licence(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		wp_set_current_user( $base['customer'] );
		foreach ( array( array( 'module:factory' => '1' ), array( 'user' => '2' ) ) as $extras ) {
			$_POST['wplm_extras']            = $extras;
			$_POST['wplm_target_license_id'] = (string) $base['license_id'];
			$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 1 ) );
			$this->assertNotFalse( WC()->cart->add_to_cart( $product->get_id(), 1 ) );
			unset( $_POST['wplm_extras'], $_POST['wplm_target_license_id'] );
		}
		WC()->cart->calculate_totals();
		$order = new \WC_Order();
		$order->set_customer_id( $base['customer'] );
		WC()->checkout()->create_order_line_items( $order, WC()->cart );
		$order->set_payment_method( 'bacs' );
		$order->calculate_totals();
		$order->save();
		WC()->cart->empty_cart();
		$order->update_status( 'on-hold' );
		$order->update_status( 'completed' );
		$items   = array_values( wc_get_order( $order->get_id() )->get_items() );
		$factory = $this->line( $base['license_id'], 'factory', 'addon' );
		$stamp   = (string) $items[0]->get_meta( '_wplm_entitlement_ids' );
		$this->assertSame( wp_json_encode( array( $factory->id ) ), $stamp );

		$this->lose_the_stamp( $items[1] );
		$this->unfulfil( $order );
		$this->complete_again( $order );

		$this->assertSame( $factory->id, $this->line( $base['license_id'], 'factory', 'addon' )->id, 'The stamped line is kept as it is.' );
		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'factory' ) );
		$this->assertSame( $stamp, (string) \WC_Order_Factory::get_order_item( $items[0]->get_id() )->get_meta( '_wplm_entitlement_ids' ) );
		$users = $this->addon_lines( $base['license_id'], 'users' );
		$this->assertCount( 1, $users, 'The unstamped one is written once.' );
		$this->assertSame( 2, $users[0]->qty );
		$this->assertSame( '1', (string) wc_get_order( $order->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Second fix review (2026-10-15): a retry adopts what a cut-off attempt wrote, never takes it back
	// ---------------------------------------------------------------------------------------------

	public function test_a_retry_refused_after_a_crash_keeps_the_addons_the_customer_has_and_pays_for(): void {
		$this->add_seat_plan_offer();
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );
		$addons = $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] );
		$second = $this->buy_through_cart(
			$product,
			array(
				'wplm_extras'            => array( 'seat-plan' => '2' ),
				'wplm_target_license_id' => (string) $base['license_id'],
			),
			$base['customer']
		);
		$seats = $this->addon_lines( $base['license_id'], 'seats' );
		$this->assertCount( 1, $seats );
		$this->assertSame( 1600.0, $this->total( $addons->id ) );
		$this->lose_the_stamp( $second['item'] );
		$this->unfulfil( $second['order'] );
		// Since then the add-ons renewal fell overdue: new add-ons could not join it now.
		foreach ( $this->lines( $base['license_id'], 'addon' ) as $line ) {
			$this->set_row( 'entitlements', $line->id, array( 'paid_through' => EntitlementService::add_period( wp_date( 'Y-m-d' ), -2, 'day' ) ) );
		}

		$this->complete_again( $second['order'] );

		$this->assertSame( array( $seats[0]->id ), array_map( static fn( $l ) => $l->id, $this->addon_lines( $base['license_id'], 'seats' ) ), 'The seats the customer has are kept, not written again.' );
		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'fbr' ) );
		$this->assertCount( 2, $this->items( $addons->id ), 'Still billed for both.' );
		$this->assertSame( 1600.0, $this->total( $addons->id ) );
		$order = wc_get_order( $second['order']->get_id() );
		$this->assertSame( '1', (string) $order->get_meta( '_wplm_plan_fulfilled' ), 'Recorded, so the order is done.' );
		$this->assertSame( wp_json_encode( array( $seats[0]->id ) ), (string) \WC_Order_Factory::get_order_item( $second['item']->get_id() )->get_meta( '_wplm_entitlement_ids' ) );
		$this->assertStringContainsString( 'nothing was added or billed twice', $this->notes( $order ) );
	}

	public function test_a_new_licence_retry_after_a_crash_keeps_its_addons_while_their_subscription_is_paused(): void {
		$bought = $this->lifetime_with_fbr( 'month' );
		$fbr    = $this->line( $bought['license_id'], 'fbr', 'addon' );
		$this->lose_the_stamp( $bought['item'] );
		$this->unfulfil( $bought['order'] );
		$this->make( SubscriptionService::class )->pause( $bought['addons'] );

		$this->complete_again( $bought['order'] );

		$this->assertSame( $fbr->id, $this->line( $bought['license_id'], 'fbr', 'addon' )->id, 'Kept as it was.' );
		$this->assertCount( 1, $this->addon_lines( $bought['license_id'], 'fbr' ) );
		$this->assertCount( 1, $this->addon_lines( $bought['license_id'], 'factory' ) );
		$this->assertSame( $bought['addons'], (int) $this->line( $bought['license_id'], 'fbr', 'addon' )->subscription_id );
		$this->assertCount( 1, $this->items( $bought['addons'] ) );
		$this->assertSame( 1000.0, $this->total( $bought['addons'] ) );
		$this->assertSame( 'on-hold', $this->subscription_row( $bought['addons'] )['status'], 'The customer\'s pause is left alone.' );
		$this->assertSame( '1', (string) wc_get_order( $bought['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
	}

	public function test_a_crash_before_the_subscription_item_was_attached_bills_the_addon_once(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$order   = $this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );
		$addons  = $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] );
		$fbr     = $this->line( $base['license_id'], 'fbr', 'addon' );
		// The crash came after the line was written, before its subscription item.
		foreach ( $this->items( $addons->id ) as $sub_item ) {
			$this->make( SubscriptionRepository::class )->delete_item( $sub_item->id );
		}
		$this->make( SubscriptionRepository::class )->update( $addons->id, array( 'recurring_total' => 0 ) );
		$this->lose_the_stamp( $order['item'] );
		$this->unfulfil( $order['order'] );

		$this->complete_again( $order['order'] );

		$this->assertSame( $fbr->id, $this->line( $base['license_id'], 'fbr', 'addon' )->id );
		$items = $this->items( $addons->id );
		$this->assertCount( 1, $items, 'The missing item is attached, once.' );
		$this->assertSame( array( $fbr->id ), $items[0]->meta['entitlement_ids'] );
		$this->assertSame( 1000.0, $this->total( $addons->id ) );
		$this->assertSame( wp_json_encode( array( $items[0]->id ) ), (string) \WC_Order_Factory::get_order_item( $order['item']->get_id() )->get_meta( '_wplm_addon_subscription_item_ids' ) );
		$this->assertSame( '1', (string) wc_get_order( $order['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
	}

	public function test_an_attempt_cut_off_part_way_is_left_for_the_owner_and_nothing_is_removed(): void {
		$bought  = $this->lifetime_with_fbr( 'month' );
		$factory = $this->line( $bought['license_id'], 'factory', 'addon' );
		$fbr     = $this->line( $bought['license_id'], 'fbr', 'addon' );
		// Cut off after the first line: the second was never written.
		$this->make( EntitlementRepository::class )->delete( $fbr->id );
		$this->lose_the_stamp( $bought['item'] );
		$this->unfulfil( $bought['order'] );

		$this->complete_again( $bought['order'] );

		$this->assertSame( array( $factory->id ), array_map( static fn( $l ) => $l->id, $this->lines( $bought['license_id'], 'addon' ) ), 'Nothing taken back, nothing written.' );
		$order = wc_get_order( $bought['order']->get_id() );
		$this->assertSame( '', (string) $order->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertStringContainsString( 'cut off part-way', $this->notes( $order ) );
		$this->assertStringContainsString( '#' . $factory->id, $this->notes( $order ) );
	}

	public function test_a_line_retried_beside_another_lines_unrecorded_addons_leaves_them_to_that_line(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		wp_set_current_user( $base['customer'] );
		foreach ( array( array( 'module:factory' => '1' ), array( 'user' => '2' ) ) as $extras ) {
			$_POST['wplm_extras']            = $extras;
			$_POST['wplm_target_license_id'] = (string) $base['license_id'];
			$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 1 ) );
			$this->assertNotFalse( WC()->cart->add_to_cart( $product->get_id(), 1 ) );
			unset( $_POST['wplm_extras'], $_POST['wplm_target_license_id'] );
		}
		WC()->cart->calculate_totals();
		$order = new \WC_Order();
		$order->set_customer_id( $base['customer'] );
		WC()->checkout()->create_order_line_items( $order, WC()->cart );
		$order->set_payment_method( 'bacs' );
		$order->calculate_totals();
		$order->save();
		WC()->cart->empty_cart();
		// The first line fails; the second is written, then the request dies before it is recorded.
		$this->break_next = true;
		$order->update_status( 'on-hold' );
		$order->update_status( 'completed' );
		$items = array_values( wc_get_order( $order->get_id() )->get_items() );
		$this->assertNull( $this->line( $base['license_id'], 'factory', 'addon' ) );
		$users = $this->addon_lines( $base['license_id'], 'users' );
		$this->assertCount( 1, $users );
		$this->lose_the_stamp( $items[1] );

		$this->complete_again( $order );

		$order = wc_get_order( $order->get_id() );
		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'factory' ), 'The first line is written as usual.' );
		$this->assertSame( array( $users[0]->id ), array_map( static fn( $l ) => $l->id, $this->addon_lines( $base['license_id'], 'users' ) ), 'The second line\'s users kept, once.' );
		$this->assertSame( wp_json_encode( array( $users[0]->id ) ), (string) \WC_Order_Factory::get_order_item( $items[1]->get_id() )->get_meta( '_wplm_entitlement_ids' ), 'Adopted by their own line.' );
		$this->assertSame( '1', (string) $order->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertStringNotContainsString( 'cut off part-way', $this->notes( $order ), 'Nobody is told to remove lines that are paid for.' );
	}

	public function test_a_line_cut_off_part_way_beside_another_line_for_the_same_licence_is_refused_and_never_written_twice(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		wp_set_current_user( $base['customer'] );
		// Line 1: a phone (+1 phones, +1 users). Line 2: a user (+1 users).
		foreach ( array( array( 'phone' => '1' ), array( 'user' => '1' ) ) as $extras ) {
			$_POST['wplm_extras']            = $extras;
			$_POST['wplm_target_license_id'] = (string) $base['license_id'];
			$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 1 ) );
			$this->assertNotFalse( WC()->cart->add_to_cart( $product->get_id(), 1 ) );
			unset( $_POST['wplm_extras'], $_POST['wplm_target_license_id'] );
		}
		WC()->cart->calculate_totals();
		$order = new \WC_Order();
		$order->set_customer_id( $base['customer'] );
		WC()->checkout()->create_order_line_items( $order, WC()->cart );
		$order->set_payment_method( 'bacs' );
		$order->calculate_totals();
		$order->save();
		WC()->cart->empty_cart();
		$order->update_status( 'on-hold' );
		$order->update_status( 'completed' );
		$items = array_values( wc_get_order( $order->get_id() )->get_items() );
		// The first attempt died inside line 1, after its phones line: its users line and line 2 never written.
		$repo   = $this->make( EntitlementRepository::class );
		$orphan = null;
		foreach ( $items as $n => $item ) {
			foreach ( json_decode( (string) $item->get_meta( '_wplm_entitlement_ids' ), true ) as $id ) {
				$line = $repo->find_by_id( (int) $id );
				if ( 0 === $n && 'phones' === $line->code ) {
					$orphan = $line->id;
				} else {
					$repo->delete( (int) $id );
				}
			}
			$this->lose_the_stamp( $item );
		}
		$this->unfulfil( $order );

		$this->complete_again( $order );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( array( $orphan ), array_map( static fn( $l ) => $l->id, $this->addon_lines( $base['license_id'], 'phones' ) ), 'Line 1 is not written again over its own leftover.' );
		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'users' ), 'Line 2 is written.' );
		$this->assertSame( '', (string) \WC_Order_Factory::get_order_item( $items[0]->get_id() )->get_meta( '_wplm_entitlement_ids' ), 'Line 1 is refused.' );
		$this->assertNotSame( '', (string) \WC_Order_Factory::get_order_item( $items[1]->get_id() )->get_meta( '_wplm_entitlement_ids' ) );
		$this->assertSame( '', (string) $fresh->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertStringContainsString( 'cut off part-way', $this->notes( $fresh ) );
		$this->assertStringContainsString( '#' . $orphan, $this->notes( $fresh ) );
		$this->assertStringNotContainsString( 'the customer keeps those lines', $this->notes( $fresh ) );

		// The owner does what the note says.
		$repo->delete( $orphan );
		$this->complete_again( $order );
		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'phones' ) );
		$this->assertCount( 2, $this->addon_lines( $base['license_id'], 'users' ), 'Exactly what was paid for.' );
		$this->assertSame( '1', (string) wc_get_order( $order->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
	}

	public function test_refunding_the_order_that_opened_an_unbilled_addons_subscription_does_not_strand_another_order_sold_against_it(): void {
		$this->add_seat_plan_offer();
		$product          = $this->addon_product();
		$base             = $this->customer_with( 'Lifetime' );
		$this->break_next = true;
		$first            = $this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );
		$opened           = (int) \WC_Order_Factory::get_order_item( $first['item']->get_id() )->get_meta( '_wplm_addons_subscription_id' );
		$this->assertSame( 'pending', $this->subscription_row( $opened )['status'], 'Opened, its add-ons not written.' );
		$second = $this->add_to_licence( $product, $base, array( 'seat-plan' => '1' ), false );
		$sold   = json_decode( (string) $second['item']->get_meta( '_wplm_extras' ), true );
		$this->assertSame( $opened, (int) $sold['addons_subscription_id'], 'Sold against the pending one.' );

		wc_get_order( $first['order']->get_id() )->update_status( 'refunded' );
		$this->assertSame( 'cancelled', $this->subscription_row( $opened )['status'] );
		$order = wc_get_order( $second['order']->get_id() );
		$order->update_status( 'on-hold' );
		$order->update_status( 'completed' );

		$order = wc_get_order( $second['order']->get_id() );
		$this->assertSame( '1', (string) $order->get_meta( '_wplm_plan_fulfilled' ), 'Paid, so granted.' );
		$seats = $this->addon_lines( $base['license_id'], 'seats' );
		$this->assertCount( 1, $seats );
		$now = $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] );
		$this->assertNotNull( $now );
		$this->assertNotSame( $opened, $now->id );
		$this->assertSame( $now->id, (int) $seats[0]->subscription_id );
		$this->assertSame( 'month', $now->billing_period );
		$this->assertSame( 'active', $this->subscription_row( $now->id )['status'] );
		$this->assertSame( 300.0, $this->total( $now->id ), 'Billed what was sold, once.' );
		$this->assertGreaterThanOrEqual( EntitlementService::add_period( wp_date( 'Y-m-d' ), 27, 'day' ), $seats[0]->paid_through, 'A full cycle, as priced.' );
		$this->assertStringContainsString( 'ended before it billed anything', $this->notes( $order ) );
	}

	public function test_addons_sold_against_a_subscription_that_ended_after_billing_are_still_refused(): void {
		$this->add_seat_plan_offer();
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );
		$addons = $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] );
		$second = $this->add_to_licence( $product, $base, array( 'seat-plan' => '1' ), false );
		$this->make( SubscriptionService::class )->cancel( $addons->id );

		$order = wc_get_order( $second['order']->get_id() );
		$order->update_status( 'on-hold' );
		$order->update_status( 'completed' );

		$this->assertSame( '', (string) wc_get_order( $second['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertCount( 0, $this->addon_lines( $base['license_id'], 'seats' ) );
		$this->assertStringContainsString( 'no longer billed on subscription #' . $addons->id, $this->notes( $order ) );
	}

	/**
	 * Order A opened add-ons subscription S and its write failed; order B, sold against S, wrote its
	 * lines on S and the request died before its subscription item was attached (S still pending).
	 *
	 * @return array{0: array, 1: array, 2: int, 3: array} A, B, S, the licence's customer.
	 */
	private function second_order_cut_off_on_an_unbilled_addons_subscription(): array {
		$this->add_seat_plan_offer();
		$product          = $this->addon_product();
		$base             = $this->customer_with( 'Lifetime' );
		$this->break_next = true;
		$first            = $this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );
		$opened           = (int) \WC_Order_Factory::get_order_item( $first['item']->get_id() )->get_meta( '_wplm_addons_subscription_id' );
		$second           = $this->add_to_licence( $product, $base, array( 'seat-plan' => '1' ) );
		$this->assertSame( $opened, (int) $this->addon_lines( $base['license_id'], 'seats' )[0]->subscription_id );
		// The crash: B's line is on S, its item never attached, S never started.
		$repo = $this->make( SubscriptionRepository::class );
		foreach ( $repo->get_items( $opened ) as $sub_item ) {
			$repo->delete_item( $sub_item->id );
		}
		$repo->update( $opened, array( 'recurring_total' => 0 ) );
		$this->set_row( 'subscriptions', $opened, array( 'status' => 'pending' ) );
		$this->lose_the_stamp( $second['item'] );
		$this->unfulfil( $second['order'] );
		return array( $first, $second, $opened, $base );
	}

	public function test_refunding_the_order_that_opened_an_addons_subscription_never_ends_it_while_another_orders_lines_are_on_it(): void {
		list( $first, $second, $opened, $base ) = $this->second_order_cut_off_on_an_unbilled_addons_subscription();

		wc_get_order( $first['order']->get_id() )->update_status( 'refunded' );
		$this->assertSame( 'pending', $this->subscription_row( $opened )['status'], 'B\'s paid line is on it: it stays open.' );
		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'seats' ) );

		$this->complete_again( $second['order'] );

		$this->assertSame( '1', (string) wc_get_order( $second['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertSame( 'active', $this->subscription_row( $opened )['status'], 'It renews B\'s add-on.' );
		$this->assertCount( 1, $this->items( $opened ) );
		$this->assertSame( 300.0, $this->total( $opened ) );
		$this->assertSame( $opened, $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] )->id );
	}

	public function test_a_retry_never_records_addons_on_an_addons_subscription_that_has_ended(): void {
		list( , $second, $opened, $base ) = $this->second_order_cut_off_on_an_unbilled_addons_subscription();
		$seats                            = $this->addon_lines( $base['license_id'], 'seats' );
		$this->set_row( 'subscriptions', $opened, array( 'status' => 'cancelled' ) );

		$this->complete_again( $second['order'] );

		$order = wc_get_order( $second['order']->get_id() );
		$this->assertSame( '', (string) $order->get_meta( '_wplm_plan_fulfilled' ), 'Never reported as done: the add-on would never renew.' );
		$this->assertSame( '', (string) \WC_Order_Factory::get_order_item( $second['item']->get_id() )->get_meta( '_wplm_entitlement_ids' ) );
		$this->assertCount( 0, $this->items( $opened ), 'Nothing attached to the ended subscription.' );
		$this->assertSame( array( $seats[0]->id ), array_map( static fn( $l ) => $l->id, $this->addon_lines( $base['license_id'], 'seats' ) ), 'Nothing removed, nothing written again.' );
		$this->assertStringContainsString( 'add-ons subscription #' . $opened . ', which has ended', $this->notes( $order ) );
		$this->assertStringNotContainsString( 'nothing was added or billed twice', $this->notes( $order ) );
	}

	public function test_the_first_addons_period_starts_when_the_addons_are_written_not_when_the_subscription_was_opened(): void {
		$this->break_next = true;
		$bought           = $this->lifetime_with_fbr( 'month' );
		$this->assertSame( 'pending', $this->subscription_row( $bought['addons'] )['status'] );
		// The owner retries weeks later.
		$this->set_row( 'subscriptions', $bought['addons'], array( 'next_payment' => $this->utc( -10 * DAY_IN_SECONDS ) ) );

		$this->complete_again( $bought['order'] );

		$today = wp_date( 'Y-m-d' );
		$fbr   = $this->line( $bought['license_id'], 'fbr', 'addon' );
		$this->assertGreaterThanOrEqual( EntitlementService::add_period( $today, 27, 'day' ), $fbr->paid_through, 'A full month from the day it was written.' );
		$this->assertNotContains( $bought['addons'], $this->due_ids(), 'Not billed again at once.' );
		$this->assertSame( EntitlementService::paid_through_for( $this->subscription_row( $bought['addons'] )['next_payment'] ), $fbr->paid_through );
	}

	public function test_a_refunded_order_whose_addons_were_never_written_leaves_no_addons_subscription_behind(): void {
		$product          = $this->addon_product();
		$base             = $this->customer_with( 'Lifetime' );
		$this->break_next = true;
		$first            = $this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );
		$orphan           = (int) \WC_Order_Factory::get_order_item( $first['item']->get_id() )->get_meta( '_wplm_addons_subscription_id' );
		$this->assertSame( 'pending', $this->subscription_row( $orphan )['status'] );

		$first['order']->update_status( 'refunded' );

		$this->assertSame( 'cancelled', $this->subscription_row( $orphan )['status'], 'Nothing was ever billed on it.' );

		// Two months later the customer buys FBR again: a full month from today, not due at once.
		$second = $this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );
		$fbr    = $this->line( $base['license_id'], 'fbr', 'addon' );
		$this->assertSame( '1', (string) $second['order']->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertGreaterThanOrEqual( EntitlementService::add_period( wp_date( 'Y-m-d' ), 27, 'day' ), $fbr->paid_through );
		$this->assertNotContains( $fbr->subscription_id, $this->due_ids() );
	}

	public function test_an_orphaned_pending_addons_subscription_joined_later_starts_its_period_then(): void {
		$product          = $this->addon_product();
		$base             = $this->customer_with( 'Lifetime' );
		$this->break_next = true;
		$first            = $this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );
		$orphan           = (int) \WC_Order_Factory::get_order_item( $first['item']->get_id() )->get_meta( '_wplm_addons_subscription_id' );
		$this->set_row( 'subscriptions', $orphan, array( 'next_payment' => $this->utc( -60 * DAY_IN_SECONDS ) ) );

		$this->add_seat_plan_offer();
		$this->add_to_licence( $product, $base, array( 'seat-plan' => '1' ) );

		$seats = $this->line( $base['license_id'], 'seats', 'addon' );
		$this->assertGreaterThanOrEqual( EntitlementService::add_period( wp_date( 'Y-m-d' ), 27, 'day' ), $seats->paid_through );
		$this->assertNotContains( $seats->subscription_id, $this->due_ids() );
	}

	public function test_a_write_rolled_back_part_way_leaves_its_addons_subscription_reusable(): void {
		global $wpdb;
		$count  = 0;
		$fail   = true;
		$filter = static function ( $query ) use ( &$count, &$fail, $wpdb ) {
			if ( $fail && 0 === stripos( ltrim( $query ), 'INSERT INTO `' . $wpdb->prefix . 'wplm_subscription_items`' ) ) {
				++$count;
				if ( 2 === $count ) {
					$fail = false;
					return 'INSERT INTO `wplm_no_such_table` (x) VALUES (1)';
				}
			}
			return $query;
		};
		add_filter( 'query', $filter );
		$wpdb->suppress_errors( true );
		$customer = $this->create_customer();
		$bought   = $this->buy_through_cart(
			$this->product,
			array(
				'wplm_package_id'   => (string) $this->package( 'Lifetime' )->id,
				'wplm_extras'       => array(
					'module:fbr' => '1',
					'module:pos' => '1',
				),
				'wplm_extras_cycle' => 'year',
			),
			$customer
		);
		remove_filter( 'query', $filter );
		$wpdb->suppress_errors( false );
		$addons = (int) $bought['item']->get_meta( '_wplm_addons_subscription_id' );
		$this->assertSame( array(), $this->lines( $bought['license_id'], 'addon' ), 'Rolled back.' );
		$this->assertNotSame( 'cancelled', $this->subscription_row( $addons )['status'], 'A rollback never ends the subscription it is about to bill.' );

		$this->complete_again( $bought['order'] );

		$fbr = $this->line( $bought['license_id'], 'fbr', 'addon' );
		$sub = $this->subscription_row( $fbr->subscription_id );
		$this->assertSame( 'active', $sub['status'] );
		$this->assertSame( 18000.0, (float) $sub['recurring_total'] );
		$this->set_row( 'subscriptions', $fbr->subscription_id, array( 'next_payment' => $this->utc( -3600 ) ) );
		$this->assertContains( $fbr->subscription_id, $this->due_ids(), 'It renews.' );
	}

	public function test_a_cancelled_addons_subscription_is_never_reused_on_retry(): void {
		$this->break_next = true;
		$bought           = $this->lifetime_with_fbr( 'month' );
		$this->make( SubscriptionService::class )->cancel( $bought['addons'] );

		$this->complete_again( $bought['order'] );

		$fbr = $this->line( $bought['license_id'], 'fbr', 'addon' );
		$this->assertNotSame( $bought['addons'], $fbr->subscription_id, 'A new add-ons subscription.' );
		$this->assertSame( 'active', $this->subscription_row( $fbr->subscription_id )['status'] );
		$this->assertSame( 1000.0, $this->total( $fbr->subscription_id ) );
		$this->assertSame( $fbr->subscription_id, (int) \WC_Order_Factory::get_order_item( $bought['item']->get_id() )->get_meta( '_wplm_addons_subscription_id' ) );
	}

	public function test_a_cancelled_addons_subscription_is_never_reused_on_retry_existing_licence(): void {
		$product          = $this->addon_product();
		$base             = $this->customer_with( 'Lifetime' );
		$this->break_next = true;
		$first            = $this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );
		$opened           = (int) \WC_Order_Factory::get_order_item( $first['item']->get_id() )->get_meta( '_wplm_addons_subscription_id' );
		$this->make( SubscriptionService::class )->cancel( $opened );

		$this->complete_again( $first['order'] );

		$fbr = $this->line( $base['license_id'], 'fbr', 'addon' );
		$this->assertNotNull( $fbr );
		$this->assertNotSame( $opened, $fbr->subscription_id );
		$this->assertSame( 'active', $this->subscription_row( $fbr->subscription_id )['status'] );
	}

	// ---------------------------------------------------------------------------------------------
	// Review fixes (2026-10-15): the add-ons subscription is checked before payment
	// ---------------------------------------------------------------------------------------------

	/** An existing lifetime licence with FBR monthly on its add-ons subscription. */
	private function lifetime_licence_with_addons(): array {
		$this->add_seat_plan_offer();
		$product           = $this->addon_product();
		$base              = $this->customer_with( 'Lifetime' );
		$this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );
		$base['product']   = $product;
		$base['addons']    = $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] )->id;
		$base['fbr_line']  = $this->line( $base['license_id'], 'fbr', 'addon' );
		return $base;
	}

	private function validate_for_licence( array $base, array $extras ): bool {
		wp_set_current_user( $base['customer'] );
		return $this->validate(
			array(
				'wplm_extras'            => $extras,
				'wplm_target_license_id' => (string) $base['license_id'],
			),
			$base['product']
		);
	}

	/** @return string[] Offer ids the product page shows for the licence. */
	private function offered_ids( array $base ): array {
		$targets = $this->make( AddonService::class )->eligible_targets( $base['customer'], $this->make( PlanService::class )->get( (int) $base['product']->get_meta( '_wplm_plan_id' ) ) );
		return array_column( $targets[ $base['license_id'] ]['extras'] ?? array(), 'id' );
	}

	public function test_recurring_addons_are_refused_before_payment_while_the_addons_subscription_is_on_hold_for_payment(): void {
		$base    = $this->lifetime_licence_with_addons();
		$dunning = $this->make( \WPLM\Services\Subscriptions\DunningManager::class );
		for ( $i = 0; $i <= count( $dunning->retry_schedule() ); $i++ ) {
			$dunning->handle_failure( $this->make( SubscriptionRepository::class )->find_by_id( $base['addons'] ) );
		}
		$this->set_row( 'entitlements', $base['fbr_line']->id, array( 'paid_through' => EntitlementService::add_period( wp_date( 'Y-m-d' ), -2, 'day' ) ) );
		$this->assertSame( 'on-hold', $this->subscription_row( $base['addons'] )['status'] );

		$this->assertFalse( $this->validate_for_licence( $base, array( 'seat-plan' => '2' ) ) );
		$this->assertSame( 'Pay the licence\'s add-ons renewal first, then add this.', $this->first_error() );
		$this->assertTrue( $this->validate_for_licence( $base, array( 'module:factory' => '1' ) ), 'A lifetime add-on is still sold: ' . $this->first_error() );
		$this->assertNotContains( 'seat-plan', $this->offered_ids( $base ), 'The page does not offer it either.' );
		$this->assertContains( 'module:factory', $this->offered_ids( $base ) );
	}

	public function test_recurring_addons_are_refused_before_payment_while_the_addons_renewal_is_overdue(): void {
		$base = $this->lifetime_licence_with_addons();
		$this->set_row( 'entitlements', $base['fbr_line']->id, array( 'paid_through' => EntitlementService::add_period( wp_date( 'Y-m-d' ), -2, 'day' ) ) );
		$this->assertSame( 'active', $this->subscription_row( $base['addons'] )['status'] );

		$this->assertFalse( $this->validate_for_licence( $base, array( 'seat-plan' => '1' ) ) );
		$this->assertSame( 'Pay the licence\'s add-ons renewal first, then add this.', $this->first_error() );

		// Already in the cart before it fell due: checkout stops.
		$this->set_row( 'entitlements', $base['fbr_line']->id, array( 'paid_through' => EntitlementService::add_period( wp_date( 'Y-m-d' ), 20, 'day' ) ) );
		$this->assertTrue( $this->validate_for_licence( $base, array( 'seat-plan' => '1' ) ) );
		WC()->cart->add_to_cart( $base['product']->get_id(), 1, 0, array(), array( 'wplm_extras' => array( 'seat-plan' => 1 ), 'wplm_addon_license_id' => $base['license_id'] ) );
		$this->set_row( 'entitlements', $base['fbr_line']->id, array( 'paid_through' => EntitlementService::add_period( wp_date( 'Y-m-d' ), -2, 'day' ) ) );
		wc_clear_notices();
		$this->checkout()->check_cart_extras();
		$this->assertStringContainsString( 'Pay the licence\'s add-ons renewal first', $this->first_error() );
	}

	public function test_recurring_addons_are_refused_while_the_customer_has_paused_the_addons_subscription(): void {
		$base = $this->lifetime_licence_with_addons();
		$this->make( SubscriptionService::class )->pause( $base['addons'] );

		$this->assertFalse( $this->validate_for_licence( $base, array( 'seat-plan' => '1' ) ) );
		$this->assertStringContainsString( 'paused', $this->first_error() );
		$this->assertStringContainsString( 'Resume them', $this->first_error() );
	}

	public function test_recurring_addons_never_join_an_addons_subscription_set_to_end(): void {
		$base = $this->lifetime_licence_with_addons();
		$this->make( SubscriptionService::class )->cancel( $base['addons'], true );
		$this->assertSame( 'pending-cancel', $this->subscription_row( $base['addons'] )['status'] );

		$this->assertFalse( $this->validate_for_licence( $base, array( 'seat-plan' => '1' ) ) );
		$this->assertStringContainsString( 'set to end', $this->first_error() );
		$this->assertTrue( $this->validate_for_licence( $base, array( 'module:factory' => '1' ) ), 'A lifetime add-on is still sold.' );
	}

	public function test_an_addons_subscription_that_ended_its_last_period_no_longer_holds_or_forces_anything(): void {
		$base = $this->lifetime_licence_with_addons();
		$this->make( SubscriptionService::class )->cancel( $base['addons'], true );
		// Its last paid period is over.
		$this->set_row( 'subscriptions', $base['addons'], array( 'next_payment' => $this->utc( -5 * DAY_IN_SECONDS ) ) );
		$this->set_row( 'entitlements', $base['fbr_line']->id, array( 'paid_through' => EntitlementService::add_period( wp_date( 'Y-m-d' ), -6, 'day' ) ) );

		$this->assertNull( $this->make( AddonService::class )->addons_cycle( $this->make( LicenseRepository::class )->find_by_id( $base['license_id'] ) ), 'The customer chooses again.' );
		$this->assertContains( 'module:fbr', $this->offered_ids( $base ), 'FBR can be bought again.' );

		$again = $this->buy_through_cart(
			$base['product'],
			array(
				'wplm_extras'            => array( 'module:fbr' => '1' ),
				'wplm_extras_cycle'      => 'year',
				'wplm_target_license_id' => (string) $base['license_id'],
			),
			$base['customer']
		);
		$this->assertSame( 10000.0, (float) $again['order']->get_total() );
		$this->assertSame( '1', (string) $again['order']->get_meta( '_wplm_plan_fulfilled' ) );
		$open = $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] );
		$this->assertNotSame( $base['addons'], $open->id, 'A new add-ons subscription bills it.' );
		$this->assertSame( 'year', $open->billing_period );
	}

	public function test_fulfilment_refuses_to_join_an_addons_subscription_set_to_end_after_the_sale(): void {
		$base  = $this->lifetime_licence_with_addons();
		$order = $this->buy_through_cart(
			$base['product'],
			array(
				'wplm_extras'            => array( 'seat-plan' => '1' ),
				'wplm_target_license_id' => (string) $base['license_id'],
			),
			$base['customer'],
			false
		);
		$this->make( SubscriptionService::class )->cancel( $base['addons'], true );

		$order['order']->update_status( 'completed' );

		$this->assertNull( $this->line( $base['license_id'], 'seats', 'addon' ) );
		$this->assertStringContainsString( 'set to end', $this->notes( $order['order'] ) );
		$this->assertSame( 1000.0, $this->total( $base['addons'] ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Review fixes (2026-10-15): one cycle per licence in a cart
	// ---------------------------------------------------------------------------------------------

	public function test_two_cart_lines_for_one_licence_cannot_be_billed_on_two_cycles(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		wp_set_current_user( $base['customer'] );
		$monthly = array(
			'wplm_extras'            => array( 'module:fbr' => '1' ),
			'wplm_extras_cycle'      => 'month',
			'wplm_target_license_id' => (string) $base['license_id'],
		);
		$this->assertTrue( $this->validate( $monthly, $product ) );
		WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'wplm_extras' => array( 'module:fbr' => 1 ), 'wplm_extras_cycle' => 'month', 'wplm_addon_license_id' => $base['license_id'] ) );

		$this->assertFalse(
			$this->validate(
				array(
					'wplm_extras'            => array( 'module:pos' => '1' ),
					'wplm_extras_cycle'      => 'year',
					'wplm_target_license_id' => (string) $base['license_id'],
				),
				$product
			),
			'Yearly beside monthly for the same licence: the second could never be added.'
		);
		$this->assertStringContainsString( 'billed every month', $this->first_error() );
		$this->assertTrue(
			$this->validate(
				array(
					'wplm_extras'            => array( 'module:factory' => '1' ),
					'wplm_target_license_id' => (string) $base['license_id'],
				),
				$product
			),
			'A lifetime add-on beside it is fine.'
		);

		// Both already in the cart (added before this check existed): checkout stops.
		WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'wplm_extras' => array( 'module:pos' => 1 ), 'wplm_extras_cycle' => 'year', 'wplm_addon_license_id' => $base['license_id'] ) );
		wc_clear_notices();
		$this->checkout()->check_cart_extras();
		$this->assertStringContainsString( 'billed every month', $this->first_error() );
	}

	public function test_an_order_not_completed_yet_holds_the_licences_addons_cycle_for_the_next_order(): void {
		$this->add_seat_plan_offer();
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		// A bank transfer: placed, waiting for the shop to confirm it. No add-ons subscription yet.
		$first = $this->buy_through_cart(
			$product,
			array(
				'wplm_extras'            => array( 'module:fbr' => '1' ),
				'wplm_extras_cycle'      => 'month',
				'wplm_target_license_id' => (string) $base['license_id'],
			),
			$base['customer'],
			false
		);
		wc_get_order( $first['order']->get_id() )->update_status( 'on-hold' );
		$this->assertNull( $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] ) );

		$seen = array();
		add_action(
			'wplm_package_extras_form',
			static function ( $data ) use ( &$seen ) {
				$seen[] = $data;
			}
		);
		wp_set_current_user( $base['customer'] );
		$this->render( $product );
		$this->assertSame( array( $base['license_id'] => 'month' ), $seen[0]['cycle_by_license'], 'The product page offers the cycle that order bills.' );

		$yearly = array(
			'wplm_extras'            => array( 'seat-plan' => '1' ),
			'wplm_extras_cycle'      => 'year',
			'wplm_target_license_id' => (string) $base['license_id'],
		);
		$this->assertFalse( $this->validate( $yearly, $product ), 'Yearly would be paid for and never added once the monthly order opens the add-ons subscription.' );
		$this->assertStringContainsString( 'order #' . $first['order']->get_order_number() . ', not completed yet', $this->first_error() );
		$this->assertStringContainsString( 'billed every month', $this->first_error() );
		$this->assertTrue( $this->validate( array( 'wplm_extras_cycle' => 'month' ) + $yearly, $product ), 'The same cycle is fine.' );

		// Placed after the yearly line went into the cart: the checkout stops.
		WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'wplm_extras' => array( 'seat-plan' => 1 ), 'wplm_extras_cycle' => 'year', 'wplm_addon_license_id' => $base['license_id'] ) );
		wc_clear_notices();
		$this->checkout()->check_cart_extras();
		$this->assertStringContainsString( 'not completed yet', $this->first_error() );

		// Left unpaid in the session (the gateway abandoned), but no longer this cart's order: WooCommerce
		// would place a new order and leave that one payable, so it still counts.
		WC()->session->set( 'order_awaiting_payment', $first['order']->get_id() );
		wc_clear_notices();
		$this->checkout()->check_cart_extras();
		$this->assertStringContainsString( 'not completed yet', $this->first_error() );
		$this->assertFalse( wc_get_order( $first['order']->get_id() )->has_cart_hash( WC()->cart->get_cart_hash() ) );
		WC()->cart->empty_cart();
		// Its line removed, the cart empty: nothing in the cart is that order's (an unpaid order WooCommerce
		// would still offer to pay).
		wc_get_order( $first['order']->get_id() )->update_status( 'pending' );
		WC()->session->set( 'order_awaiting_payment', $first['order']->get_id() );
		$this->assertFalse( $this->validate( $yearly, $product ) );
		$this->assertStringContainsString( 'not completed yet', $this->first_error() );
		WC()->session->set( 'order_awaiting_payment', null );

		// The order being paid for now is the cart's own (WooCommerce pays that order for this cart).
		WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'wplm_extras' => array( 'module:fbr' => 1 ), 'wplm_extras_cycle' => 'month', 'wplm_addon_license_id' => $base['license_id'] ) );
		$own = wc_get_order( $first['order']->get_id() );
		$own->set_cart_hash( WC()->cart->get_cart_hash() );
		$own->save();
		WC()->session->set( 'order_awaiting_payment', $first['order']->get_id() );
		wc_clear_notices();
		$this->checkout()->check_cart_extras();
		$this->assertSame( '', $this->first_error() );
		WC()->session->set( 'order_awaiting_payment', null );
		WC()->cart->empty_cart();

		// Once that order is cancelled, the customer chooses again.
		wc_get_order( $first['order']->get_id() )->update_status( 'cancelled' );
		$this->assertTrue( $this->validate( $yearly, $product ) );
	}

	public function test_a_completed_order_whose_addons_wait_for_a_retry_still_holds_the_cycle(): void {
		$this->add_seat_plan_offer();
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$yearly  = array(
			'wplm_extras'            => array( 'seat-plan' => '1' ),
			'wplm_extras_cycle'      => 'year',
			'wplm_target_license_id' => (string) $base['license_id'],
		);
		// Completed while another request held the licence: nothing added, no add-ons subscription.
		add_option( 'wplm_addons_lock_' . $base['license_id'], (string) time(), '', 'no' );
		$first = $this->buy_through_cart(
			$product,
			array(
				'wplm_extras'            => array( 'module:fbr' => '1' ),
				'wplm_extras_cycle'      => 'month',
				'wplm_target_license_id' => (string) $base['license_id'],
			),
			$base['customer']
		);
		$this->assertSame( '', (string) wc_get_order( $first['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertNull( $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] ) );

		wp_set_current_user( $base['customer'] );
		$this->assertFalse( $this->validate( $yearly, $product ), 'Its retry will open the add-ons subscription monthly.' );
		$this->assertStringContainsString( 'order #' . $first['order']->get_order_number() . ' are still being added', $this->first_error() );
		$this->assertStringContainsString( 'or contact us', $this->first_error(), 'A completed order cannot be cancelled by the customer.' );

		delete_option( 'wplm_addons_lock_' . $base['license_id'] );
		$this->complete_again( $first['order'] );
		$this->assertSame( 'month', $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] )->billing_period );
		$second = $this->buy_through_cart( $product, $yearly, $base['customer'] );
		$this->assertSame( 300.0, (float) $second['order']->get_total(), 'Forced to the add-ons subscription\'s cycle.' );
		$this->assertSame( '1', (string) wc_get_order( $second['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ), 'And so added.' );
		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'seats' ) );
	}

	public function test_a_line_the_shop_refunded_in_full_no_longer_holds_the_licences_addons_cycle(): void {
		$this->add_seat_plan_offer();
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		// Completed while another request held the licence: nothing added, no add-ons subscription.
		add_option( 'wplm_addons_lock_' . $base['license_id'], (string) time(), '', 'no' );
		$first = $this->buy_through_cart(
			$product,
			array(
				'wplm_extras'            => array( 'module:fbr' => '1' ),
				'wplm_extras_cycle'      => 'month',
				'wplm_target_license_id' => (string) $base['license_id'],
			),
			$base['customer']
		);
		delete_option( 'wplm_addons_lock_' . $base['license_id'] );
		$order  = wc_get_order( $first['order']->get_id() );
		$item   = array_values( $order->get_items() )[0];
		$yearly = array(
			'wplm_extras'            => array( 'seat-plan' => '1' ),
			'wplm_extras_cycle'      => 'year',
			'wplm_target_license_id' => (string) $base['license_id'],
		);
		wp_set_current_user( $base['customer'] );

		// Part of its price refunded: the line is still to be added, so it still holds the cycle.
		$this->assertNotWPError( wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 300 ) ) );
		$this->assertFalse( $this->validate( $yearly, $product ) );

		// The shop refunds the line itself (as its note says): it no longer holds anything.
		$this->assertNotWPError(
			wc_create_refund(
				array(
					'order_id'   => $order->get_id(),
					'amount'     => 200,
					'line_items' => array(
						$item->get_id() => array(
							'qty'          => 1,
							'refund_total' => 200,
						),
					),
				)
			)
		);
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status(), 'A partial refund: the order stays completed.' );
		$this->assertTrue( $this->validate( $yearly, $product ), 'The customer chooses the cycle again.' );
	}

	public function test_a_failed_order_still_holds_the_licences_addons_cycle(): void {
		$this->add_seat_plan_offer();
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$first   = $this->buy_through_cart(
			$product,
			array(
				'wplm_extras'            => array( 'module:fbr' => '1' ),
				'wplm_extras_cycle'      => 'month',
				'wplm_target_license_id' => (string) $base['license_id'],
			),
			$base['customer'],
			false
		);
		// The payment failed: the customer can still pay it from My account.
		wc_get_order( $first['order']->get_id() )->update_status( 'failed' );
		$this->assertTrue( wc_get_order( $first['order']->get_id() )->needs_payment() );

		wp_set_current_user( $base['customer'] );
		$this->assertFalse(
			$this->validate(
				array(
					'wplm_extras'            => array( 'seat-plan' => '1' ),
					'wplm_extras_cycle'      => 'year',
					'wplm_target_license_id' => (string) $base['license_id'],
				),
				$product
			)
		);
		$this->assertStringContainsString( 'order #' . $first['order']->get_order_number() . ', not completed yet', $this->first_error() );
	}

	/**
	 * Pay for an order as WooCommerce's order-pay page and the Store API do: true when nothing refused it.
	 *
	 * @return array{0: string, 1: \WP_Error} The order-pay page's first error, the Store API's errors.
	 */
	private function pay_later( \WC_Order $order ): array {
		wc_clear_notices();
		do_action( 'woocommerce_before_pay_action', wc_get_order( $order->get_id() ) );
		$page   = $this->first_error();
		$errors = new \WP_Error();
		do_action( 'woocommerce_checkout_validate_order_before_payment', wc_get_order( $order->get_id() ), $errors );
		wc_clear_notices();
		return array( $page, $errors );
	}

	public function test_an_order_paid_later_is_refused_payment_when_its_addons_could_no_longer_be_added(): void {
		$this->add_seat_plan_offer();
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$target  = array( 'wplm_target_license_id' => (string) $base['license_id'] );
		$first   = $this->buy_through_cart( $product, array( 'wplm_extras' => array( 'module:fbr' => '1' ), 'wplm_extras_cycle' => 'month' ) + $target, $base['customer'], false );
		// Out of the way while a yearly order opens the licence's add-ons subscription, then payable again
		// (an order placed before this release, or set back by hand).
		wc_get_order( $first['order']->get_id() )->update_status( 'cancelled' );
		$this->buy_through_cart( $product, array( 'wplm_extras' => array( 'seat-plan' => '1' ), 'wplm_extras_cycle' => 'year' ) + $target, $base['customer'] );
		$addons = $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] );
		$this->assertSame( 'year', $addons->billing_period );
		wc_get_order( $first['order']->get_id() )->update_status( 'failed' );

		list( $page, $errors ) = $this->pay_later( $first['order'] );
		$this->assertStringContainsString( 'billed every year', $page, 'Never charged for add-ons that could not be added.' );
		$this->assertStringContainsString( 'order #' . $first['order']->get_order_number(), $page );
		$this->assertTrue( $errors->has_errors(), 'The block checkout\'s order-pay too.' );

		// On the add-ons subscription's cycle: paid as usual.
		$same = $this->buy_through_cart( $product, array( 'wplm_extras' => array( 'module:fbr' => '1' ), 'wplm_extras_cycle' => 'year' ) + $target, $base['customer'], false );
		list( $page, $errors ) = $this->pay_later( $same['order'] );
		$this->assertSame( '', $page );
		$this->assertFalse( $errors->has_errors() );

		// Sold against an add-ons subscription that billed and has ended: it could not be added either.
		$this->make( SubscriptionService::class )->cancel( $addons->id );
		list( $page ) = $this->pay_later( $same['order'] );
		$this->assertStringContainsString( 'subscription #' . $addons->id, $page );
	}

	public function test_an_order_paid_later_is_refused_while_another_paid_order_holds_the_other_cycle(): void {
		$this->add_seat_plan_offer();
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$target  = array( 'wplm_target_license_id' => (string) $base['license_id'] );
		$first   = $this->buy_through_cart( $product, array( 'wplm_extras' => array( 'module:fbr' => '1' ), 'wplm_extras_cycle' => 'month' ) + $target, $base['customer'], false );
		wc_get_order( $first['order']->get_id() )->update_status( 'cancelled' );
		$yearly = $this->buy_through_cart( $product, array( 'wplm_extras' => array( 'seat-plan' => '1' ), 'wplm_extras_cycle' => 'year' ) + $target, $base['customer'], false );
		wc_get_order( $first['order']->get_id() )->update_status( 'failed' );
		// Both unpaid: whichever is paid first decides.
		$this->assertSame( '', $this->pay_later( $first['order'] )[0] );
		$this->assertSame( '', $this->pay_later( $yearly['order'] )[0] );

		wc_get_order( $yearly['order']->get_id() )->update_status( 'processing' );
		list( $page ) = $this->pay_later( $first['order'] );
		$this->assertStringContainsString( 'order #' . $yearly['order']->get_order_number(), $page );
		$this->assertStringContainsString( 'billed every year', $page );
	}

	// ---------------------------------------------------------------------------------------------
	// Review fixes (2026-10-15): one request at a time adds to a licence
	// ---------------------------------------------------------------------------------------------

	public function test_a_licence_another_request_is_adding_to_takes_nothing_until_retried(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		add_option( 'wplm_addons_lock_' . $base['license_id'], (string) time(), '', 'no' );

		$order = $this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );

		$this->assertNull( $this->line( $base['license_id'], 'fbr', 'addon' ), 'Checked and written only under the licence lock.' );
		$this->assertNull( $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] ) );
		$this->assertStringContainsString( 'another order is adding to this licence right now', $this->notes( $order['order'] ) );
		$this->assertSame( '', (string) wc_get_order( $order['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );

		delete_option( 'wplm_addons_lock_' . $base['license_id'] );
		$this->complete_again( $order['order'] );
		$this->assertNotNull( $this->line( $base['license_id'], 'fbr', 'addon' ) );
		$this->assertFalse( get_option( 'wplm_addons_lock_' . $base['license_id'] ), 'Released.' );
	}

	public function test_a_stale_licence_lock_is_taken_over(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		add_option( 'wplm_addons_lock_' . $base['license_id'], (string) ( time() - AddonService::LICENCE_LOCK_TTL - 5 ), '', 'no' );

		$this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );

		$this->assertNotNull( $this->line( $base['license_id'], 'fbr', 'addon' ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Review fixes (2026-10-15): a licence id is only taken from an add-on line, for its owner
	// ---------------------------------------------------------------------------------------------

	public function test_a_licence_type_line_never_records_someone_elses_licence(): void {
		$victim   = $this->customer_with( 'Lifetime' );
		$attacker = $this->create_customer();

		$bought = $this->buy_through_cart(
			$this->product,
			array(
				'wplm_package_id'        => (string) $this->package( 'Monthly' )->id,
				'wplm_target_license_id' => (string) $victim['license_id'],
			),
			$attacker
		);

		$item = \WC_Order_Factory::get_order_item( $bought['item']->get_id() );
		$this->assertSame( '', (string) $item->get_meta( '_wplm_addon_license_id' ), 'The victim\'s licence is not tied to the attacker\'s order.' );
		$this->assertSame( '', (string) $item->get_meta( 'Added to' ), 'Nor shown, with the end of its key.' );
		$this->assertGreaterThan( 0, $bought['license_id'], 'The attacker gets the licence type they paid for.' );

		// A cart line from before this fix still carries the id: the order line ignores it.
		wp_set_current_user( $attacker );
		$order = new \WC_Order();
		$order->set_customer_id( $attacker );
		$line = new \WC_Order_Item_Product();
		$line->set_product( $this->product );
		$this->checkout()->save_order_line_item(
			$line,
			'key',
			array(
				'wplm_package_id'       => $this->package( 'Monthly' )->id,
				'wplm_addon_license_id' => $victim['license_id'],
			),
			$order
		);
		$this->assertSame( '', (string) $line->get_meta( '_wplm_addon_license_id' ) );
		$data = $this->checkout()->display_cart_item_data(
			array(),
			array(
				'wplm_package_id'       => $this->package( 'Monthly' )->id,
				'wplm_addon_license_id' => $victim['license_id'],
			)
		);
		$this->assertNotContains( 'Added to', array_column( $data, 'key' ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Review fixes (2026-10-15): the add-ons renewal invoice names the add-ons
	// ---------------------------------------------------------------------------------------------

	public function test_the_addons_renewal_invoice_lists_the_addons_never_the_lifetime_product(): void {
		$this->add_seat_plan_offer();
		$customer = $this->create_customer();
		$bought   = $this->buy_through_cart(
			$this->product,
			array(
				'wplm_package_id' => (string) $this->package( 'Lifetime' )->id,
				'wplm_extras'     => array(
					'module:fbr' => '1',
					'seat-plan'  => '2',
				),
			),
			$customer
		);
		$addons  = (int) $bought['item']->get_meta( '_wplm_addons_subscription_id' );
		$sub     = $this->make( SubscriptionRepository::class )->find_by_id( $addons );
		$tail    = strtoupper( substr( $this->make( LicenseRepository::class )->find_by_id( $bought['license_id'] )->license_key, -4 ) );

		$invoice = $this->make( SelfServiceRenewal::class )->create_renewal_order_object( $addons );

		$this->assertSame( array(), $invoice->get_items( 'line_item' ), 'Not the lifetime product.' );
		$names = array_values( array_map( static fn( $fee ) => $fee->get_name(), $invoice->get_items( 'fee' ) ) );
		$this->assertSame( array( 'FBR — Add-ons for licence ending ' . $tail, '2 × Extra seat (billed) — Add-ons for licence ending ' . $tail ), $names );
		$this->assertSame( 1600.0, (float) $invoice->get_total() );
		$this->assertSame( $sub->recurring_total, (float) $invoice->get_total() );

		foreach ( $invoice->get_items( 'fee' ) as $fee ) {
			$this->assertSame( $this->product->get_tax_status(), $fee->get_tax_status(), 'Taxed as the product it was bought on.' );
		}

		$before = $this->line( $bought['license_id'], 'fbr' )->paid_through;
		$invoice->update_status( 'completed' );
		$this->assertSame( EntitlementService::add_period( $before, 1, 'month' ), $this->line( $bought['license_id'], 'fbr' )->paid_through, 'Paying it still renews them.' );
	}

	public function test_an_addons_invoice_is_not_taxed_when_its_product_is_not(): void {
		$this->product->set_tax_status( 'none' );
		$this->product->save();
		$bought = $this->lifetime_with_fbr( 'month' );

		$invoice = $this->make( SelfServiceRenewal::class )->create_renewal_order_object( $bought['addons'] );

		foreach ( $invoice->get_items( 'fee' ) as $fee ) {
			$this->assertSame( 'none', $fee->get_tax_status() );
		}
	}

	// ---------------------------------------------------------------------------------------------
	// Review fixes (2026-10-15): a draft is not for sale
	// ---------------------------------------------------------------------------------------------

	public function test_a_draft_plan_product_cannot_be_bought_until_it_is_published(): void {
		$draft = $this->create_plan_product( $this->plan['plan_id'] );
		$draft->set_status( 'draft' );
		$draft->save();
		$addons = $this->addon_product();
		$addons->set_status( 'draft' );
		$addons->save();
		$base = $this->customer_with( 'Lifetime' );

		foreach ( array( 0, $base['customer'] ) as $visitor ) {
			wp_set_current_user( $visitor );
			$this->assertFalse( wc_get_product( $draft->get_id() )->is_purchasable(), 'Draft licence product, visitor ' . $visitor );
			$this->assertFalse( wc_get_product( $addons->get_id() )->is_purchasable(), 'Draft add-ons product, visitor ' . $visitor );
		}
		$_POST['wplm_package_id'] = (string) $this->package( 'Lifetime' )->id;
		$this->assertFalse( WC()->cart->add_to_cart( $draft->get_id(), 1 ), 'Not even through ?add-to-cart=.' );
		unset( $_POST['wplm_package_id'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( wc_get_product( $draft->get_id() )->is_purchasable(), 'The owner can test it.' );

		$draft->set_status( 'publish' );
		$draft->save();
		wp_set_current_user( $base['customer'] );
		$this->assertTrue( wc_get_product( $draft->get_id() )->is_purchasable(), 'Published: for sale, priced by its plan.' );
	}

	// ---------------------------------------------------------------------------------------------
	// Review fixes (2026-10-15): an add-ons-only subscription has no plan to switch
	// ---------------------------------------------------------------------------------------------

	public function test_an_addons_only_subscription_cannot_be_switched(): void {
		$bought = $this->lifetime_with_fbr( 'month' );
		$items  = $this->items( $bought['addons'] );

		$result = $this->make( SwitchManager::class )->switch_plan(
			$bought['addons'],
			array(
				'product_id'       => $this->product->get_id(),
				'recurring_total'  => 5000,
				'billing_interval' => 1,
				'billing_period'   => 'month',
				'modules'          => array( 'fbr' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wplm_switch_addons_only', $result->get_error_code() );
		$this->assertSame( 1000.0, $this->total( $bought['addons'] ), 'No plan charge added.' );
		$this->assertEquals( $items, $this->items( $bought['addons'] ) );
		$this->assertSame( 'addon', $this->line( $bought['license_id'], 'fbr' )->source, 'FBR stays an add-on it bills.' );
	}

	// ---------------------------------------------------------------------------------------------
	// Review fixes (2026-10-15): WPLM's own extras form
	// ---------------------------------------------------------------------------------------------

	public function test_the_fallback_scripts_only_touch_their_own_form(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		wp_set_current_user( $base['customer'] );

		foreach ( array( $this->render( $this->product ), $this->render( $product ) ) as $html ) {
			$this->assertStringContainsString( ".closest('form')", $html );
			$this->assertStringNotContainsString( 'document.querySelectorAll', $html, 'Nothing page-wide: another product\'s form on the same page is left alone.' );
			$this->assertStringNotContainsString( "document.getElementById('wplm-target-license')", $html );
		}
		$this->assertNotSame( $this->render( $this->product ), $this->render( $this->product ), 'Each form has its own id.' );
	}

	public function test_only_the_shown_licence_has_a_cycle_checked_in_the_fallback(): void {
		$product = $this->addon_product();
		$first   = $this->customer_with( 'Lifetime' );
		$second  = $this->buy( $this->product, $this->package( 'Lifetime' ), $first['customer'] );
		wp_set_current_user( $first['customer'] );

		$html = $this->render( $product );

		$this->assertSame( 2, substr_count( $html, 'class="wplm-extras" data-wplm-for=' ), 'Both licences take recurring add-ons.' );
		$this->assertSame( 1, preg_match_all( '/<input [^>]*name="wplm_extras_cycle"[^>]*checked/', $html ), 'One checked radio in the form\'s one radio group.' );
		$this->assertMatchesRegularExpression( '/id="wplm-extras-cycle-' . $first['license_id'] . '-year" name="wplm_extras_cycle" value="year" data-wplm-default="1"\s+checked/', $html, 'The shown (first) licence\'s default.' );
		$this->assertMatchesRegularExpression( '/id="wplm-extras-cycle-' . $second['license_id'] . '-year" name="wplm_extras_cycle" value="year" data-wplm-default="1"\s*>/', $html, 'The other licence\'s default is marked, for the script to check when it is shown.' );
	}

	public function test_the_addons_page_with_nothing_chosen_asks_for_an_addon_not_a_licence_type(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		wp_set_current_user( $base['customer'] );

		$this->assertFalse(
			$this->validate(
				array(
					'wplm_package_id'        => '0',
					'wplm_extras'            => array( 'user' => '0' ),
					'wplm_target_license_id' => (string) $base['license_id'],
				),
				$product
			)
		);
		$this->assertSame( 'Choose an add-on, or tick at least one add-on, before adding to cart.', $this->first_error() );
	}

	// ---------------------------------------------------------------------------------------------
	// Final review (2026-10-15): a line refunded in full is never added; an order paid later is checked
	// as fulfilment will check it; a refund never leaves an empty pending add-ons subscription behind
	// ---------------------------------------------------------------------------------------------

	/**
	 * One order for a lifetime licence with two lines: FBR billed every month, and a user for life.
	 *
	 * @return \WC_Order Not paid yet.
	 */
	private function fbr_and_user_order( \WC_Product $product, array $base ): \WC_Order {
		wp_set_current_user( $base['customer'] );
		$lines = array(
			array(
				'wplm_extras'       => array( 'module:fbr' => '1' ),
				'wplm_extras_cycle' => 'month',
			),
			array( 'wplm_extras' => array( 'user' => '1' ) ),
		);
		foreach ( $lines as $post ) {
			$post['wplm_target_license_id'] = (string) $base['license_id'];
			foreach ( $post as $field => $value ) {
				$_POST[ $field ] = $value;
			}
			$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 1 ) );
			$this->assertNotFalse( WC()->cart->add_to_cart( $product->get_id(), 1 ) );
			foreach ( array_keys( $post ) as $field ) {
				unset( $_POST[ $field ] );
			}
		}
		WC()->cart->calculate_totals();
		$order = new \WC_Order();
		$order->set_customer_id( $base['customer'] );
		WC()->checkout()->create_order_line_items( $order, WC()->cart );
		$order->set_payment_method( 'bacs' );
		$order->calculate_totals();
		$order->save();
		WC()->cart->empty_cart();
		return $order;
	}

	/** The shop refunds one order line in full, as WPLM's refusal note tells it to. */
	private function refund_line( \WC_Order $order, \WC_Order_Item_Product $item ): void {
		$total = (float) $item->get_total();
		$this->assertNotWPError(
			wc_create_refund(
				array(
					'order_id'   => $order->get_id(),
					'amount'     => $total,
					'line_items' => array(
						$item->get_id() => array(
							'qty'          => 1,
							'refund_total' => $total,
						),
					),
				)
			)
		);
	}

	public function test_a_line_refunded_in_full_is_never_added_when_the_order_is_completed_again(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$order   = $this->fbr_and_user_order( $product, $base );
		// Completed while another request held the licence: both lines refused.
		add_option( 'wplm_addons_lock_' . $base['license_id'], (string) time(), '', 'no' );
		$order->update_status( 'on-hold' );
		$order->update_status( 'completed' );
		delete_option( 'wplm_addons_lock_' . $base['license_id'] );
		$order = wc_get_order( $order->get_id() );
		$items = array_values( $order->get_items() );
		$this->refund_line( $order, $items[0] );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );

		// The owner sets it to Completed again, for the user line.
		$this->complete_again( $order );

		$order = wc_get_order( $order->get_id() );
		$this->assertCount( 0, $this->addon_lines( $base['license_id'], 'fbr' ), 'Refunded: never added.' );
		$this->assertNull( $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] ), 'Nothing billed every month for it.' );
		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'users' ), 'The line paid for is added.' );
		$this->assertSame( '', (string) \WC_Order_Factory::get_order_item( $items[0]->get_id() )->get_meta( '_wplm_entitlement_ids' ) );
		$this->assertSame( '1', (string) $order->get_meta( '_wplm_plan_fulfilled' ), 'Nothing left to add.' );
		$this->assertStringContainsString( 'refunded in full', $this->notes( $order ) );
	}

	public function test_a_line_refunded_in_full_before_completion_leaves_the_cycle_to_the_next_order(): void {
		$this->add_seat_plan_offer();
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$first   = $this->fbr_and_user_order( $product, $base );
		$first->update_status( 'processing' );
		$first = wc_get_order( $first->get_id() );
		$this->refund_line( $first, array_values( $first->get_items() )[0] );

		// The monthly line is gone: the customer chooses the cycle again.
		$yearly = array(
			'wplm_extras'            => array( 'seat-plan' => '1' ),
			'wplm_extras_cycle'      => 'year',
			'wplm_target_license_id' => (string) $base['license_id'],
		);
		$second = $this->buy_through_cart( $product, $yearly, $base['customer'], false );
		wc_get_order( $second['order']->get_id() )->update_status( 'processing' );

		wc_get_order( $first->get_id() )->update_status( 'completed' );
		wc_get_order( $second['order']->get_id() )->update_status( 'completed' );

		$this->assertCount( 0, $this->addon_lines( $base['license_id'], 'fbr' ), 'Refunded: never added.' );
		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'users' ) );
		$seats = $this->addon_lines( $base['license_id'], 'seats' );
		$this->assertCount( 1, $seats, 'The yearly seat paid for is added.' );
		$addons = $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] );
		$this->assertSame( 'year', $addons->billing_period );
		$this->assertSame( (int) $addons->id, (int) $seats[0]->subscription_id );
		$this->assertSame( '1', (string) wc_get_order( $first->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertSame( '1', (string) wc_get_order( $second['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
	}

	public function test_a_line_refunded_in_full_never_claims_what_an_interrupted_attempt_left_on_the_licence(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		wp_set_current_user( $base['customer'] );
		foreach ( array( array( 'module:factory' => '1' ), array( 'user' => '2' ) ) as $extras ) {
			$_POST['wplm_extras']            = $extras;
			$_POST['wplm_target_license_id'] = (string) $base['license_id'];
			$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 1 ) );
			$this->assertNotFalse( WC()->cart->add_to_cart( $product->get_id(), 1 ) );
			unset( $_POST['wplm_extras'], $_POST['wplm_target_license_id'] );
		}
		WC()->cart->calculate_totals();
		$order = new \WC_Order();
		$order->set_customer_id( $base['customer'] );
		WC()->checkout()->create_order_line_items( $order, WC()->cart );
		$order->set_payment_method( 'bacs' );
		$order->calculate_totals();
		$order->save();
		WC()->cart->empty_cart();
		// The first line fails; the second is written, then the request dies before it is recorded.
		$this->break_next = true;
		$order->update_status( 'on-hold' );
		$order->update_status( 'completed' );
		$items = array_values( wc_get_order( $order->get_id() )->get_items() );
		$users = $this->addon_lines( $base['license_id'], 'users' );
		$this->assertCount( 1, $users );
		$this->lose_the_stamp( $items[1] );
		// The shop refunds the second line in full.
		$this->refund_line( wc_get_order( $order->get_id() ), $items[1] );

		$this->complete_again( $order );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( array( $users[0]->id ), array_map( static fn( $l ) => $l->id, $this->addon_lines( $base['license_id'], 'users' ) ), 'Nothing removed automatically.' );
		$this->assertCount( 0, $this->addon_lines( $base['license_id'], 'factory' ), 'The leftovers are no line\'s now: nothing is written beside them.' );
		$this->assertSame( '', (string) \WC_Order_Factory::get_order_item( $items[1]->get_id() )->get_meta( '_wplm_entitlement_ids' ), 'Never recorded for a refunded line.' );
		$this->assertSame( '', (string) $order->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertStringContainsString( 'cut off part-way', $this->notes( $order ) );
		$this->assertStringContainsString( '#' . $users[0]->id, $this->notes( $order ) );
	}

	public function test_an_order_paid_later_is_refused_when_the_licence_has_since_got_its_monthly_module(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$fbr     = array(
			'wplm_extras'            => array( 'module:fbr' => '1' ),
			'wplm_extras_cycle'      => 'month',
			'wplm_target_license_id' => (string) $base['license_id'],
		);
		$first   = $this->buy_through_cart( $product, $fbr, $base['customer'], false );
		wc_get_order( $first['order']->get_id() )->update_status( 'failed' );
		$this->assertSame( '', $this->pay_later( $first['order'] )[0], 'Payable while it could be added.' );
		$this->buy_through_cart( $product, $fbr, $base['customer'] );
		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'fbr' ) );

		list( $page, $errors ) = $this->pay_later( $first['order'] );

		$this->assertStringContainsString( 'already has FBR', $page, 'Never charged twice for one module.' );
		$this->assertStringContainsString( 'order #' . $first['order']->get_order_number(), $page );
		$this->assertTrue( $errors->has_errors(), 'The block checkout\'s order-pay too.' );
	}

	public function test_an_order_paid_later_is_refused_when_the_licence_has_since_got_its_lifetime_module(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$factory = array(
			'wplm_extras'            => array( 'module:factory' => '1' ),
			'wplm_target_license_id' => (string) $base['license_id'],
		);
		$first   = $this->buy_through_cart( $product, $factory, $base['customer'], false );
		wc_get_order( $first['order']->get_id() )->update_status( 'failed' );
		$this->buy_through_cart( $product, $factory, $base['customer'] );
		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'factory' ) );

		list( $page, $errors ) = $this->pay_later( $first['order'] );

		$this->assertStringContainsString( 'already has Factory', $page, 'Never charged twice for one module.' );
		$this->assertTrue( $errors->has_errors() );

		// One that can still be added is paid as usual.
		$user = $this->buy_through_cart(
			$product,
			array(
				'wplm_extras'            => array( 'user' => '1' ),
				'wplm_target_license_id' => (string) $base['license_id'],
			),
			$base['customer'],
			false
		);
		list( $page, $errors ) = $this->pay_later( $user['order'] );
		$this->assertSame( '', $page );
		$this->assertFalse( $errors->has_errors() );
	}

	public function test_an_order_paid_later_is_refused_when_its_licence_can_no_longer_take_addons(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$first   = $this->buy_through_cart(
			$product,
			array(
				'wplm_extras'            => array( 'user' => '1' ),
				'wplm_target_license_id' => (string) $base['license_id'],
			),
			$base['customer'],
			false
		);
		wc_get_order( $first['order']->get_id() )->update_status( 'failed' );
		$this->set_row( 'licenses', $base['license_id'], array( 'status' => 4 ) ); // Suspended.

		list( $page, $errors ) = $this->pay_later( $first['order'] );

		$this->assertStringContainsString( 'nothing can be added to it', $page );
		$this->assertTrue( $errors->has_errors() );
	}

	public function test_refunding_both_orders_on_an_unbilled_addons_subscription_ends_it(): void {
		list( $first, $second, $opened, $base ) = $this->second_order_cut_off_on_an_unbilled_addons_subscription();
		wc_get_order( $first['order']->get_id() )->update_status( 'refunded' );
		$this->assertSame( 'pending', $this->subscription_row( $opened )['status'], 'B\'s line is on it: it stays open.' );

		wc_get_order( $second['order']->get_id() )->update_status( 'refunded' );

		$this->assertSame( 'cancelled', $this->subscription_row( $opened )['status'], 'Nothing on it, never billed: it ends.' );
		$licence = $this->make( \WPLM\Repositories\LicenseRepository::class )->find_by_id( $base['license_id'] );
		$this->assertNull( $this->make( AddonService::class )->addons_cycle( $licence ), 'The customer chooses the cycle again.' );
		$yearly = $this->buy_through_cart(
			wc_get_product( $second['item']->get_product_id() ),
			array(
				'wplm_extras'            => array( 'seat-plan' => '1' ),
				'wplm_extras_cycle'      => 'year',
				'wplm_target_license_id' => (string) $base['license_id'],
			),
			$base['customer'],
			false
		);
		$this->assertSame( 'year', json_decode( (string) $yearly['item']->get_meta( '_wplm_extras' ), true )['cycle'] );
	}

	public function test_refunding_in_full_the_line_that_opened_an_unbilled_addons_subscription_ends_it(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$order   = $this->fbr_and_user_order( $product, $base );
		// The FBR line opens the add-ons subscription, then its write fails; the user line is added.
		$this->break_next = true;
		$order->update_status( 'on-hold' );
		$order->update_status( 'completed' );
		$order  = wc_get_order( $order->get_id() );
		$fbr    = array_values( $order->get_items() )[0];
		$opened = (int) \WC_Order_Factory::get_order_item( $fbr->get_id() )->get_meta( '_wplm_addons_subscription_id' );
		$this->assertGreaterThan( 0, $opened );
		$this->assertSame( 'pending', $this->subscription_row( $opened )['status'] );
		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'users' ) );

		// The shop refunds the FBR line in full: the order stays completed.
		$this->refund_line( $order, $fbr );

		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 'cancelled', $this->subscription_row( $opened )['status'], 'Never billed, nothing on it: it ends with the line.' );
		$licence = $this->make( \WPLM\Repositories\LicenseRepository::class )->find_by_id( $base['license_id'] );
		$this->assertNull( $this->make( AddonService::class )->addons_cycle( $licence ), 'The customer chooses the cycle again.' );
		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'users' ), 'The line paid for is untouched.' );

		// Completed again: nothing more is added, nothing is reopened.
		$this->complete_again( $order );
		$this->assertCount( 0, $this->addon_lines( $base['license_id'], 'fbr' ) );
		$this->assertSame( 'cancelled', $this->subscription_row( $opened )['status'] );
		$this->assertNull( $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] ) );
		$this->assertSame( '1', (string) wc_get_order( $order->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
	}

	public function test_a_new_licence_line_refunded_in_full_before_completion_gets_no_addons(): void {
		$bought = $this->buy_new( 'Monthly', array( 'module:fbr' => '1' ), null, false );
		$order  = wc_get_order( $bought['order']->get_id() );
		$order->update_status( 'processing' );
		$this->refund_line( wc_get_order( $order->get_id() ), array_values( $order->get_items() )[0] );

		wc_get_order( $order->get_id() )->update_status( 'completed' );

		$order = wc_get_order( $order->get_id() );
		$ids   = json_decode( (string) array_values( $order->get_items() )[0]->get_meta( '_wplm_license_ids' ), true );
		$this->assertNotEmpty( $ids, 'The licence itself: as for any licence type line (an owner question).' );
		$this->assertCount( 0, $this->lines( (int) $ids[0], 'addon' ), 'Refunded: its add-ons are never added.' );
		$this->assertSame( '1', (string) $order->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertStringContainsString( 'refunded in full', $this->notes( $order ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Final review, round 5 (2026-10-15): a paid order not completed yet holds its modules before
	// payment; a cancelled order ends its unbilled add-ons subscription; a licence type's stray licence
	// id never blocks payment
	// ---------------------------------------------------------------------------------------------

	public function test_an_order_paid_later_is_refused_while_a_paid_order_not_completed_yet_adds_its_monthly_module(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$fbr     = array(
			'wplm_extras'            => array( 'module:fbr' => '1' ),
			'wplm_extras_cycle'      => 'month',
			'wplm_target_license_id' => (string) $base['license_id'],
		);
		$first   = $this->buy_through_cart( $product, $fbr, $base['customer'], false );
		wc_get_order( $first['order']->get_id() )->update_status( 'failed' );
		$second = $this->buy_through_cart( $product, $fbr, $base['customer'], false );
		$this->assertSame( '', $this->pay_later( $first['order'] )[0], 'An unpaid order holds nothing: the first one paid wins.' );
		wc_get_order( $second['order']->get_id() )->update_status( 'on-hold' ); // A bank transfer awaiting the shop.

		list( $page, $errors ) = $this->pay_later( $first['order'] );

		$this->assertStringContainsString( 'Your order #' . $second['order']->get_order_number() . ', not completed yet, already adds FBR', $page, 'Never charged twice for one module.' );
		$this->assertStringContainsString( 'order #' . $first['order']->get_order_number() . ' adds add-ons', $page );
		$this->assertTrue( $errors->has_errors(), 'The block checkout\'s order-pay too.' );

		// The shop refunds that order's line in full: the first order is payable again.
		$this->refund_line( wc_get_order( $second['order']->get_id() ), $second['item'] );
		$this->assertSame( '', $this->pay_later( $first['order'] )[0] );
	}

	public function test_an_order_paid_later_is_refused_while_a_paid_order_not_completed_yet_adds_its_lifetime_module(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$factory = array(
			'wplm_extras'            => array( 'module:factory' => '1' ),
			'wplm_target_license_id' => (string) $base['license_id'],
		);
		$first   = $this->buy_through_cart( $product, $factory, $base['customer'], false );
		wc_get_order( $first['order']->get_id() )->update_status( 'failed' );
		$second = $this->buy_through_cart( $product, $factory, $base['customer'], false );
		wc_get_order( $second['order']->get_id() )->update_status( 'processing' );

		list( $page, $errors ) = $this->pay_later( $first['order'] );

		$this->assertStringContainsString( 'already adds Factory', $page );
		$this->assertTrue( $errors->has_errors() );

		// Completed, and its write failed (awaiting a retry): it still holds Factory.
		$this->break_next = true;
		wc_get_order( $second['order']->get_id() )->update_status( 'completed' );
		$this->assertSame( '', (string) wc_get_order( $second['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertCount( 0, $this->addon_lines( $base['license_id'], 'factory' ) );
		list( $page, $errors ) = $this->pay_later( $first['order'] );
		$this->assertStringContainsString( 'order #' . $second['order']->get_order_number() . ' is still adding Factory', $page );
		$this->assertTrue( $errors->has_errors() );

		// One that adds something else is paid as usual.
		$user = $this->buy_through_cart(
			$product,
			array(
				'wplm_extras'            => array( 'user' => '1' ),
				'wplm_target_license_id' => (string) $base['license_id'],
			),
			$base['customer'],
			false
		);
		list( $page, $errors ) = $this->pay_later( $user['order'] );
		$this->assertSame( '', $page );
		$this->assertFalse( $errors->has_errors() );
	}

	public function test_add_to_cart_and_checkout_refuse_a_module_a_paid_order_not_completed_yet_adds(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$factory = array(
			'wplm_extras'            => array( 'module:factory' => '1' ),
			'wplm_target_license_id' => (string) $base['license_id'],
		);
		$first   = $this->buy_through_cart( $product, $factory, $base['customer'], false );
		wp_set_current_user( $base['customer'] );
		$this->assertTrue( $this->validate( $factory, $product ), 'Unpaid, it holds nothing.' );

		// In the cart before the first order is paid; checkout then stops.
		foreach ( $factory as $field => $value ) {
			$_POST[ $field ] = $value;
		}
		$this->assertNotFalse( WC()->cart->add_to_cart( $product->get_id(), 1 ) );
		foreach ( array_keys( $factory ) as $field ) {
			unset( $_POST[ $field ] );
		}
		wc_get_order( $first['order']->get_id() )->update_status( 'on-hold' );
		wc_clear_notices();
		$this->checkout()->check_cart_extras();
		$this->assertStringContainsString( 'Your order #' . $first['order']->get_order_number() . ', not completed yet, already adds Factory', $this->first_error() );
		WC()->cart->empty_cart();

		$this->assertFalse( $this->validate( $factory, $product ), 'Never charged twice for one module.' );
		$this->assertStringContainsString( 'already adds Factory', $this->first_error() );
		$this->assertTrue(
			$this->validate(
				array(
					'wplm_extras'            => array( 'user' => '1' ),
					'wplm_target_license_id' => (string) $base['license_id'],
				),
				$product
			),
			'A limit adds up: never held.'
		);
		wc_clear_notices();

		// Completed: the licence has it.
		wc_get_order( $first['order']->get_id() )->update_status( 'completed' );
		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'factory' ) );
		$this->assertFalse( $this->validate( $factory, $product ) );
	}

	public function test_two_paid_orders_for_one_module_still_add_it_once_at_completion(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$factory = array(
			'wplm_extras'            => array( 'module:factory' => '1' ),
			'wplm_target_license_id' => (string) $base['license_id'],
		);
		// Both paid before this check existed (or a gateway that reports payment on its own).
		$first  = $this->buy_through_cart( $product, $factory, $base['customer'], false );
		$second = $this->buy_through_cart( $product, $factory, $base['customer'], false );
		wc_get_order( $first['order']->get_id() )->update_status( 'on-hold' );
		wc_get_order( $second['order']->get_id() )->update_status( 'on-hold' );

		wc_get_order( $first['order']->get_id() )->update_status( 'completed' );
		wc_get_order( $second['order']->get_id() )->update_status( 'completed' );

		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'factory' ), 'The first completed adds it: they never refuse each other.' );
		$this->assertSame( '1', (string) wc_get_order( $first['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertSame( '', (string) wc_get_order( $second['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertStringContainsString( 'already has Factory', $this->notes( wc_get_order( $second['order']->get_id() ) ) );
	}

	public function test_cancelling_an_order_whose_addons_were_never_written_ends_its_unbilled_addons_subscription(): void {
		$this->add_seat_plan_offer();
		$product          = $this->addon_product();
		$base             = $this->customer_with( 'Lifetime' );
		$this->break_next = true;
		$first            = $this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );
		$opened           = (int) \WC_Order_Factory::get_order_item( $first['item']->get_id() )->get_meta( '_wplm_addons_subscription_id' );
		$this->assertSame( 'pending', $this->subscription_row( $opened )['status'] );

		wc_get_order( $first['order']->get_id() )->update_status( 'cancelled' );

		$this->assertSame( 'cancelled', $this->subscription_row( $opened )['status'], 'Never billed, nothing on it: it ends with the order.' );
		$licence = $this->make( LicenseRepository::class )->find_by_id( $base['license_id'] );
		$this->assertNull( $this->make( AddonService::class )->addons_cycle( $licence ), 'The customer chooses the cycle again.' );
		$this->assertNull( $this->make( AddonService::class )->offered_cycle( $licence ) );
		$yearly = $this->buy_through_cart(
			$product,
			array(
				'wplm_extras'            => array( 'seat-plan' => '1' ),
				'wplm_extras_cycle'      => 'year',
				'wplm_target_license_id' => (string) $base['license_id'],
			),
			$base['customer']
		);
		$this->assertSame( 'year', json_decode( (string) $yearly['item']->get_meta( '_wplm_extras' ), true )['cycle'] );
		$addons = $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] );
		$this->assertNotSame( $opened, $addons->id );
		$this->assertSame( 'year', $addons->billing_period );
		$this->assertSame( 'cancelled', $this->subscription_row( $opened )['status'], 'Cancelled, never deleted.' );
	}

	public function test_trashing_an_order_whose_addons_were_never_written_ends_its_unbilled_addons_subscription(): void {
		$product          = $this->addon_product();
		$base             = $this->customer_with( 'Lifetime' );
		$this->break_next = true;
		$first            = $this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );
		$opened           = (int) \WC_Order_Factory::get_order_item( $first['item']->get_id() )->get_meta( '_wplm_addons_subscription_id' );
		$this->assertSame( 'pending', $this->subscription_row( $opened )['status'] );

		wc_get_order( $first['order']->get_id() )->delete( false );

		$this->assertSame( 'cancelled', $this->subscription_row( $opened )['status'] );
	}

	public function test_trashing_an_order_from_the_orders_list_on_posts_storage_ends_its_unbilled_addons_subscription(): void {
		if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$this->markTestSkipped( 'Posts storage only: the orders list trashes the post.' );
		}
		$product          = $this->addon_product();
		$base             = $this->customer_with( 'Lifetime' );
		$this->break_next = true;
		$first            = $this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );
		$opened           = (int) \WC_Order_Factory::get_order_item( $first['item']->get_id() )->get_meta( '_wplm_addons_subscription_id' );
		$this->assertSame( 'pending', $this->subscription_row( $opened )['status'] );

		wp_trash_post( $first['order']->get_id() );

		$this->assertSame( 'cancelled', $this->subscription_row( $opened )['status'] );
	}

	public function test_cancelling_the_order_that_opened_an_addons_subscription_never_ends_it_while_another_orders_lines_are_on_it(): void {
		list( $first, $second, $opened, $base ) = $this->second_order_cut_off_on_an_unbilled_addons_subscription();

		wc_get_order( $first['order']->get_id() )->update_status( 'cancelled' );

		$this->assertSame( 'pending', $this->subscription_row( $opened )['status'], 'B\'s paid line is on it: it stays open.' );
		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'seats' ), 'Nothing removed.' );
		$this->complete_again( $second['order'] );
		$this->assertSame( 'active', $this->subscription_row( $opened )['status'] );
	}

	public function test_cancelling_an_order_whose_addons_were_added_changes_nothing(): void {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$first   = $this->add_to_licence( $product, $base, array( 'module:fbr' => '1' ) );
		$addons  = $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] );
		$this->assertSame( 'active', $addons->status );

		wc_get_order( $first['order']->get_id() )->update_status( 'cancelled' );

		$this->assertSame( 'active', $this->subscription_row( $addons->id )['status'] );
		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'fbr' ), 'A cancelled order is not a refund: nothing is taken back.' );
	}

	/** An unpaid order with a licence type's line carrying a licence id, as orders before 1.3.0 could. */
	private function licence_type_order_with_a_licence_id( int $customer, int $license_id ): \WC_Order {
		$pkg   = $this->package( 'Monthly' );
		$order = wc_create_order( array( 'customer_id' => $customer ) );
		$item  = $order->get_item( $order->add_product( $this->product, 1 ) );
		$item->add_meta_data( '_wplm_package_id', $pkg->id, true );
		$item->add_meta_data( '_wplm_plan_id', $pkg->plan_id, true );
		$item->add_meta_data( '_wplm_addon_license_id', $license_id, true );
		$item->save();
		$order->set_payment_method( 'bacs' );
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'failed' );
		return wc_get_order( $order->get_id() );
	}

	public function test_a_licence_type_line_with_a_stray_licence_id_can_still_be_paid_and_issues_a_licence(): void {
		$base     = $this->customer_with( 'Lifetime' );
		$stranger = $this->customer_with( 'Lifetime' );
		foreach ( array( $base['license_id'], $stranger['license_id'], 999999 ) as $license_id ) {
			$order = $this->licence_type_order_with_a_licence_id( $base['customer'], $license_id );

			list( $page, $errors ) = $this->pay_later( $order );

			$this->assertSame( '', $page, 'A licence type is issued as a new licence, whatever licence id it carries.' );
			$this->assertFalse( $errors->has_errors() );
		}
		$order->update_status( 'processing' );
		wc_get_order( $order->get_id() )->update_status( 'completed' );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '1', (string) $order->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertNotEmpty( json_decode( (string) array_values( $order->get_items() )[0]->get_meta( '_wplm_license_ids' ), true ) );
	}
	/** A licence bought with extras whose extras step was cut off: completed, the licence issued, the extras not added. */
	private function new_purchase_awaiting_its_extras( string $package, array $extras, ?string $cycle = null ): array {
		$this->break_next = true;
		if ( null !== $cycle ) {
			$bought = $this->lifetime_with_fbr( $cycle );
		} else {
			$bought = $this->buy_new( $package, $extras );
		}
		$order = wc_get_order( $bought['order']->get_id() );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( '', (string) $order->get_meta( '_wplm_plan_fulfilled' ), 'Awaiting a retry.' );
		$this->assertSame( array(), $this->lines( $bought['license_id'], 'addon' ) );
		return $bought;
	}

	/** As if the order had been paid before its extras held their modules: hidden from the hold while `$paid` runs. */
	private function paid_before_the_hold( \WC_Order $order, callable $paid ) {
		$order = wc_get_order( $order->get_id() );
		$order->update_meta_data( '_wplm_plan_fulfilled', '1' );
		$order->save();
		$result = $paid();
		$this->unfulfil( $order );
		return $result;
	}

	public function test_a_new_purchase_whose_extras_await_a_retry_holds_their_modules(): void {
		$product = $this->addon_product();
		foreach ( array( 'Lifetime', 'Monthly' ) as $package ) {
			$first = $this->new_purchase_awaiting_its_extras( $package, array( 'module:factory' => '1' ) );
			wp_set_current_user( $first['customer'] );
			$factory = array(
				'wplm_extras'            => array( 'module:factory' => '1' ),
				'wplm_target_license_id' => (string) $first['license_id'],
			);

			$this->assertFalse( $this->validate( $factory, $product ), $package . ': Factory is not sold again while the first order still adds it.' );
			$this->assertStringContainsString( 'order #' . $first['order']->get_order_number() . ' is still adding Factory', $this->first_error() );

			$this->complete_again( $first['order'] );
			$this->assertCount( 1, $this->addon_lines( $first['license_id'], 'factory' ), $package . ': added once.' );
			$this->assertSame( '1', (string) wc_get_order( $first['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
		}
	}

	public function test_a_new_purchase_whose_recurring_extras_await_a_retry_holds_them(): void {
		$product = $this->addon_product();
		$first   = $this->new_purchase_awaiting_its_extras( 'Lifetime', array(), 'month' );
		wp_set_current_user( $first['customer'] );
		$fbr = array(
			'wplm_extras'            => array( 'module:fbr' => '1' ),
			'wplm_extras_cycle'      => 'month',
			'wplm_target_license_id' => (string) $first['license_id'],
		);

		$this->assertFalse( $this->validate( $fbr, $product ), 'FBR is not sold again while the first order still adds it.' );
		$this->assertStringContainsString( 'is still adding', $this->first_error() );

		$this->complete_again( $first['order'] );
		$this->assertCount( 1, $this->addon_lines( $first['license_id'], 'fbr' ) );
		$this->assertCount( 1, $this->items( $first['addons'] ) );
		$this->assertSame( 1000.0, $this->total( $first['addons'] ) );
	}

	public function test_a_retry_adds_no_module_the_licence_got_since(): void {
		$product = $this->addon_product();
		$first   = $this->new_purchase_awaiting_its_extras( 'Lifetime', array( 'module:factory' => '1' ) );
		$base    = array(
			'license_id' => $first['license_id'],
			'customer'   => $first['customer'],
		);
		$this->paid_before_the_hold( $first['order'], fn() => $this->add_to_licence( $product, $base, array( 'module:factory' => '1' ) ) );
		$this->assertCount( 1, $this->addon_lines( $first['license_id'], 'factory' ) );

		$this->complete_again( $first['order'] );

		$order = wc_get_order( $first['order']->get_id() );
		$this->assertCount( 1, $this->addon_lines( $first['license_id'], 'factory' ), 'Never added twice.' );
		$this->assertSame( '', (string) $order->get_meta( '_wplm_plan_fulfilled' ), 'Left for the shop.' );
		$this->assertStringContainsString( 'already has Factory', $this->notes( $order ) );
		$this->assertStringContainsString( 'refund the add-ons\' part of this line', $this->notes( $order ) );
	}

	public function test_a_retry_adds_no_recurring_module_the_licence_got_since(): void {
		$product = $this->addon_product();
		$first   = $this->new_purchase_awaiting_its_extras( 'Lifetime', array(), 'month' );
		$base    = array(
			'license_id' => $first['license_id'],
			'customer'   => $first['customer'],
		);
		$fbr     = array(
			'wplm_extras'            => array( 'module:fbr' => '1' ),
			'wplm_extras_cycle'      => 'month',
			'wplm_target_license_id' => (string) $first['license_id'],
		);
		$this->paid_before_the_hold( $first['order'], fn() => $this->buy_through_cart( $product, $fbr, $first['customer'] ) );
		$this->assertCount( 1, $this->addon_lines( $first['license_id'], 'fbr' ) );

		$this->complete_again( $first['order'] );

		$this->assertCount( 1, $this->addon_lines( $first['license_id'], 'fbr' ), 'Never added twice.' );
		$this->assertCount( 0, $this->addon_lines( $first['license_id'], 'factory' ), 'The line is refused whole.' );
		$this->assertCount( 1, $this->items( $first['addons'] ), 'Never billed twice.' );
		$this->assertSame( 1000.0, $this->total( $first['addons'] ) );
		$this->assertStringContainsString( 'already has FBR', $this->notes( wc_get_order( $first['order']->get_id() ) ) );
	}

	public function test_a_retry_whose_addons_subscription_ended_joins_the_licences_open_one(): void {
		$first = $this->new_purchase_awaiting_its_extras( 'Lifetime', array(), 'month' );
		$subs  = $this->make( SubscriptionRepository::class );
		$subs->update( $first['addons'], array( 'status' => 'cancelled' ) );
		$license = $this->make( LicenseRepository::class )->find_by_id( $first['license_id'] );
		$other   = $this->make( AddonService::class )->create_addons_subscription( $license, wc_get_order( $first['order']->get_id() ), 'month' );

		$this->complete_again( $first['order'] );

		$this->assertCount( 2, $subs->get_addons_for_license( $first['license_id'] ), 'No second open add-ons subscription.' );
		$fbr = $this->addon_lines( $first['license_id'], 'fbr' );
		$this->assertCount( 1, $fbr );
		$this->assertSame( $other->id, (int) $fbr[0]->subscription_id );
		$this->assertSame( 1000.0, $this->total( $other->id ) );
		$this->assertSame( '1', (string) wc_get_order( $first['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
	}

	public function test_a_retry_never_opens_an_addons_subscription_on_another_cycle(): void {
		$first = $this->new_purchase_awaiting_its_extras( 'Lifetime', array(), 'month' );
		$subs  = $this->make( SubscriptionRepository::class );
		$subs->update( $first['addons'], array( 'status' => 'cancelled' ) );
		$license = $this->make( LicenseRepository::class )->find_by_id( $first['license_id'] );
		$other   = $this->make( AddonService::class )->create_addons_subscription( $license, wc_get_order( $first['order']->get_id() ), 'year' );

		$this->complete_again( $first['order'] );

		$this->assertCount( 2, $subs->get_addons_for_license( $first['license_id'] ), 'No second open add-ons subscription.' );
		$this->assertCount( 0, $this->addon_lines( $first['license_id'], 'fbr' ) );
		$this->assertSame( array(), $this->items( $other->id ) );
		$this->assertStringContainsString( 'billed on subscription #' . $other->id . ' every year', $this->notes( wc_get_order( $first['order']->get_id() ) ) );
	}
	public function test_a_new_purchase_whose_recurring_extras_await_a_retry_holds_their_cycle(): void {
		$first = $this->new_purchase_awaiting_its_extras( 'Lifetime', array(), 'month' );
		$this->make( SubscriptionRepository::class )->update( $first['addons'], array( 'status' => 'cancelled' ) );
		$license = $this->make( LicenseRepository::class )->find_by_id( $first['license_id'] );
		$addons  = $this->make( AddonService::class );

		$pending = $addons->unfulfilled_addons_cycle( $license );
		$this->assertSame( 'month', $pending['cycle'] ?? null, 'Its cycle is the licence\'s until it is added.' );
		$this->assertSame( $first['order']->get_id(), $pending['order']->get_id() );
		$this->assertSame( 'month', $addons->offered_cycle( $license ) );
	}

	public function test_a_retry_that_joins_an_add_ons_subscription_already_billing_says_so(): void {
		$first = $this->new_purchase_awaiting_its_extras( 'Lifetime', array(), 'month' );
		$subs  = $this->make( SubscriptionRepository::class );
		$subs->update( $first['addons'], array( 'status' => 'cancelled' ) );
		$license = $this->make( LicenseRepository::class )->find_by_id( $first['license_id'] );
		$other   = $this->make( AddonService::class )->create_addons_subscription( $license, wc_get_order( $first['order']->get_id() ), 'month', time() - 25 * DAY_IN_SECONDS );
		$subs->update( $other->id, array( 'status' => 'active' ) );
		$through = $this->make( AddonService::class )->addons_paid_through( $subs->find_by_id( $other->id ) );

		$this->complete_again( $first['order'] );

		$fbr = $this->addon_lines( $first['license_id'], 'fbr' );
		$this->assertCount( 1, $fbr );
		$this->assertSame( $other->id, (int) $fbr[0]->subscription_id );
		$this->assertSame( $through, $fbr[0]->paid_through, 'Billing is left as it is.' );
		$this->assertStringContainsString( 'joined the licence\'s add-ons subscription #' . $other->id . ', which was already billing: they are paid through ' . $through, $this->notes( wc_get_order( $first['order']->get_id() ) ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Mark add-ons settled (1.3.0)
	// ---------------------------------------------------------------------------------------------

	private function settlement(): AddonSettlement {
		return new AddonSettlement( $this->make( AddonService::class ) );
	}

	private function shop_owner(): int {
		return self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Shop Owner',
			)
		);
	}

	/** The order screen's "Mark add-ons settled" order action, as WooCommerce runs it on Update. */
	private function settle_from_screen( \WC_Order $order, array $item_ids, string $note = '', ?string $nonce = null ): void {
		$_POST['wplm_settle_items'] = array_map( 'strval', $item_ids );
		$_POST['wplm_settle_note']  = $note;
		$_POST['wplm_settle_nonce'] = $nonce ?? wp_create_nonce( AddonSettlement::ACTION . '_' . $order->get_id() );
		do_action( 'woocommerce_order_action_' . AddonSettlement::ACTION, wc_get_order( $order->get_id() ) );
		unset( $_POST['wplm_settle_items'], $_POST['wplm_settle_note'], $_POST['wplm_settle_nonce'] );
	}

	/** The order note that contains `$needle`. */
	private function note_with( \WC_Order $order, string $needle ): string {
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			if ( false !== strpos( $note->content, $needle ) ) {
				return $note->content;
			}
		}
		$this->fail( "No order note with \"{$needle}\"." );
	}

	private function fresh_item( \WC_Order_Item_Product $item ): \WC_Order_Item_Product {
		return \WC_Order_Factory::get_order_item( $item->get_id() );
	}

	/** Two existing-licence orders for Factory: the second, completed after the first, is refused "already has". */
	private function factory_refused_on_a_second_order(): array {
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Monthly' );
		$first   = $this->add_to_licence( $product, $base, array( 'module:factory' => '1' ), false );
		$second  = $this->add_to_licence( $product, $base, array( 'module:factory' => '1' ), false );
		$first['order']->update_status( 'completed' );
		$second['order']->update_status( 'completed' );
		$this->assertSame( '', (string) wc_get_order( $second['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
		return array( $base, $first, $second );
	}

	public function test_settling_one_line_releases_its_module_and_cycle_hold_and_it_is_never_added(): void {
		$this->add_seat_plan_offer();
		$product = $this->addon_product();
		$base    = $this->customer_with( 'Lifetime' );
		$order   = $this->fbr_and_user_order( $product, $base );
		// Completed while another request held the licence: both lines await a retry.
		add_option( 'wplm_addons_lock_' . $base['license_id'], (string) time(), '', 'no' );
		$order->update_status( 'on-hold' );
		$order->update_status( 'completed' );
		delete_option( 'wplm_addons_lock_' . $base['license_id'] );
		$order = wc_get_order( $order->get_id() );
		$items = array_values( $order->get_items() );
		$this->assertStringContainsString( 'set the order to Completed again to retry', $this->note_with( $order, 'another order is adding to this licence' ), 'A retry cures a lock, and the note still says so.' );

		$license = $this->make( LicenseRepository::class )->find_by_id( $base['license_id'] );
		$addons  = $this->make( AddonService::class );
		$yearly  = array(
			'wplm_extras'            => array( 'seat-plan' => '1' ),
			'wplm_extras_cycle'      => 'year',
			'wplm_target_license_id' => (string) $base['license_id'],
		);
		$fbr     = array(
			'wplm_extras'            => array( 'module:fbr' => '1' ),
			'wplm_extras_cycle'      => 'month',
			'wplm_target_license_id' => (string) $base['license_id'],
		);
		$this->assertArrayHasKey( 'fbr', $addons->unfulfilled_addon_modules( $license ) );
		$this->assertSame( 'month', $addons->unfulfilled_addons_cycle( $license )['cycle'] ?? null );
		wp_set_current_user( $base['customer'] );
		$this->assertFalse( $this->validate( $yearly, $product ), 'Held: the FBR line will open the add-ons subscription monthly.' );
		$this->assertFalse( $this->validate( $fbr, $product ), 'Held: the order still adds FBR.' );
		$this->assertStringContainsString( 'is still adding FBR', $this->first_error() );

		$owner = $this->shop_owner();
		wp_set_current_user( $owner );
		$this->settle_from_screen( $order, array( $items[0]->get_id() ), 'Refunded by bank transfer' );

		$order = wc_get_order( $order->get_id() );
		$stamp = AddonSettlement::stamp( $this->fresh_item( $items[0] ) );
		$this->assertSame( array( $owner, 'Refunded by bank transfer' ), array( (int) ( $stamp['by'] ?? 0 ), $stamp['note'] ?? null ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ( $stamp['at'] ?? '' ) );
		$this->assertSame( '', (string) $order->get_meta( '_wplm_plan_fulfilled' ), 'The user line still awaits its retry: the order is not done.' );
		$this->assertArrayNotHasKey( 'fbr', $addons->unfulfilled_addon_modules( $license ), 'FBR is no longer held.' );
		$this->assertNull( $addons->unfulfilled_addons_cycle( $license ), 'Nor the monthly cycle.' );
		$this->assertCount( 0, $this->addon_lines( $base['license_id'], 'fbr' ), 'Nothing written.' );
		$this->assertNull( $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] ), 'No subscription opened.' );
		$this->assertStringContainsString( 'add-ons on line "' . $items[0]->get_name() . '" marked settled by Shop Owner: Refunded by bank transfer', $this->notes( $order ) );
		wp_set_current_user( $base['customer'] );
		$this->assertTrue( $this->validate( $yearly, $product ), 'The customer chooses the cycle again: ' . $this->first_error() );
		$this->assertTrue( $this->validate( $fbr, $product ), 'FBR can be bought again: ' . $this->first_error() );

		// The owner completes it again for the user line: the settled line is never written.
		$this->complete_again( $order );

		$order = wc_get_order( $order->get_id() );
		$this->assertCount( 0, $this->addon_lines( $base['license_id'], 'fbr' ), 'Settled: never added.' );
		$this->assertNull( $this->make( SubscriptionRepository::class )->find_addons_for_license( $base['license_id'] ), 'Nothing billed every month for it.' );
		$this->assertCount( 1, $this->addon_lines( $base['license_id'], 'users' ), 'The other line is added.' );
		$this->assertSame( '', (string) $this->fresh_item( $items[0] )->get_meta( '_wplm_entitlement_ids' ) );
		$this->assertSame( '1', (string) $order->get_meta( '_wplm_plan_fulfilled' ), 'Every line is done now.' );
	}

	public function test_settling_a_line_refused_for_good_marks_the_order_fulfilled_and_settling_it_again_changes_nothing(): void {
		list( $base, , $second ) = $this->factory_refused_on_a_second_order();
		$order                   = wc_get_order( $second['order']->get_id() );
		$refusal                 = $this->note_with( $order, 'already has Factory' );
		$this->assertStringContainsString( 'A retry cannot add them: refund this line, or use Mark add-ons settled.', $refusal );
		$this->assertStringNotContainsString( 'Completed again', $refusal, 'Never told to retry what a retry cannot cure.' );
		$license = $this->make( LicenseRepository::class )->find_by_id( $base['license_id'] );
		$addons  = $this->make( AddonService::class );
		$this->assertSame( $order->get_id(), ( $addons->unfulfilled_addon_modules( $license )['factory'] ?? null )?->get_id() );
		$total = $this->total( $base['subscription_id'] );
		$owner = $this->shop_owner();

		$result = $this->settlement()->settle( $order->get_id(), array( $second['item']->get_id() ), $owner );

		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertSame( array( $second['item']->get_id() ), $result['settled'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '1', (string) $order->get_meta( '_wplm_plan_fulfilled' ), 'Its only line is done.' );
		$this->assertStringContainsString( 'every line of this order is now done', $this->notes( $order ) );
		$this->assertSame( array(), $addons->unfulfilled_addon_modules( $license ) );
		$this->assertSame( $total, $this->total( $base['subscription_id'] ), 'Nothing billed.' );
		$notes = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$stamp = AddonSettlement::stamp( $this->fresh_item( $second['item'] ) );

		// Twice: nothing more.
		$again = $this->settlement()->settle( $order->get_id(), array( $second['item']->get_id() ), $this->shop_owner(), 'second time' );

		$this->assertTrue( $again['ok'], $again['message'] );
		$this->assertSame( array(), $again['settled'] );
		$this->assertSame( $stamp, AddonSettlement::stamp( $this->fresh_item( $second['item'] ) ), 'The first stamp stays.' );
		$this->assertCount( $notes, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'No second note.' );

		// Completed again: nothing is added.
		$this->complete_again( $order );
		$this->assertCount( 1, $this->factory_lines( $base['license_id'] ) );
		$this->assertSame( $total, $this->total( $base['subscription_id'] ) );
	}

	public function test_the_extras_of_a_new_licence_refused_for_good_are_settled_and_the_licence_kept(): void {
		$product = $this->addon_product();
		$first   = $this->new_purchase_awaiting_its_extras( 'Lifetime', array( 'module:factory' => '1' ) );
		$base    = array(
			'license_id' => $first['license_id'],
			'customer'   => $first['customer'],
		);
		$this->paid_before_the_hold( $first['order'], fn() => $this->add_to_licence( $product, $base, array( 'module:factory' => '1' ) ) );
		$this->complete_again( $first['order'] );
		$order   = wc_get_order( $first['order']->get_id() );
		$refusal = $this->note_with( $order, 'already has Factory' );
		$this->assertStringContainsString( 'A retry cannot add them: refund the add-ons\' part of this line, or use Mark add-ons settled.', $refusal );
		$this->assertStringNotContainsString( 'Completed again', $refusal );
		$license = $this->make( LicenseRepository::class )->find_by_id( $first['license_id'] );
		$this->assertArrayHasKey( 'factory', $this->make( AddonService::class )->unfulfilled_addon_modules( $license ) );
		$status = (int) $this->license_row( $first['license_id'] )['status'];

		$result = $this->settlement()->settle( $order->get_id(), array( $first['item']->get_id() ), $this->shop_owner() );

		$this->assertTrue( $result['ok'], $result['message'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '1', (string) $order->get_meta( '_wplm_plan_fulfilled' ), 'The licence is issued and its extras settled.' );
		$this->assertSame( array(), $this->make( AddonService::class )->unfulfilled_addon_modules( $license ) );
		$this->assertSame( $status, (int) $this->license_row( $first['license_id'] )['status'], 'The licence is untouched.' );
		$this->assertSame( array( $first['license_id'] ), json_decode( (string) $this->fresh_item( $first['item'] )->get_meta( '_wplm_license_ids' ), true ) );
		$this->complete_again( $order );
		$this->assertCount( 1, $this->addon_lines( $first['license_id'], 'factory' ), 'Never added twice.' );
		$this->assertSame( 1, $this->licence_count( $first['customer'] ), 'No second key.' );
	}

	public function test_only_a_shop_manager_with_the_forms_nonce_can_settle_and_only_lines_awaiting_their_addons(): void {
		list( $base, $first, $second ) = $this->factory_refused_on_a_second_order();
		$item                          = $second['item'];
		$stamped                       = fn() => AddonSettlement::stamp( $this->fresh_item( $item ) );

		// The customer, with a nonce of their own: no.
		wp_set_current_user( $base['customer'] );
		$this->settle_from_screen( $second['order'], array( $item->get_id() ) );
		$this->assertSame( array(), $stamped() );
		$this->assertFalse( $this->settlement()->handle_request( $second['order'], array( 'wplm_settle_items' => array( $item->get_id() ), 'wplm_settle_nonce' => wp_create_nonce( AddonSettlement::ACTION . '_' . $second['order']->get_id() ) ) )['ok'] );
		$this->assertArrayNotHasKey( AddonSettlement::ACTION, $this->settlement()->order_actions( array(), wc_get_order( $second['order']->get_id() ) ) );

		// The owner, with no nonce, a wrong one, or another order's: no.
		$owner = $this->shop_owner();
		wp_set_current_user( $owner );
		$this->settle_from_screen( $second['order'], array( $item->get_id() ), '', '' );
		$this->settle_from_screen( $second['order'], array( $item->get_id() ), '', 'not-a-nonce' );
		$this->settle_from_screen( $second['order'], array( $item->get_id() ), '', wp_create_nonce( AddonSettlement::ACTION . '_' . $first['order']->get_id() ) );
		$this->assertSame( array(), $stamped() );
		$this->assertSame( '', (string) wc_get_order( $second['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );

		// A line whose add-ons were added, another order's line, an order not completed: no.
		$added = $this->settlement()->settle( $first['order']->get_id(), array( $first['item']->get_id() ), $owner );
		$this->assertFalse( $added['ok'] );
		$this->assertSame( array(), AddonSettlement::stamp( $this->fresh_item( $first['item'] ) ) );
		$this->assertFalse( $this->settlement()->settle( $first['order']->get_id(), array( $item->get_id() ), $owner )['ok'] );
		$this->assertFalse( $this->settlement()->settle( $second['order']->get_id(), array(), $owner )['ok'], 'Nothing ticked.' );
		$pending = $this->add_to_licence( $this->addon_product(), $base, array( 'user' => '1' ), false );
		wp_set_current_user( $owner );
		$this->assertFalse( $this->settlement()->settle( $pending['order']->get_id(), array( $pending['item']->get_id() ), $owner )['ok'], 'Not completed: it may still be paid for and added.' );
		$this->assertSame( array(), AddonSettlement::stamp( $this->fresh_item( $pending['item'] ) ) );
		$this->assertSame( array(), $stamped() );

		// The order screen offers it only where it applies.
		$screen = wc_get_order( $second['order']->get_id() );
		$this->assertArrayHasKey( AddonSettlement::ACTION, $this->settlement()->order_actions( array(), $screen ) );
		$this->assertArrayNotHasKey( AddonSettlement::ACTION, $this->settlement()->order_actions( array(), wc_get_order( $first['order']->get_id() ) ) );
		ob_start();
		do_action( 'woocommerce_after_order_itemmeta', $item->get_id(), $this->fresh_item( $item ), null );
		do_action( 'woocommerce_after_order_itemmeta', $first['item']->get_id(), $this->fresh_item( $first['item'] ), null );
		do_action( 'woocommerce_order_actions_end', $second['order']->get_id() );
		$html = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $html, 'name="wplm_settle_items[]"' ), 'One tick: the refused line only.' );
		$this->assertStringContainsString( 'value="' . $item->get_id() . '"', $html );
		$this->assertStringContainsString( 'name="wplm_settle_nonce"', $html );
		$this->assertStringContainsString( 'name="wplm_settle_note"', $html );

		// The owner, from the order screen: yes.
		$this->settle_from_screen( $second['order'], array( $item->get_id() ), 'Refunded' );
		$this->assertSame( $owner, (int) ( $stamped()['by'] ?? 0 ) );
		$this->assertSame( '1', (string) wc_get_order( $second['order']->get_id() )->get_meta( '_wplm_plan_fulfilled' ) );
		ob_start();
		do_action( 'woocommerce_after_order_itemmeta', $item->get_id(), $this->fresh_item( $item ), null );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Add-ons marked settled by Shop Owner', $html );
		$this->assertStringContainsString( 'Refunded', $html );
		$this->assertStringNotContainsString( 'wplm_settle_items', $html );
	}

	public function test_settling_works_the_same_on_hpos_order_storage(): void {
		update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		$this->assertTrue( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );

		list( $base, , $second ) = $this->factory_refused_on_a_second_order();
		global $wpdb;
		$this->assertSame( (string) $second['order']->get_id(), (string) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wc_orders WHERE id = %d", $second['order']->get_id() ) ), 'The order lives in the HPOS table.' );
		$this->assertNotSame( 'shop_order', get_post_type( $second['order']->get_id() ), 'Not in posts storage.' );
		$license = $this->make( LicenseRepository::class )->find_by_id( $base['license_id'] );
		$this->assertArrayHasKey( 'factory', $this->make( AddonService::class )->unfulfilled_addon_modules( $license ) );

		wp_set_current_user( $this->shop_owner() );
		$this->assertArrayHasKey( AddonSettlement::ACTION, $this->settlement()->order_actions( array(), wc_get_order( $second['order']->get_id() ) ) );
		$this->settle_from_screen( $second['order'], array( $second['item']->get_id() ), 'HPOS' );

		$order = wc_get_order( $second['order']->get_id() );
		$this->assertSame( 'HPOS', AddonSettlement::stamp( $this->fresh_item( $second['item'] ) )['note'] ?? null );
		$this->assertSame( '1', (string) $order->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertSame( array(), $this->make( AddonService::class )->unfulfilled_addon_modules( $license ) );
		$this->assertStringContainsString( 'marked settled by Shop Owner: HPOS', $this->notes( $order ) );
	}
}
