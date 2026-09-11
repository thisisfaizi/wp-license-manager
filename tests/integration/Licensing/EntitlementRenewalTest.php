<?php
/**
 * A renewal payment moves the paid-through date of the subscription's dated lines — the only way a
 * lapsed Super Ledger module comes back. Same rule as classic licences (1.1.0): inside grace the
 * period continues from the old end; after grace a full period starts today.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Licensing;

use WPLM\Licensing\TokenV2Service;
use WPLM\Repositories\SubscriptionRepository;
use WPLM\Services\EntitlementService;
use WPLM\Services\Subscriptions\RenewalProcessor;
use WPLM\Tests\TestCase;

class EntitlementRenewalTest extends TestCase {

	/** Payment time: 2026-09-11 10:00:00 UTC. */
	private const NOW = 1789120800;

	private const TODAY = '2026-09-11';

	private function buy_monthly(): array {
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
			array( 'profile' => 'super-ledger' )
		);
		$product  = $this->create_plan_product( $plan['plan_id'] );
		$bought   = $this->buy( $product, $plan['packages'][0], $this->create_customer() );
		$this->assertGreaterThan( 0, $bought['subscription_id'] );
		return $bought;
	}

	/** Set every line of the subscription to one paid-through date. */
	private function set_paid_through( array $bought, string $date ): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wplm_entitlements', array( 'paid_through' => $date ), array( 'subscription_id' => $bought['subscription_id'] ) );
	}

	private function pay( array $bought, int $now = self::NOW ): void {
		$sub = $this->make( SubscriptionRepository::class )->find_by_id( $bought['subscription_id'] );
		$this->make( RenewalProcessor::class )->apply_payment( $sub, null, 5000.0, '', $now );
	}

	/** @return array<string, string|null> code => paid_through for the subscription's lines. */
	private function paid_through( array $bought ): array {
		$out = array();
		foreach ( $this->make( EntitlementService::class )->lines( $bought['license_id'] ) as $line ) {
			if ( $line->subscription_id === $bought['subscription_id'] ) {
				$out[ $line->code ] = $line->paid_through;
			}
		}
		ksort( $out );
		return $out;
	}

	/** @dataProvider payment_days */
	public function test_a_payment_extends_the_lines_by_the_rule( string $paid_through_before, string $expected_after, string $why ): void {
		$bought = $this->buy_monthly();
		$this->set_paid_through( $bought, $paid_through_before );

		$this->pay( $bought );

		$this->assertSame(
			array(
				'base'  => $expected_after,
				'users' => $expected_after,
			),
			$this->paid_through( $bought ),
			$why
		);
	}

	public function payment_days(): array {
		return array(
			'paid early'                 => array( '2026-09-20', '2026-10-20', 'Paying before the end continues from the old end.' ),
			'paid inside grace'          => array( '2026-09-08', '2026-10-08', 'Inside grace: continues from the old end, so grace days are not free.' ),
			'last day of grace'          => array( '2026-09-04', '2026-10-04', 'paid_through + 7 = today is still inside grace.' ),
			'one day after grace'        => array( '2026-09-03', '2026-10-10', 'Past grace: a full month from today (11 Sep → 10 Oct inclusive).' ),
			'long lapsed'                => array( '2026-01-31', '2026-10-10', 'Read-only for months: the lapsed days are not billed.' ),
		);
	}

	public function test_month_end_clamps_instead_of_spilling_into_the_next_month(): void {
		$bought = $this->buy_monthly();
		$this->set_paid_through( $bought, '2027-01-30' ); // Paid through 30 Jan: the term ends 31 Jan.

		$this->pay( $bought, strtotime( '2027-01-25 10:00:00 UTC' ) );

		$this->assertSame( '2027-02-27', $this->paid_through( $bought )['base'], '31 Jan + 1 month is 28 Feb, not 3 Mar.' );
	}

	public function test_lifetime_lines_and_other_lines_never_move(): void {
		$bought = $this->buy_monthly();
		$lines  = $this->make( EntitlementService::class );
		$lines->add_line( $bought['license_id'], array( 'kind' => 'module', 'code' => 'pos', 'paid_through' => null ) );
		$lines->add_line( $bought['license_id'], array( 'kind' => 'module', 'code' => 'fbr', 'paid_through' => '2026-09-01' ) );
		$this->set_paid_through( $bought, '2026-09-20' );

		$this->pay( $bought );

		$by_code = array();
		foreach ( $lines->lines( $bought['license_id'] ) as $line ) {
			$by_code[ $line->code ] = $line->paid_through;
		}
		$this->assertNull( $by_code['pos'], 'Lifetime stays lifetime.' );
		$this->assertSame( '2026-09-01', $by_code['fbr'], 'A line not paid by this subscription is not extended by its payment.' );
		$this->assertSame( '2026-10-20', $by_code['base'] );
	}

	public function test_next_payment_is_the_start_of_the_day_after_paid_through_in_the_site_time_zone(): void {
		update_option( 'timezone_string', 'Asia/Karachi' );
		$bought = $this->buy_monthly();
		$this->set_paid_through( $bought, '2026-09-20' );

		$this->pay( $bought );

		$this->assertSame( '2026-10-20', $this->paid_through( $bought )['base'] );
		$this->assertSame( '2026-10-20 19:00:00', $this->subscription_row( $bought['subscription_id'] )['next_payment'], 'Midnight 21 Oct in Karachi is 19:00 UTC on the 20th.' );
		$this->assertNull( $this->license_row( $bought['license_id'] )['expires_at'] );
	}

	public function test_the_next_token_shows_the_new_date(): void {
		$bought = $this->buy_monthly();
		$this->set_paid_through( $bought, '2026-09-08' );
		$license = $this->make( \WPLM\Repositories\LicenseRepository::class )->find_by_id( $bought['license_id'] );
		$fp      = $this->client_fp( 'office' );
		$machine = $this->make( \WPLM\Services\ActivationService::class )->activate( $license->license_key, $fp );
		$this->assertNotWPError( $machine );

		$this->pay( $bought );

		$license = $this->make( \WPLM\Repositories\LicenseRepository::class )->find_by_id( $bought['license_id'] );
		$payload = $this->make( TokenV2Service::class )->payload( $license, $machine, $fp, array( 'now' => self::NOW ) );
		$this->assertSame( '2026-10-08', $payload['modules']['base']['until'] );
		$this->assertSame( 3, $payload['limits']['users'] );
	}

	/** O-5: the owner marks a bank-transfer renewal invoice Completed, and the lines move. */
	public function test_a_manual_renewal_invoice_marked_completed_extends_the_lines(): void {
		$bought = $this->buy_monthly();
		$today  = wp_date( 'Y-m-d' );
		$old    = gmdate( 'Y-m-d', strtotime( $today . ' -2 days' ) );
		$this->set_paid_through( $bought, $old );
		$this->set_row( 'subscriptions', $bought['subscription_id'], array( 'next_payment' => $this->utc( -DAY_IN_SECONDS ) ) );

		$this->make( RenewalProcessor::class )->process_due_renewals();
		$invoices = array_values( array_filter( $this->renewal_orders( $bought['subscription_id'] ), static fn( $o ) => '1' === $o->get_meta( '_wplm_is_renewal' ) ) );
		$this->assertCount( 1, $invoices, 'One renewal invoice for the cycle.' );

		$invoices[0]->update_status( 'completed' );

		// Inside grace, so from the old end; the period arithmetic itself is pinned by the fixed-date cases.
		$expected = gmdate( 'Y-m-d', strtotime( EntitlementService::add_period( gmdate( 'Y-m-d', strtotime( $old . ' +1 day' ) ), 1, 'month' ) . ' -1 day' ) );
		$this->assertSame( $expected, $this->paid_through( $bought )['base'] );

		$notes = array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $invoices[0]->get_id() ) ) );
		$this->assertContains( 'WPLM: renewal applied — modules paid through ' . $expected . '. The customer unlocks at the next check-in.', $notes, 'The owner sees the new date on the order they just completed.' );
	}
}
