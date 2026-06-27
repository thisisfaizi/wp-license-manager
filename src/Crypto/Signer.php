<?php
/**
 * Ed25519 license signing and verification.
 *
 * @package WPLM\Crypto
 */

namespace WPLM\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Signs license payloads with Ed25519 (via libsodium) and verifies those
 * signatures. The format is: base64url(payload) . '.' . base64url(signature).
 * Clients split on the first '.' and call sodium_crypto_sign_verify_detached
 * with the public key exposed at GET /wplm/v1/public-key.
 */
class Signer {

	private string $secret_key;
	private string $public_key;

	public function __construct() {
		$this->load_keypair();
	}

	/**
	 * Sign a data array and return a compact signed token.
	 *
	 * @param array<string, mixed> $payload The payload to sign.
	 * @return string base64url(json_payload) . '.' . base64url(signature)
	 * @throws \RuntimeException When sodium is unavailable or signing fails.
	 */
	public function sign( array $payload ): string {
		if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
			throw new \RuntimeException( 'WPLM Signer: libsodium is required for Ed25519 signing.' );
		}

		$body = self::base64url_encode( wp_json_encode( $payload ) );
		$sig  = self::base64url_encode(
			sodium_crypto_sign_detached( $body, $this->secret_key )
		);

		return $body . '.' . $sig;
	}

	/**
	 * Verify a signed token and return the decoded payload, or null on failure.
	 *
	 * @param string $token The signed token string.
	 * @return array<string, mixed>|null Decoded payload or null on invalid signature.
	 */
	public function verify( string $token ): ?array {
		$parts = explode( '.', $token, 2 );
		if ( count( $parts ) !== 2 ) {
			return null;
		}

		[ $body, $sig_b64 ] = $parts;

		$sig = self::base64url_decode( $sig_b64 );
		if ( false === $sig ) {
			return null;
		}

		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return null;
		}

		try {
			$valid = sodium_crypto_sign_verify_detached( $sig, $body, $this->public_key );
		} catch ( \Exception $e ) {
			return null;
		}

		if ( ! $valid ) {
			return null;
		}

		$json = self::base64url_decode( $body );
		return $json ? json_decode( $json, true ) : null;
	}

	/** Return the base64-encoded public key (safe to expose via REST). */
	public function get_public_key_base64(): string {
		return base64_encode( $this->public_key );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	public static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	public static function base64url_decode( string $data ): string|false {
		$padded = str_pad( strtr( $data, '-_', '+/' ), strlen( $data ) + ( 4 - strlen( $data ) % 4 ) % 4, '=' );
		return base64_decode( $padded, true );
	}

	/** Load the keypair from the option or the constant. */
	private function load_keypair(): void {
		if ( defined( 'WPLM_SIGNING_KEYPAIR' ) && WPLM_SIGNING_KEYPAIR ) {
			$encoded = json_decode( WPLM_SIGNING_KEYPAIR, true );
		} else {
			$stored  = get_option( 'wplm_signing_keypair', '' );
			$encoded = $stored ? json_decode( $stored, true ) : null;
		}

		if ( ! $encoded || empty( $encoded['sec'] ) || empty( $encoded['pub'] ) ) {
			throw new \RuntimeException( 'WPLM Signer: signing keypair is missing. Run activation again via Settings → Tools → Re-roll keypair.' );
		}

		$this->secret_key = base64_decode( $encoded['sec'], true );
		$this->public_key = base64_decode( $encoded['pub'], true );

		if ( ! $this->secret_key || ! $this->public_key ) {
			throw new \RuntimeException( 'WPLM Signer: keypair decode failed.' );
		}
	}
}
