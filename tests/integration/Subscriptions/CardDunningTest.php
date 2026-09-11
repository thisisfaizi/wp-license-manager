<?php
/**
 * Audit F3/F4/F5: a stored-card customer whose renewal charge fails. Retries must actually run,
 * nothing on the server locks the licence for non-payment (it lapses on its own after the paid
 * period + grace), and a successful charge brings an expired licence back.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Subscriptions;

use WPLM\Repositories\SubscriptionRepository;
use WPLM\Services\LicenseService;
use WPLM\Services\Subscriptions\RenewalProcessor;
use WPLM\Tests\TestCase;

class CardDunningTest extends TestCase {

	/** @var array|\WP_Error Result the fake gateway returns. */
	private $charge_result;

	public function set_up(): void {
		parent::set_up();
		$this->charge_result = new \WP_Error( 'declined', 'Card declined' );
		add_filter( 'wplm_gateway_charge', fn() => $this->charge_result );
	}

	private function card_customer_with_due_renewal(): array {
		$plan     = $this->create_plan(
			array(
				array(
					'name'             => 'Monthly',
					'billing_type'     => 'recurring',
					'billing_period'   => 'month',
					'billing_interval' => 1,
					'price'            => 1000,
				),
			)
		);
		$product  = $this->create_plan_product( $plan['plan_id'] );
		$customer = $this->create_customer();
		$bought   = $this->buy( $product, $plan['packages'][0], $customer, 'm527card' );

		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'tok_test' );
		$token->set_gateway_id( 'm527card' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( gmdate( 'Y', strtotime( '+2 years' ) ) );
		$token->set_user_id( $customer );
		$token->save();

		$end = $this->utc( -MINUTE_IN_SECONDS );
		$this->set_row( 'subscriptions', $bought['subscription_id'], array( 'payment_token_id' => $token->get_id(), 'next_payment' => $end ) );
		$this->set_row( 'licenses', $bought['license_id'], array( 'expires_at' => $end ) );
		return $bought + array( 'end' => $end );
	}

	private function make_retry_due( int $subscription_id ): void {
		$this->set_row( 'subscriptions', $subscription_id, array( 'next_payment' => $this->utc( -MINUTE_IN_SECONDS ) ) );
	}

	/** F3: the first decline neither suspends the licence nor parks the subscription out of reach. */
	public function test_first_decline_locks_nothing_and_schedules_a_retry(): void {
		$b = $this->card_customer_with_due_renewal();
		$this->make( RenewalProcessor::class )->process_due_renewals();

		$sub = $this->subscription_row( $b['subscription_id'] );
		$this->assertSame( 1, (int) $sub['failed_attempts'] );
		$this->assertSameMoment( $this->utc( DAY_IN_SECONDS ), $sub['next_payment'], 60, 'Retry in 1 day.' );
		$this->assertSame( 1, (int) $this->license_row( $b['license_id'] )['status'], 'No server-side lock for non-payment.' );

		$this->make_retry_due( $b['subscription_id'] );
		$due = array_map( static fn( $s ) => $s->id, $this->make( SubscriptionRepository::class )->get_due_for_renewal() );
		$this->assertContains( $b['subscription_id'], $due, 'The retry is picked up when it is due.' );
	}

	/** F3: all retries run; after the last one the customer is invoiced to pay by hand, still no lock. */
	public function test_exhausted_retries_invoice_the_customer_and_lock_nothing(): void {
		$b  = $this->card_customer_with_due_renewal();
		$rp = $this->make( RenewalProcessor::class );
		// The first attempt plus the three scheduled retries (1, 3, 5 days) all fail.
		for ( $i = 0; $i < 4; $i++ ) {
			$rp->process_due_renewals();
			$this->make_retry_due( $b['subscription_id'] );
		}

		$sub = $this->subscription_row( $b['subscription_id'] );
		$this->assertSame( 4, (int) $sub['failed_attempts'] );
		$this->assertSame( 'on-hold', $sub['status'], 'Awaiting a manual payment.' );
		$this->assertNotEmpty( array_filter( $this->renewal_orders( $b['subscription_id'] ), static fn( $o ) => '1' === $o->get_meta( '_wplm_is_renewal' ) && 'pending' === $o->get_status() ) );

		$license = $this->license_row( $b['license_id'] );
		$this->assertSame( 1, (int) $license['status'], 'Never suspended or revoked for non-payment.' );
		$this->assertSameMoment( $b['end'], $license['expires_at'], 5, 'The licence simply lapses at its paid end + grace.' );
	}

	/** A retry that succeeds resets the dunning state and extends from the old end (inside grace). */
	public function test_successful_retry_extends_from_the_old_end(): void {
		$b  = $this->card_customer_with_due_renewal();
		$rp = $this->make( RenewalProcessor::class );
		$rp->process_due_renewals();

		$this->charge_result = array( 'txn' => 'txn_ok' );
		$this->make_retry_due( $b['subscription_id'] );
		$rp->process_due_renewals();

		$expected = gmdate( 'Y-m-d H:i:s', strtotime( $b['end'] . ' UTC +1 month' ) );
		$this->assertSameMoment( $expected, $this->license_row( $b['license_id'] )['expires_at'], 5 );
		$sub = $this->subscription_row( $b['subscription_id'] );
		$this->assertSame( 0, (int) $sub['failed_attempts'] );
		$this->assertSame( 'active', $sub['status'] );
		$this->assertSameMoment( $expected, $sub['next_payment'], 5 );
	}

	/** F4 + F5: an already-expired licence is revived by a successful charge, with a full period from now. */
	public function test_successful_charge_revives_an_expired_licence(): void {
		$b = $this->card_customer_with_due_renewal();
		$this->set_row( 'licenses', $b['license_id'], array( 'expires_at' => $this->utc( -10 * DAY_IN_SECONDS ) ) );
		$ls  = $this->make( LicenseService::class );
		$key = $ls->get_by_id( $b['license_id'] )->license_key;
		$this->assertSame( 'expired', $ls->validate( $key )['code'] );

		$this->charge_result = array( 'txn' => 'txn_ok' );
		$this->make( RenewalProcessor::class )->process_due_renewals();

		$this->assertTrue( $ls->validate( $key )['valid'], 'A customer who has paid is not refused.' );
		$this->assertSameMoment( gmdate( 'Y-m-d H:i:s', strtotime( '+1 month' ) ), $this->license_row( $b['license_id'] )['expires_at'], 60, 'Past grace: a full period from the payment.' );
	}

	/** D7: a licence the owner suspended by hand stays suspended when a renewal succeeds. */
	public function test_payment_does_not_lift_a_manual_suspension(): void {
		$b = $this->card_customer_with_due_renewal();
		$this->make( LicenseService::class )->change_status( $b['license_id'], 4 );

		$this->charge_result = array( 'txn' => 'txn_ok' );
		$this->make( RenewalProcessor::class )->process_due_renewals();

		$this->assertSame( 4, (int) $this->license_row( $b['license_id'] )['status'], 'Manual lock is lifted only by the owner.' );
	}
}
