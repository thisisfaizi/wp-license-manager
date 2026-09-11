<?php
/**
 * Audit F1/F2: a customer who pays by hand (bank transfer, JazzCash, Easypaisa) and then stops
 * paying must lose the licence once the paid period and grace have passed — and must be sent
 * something to pay. Nothing on the server locks them before that.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Subscriptions;

use WPLM\Services\LicenseService;
use WPLM\Services\Subscriptions\RenewalProcessor;
use WPLM\Tests\TestCase;

class ManualPayerTest extends TestCase {

	private function buy_monthly(): array {
		$plan     = $this->create_plan(
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
		$product  = $this->create_plan_product( $plan['plan_id'] );
		$customer = $this->create_customer();
		$bought   = $this->buy( $product, $plan['packages'][0], $customer );
		$this->assertGreaterThan( 0, $bought['license_id'], 'The purchase must issue a licence.' );
		return $bought;
	}

	private function key( int $license_id ): string {
		return $this->make( LicenseService::class )->get_by_id( $license_id )->license_key;
	}

	private function renewal_invoices( int $subscription_id ): array {
		return array_values( array_filter( $this->renewal_orders( $subscription_id ), static fn( $o ) => '1' === $o->get_meta( '_wplm_is_renewal' ) ) );
	}

	/** F1: the licence for a monthly plan ends when the paid month ends. */
	public function test_monthly_licence_expires_at_the_end_of_the_paid_period(): void {
		$bought  = $this->buy_monthly();
		$license = $this->license_row( $bought['license_id'] );
		$sub     = $this->subscription_row( $bought['subscription_id'] );

		$this->assertSameMoment( $sub['next_payment'], $license['expires_at'], 5, 'The licence must expire when the paid period ends.' );
		$this->assertSameMoment( gmdate( 'Y-m-d H:i:s', strtotime( '+1 month' ) ), $license['expires_at'], 60, 'Stored in UTC.' );
		$this->assertSame( 7, (int) $license['grace_days'], 'Default grace is 7 days.' );
	}

	/** F2: unpaid past period + grace → the licence no longer validates. */
	public function test_unpaid_licence_stops_validating_after_grace(): void {
		$bought = $this->buy_monthly();
		$this->set_row( 'subscriptions', $bought['subscription_id'], array( 'next_payment' => $this->utc( -8 * DAY_IN_SECONDS ) ) );
		$this->set_row( 'licenses', $bought['license_id'], array( 'expires_at' => $this->utc( -8 * DAY_IN_SECONDS ) ) );
		$this->make( RenewalProcessor::class )->process_due_renewals();

		$result = $this->make( LicenseService::class )->validate( $this->key( $bought['license_id'] ) );
		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'expired', $result['code'] );
	}

	/** F2: within grace the customer keeps working. */
	public function test_unpaid_licence_still_validates_inside_grace(): void {
		$bought = $this->buy_monthly();
		$this->set_row( 'subscriptions', $bought['subscription_id'], array( 'next_payment' => $this->utc( -6 * DAY_IN_SECONDS ) ) );
		$this->set_row( 'licenses', $bought['license_id'], array( 'expires_at' => $this->utc( -6 * DAY_IN_SECONDS ) ) );
		$this->make( RenewalProcessor::class )->process_due_renewals();

		$this->assertTrue( $this->make( LicenseService::class )->validate( $this->key( $bought['license_id'] ) )['valid'] );
	}

	/** F2: a due manual subscription gets exactly one renewal invoice per cycle, emailed, and no lock. */
	public function test_due_manual_subscription_gets_one_emailed_invoice_per_cycle(): void {
		$bought = $this->buy_monthly();
		$this->set_row( 'subscriptions', $bought['subscription_id'], array( 'next_payment' => $this->utc( -HOUR_IN_SECONDS ) ) );

		reset_phpmailer_instance();
		$rp     = $this->make( RenewalProcessor::class );
		$rp->process_due_renewals();
		$rp->process_due_renewals();

		$invoices = $this->renewal_invoices( $bought['subscription_id'] );
		$this->assertCount( 1, $invoices, 'One renewal invoice per cycle, however often cron runs.' );
		$this->assertSame( 'pending', $invoices[0]->get_status() );

		$mailer = tests_retrieve_phpmailer_instance();
		$to     = array();
		foreach ( $mailer->mock_sent as $mail ) {
			$to[] = $mail['to'][0][0] ?? '';
		}
		$this->assertContains( 'customer' . $bought['order']->get_customer_id() . '@example.org', $to, 'The customer is emailed the invoice.' );

		$this->assertSame( 'active', $this->subscription_row( $bought['subscription_id'] )['status'], 'A missed manual payment locks nothing on the server.' );
		$this->assertSame( 1, (int) $this->license_row( $bought['license_id'] )['status'] );
	}

	/** Paying inside grace continues from the end of the paid period (no free days, no lost days). */
	public function test_paying_inside_grace_extends_from_the_old_end(): void {
		$bought  = $this->buy_monthly();
		$old_end = $this->utc( -3 * DAY_IN_SECONDS );
		$this->set_row( 'licenses', $bought['license_id'], array( 'expires_at' => $old_end ) );
		$this->set_row( 'subscriptions', $bought['subscription_id'], array( 'next_payment' => $old_end ) );
		$this->make( RenewalProcessor::class )->process_due_renewals();

		$this->renewal_invoices( $bought['subscription_id'] )[0]->update_status( 'completed' );

		$expected = gmdate( 'Y-m-d H:i:s', strtotime( $old_end . ' UTC +1 month' ) );
		$license  = $this->license_row( $bought['license_id'] );
		$this->assertSameMoment( $expected, $license['expires_at'], 5 );
		$this->assertSameMoment( $expected, $this->subscription_row( $bought['subscription_id'] )['next_payment'], 5, 'next_payment follows the licence.' );
		$this->assertTrue( $this->make( LicenseService::class )->validate( $this->key( $bought['license_id'] ) )['valid'] );
	}

	/** Paying after the lock starts a full period from the payment (the locked days are not billed). */
	public function test_paying_after_grace_starts_a_full_period_from_now(): void {
		$bought = $this->buy_monthly();
		$this->set_row( 'licenses', $bought['license_id'], array( 'expires_at' => $this->utc( -20 * DAY_IN_SECONDS ) ) );
		$this->set_row( 'subscriptions', $bought['subscription_id'], array( 'next_payment' => $this->utc( -20 * DAY_IN_SECONDS ) ) );
		$ls = $this->make( LicenseService::class );
		$this->assertSame( 'expired', $ls->validate( $this->key( $bought['license_id'] ) )['code'] );
		$this->make( RenewalProcessor::class )->process_due_renewals();

		$this->renewal_invoices( $bought['subscription_id'] )[0]->update_status( 'completed' );

		$this->assertSameMoment( gmdate( 'Y-m-d H:i:s', strtotime( '+1 month' ) ), $this->license_row( $bought['license_id'] )['expires_at'], 60 );
		$this->assertTrue( $ls->validate( $this->key( $bought['license_id'] ) )['valid'], 'An expired licence comes back when paid (F4).' );
	}

	/** F6: the offline token is re-signed with the new expiry. */
	public function test_renewal_re_signs_the_offline_token(): void {
		$bought = $this->buy_monthly();
		$this->set_row( 'subscriptions', $bought['subscription_id'], array( 'next_payment' => $this->utc( -HOUR_IN_SECONDS ) ) );
		$this->make( RenewalProcessor::class )->process_due_renewals();
		$this->renewal_invoices( $bought['subscription_id'] )[0]->update_status( 'completed' );

		$license = $this->make( LicenseService::class )->get_by_id( $bought['license_id'] );
		$payload = json_decode( base64_decode( strtr( explode( '.', $license->signature )[0], '-_', '+/' ) ), true );
		$this->assertSame( $license->expires_at, $payload['expires'] );
	}

	/** F7: "due" is decided in UTC whatever the MySQL server's own time zone is. */
	public function test_due_query_is_utc_regardless_of_mysql_time_zone(): void {
		global $wpdb;
		$bought = $this->buy_monthly();
		$wpdb->query( "SET time_zone = '+05:00'" );
		try {
			$this->set_row( 'subscriptions', $bought['subscription_id'], array( 'next_payment' => $this->utc( 2 * HOUR_IN_SECONDS ) ) );
			$due = array_map( static fn( $s ) => $s->id, $this->make( \WPLM\Repositories\SubscriptionRepository::class )->get_due_for_renewal() );
			$this->assertNotContains( $bought['subscription_id'], $due, 'Due in 2 hours (UTC) is not due yet, even on a +05:00 MySQL.' );
		} finally {
			$wpdb->query( "SET time_zone = '+00:00'" );
		}
	}
}
