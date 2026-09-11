<?php
/**
 * Activation service — machine activate / deactivate logic (Section 6.1).
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
 * Handles device activation and deactivation against a license.
 *
 * All seat accounting (activation_count) is delegated to the repository
 * layer, which uses atomic SQL so concurrent requests cannot double-count.
 */
class ActivationService {

	/** @var Repositories\LicenseRepository */
	private Repositories\LicenseRepository $license_repo;

	/** @var Repositories\MachineRepository */
	private Repositories\MachineRepository $machine_repo;

	/** @var Repositories\ActivationLogRepository */
	private Repositories\ActivationLogRepository $log_repo;

	/** @var Repositories\BlacklistRepository */
	private Repositories\BlacklistRepository $blacklist_repo;

	/** @var Crypto\Fingerprint */
	private Crypto\Fingerprint $fingerprint;

	/** @var Crypto\Signer */
	private Crypto\Signer $signer;

	/**
	 * @param Repositories\LicenseRepository       $license_repo
	 * @param Repositories\MachineRepository       $machine_repo
	 * @param Repositories\ActivationLogRepository $log_repo
	 * @param Repositories\BlacklistRepository     $blacklist_repo
	 * @param Crypto\Fingerprint                   $fingerprint
	 * @param Crypto\Signer                        $signer
	 */
	public function __construct(
		Repositories\LicenseRepository $license_repo,
		Repositories\MachineRepository $machine_repo,
		Repositories\ActivationLogRepository $log_repo,
		Repositories\BlacklistRepository $blacklist_repo,
		Crypto\Fingerprint $fingerprint,
		Crypto\Signer $signer
	) {
		$this->license_repo   = $license_repo;
		$this->machine_repo   = $machine_repo;
		$this->log_repo       = $log_repo;
		$this->blacklist_repo = $blacklist_repo;
		$this->fingerprint    = $fingerprint;
		$this->signer         = $signer;
	}

	// -------------------------------------------------------------------------
	// Activate
	// -------------------------------------------------------------------------

	/**
	 * Activate a device against a license key.
	 *
	 * Implements Section 6.1 of the build specification.
	 *
	 * @param string $key_string      Plaintext license key supplied by the client.
	 * @param string $raw_fingerprint Raw (unhashed) device fingerprint from the client.
	 * @param array  $meta            Optional metadata: name, hostname, platform, app_version,
	 *                                ip_address, components (array).
	 * @return Models\Machine|WP_Error Machine model on success, WP_Error on failure.
	 */
	public function activate( string $key_string, string $raw_fingerprint, array $meta = array() ): Models\Machine|WP_Error {
		global $wpdb;

		// 1. Resolve license by plaintext key.
		$license = $this->license_repo->find_by_key( $key_string );
		if ( ! $license ) {
			return new WP_Error(
				'license_not_found',
				__( 'License key not found.', 'wp-license-manager' ),
				array( 'status' => 404 )
			);
		}

		// 2. Status check — must be active (1) or inactive (2).
		$status_error = $this->check_license_status( $license );
		if ( is_wp_error( $status_error ) ) {
			return $status_error;
		}

		// 3. Expiry check.
		if ( ! $license->is_within_expiry() ) {
			return new WP_Error(
				'license_expired',
				__( 'This license has expired.', 'wp-license-manager' ),
				array( 'status' => 410 )
			);
		}

		// 4. Hash the raw fingerprint for storage/lookup.
		$fp = $this->fingerprint->hash( $raw_fingerprint );

		// 5. Blacklist check — fingerprint.
		if ( $this->blacklist_repo->is_blacklisted( 'fingerprint', $fp ) ) {
			return new WP_Error(
				'blacklisted',
				__( 'This device fingerprint is blacklisted.', 'wp-license-manager' ),
				array( 'status' => 403 )
			);
		}

		// 6. Blacklist check — IP address.
		$ip_address = $meta['ip_address'] ?? '';
		if ( $ip_address && $this->blacklist_repo->is_blacklisted( 'ip', $ip_address ) ) {
			return new WP_Error(
				'blacklisted',
				__( 'This IP address is blacklisted.', 'wp-license-manager' ),
				array( 'status' => 403 )
			);
		}

		// 7. Existing machine for this license+fingerprint — idempotent reactivate.
		$existing = $this->machine_repo->find_by_license_and_fingerprint( $license->id, $fp );
		if ( $existing ) {
			// 7a. Already active — idempotent, consumes no new seat. Reconcile the
			// license: an active device must mean an active license with a seat
			// count that matches reality. This self-heals any earlier drift (e.g.
			// a license left inactive / count 0 after an interrupted deactivate),
			// so the app's "activate" always lands the license in the right state.
			if ( $existing->is_active() ) {
				$count = $this->license_repo->sync_activation_count( $license->id );
				if ( $count > 0 && 1 !== $license->status && 6 !== $license->status ) {
					$this->license_repo->update( $license->id, array( 'status' => 1 ) );
				}
				$this->log_repo->create(
					array(
						'license_id' => $license->id,
						'machine_id' => $existing->id,
						'event'      => 'activate',
						'result'     => 'success',
						'ip_address' => $ip_address,
						'meta'       => array( 'idempotent' => true ),
					)
				);
				return $existing;
			}

			// 7b. Device was explicitly revoked (status 3) — stays denied.
			if ( 3 === $existing->status ) {
				$this->log_repo->create(
					array(
						'license_id' => $license->id,
						'machine_id' => $existing->id,
						'event'      => 'activate',
						'result'     => 'denied',
						'ip_address' => $ip_address,
						'meta'       => array( 'reason' => 'machine_revoked' ),
					)
				);
				return new WP_Error(
					'machine_revoked',
					__( 'This device has been revoked for this license.', 'wp-license-manager' ),
					array( 'status' => 403 )
				);
			}

			// 7c. Deactivated (status 2) — reactivate the SAME row instead of
			// inserting a duplicate. This is the safety net for when a device's
			// fingerprint is stable but its local activation state was lost
			// (e.g. the app data was cleared). Subject to the seat ceiling.
			if ( null !== $license->max_activations
				&& $license->activation_count >= $this->compute_ceiling( $license ) ) {
				$this->log_repo->create(
					array(
						'license_id' => $license->id,
						'machine_id' => $existing->id,
						'event'      => 'activate',
						'result'     => 'limit_exceeded',
						'ip_address' => $ip_address,
						'meta'       => array( 'reactivate' => true ),
					)
				);
				return new WP_Error(
					'machine_limit_exceeded',
					__( 'Maximum device activations reached for this license.', 'wp-license-manager' ),
					array( 'status' => 422 )
				);
			}

			$this->machine_repo->update(
				$existing->id,
				array(
					'status'      => 1,
					'name'        => $meta['name'] ?? $existing->name,
					'hostname'    => $meta['hostname'] ?? $existing->hostname,
					'platform'    => $meta['platform'] ?? $existing->platform,
					'app_version' => $meta['app_version'] ?? $existing->app_version,
				)
			);
			$this->license_repo->sync_activation_count( $license->id );

			// If the license was set inactive (status 2) when its last device was
			// deactivated, bring it back to active now that a device is bound
			// again — mirroring the fresh-activation path (step 13 below).
			if ( 2 === $license->status ) {
				$this->license_repo->update( $license->id, array( 'status' => 1 ) );
			}

			$reactivated = $this->machine_repo->find_by_id( $existing->id );
			$this->log_repo->create(
				array(
					'license_id' => $license->id,
					'machine_id' => $existing->id,
					'event'      => 'activate',
					'result'     => 'success',
					'ip_address' => $ip_address,
					'meta'       => array( 'reactivated' => true ),
				)
			);
			do_action( 'wplm_machine_activated', $reactivated, $license );
			return $reactivated;
		}

		// 8. Seat ceiling — based on overage_strategy.
		if ( null !== $license->max_activations ) {
			$ceiling = $this->compute_ceiling( $license );

			// 9. Seat limit exceeded.
			if ( $license->activation_count >= $ceiling ) {
				$this->log_repo->create(
					array(
						'license_id' => $license->id,
						'machine_id' => null,
						'event'      => 'activate',
						'result'     => 'limit_exceeded',
						'ip_address' => $ip_address,
						'meta'       => array(
							'activation_count' => $license->activation_count,
							'ceiling'          => $ceiling,
						),
					)
				);
				return new WP_Error(
					'machine_limit_exceeded',
					__( 'Maximum device activations reached for this license.', 'wp-license-manager' ),
					array( 'status' => 422 )
				);
			}
		}

		// 10. Build machine row data.
		$lease_expires_at = null;
		// Never for a profile licence, even one saved floating before that was refused: it checks in with its
		// own deadline, and a lease would put its machine in the zombie cull.
		if ( $license->is_floating && null === $license->profile ) {
			$interval         = (int) apply_filters( 'wplm_heartbeat_interval', 600 );
			$lease_expires_at = gmdate( 'Y-m-d H:i:s', time() + $interval );
		}

		$machine_data = array(
			'license_id'       => $license->id,
			'fingerprint'      => $fp,
			'status'           => 1,
			'name'             => $meta['name'] ?? null,
			'hostname'         => $meta['hostname'] ?? null,
			'platform'         => $meta['platform'] ?? null,
			'app_version'      => $meta['app_version'] ?? null,
			'ip_address'       => $ip_address ?: null,
			'lease_expires_at' => $lease_expires_at,
		);

		$machine_id = $this->machine_repo->create( $machine_data );
		$machine    = $this->machine_repo->find_by_id( $machine_id );

		// 11. Recompute seat count from active machines (drift-proof).
		$this->license_repo->sync_activation_count( $license->id );

		// 12. First activation — set activated_at and potentially expires_at.
		if ( null === $license->activated_at ) {
			$first_activation_data = array(
				'activated_at' => current_time( 'mysql' ),
			);

			if ( null !== $license->valid_for_days ) {
				$first_activation_data['expires_at'] = gmdate(
					'Y-m-d H:i:s',
					strtotime( "+{$license->valid_for_days} days" )
				);
			}

			$this->license_repo->update( $license->id, $first_activation_data );
		}

		// 13. If license was inactive (status=2), set active (status=1).
		if ( 2 === $license->status ) {
			$this->license_repo->update( $license->id, array( 'status' => 1 ) );
		}

		// 14. Handle components if provided.
		if ( ! empty( $meta['components'] ) && is_array( $meta['components'] ) ) {
			$components_table = $wpdb->prefix . 'wplm_machine_components';
			foreach ( $meta['components'] as $component ) {
				if ( ! is_array( $component ) ) {
					continue;
				}
				$wpdb->insert(
					$components_table,
					array(
						'machine_id' => $machine_id,
						'type'       => sanitize_text_field( $component['type'] ?? '' ),
						'value'      => sanitize_text_field( $component['value'] ?? '' ),
						'created_at' => current_time( 'mysql' ),
					),
					array( '%d', '%s', '%s', '%s' )
				);
			}
		}

		// 15. Log the activation.
		$this->log_repo->create(
			array(
				'license_id' => $license->id,
				'machine_id' => $machine->id,
				'event'      => 'activate',
				'result'     => 'success',
				'ip_address' => $ip_address,
				'meta'       => array(
					'fingerprint' => $fp,
					'hostname'    => $meta['hostname'] ?? null,
					'platform'    => $meta['platform'] ?? null,
				),
			)
		);

		// 16. Fire action hook.
		do_action( 'wplm_machine_activated', $machine, $license );

		// 17. Return the Machine model.
		return $machine;
	}

	// -------------------------------------------------------------------------
	// Deactivate (by fingerprint)
	// -------------------------------------------------------------------------

	/**
	 * Deactivate a device identified by its fingerprint for a given license key.
	 *
	 * @param string $key_string      Plaintext license key.
	 * @param string $raw_fingerprint Raw device fingerprint from the client.
	 * @return bool|WP_Error True on success (or already deactivated), WP_Error on failure.
	 */
	public function deactivate( string $key_string, string $raw_fingerprint ): bool|WP_Error {
		// 1. Resolve license.
		$license = $this->license_repo->find_by_key( $key_string );
		if ( ! $license ) {
			return new WP_Error(
				'license_not_found',
				__( 'License key not found.', 'wp-license-manager' ),
				array( 'status' => 404 )
			);
		}

		// 2. Hash fingerprint.
		$fp = $this->fingerprint->hash( $raw_fingerprint );

		// 3. Find machine.
		$machine = $this->machine_repo->find_by_license_and_fingerprint( $license->id, $fp );
		if ( ! $machine ) {
			return new WP_Error(
				'machine_not_found',
				__( 'No matching device activation found for this license.', 'wp-license-manager' ),
				array( 'status' => 404 )
			);
		}

		// 4. Idempotency — already deactivated.
		if ( ! $machine->is_active() ) {
			return true;
		}

		// 5. Deactivate the machine row.
		$this->machine_repo->deactivate( $machine->id );

		// 6. Recompute seat count from the remaining active machines (drift-proof).
		$remaining = $this->license_repo->sync_activation_count( $license->id );

		// 7. If no active devices remain and the license is active, mark inactive.
		if ( $remaining <= 0 && 1 === $license->status ) {
			$this->license_repo->update( $license->id, array( 'status' => 2 ) );
		}

		// 8. Log the deactivation.
		$this->log_repo->create(
			array(
				'license_id' => $license->id,
				'machine_id' => $machine->id,
				'event'      => 'deactivate',
				'result'     => 'success',
				'meta'       => array( 'fingerprint' => $fp ),
			)
		);

		// 9. Fire action hook.
		do_action( 'wplm_machine_deactivated', $machine, $license );

		return true;
	}

	// -------------------------------------------------------------------------
	// Deactivate (by machine ID)
	// -------------------------------------------------------------------------

	/**
	 * Deactivate a device directly by its machine row id.
	 * Used by admin UI and internal tooling.
	 *
	 * @param int    $machine_id Machine row id.
	 * @param string $source     Who asked, for the log (e.g. admin_by_machine_id, public_by_machine_id).
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function deactivate_by_machine_id( int $machine_id, string $source = 'admin_by_machine_id' ): bool|WP_Error {
		// 1. Load the machine.
		$machine = $this->machine_repo->find_by_id( $machine_id );
		if ( ! $machine ) {
			return new WP_Error(
				'machine_not_found',
				__( 'Machine not found.', 'wp-license-manager' ),
				array( 'status' => 404 )
			);
		}

		// 2. Load the associated license.
		$license = $this->license_repo->find_by_id( $machine->license_id );

		// Idempotent: deactivating an inactive device changes nothing and logs nothing.
		if ( ! $machine->is_active() ) {
			return true;
		}

		// 3. Deactivate the machine row.
		$this->machine_repo->deactivate( $machine_id );

		// 4. Recompute the seat count from active machines (drift-proof, like deactivate()).
		$this->license_repo->sync_activation_count( $machine->license_id );

		// 5. Log and fire action.
		$this->log_repo->create(
			array(
				'license_id' => $machine->license_id,
				'machine_id' => $machine->id,
				'event'      => 'deactivate',
				'result'     => 'success',
				'meta'       => array( 'source' => $source ),
			)
		);

		do_action( 'wplm_machine_deactivated', $machine, $license );

		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Return a WP_Error if the license status blocks new activations, else null.
	 *
	 * @param Models\License $license
	 * @return WP_Error|null
	 */
	private function check_license_status( Models\License $license ): ?WP_Error {
		switch ( $license->status ) {
			case 1: // active
			case 2: // inactive
				return null;

			case 4: // suspended
				return new WP_Error(
					'license_suspended',
					__( 'This license is suspended.', 'wp-license-manager' ),
					array( 'status' => 423 )
				);

			case 5: // revoked
				return new WP_Error(
					'license_revoked',
					__( 'This license has been revoked.', 'wp-license-manager' ),
					array( 'status' => 403 )
				);

			case 6: // terminated
				return new WP_Error(
					'license_terminated',
					__( 'This license has been permanently terminated.', 'wp-license-manager' ),
					array( 'status' => 403 )
				);

			case 3: // expired
				return new WP_Error(
					'license_expired',
					__( 'This license has expired.', 'wp-license-manager' ),
					array( 'status' => 410 )
				);

			case 0: // pending
			default:
				return new WP_Error(
					'license_not_active',
					__( 'This license is not yet active.', 'wp-license-manager' ),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Compute the effective seat ceiling based on the overage strategy.
	 *
	 * @param Models\License $license
	 * @return int
	 */
	private function compute_ceiling( Models\License $license ): int {
		$max = (int) $license->max_activations;

		switch ( $license->overage_strategy ) {
			case 'allow_1_25x':
				return (int) ceil( $max * 1.25 );

			case 'allow_2x':
				return $max * 2;

			case 'deny':
			default:
				return $max;
		}
	}
}
