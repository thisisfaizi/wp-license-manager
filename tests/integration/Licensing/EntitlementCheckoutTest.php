<?php
/**
 * Buying a plan that sells a licence profile writes entitlement lines, and such a licence never expires
 * as a whole: its lines carry the dates, and the client computes grace and read-only from the token.
 * Every other product keeps its classic licence and v1 token, untouched.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Licensing;

use WPLM\Crypto\Signer;
use WPLM\Services\ActivationService;
use WPLM\Services\EntitlementService;
use WPLM\Services\LicenseService;
use WPLM\Services\PlanService;
use WPLM\Services\Subscriptions\RenewalProcessor;
use WPLM\Services\Subscriptions\SubscriptionService;
use WPLM\Tests\TestCase;

class EntitlementCheckoutTest extends TestCase {

	private function monthly_distribution(): array {
		return array(
			'name'             => 'Distribution monthly',
			'billing_type'     => 'recurring',
			'billing_period'   => 'month',
			'billing_interval' => 1,
			'price'            => 5000,
			'entitlements'     => array(
				array( 'kind' => 'module', 'code' => 'base' ),
				array( 'kind' => 'module', 'code' => 'distribution' ),
				array(
					'kind' => 'limit',
					'code' => 'users',
					'qty'  => 3,
				),
				array(
					'kind' => 'limit',
					'code' => 'phones',
					'qty'  => 2,
				),
			),
		);
	}

	private function pos_lifetime(): array {
		return array(
			'name'         => 'POS lifetime',
			'billing_type' => 'lifetime',
			'price'        => 60000,
			'entitlements' => array( array( 'kind' => 'module', 'code' => 'pos' ) ),
		);
	}

	private function buy_profile( array $package ): array {
		$plan     = $this->create_plan( array( $package ), array( 'profile' => 'acme-office' ) );
		$product  = $this->create_plan_product( $plan['plan_id'] );
		$customer = $this->create_customer();
		$bought   = $this->buy( $product, $plan['packages'][0], $customer );
		$this->assertGreaterThan( 0, $bought['license_id'], 'The purchase must issue a licence.' );
		return $bought;
	}

	private function lines( int $license_id ): array {
		return $this->make( EntitlementService::class )->lines( $license_id );
	}

	public function test_a_monthly_plan_writes_its_lines_paid_through_the_day_before_next_payment(): void {
		update_option( 'timezone_string', 'Asia/Karachi' );
		$bought  = $this->buy_profile( $this->monthly_distribution() );
		$license = $this->license_row( $bought['license_id'] );
		$sub     = $this->subscription_row( $bought['subscription_id'] );

		$this->assertSame( 'acme-office', $license['profile'] );
		$this->assertNull( $license['expires_at'], 'The lines carry the dates, not the licence.' );

		$expected_through = EntitlementService::paid_through_for( $sub['next_payment'] );
		$got              = array_map(
			static fn( $l ) => array( $l->kind, $l->code, $l->qty, $l->paid_through, $l->source, $l->subscription_id ),
			$this->lines( $bought['license_id'] )
		);
		$this->assertEqualsCanonicalizing(
			array(
				array( 'module', 'base', 0, $expected_through, 'plan', $bought['subscription_id'] ),
				array( 'module', 'distribution', 0, $expected_through, 'plan', $bought['subscription_id'] ),
				array( 'limit', 'users', 3, $expected_through, 'plan', $bought['subscription_id'] ),
				array( 'limit', 'phones', 2, $expected_through, 'plan', $bought['subscription_id'] ),
			),
			$got
		);
	}

	public function test_a_lifetime_plan_writes_lifetime_lines(): void {
		$bought = $this->buy_profile( $this->pos_lifetime() );
		$lines  = $this->lines( $bought['license_id'] );

		$this->assertCount( 1, $lines );
		$this->assertSame( 'pos', $lines[0]->code );
		$this->assertNull( $lines[0]->paid_through );
		$this->assertNull( $lines[0]->subscription_id );
	}

	public function test_a_paid_but_unconfirmed_order_issues_nothing_until_it_is_completed(): void {
		$plan     = $this->create_plan( array( $this->pos_lifetime() ), array( 'profile' => 'acme-office' ) );
		$product  = $this->create_plan_product( $plan['plan_id'] );
		$customer = $this->create_customer();

		$order   = wc_create_order( array( 'customer_id' => $customer ) );
		$item_id = $order->add_product( $product, 1 );
		$item    = $order->get_item( $item_id );
		$item->add_meta_data( '_wplm_package_id', $plan['packages'][0]->id, true );
		$item->save();
		$order->set_billing_email( 'customer' . $customer . '@example.org' );
		$order->calculate_totals();
		$order->save();

		// Payment taken, but the shop has not confirmed the order yet.
		$order->update_status( 'processing' );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '', (string) $order->get_meta( '_wplm_plan_fulfilled' ), 'Processing must not fulfil.' );
		foreach ( $order->get_items() as $line ) {
			$this->assertSame( '', (string) $line->get_meta( '_wplm_license_ids' ), 'No key may be issued on Processing.' );
		}

		$order->update_status( 'completed' );

		$order      = wc_get_order( $order->get_id() );
		$license_id = 0;
		foreach ( $order->get_items() as $line ) {
			$ids        = json_decode( (string) $line->get_meta( '_wplm_license_ids' ), true );
			$license_id = (int) ( $ids[0] ?? 0 );
		}
		$this->assertGreaterThan( 0, $license_id, 'Completing the order issues the key.' );
		$this->assertCount( 1, $this->lines( $license_id ) );
	}

	public function test_a_retried_fulfilment_does_not_write_the_lines_twice(): void {
		$bought = $this->buy_profile( $this->pos_lifetime() );
		$order  = $bought['order'];
		$order->delete_meta_data( '_wplm_plan_fulfilled' );
		$order->save();

		$order->update_status( 'processing' );
		$order->update_status( 'completed' );

		$this->assertCount( 1, $this->lines( $bought['license_id'] ) );
	}

	public function test_a_plan_template_with_an_unknown_code_is_refused_on_save(): void {
		$plans   = $this->make( PlanService::class );
		$plan_id = $plans->create_plan(
			array(
				'name'    => 'Typo plan',
				'profile' => 'acme-office',
			)
		);

		$this->expectException( \InvalidArgumentException::class );
		$plans->sync_packages(
			$plan_id,
			array(
				array(
					'name'         => 'Broken',
					'billing_type' => 'lifetime',
					'entitlements' => array( array( 'kind' => 'module', 'code' => 'distributon' ) ),
				),
			)
		);
	}

	public function test_an_unknown_plan_profile_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->make( PlanService::class )->create_plan(
			array(
				'name'    => 'Nope',
				'profile' => 'super-legder',
			)
		);
	}

	// ---------------------------------------------------------------------------------------------
	// An entitlement licence never gets an expiry, whichever path tries to give it one.
	// ---------------------------------------------------------------------------------------------

	public function test_cancelling_the_subscription_does_not_give_the_licence_an_expiry(): void {
		$bought = $this->buy_profile( $this->monthly_distribution() );

		$this->make( SubscriptionService::class )->update_status( $bought['subscription_id'], 'cancelled' );

		$this->assertNull( $this->license_row( $bought['license_id'] )['expires_at'] );
	}

	public function test_a_renewal_payment_does_not_give_the_licence_an_expiry(): void {
		$bought = $this->buy_profile( $this->monthly_distribution() );
		$sub    = $this->make( \WPLM\Repositories\SubscriptionRepository::class )->find_by_id( $bought['subscription_id'] );

		$this->make( RenewalProcessor::class )->apply_payment( $sub, null, 5000.0 );

		$this->assertNull( $this->license_row( $bought['license_id'] )['expires_at'] );
	}

	public function test_first_activation_does_not_start_a_valid_for_days_expiry(): void {
		$license = $this->make( LicenseService::class )->create(
			array(
				'key_string'     => 'SL-' . wp_generate_password( 16, false ),
				'profile'        => 'acme-office',
				'valid_for_days' => 30,
			)
		);

		$this->assertNotWPError( $this->make( ActivationService::class )->activate( $license->license_key, $this->client_fp( 'pc' ) ) );

		$this->assertNull( $this->license_row( $license->id )['expires_at'] );
	}

	public function test_an_admin_or_api_edit_cannot_set_an_expiry(): void {
		$license = $this->make( LicenseService::class )->create(
			array(
				'key_string' => 'SL-' . wp_generate_password( 16, false ),
				'profile'    => 'acme-office',
				'expires_at' => '2026-01-01 00:00:00',
			)
		);
		$this->assertNull( $this->license_row( $license->id )['expires_at'], 'Not on create.' );

		$this->make( LicenseService::class )->update( $license->id, array( 'expires_at' => '2026-01-01 00:00:00' ) );
		$this->assertNull( $this->license_row( $license->id )['expires_at'], 'Not on update.' );

		$this->make( LicenseService::class )->renew( $license->license_key, '2026-01-01 00:00:00' );
		$this->assertNull( $this->license_row( $license->id )['expires_at'], 'Not on renew.' );

		$this->assertTrue( $this->make( LicenseService::class )->validate( $license->license_key )['valid'], 'So classic validation never marks it expired.' );
	}

	public function test_turning_a_licence_into_a_profile_licence_clears_its_expiry(): void {
		$license = $this->make( LicenseService::class )->create(
			array(
				'key_string' => 'OLD-' . wp_generate_password( 12, false ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);

		$this->make( LicenseService::class )->update( $license->id, array( 'profile' => 'acme-office' ) );

		$row = $this->license_row( $license->id );
		$this->assertSame( 'acme-office', $row['profile'] );
		$this->assertNull( $row['expires_at'] );
	}

	// ---------------------------------------------------------------------------------------------
	// Other products are untouched.
	// ---------------------------------------------------------------------------------------------

	public function test_a_classic_plan_still_issues_a_classic_licence_with_the_v1_token(): void {
		$plan     = $this->create_plan(
			array(
				array(
					'name'             => 'Monthly',
					'billing_type'     => 'recurring',
					'billing_period'   => 'month',
					'billing_interval' => 1,
					'price'            => 10,
				),
			)
		);
		$product  = $this->create_plan_product( $plan['plan_id'] );
		$bought   = $this->buy( $product, $plan['packages'][0], $this->create_customer() );
		$license  = $this->license_row( $bought['license_id'] );

		$this->assertNull( $license['profile'] );
		$this->assertNotNull( $license['expires_at'] );
		$this->assertSame( array(), $this->lines( $bought['license_id'] ) );

		$v1   = $this->make( Signer::class )->verify( $license['signature'] );
		$keys = array_keys( $v1 );
		sort( $keys );
		$this->assertSame( array( 'expires', 'iat', 'key', 'max', 'pid' ), $keys );
	}
}
