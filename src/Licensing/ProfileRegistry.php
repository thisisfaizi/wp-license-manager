<?php
/**
 * Registry of licence profiles.
 *
 * @package WPLM\Licensing
 */

namespace WPLM\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * The known licence profiles, keyed by code. None is built in: each product's add-on plugin registers
 * its own through the `wplm_license_profiles` filter, before WPLM first reads the list.
 *
 * A licence or plan whose profile nobody registers gets no v2 token, so the add-on that registers a
 * profile in use must stay active.
 */
class ProfileRegistry {

	/** A profile code: lower-case letters, digits and hyphens. It is signed as `pid` and used in option names. */
	private const CODE_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/';

	/** @var array<string, Profile>|null */
	private ?array $profiles = null;

	/** @return array<string, Profile> */
	public function all(): array {
		if ( null === $this->profiles ) {
			/**
			 * Filter the licence profiles.
			 *
			 * @param array<string, Profile> $profiles Profiles keyed by code.
			 */
			$filtered       = (array) apply_filters( 'wplm_license_profiles', array() );
			$this->profiles = array();
			foreach ( $filtered as $code => $profile ) {
				if ( $profile instanceof Profile && $code === $profile->code && 1 === preg_match( self::CODE_PATTERN, $code ) ) {
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
