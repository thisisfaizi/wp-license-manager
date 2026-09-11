<?php
/**
 * Licence profiles come only from add-ons: WPLM builds none in, and a malformed registration is ignored.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Licensing;

use WPLM\Licensing\Profile;
use WPLM\Licensing\ProfileRegistry;
use WPLM\Tests\TestCase;

class ProfileRegistryTest extends TestCase {

	public function test_wplm_registers_no_profile_of_its_own(): void {
		remove_all_filters( 'wplm_license_profiles' );

		$this->assertSame( array(), ( new ProfileRegistry() )->all() );
		$this->assertNull( ( new ProfileRegistry() )->get( 'super-ledger' ) );
	}

	public function test_an_add_on_registers_its_profile_through_the_filter(): void {
		$profile = ( new ProfileRegistry() )->get( 'acme-office' );

		$this->assertInstanceOf( Profile::class, $profile, 'The test bootstrap registers acme-office the way an add-on does.' );
		$this->assertSame( 'Acme Office', $profile->label );
	}

	public function test_a_malformed_registration_is_ignored(): void {
		add_filter(
			'wplm_license_profiles',
			static function ( array $profiles ): array {
				$profiles['mismatch']    = new Profile( 'other', 'Key and code differ', array( 'base' ), array() );
				$profiles['Bad Code']    = new Profile( 'Bad Code', 'Spaces and capitals', array( 'base' ), array() );
				$profiles['not-profile'] = array( 'code' => 'not-profile' );
				return $profiles;
			}
		);

		$this->assertSame( array( 'acme-office' ), array_keys( ( new ProfileRegistry() )->all() ) );
	}
}
