<?php
/**
 * AES-256-GCM encryption / decryption for stored license key strings.
 *
 * @package WPLM\Crypto
 */

namespace WPLM\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts and decrypts license key plaintext with libsodium AEAD.
 *
 * Encryption ALWAYS uses XChaCha20-Poly1305-IETF: it is available on every
 * libsodium build regardless of CPU, runs in constant time in software, and
 * uses a large random nonce — so ciphertext stays decryptable after a server
 * migration or library upgrade. Each blob is prefixed with a one-byte algorithm
 * marker so decryption is driven by the stored data, never by runtime hardware
 * detection. AES-256-GCM blobs are still decryptable for forward-compatibility.
 *
 * The encryption key is retrieved from the `wplm_encryption_key` option (stored
 * base64-encoded, unautoloaded) or the `WPLM_ENCRYPTION_KEY` constant.
 */
class KeyVault {

	/** Algorithm markers stored as the first byte of every ciphertext blob. */
	private const ALG_AES_256_GCM = "\x01";
	private const ALG_XCHACHA20   = "\x02";

	private string $key;

	public function __construct() {
		$this->key = $this->load_key();
	}

	/**
	 * Encrypt a plaintext license key string.
	 *
	 * Format: base64( alg_marker(1) . nonce . ciphertext+tag ).
	 *
	 * @param string $plaintext The raw license key string.
	 * @return string Base64-encoded ciphertext blob.
	 * @throws \RuntimeException On encryption failure.
	 */
	public function encrypt( string $plaintext ): string {
		$nonce      = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plaintext, '', $nonce, $this->key );

		return base64_encode( self::ALG_XCHACHA20 . $nonce . $ciphertext );
	}

	/**
	 * Decrypt a stored ciphertext back to the plaintext license key.
	 *
	 * @param string $ciphertext Base64-encoded blob from the database.
	 * @return string The original plaintext key.
	 * @throws \RuntimeException On decryption failure.
	 */
	public function decrypt( string $ciphertext ): string {
		$blob = base64_decode( $ciphertext, true );
		if ( false === $blob || strlen( $blob ) < 2 ) {
			throw new \RuntimeException( 'WPLM KeyVault: invalid ciphertext (bad base64).' );
		}

		$alg  = $blob[0];
		$body = substr( $blob, 1 );

		if ( self::ALG_AES_256_GCM === $alg ) {
			if ( ! $this->aes_available() ) {
				throw new \RuntimeException( 'WPLM KeyVault: ciphertext requires AES-256-GCM, unavailable on this server.' );
			}
			$nonce_len = SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES;
			$nonce     = substr( $body, 0, $nonce_len );
			$ct        = substr( $body, $nonce_len );
			$plaintext = sodium_crypto_aead_aes256gcm_decrypt( $ct, '', $nonce, $this->key );
		} elseif ( self::ALG_XCHACHA20 === $alg ) {
			$nonce_len = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
			$nonce     = substr( $body, 0, $nonce_len );
			$ct        = substr( $body, $nonce_len );
			$plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $ct, '', $nonce, $this->key );
		} else {
			throw new \RuntimeException( 'WPLM KeyVault: unrecognised ciphertext algorithm marker.' );
		}

		if ( false === $plaintext ) {
			throw new \RuntimeException( 'WPLM KeyVault: decryption failed (tampered or wrong key).' );
		}

		return $plaintext;
	}

	/** Whether hardware AES-256-GCM is available on this CPU. */
	private function aes_available(): bool {
		return function_exists( 'sodium_crypto_aead_aes256gcm_is_available' )
			&& sodium_crypto_aead_aes256gcm_is_available();
	}

	/** Load the 256-bit key from the constant or the option. */
	private function load_key(): string {
		if ( defined( 'WPLM_ENCRYPTION_KEY' ) && WPLM_ENCRYPTION_KEY ) {
			$raw = base64_decode( WPLM_ENCRYPTION_KEY, true );
		} else {
			$stored = get_option( 'wplm_encryption_key', '' );
			$raw    = $stored ? base64_decode( $stored, true ) : false;
		}

		if ( ! $raw || strlen( $raw ) !== 32 ) {
			throw new \RuntimeException( 'WPLM KeyVault: encryption key is missing or not 256 bits. Regenerate via Settings → Tools.' );
		}

		return $raw;
	}
}
