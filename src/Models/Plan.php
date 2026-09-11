<?php
/**
 * Plan model — a reusable subscription offering assigned to WooCommerce products.
 *
 * @package WPLM\Models
 */

namespace WPLM\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a row in wplm_plans. A plan groups one or more {@see Package}
 * tiers (monthly / yearly / lifetime / benefit-based) and is assigned to any
 * native WooCommerce product via the _wplm_plan_id product meta.
 */
class Plan {

	public int $id              = 0;
	public string $name         = '';
	public ?string $description = null;
	/** Licence profile the plan sells (e.g. 'super-ledger'), or null for classic licences. */
	public ?string $profile   = null;
	public int $status        = 1; // 1 active, 0 inactive.
	public string $created_at = '';
	public string $updated_at = '';

	/** @var Package[] Loaded packages (optional; populated by the repository). */
	public array $packages = array();

	/**
	 * Hydrate a Plan from a database row.
	 *
	 * @param array $row Raw DB row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$plan              = new self();
		$plan->id          = (int) ( $row['id'] ?? 0 );
		$plan->name        = (string) ( $row['name'] ?? '' );
		$plan->description = isset( $row['description'] ) ? (string) $row['description'] : null;
		$plan->profile     = isset( $row['profile'] ) && '' !== $row['profile'] ? (string) $row['profile'] : null;
		$plan->status      = (int) ( $row['status'] ?? 1 );
		$plan->created_at  = (string) ( $row['created_at'] ?? '' );
		$plan->updated_at  = (string) ( $row['updated_at'] ?? '' );
		return $plan;
	}

	/**
	 * Convert to a plain array for REST/JSON responses.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'id'          => $this->id,
			'name'        => $this->name,
			'description' => $this->description,
			'profile'     => $this->profile,
			'status'      => $this->status,
			'created_at'  => $this->created_at,
			'updated_at'  => $this->updated_at,
			'packages'    => array_map(
				static fn( Package $p ) => $p->to_array(),
				$this->packages
			),
		);
	}
}
