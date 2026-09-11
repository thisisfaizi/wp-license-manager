<?php
/**
 * The compact signed-token format, with no WordPress dependency.
 *
 * @package WPLM\Crypto
 */

namespace WPLM\Crypto;

// Loadable without WordPress (bin/fleet-notice.php runs from an offline key backup).
defined( 'ABSPATH' ) || defined( 'WPLM_STANDALONE' ) || exit;

/**
 * `base64url(json) . '.' . base64url(ed25519_signature_over_the_base64url_body)`.
 *
 * The single definition of the format every WPLM token uses: v1 and v2 licence tokens (through
 * Signer, with the site's key), fleet notices and contract fixtures (with a key given explicitly).
 */
final class CompactToken {

	/**
	 * Sign a payload with an Ed25519 secret key.
	 *
	 * @param array<string, mixed> $payload    JSON-encodable payload. Use (object) for maps that may be empty.
	 * @param string               $secret_key Raw 64-byte Ed25519 secret key.
	 * @return string
	 * @throws \RuntimeException When sodium is missing or the payload cannot be encoded.
	 */
	public static function sign( array $payload, string $secret_key ): string {
		if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
			throw new \RuntimeException( 'WPLM: libsodium is required for Ed25519 signing.' );
		}
		// No flags: byte-identical to wp_json_encode(), which v1 tokens were always signed with.
		$json = json_encode( $payload ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- must run without WordPress.
		if ( false === $json ) {
			throw new \RuntimeException( 'WPLM: the token payload could not be encoded as JSON.' );
		}
		$body = self::base64url_encode( $json );
		return $body . '.' . self::base64url_encode( sodium_crypto_sign_detached( $body, $secret_key ) );
	}

	/**
	 * Verify a token and return its payload, or null.
	 *
	 * @param string $token      The token.
	 * @param string $public_key Raw 32-byte Ed25519 public key.
	 * @return array<string, mixed>|null
	 */
	public static function verify( string $token, string $public_key ): ?array {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return null;
		}
		$sig = self::base64url_decode( $parts[1] );
		if ( false === $sig || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public_key ) ) {
			return null;
		}
		if ( ! sodium_crypto_sign_verify_detached( $sig, $parts[0], $public_key ) ) {
			return null;
		}
		$json    = self::base64url_decode( $parts[0] );
		$payload = false !== $json ? json_decode( $json, true ) : null;
		return is_array( $payload ) ? $payload : null;
	}

	/** RFC 4648 base64url without padding. */
	public static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- key and token encoding.
	}

	/** Decode RFC 4648 base64url (padding optional); false on invalid input. */
	public static function base64url_decode( string $data ) {
		$padded = str_pad( strtr( $data, '-_', '+/' ), strlen( $data ) + ( 4 - strlen( $data ) % 4 ) % 4, '=' );
		return base64_decode( $padded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- key and token encoding.
	}

	/**
	 * Read a keypair in WPLM's storage format: JSON `{"sec": base64, "pub": base64}` (the
	 * `WPLM_SIGNING_KEYPAIR` constant and the `wplm_signing_keypair` option use the same shape).
	 *
	 * @param string $json Keypair JSON.
	 * @return array{sec: string, pub: string} Raw keys.
	 * @throws \InvalidArgumentException When the JSON is not a valid Ed25519 keypair.
	 */
	public static function keypair_from_json( string $json ): array {
		$decoded = json_decode( $json, true );
		$sec     = is_array( $decoded ) && isset( $decoded['sec'] ) ? base64_decode( (string) $decoded['sec'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- key and token encoding.
		$pub     = is_array( $decoded ) && isset( $decoded['pub'] ) ? base64_decode( (string) $decoded['pub'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- key and token encoding.
		if ( false === $sec || false === $pub || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $sec ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $pub ) ) {
			throw new \InvalidArgumentException( 'Not a WPLM Ed25519 keypair: expected JSON {"sec": base64 64-byte key, "pub": base64 32-byte key}.' );
		}
		if ( sodium_crypto_sign_publickey_from_secretkey( $sec ) !== $pub ) {
			throw new \InvalidArgumentException( 'The keypair does not match: its public key was not derived from its secret key.' );
		}
		return array(
			'sec' => $sec,
			'pub' => $pub,
		);
	}
}
