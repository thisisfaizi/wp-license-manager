<?php
/**
 * Release model.
 *
 * @package WPLM\Models
 */

namespace WPLM\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a software release from wplm_releases.
 */
class Release {

	public int $id          = 0;
	public ?int $product_id = null;
	public string $version  = '';

	/**
	 * Release channel: stable | beta | rc
	 */
	public string $channel = 'stable';

	public ?string $changelog       = null;
	public ?string $file_path       = null;
	public ?string $file_hash       = null;
	public ?string $min_app_version = null;
	public string $released_at      = '';

	/**
	 * Create a Release from a database row.
	 *
	 * @param array $row Raw DB row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$r                  = new self();
		$r->id              = (int) ( $row['id'] ?? 0 );
		$r->product_id      = isset( $row['product_id'] ) ? (int) $row['product_id'] : null;
		$r->version         = $row['version'] ?? '';
		$r->channel         = $row['channel'] ?? 'stable';
		$r->changelog       = $row['changelog'] ?? null;
		$r->file_path       = $row['file_path'] ?? null;
		$r->file_hash       = $row['file_hash'] ?? null;
		$r->min_app_version = $row['min_app_version'] ?? null;
		$r->released_at     = $row['released_at'] ?? '';
		return $r;
	}

	/**
	 * Convert to a plain array for REST responses.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'product_id'      => $this->product_id,
			'version'         => $this->version,
			'channel'         => $this->channel,
			'changelog'       => $this->changelog,
			'file_hash'       => $this->file_hash,
			'min_app_version' => $this->min_app_version,
			'released_at'     => $this->released_at,
		);
	}
}
