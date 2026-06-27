<?php
/**
 * API Key model.
 *
 * @package WPLM\Models
 */

namespace WPLM\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a single API key record from wplm_api_keys.
 */
class ApiKey {

	public int $id              = 0;
	public int $user_id         = 0;
	public ?string $description = null;

	/**
	 * Permission level: read | write | read_write
	 */
	public string $permissions = 'read';

	/** Hashed consumer key stored in the database. */
	public string $consumer_key = '';

	/** Hashed consumer secret stored in the database. */
	public string $consumer_secret = '';

	/** Last 7 characters of the plaintext key for display. */
	public string $truncated_key = '';

	public ?string $last_access_at = null;
	public string $created_at      = '';

	/**
	 * Whether this key grants write access.
	 *
	 * @return bool
	 */
	public function has_write_permission(): bool {
		return in_array( $this->permissions, array( 'write', 'read_write' ), true );
	}

	/**
	 * Whether this key grants read access.
	 *
	 * @return bool
	 */
	public function has_read_permission(): bool {
		return in_array( $this->permissions, array( 'read', 'read_write' ), true );
	}

	/**
	 * Create an ApiKey from a database row.
	 *
	 * @param array $row Raw DB row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$k                  = new self();
		$k->id              = (int) ( $row['id'] ?? 0 );
		$k->user_id         = (int) ( $row['user_id'] ?? 0 );
		$k->description     = $row['description'] ?? null;
		$k->permissions     = $row['permissions'] ?? 'read';
		$k->consumer_key    = $row['consumer_key'] ?? '';
		$k->consumer_secret = $row['consumer_secret'] ?? '';
		$k->truncated_key   = $row['truncated_key'] ?? '';
		$k->last_access_at  = $row['last_access_at'] ?? null;
		$k->created_at      = $row['created_at'] ?? '';
		return $k;
	}

	/**
	 * Convert to a plain array for REST responses (excludes hashed secrets).
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'id'             => $this->id,
			'user_id'        => $this->user_id,
			'description'    => $this->description,
			'permissions'    => $this->permissions,
			'truncated_key'  => $this->truncated_key,
			'last_access_at' => $this->last_access_at,
			'created_at'     => $this->created_at,
		);
	}
}
