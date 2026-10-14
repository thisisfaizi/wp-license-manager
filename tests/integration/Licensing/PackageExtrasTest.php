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
}
