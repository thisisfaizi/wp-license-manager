<?php
/**
 * "Skip it once" (1.3.0, owner decision D1): a dated add-on that joined a subscription after a renewal
 * that was then refunded keeps the period it was bought for. The next renewal charges for the rest and
 * not for that add-on, extends the rest only, and the lines are in step again; the renewal after that
 * bills everything together. The card (cron) path and the manual invoice path agree.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Subscriptions;

use WPLM\Integrations\WooCommerce\SelfServiceRenewal;
use WPLM\Licensing\ProfileRegistry;
use WPLM\Repositories\EntitlementRepository;
use WPLM\Repositories\SubscriptionRepository;
use WPLM\Services\EntitlementService;
use WPLM\Services\Subscriptions\RenewalProcessor;
use WPLM\Services\Subscriptions\SubscriptionService;
use WPLM\Tests\TestCase;

class PaidAheadRenewalTest extends TestCase {

	/** @var float[] Amounts the fake card gateway was asked to charge. */
	private array $charges = array();

	/** @var int[] Orders the fake gateway module built for card charges. */
	private array $created = array();

	public function set_up(): void {
		parent::set_up();
		add_filter(
			'wplm_gateway_charge',
			function ( $result, $token, $amount ) {
				unset( $result, $token );
				$this->charges[] = (float) $amount;
				return array( 'txn' => 'txn_ok' );
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		$this->make( ProfileRegistry::class )->get( 'acme-office' )->requires = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// ---------------------------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------------------------

	/**
	 * A monthly profile licence paid through yesterday, renewed (one month), Factory bought on a later
	 * order (joining the subscription, co-termed with the renewed month), then the renewal refunded.
	 *
	 * @return array{license_id:int, subscription_id:int, customer:int, p0:string, p1:string, factory:int, factory_order:int, item:int, total:float, item_total:float}
	 */
	private function refunded_with_factory_joined(): array {
		$plan     = $this->create_plan(
			array(
				array(
					'name'             => 'Distribution monthly',
					'billing_type'     => 'recurring',
					'billing_period'   => 'month',
					'billing_interval' => 1,
					'price'            => 5000,
					'entitlements'     => array(
						array( 'kind' => 'module', 'code' => 'base' ),
						array(
							'kind' => 'limit',
							'code' => 'users',
							'qty'  => 3,
						),
					),
				),
			),
			array( 'profile' => 'acme-office' )
		);
		$customer = $this->create_customer();
		$bought   = $this->buy( $this->create_plan_product( $plan['plan_id'] ), $plan['packages'][0], $customer );
		$sub_id   = $bought['subscription_id'];
		$p0       = EntitlementService::add_period( wp_date( 'Y-m-d' ), -1, 'day' );
		$this->lines_through( $sub_id, $p0 );

		$renewal = $this->renew( $sub_id );
		$p1      = EntitlementService::renewed_paid_through( $p0, $p0, 0, 1, 'month' );
		$this->assertSame( array( $p1 ), $this->dates( $sub_id ) );

		// Factory is bought on a later order and joins the subscription, paid through the renewed month.
		$addons  = $this->create_plan(
			array(
				array(
					'name'             => 'Factory monthly',
					'billing_type'     => 'recurring',
					'billing_period'   => 'month',
					'billing_interval' => 1,
					'price'            => 1500,
					'entitlements'     => array( array( 'kind' => 'module', 'code' => 'factory' ) ),
				),
			),
			array(
				'name'    => 'Acme add-ons',
				'profile' => 'acme-office',
				'kind'    => 'addon',
			)
		);
		$product = $this->create_plan_product( $addons['plan_id'] );
		$order   = wc_create_order( array( 'customer_id' => $customer ) );
		$item_id = $order->add_product( $product, 1 );
		$item    = $order->get_item( $item_id );
		$item->add_meta_data( '_wplm_package_id', $addons['packages'][0]->id, true );
		$item->add_meta_data( '_wplm_addon_license_id', $bought['license_id'], true );
		$item->save();
		$order->set_payment_method( 'bacs' );
		$order->set_billing_email( 'customer' . $customer . '@example.org' );
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'on-hold' );
		$order->update_status( 'completed' );

		$factory = null;
		foreach ( $this->make( EntitlementRepository::class )->get_by_subscription( $sub_id ) as $line ) {
			if ( 'factory' === $line->code ) {
				$factory = $line;
			}
		}
		$this->assertNotNull( $factory, 'Factory joined the subscription.' );
		$this->assertSame( $p1, $factory->paid_through, 'Co-termed with the renewed month.' );
		$sub_item = null;
		foreach ( $this->make( SubscriptionRepository::class )->get_items( $sub_id ) as $sub_item_row ) {
			if ( in_array( $factory->id, array_map( 'intval', (array) ( $sub_item_row->meta['entitlement_ids'] ?? array() ) ), true ) ) {
				$sub_item = $sub_item_row;
			}
		}
		$this->assertNotNull( $sub_item, 'Billed as an item of the subscription.' );

		wc_get_order( $renewal->get_id() )->update_status( 'refunded' );
		$this->assertSame( 'on-hold', $this->subscription_row( $sub_id )['status'] );
		$this->assertSame( $p1, $this->through( $factory->id ), 'Factory keeps the period it was bought for.' );
		$this->assertSame( array( $p0, $p1 ), $this->dates( $sub_id ), 'The rest is back a month.' );

		return array(
			'license_id'      => $bought['license_id'],
			'subscription_id' => $sub_id,
			'customer'        => $customer,
			'p0'              => $p0,
			'p1'              => $p1,
			'factory'         => $factory->id,
			'factory_order'   => $order->get_id(),
			'item'            => $sub_item->id,
			'total'           => (float) $this->subscription_row( $sub_id )['recurring_total'],
			'item_total'      => (float) $sub_item->line_total,
		);
	}

	private function lines_through( int $subscription_id, string $through ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wplm_entitlements SET paid_through = %s WHERE subscription_id = %d AND paid_through IS NOT NULL", $through, $subscription_id ) );
		$this->set_row( 'subscriptions', $subscription_id, array( 'next_payment' => $this->start_of_day_after( $through ) ) );
	}

	private function start_of_day_after( string $through ): string {
		return ( new \DateTimeImmutable( EntitlementService::add_period( $through, 1, 'day' ) . ' 00:00:00', wp_timezone() ) )
			->setTimezone( new \DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d H:i:s' );
	}

	/** The customer pays a renewal invoice: the order is created, then marked Completed. */
	private function renew( int $subscription_id ): \WC_Order {
		$order = $this->make( SelfServiceRenewal::class )->create_renewal_order_object( $subscription_id );
		$this->assertInstanceOf( \WC_Order::class, $order );
		return $this->pay( $order );
	}

	private function pay( \WC_Order $order ): \WC_Order {
		$order->set_status( 'on-hold' );
		$order->save();
		$order->update_status( 'completed' );
		return wc_get_order( $order->get_id() );
	}

	/** @return string[] The distinct paid-through dates of the subscription's dated lines, sorted. */
	private function dates( int $subscription_id ): array {
		$dates = array();
		foreach ( $this->make( EntitlementRepository::class )->get_by_subscription( $subscription_id ) as $line ) {
			if ( null !== $line->paid_through ) {
				$dates[] = $line->paid_through;
			}
		}
		$dates = array_values( array_unique( $dates ) );
		sort( $dates );
		return $dates;
	}

	private function through( int $line_id ): ?string {
		return $this->make( EntitlementRepository::class )->find_by_id( $line_id )->paid_through;
	}

	private function notes( int $order_id ): string {
		return implode( "\n", array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $order_id ) ) ) );
	}

	/** A stored card; each cron charge is recorded on an order a gateway module builds. */
	private function card( array $s ): void {
		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'tok_test' );
		$token->set_gateway_id( 'm527card' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( gmdate( 'Y', strtotime( '+2 years' ) ) );
		$token->set_user_id( $s['customer'] );
		$token->save();
		$this->set_row( 'subscriptions', $s['subscription_id'], array( 'payment_token_id' => $token->get_id() ) );
		$customer = $s['customer'];
		add_filter(
			'wplm_create_renewal_order',
			function ( $id ) use ( $customer ) {
				unset( $id );
				$order = wc_create_order( array( 'customer_id' => $customer ) );
				$order->set_status( 'completed' );
				$order->save();
				$this->created[] = $order->get_id();
				return $order->get_id();
			}
		);
	}

	// ---------------------------------------------------------------------------------------------
	// The card (cron) path
	// ---------------------------------------------------------------------------------------------

	public function test_resumed_after_the_refund_the_card_is_charged_without_the_joined_add_on_once(): void {
		$s      = $this->refunded_with_factory_joined();
		$sub_id = $s['subscription_id'];
		$this->card( $s );
		$p2 = EntitlementService::renewed_paid_through( $s['p1'], $s['p1'], 0, 1, 'month' );

		$this->make( SubscriptionService::class )->resume( $sub_id );
		do_action( 'wplm_process_renewals' );

		$this->assertSame( array( round( $s['total'] - $s['item_total'], 4 ) ), $this->charges, 'The base is charged; Factory, already paid through the new month, is not.' );
		$this->assertSame( array( $s['p1'] ), $this->dates( $sub_id ), 'Every line in step again.' );
		$this->assertSame( $s['p1'], $this->through( $s['factory'] ), 'Factory was not extended.' );
		$this->assertSame( $this->start_of_day_after( $s['p1'] ), $this->subscription_row( $sub_id )['next_payment'] );
		$this->assertSame( (float) $s['total'], (float) $this->subscription_row( $sub_id )['recurring_total'], 'The subscription still bills Factory: only this charge left it out.' );
		$this->assertCount( 1, $this->created );
		$order = wc_get_order( $this->created[0] );
		$this->assertStringContainsString( 'WPLM: Factory not charged this time — already paid through ' . $s['p1'] . '.', $this->notes( $order->get_id() ) );
		$skipped = json_decode( (string) $order->get_meta( RenewalProcessor::SKIPPED_META ), true );
		$this->assertSame( array( $s['item'] ), array_map( 'intval', array_keys( $skipped ) ) );
		$snapshot = json_decode( (string) $order->get_meta( RenewalProcessor::SNAPSHOT_META ), true );
		$this->assertArrayNotHasKey( $s['factory'], $snapshot['lines'], 'Not what this renewal moved: a refund of it leaves Factory alone.' );
		global $wpdb;
		$this->assertSame( round( $s['total'] - $s['item_total'], 4 ), (float) $wpdb->get_var( $wpdb->prepare( "SELECT amount FROM {$wpdb->prefix}wplm_subscription_renewals WHERE order_id = %d AND type = 'renewal'", $order->get_id() ) ), 'The renewal row records what was charged.' );

		// The next charge bills everything together again.
		$this->set_row( 'subscriptions', $sub_id, array( 'next_payment' => $this->utc( -MINUTE_IN_SECONDS ) ) );
		do_action( 'wplm_process_renewals' );

		$this->assertSame( (float) $s['total'], end( $this->charges ), 'Factory is charged with the rest.' );
		$this->assertSame( array( $p2 ), $this->dates( $sub_id ), 'All lines move together.' );
		$this->assertStringNotContainsString( 'not charged this time', $this->notes( (int) end( $this->created ) ) );
	}

	// ---------------------------------------------------------------------------------------------
	// The manual invoice path
	// ---------------------------------------------------------------------------------------------

	public function test_resumed_after_the_refund_the_invoice_leaves_out_the_joined_add_on_once(): void {
		$s      = $this->refunded_with_factory_joined();
		$sub_id = $s['subscription_id'];
		$p2     = EntitlementService::renewed_paid_through( $s['p1'], $s['p1'], 0, 1, 'month' );
		$before = array_map( static fn( $o ) => $o->get_id(), $this->renewal_orders( $sub_id ) );

		$this->make( SubscriptionService::class )->resume( $sub_id );
		do_action( 'wplm_process_renewals' );

		$new = array_values( array_diff( array_map( static fn( $o ) => $o->get_id(), $this->renewal_orders( $sub_id ) ), $before ) );
		$this->assertCount( 1, $new, 'One renewal invoice was issued.' );
		$invoice = wc_get_order( $new[0] );
		$this->assertSame( 'pending', $invoice->get_status() );
		$this->assertSame( round( $s['total'] - $s['item_total'], 4 ), (float) $invoice->get_total(), 'Factory is not invoiced this time.' );
		$this->assertStringContainsString( 'WPLM: Factory not charged this time — already paid through ' . $s['p1'] . '.', $this->notes( $invoice->get_id() ) );

		// Paid late (dates moved meanwhile): what is extended follows what was invoiced.
		$this->pay( $invoice );

		$this->assertSame( array( $s['p1'] ), $this->dates( $sub_id ), 'In step again.' );
		$this->assertSame( $s['p1'], $this->through( $s['factory'] ), 'Factory was not extended.' );
		$this->assertSame( 'active', $this->subscription_row( $sub_id )['status'] );

		// The next renewal invoices everything.
		$next = $this->make( SelfServiceRenewal::class )->create_renewal_order_object( $sub_id );
		$this->assertSame( (float) $s['total'], (float) $next->get_total(), 'Factory is invoiced with the rest.' );
		$this->assertStringNotContainsString( 'not charged this time', $this->notes( $next->get_id() ) );
		$this->pay( $next );
		$this->assertSame( array( $p2 ), $this->dates( $sub_id ), 'All lines move together.' );
	}

	public function test_an_invoice_paid_keeps_to_what_it_left_out_even_when_computed_again_it_would_differ(): void {
		$s       = $this->refunded_with_factory_joined();
		$sub_id  = $s['subscription_id'];
		$this->make( SubscriptionService::class )->resume( $sub_id );
		$invoice = $this->make( SelfServiceRenewal::class )->create_renewal_order_object( $sub_id );
		$this->assertSame( round( $s['total'] - $s['item_total'], 4 ), (float) $invoice->get_total() );
		// Before it is paid, Factory's line is moved back by hand: computed now, it would be charged.
		$this->make( EntitlementRepository::class )->update( $s['factory'], array( 'paid_through' => $s['p0'] ) );

		$this->pay( $invoice );

		$this->assertSame( $s['p0'], $this->through( $s['factory'] ), 'Not invoiced, so not extended.' );
	}

	public function test_lines_in_step_skip_nothing(): void {
		$s      = $this->refunded_with_factory_joined();
		$sub_id = $s['subscription_id'];
		// Every line in step (the owner set them by hand): nothing is paid ahead.
		$this->lines_through( $sub_id, $s['p0'] );
		$sub = $this->make( SubscriptionRepository::class )->find_by_id( $sub_id );

		$this->assertSame( array(), $this->make( RenewalProcessor::class )->items_paid_ahead( $sub ) );
		$this->assertSame( (float) $s['total'], $this->make( RenewalProcessor::class )->renewal_amount( $sub, array() ) );
	}

	public function test_an_add_ons_only_invoice_lists_only_the_add_ons_it_charges(): void {
		$license = $this->make( \WPLM\Services\LicenseService::class )->create(
			array(
				'key_string'      => 'SL-' . wp_generate_password( 16, false ),
				'profile'         => 'acme-office',
				'max_activations' => 1,
				'user_id'         => $this->create_customer(),
			)
		);
		$customer = $this->create_customer();
		$p0       = EntitlementService::add_period( wp_date( 'Y-m-d' ), -1, 'day' );
		$p1       = EntitlementService::renewed_paid_through( $p0, $p0, 0, 1, 'month' );
		$sub      = $this->make( SubscriptionService::class )->create(
			array(
				'user_id'           => $customer,
				'billing_interval'  => 1,
				'billing_period'    => 'month',
				'recurring_total'   => 2500,
				'currency'          => 'PKR',
				'addons_license_id' => $license->id,
			)
		);
		$this->make( SubscriptionService::class )->update_status( $sub->id, 'active' );
		$repo  = $this->make( EntitlementRepository::class );
		$items = $this->make( SubscriptionRepository::class );
		foreach ( array( array( 'fbr', 'FBR', 1000.0, $p0 ), array( 'factory', 'Factory', 1500.0, $p1 ) ) as $add ) {
			$line = $repo->create(
				array(
					'license_id'      => $license->id,
					'kind'            => 'module',
					'code'            => $add[0],
					'qty'             => 1,
					'paid_through'    => $add[3],
					'source'          => 'addon',
					'subscription_id' => $sub->id,
				)
			);
			$items->add_item(
				$sub->id,
				array(
					'quantity'   => 1,
					'line_total' => $add[2],
					'meta'       => array(
						'addon'           => true,
						'label'           => $add[1],
						'entitlement_ids' => array( $line ),
					),
				)
			);
		}
		$this->set_row( 'subscriptions', $sub->id, array( 'next_payment' => $this->start_of_day_after( $p0 ) ) );

		$invoice = $this->make( SelfServiceRenewal::class )->create_renewal_order_object( $sub->id );

		$this->assertSame( 1000.0, (float) $invoice->get_total(), 'Only FBR is invoiced.' );
		$names = array_map( static fn( $fee ) => $fee->get_name(), array_values( $invoice->get_fees() ) );
		$this->assertCount( 1, $names );
		$this->assertStringStartsWith( 'FBR', $names[0] );
		$this->assertStringContainsString( 'Factory not charged this time — already paid through ' . $p1, $this->notes( $invoice->get_id() ) );

		$this->pay( $invoice );

		$this->assertSame( array( $p1 ), $this->dates( $sub->id ), 'In step again.' );
	}
}
