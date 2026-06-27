<?php
/**
 * Package model — a billing tier inside a {@see Plan}.
 *
 * @package WPLM\Models
 */

namespace WPLM\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a row in wplm_packages: a single purchasable tier such as
 * "Monthly", "Yearly", "Lifetime" or a benefit-based plan. Carries its own
 * billing schedule, pricing, licensing rules and a list of benefits.
 */
class Package {

	/** Billing types. */
	public const TYPE_RECURRING = 'recurring';
	public const TYPE_LIFETIME  = 'lifetime';
	public const TYPE_ONETIME   = 'onetime';

	public int $id                  = 0;
	public int $plan_id             = 0;
	public string $name             = '';
	public string $billing_type     = self::TYPE_RECURRING;
	public string $billing_period   = 'month';
	public int $billing_interval    = 1;
	public float $price             = 0.0;
	public float $signup_fee        = 0.0;
	public int $trial_days          = 0;
	public int $length_cycles       = 0;
	public ?int $generator_id       = null;
	public ?int $max_activations    = null;
	public string $overage_strategy = 'deny';
	public ?int $valid_for_days     = null;
	/** @var string[] */
	public array $benefits    = array();
	public int $sort_order    = 0;
	public int $status        = 1;
	public string $created_at = '';

	/**
	 * Hydrate a Package from a database row.
	 *
	 * @param array $row Raw DB row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$pkg                   = new self();
		$pkg->id               = (int) ( $row['id'] ?? 0 );
		$pkg->plan_id          = (int) ( $row['plan_id'] ?? 0 );
		$pkg->name             = (string) ( $row['name'] ?? '' );
		$pkg->billing_type     = (string) ( $row['billing_type'] ?? self::TYPE_RECURRING );
		$pkg->billing_period   = (string) ( $row['billing_period'] ?? 'month' );
		$pkg->billing_interval = (int) ( $row['billing_interval'] ?? 1 );
		$pkg->price            = (float) ( $row['price'] ?? 0 );
		$pkg->signup_fee       = (float) ( $row['signup_fee'] ?? 0 );
		$pkg->trial_days       = (int) ( $row['trial_days'] ?? 0 );
		$pkg->length_cycles    = (int) ( $row['length_cycles'] ?? 0 );
		$pkg->generator_id     = isset( $row['generator_id'] ) && '' !== $row['generator_id'] ? (int) $row['generator_id'] : null;
		$pkg->max_activations  = isset( $row['max_activations'] ) && '' !== $row['max_activations'] ? (int) $row['max_activations'] : null;
		$pkg->overage_strategy = (string) ( $row['overage_strategy'] ?? 'deny' );
		$pkg->valid_for_days   = isset( $row['valid_for_days'] ) && '' !== $row['valid_for_days'] ? (int) $row['valid_for_days'] : null;
		$pkg->benefits         = self::decode_benefits( $row['benefits'] ?? null );
		$pkg->sort_order       = (int) ( $row['sort_order'] ?? 0 );
		$pkg->status           = (int) ( $row['status'] ?? 1 );
		$pkg->created_at       = (string) ( $row['created_at'] ?? '' );
		return $pkg;
	}

	/**
	 * Decode the stored benefits column into a string array.
	 *
	 * Accepts JSON arrays or newline-separated text for resilience.
	 *
	 * @param mixed $raw Stored benefits value.
	 * @return string[]
	 */
	private static function decode_benefits( $raw ): array {
		if ( empty( $raw ) ) {
			return array();
		}
		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'strval', $raw ) ) );
		}
		$decoded = json_decode( (string) $raw, true );
		if ( is_array( $decoded ) ) {
			return array_values( array_filter( array_map( 'strval', $decoded ) ) );
		}
		// Fallback: newline-separated.
		return array_values( array_filter( array_map( 'trim', explode( "\n", (string) $raw ) ) ) );
	}

	/** Whether this package bills on a recurring schedule. */
	public function is_recurring(): bool {
		return self::TYPE_RECURRING === $this->billing_type;
	}

	/**
	 * Convert to a plain array for REST/JSON responses.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'id'               => $this->id,
			'plan_id'          => $this->plan_id,
			'name'             => $this->name,
			'billing_type'     => $this->billing_type,
			'billing_period'   => $this->billing_period,
			'billing_interval' => $this->billing_interval,
			'price'            => $this->price,
			'signup_fee'       => $this->signup_fee,
			'trial_days'       => $this->trial_days,
			'length_cycles'    => $this->length_cycles,
			'generator_id'     => $this->generator_id,
			'max_activations'  => $this->max_activations,
			'overage_strategy' => $this->overage_strategy,
			'valid_for_days'   => $this->valid_for_days,
			'benefits'         => $this->benefits,
			'sort_order'       => $this->sort_order,
			'status'           => $this->status,
		);
	}
}
