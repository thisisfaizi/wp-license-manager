<?php
/**
 * Entitlement model — one line of what a profile licence grants.
 *
 * @package WPLM\Models
 */

namespace WPLM\Models;

defined( 'ABSPATH' ) || exit;

/**
 * A row in wplm_entitlements. A `module` line grants a module; a `limit` line adds `qty` to a
 * counted limit (users, seats, phones). `paid_through` is the inclusive last paid day (Y-m-d, site
 * time zone); null means lifetime.
 */
class Entitlement {

	public const KIND_MODULE = 'module';
	public const KIND_LIMIT  = 'limit';

	public const SOURCES = array( 'plan', 'addon', 'manual' );

	public int $id               = 0;
	public int $license_id       = 0;
	public string $kind          = self::KIND_MODULE;
	public string $code          = '';
	public int $qty              = 0;
	public ?string $paid_through = null;
	public string $source        = 'manual';
	public ?int $subscription_id = null;
	public string $created_at    = '';
	public string $updated_at    = '';

	/** Whether this line never lapses. */
	public function is_lifetime(): bool {
		return null === $this->paid_through;
	}

	/**
	 * Hydrate from a database row.
	 *
	 * @param array $row Raw DB row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$e                  = new self();
		$e->id              = (int) ( $row['id'] ?? 0 );
		$e->license_id      = (int) ( $row['license_id'] ?? 0 );
		$e->kind            = (string) ( $row['kind'] ?? self::KIND_MODULE );
		$e->code            = (string) ( $row['code'] ?? '' );
		$e->qty             = (int) ( $row['qty'] ?? 0 );
		$e->paid_through    = isset( $row['paid_through'] ) && '' !== $row['paid_through'] ? (string) $row['paid_through'] : null;
		$e->source          = (string) ( $row['source'] ?? 'manual' );
		$e->subscription_id = isset( $row['subscription_id'] ) && '' !== $row['subscription_id'] ? (int) $row['subscription_id'] : null;
		$e->created_at      = (string) ( $row['created_at'] ?? '' );
		$e->updated_at      = (string) ( $row['updated_at'] ?? '' );
		return $e;
	}

	/** Plain array for REST/JSON. */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'license_id'      => $this->license_id,
			'kind'            => $this->kind,
			'code'            => $this->code,
			'qty'             => $this->qty,
			'paid_through'    => $this->paid_through,
			'source'          => $this->source,
			'subscription_id' => $this->subscription_id,
			'created_at'      => $this->created_at,
			'updated_at'      => $this->updated_at,
		);
	}
}
