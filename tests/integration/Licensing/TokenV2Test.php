<?php
/**
 * The v2 token: the signed, machine-bound statement of what one machine of an entitlement licence may
 * do and until when. Its field shape is the contract a client verifies without PHP.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Licensing;

use WPLM\Crypto\Signer;
use WPLM\Licensing\ProfileRegistry;
use WPLM\Licensing\TokenV2Service;
use WPLM\Services\EntitlementService;
use WPLM\Services\LicenseService;
use WPLM\Tests\TestCase;

class TokenV2Test extends TestCase {

	/** A fixed issue time: 2026-09-11 10:00:00 UTC. */
	private const NOW = 1789120800;

	private function tokens(): TokenV2Service {
		return $this->make( TokenV2Service::class );
	}

	private function lines(): EntitlementService {
		return $this->make( EntitlementService::class );
	}

	/** A date relative to NOW, in the site's time zone (UTC in tests). */
	private function day( int $offset_days ): string {
		return gmdate( 'Y-m-d', self::NOW + $offset_days * DAY_IN_SECONDS );
	}

	private function issue( array $bound, array $opts = array() ) {
		return $this->tokens()->payload( $bound['license'], $bound['machine'], $bound['fp'], $opts + array( 'now' => self::NOW ) );
	}

	public function test_payload_carries_exactly_the_contract_fields(): void {
		$bound   = $this->activated_profile_licence();
		$payload = $this->issue( $bound );

		$this->assertIsArray( $payload );
		$keys = array_keys( $payload );
		sort( $keys );
		$this->assertSame( array( 'checkInBy', 'fp', 'graceDays', 'iat', 'lid', 'limits', 'mid', 'modules', 'pid', 'srv', 'status', 'v' ), $keys );

		$this->assertSame( 2, $payload['v'] );
		$this->assertSame( 'acme-office', $payload['pid'] );
		$this->assertSame( $bound['license']->id, $payload['lid'] );
		$this->assertSame( $bound['machine']->id, $payload['mid'] );
		$this->assertSame( $bound['fp'], $payload['fp'] );
		$this->assertSame( self::NOW, $payload['iat'] );
		$this->assertSame( self::NOW, $payload['srv'] );
		$this->assertSame( self::NOW + 7 * DAY_IN_SECONDS, $payload['checkInBy'], 'Check-in window defaults to 7 days (O-1).' );
		$this->assertSame( 7, $payload['graceDays'], 'Grace defaults to 7 days (D1).' );
		$this->assertSame( 'active', $payload['status'] );
	}

	public function test_module_until_is_the_latest_paid_through_and_null_when_any_line_is_lifetime(): void {
		$bound = $this->activated_profile_licence();
		$lid   = $bound['license']->id;
		$this->lines()->add_line( $lid, array( 'kind' => 'module', 'code' => 'base', 'paid_through' => $this->day( 20 ) ) );
		$this->lines()->add_line( $lid, array( 'kind' => 'module', 'code' => 'base', 'paid_through' => $this->day( 50 ) ) );
		$this->lines()->add_line( $lid, array( 'kind' => 'module', 'code' => 'pos', 'paid_through' => $this->day( 20 ) ) );
		$this->lines()->add_line( $lid, array( 'kind' => 'module', 'code' => 'pos', 'paid_through' => null ) );
		$this->lines()->add_line( $lid, array( 'kind' => 'module', 'code' => 'distribution', 'paid_through' => $this->day( -30 ) ) );

		$modules = $this->issue( $bound )['modules'];

		$this->assertSame(
			array(
				'base'         => array( 'until' => $this->day( 50 ) ),
				'distribution' => array( 'until' => $this->day( -30 ) ),
				'pos'          => array( 'until' => null ),
			),
			$modules,
			'A lapsed module is still listed: the app, not the server, turns it read-only.'
		);
	}

	public function test_limits_sum_only_lines_that_have_not_lapsed_past_grace(): void {
		$bound = $this->activated_profile_licence();
		$lid   = $bound['license']->id;
		$this->lines()->add_line( $lid, array( 'kind' => 'limit', 'code' => 'users', 'qty' => 3, 'paid_through' => $this->day( 5 ) ) );
		$this->lines()->add_line( $lid, array( 'kind' => 'limit', 'code' => 'users', 'qty' => 2, 'paid_through' => $this->day( -7 ) ) );
		$this->lines()->add_line( $lid, array( 'kind' => 'limit', 'code' => 'users', 'qty' => 4, 'paid_through' => $this->day( -8 ) ) );
		$this->lines()->add_line( $lid, array( 'kind' => 'limit', 'code' => 'phones', 'qty' => 1, 'paid_through' => null ) );

		$limits = $this->issue( $bound )['limits'];

		$this->assertSame(
			array(
				'users'  => 5,
				'seats'  => 0,
				'phones' => 1,
			),
			$limits,
			'Inside grace (today − 7) still counts; one day past grace does not; lifetime always counts.'
		);
	}

	public function test_empty_maps_are_json_objects_in_the_signed_token(): void {
		$bound  = $this->activated_profile_licence();
		$issued = $this->tokens()->issue( $bound['license'], $bound['machine'], $bound['fp'], array( 'now' => self::NOW ) );

		$this->assertIsArray( $issued );
		$json = Signer::base64url_decode( explode( '.', $issued['token'], 2 )[0] );
		$this->assertStringContainsString( '"modules":{}', $json, 'An empty PHP array would encode as [], which a Dart Map decode rejects.' );
		$this->assertStringContainsString( '"limits":{"users":0,"seats":0,"phones":0}', $json );
	}

	public function test_token_verifies_against_the_public_key(): void {
		$bound = $this->activated_profile_licence();
		$this->lines()->add_line( $bound['license']->id, array( 'kind' => 'module', 'code' => 'base', 'paid_through' => $this->day( 30 ) ) );
		$issued = $this->tokens()->issue( $bound['license'], $bound['machine'], $bound['fp'], array( 'now' => self::NOW ) );

		$this->assertIsArray( $issued );
		$verified = $this->make( Signer::class )->verify( $issued['token'] );
		$this->assertSame( $this->day( 30 ), $verified['modules']['base']['until'] );
		$this->assertSame( $issued['payload']['checkInBy'], $verified['checkInBy'] );

		$tampered = $issued['token'];
		$tampered[3] = 'A' === $tampered[3] ? 'B' : 'A';
		$this->assertNull( $this->make( Signer::class )->verify( $tampered ) );
	}

	public function test_check_in_window_and_grace_come_from_the_profile_settings(): void {
		$profile = $this->make( ProfileRegistry::class )->get( 'acme-office' );
		update_option( $profile->option_name( 'check_in_days' ), '3' );
		update_option( $profile->option_name( 'grace_days' ), '10' );

		$payload = $this->issue( $this->activated_profile_licence() );

		$this->assertSame( self::NOW + 3 * DAY_IN_SECONDS, $payload['checkInBy'] );
		$this->assertSame( 10, $payload['graceDays'] );
	}

	public function test_a_suspended_licence_still_gets_a_token_that_says_so(): void {
		$bound = $this->activated_profile_licence();
		$this->make( LicenseService::class )->change_status( $bound['license']->id, 4 );

		$payload = $this->issue( $this->reload( $bound ) );

		$this->assertSame( 'suspended', $payload['status'], 'Suspended offices must learn it at check-in so they go read-only with the right reason.' );
	}

	public function test_revoked_and_terminated_licences_get_no_token(): void {
		foreach ( array( 5 => 'license_revoked', 6 => 'license_terminated' ) as $status => $code ) {
			$bound = $this->activated_profile_licence();
			$this->set_row( 'licenses', $bound['license']->id, array( 'status' => $status ) );

			$result = $this->issue( $this->reload( $bound ) );

			$this->assertWPError( $result );
			$this->assertSame( $code, $result->get_error_code() );
		}
	}

	public function test_an_expired_status_left_by_classic_validation_does_not_block_the_token(): void {
		$bound = $this->activated_profile_licence();
		$this->set_row( 'licenses', $bound['license']->id, array( 'status' => 3 ) );

		$this->assertSame( 'active', $this->issue( $this->reload( $bound ) )['status'] );
	}

	public function test_the_fingerprint_must_be_the_machines_own(): void {
		$bound = $this->activated_profile_licence();

		$other = $this->tokens()->payload( $bound['license'], $bound['machine'], hash( 'sha256', 'acme-office|another-pc' ), array( 'now' => self::NOW ) );
		$this->assertWPError( $other );
		$this->assertSame( 'wplm_fingerprint_mismatch', $other->get_error_code() );

		$malformed = $this->tokens()->payload( $bound['license'], $bound['machine'], 'not-a-hash', array( 'now' => self::NOW ) );
		$this->assertWPError( $malformed );
		$this->assertSame( 'wplm_invalid_fingerprint', $malformed->get_error_code() );
	}

	public function test_a_machine_from_another_licence_or_deactivated_gets_no_token(): void {
		$first  = $this->activated_profile_licence( 'pc-one' );
		$second = $this->activated_profile_licence( 'pc-two' );

		$crossed = $this->tokens()->payload( $first['license'], $second['machine'], $second['fp'], array( 'now' => self::NOW ) );
		$this->assertWPError( $crossed );
		$this->assertSame( 'wplm_machine_not_active', $crossed->get_error_code() );

		$this->set_row( 'machines', $first['machine']->id, array( 'status' => 2 ) );
		$inactive = $this->issue( $this->reload( $first ) );
		$this->assertWPError( $inactive );
		$this->assertSame( 'wplm_machine_not_active', $inactive->get_error_code() );
	}

	public function test_a_classic_licence_gets_no_v2_token(): void {
		$bound = $this->activated_profile_licence();
		$this->set_row( 'licenses', $bound['license']->id, array( 'profile' => null ) );

		$result = $this->issue( $this->reload( $bound ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wplm_not_entitlement_license', $result->get_error_code() );
	}
}
