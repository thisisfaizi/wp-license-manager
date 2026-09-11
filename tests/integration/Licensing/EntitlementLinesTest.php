<?php
/**
 * Entitlement lines: the closed code list, validation, persistence and the paid-through date rule.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Licensing;

use WPLM\Services\EntitlementService;
use WPLM\Services\LicenseService;
use WPLM\Tests\TestCase;

class EntitlementLinesTest extends TestCase {

	private function lines(): EntitlementService {
		return $this->make( EntitlementService::class );
	}

	private function profile_licence_id(): int {
		return $this->make( LicenseService::class )->create(
			array(
				'key_string' => 'SL-' . wp_generate_password( 16, false ),
				'profile'    => 'super-ledger',
			)
		)->id;
	}

	public function test_a_line_survives_a_fresh_read(): void {
		$lid  = $this->profile_licence_id();
		$line = $this->lines()->add_line(
			$lid,
			array(
				'kind'         => 'limit',
				'code'         => 'phones',
				'qty'          => 2,
				'paid_through' => '2026-10-10',
				'source'       => 'addon',
			)
		);

		$read = $this->lines()->lines( $lid );

		$this->assertCount( 1, $read );
		$this->assertSame( $line->id, $read[0]->id );
		$this->assertSame( 'limit', $read[0]->kind );
		$this->assertSame( 'phones', $read[0]->code );
		$this->assertSame( 2, $read[0]->qty );
		$this->assertSame( '2026-10-10', $read[0]->paid_through );
		$this->assertSame( 'addon', $read[0]->source );
		$this->assertNull( $read[0]->subscription_id );
	}

	public function test_a_lifetime_line_stores_null(): void {
		$lid = $this->profile_licence_id();
		$this->lines()->add_line( $lid, array( 'kind' => 'module', 'code' => 'pos', 'paid_through' => null ) );

		$this->assertNull( $this->lines()->lines( $lid )[0]->paid_through );
		$this->assertSame( 0, $this->lines()->lines( $lid )[0]->qty, 'A module line carries no quantity.' );
	}

	/** @dataProvider refused_lines */
	public function test_an_invalid_line_is_refused( array $line, string $why ): void {
		$lid = $this->profile_licence_id();

		try {
			$this->lines()->add_line( $lid, $line );
			$this->fail( 'Expected refusal: ' . $why );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( array(), $this->lines()->lines( $lid ), 'A refused line writes nothing.' );
		}
	}

	public function refused_lines(): array {
		return array(
			'unknown module'       => array( array( 'kind' => 'module', 'code' => 'payroll' ), 'a typo must not silently sell nothing' ),
			'module code as limit' => array( array( 'kind' => 'limit', 'code' => 'pos', 'qty' => 1 ), 'kinds keep their own codes' ),
			'unknown kind'         => array( array( 'kind' => 'feature', 'code' => 'base' ), 'only module|limit' ),
			'limit without qty'    => array( array( 'kind' => 'limit', 'code' => 'users', 'qty' => 0 ), 'a limit line adds at least one' ),
			'bad date'             => array( array( 'kind' => 'module', 'code' => 'base', 'paid_through' => '10/10/2026' ), 'Y-m-d only' ),
			'impossible date'      => array( array( 'kind' => 'module', 'code' => 'base', 'paid_through' => '2026-02-30' ), 'a real calendar date' ),
			'unknown source'       => array( array( 'kind' => 'module', 'code' => 'base', 'source' => 'gift' ), 'plan|addon|manual' ),
		);
	}

	public function test_a_classic_licence_cannot_carry_lines(): void {
		$classic = $this->make( LicenseService::class )->create( array( 'key_string' => 'CLASSIC-' . wp_generate_password( 12, false ) ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->lines()->add_line( $classic->id, array( 'kind' => 'module', 'code' => 'base' ) );
	}

	public function test_updating_a_line_is_validated_the_same_way(): void {
		$lid  = $this->profile_licence_id();
		$line = $this->lines()->add_line( $lid, array( 'kind' => 'module', 'code' => 'base', 'paid_through' => '2026-10-10' ) );

		$updated = $this->lines()->update_line( $line->id, array( 'paid_through' => '2026-11-10' ) );
		$this->assertSame( '2026-11-10', $updated->paid_through );

		$this->expectException( \InvalidArgumentException::class );
		$this->lines()->update_line( $line->id, array( 'code' => 'payroll' ) );
	}

	public function test_deleting_a_line_removes_it(): void {
		$lid  = $this->profile_licence_id();
		$line = $this->lines()->add_line( $lid, array( 'kind' => 'module', 'code' => 'base' ) );

		$this->assertTrue( $this->lines()->delete_line( $line->id ) );
		$this->assertSame( array(), $this->lines()->lines( $lid ) );
	}

	/** @dataProvider periods */
	public function test_period_arithmetic_clamps_to_month_end( string $from, int $n, string $period, string $expected ): void {
		$this->assertSame( $expected, EntitlementService::add_period( $from, $n, $period ) );
	}

	public function periods(): array {
		return array(
			'plain month'           => array( '2026-09-11', 1, 'month', '2026-10-11' ),
			'31 Jan + 1 month'      => array( '2027-01-31', 1, 'month', '2027-02-28' ),
			'31 Jan + 1 month leap' => array( '2028-01-31', 1, 'month', '2028-02-29' ),
			'across the year'       => array( '2026-12-15', 2, 'month', '2027-02-15' ),
			'29 Feb + 1 year'       => array( '2028-02-29', 1, 'year', '2029-02-28' ),
			'back a month'          => array( '2027-01-15', -1, 'month', '2026-12-15' ),
			'back a day'            => array( '2027-03-01', -1, 'day', '2027-02-28' ),
			'two weeks'             => array( '2026-09-11', 2, 'week', '2026-09-25' ),
		);
	}

	/**
	 * `paid_through` is the inclusive last paid day **in the site's time zone**: a Karachi customer
	 * who pays at 02:00 PKT (21:00 UTC the day before) must not get a paid-through date a day early.
	 */
	public function test_paid_through_is_the_day_before_the_end_in_the_site_time_zone(): void {
		update_option( 'timezone_string', 'Asia/Karachi' );
		$this->assertSame( '2026-10-10', EntitlementService::paid_through_for( '2026-10-10 21:00:00' ), '21:00 UTC is 02:00 on the 11th in Karachi.' );
		$this->assertSame( '2026-10-09', EntitlementService::paid_through_for( '2026-10-10 18:00:00' ), '18:00 UTC is 23:00 on the 10th in Karachi.' );

		update_option( 'timezone_string', 'UTC' );
		$this->assertSame( '2026-10-09', EntitlementService::paid_through_for( '2026-10-10 21:00:00' ) );
	}
}
