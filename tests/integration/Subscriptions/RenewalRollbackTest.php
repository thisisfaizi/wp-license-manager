<?php
/**
 * A full refund of a renewal order takes its period back (1.3.0): the payment kept a snapshot of what
 * it moved (`_wplm_renewal_snapshot`), and the refund shifts each thing back by exactly that — each
 * line's paid-through date, the subscription's next payment, a classic licence's expiry — never
 * overwriting what a later renewal moved further. A partial refund changes nothing and says so.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Subscriptions;

use WPLM\Integrations\WooCommerce\SelfServiceRenewal;
use WPLM\Models\Package;
use WPLM\Models\Plan;
use WPLM\Repositories\EntitlementRepository;
use WPLM\Repositories\SubscriptionRepository;
use WPLM\Services\EntitlementService;
use WPLM\Services\LicenseService;
use WPLM\Services\Subscriptions\RenewalProcessor;
use WPLM\Tests\TestCase;

class RenewalRollbackTest extends TestCase {

	/** @var array<int, array> `wplm_entitlement_saved` calls: [line, op]. */
	private array $saved = array();

	/** @var array<int, array> `wplm_renewal_rolled_back` calls. */
	private array $rolled = array();

	/** @var array<int, array> `wplm_license_term_extended` calls. */
	private array $extended = array();

	/** @var array|\WP_Error What the fake card gateway returns. */
	private $charge_result;

	public function set_up(): void {
		parent::set_up();
		add_action(
			'wplm_entitlement_saved',
			function ( $line, $op ) {
				$this->saved[] = array( $line, $op );
			},
			10,
			2
		);
		add_action(
			'wplm_renewal_rolled_back',
			function ( $order_id, $sub_id, $snapshot ) {
				$this->rolled[] = array( $order_id, $sub_id, $snapshot );
			},
			10,
			3
		);
		add_action(
			'wplm_license_term_extended',
			function ( $id, $new, $old ) {
				$this->extended[] = array( $id, $new, $old );
			},
			10,
			3
		);
		$this->charge_result = array( 'txn' => 'txn_ok' );
		add_filter( 'wplm_gateway_charge', fn() => $this->charge_result );
	}

	public function tear_down(): void {
		if ( function_exists( 'WC' ) && null !== WC()->cart ) {
			WC()->cart->empty_cart();
		}
		unset( $_POST['wplm_extras'], $_POST['wplm_package_id'], $_POST['wplm_extras_cycle'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// ---------------------------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------------------------

	/** An entitlement (profile) licence bought monthly or yearly. */
	private function buy_profile( string $period ): array {
		$plan   = $this->create_plan(
			array(
				array(
					'name'             => 'Distribution ' . $period,
					'billing_type'     => 'recurring',
					'billing_period'   => $period,
					'billing_interval' => 1,
					'price'            => 'month' === $period ? 5000 : 50000,
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
		$bought = $this->buy( $this->create_plan_product( $plan['plan_id'] ), $plan['packages'][0], $this->create_customer() );
		$this->assertGreaterThan( 0, $bought['subscription_id'] );
		$this->align_lines( $bought['subscription_id'] );
		return $bought;
	}

	/** A classic licence (an expiry, no profile) bought monthly. */
	private function buy_classic(): array {
		$plan   = $this->create_plan(
			array(
				array(
					'name'             => 'Monthly',
					'billing_type'     => 'recurring',
					'billing_period'   => 'month',
					'billing_interval' => 1,
					'price'            => 1000,
					'max_activations'  => 1,
				),
			)
		);
		$bought = $this->buy( $this->create_plan_product( $plan['plan_id'] ), $plan['packages'][0], $this->create_customer() );
		$this->assertGreaterThan( 0, $bought['license_id'] );
		$end = $this->safe_day( 1 ) . ' 10:00:00';
		$this->set_row( 'licenses', $bought['license_id'], array( 'expires_at' => $end ) );
		$this->set_row( 'subscriptions', $bought['subscription_id'], array( 'next_payment' => $end ) );
		return $bought;
	}

	/**
	 * A date from `$from_offset` days on whose next day is the 28th or earlier, so whole months added
	 * and taken back never clamp (the rule itself is pinned elsewhere), and a renewal paid today is
	 * inside grace.
	 */
	private function safe_day( int $from_offset = 0 ): string {
		$day = EntitlementService::add_period( wp_date( 'Y-m-d' ), $from_offset, 'day' );
		while ( (int) substr( EntitlementService::add_period( $day, 1, 'day' ), 8, 2 ) > 28 ) {
			$day = EntitlementService::add_period( $day, 1, 'day' );
		}
		return $day;
	}

	/** Every dated line of the subscription paid through one safe day, and its next payment the day after. */
	private function align_lines( int $subscription_id ): string {
		global $wpdb;
		$through = $this->safe_day( 1 );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wplm_entitlements SET paid_through = %s WHERE subscription_id = %d AND paid_through IS NOT NULL", $through, $subscription_id ) );
		$this->set_row( 'subscriptions', $subscription_id, array( 'next_payment' => $this->start_of_day_after( $through ) ) );
		return $through;
	}

	/** The start of the day after a paid-through date, in the site's time zone, as UTC. */
	private function start_of_day_after( string $through ): string {
		return ( new \DateTimeImmutable( EntitlementService::add_period( $through, 1, 'day' ) . ' 00:00:00', wp_timezone() ) )
			->setTimezone( new \DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d H:i:s' );
	}

	/** The customer pays a renewal invoice: the order is created, then marked Completed. */
	private function renew( int $subscription_id ): \WC_Order {
		$order = $this->make( SelfServiceRenewal::class )->create_renewal_order_object( $subscription_id );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->set_status( 'on-hold' );
		$order->save();
		$order->update_status( 'completed' );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '1', (string) $order->get_meta( '_wplm_renewal_applied' ) );
		return $order;
	}

	private function refund( \WC_Order $order ): \WC_Order {
		wc_get_order( $order->get_id() )->update_status( 'refunded' );
		return wc_get_order( $order->get_id() );
	}

	/** @return array<int, string|null> line id => paid_through, for the subscription's lines. */
	private function through( int $subscription_id ): array {
		$out = array();
		foreach ( $this->make( EntitlementRepository::class )->get_by_subscription( $subscription_id ) as $line ) {
			$out[ $line->id ] = $line->paid_through;
		}
		ksort( $out );
		return $out;
	}

	/** Every dated line moved to one date. */
	private function all( array $through, string $date ): array {
		return array_map( static fn( $d ) => null === $d ? null : $date, $through );
	}

	private function notes( \WC_Order $order ): string {
		return implode( "\n", array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) );
	}

	/** @return array<int, array> The renewal rows (type, status) of an order. */
	private function rows( int $order_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT type, status FROM {$wpdb->prefix}wplm_subscription_renewals WHERE order_id = %d ORDER BY id", $order_id ), ARRAY_A );
	}

	private function next_month( string $through, int $n = 1, string $period = 'month' ): string {
		return EntitlementService::renewed_paid_through( $through, $through, 0, $n, $period );
	}

	// ---------------------------------------------------------------------------------------------
	// Profile licences (monthly and yearly)
	// ---------------------------------------------------------------------------------------------

	/** @dataProvider periods */
	public function test_a_refunded_renewal_of_a_profile_licence_takes_its_period_back( string $period ): void {
		$bought = $this->buy_profile( $period );
		$sub_id = $bought['subscription_id'];
		$from   = $this->through( $sub_id );
		$p0     = (string) reset( $from );
		$before = $this->subscription_row( $sub_id );
		$this->set_row( 'subscriptions', $sub_id, array( 'failed_attempts' => 2 ) );

		$order = $this->renew( $sub_id );
		$p1    = $this->next_month( $p0, 1, $period );
		$this->assertSame( $this->all( $from, $p1 ), $this->through( $sub_id ), 'The renewal moved every dated line one ' . $period . '.' );
		$this->assertSame( $this->start_of_day_after( $p1 ), $this->subscription_row( $sub_id )['next_payment'] );
		$snapshot = json_decode( (string) $order->get_meta( RenewalProcessor::SNAPSHOT_META ), true );
		$this->assertSame( $sub_id, $snapshot['subscription_id'] );
		$this->assertSame( $before['next_payment'], $snapshot['before']['next_payment'] ?? null, 'Kept before anything moved.' );
		$this->assertSame( 2, $snapshot['before']['failed_attempts'] ?? null );
		$this->assertSame( $this->subscription_row( $sub_id )['next_payment'], $snapshot['after']['next_payment'] ?? null );
		$this->assertCount( 2, $snapshot['lines'] );
		$this->saved = array();

		$order = $this->refund( $order );

		$this->assertSame( $from, $this->through( $sub_id ), 'Every line back where it was.' );
		$row = $this->subscription_row( $sub_id );
		$this->assertSame( $before['next_payment'], $row['next_payment'] );
		$this->assertSame( $before['last_payment'], $row['last_payment'], 'The latest renewal: last payment restored.' );
		$this->assertSame( 2, (int) $row['failed_attempts'], 'And its failed attempts.' );
		$this->assertSame( 'active', $row['status'], 'Status is left to the renewal cron and dunning.' );
		$this->assertSame( array( 'invoice:refunded', 'renewal:refunded' ), array_map( static fn( $r ) => $r['type'] . ':' . $r['status'], $this->rows( $order->get_id() ) ) );
		$stamp = json_decode( (string) $order->get_meta( RenewalProcessor::ROLLED_BACK_META ), true );
		$this->assertIsArray( $stamp );
		$this->assertArrayHasKey( 'at', $stamp );
		$this->assertArrayHasKey( 'by', $stamp );
		$this->assertSame( '', (string) $order->get_meta( '_wplm_renewal_applied' ) );
		$this->assertSame( array( 'rolled_back', 'rolled_back' ), array_map( static fn( $c ) => $c[1], $this->saved ) );
		$this->assertCount( 1, $this->rolled );
		$this->assertSame( array( $order->get_id(), $sub_id ), array( $this->rolled[0][0], $this->rolled[0][1] ) );
		$this->assertSame( $snapshot['lines'], $this->rolled[0][2]['lines'] );
		$this->assertStringContainsString( 'WPLM: renewal refunded: subscription #' . $sub_id . ' now paid through ' . $p0 . ' (was ' . $p1 . '); next payment ' . $before['next_payment'] . ' UTC.', $this->notes( $order ) );
	}

	public function periods(): array {
		return array(
			'monthly' => array( 'month' ),
			'yearly'  => array( 'year' ),
		);
	}

	// ---------------------------------------------------------------------------------------------
	// A classic licence
	// ---------------------------------------------------------------------------------------------

	public function test_a_refunded_renewal_of_a_classic_licence_moves_its_expiry_back(): void {
		$bought = $this->buy_classic();
		$e0     = $this->license_row( $bought['license_id'] )['expires_at'];
		$order  = $this->renew( $bought['subscription_id'] );
		$e1     = $this->license_row( $bought['license_id'] )['expires_at'];
		$this->assertSame( gmdate( 'Y-m-d H:i:s', strtotime( $e0 . ' UTC +1 month' ) ), $e1 );
		$signed         = $this->license_row( $bought['license_id'] )['signature'];
		$this->extended = array();

		$order = $this->refund( $order );

		$row = $this->license_row( $bought['license_id'] );
		$this->assertSame( $e0, $row['expires_at'], 'Back to the old end.' );
		$this->assertSame( $e0, $this->subscription_row( $bought['subscription_id'] )['next_payment'] );
		$this->assertSame( 1, (int) $row['status'], 'Still inside its paid period: active.' );
		$this->assertNotSame( $signed, $row['signature'], 'The offline token is re-signed with the earlier expiry.' );
		$this->assertSame( array( array( $bought['license_id'], $e0, $e1 ) ), $this->extended, 'Announced with the earlier date.' );
		$this->assertStringContainsString( 'now paid through ' . $e0 . ' UTC (was ' . $e1 . ' UTC)', $this->notes( $order ) );
	}

	public function test_a_classic_licence_refunded_past_its_grace_is_expired_as_validation_would(): void {
		$bought = $this->buy_classic();
		$lapsed = $this->utc( -20 * DAY_IN_SECONDS );
		$this->set_row( 'licenses', $bought['license_id'], array( 'expires_at' => $lapsed ) );
		$this->set_row( 'subscriptions', $bought['subscription_id'], array( 'next_payment' => $lapsed ) );
		$order = $this->renew( $bought['subscription_id'] );
		$this->assertSame( 1, (int) $this->license_row( $bought['license_id'] )['status'] );

		$this->refund( $order );

		$row = $this->license_row( $bought['license_id'] );
		$this->assertSame( $lapsed, $row['expires_at'] );
		$this->assertSame( 3, (int) $row['status'], 'Past its grace again: expired, as validation would mark it.' );
		$key = $this->make( LicenseService::class )->get_by_id( $bought['license_id'] )->license_key;
		$this->assertSame( 'expired', $this->make( LicenseService::class )->validate( $key )['code'] );
	}

	// ---------------------------------------------------------------------------------------------
	// An add-ons-only subscription on a lifetime licence
	// ---------------------------------------------------------------------------------------------

	public function test_a_refunded_addons_renewal_moves_only_its_lines_and_the_lifetime_licence_stays_active(): void {
		add_filter(
			'wplm_package_extras',
			static function ( $offers, $ctx ) {
				if ( 'lifetime' === $ctx['billing'] ) {
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
					);
				}
				return $offers;
			},
			10,
			2
		);
		$plan     = $this->create_plan(
			array(
				array(
					'name'         => 'Lifetime',
					'billing_type' => 'lifetime',
					'price'        => 100000,
					'entitlements' => array( array( 'kind' => 'module', 'code' => 'base' ) ),
				),
			),
			array( 'profile' => 'acme-office' )
		);
		$product  = $this->create_plan_product( $plan['plan_id'] );
		$customer = $this->create_customer();
		wc_load_cart();
		WC()->cart->empty_cart();
		wp_set_current_user( $customer );
		$_POST['wplm_package_id']   = (string) $plan['packages'][0]->id;
		$_POST['wplm_extras']       = array( 'module:fbr' => '1' );
		$_POST['wplm_extras_cycle'] = 'month';
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 1 ), wp_json_encode( wc_get_notices( 'error' ) ) );
		$this->assertNotFalse( WC()->cart->add_to_cart( $product->get_id(), 1 ) );
		unset( $_POST['wplm_package_id'], $_POST['wplm_extras'], $_POST['wplm_extras_cycle'] );
		WC()->cart->calculate_totals();
		$bought = new \WC_Order();
		$bought->set_customer_id( $customer );
		WC()->checkout()->create_order_line_items( $bought, WC()->cart );
		$bought->set_payment_method( 'bacs' );
		$bought->calculate_totals();
		$bought->save();
		WC()->cart->empty_cart();
		$bought->update_status( 'on-hold' );
		$bought->update_status( 'completed' );
		$items      = array_values( wc_get_order( $bought->get_id() )->get_items() );
		$license_id = (int) ( json_decode( (string) $items[0]->get_meta( '_wplm_license_ids' ), true )[0] ?? 0 );
		$addons     = (int) $items[0]->get_meta( '_wplm_addons_subscription_id' );
		$this->assertGreaterThan( 0, $license_id );
		$this->assertTrue( $this->make( SubscriptionRepository::class )->find_by_id( $addons )->is_addons_only() );
		$p0       = $this->align_lines( $addons );
		$this->set_row( 'licenses', $license_id, array( 'status' => 1 ) ); // Activated: in use.
		$licence  = $this->license_row( $license_id );
		$lifetime = array_values( array_filter( $this->make( EntitlementRepository::class )->get_by_license( $license_id ), static fn( $l ) => null === $l->paid_through ) );
		$this->assertNotEmpty( $lifetime );

		$order = $this->renew( $addons );
		$this->assertSame( array( $this->next_month( $p0 ) ), array_values( $this->through( $addons ) ) );

		$order = $this->refund( $order );

		$this->assertSame( array( $p0 ), array_values( $this->through( $addons ) ), 'The FBR line is back one month.' );
		$this->assertSame( $this->start_of_day_after( $p0 ), $this->subscription_row( $addons )['next_payment'] );
		$after = $this->license_row( $license_id );
		$this->assertSame( array( $licence['status'], $licence['expires_at'] ), array( $after['status'], $after['expires_at'] ), 'The lifetime licence is untouched.' );
		$this->assertSame( 1, (int) $after['status'], 'The licence stays active.' );
		foreach ( $lifetime as $line ) {
			$this->assertNull( $this->make( EntitlementRepository::class )->find_by_id( $line->id )->paid_through, 'Its lifetime lines never move.' );
		}
		$this->assertStringContainsString( 'subscription #' . $addons . ' (Add-ons for licence ending', $this->notes( $order ) );
	}

	// ---------------------------------------------------------------------------------------------
	// What is kept, and doing it twice
	// ---------------------------------------------------------------------------------------------

	/** Pay a renewal on a plain order at a fixed time (as the card path records it). */
	private function pay_on_order( int $subscription_id, int $now ): \WC_Order {
		$order = wc_create_order( array( 'customer_id' => (int) $this->subscription_row( $subscription_id )['user_id'] ) );
		$order->set_total( 1000 );
		$order->set_status( 'completed' );
		$order->save();
		$sub = $this->make( SubscriptionRepository::class )->find_by_id( $subscription_id );
		$this->make( RenewalProcessor::class )->apply_payment( $sub, $order->get_id(), 1000.0, 'txn', $now );
		return wc_get_order( $order->get_id() );
	}

	public function test_a_later_renewal_across_months_of_different_lengths_keeps_whole_months(): void {
		$jan10 = (int) strtotime( '2026-01-10 10:00:00 UTC' ); // Each paid before the end: inside grace.
		$feb10 = (int) strtotime( '2026-02-10 10:00:00 UTC' );

		// A profile licence paid through 2026-01-14: January (31 days), then February (28 days).
		$bought = $this->buy_profile( 'month' );
		$sub_id = $bought['subscription_id'];
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wplm_entitlements SET paid_through = %s WHERE subscription_id = %d AND paid_through IS NOT NULL", '2026-01-14', $sub_id ) );
		$this->set_row( 'subscriptions', $sub_id, array( 'next_payment' => $this->start_of_day_after( '2026-01-14' ) ) );
		$first = $this->pay_on_order( $sub_id, $jan10 );
		$this->pay_on_order( $sub_id, $feb10 );
		$this->assertSame( array( '2026-03-14' ), array_values( array_unique( $this->through( $sub_id ) ) ) );

		$this->refund( $first );

		$this->assertSame( array( '2026-02-14' ), array_values( array_unique( $this->through( $sub_id ) ) ), 'One month taken back as a month, not as 31 days.' );
		$this->assertSame( $this->start_of_day_after( '2026-02-14' ), $this->subscription_row( $sub_id )['next_payment'] );

		// A classic licence ending 2026-01-14 10:00 UTC, the same way.
		$classic = $this->buy_classic();
		$this->set_row( 'licenses', $classic['license_id'], array( 'expires_at' => '2026-01-14 10:00:00' ) );
		$this->set_row( 'subscriptions', $classic['subscription_id'], array( 'next_payment' => '2026-01-14 10:00:00' ) );
		$first = $this->pay_on_order( $classic['subscription_id'], $jan10 );
		$this->pay_on_order( $classic['subscription_id'], $feb10 );
		$this->assertSame( '2026-03-14 10:00:00', $this->license_row( $classic['license_id'] )['expires_at'] );

		$this->refund( $first );

		$this->assertSame( '2026-02-14 10:00:00', $this->license_row( $classic['license_id'] )['expires_at'] );
		$this->assertSame( '2026-02-14 10:00:00', $this->subscription_row( $classic['subscription_id'] )['next_payment'] );
	}

	public function test_a_later_renewal_is_kept_when_an_earlier_one_is_refunded(): void {
		$bought = $this->buy_profile( 'month' );
		$sub_id = $bought['subscription_id'];
		$from   = $this->through( $sub_id );
		$p0     = (string) reset( $from );
		$first  = $this->renew( $sub_id );
		$second = $this->renew( $sub_id );
		$p2     = $this->next_month( $this->next_month( $p0 ) );
		$this->assertSame( $this->all( $from, $p2 ), $this->through( $sub_id ), 'Two months paid.' );
		$last = $this->subscription_row( $sub_id )['last_payment'];

		$this->refund( $first );

		$this->assertSame( $this->all( $from, $this->next_month( $p0 ) ), $this->through( $sub_id ), 'Shifted back one month: the second month stays.' );
		$row = $this->subscription_row( $sub_id );
		$this->assertSame( $this->start_of_day_after( $this->next_month( $p0 ) ), $row['next_payment'] );
		$this->assertSame( $last, $row['last_payment'], 'Not the latest renewal: its last payment stays.' );
		$this->assertSame( '1', (string) wc_get_order( $second->get_id() )->get_meta( '_wplm_renewal_applied' ), 'The later renewal is untouched.' );
	}

	public function test_refunding_twice_moves_nothing_more(): void {
		$bought = $this->buy_profile( 'month' );
		$sub_id = $bought['subscription_id'];
		$from   = $this->through( $sub_id );
		$order  = $this->renew( $sub_id );
		$order  = $this->refund( $order );
		$this->assertSame( $from, $this->through( $sub_id ), 'The first refund rolled it back.' );
		$next  = $this->subscription_row( $sub_id )['next_payment'];
		$notes = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		$this->assertFalse( $this->make( RenewalProcessor::class )->roll_back( $order ), 'Run again: nothing to do.' );
		do_action( 'woocommerce_order_status_refunded', $order->get_id(), $order );

		$this->assertSame( $from, $this->through( $sub_id ) );
		$this->assertSame( $next, $this->subscription_row( $sub_id )['next_payment'] );
		$this->assertCount( 1, $this->rolled );
		$this->assertCount( $notes, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'No second note.' );
	}

	public function test_a_refunded_renewal_completed_again_extends_once_and_a_second_refund_takes_it_back(): void {
		$bought = $this->buy_profile( 'month' );
		$sub_id = $bought['subscription_id'];
		$from   = $this->through( $sub_id );
		$p0     = (string) reset( $from );
		$order  = $this->renew( $sub_id );
		$order  = $this->refund( $order );
		$this->assertSame( $from, $this->through( $sub_id ) );

		$order->update_status( 'completed' );
		$order = wc_get_order( $order->get_id() );
		do_action( 'woocommerce_order_status_completed', $order->get_id(), $order ); // Re-saved by an admin: never twice.

		$this->assertSame( $this->all( $from, $this->next_month( $p0 ) ), $this->through( $sub_id ), 'Extended again, exactly once.' );
		$this->assertSame( '1', (string) $order->get_meta( '_wplm_renewal_applied' ) );
		$this->assertSame( '', (string) $order->get_meta( RenewalProcessor::ROLLED_BACK_META ), 'A fresh payment, a fresh snapshot.' );

		$this->refund( $order );

		$this->assertSame( $from, $this->through( $sub_id ), 'Refunded again: back again.' );
		$this->assertCount( 2, $this->rolled );
	}

	public function test_a_partial_refund_of_a_renewal_only_notes(): void {
		$bought = $this->buy_profile( 'month' );
		$sub_id = $bought['subscription_id'];
		$order  = $this->renew( $sub_id );
		$moved  = $this->through( $sub_id );
		$next   = $this->subscription_row( $sub_id )['next_payment'];

		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 100,
			)
		);
		$this->assertNotWPError( $refund );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( $moved, $this->through( $sub_id ), 'The period stays.' );
		$this->assertSame( $next, $this->subscription_row( $sub_id )['next_payment'] );
		$this->assertSame( array(), $this->rolled );
		$this->assertStringContainsString( 'WPLM: partial refund — the renewal period is unchanged; refund in full to take it back.', $this->notes( $order ) );

		// The rest refunded: in full now, so it rolls back.
		$rest = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => (float) $order->get_total() - 100,
			)
		);
		$this->assertNotWPError( $rest );
		$this->assertSame( 'refunded', wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount( 1, $this->rolled, 'A full refund through WooCommerce takes it back.' );
		$this->assertNotSame( $moved, $this->through( $sub_id ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Renewals refunded out of order, restarts, lines joined since, refunds that overlap
	// ---------------------------------------------------------------------------------------------

	/** Every dated line of a subscription paid through one date, and its next payment the day after. */
	private function lines_through( int $subscription_id, string $through ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wplm_entitlements SET paid_through = %s WHERE subscription_id = %d AND paid_through IS NOT NULL", $through, $subscription_id ) );
		$this->set_row( 'subscriptions', $subscription_id, array( 'next_payment' => $this->start_of_day_after( $through ) ) );
	}

	/** A classic licence ending at one time, due then. */
	private function classic_ending( string $end ): array {
		$classic = $this->buy_classic();
		$this->set_row( 'licenses', $classic['license_id'], array( 'expires_at' => $end ) );
		$this->set_row( 'subscriptions', $classic['subscription_id'], array( 'next_payment' => $end ) );
		return $classic;
	}

	/** One date for every dated line of a subscription (they move together). */
	private function one_date( int $subscription_id ): string {
		$dates = array_values( array_unique( array_filter( $this->through( $subscription_id ) ) ) );
		$this->assertCount( 1, $dates, 'The lines move together.' );
		return (string) $dates[0];
	}

	public function test_two_renewals_refunded_oldest_first_take_both_periods_back(): void {
		$bought = $this->buy_profile( 'month' );
		$sub_id = $bought['subscription_id'];
		$from   = $this->through( $sub_id );
		$p0     = (string) reset( $from );
		$next0  = $this->subscription_row( $sub_id )['next_payment'];
		$first  = $this->renew( $sub_id );
		$second = $this->renew( $sub_id );

		$this->refund( $first );
		$this->assertSame( $this->all( $from, $this->next_month( $p0 ) ), $this->through( $sub_id ), 'The older one: one month back.' );

		$second = $this->refund( $second );

		$this->assertSame( $from, $this->through( $sub_id ), 'Both refunded: back to where it started.' );
		$this->assertSame( $next0, $this->subscription_row( $sub_id )['next_payment'] );
		$this->assertStringNotContainsString( 'nothing', $this->notes( $second ) );
		$this->assertCount( 2, $this->rolled );

		// A classic licence the same way.
		$classic = $this->classic_ending( '2026-01-14 10:00:00' );
		$first   = $this->pay_on_order( $classic['subscription_id'], (int) strtotime( '2026-01-10 10:00:00 UTC' ) );
		$second  = $this->pay_on_order( $classic['subscription_id'], (int) strtotime( '2026-02-10 10:00:00 UTC' ) );
		$this->refund( $first );
		$this->assertSame( '2026-02-14 10:00:00', $this->license_row( $classic['license_id'] )['expires_at'] );
		$this->refund( $second );
		$this->assertSame( '2026-01-14 10:00:00', $this->license_row( $classic['license_id'] )['expires_at'], 'Both months gone.' );
		$this->assertSame( '2026-01-14 10:00:00', $this->subscription_row( $classic['subscription_id'] )['next_payment'] );
	}

	public function test_a_renewal_paid_after_grace_refunded_under_a_later_one_takes_back_one_period(): void {
		// Paid through 2026-01-14; the first renewal comes after grace (a month from its own day), the
		// second inside grace (continuing). Refunding the first takes back one month, not the lapse.
		$bought = $this->buy_profile( 'month' );
		$sub_id = $bought['subscription_id'];
		$this->lines_through( $sub_id, '2026-01-14' );
		$first = $this->pay_on_order( $sub_id, (int) strtotime( '2026-04-10 10:00:00 UTC' ) );
		$this->assertSame( '2026-05-09', $this->one_date( $sub_id ) );
		$this->pay_on_order( $sub_id, (int) strtotime( '2026-05-01 10:00:00 UTC' ) );
		$this->assertSame( '2026-06-09', $this->one_date( $sub_id ) );

		$this->refund( $first );

		$this->assertSame( '2026-05-09', $this->one_date( $sub_id ), 'One month back.' );
		$this->assertSame( $this->start_of_day_after( '2026-05-09' ), $this->subscription_row( $sub_id )['next_payment'] );

		// A classic licence the same way.
		$classic = $this->classic_ending( '2026-01-14 10:00:00' );
		$first   = $this->pay_on_order( $classic['subscription_id'], (int) strtotime( '2026-04-10 10:00:00 UTC' ) );
		$this->pay_on_order( $classic['subscription_id'], (int) strtotime( '2026-05-01 10:00:00 UTC' ) );
		$this->assertSame( '2026-06-10 10:00:00', $this->license_row( $classic['license_id'] )['expires_at'] );

		$this->refund( $first );

		$this->assertSame( '2026-05-10 10:00:00', $this->license_row( $classic['license_id'] )['expires_at'] );
		$this->assertSame( '2026-05-10 10:00:00', $this->subscription_row( $classic['subscription_id'] )['next_payment'] );
	}

	public function test_a_renewal_whose_period_a_later_fresh_start_left_behind_moves_nothing(): void {
		// The first renewal continues to 2026-02-14; the customer lapses; the second starts afresh on
		// 2026-05-01. The first's period is over: refunding it takes nothing from the second.
		$bought = $this->buy_profile( 'month' );
		$sub_id = $bought['subscription_id'];
		$this->lines_through( $sub_id, '2026-01-14' );
		$first = $this->pay_on_order( $sub_id, (int) strtotime( '2026-01-10 10:00:00 UTC' ) );
		$this->assertSame( '2026-02-14', $this->one_date( $sub_id ) );
		$second = $this->pay_on_order( $sub_id, (int) strtotime( '2026-05-01 10:00:00 UTC' ) );
		$this->assertSame( '2026-05-31', $this->one_date( $sub_id ) );
		$next = $this->subscription_row( $sub_id )['next_payment'];

		$first = $this->refund( $first );

		$this->assertSame( '2026-05-31', $this->one_date( $sub_id ), 'The fresh period stays whole.' );
		$this->assertSame( $next, $this->subscription_row( $sub_id )['next_payment'] );
		$this->assertNotSame( '', (string) $first->get_meta( RenewalProcessor::ROLLED_BACK_META ), 'Done: the refund is recorded.' );
		$this->assertSame( array( 'renewal:refunded' ), array_map( static fn( $r ) => $r['type'] . ':' . $r['status'], $this->rows( $first->get_id() ) ) );
		$this->assertStringContainsString( 'renewed afresh after this period had ended', $this->notes( $first ) );

		// Refunding the second too: back to before either.
		$this->refund( $second );
		$this->assertSame( '2026-01-14', $this->one_date( $sub_id ) );
		$this->assertSame( $this->start_of_day_after( '2026-01-14' ), $this->subscription_row( $sub_id )['next_payment'] );

		// A classic licence the same way.
		$classic = $this->classic_ending( '2026-01-14 10:00:00' );
		$first   = $this->pay_on_order( $classic['subscription_id'], (int) strtotime( '2026-01-10 10:00:00 UTC' ) );
		$this->pay_on_order( $classic['subscription_id'], (int) strtotime( '2026-05-01 10:00:00 UTC' ) );
		$this->assertSame( '2026-06-01 10:00:00', $this->license_row( $classic['license_id'] )['expires_at'] );
		$this->refund( $first );
		$this->assertSame( '2026-06-01 10:00:00', $this->license_row( $classic['license_id'] )['expires_at'] );
		$this->assertSame( '2026-06-01 10:00:00', $this->subscription_row( $classic['subscription_id'] )['next_payment'] );
	}

	public function test_a_line_joined_after_the_renewal_stops_the_rollback_for_a_check_by_hand(): void {
		$bought = $this->buy_profile( 'month' );
		$sub_id = $bought['subscription_id'];
		$from   = $this->through( $sub_id );
		$p0     = (string) reset( $from );
		$order  = $this->renew( $sub_id );
		$p1     = $this->next_month( $p0 );
		// An add-on joins, co-termed with what the renewal paid.
		$joined = $this->make( EntitlementRepository::class )->create(
			array(
				'license_id'      => $bought['license_id'],
				'kind'            => 'module',
				'code'            => 'fbr',
				'qty'             => 1,
				'paid_through'    => $p1,
				'subscription_id' => $sub_id,
			)
		);
		$moved = $this->through( $sub_id );
		$next  = $this->subscription_row( $sub_id )['next_payment'];

		$order = $this->refund( $order );

		$this->assertSame( $moved, $this->through( $sub_id ), 'Nothing moved: the lines would fall out of step.' );
		$this->assertSame( $p1, $moved[ $joined ] );
		$this->assertSame( $next, $this->subscription_row( $sub_id )['next_payment'] );
		$this->assertSame( array(), $this->rolled );
		$this->assertSame( '1', (string) $order->get_meta( '_wplm_renewal_applied' ), 'Not stamped: it can be done once checked.' );
		$this->assertSame( '', (string) $order->get_meta( RenewalProcessor::ROLLED_BACK_META ) );
		$this->assertStringContainsString( 'joined subscription #' . $sub_id . ' after this renewal', $this->notes( $order ) );
	}

	public function test_a_rollback_run_again_while_it_runs_takes_back_one_period(): void {
		$bought = $this->buy_profile( 'month' );
		$sub_id = $bought['subscription_id'];
		$from   = $this->through( $sub_id );
		$p0     = (string) reset( $from );
		$first  = $this->renew( $sub_id );
		$this->renew( $sub_id );
		$fired = false;
		add_action(
			'wplm_entitlement_saved',
			function ( $line, $op ) use ( $first, &$fired ) {
				unset( $line );
				if ( 'rolled_back' === $op && ! $fired ) {
					$fired = true;
					$this->assertFalse( $this->make( RenewalProcessor::class )->roll_back( wc_get_order( $first->get_id() ) ), 'The second run finds it taken.' );
				}
			},
			5,
			2
		);

		$this->refund( $first );

		$this->assertTrue( $fired );
		$this->assertSame( $this->all( $from, $this->next_month( $p0 ) ), $this->through( $sub_id ), 'One month back, once.' );
		$this->assertCount( 1, $this->rolled );
	}

	public function test_a_refund_that_finds_another_rollback_running_moves_nothing_and_says_how_to_retry(): void {
		$bought = $this->buy_profile( 'month' );
		$sub_id = $bought['subscription_id'];
		$from   = $this->through( $sub_id );
		$order  = $this->renew( $sub_id );
		$moved  = $this->through( $sub_id );
		$this->assertTrue( \WPLM\Support\Lock::claim( RenewalProcessor::LOCK_PREFIX . $sub_id, 300 ), 'Another request holds the subscription.' );

		$order = $this->refund( $order );

		$this->assertSame( $moved, $this->through( $sub_id ), 'Nothing moved.' );
		$this->assertSame( '1', (string) $order->get_meta( '_wplm_renewal_applied' ) );
		$this->assertStringContainsString( 'Set the order to Completed (nothing is extended again), then to Refunded again.', $this->notes( $order ) );

		// Once it is free, the retry the note names takes the period back.
		\WPLM\Support\Lock::release( RenewalProcessor::LOCK_PREFIX . $sub_id );
		$order->update_status( 'completed' );
		$this->assertSame( $moved, $this->through( $sub_id ), 'Completing it again extends nothing.' );
		$this->refund( $order );
		$this->assertSame( $from, $this->through( $sub_id ) );
		$this->assertCount( 1, $this->rolled );
	}

	public function test_a_renewal_paid_while_a_rollback_runs_keeps_its_next_payment(): void {
		$bought = $this->buy_profile( 'month' );
		$sub_id = $bought['subscription_id'];
		$first  = $this->renew( $sub_id );
		$last   = $this->subscription_row( $sub_id )['last_payment'];
		$fired  = false;
		add_action(
			'wplm_entitlement_saved',
			function ( $line, $op ) use ( $sub_id, &$fired ) {
				unset( $line );
				if ( 'rolled_back' === $op && ! $fired ) {
					$fired = true;
					$this->renew( $sub_id );
				}
			},
			5,
			2
		);

		$this->refund( $first );

		$this->assertTrue( $fired );
		$row = $this->subscription_row( $sub_id );
		$this->assertSame( $this->start_of_day_after( $this->one_date( $sub_id ) ), $row['next_payment'], 'The next payment agrees with the lines.' );
		$this->assertNotNull( $row['last_payment'] );
		$this->assertGreaterThanOrEqual( (string) $last, (string) $row['last_payment'], 'The later payment is kept.' );
	}

	// ---------------------------------------------------------------------------------------------
	// Before 1.3.0, lines removed, HPOS, the card path
	// ---------------------------------------------------------------------------------------------

	/** A renewal order paid before 1.3.0: no snapshot. */
	private function forget_snapshot( \WC_Order $order ): \WC_Order {
		$order = wc_get_order( $order->get_id() );
		$order->delete_meta_data( RenewalProcessor::SNAPSHOT_META );
		$order->save();
		return wc_get_order( $order->get_id() );
	}

	public function test_a_renewal_paid_before_snapshots_is_shifted_back_one_period_while_nothing_moved_since(): void {
		$bought = $this->buy_profile( 'month' );
		$sub_id = $bought['subscription_id'];
		$from   = $this->through( $sub_id );
		$next   = $this->subscription_row( $sub_id )['next_payment'];
		$order  = $this->forget_snapshot( $this->renew( $sub_id ) );
		$this->assertSame( $next, (string) $order->get_meta( '_wplm_renewal_cycle' ), 'The cycle it renewed.' );

		$order = $this->refund( $order );

		$this->assertSame( $from, $this->through( $sub_id ) );
		$this->assertSame( $next, $this->subscription_row( $sub_id )['next_payment'] );
		$this->assertNotSame( '', (string) $order->get_meta( RenewalProcessor::ROLLED_BACK_META ) );
		$this->assertCount( 1, $this->rolled );

		// A classic licence the same way.
		$classic = $this->buy_classic();
		$e0      = $this->license_row( $classic['license_id'] )['expires_at'];
		$this->refund( $this->forget_snapshot( $this->renew( $classic['subscription_id'] ) ) );
		$this->assertSame( $e0, $this->license_row( $classic['license_id'] )['expires_at'] );
		$this->assertSame( $e0, $this->subscription_row( $classic['subscription_id'] )['next_payment'] );
	}

	public function test_a_renewal_paid_before_snapshots_changes_nothing_once_the_subscription_moved_since(): void {
		$bought = $this->buy_profile( 'month' );
		$sub_id = $bought['subscription_id'];
		$order  = $this->forget_snapshot( $this->renew( $sub_id ) );
		$this->renew( $sub_id );
		$moved = $this->through( $sub_id );
		$next  = $this->subscription_row( $sub_id )['next_payment'];

		$order = $this->refund( $order );

		$this->assertSame( $moved, $this->through( $sub_id ), 'Nothing changed.' );
		$this->assertSame( $next, $this->subscription_row( $sub_id )['next_payment'] );
		$this->assertSame( array(), $this->rolled );
		$this->assertSame( '1', (string) $order->get_meta( '_wplm_renewal_applied' ) );
		$this->assertStringContainsString( 'subscription #' . $sub_id . ' has moved since', $this->notes( $order ) );
		$this->assertStringContainsString( 'nothing was changed', $this->notes( $order ) );
	}

	public function test_a_line_removed_between_the_renewal_and_the_refund_is_left_out(): void {
		$bought = $this->buy_profile( 'month' );
		$sub_id = $bought['subscription_id'];
		$from   = $this->through( $sub_id );
		$order  = $this->renew( $sub_id );
		$ids    = array_keys( $from );
		$this->make( EntitlementService::class )->delete_line( $ids[0] );

		$order = $this->refund( $order );

		$this->assertSame( array( $ids[1] => $from[ $ids[1] ] ), $this->through( $sub_id ), 'The other line is back.' );
		$this->assertNull( $this->make( EntitlementRepository::class )->find_by_id( $ids[0] ), 'Never written back.' );
		$this->assertStringContainsString( '1 line was removed or moved back since, and was left as it is.', $this->notes( $order ) );
	}

	public function test_a_refunded_renewal_rolls_back_the_same_on_hpos_order_storage(): void {
		update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		$this->assertTrue( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );

		$bought = $this->buy_profile( 'month' );
		$sub_id = $bought['subscription_id'];
		$from   = $this->through( $sub_id );
		$next   = $this->subscription_row( $sub_id )['next_payment'];
		$order  = $this->renew( $sub_id );
		$this->assertNotSame( 'shop_order', get_post_type( $order->get_id() ), 'Not in posts storage.' );

		$order = $this->refund( $order );

		$this->assertSame( $from, $this->through( $sub_id ) );
		$this->assertSame( $next, $this->subscription_row( $sub_id )['next_payment'] );
		$this->assertNotSame( '', (string) $order->get_meta( RenewalProcessor::ROLLED_BACK_META ) );
		$this->assertSame( '', (string) $order->get_meta( '_wplm_renewal_applied' ) );
	}

	public function test_a_refunded_card_renewal_from_the_cron_rolls_back(): void {
		$bought   = $this->buy_classic();
		$customer = (int) $this->subscription_row( $bought['subscription_id'] )['user_id'];
		$token    = new \WC_Payment_Token_CC();
		$token->set_token( 'tok_test' );
		$token->set_gateway_id( 'm527card' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( gmdate( 'Y', strtotime( '+2 years' ) ) );
		$token->set_user_id( $customer );
		$token->save();
		$e0 = $this->utc( -MINUTE_IN_SECONDS );
		$this->set_row( 'subscriptions', $bought['subscription_id'], array( 'payment_token_id' => $token->get_id(), 'next_payment' => $e0 ) );
		$this->set_row( 'licenses', $bought['license_id'], array( 'expires_at' => $e0 ) );
		// A gateway module builds the order the charge is recorded on (no `_wplm_is_renewal`).
		$created = 0;
		add_filter(
			'wplm_create_renewal_order',
			static function ( $id ) use ( $customer, &$created ) {
				$order = wc_create_order( array( 'customer_id' => $customer ) );
				$order->set_total( 1000 );
				$order->set_status( 'completed' );
				$order->save();
				$created = $order->get_id();
				return $created;
			}
		);

		$this->make( RenewalProcessor::class )->process_due_renewals();

		$this->assertGreaterThan( 0, $created );
		$order = wc_get_order( $created );
		$this->assertSame( '', (string) $order->get_meta( '_wplm_is_renewal' ) );
		$this->assertSame( '1', (string) $order->get_meta( '_wplm_renewal_applied' ), 'The card path stamps its order too.' );
		$e1 = gmdate( 'Y-m-d H:i:s', strtotime( $e0 . ' UTC +1 month' ) );
		$this->assertSame( $e1, $this->license_row( $bought['license_id'] )['expires_at'] );

		$order = $this->refund( $order );

		$this->assertSame( $e0, $this->license_row( $bought['license_id'] )['expires_at'] );
		$this->assertSame( $e0, $this->subscription_row( $bought['subscription_id'] )['next_payment'] );
		$this->assertSame( array( 'renewal:refunded' ), array_map( static fn( $r ) => $r['type'] . ':' . $r['status'], $this->rows( $created ) ) );
		$this->assertCount( 1, $this->rolled );
		$this->assertSame( 1, (int) $this->license_row( $bought['license_id'] )['status'], 'Inside grace: still active.' );
		$this->assertStringContainsString( 'unless the subscription is put on hold or cancelled, the renewal cron charges the saved card again on its next run', $this->notes( $order ), 'The shop is told the card will be charged again.' );
	}
}
