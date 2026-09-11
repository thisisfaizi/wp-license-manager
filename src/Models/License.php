<?php
/**
 * License model.
 *
 * @package WPLM\Models
 */

namespace WPLM\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a single license record. All properties map 1:1 to wplm_licenses
 * columns. The `license_key` property holds the DECRYPTED plaintext key —
 * never store this object in a cache; cache only the encrypted DB row.
 */
class License {

	public int $id = 0;

	/** Decrypted plaintext license key string. */
	public string $license_key = '';

	/** SHA-256 hash of the plaintext key (for DB lookups). */
	public string $hash = '';

	/** Ed25519 signed token for this license. */
	public string $signature = '';

	public ?int $product_id = null;
	public ?int $order_id   = null;
	public ?int $user_id    = null;

	/**
	 * Status code.
	 * 0 pending | 1 active | 2 inactive | 3 expired | 4 suspended | 5 revoked | 6 terminated
	 */
	public int $status = 0;

	public ?int $max_activations = null;
	public int $activation_count = 0;
	public bool $is_floating     = false;

	/** deny | allow_1_25x | allow_2x */
	public string $overage_strategy = 'deny';

	public ?int $valid_for_days  = null;
	public ?string $activated_at = null;
	public ?string $expires_at   = null;
	public int $grace_days       = 0;

	/** 0 import | 1 generator | 2 api | 3 woocommerce */
	public int $source = 2;

	/**
	 * Licence profile code (see ProfileRegistry), or null for a classic licence. A profile licence
	 * never expires as a whole: its entitlement lines carry the dates.
	 */
	public ?string $profile = null;

	public string $created_at = '';
	public ?int $created_by   = null;
	public string $updated_at = '';

	/** @var Machine[] Active machines for this license (populated on demand). */
	public array $machines = array();

	// -------------------------------------------------------------------------
	// Status helpers
	// -------------------------------------------------------------------------

	public function is_active(): bool {
		return 1 === $this->status;
	}

	public function is_expired(): bool {
		return 3 === $this->status;
	}

	public function is_revoked(): bool {
		return 5 === $this->status;
	}

	public function is_terminated(): bool {
		return 6 === $this->status;
	}

	public function is_suspended(): bool {
		return 4 === $this->status;
	}

	/**
	 * Return whether this license is within its expiry window (including grace).
	 */
	public function is_within_expiry(): bool {
		if ( null === $this->expires_at ) {
			return true; // perpetual
		}
		$grace_until = strtotime( $this->expires_at ) + ( $this->grace_days * DAY_IN_SECONDS );
		return time() <= $grace_until;
	}

	/** Build the status label for display. */
	public function status_label(): string {
		$labels = array(
			0 => __( 'Pending', 'wp-license-manager' ),
			1 => __( 'Active', 'wp-license-manager' ),
			2 => __( 'Inactive', 'wp-license-manager' ),
			3 => __( 'Expired', 'wp-license-manager' ),
			4 => __( 'Suspended', 'wp-license-manager' ),
			5 => __( 'Revoked', 'wp-license-manager' ),
			6 => __( 'Terminated', 'wp-license-manager' ),
		);
		return $labels[ $this->status ] ?? __( 'Unknown', 'wp-license-manager' );
	}

	/** Create a License from a database row (columns as returned by $wpdb). */
	public static function from_row( array $row ): self {
		$license                   = new self();
		$license->id               = (int) ( $row['id'] ?? 0 );
		$license->license_key      = $row['license_key'] ?? '';
		$license->hash             = $row['hash'] ?? '';
		$license->signature        = $row['signature'] ?? '';
		$license->product_id       = isset( $row['product_id'] ) ? (int) $row['product_id'] : null;
		$license->order_id         = isset( $row['order_id'] ) ? (int) $row['order_id'] : null;
		$license->user_id          = isset( $row['user_id'] ) ? (int) $row['user_id'] : null;
		$license->status           = (int) ( $row['status'] ?? 0 );
		$license->max_activations  = isset( $row['max_activations'] ) ? (int) $row['max_activations'] : null;
		$license->activation_count = (int) ( $row['activation_count'] ?? 0 );
		$license->is_floating      = (bool) ( $row['is_floating'] ?? false );
		$license->overage_strategy = $row['overage_strategy'] ?? 'deny';
		$license->valid_for_days   = isset( $row['valid_for_days'] ) ? (int) $row['valid_for_days'] : null;
		$license->activated_at     = $row['activated_at'] ?? null;
		$license->expires_at       = $row['expires_at'] ?? null;
		$license->grace_days       = (int) ( $row['grace_days'] ?? 0 );
		$license->source           = (int) ( $row['source'] ?? 2 );
		$license->profile          = isset( $row['profile'] ) && '' !== $row['profile'] ? (string) $row['profile'] : null;
		$license->created_at       = $row['created_at'] ?? '';
		$license->created_by       = isset( $row['created_by'] ) ? (int) $row['created_by'] : null;
		$license->updated_at       = $row['updated_at'] ?? '';
		return $license;
	}

	/** Convert to a plain array for REST responses (excludes encrypted key). */
	public function to_array( bool $include_key = false ): array {
		$data = array(
			'id'               => $this->id,
			'product_id'       => $this->product_id,
			'order_id'         => $this->order_id,
			'user_id'          => $this->user_id,
			'status'           => $this->status,
			'status_label'     => $this->status_label(),
			'max_activations'  => $this->max_activations,
			'activation_count' => $this->activation_count,
			'is_floating'      => $this->is_floating,
			'overage_strategy' => $this->overage_strategy,
			'valid_for_days'   => $this->valid_for_days,
			'activated_at'     => $this->activated_at,
			'expires_at'       => $this->expires_at,
			'grace_days'       => $this->grace_days,
			'source'           => $this->source,
			'profile'          => $this->profile,
			'created_at'       => $this->created_at,
			'updated_at'       => $this->updated_at,
		);

		if ( $include_key ) {
			$data['license_key'] = $this->license_key;
			$data['signature']   = $this->signature;
		}

		return $data;
	}
}
