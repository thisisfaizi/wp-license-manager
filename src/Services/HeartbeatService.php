<?php
/**
 * Heartbeat service — machine lease renewal and zombie culling.
 *
 * @package WPLM\Services
 */

namespace WPLM\Services;

use WP_Error;
use WPLM\Models;
use WPLM\Repositories;
use WPLM\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Handles floating-license heartbeat pings and automatic deactivation of
 * stale / zombie machines that have stopped sending heartbeats.
 */
class HeartbeatService {

	/** @var Repositories\MachineRepository */
	private Repositories\MachineRepository $machine_repo;

	/** @var Repositories\LicenseRepository */
	private Repositories\LicenseRepository $license_repo;

	/** @var Repositories\ActivationLogRepository */
	private Repositories\ActivationLogRepository $log_repo;

	/** @var Crypto\Fingerprint */
	private Crypto\Fingerprint $fingerprint;

	/**
	 * @param Repositories\MachineRepository       $machine_repo
	 * @param Repositories\LicenseRepository       $license_repo
	 * @param Repositories\ActivationLogRepository $log_repo
	 * @param Crypto\Fingerprint                   $fingerprint
	 */
	public function __construct(
		Repositories\MachineRepository $machine_repo,
		Repositories\LicenseRepository $license_repo,
		Repositories\ActivationLogRepository $log_repo,
		Crypto\Fingerprint $fingerprint
	) {
		$this->machine_repo = $machine_repo;
		$this->license_repo = $license_repo;
		$this->log_repo     = $log_repo;
		$this->fingerprint  = $fingerprint;
	}

	// -------------------------------------------------------------------------
	// Heartbeat
	// -------------------------------------------------------------------------

	/**
	 * Record a heartbeat for an active floating-license device.
	 *
	 * Called by the REST controller after verifying the license key. The raw
	 * fingerprint is hashed here so storage is always in the HMAC-SHA256 form.
	 *
	 * @param string $raw_fingerprint Unhashed fingerprint from the client.
	 * @param int    $license_id      License row id (resolved by the caller from the key).
	 * @param array  $context         Optional: ip_address, app_version, etc.
	 * @return Models\Machine|WP_Error Refreshed Machine model on success, WP_Error on failure.
	 */
	public function record_heartbeat( string $raw_fingerprint, int $license_id, array $context = array() ): Models\Machine|WP_Error {
		// 1. Hash the raw fingerprint.
		$hashed_fp = $this->fingerprint->hash( $raw_fingerprint );

		// 2. Locate the machine.
		$machine = $this->machine_repo->find_by_license_and_fingerprint( $license_id, $hashed_fp );
		if ( ! $machine ) {
			return new WP_Error(
				'machine_not_found',
				__( 'No matching device activation found.', 'wp-license-manager' ),
				array( 'status' => 404 )
			);
		}

		// 3. Machine must be active.
		if ( ! $machine->is_active() ) {
			return new WP_Error(
				'machine_inactive',
				__( 'This device activation is not active.', 'wp-license-manager' ),
				array( 'status' => 403 )
			);
		}

		// 4. Compute new lease expiry for floating licenses.
		$new_lease_expires_at = null;
		if ( null !== $machine->lease_expires_at ) {
			$interval             = (int) apply_filters( 'wplm_heartbeat_interval', 600 );
			$new_lease_expires_at = gmdate( 'Y-m-d H:i:s', time() + $interval );
		}

		// 5. Persist the heartbeat (and new lease if applicable).
		$this->machine_repo->update_heartbeat( $machine->id, $new_lease_expires_at );

		// 6. Log the heartbeat.
		$this->log_repo->create(
			array(
				'license_id' => $license_id,
				'machine_id' => $machine->id,
				'event'      => 'heartbeat',
				'result'     => 'success',
				'ip_address' => $context['ip_address'] ?? null,
				'meta'       => array(
					'fingerprint' => $hashed_fp,
					'app_version' => $context['app_version'] ?? null,
					'new_lease'   => $new_lease_expires_at,
				),
			)
		);

		// 7. Fire action hook.
		do_action( 'wplm_heartbeat_received', $machine );

		// 8. Return a fresh copy of the machine so callers see the updated lease.
		$refreshed = $this->machine_repo->find_by_id( $machine->id );
		return $refreshed ?? $machine;
	}

	// -------------------------------------------------------------------------
	// Zombie culling
	// -------------------------------------------------------------------------

	/**
	 * Deactivate stale machines that have missed heartbeats beyond the cull window.
	 *
	 * Intended to be called from WP-Cron (registered via Cron\Scheduler).
	 * Only machines with a non-null lease_expires_at (i.e. floating licenses)
	 * and a non-null last_heartbeat_at are eligible — see MachineRepository::get_stale_machines().
	 *
	 * @return int Number of zombie machines culled.
	 */
	public function cull_zombies(): int {
		$window = (int) apply_filters( 'wplm_zombie_window', 1200 );
		$cutoff = time() - $window;

		$stale = $this->machine_repo->get_stale_machines( $cutoff );

		$culled = 0;

		foreach ( $stale as $m ) {
			$this->machine_repo->deactivate( $m->id );
			$this->license_repo->sync_activation_count( $m->license_id );

			$this->log_repo->create(
				array(
					'license_id' => $m->license_id,
					'machine_id' => $m->id,
					'event'      => 'heartbeat',
					'result'     => 'zombie_culled',
					'meta'       => array(
						'fingerprint'       => $m->fingerprint,
						'last_heartbeat_at' => $m->last_heartbeat_at,
						'cutoff_timestamp'  => $cutoff,
					),
				)
			);

			do_action( 'wplm_zombie_culled', $m );

			++$culled;
		}

		return $culled;
	}
}
