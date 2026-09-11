<?php
/**
 * Offline renewal codes: for a machine with no internet, the owner issues a normal v2
 * token for that machine with a longer check-in deadline, and sends it over WhatsApp.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Licensing;

use WPLM\Crypto\Signer;
use WPLM\Licensing\CheckInService;
use WPLM\Licensing\OfflineCodeService;
use WPLM\Repositories\ActivationLogRepository;
use WPLM\Services\EntitlementService;
use WPLM\Tests\TestCase;

class OfflineCodeTest extends TestCase {

	private const NOW = 1789120800; // 2026-09-11 10:00:00 UTC.

	private function codes(): OfflineCodeService {
		return $this->make( OfflineCodeService::class );
	}

	/** A machine of an entitlement licence that has checked in once, so the server knows the fp it signs. */
	private function checked_in_machine(): array {
		$bound  = $this->activated_profile_licence();
		$result = $this->make( CheckInService::class )->check_in( $bound['license'], $bound['fp'], array(), null );
		$this->assertNotWPError( $result );
		$this->make( EntitlementService::class )->add_line( $bound['license']->id, array( 'kind' => 'module', 'code' => 'base', 'paid_through' => '2026-10-10' ) );
		return $this->reload( $bound );
	}

	public function test_a_code_is_a_v2_token_with_the_chosen_check_in_deadline(): void {
		$bound = $this->checked_in_machine();

		$code = $this->codes()->issue( $bound['machine']->id, 30, 1, self::NOW );

		$this->assertIsArray( $code );
		$payload = $this->make( Signer::class )->verify( $code['token'] );
		$this->assertSame( self::NOW + 30 * DAY_IN_SECONDS, $payload['checkInBy'] );
		$this->assertSame( self::NOW + 30 * DAY_IN_SECONDS, $code['check_in_by'] );
		$this->assertSame( $bound['fp'], $payload['fp'], 'Bound to that machine, like any token.' );
		$this->assertSame( '2026-10-10', $payload['modules']['base']['until'], 'It never extends what is paid for.' );
		$this->assertSame( 7, $payload['graceDays'] );
	}

	public function test_issuing_a_code_is_logged_with_who_and_how_long(): void {
		$bound = $this->checked_in_machine();

		$this->codes()->issue( $bound['machine']->id, 45, 7, self::NOW );

		$events = $this->make( ActivationLogRepository::class )->get_by_license( $bound['license']->id );
		$codes  = array_values( array_filter( $events, static fn( $e ) => 'offline_code' === $e->event ) );
		$this->assertCount( 1, $codes );
		$meta = is_array( $codes[0]->meta ) ? $codes[0]->meta : json_decode( (string) $codes[0]->meta, true );
		$this->assertSame( 7, $meta['user_id'] );
		$this->assertSame( 45, $meta['days'] );
		$this->assertSame( self::NOW + 45 * DAY_IN_SECONDS, $meta['check_in_by'] );
	}

	public function test_a_machine_that_never_checked_in_since_the_upgrade_is_refused_clearly(): void {
		$bound = $this->activated_profile_licence(); // Activated through the service, not a check-in.
		$this->set_row( 'machines', $bound['machine']->id, array( 'token_fp' => null ) );

		$result = $this->codes()->issue( $bound['machine']->id, 30, 1, self::NOW );

		$this->assertWPError( $result );
		$this->assertSame( 'wplm_no_check_in_yet', $result->get_error_code() );
	}

	public function test_days_must_be_between_1_and_365(): void {
		$bound = $this->checked_in_machine();

		foreach ( array( 0, -5, 366 ) as $days ) {
			$result = $this->codes()->issue( $bound['machine']->id, $days, 1, self::NOW );
			$this->assertWPError( $result, "{$days} days" );
			$this->assertSame( 'wplm_invalid_days', $result->get_error_code() );
		}
	}

	public function test_no_code_for_a_revoked_licence_a_deactivated_machine_or_a_classic_licence(): void {
		$revoked = $this->checked_in_machine();
		$this->set_row( 'licenses', $revoked['license']->id, array( 'status' => 5 ) );
		$this->assertSame( 'license_revoked', $this->codes()->issue( $revoked['machine']->id, 30, 1, self::NOW )->get_error_code() );

		$off = $this->checked_in_machine();
		$this->set_row( 'machines', $off['machine']->id, array( 'status' => 2 ) );
		$this->assertSame( 'wplm_machine_not_active', $this->codes()->issue( $off['machine']->id, 30, 1, self::NOW )->get_error_code() );

		$classic = $this->checked_in_machine();
		$this->set_row( 'licenses', $classic['license']->id, array( 'profile' => null ) );
		$this->assertSame( 'wplm_not_entitlement_license', $this->codes()->issue( $classic['machine']->id, 30, 1, self::NOW )->get_error_code() );

		$this->assertSame( 'wplm_machine_not_found', $this->codes()->issue( 999999, 30, 1, self::NOW )->get_error_code() );
	}
}
