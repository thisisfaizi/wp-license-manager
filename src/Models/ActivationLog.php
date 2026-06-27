<?php
/**
 * Activation log model.
 *
 * @package WPLM\Models
 */

namespace WPLM\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a single activation log entry from wplm_activation_log.
 */
class ActivationLog {

	public int $id          = 0;
	public int $license_id  = 0;
	public ?int $machine_id = null;

	/**
	 * Event type: validate | activate | deactivate | heartbeat | revoke | deny
	 */
	public string $event = 'validate';

	/**
	 * Outcome: success | fail | limit_exceeded | expired | revoked | blacklisted
	 */
	public string $result = 'success';

	public ?string $ip_address = null;
	public ?string $country    = null;

	/** Decoded JSON meta payload. */
	public array $meta        = array();
	public string $created_at = '';

	/**
	 * Create an ActivationLog from a database row.
	 * JSON-decodes the `meta` column safely.
	 *
	 * @param array $row Raw DB row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$log             = new self();
		$log->id         = (int) ( $row['id'] ?? 0 );
		$log->license_id = (int) ( $row['license_id'] ?? 0 );
		$log->machine_id = isset( $row['machine_id'] ) ? (int) $row['machine_id'] : null;
		$log->event      = $row['event'] ?? 'validate';
		$log->result     = $row['result'] ?? 'success';
		$log->ip_address = $row['ip_address'] ?? null;
		$log->country    = $row['country'] ?? null;
		$log->meta       = json_decode( $row['meta'] ?? '[]', true ) ?? array();
		$log->created_at = $row['created_at'] ?? '';
		return $log;
	}

	/**
	 * Convert to a plain array for REST responses.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'id'         => $this->id,
			'license_id' => $this->license_id,
			'machine_id' => $this->machine_id,
			'event'      => $this->event,
			'result'     => $this->result,
			'ip_address' => $this->ip_address,
			'country'    => $this->country,
			'meta'       => $this->meta,
			'created_at' => $this->created_at,
		);
	}
}
