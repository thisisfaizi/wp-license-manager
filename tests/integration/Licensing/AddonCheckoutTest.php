<?php
/**
 * Add-ons for an existing licence: a module, users or a dated pack bought later is added to the
 * customer's licence (no new key), joins the plan's subscription when the plan is recurring, renews
 * with it, and a refund or an admin removal takes it — and its billing — back.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Licensing;

use WPLM\Admin\LicenceAdminActions;
use WPLM\Integrations\WooCommerce\AddonSettlement;
use WPLM\Integrations\WooCommerce\PlanCheckout;
use WPLM\Integrations\WooCommerce\SelfServiceRenewal;
use WPLM\Licensing\ProfileRegistry;
use WPLM\Models\License;
use WPLM\Repositories\SubscriptionRepository;
use WPLM\Services\AddonService;
use WPLM\Services\EntitlementService;
use WPLM\Services\GeneratorService;
use WPLM\Services\LicenseService;
use WPLM\Services\PlanService;
use WPLM\Services\Subscriptions\RenewalProcessor;
use WPLM\Services\Subscriptions\SubscriptionService;
use WPLM\Tests\TestCase;

class AddonCheckoutTest extends TestCase {

	/** @var array{plan_id:int, packages:\WPLM\Models\Package[]} */
	private array $addons;

	/** @var \WC_Product */
	private \WC_Product $addon_product;

	public function set_up(): void {
		parent::set_up();
		$this->addons        = $this->create_plan(
			array(
				$this->recurring( 'FBR monthly', 'month', 1000, array( array( 'kind' => 'module', 'code' => 'fbr' ) ) ),
				$this->recurring( 'FBR yearly', 'year', 10000, array( array( 'kind' => 'module', 'code' => 'fbr' ) ) ),
				array(
					'name'         => 'FBR lifetime',
					'billing_type' => 'lifetime',
					'price'        => 30000,
					'entitlements' => array( array( 'kind' => 'module', 'code' => 'fbr' ) ),
				),
				$this->recurring(
					'Extra user monthly',
					'month',
					1000,
					array(
						array(
							'kind' => 'limit',
							'code' => 'users',
							'qty'  => 1,
						),
					)
				),
				$this->recurring(
					'Extra seat monthly',
					'month',
					1000,
					array(
						array(
							'kind' => 'limit',
							'code' => 'seats',
							'qty'  => 1,
						),
					)
				),
				array(
					'name'           => 'Assets 30-day pack',
					'billing_type'   => 'onetime',
					'valid_for_days' => 30,
					'price'          => 2000,
					'entitlements'   => array( array( 'kind' => 'module', 'code' => 'assets' ) ),
				),
				$this->recurring( 'Subcontracting monthly', 'month', 1500, array( array( 'kind' => 'module', 'code' => 'subcontract' ) ) ),
			),
			array(
				'name'    => 'Acme add-ons',
				'profile' => 'acme-office',
				'kind'    => 'addon',
			)
		);
		$this->addon_product = $this->create_plan_product( $this->addons['plan_id'] );
	}

	public function tear_down(): void {
		$this->make( ProfileRegistry::class )->get( 'acme-office' )->requires = array();
		unset( $_GET['wplm_license'], $_POST['wplm_package_id'], $_POST['wplm_target_license_id'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// ---------------------------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------------------------

	private function recurring( string $name, string $period, float $price, array $lines ): array {
		return array(
			'name'             => $name,
			'billing_type'     => 'recurring',
			'billing_period'   => $period,
			'billing_interval' => 1,
			'price'            => $price,
			'entitlements'     => $lines,
		);
	}

	private function addon( string $name ): \WPLM\Models\Package {
		foreach ( $this->addons['packages'] as $package ) {
			if ( $name === $package->name ) {
				return $package;
			}
		}
		$this->fail( "No add-on {$name}." );
	}

	/** A customer who bought the base plan: monthly (default), yearly or lifetime. */
	private function customer_with( string $type = 'month' ): array {
		$lines = array(
			array( 'kind' => 'module', 'code' => 'base' ),
			array( 'kind' => 'module', 'code' => 'distribution' ),
			array(
				'kind' => 'limit',
				'code' => 'users',
				'qty'  => 3,
			),
		);
		$pkg   = 'lifetime' === $type
			? array(
				'name'         => 'Base lifetime',
				'billing_type' => 'lifetime',
				'price'        => 100000,
				'entitlements' => $lines,
			)
			: $this->recurring( 'Base ' . $type, $type, 'month' === $type ? 5000 : 50000, $lines );
		$plan     = $this->create_plan( array( $pkg ), array( 'profile' => 'acme-office' ) );
		$customer = $this->create_customer();
		$bought   = $this->buy( $this->create_plan_product( $plan['plan_id'] ), $plan['packages'][0], $customer );
		$this->assertGreaterThan( 0, $bought['license_id'] );
		$bought['customer'] = $customer;
		$bought['license']  = $this->licence( $bought['license_id'] );
		return $bought;
	}

	private function licence( int $id ): License {
		return $this->make( LicenseService::class )->get_by_id( $id );
	}

	/** Buy an add-on for a licence through a real order: on-hold, then Completed. */
	private function buy_addon( \WPLM\Models\Package $package, int $customer, int $license_id, int $qty = 1 ): \WC_Order {
		$order   = wc_create_order( array( 'customer_id' => $customer ) );
		$item_id = $order->add_product( $this->addon_product, $qty );
		$item    = $order->get_item( $item_id );
		$item->add_meta_data( '_wplm_package_id', $package->id, true );
		$item->add_meta_data( '_wplm_addon_license_id', $license_id, true );
		$item->save();
		$order->set_payment_method( 'bacs' );
		$order->set_billing_email( 'customer' . $customer . '@example.org' );
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'on-hold' );
		$order->update_status( 'completed' );
		return wc_get_order( $order->get_id() );
	}

	private function the_item( \WC_Order $order ): \WC_Order_Item_Product {
		$items = array_values( $order->get_items() );
		return $items[0];
	}

	/** @return \WPLM\Models\Entitlement[] */
	private function lines( int $license_id ): array {
		return $this->make( EntitlementService::class )->lines( $license_id );
	}

	private function line( int $license_id, string $code, ?string $source = null ): ?\WPLM\Models\Entitlement {
		foreach ( $this->lines( $license_id ) as $line ) {
			if ( $code === $line->code && ( null === $source || $source === $line->source ) ) {
				return $line;
			}
		}
		return null;
	}

	private function service(): AddonService {
		return $this->make( AddonService::class );
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

	private function items( int $subscription_id ): array {
		return $this->make( SubscriptionRepository::class )->get_items( $subscription_id );
	}

	private function total( int $subscription_id ): float {
		return (float) $this->subscription_row( $subscription_id )['recurring_total'];
	}

	private function licence_count( int $customer ): int {
		return (int) $this->make( \WPLM\Repositories\LicenseRepository::class )->get_list( array( 'user_id' => $customer ) )['total'];
	}

	private function assertRefused( string $code, $result ): void {
		$this->assertWPError( $result );
		$this->assertSame( 'wplm_addon_' . $code, $result->get_error_code(), $result->get_error_message() );
	}

	private function email_body( \WC_Order $order ): string {
		ob_start();
		do_action( 'woocommerce_email_after_order_table', $order, false, false, new \WC_Email_Customer_Completed_Order() );
		return (string) ob_get_clean();
	}

	// ---------------------------------------------------------------------------------------------
	// Recurring: joins the plan's subscription
	// ---------------------------------------------------------------------------------------------

	public function test_a_monthly_addon_joins_the_plans_subscription_and_ends_with_it(): void {
		$base  = $this->customer_with( 'month' );
		$order = $this->buy_addon( $this->addon( 'FBR monthly' ), $base['customer'], $base['license_id'] );

		$fbr = $this->line( $base['license_id'], 'fbr' );
		$this->assertNotNull( $fbr, 'FBR is on the licence the customer picked.' );
		$this->assertSame( 'addon', $fbr->source );
		$this->assertSame( $order->get_id(), $fbr->order_id );
		$this->assertSame( $base['subscription_id'], $fbr->subscription_id, 'It joins the plan\'s subscription.' );
		$this->assertSame( $this->line( $base['license_id'], 'base' )->paid_through, $fbr->paid_through, 'Paid through the same day as the plan.' );

		$this->assertSame( 1, $this->licence_count( $base['customer'] ), 'No new licence.' );
		$item = $this->the_item( $order );
		$this->assertSame( '', (string) $item->get_meta( '_wplm_license_ids' ), 'No key was issued for the line.' );
		$this->assertSame( '', (string) $item->get_meta( '_wplm_subscription_id' ), 'A refund handler would cancel the plan through that meta.' );
		$this->assertSame( array( $fbr->id ), json_decode( (string) $item->get_meta( '_wplm_entitlement_ids' ), true ) );
		$this->assertSame( '1', (string) $order->get_meta( '_wplm_plan_fulfilled' ) );

		$items = $this->items( $base['subscription_id'] );
		$this->assertCount( 2, $items, 'The plan and the add-on.' );
		$this->assertSame( 6000.0, $this->total( $base['subscription_id'] ), 'Billed together: derived from the items.' );
	}

	public function test_no_key_and_no_licences_issued_action_but_an_addons_applied_one(): void {
		$base    = $this->customer_with( 'month' );
		$issued  = did_action( 'wplm_order_licenses_issued' );
		$applied = array();
		add_action(
			'wplm_order_addons_applied',
			static function ( $order_id, $entries ) use ( &$applied ) {
				$applied[] = array( $order_id, $entries );
			},
			10,
			2
		);

		$order = $this->buy_addon( $this->addon( 'FBR monthly' ), $base['customer'], $base['license_id'] );

		$this->assertSame( $issued, did_action( 'wplm_order_licenses_issued' ), 'Tunnel provisioning and key mail listen to that action.' );
		$this->assertCount( 1, $applied );
		$this->assertSame( $order->get_id(), $applied[0][0] );
		$this->assertSame( $base['license_id'], $applied[0][1][0]['license']->id );
		$this->assertSame( 'fbr', $applied[0][1][0]['lines'][0]->code );
		$this->assertSame( array(), $this->make( \WPLM\Repositories\LicenseRepository::class )->get_list( array( 'order_id' => $order->get_id() ) )['items'] );
	}

	public function test_the_renewal_extends_the_addon_with_the_plan_and_bills_both(): void {
		$base = $this->customer_with( 'month' );
		$this->buy_addon( $this->addon( 'FBR monthly' ), $base['customer'], $base['license_id'] );
		$sub = $this->make( SubscriptionRepository::class )->find_by_id( $base['subscription_id'] );

		$invoice = $this->make( SelfServiceRenewal::class )->create_renewal_order_object( $sub->id );
		$this->assertSame( 6000.0, (float) $invoice->get_total(), 'The renewal invoice bills the plan and the add-on together.' );

		$this->make( RenewalProcessor::class )->apply_payment( $sub, null, 6000.0 );

		$base_line = $this->line( $base['license_id'], 'base' );
		$fbr       = $this->line( $base['license_id'], 'fbr' );
		$this->assertSame( EntitlementService::add_period( EntitlementService::paid_through_for( $sub->next_payment ), 1, 'month' ), $base_line->paid_through );
		$this->assertSame( $base_line->paid_through, $fbr->paid_through, 'The add-on renews with the plan.' );
	}

	public function test_recalculating_a_plain_base_subscription_changes_nothing(): void {
		$base = $this->customer_with( 'month' );

		$this->service()->recalculate( $base['subscription_id'] );

		$this->assertSame( 5000.0, $this->total( $base['subscription_id'] ) );
	}

	/** A subscription whose amount was edited by hand keeps the edit; the add-on is added to it. */
	public function test_a_hand_edited_amount_is_kept_when_an_addon_joins(): void {
		$base = $this->customer_with( 'month' );
		$this->set_row( 'subscriptions', $base['subscription_id'], array( 'recurring_total' => 4500 ) );

		$this->buy_addon( $this->addon( 'FBR monthly' ), $base['customer'], $base['license_id'] );

		$this->assertSame( 5500.0, $this->total( $base['subscription_id'] ) );
	}

	public function test_quantity_multiplies_what_the_addon_adds_to_a_limit(): void {
		$base = $this->customer_with( 'month' );

		$this->buy_addon( $this->addon( 'Extra user monthly' ), $base['customer'], $base['license_id'], 3 );

		$users = $this->line( $base['license_id'], 'users', 'addon' );
		$this->assertSame( 3, $users->qty );
		$summary = EntitlementService::summarize_lines( $this->lines( $base['license_id'] ), $this->make( ProfileRegistry::class )->get( 'acme-office' ), wp_date( 'Y-m-d' ), 7 );
		$this->assertSame( 6, $summary['limits']['users'] );
		$this->assertSame( 8000.0, $this->total( $base['subscription_id'] ), '5000 + 3 × 1000.' );
	}

	// ---------------------------------------------------------------------------------------------
	// Lifetime and one-time
	// ---------------------------------------------------------------------------------------------

	public function test_a_lifetime_addon_on_a_lifetime_licence_is_for_life(): void {
		$base = $this->customer_with( 'lifetime' );

		$this->buy_addon( $this->addon( 'FBR lifetime' ), $base['customer'], $base['license_id'] );

		$fbr = $this->line( $base['license_id'], 'fbr' );
		$this->assertNotNull( $fbr );
		$this->assertNull( $fbr->paid_through );
		$this->assertNull( $fbr->subscription_id );
		$this->assertSame( 1, $this->licence_count( $base['customer'] ) );
	}

	public function test_a_one_time_pack_lasts_its_days_and_a_second_pack_stacks(): void {
		update_option( 'timezone_string', 'Asia/Karachi' );
		$base  = $this->customer_with( 'lifetime' );
		$today = wp_date( 'Y-m-d' );

		$this->buy_addon( $this->addon( 'Assets 30-day pack' ), $base['customer'], $base['license_id'] );
		$this->assertSame( EntitlementService::add_period( $today, 29, 'day' ), $this->line( $base['license_id'], 'assets' )->paid_through, 'today + days − 1' );

		$second = $this->buy_addon( $this->addon( 'Assets 30-day pack' ), $base['customer'], $base['license_id'] );
		$mine   = array_values( array_filter( $this->lines( $base['license_id'] ), static fn( $l ) => $l->order_id === $second->get_id() ) );
		$this->assertCount( 1, $mine );
		$this->assertSame( EntitlementService::add_period( $today, 59, 'day' ), $mine[0]->paid_through, 'It continues from the first pack\'s end.' );
	}

	/** A pack also continues from a dated line the owner added by hand (how packs were sold before). */
	public function test_a_pack_continues_from_a_line_added_by_hand(): void {
		$base  = $this->customer_with( 'lifetime' );
		$until = EntitlementService::add_period( wp_date( 'Y-m-d' ), 10, 'day' );
		$this->make( EntitlementService::class )->add_line(
			$base['license_id'],
			array(
				'kind'         => 'module',
				'code'         => 'assets',
				'paid_through' => $until,
			)
		);

		$this->buy_addon( $this->addon( 'Assets 30-day pack' ), $base['customer'], $base['license_id'] );

		$this->assertSame( EntitlementService::add_period( $until, 30, 'day' ), $this->line( $base['license_id'], 'assets', 'addon' )->paid_through );
	}

	// ---------------------------------------------------------------------------------------------
	// Matching the plan
	// ---------------------------------------------------------------------------------------------

	public function test_each_licence_is_offered_only_the_addons_billed_like_it(): void {
		$monthly  = $this->customer_with( 'month' );
		$yearly   = $this->customer_with( 'year' );
		$lifetime = $this->customer_with( 'lifetime' );

		$this->assertTrue( $this->service()->check( $monthly['license'], $this->addon( 'FBR monthly' ) ) );
		$this->assertRefused( 'billing_mismatch', $this->service()->check( $monthly['license'], $this->addon( 'FBR yearly' ) ) );
		$this->assertRefused( 'billing_mismatch', $this->service()->check( $monthly['license'], $this->addon( 'FBR lifetime' ) ) );
		$this->assertRefused( 'billing_mismatch', $this->service()->check( $monthly['license'], $this->addon( 'Assets 30-day pack' ) ) );

		$this->assertTrue( $this->service()->check( $yearly['license'], $this->addon( 'FBR yearly' ) ) );
		$this->assertRefused( 'billing_mismatch', $this->service()->check( $yearly['license'], $this->addon( 'FBR monthly' ) ) );

		$this->assertTrue( $this->service()->check( $lifetime['license'], $this->addon( 'FBR lifetime' ) ) );
		$this->assertTrue( $this->service()->check( $lifetime['license'], $this->addon( 'Assets 30-day pack' ) ) );
		$this->assertRefused( 'billing_mismatch', $this->service()->check( $lifetime['license'], $this->addon( 'FBR monthly' ) ) );

		$plan    = $this->make( PlanService::class )->get( $this->addons['plan_id'] );
		$targets = $this->service()->eligible_targets( $yearly['customer'], $plan );
		$this->assertSame( array( $yearly['license_id'] ), array_keys( $targets ) );
		$this->assertSame( array( $this->addon( 'FBR yearly' )->id ), $targets[ $yearly['license_id'] ]['packages'] );
	}

	public function test_the_product_page_preselects_the_licence_and_shows_only_what_fits_it(): void {
		$base = $this->customer_with( 'month' );
		wp_set_current_user( $base['customer'] );
		$_GET['wplm_license'] = (string) $base['license_id'];

		global $product;
		$product = $this->addon_product;
		ob_start();
		$this->checkout()->render_package_options();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/<select id="wplm-target-license" name="wplm_target_license_id"><option value="' . $base['license_id'] . '"\s+selected/', $html );
		$this->assertStringContainsString( 'FBR monthly', $html );
		$this->assertStringContainsString( 'Extra user monthly', $html );
		$this->assertStringNotContainsString( 'FBR yearly', $html );
		$this->assertStringNotContainsString( 'FBR lifetime', $html );
		$this->assertTrue( $this->checkout()->plan_is_purchasable( false, $this->addon_product ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Email
	// ---------------------------------------------------------------------------------------------

	public function test_the_completed_email_says_what_was_added_to_which_licence(): void {
		$base  = $this->customer_with( 'month' );
		$order = $this->buy_addon( $this->addon( 'FBR monthly' ), $base['customer'], $base['license_id'] );
		$tail  = strtoupper( substr( $base['license']->license_key, -4 ) );

		// The object WooCommerce hands the email predates fulfilment; the text must not depend on it.
		$stale = new \WC_Order( $order->get_id() );
		$body  = $this->email_body( $stale );

		$this->assertStringContainsString( 'Added to your licence ending ' . $tail . ': FBR (paid through', $body );
		$this->assertStringNotContainsString( $base['license']->license_key, $body );
		$attached = apply_filters( 'woocommerce_email_attachments', array(), 'customer_completed_order', $order, null );
		$this->assertSame( array(), $attached, 'No key, so no setup sheet.' );
	}

	public function test_an_order_with_a_new_licence_and_an_addon_mentions_both(): void {
		$base     = $this->customer_with( 'lifetime' );
		$new_plan = $this->create_plan(
			array(
				array(
					'name'         => 'Second office',
					'billing_type' => 'lifetime',
					'price'        => 100000,
					'entitlements' => array( array( 'kind' => 'module', 'code' => 'base' ) ),
				),
			),
			array( 'profile' => 'acme-office' )
		);

		$order = wc_create_order( array( 'customer_id' => $base['customer'] ) );
		$first = $order->get_item( $order->add_product( $this->create_plan_product( $new_plan['plan_id'] ), 1 ) );
		$first->add_meta_data( '_wplm_package_id', $new_plan['packages'][0]->id, true );
		$first->save();
		$second = $order->get_item( $order->add_product( $this->addon_product, 1 ) );
		$second->add_meta_data( '_wplm_package_id', $this->addon( 'FBR lifetime' )->id, true );
		$second->add_meta_data( '_wplm_addon_license_id', $base['license_id'], true );
		$second->save();
		$order->set_billing_email( 'customer' . $base['customer'] . '@example.org' );
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'completed' );

		$this->assertSame( 2, $this->licence_count( $base['customer'] ), 'The new licence was issued.' );
		$this->assertNotNull( $this->line( $base['license_id'], 'fbr' ), 'And the add-on added.' );
		$body = $this->email_body( new \WC_Order( $order->get_id() ) );
		$this->assertStringContainsString( 'Your licence key and setup details are in the PDF', $body );
		$this->assertStringContainsString( 'Added to your licence ending ' . strtoupper( substr( $base['license']->license_key, -4 ) ) . ': FBR (for life)', $body );
	}

	// ---------------------------------------------------------------------------------------------
	// Taking it back
	// ---------------------------------------------------------------------------------------------

	public function test_a_full_refund_takes_back_the_lines_and_billing_but_never_the_licence(): void {
		$base   = $this->customer_with( 'month' );
		$before = array_map( static fn( $l ) => $l->to_array(), $this->lines( $base['license_id'] ) );
		$status = $this->licence( $base['license_id'] )->status;
		$order  = $this->buy_addon( $this->addon( 'FBR monthly' ), $base['customer'], $base['license_id'] );
		$this->assertSame( 6000.0, $this->total( $base['subscription_id'] ) );

		$order->update_status( 'refunded' );

		$this->assertNull( $this->line( $base['license_id'], 'fbr' ) );
		$this->assertSame( $before, array_map( static fn( $l ) => $l->to_array(), $this->lines( $base['license_id'] ) ), 'The plan\'s lines are untouched.' );
		$this->assertSame( $status, $this->licence( $base['license_id'] )->status, 'The licence is never suspended for an add-on refund.' );
		$this->assertSame( 'active', $this->subscription_row( $base['subscription_id'] )['status'], 'The plan\'s subscription is not cancelled.' );
		$this->assertSame( 5000.0, $this->total( $base['subscription_id'] ) );
		$this->assertCount( 1, $this->items( $base['subscription_id'] ) );

		$this->assertSame( 0, $this->service()->revert( wc_get_order( $order->get_id() ) ), 'Running it again removes nothing more.' );
		$this->assertSame( 5000.0, $this->total( $base['subscription_id'] ) );
	}

	public function test_removing_an_addon_line_in_admin_removes_its_billing(): void {
		$base = $this->customer_with( 'month' );
		$this->buy_addon( $this->addon( 'FBR monthly' ), $base['customer'], $base['license_id'] );
		$fbr = $this->line( $base['license_id'], 'fbr' );

		$result = $this->make( LicenceAdminActions::class )->delete_line( $base['license_id'], $fbr->id );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 5000.0, $this->total( $base['subscription_id'] ) );
		$this->assertCount( 1, $this->items( $base['subscription_id'] ) );
	}

	public function test_the_admin_panel_shows_where_an_addon_line_came_from(): void {
		$base  = $this->customer_with( 'month' );
		$order = $this->buy_addon( $this->addon( 'FBR monthly' ), $base['customer'], $base['license_id'] );

		ob_start();
		$this->make( \WPLM\Admin\Screens\EntitlementPanel::class )->render( $this->licence( $base['license_id'] ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'add-on', $html );
		$this->assertStringContainsString( 'order #' . $order->get_id(), $html );
		$this->assertStringContainsString( 'subscription #' . $base['subscription_id'], $html );
	}

	// ---------------------------------------------------------------------------------------------
	// Refusals
	// ---------------------------------------------------------------------------------------------

	public function test_another_customers_licence_is_refused_at_cart_and_at_fulfilment(): void {
		$base     = $this->customer_with( 'month' );
		$stranger = $this->create_customer();

		$this->assertRefused( 'not_yours', $this->service()->check_for_user( $stranger, $base['license'], $this->addon( 'FBR monthly' ) ) );

		$order = $this->buy_addon( $this->addon( 'FBR monthly' ), $stranger, $base['license_id'] );
		$this->assertNull( $this->line( $base['license_id'], 'fbr' ) );
		$this->assertSame( '', (string) $order->get_meta( '_wplm_plan_fulfilled' ), 'Left retryable, with a note.' );
		$this->assertSame( 5000.0, $this->total( $base['subscription_id'] ) );
	}

	public function test_a_licence_of_another_product_is_refused(): void {
		$customer = $this->create_customer();
		$classic  = $this->make( LicenseService::class )->create(
			array(
				'key_string' => 'CL-' . wp_generate_password( 12, false ),
				'user_id'    => $customer,
			)
		);

		$this->assertRefused( 'wrong_product', $this->service()->check_for_user( $customer, $classic, $this->addon( 'FBR monthly' ) ) );
	}

	public function test_a_revoked_or_suspended_licence_is_refused(): void {
		$base = $this->customer_with( 'month' );
		foreach ( array( 4, 5, 6 ) as $status ) {
			$this->set_row( 'licenses', $base['license_id'], array( 'status' => $status ) );
			$this->assertRefused( 'licence_status', $this->service()->check( $this->licence( $base['license_id'] ), $this->addon( 'FBR monthly' ) ) );
		}
	}

	/** Move every line of a licence to one paid-through date. */
	private function paid_through( int $license_id, string $date ): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wplm_entitlements', array( 'paid_through' => $date ), array( 'license_id' => $license_id ) );
	}

	public function test_a_read_only_base_is_refused(): void {
		$base = $this->customer_with( 'month' );
		$this->paid_through( $base['license_id'], EntitlementService::add_period( wp_date( 'Y-m-d' ), -60, 'day' ) );

		$this->assertRefused( 'base_read_only', $this->service()->check( $base['license'], $this->addon( 'FBR monthly' ) ) );
	}

	/** In grace the plan is overdue: an add-on aligned to it would be paid for again at the next renewal. */
	public function test_a_plan_in_grace_must_pay_its_renewal_first(): void {
		$base = $this->customer_with( 'month' );
		$this->paid_through( $base['license_id'], EntitlementService::add_period( wp_date( 'Y-m-d' ), -3, 'day' ) );

		$refused = $this->service()->check( $base['license'], $this->addon( 'FBR monthly' ) );
		$this->assertRefused( 'overdue', $refused );
		$this->assertSame( "Pay your plan's renewal first, then add this.", $refused->get_error_message() );

		$this->paid_through( $base['license_id'], wp_date( 'Y-m-d' ) );
		$this->assertTrue( $this->service()->check( $base['license'], $this->addon( 'FBR monthly' ) ), 'Paid through today is still paid up.' );
	}

	public function test_an_on_hold_plan_must_pay_its_renewal_first(): void {
		$base = $this->customer_with( 'month' );
		$this->set_row( 'subscriptions', $base['subscription_id'], array( 'status' => 'on-hold' ) );

		$this->assertRefused( 'overdue', $this->service()->check( $base['license'], $this->addon( 'FBR monthly' ) ) );
	}

	public function test_a_plan_in_trial_takes_addons_after_the_trial(): void {
		$base = $this->customer_with( 'month' );
		$this->set_row( 'subscriptions', $base['subscription_id'], array( 'status' => 'trial' ) );

		$refused = $this->service()->check( $base['license'], $this->addon( 'FBR monthly' ) );
		$this->assertRefused( 'trial', $refused );
		$this->assertSame( 'Add-ons can be bought after your trial ends.', $refused->get_error_message() );
	}

	public function test_a_lifetime_licence_is_unaffected_by_the_renewal_rules(): void {
		$base = $this->customer_with( 'lifetime' );

		$this->assertTrue( $this->service()->check( $base['license'], $this->addon( 'FBR lifetime' ) ) );
		$this->assertTrue( $this->service()->check( $base['license'], $this->addon( 'Assets 30-day pack' ) ) );
	}

	public function test_the_product_page_says_why_an_overdue_licence_cannot_take_addons(): void {
		$overdue = $this->customer_with( 'month' );
		$this->paid_through( $overdue['license_id'], EntitlementService::add_period( wp_date( 'Y-m-d' ), -3, 'day' ) );
		wp_set_current_user( $overdue['customer'] );
		global $product;
		$product = $this->addon_product;

		$this->assertFalse( $this->checkout()->plan_is_purchasable( true, $this->addon_product ) );
		ob_start();
		$this->checkout()->render_addon_notice();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( 'Pay your plan&#039;s renewal first, then add this.', $notice );
		$this->assertStringContainsString( strtoupper( substr( $overdue['license']->license_key, -4 ) ), $notice );

		// A second, paid-up licence is offered; the overdue one is listed, disabled, with the reason.
		$paid = $this->customer_with( 'month' );
		$this->set_row( 'licenses', $paid['license_id'], array( 'user_id' => $overdue['customer'] ) );
		ob_start();
		$this->checkout()->render_package_options();
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '/<option value="' . $paid['license_id'] . '"\s+selected/', $html );
		$this->assertMatchesRegularExpression( '/<option value="' . $overdue['license_id'] . '" disabled>[^<]*Pay your plan/', $html );
	}

	/** Seats and phones are counted by the product; the computers per key stay as they were. */
	public function test_a_seats_addon_leaves_computers_per_key_unchanged(): void {
		$base   = $this->customer_with( 'month' );
		$before = $this->license_row( $base['license_id'] )['max_activations'];

		$this->buy_addon( $this->addon( 'Extra seat monthly' ), $base['customer'], $base['license_id'], 2 );

		$this->assertSame( 2, $this->line( $base['license_id'], 'seats', 'addon' )->qty );
		$this->assertSame( $before, $this->license_row( $base['license_id'] )['max_activations'] );
	}

	public function test_a_cancelled_plan_subscription_is_refused(): void {
		$base = $this->customer_with( 'month' );
		$this->set_row( 'subscriptions', $base['subscription_id'], array( 'status' => 'cancelled' ) );

		$this->assertRefused( 'subscription_inactive', $this->service()->check( $base['license'], $this->addon( 'FBR monthly' ) ) );
	}

	public function test_a_module_the_licence_already_has_for_the_same_term_is_refused(): void {
		$base = $this->customer_with( 'month' );
		$this->buy_addon( $this->addon( 'FBR monthly' ), $base['customer'], $base['license_id'] );

		$this->assertRefused( 'already_has', $this->service()->check( $base['license'], $this->addon( 'FBR monthly' ) ) );
		$this->assertTrue( $this->service()->check( $base['license'], $this->addon( 'Extra user monthly' ) ), 'Limits always add up.' );
	}

	public function test_more_than_one_of_a_module_only_addon_is_refused(): void {
		$base = $this->customer_with( 'month' );

		$this->assertRefused( 'quantity', $this->service()->check_for_user( $base['customer'], $base['license'], $this->addon( 'FBR monthly' ), 2 ) );
		$this->assertTrue( $this->service()->check_for_user( $base['customer'], $base['license'], $this->addon( 'Extra user monthly' ), 2 ) );
	}

	public function test_a_profile_requirement_must_be_met(): void {
		$this->make( ProfileRegistry::class )->get( 'acme-office' )->requires = array( 'subcontract' => array( 'factory', 'pos' ) );
		$base = $this->customer_with( 'month' );

		$refused = $this->service()->check( $base['license'], $this->addon( 'Subcontracting monthly' ) );
		$this->assertRefused( 'requires', $refused );
		$this->assertSame( 'Subcontracting needs Factory or Point of Sale on the licence.', $refused->get_error_message() );

		$this->make( EntitlementService::class )->add_line( $base['license_id'], array( 'kind' => 'module', 'code' => 'factory' ) );
		$this->assertTrue( $this->service()->check( $base['license'], $this->addon( 'Subcontracting monthly' ) ) );
	}

	public function test_a_logged_out_visitor_cannot_buy_and_is_told_to_log_in(): void {
		wp_set_current_user( 0 );
		$base = $this->customer_with( 'month' );

		$this->assertRefused( 'logged_out', $this->service()->check_for_user( 0, $base['license'], $this->addon( 'FBR monthly' ) ) );
		$this->assertFalse( $this->checkout()->plan_is_purchasable( true, $this->addon_product ) );

		global $product;
		$product = $this->addon_product;
		ob_start();
		$this->checkout()->render_addon_notice();
		$this->assertStringContainsString( 'Log in to add this to your licence.', (string) ob_get_clean() );
	}

	public function test_the_filter_can_refuse_or_reword_but_not_lift_a_refusal(): void {
		$base = $this->customer_with( 'month' );
		add_filter(
			'wplm_addon_eligibility',
			static function ( $result, $license, $package ) {
				if ( 'FBR yearly' === $package->name ) {
					return true; // Tries to lift WPLM's billing refusal.
				}
				if ( 'Extra user monthly' === $package->name ) {
					return new \WP_Error( 'acme_rule', 'Not on Tuesdays.' );
				}
				return $result;
			},
			10,
			3
		);

		$this->assertRefused( 'billing_mismatch', $this->service()->check( $base['license'], $this->addon( 'FBR yearly' ) ) );
		$custom = $this->service()->check( $base['license'], $this->addon( 'Extra user monthly' ) );
		$this->assertWPError( $custom );
		$this->assertSame( 'Not on Tuesdays.', $custom->get_error_message() );
	}

	public function test_an_addon_plan_is_never_fulfilled_as_a_new_licence_even_with_a_missing_target(): void {
		$base  = $this->customer_with( 'month' );
		$order = $this->buy_addon( $this->addon( 'FBR monthly' ), $base['customer'], 999999 );

		$this->assertSame( 1, $this->licence_count( $base['customer'] ) );
		$this->assertSame( '', (string) $this->the_item( $order )->get_meta( '_wplm_license_ids' ) );
		$this->assertSame( '', (string) $order->get_meta( '_wplm_plan_fulfilled' ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Cart
	// ---------------------------------------------------------------------------------------------

	public function test_add_to_cart_refuses_someone_elses_licence_and_accepts_your_own(): void {
		$base     = $this->customer_with( 'month' );
		$stranger = $this->create_customer();
		wc_load_cart();
		wc_clear_notices();
		$_POST['wplm_package_id']        = (string) $this->addon( 'FBR monthly' )->id;
		$_POST['wplm_target_license_id'] = (string) $base['license_id'];

		wp_set_current_user( $stranger );
		$this->assertFalse( $this->checkout()->validate_add_to_cart( true, $this->addon_product->get_id(), 1 ) );
		$this->assertSame( 1, wc_notice_count( 'error' ) );

		wc_clear_notices();
		wp_set_current_user( $base['customer'] );
		$this->assertTrue( $this->checkout()->validate_add_to_cart( true, $this->addon_product->get_id(), 1 ) );
		$this->assertSame( 0, wc_notice_count( 'error' ) );
	}

	public function test_checkout_rechecks_an_addon_whose_licence_changed_after_it_was_added(): void {
		$base = $this->customer_with( 'month' );
		wp_set_current_user( $base['customer'] );
		wc_load_cart();
		WC()->cart->empty_cart();
		wc_clear_notices();
		$key = WC()->cart->add_to_cart(
			$this->addon_product->get_id(),
			1,
			0,
			array(),
			array(
				'wplm_package_id'       => $this->addon( 'FBR monthly' )->id,
				'wplm_addon_license_id' => $base['license_id'],
			)
		);
		$this->assertNotFalse( $key, 'The owner can add it.' );

		$this->checkout()->check_cart_addons();
		$this->assertSame( 0, wc_notice_count( 'error' ) );

		$this->set_row( 'licenses', $base['license_id'], array( 'status' => 4 ) );
		$this->checkout()->check_cart_addons();
		$this->assertSame( 1, wc_notice_count( 'error' ), 'A suspended licence stops the checkout.' );

		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	// ---------------------------------------------------------------------------------------------
	// Final review (2026-10-15): a line refunded in full is never added; an order paid later is checked
	// ---------------------------------------------------------------------------------------------

	/** An order of two stored add-ons for a licence, not paid yet. */
	private function two_addons_order( array $base, string $first, string $second ): \WC_Order {
		$order = wc_create_order( array( 'customer_id' => $base['customer'] ) );
		foreach ( array( $first, $second ) as $name ) {
			$item = $order->get_item( $order->add_product( $this->addon_product, 1 ) );
			$item->add_meta_data( '_wplm_package_id', $this->addon( $name )->id, true );
			$item->add_meta_data( '_wplm_addon_license_id', $base['license_id'], true );
			$item->save();
		}
		$order->set_payment_method( 'bacs' );
		$order->set_billing_email( 'customer' . $base['customer'] . '@example.org' );
		$order->calculate_totals();
		$order->save();
		return $order;
	}

	public function test_a_stored_addon_refunded_in_full_is_never_added_when_the_order_is_completed_again(): void {
		$base  = $this->customer_with( 'month' );
		$order = $this->two_addons_order( $base, 'FBR monthly', 'Extra user monthly' );
		add_option( 'wplm_addons_lock_' . $base['license_id'], (string) time(), '', 'no' );
		$order->update_status( 'on-hold' );
		$order->update_status( 'completed' );
		delete_option( 'wplm_addons_lock_' . $base['license_id'] );
		$order = wc_get_order( $order->get_id() );
		$fbr   = array_values( $order->get_items() )[0];
		$this->assertNotWPError(
			wc_create_refund(
				array(
					'order_id'   => $order->get_id(),
					'amount'     => (float) $fbr->get_total(),
					'line_items' => array(
						$fbr->get_id() => array(
							'qty'          => 1,
							'refund_total' => (float) $fbr->get_total(),
						),
					),
				)
			)
		);

		$order->update_status( 'processing' );
		wc_get_order( $order->get_id() )->update_status( 'completed' );

		$order = wc_get_order( $order->get_id() );
		$this->assertNull( $this->line( $base['license_id'], 'fbr', 'addon' ), 'Refunded: never added.' );
		$this->assertNotNull( $this->line( $base['license_id'], 'users', 'addon' ), 'The line paid for is added.' );
		$this->assertSame( 6000.0, $this->total( $base['subscription_id'] ), 'Billed for the plan and the user, never the refunded FBR.' );
		$this->assertSame( '1', (string) $order->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertStringContainsString( 'refunded in full', $this->notes_of( $order ) );
	}

	public function test_a_stored_addon_order_paid_later_is_refused_when_the_licence_has_since_got_it(): void {
		$base  = $this->customer_with( 'month' );
		$stale = wc_create_order( array( 'customer_id' => $base['customer'] ) );
		$item  = $stale->get_item( $stale->add_product( $this->addon_product, 1 ) );
		$item->add_meta_data( '_wplm_package_id', $this->addon( 'FBR monthly' )->id, true );
		$item->add_meta_data( '_wplm_addon_license_id', $base['license_id'], true );
		$item->save();
		$stale->calculate_totals();
		$stale->save();
		$stale->update_status( 'failed' );
		$this->buy_addon( $this->addon( 'FBR monthly' ), $base['customer'], $base['license_id'] );

		wc_clear_notices();
		do_action( 'woocommerce_before_pay_action', wc_get_order( $stale->get_id() ) );
		$notices = wc_get_notices( 'error' );
		$errors  = new \WP_Error();
		do_action( 'woocommerce_checkout_validate_order_before_payment', wc_get_order( $stale->get_id() ), $errors );
		wc_clear_notices();

		$this->assertNotEmpty( $notices, 'Never charged twice for one add-on.' );
		$this->assertStringContainsString( 'already has FBR', (string) ( $notices[0]['notice'] ?? '' ) );
		$this->assertTrue( $errors->has_errors() );
	}

	private function notes_of( \WC_Order $order ): string {
		return implode( "\n", array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) );
	}

	/** A stored add-on order for a licence, not paid yet. */
	private function addon_order( string $name, array $base ): \WC_Order {
		$order = wc_create_order( array( 'customer_id' => $base['customer'] ) );
		$item  = $order->get_item( $order->add_product( $this->addon_product, 1 ) );
		$item->add_meta_data( '_wplm_package_id', $this->addon( $name )->id, true );
		$item->add_meta_data( '_wplm_addon_license_id', $base['license_id'], true );
		$item->save();
		$order->set_payment_method( 'bacs' );
		$order->set_billing_email( 'customer' . $base['customer'] . '@example.org' );
		$order->calculate_totals();
		$order->save();
		return $order;
	}

	public function test_a_stored_addon_is_refused_before_payment_while_a_paid_order_not_completed_yet_adds_it(): void {
		$base  = $this->customer_with( 'month' );
		$stale = $this->addon_order( 'FBR monthly', $base );
		$stale->update_status( 'failed' );
		$paid = $this->addon_order( 'FBR monthly', $base );
		$paid->update_status( 'on-hold' ); // A bank transfer awaiting the shop.

		wc_clear_notices();
		do_action( 'woocommerce_before_pay_action', wc_get_order( $stale->get_id() ) );
		$notices = wc_get_notices( 'error' );
		$errors  = new \WP_Error();
		do_action( 'woocommerce_checkout_validate_order_before_payment', wc_get_order( $stale->get_id() ), $errors );
		wc_clear_notices();

		$this->assertNotEmpty( $notices, 'Never charged twice for one add-on.' );
		$this->assertStringContainsString( 'Your order #' . $paid->get_order_number() . ', not completed yet, already adds FBR', (string) ( $notices[0]['notice'] ?? '' ) );
		$this->assertTrue( $errors->has_errors() );

		// Add to cart, and the checkout's check of the cart.
		wc_load_cart();
		WC()->cart->empty_cart();
		wp_set_current_user( $base['customer'] );
		$_POST['wplm_target_license_id'] = (string) $base['license_id'];
		$_POST['wplm_package_id']        = (string) $this->addon( 'FBR monthly' )->id;
		wc_clear_notices();
		$this->assertFalse( $this->checkout()->validate_add_to_cart( true, $this->addon_product->get_id(), 1 ) );
		$_POST['wplm_package_id'] = (string) $this->addon( 'Extra user monthly' )->id;
		wc_clear_notices();
		$this->assertTrue( $this->checkout()->validate_add_to_cart( true, $this->addon_product->get_id(), 1 ), 'A limit adds up: never held.' );
		unset( $_POST['wplm_package_id'], $_POST['wplm_target_license_id'] );
		$this->assertNotFalse(
			WC()->cart->add_to_cart(
				$this->addon_product->get_id(),
				1,
				0,
				array(),
				array(
					'wplm_package_id'       => $this->addon( 'FBR monthly' )->id,
					'wplm_addon_license_id' => $base['license_id'],
				)
			)
		);
		wc_clear_notices();
		$this->checkout()->check_cart_addons();
		$this->assertSame( 1, wc_notice_count( 'error' ), 'The checkout stops.' );
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function test_a_stored_addon_refused_for_good_is_marked_settled_and_holds_nothing(): void {
		$base  = $this->customer_with( 'month' );
		$stale = $this->addon_order( 'FBR monthly', $base );
		$stale->update_status( 'processing' ); // Paid; its FBR is held for it.
		$this->buy_addon( $this->addon( 'FBR monthly' ), $base['customer'], $base['license_id'] ); // FBR added by another order since.
		$total = $this->total( $base['subscription_id'] );
		wc_get_order( $stale->get_id() )->update_status( 'completed' );

		$stale   = wc_get_order( $stale->get_id() );
		$refusal = implode( "\n", array_filter( explode( "\n", $this->notes_of( $stale ) ), static fn( $n ) => false !== strpos( $n, 'already has FBR' ) ) );
		$this->assertStringContainsString( 'A retry cannot add it: refund this line, or use Mark add-ons settled.', $refusal );
		$this->assertStringNotContainsString( 'Completed again', $refusal, 'A retry never cures "already has".' );
		$this->assertSame( '', (string) $stale->get_meta( '_wplm_plan_fulfilled' ) );
		$this->assertArrayHasKey( 'fbr', $this->service()->unfulfilled_addon_modules( $base['license'] ) );

		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$result = ( new AddonSettlement( $this->service() ) )->settle( $stale->get_id(), array( $this->the_item( $stale )->get_id() ), $admin, 'Credited on the next invoice' );

		$this->assertTrue( $result['ok'], $result['message'] );
		$stale = wc_get_order( $stale->get_id() );
		$this->assertSame( '1', (string) $stale->get_meta( '_wplm_plan_fulfilled' ), 'Its only line is done.' );
		$this->assertSame( array(), $this->service()->unfulfilled_addon_modules( $base['license'] ), 'FBR is no longer held.' );
		$this->assertCount( 1, array_filter( $this->lines( $base['license_id'] ), static fn( $l ) => 'fbr' === $l->code ), 'Nothing written.' );
		$this->assertSame( $total, $this->total( $base['subscription_id'] ), 'Nothing billed.' );
		$this->assertStringContainsString( 'marked settled by', $this->notes_of( $stale ) );
	}
}
