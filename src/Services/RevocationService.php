<?php
/**
 * Revocation service — license and device revocation, suspension, CRL.
 *
 * @package WPLM\Services
 */

namespace WPLM\Services;

use RuntimeException;
use WPLM\Repositories;
use WPLM\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Manages license and machine revocation, suspension, termination, blacklisting,
 * and CRL (certificate revocation list) generation.
 */
class RevocationService {

	/** @var Repositories\LicenseRepository */
	private Repositories\LicenseRepository $license_repo;

	/** @var Repositories\MachineRepository */
	private Repositories\MachineRepository $machine_repo;

	/** @var Repositories\BlacklistRepository */
	private Repositories\BlacklistRepository $blacklist_repo;

	/** @var Repositories\ActivationLogRepository */
	private Repositories\ActivationLogRepository $log_repo;

	/** @var Crypto\Signer */
	private Crypto\Signer $signer;

	/**
	 * @param Repositories\LicenseRepository       $license_repo
	 * @param Repositories\MachineRepository       $machine_repo
	 * @param Repositories\BlacklistRepository     $blacklist_repo
	 * @param Repositories\ActivationLogRepository $log_repo
	 * @param Crypto\Signer                        $signer
	 */
	public function __construct(
		Repositories\LicenseRepository $license_repo,
		Repositories\MachineRepository $machine_repo,
		Repositories\BlacklistRepository $blacklist_repo,
		Repositories\ActivationLogRepository $log_repo,
		Crypto\Signer $signer
	) {
		$this->license_repo   = $license_repo;
		$this->machine_repo   = $machine_repo;
		$this->blacklist_repo = $blacklist_repo;
		$this->log_repo       = $log_repo;
		$this->signer         = $signer;
	}

	// -------------------------------------------------------------------------
	// License status transitions
	// -------------------------------------------------------------------------

	/**
	 * Permanently revoke a license (status → 5).
	 *
	 * Not reversible through normal channels; use reinstate_license() to undo
	 * if the business process permits.
	 *
	 * @param int $id License row id.
	 * @return bool
	 * @throws RuntimeException If license not found or already terminated.
	 */
	public function revoke_license( int $id ): bool {
		$license = $this->license_repo->find_by_id( $id );

		if ( ! $license ) {
			throw new RuntimeException(
				sprintf( 'WPLM RevocationService: license %d not found.', $id )
			);
		}

		if ( 6 === $license->status ) {
			throw new RuntimeException(
				sprintf( 'WPLM RevocationService: license %d is terminated and cannot be modified.', $id )
			);
		}

		$old_status = $license->status;

		$this->license_repo->update( $id, array( 'status' => 5 ) );

		// Deactivate every currently-active device so the license cannot keep
		// being used by already-activated machines, and free their seats. The
		// license hash is also added to the CRL below for offline enforcement.
		$active_machines = $this->machine_repo->get_by_license( $id, 1 );
		foreach ( $active_machines as $machine ) {
			$this->machine_repo->deactivate( $machine->id );
		}
		$this->license_repo->update( $id, array( 'activation_count' => 0 ) );

		$this->log_repo->create(
			array(
				'license_id' => $id,
				'machine_id' => null,
				'event'      => 'revoke',
				'result'     => 'success',
				'meta'       => array(
					'old_status'          => $old_status,
					'new_status'          => 5,
					'devices_deactivated' => count( $active_machines ),
				),
			)
		);

		do_action( 'wplm_license_revoked', $license );
		do_action( 'wplm_license_status_changed', $license, $old_status, 5 );

		$this->build_crl();

		return true;
	}

	/**
	 * Suspend a license (status → 4).
	 *
	 * Temporary hold — all activations are denied while suspended. The license
	 * can be reinstated via reinstate_license().
	 *
	 * @param int $id License row id.
	 * @return bool
	 * @throws RuntimeException If license not found or terminated.
	 */
	public function suspend_license( int $id ): bool {
		$license = $this->license_repo->find_by_id( $id );

		if ( ! $license ) {
			throw new RuntimeException(
				sprintf( 'WPLM RevocationService: license %d not found.', $id )
			);
		}

		if ( 6 === $license->status ) {
			throw new RuntimeException(
				sprintf( 'WPLM RevocationService: license %d is terminated and cannot be modified.', $id )
			);
		}

		$old_status = $license->status;

		$this->license_repo->update( $id, array( 'status' => 4 ) );

		do_action( 'wplm_license_status_changed', $license, $old_status, 4 );

		return true;
	}

	/**
	 * Reinstate a previously suspended or revoked license (status → 2 inactive).
	 *
	 * @param int $id License row id.
	 * @return bool
	 * @throws RuntimeException If license not found, terminated, or not in a reinstate-able state.
	 */
	public function reinstate_license( int $id ): bool {
		$license = $this->license_repo->find_by_id( $id );

		if ( ! $license ) {
			throw new RuntimeException(
				sprintf( 'WPLM RevocationService: license %d not found.', $id )
			);
		}

		if ( 6 === $license->status ) {
			throw new RuntimeException(
				sprintf( 'WPLM RevocationService: license %d is terminated and cannot be reinstated.', $id )
			);
		}

		// Only revoked (5) or suspended (4) can be reinstated.
		if ( ! in_array( $license->status, array( 4, 5 ), true ) ) {
			throw new RuntimeException(
				sprintf(
					'WPLM RevocationService: license %d has status %d and is not eligible for reinstatement.',
					$id,
					$license->status
				)
			);
		}

		$old_status = $license->status;

		// Set to inactive (2) — the activation engine will promote it to active (1)
		// once a device activates successfully.
		$this->license_repo->update( $id, array( 'status' => 2 ) );

		do_action( 'wplm_license_status_changed', $license, $old_status, 2 );

		return true;
	}

	/**
	 * Permanently terminate a license (status → 6).
	 *
	 * This is an irreversible kill switch; no guard against the current status
	 * is applied beyond what the repository enforces.
	 *
	 * @param int $id License row id.
	 * @return bool
	 * @throws RuntimeException If license not found.
	 */
	public function terminate_license( int $id ): bool {
		$license = $this->license_repo->find_by_id( $id );

		if ( ! $license ) {
			throw new RuntimeException(
				sprintf( 'WPLM RevocationService: license %d not found.', $id )
			);
		}

		$this->license_repo->update( $id, array( 'status' => 6 ) );

		do_action( 'wplm_license_terminated', $license );

		return true;
	}

	// -------------------------------------------------------------------------
	// Device revocation
	// -------------------------------------------------------------------------

	/**
	 * Revoke a single machine / device (status → 3).
	 *
	 * Does not revoke the parent license; only blocks this specific fingerprint.
	 *
	 * @param int $machine_id Machine row id.
	 * @return bool
	 * @throws RuntimeException If machine not found.
	 */
	public function revoke_device( int $machine_id ): bool {
		$machine = $this->machine_repo->find_by_id( $machine_id );

		if ( ! $machine ) {
			throw new RuntimeException(
				sprintf( 'WPLM RevocationService: machine %d not found.', $machine_id )
			);
		}

		$this->machine_repo->revoke( $machine_id );

		$this->license_repo->decrement_activation_count( $machine->license_id );

		$this->log_repo->create(
			array(
				'license_id' => $machine->license_id,
				'machine_id' => $machine->id,
				'event'      => 'revoke',
				'result'     => 'success',
				'meta'       => array( 'scope' => 'device' ),
			)
		);

		do_action( 'wplm_machine_revoked', $machine );

		return true;
	}

	// -------------------------------------------------------------------------
	// Blacklist
	// -------------------------------------------------------------------------

	/**
	 * Add an entry to the blacklist (fingerprint, IP, or email).
	 *
	 * @param string $type   fingerprint | ip | email
	 * @param string $value  The value to blacklist.
	 * @param string $reason Human-readable reason (optional).
	 * @return bool True if the row was inserted successfully.
	 */
	public function blacklist_add( string $type, string $value, string $reason = '' ): bool {
		$id = $this->blacklist_repo->add( $type, $value, $reason );
		return $id > 0;
	}

	// -------------------------------------------------------------------------
	// Certificate Revocation List (CRL)
	// -------------------------------------------------------------------------

	/**
	 * Build a fresh, signed CRL and cache it as a transient.
	 *
	 * The CRL contains all revoked/terminated license hashes and all revoked
	 * machine fingerprints so clients can enforce revocation offline.
	 *
	 * @return string Signed CRL token (base64url(payload).base64url(sig)).
	 */
	public function build_crl(): string {
		global $wpdb;

		// Revoked + terminated license hashes.
		$licenses_table = $wpdb->prefix . 'wplm_licenses';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$license_rows = $wpdb->get_results(
			"SELECT `hash` FROM `{$licenses_table}` WHERE status IN (5, 6)",
			ARRAY_A
		);

		$revoked_keys = array();
		if ( is_array( $license_rows ) ) {
			foreach ( $license_rows as $row ) {
				if ( ! empty( $row['hash'] ) ) {
					$revoked_keys[] = $row['hash'];
				}
			}
		}

		// Revoked machine fingerprints.
		$machines_table = $wpdb->prefix . 'wplm_machines';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$machine_rows = $wpdb->get_results(
			"SELECT `fingerprint` FROM `{$machines_table}` WHERE status = 3",
			ARRAY_A
		);

		$revoked_fingerprints = array();
		if ( is_array( $machine_rows ) ) {
			foreach ( $machine_rows as $row ) {
				if ( ! empty( $row['fingerprint'] ) ) {
					$revoked_fingerprints[] = $row['fingerprint'];
				}
			}
		}

		$payload = array(
			'revoked_keys'         => $revoked_keys,
			'revoked_fingerprints' => $revoked_fingerprints,
			'generated_at'         => gmdate( 'c' ),
			'iat'                  => time(),
		);

		$signed_crl = $this->signer->sign( $payload );

		set_transient( 'wplm_crl', $signed_crl, HOUR_IN_SECONDS );

		return $signed_crl;
	}

	/**
	 * Return the cached CRL, building a fresh one if the transient has expired.
	 *
	 * @return string Signed CRL token.
	 */
	public function get_crl(): string {
		$cached = get_transient( 'wplm_crl' );

		if ( false !== $cached && is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		return $this->build_crl();
	}
}
