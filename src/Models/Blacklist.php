<?php
/**
 * Blacklist model.
 *
 * @package WPLM\Models
 */

namespace WPLM\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a blacklist entry from wplm_blacklist.
 */
class Blacklist {

	public int $id = 0;

	/**
	 * Entry type: fingerprint | ip | email
	 */
	public string $type       = 'ip';
	public string $value      = '';
	public ?string $reason    = null;
	public string $created_at = '';

	/**
	 * Create a Blacklist from a database row.
	 *
	 * @param array $row Raw DB row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$b             = new self();
		$b->id         = (int) ( $row['id'] ?? 0 );
		$b->type       = $row['type'] ?? 'ip';
		$b->value      = $row['value'] ?? '';
		$b->reason     = $row['reason'] ?? null;
		$b->created_at = $row['created_at'] ?? '';
		return $b;
	}

	/**
	 * Convert to a plain array for REST responses.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'id'         => $this->id,
			'type'       => $this->type,
			'value'      => $this->value,
			'reason'     => $this->reason,
			'created_at' => $this->created_at,
		);
	}
}
