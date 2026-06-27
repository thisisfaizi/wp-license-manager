<?php
/**
 * Machine component model.
 *
 * @package WPLM\Models
 */

namespace WPLM\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a hardware component fingerprint from wplm_machine_components.
 * Each component belongs to a Machine and contributes to its overall fingerprint.
 */
class Component {

	public int $id             = 0;
	public int $machine_id     = 0;
	public string $fingerprint = '';

	/**
	 * Component kind: cpu | motherboard | disk | mac | gpu | custom
	 */
	public string $kind       = 'custom';
	public ?string $name      = null;
	public string $created_at = '';

	/**
	 * Create a Component from a database row.
	 *
	 * @param array $row Raw DB row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$c              = new self();
		$c->id          = (int) ( $row['id'] ?? 0 );
		$c->machine_id  = (int) ( $row['machine_id'] ?? 0 );
		$c->fingerprint = $row['fingerprint'] ?? '';
		$c->kind        = $row['kind'] ?? 'custom';
		$c->name        = $row['name'] ?? null;
		$c->created_at  = $row['created_at'] ?? '';
		return $c;
	}

	/**
	 * Convert to a plain array for REST responses.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'id'          => $this->id,
			'machine_id'  => $this->machine_id,
			'fingerprint' => $this->fingerprint,
			'kind'        => $this->kind,
			'name'        => $this->name,
			'created_at'  => $this->created_at,
		);
	}
}
