<?php
/**
 * Registry of licence profiles.
 *
 * @package WPLM\Licensing
 */

namespace WPLM\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * The known licence profiles, keyed by code. Super Ledger is built in; another product registers
 * its own through the `wplm_license_profiles` filter.
 */
class ProfileRegistry {

	/** Super Ledger's profile code, signed into its tokens as `pid`. */
	public const SUPER_LEDGER = 'super-ledger';

	/** @var array<string, Profile>|null */
	private ?array $profiles = null;

	/** @return array<string, Profile> */
	public function all(): array {
		if ( null === $this->profiles ) {
			$built = array(
				self::SUPER_LEDGER => new Profile(
					self::SUPER_LEDGER,
					'Super Ledger',
					array( 'base', 'distribution', 'pos', 'factory', 'fbr', 'assets', 'subcontract' ),
					array( 'users', 'seats', 'phones' )
				),
			);

			/**
			 * Filter the licence profiles.
			 *
			 * @param array<string, Profile> $built Profiles keyed by code.
			 */
			$filtered       = (array) apply_filters( 'wplm_license_profiles', $built );
			$this->profiles = array();
			foreach ( $filtered as $code => $profile ) {
				if ( $profile instanceof Profile && $code === $profile->code ) {
					$this->profiles[ $code ] = $profile;
				}
			}
		}
		return $this->profiles;
	}

	/** A profile by code, or null (also for null/empty input). */
	public function get( ?string $code ): ?Profile {
		if ( null === $code || '' === $code ) {
			return null;
		}
		return $this->all()[ $code ] ?? null;
	}
}
