<?php
/**
 * Machine (device activation) model.
 *
 * @package WPLM\Models
 */

namespace WPLM\Models;

defined( 'ABSPATH' ) || exit;

/** Represents a single device activation record from wplm_machines. */
class Machine {

	public int $id                    = 0;
	public int $license_id            = 0;
	public string $fingerprint        = '';
	public ?string $name              = null;
	public ?string $hostname          = null;
	public ?string $ip_address        = null;
	public ?string $platform          = null;
	public ?string $app_version       = null;
	public ?string $lease_expires_at  = null;
	public ?string $last_heartbeat_at = null;

	/** 1 active | 2 deactivated | 3 revoked */
	public int $status = 1;

	public string $activated_at = '';
	public string $created_at   = '';

	/** The client's own fingerprint value signed into v2 tokens (profile licences only). */
	public ?string $token_fp = null;

	/** @var array<string, int>|null Latest usage counts reported at check-in (profile licences only). */
	public ?array $usage = null;

	/** @var Component[] */
	public array $components = array();

	public function is_active(): bool {
		return 1 === $this->status;
	}

	public function has_expired_lease(): bool {
		if ( null === $this->lease_expires_at ) {
			return false;
		}
		return strtotime( $this->lease_expires_at ) < time();
	}

	public static function from_row( array $row ): self {
		$m                    = new self();
		$m->id                = (int) ( $row['id'] ?? 0 );
		$m->license_id        = (int) ( $row['license_id'] ?? 0 );
		$m->fingerprint       = $row['fingerprint'] ?? '';
		$m->name              = $row['name'] ?? null;
		$m->hostname          = $row['hostname'] ?? null;
		$m->ip_address        = $row['ip_address'] ?? null;
		$m->platform          = $row['platform'] ?? null;
		$m->app_version       = $row['app_version'] ?? null;
		$m->lease_expires_at  = $row['lease_expires_at'] ?? null;
		$m->last_heartbeat_at = $row['last_heartbeat_at'] ?? null;
		$m->status            = (int) ( $row['status'] ?? 1 );
		$m->activated_at      = $row['activated_at'] ?? '';
		$m->created_at        = $row['created_at'] ?? '';
		$m->token_fp          = isset( $row['token_fp'] ) && '' !== $row['token_fp'] ? (string) $row['token_fp'] : null;
		$usage                = isset( $row['usage_json'] ) ? json_decode( (string) $row['usage_json'], true ) : null;
		$m->usage             = is_array( $usage ) ? $usage : null;
		return $m;
	}

	public function to_array(): array {
		return array(
			'id'                => $this->id,
			'license_id'        => $this->license_id,
			'fingerprint'       => $this->fingerprint,
			'name'              => $this->name,
			'hostname'          => $this->hostname,
			'platform'          => $this->platform,
			'app_version'       => $this->app_version,
			'lease_expires_at'  => $this->lease_expires_at,
			'last_heartbeat_at' => $this->last_heartbeat_at,
			'status'            => $this->status,
			'activated_at'      => $this->activated_at,
			'usage'             => $this->usage,
		);
	}
}
