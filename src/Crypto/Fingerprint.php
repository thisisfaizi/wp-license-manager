<?php
/**
 * Device fingerprint hashing helpers.
 *
 * @package WPLM\Crypto
 */

namespace WPLM\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * HMAC-SHA256 anonymization of device fingerprints before storage.
 * Raw fingerprints (e.g. machine GUID, SHA-256 of hardware identifiers) are
 * hashed with a site-specific secret so stored fingerprints are pseudonymous
 * and cannot be reverse-engineered to identify hardware.
 *
 * Clients MAY send pre-hashed fingerprints; the server always runs them
 * through this class so the storage format is consistent regardless of
 * whether the client hashed or not.
 */
class Fingerprint {

	/** Loaded on first use, so constructing the service never fails (audit F9). */
	private ?string $hmac_secret = null;

	/**
	 * Hash a device fingerprint for storage.
	 *
	 * @param string $raw_fingerprint The raw or pre-hashed fingerprint from the client.
	 * @return string 64-character lowercase hex HMAC-SHA256.
	 */
	public function hash( string $raw_fingerprint ): string {
		if ( null === $this->hmac_secret ) {
			$this->hmac_secret = $this->load_secret();
		}
		return hash_hmac( 'sha256', $raw_fingerprint, $this->hmac_secret );
	}

	/**
	 * Compute a SHA-256 hash of an arbitrary string (for non-HMAC use-cases
	 * such as the license_key hash column).
	 *
	 * @param string $data Input data.
	 * @return string 64-character hex SHA-256 digest.
	 */
	public static function sha256( string $data ): string {
		return hash( 'sha256', $data );
	}

	/** Load HMAC secret from the constant or the option. */
	private function load_secret(): string {
		if ( defined( 'WPLM_FINGERPRINT_HMAC' ) && WPLM_FINGERPRINT_HMAC ) {
			$raw = base64_decode( WPLM_FINGERPRINT_HMAC, true );
		} else {
			$stored = get_option( 'wplm_fingerprint_hmac', '' );
			$raw    = $stored ? base64_decode( $stored, true ) : false;
		}

		if ( ! $raw ) {
			throw new \RuntimeException( 'WPLM Fingerprint: HMAC secret is missing.' );
		}

		return $raw;
	}
}
