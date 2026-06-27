<?php
/**
 * Seeder — generates cryptographic secrets on first activation.
 *
 * @package WPLM\Install
 */

namespace WPLM\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Generates and stores the four long-lived secrets that WPLM needs:
 *   1. Ed25519 signing keypair
 *   2. AES-256-GCM encryption key
 *   3. HMAC-SHA256 fingerprint secret
 *   4. Webhook signing secret
 *
 * Secrets are stored in wp_options with autoload=off to avoid leaking them into
 * every page-load query. All constants may be defined in wp-config.php to
 * override the stored values (useful for environment-level secret management).
 */
class Seeder {

	/** Run: generate secrets that are not yet present. */
	public function run(): void {
		$this->maybe_generate_signing_keypair();
		$this->maybe_generate_encryption_key();
		$this->maybe_generate_fingerprint_hmac();
		$this->maybe_generate_webhook_secret();

		// Default settings.
		$this->seed_default_options();
	}

	// -------------------------------------------------------------------------
	// Secret generation helpers
	// -------------------------------------------------------------------------

	/**
	 * Force-regenerate the Ed25519 signing keypair (Tools → danger zone).
	 *
	 * WARNING: this invalidates every previously-issued signed license payload,
	 * since clients verifying with the old public key will fail. The encryption
	 * key is deliberately left untouched so stored keys remain decryptable.
	 *
	 * @return bool True when a new keypair was generated.
	 */
	public function regenerate_signing_keypair(): bool {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			return false;
		}

		delete_option( 'wplm_signing_keypair' );
		$this->maybe_generate_signing_keypair();

		return (bool) get_option( 'wplm_signing_keypair' );
	}

	private function maybe_generate_signing_keypair(): void {
		if ( get_option( 'wplm_signing_keypair' ) ) {
			return;
		}

		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			// Fall back to a stored flag so we skip gracefully; crypto layer handles fallback.
			update_option( 'wplm_signing_keypair', '', false );
			return;
		}

		$keypair = sodium_crypto_sign_keypair();
		// Store as base64 to survive utf8mb4 encoding.
		$encoded = array(
			'kp'  => base64_encode( $keypair ),
			'pub' => base64_encode( sodium_crypto_sign_publickey( $keypair ) ),
			'sec' => base64_encode( sodium_crypto_sign_secretkey( $keypair ) ),
		);

		update_option( 'wplm_signing_keypair', wp_json_encode( $encoded ), false );
	}

	private function maybe_generate_encryption_key(): void {
		if ( get_option( 'wplm_encryption_key' ) ) {
			return;
		}
		// 256-bit random key.
		$key = base64_encode( random_bytes( 32 ) );
		update_option( 'wplm_encryption_key', $key, false );
	}

	private function maybe_generate_fingerprint_hmac(): void {
		if ( get_option( 'wplm_fingerprint_hmac' ) ) {
			return;
		}
		$secret = base64_encode( random_bytes( 32 ) );
		update_option( 'wplm_fingerprint_hmac', $secret, false );
	}

	private function maybe_generate_webhook_secret(): void {
		if ( get_option( 'wplm_webhook_secret' ) ) {
			return;
		}
		$secret = base64_encode( random_bytes( 32 ) );
		update_option( 'wplm_webhook_secret', $secret, false );
	}

	/** Store plugin defaults on first activation (skip if already present). */
	private function seed_default_options(): void {
		$defaults = array(
			'wplm_default_max_activations'  => 1,
			'wplm_default_overage_strategy' => 'deny',
			'wplm_heartbeat_interval'       => 600,
			'wplm_zombie_window'            => 1200,
			'wplm_telemetry_retention_days' => 90,
			'wplm_order_complete_status'    => 'completed',
			'wplm_hide_key_in_email'        => 0,
			'wplm_force_ssl'                => 1,
			'wplm_sub_engine_enabled'       => 1,
			'wplm_dunning_schedule'         => wp_json_encode( array( 1, 3, 5 ) ),
			'wplm_qr_enabled'               => 1,
			'wplm_qr_size'                  => 200,
			'wplm_webhook_retry_attempts'   => 5,
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value, '', 'yes' ); // autoload defaults that are read frequently
			}
		}
	}
}
