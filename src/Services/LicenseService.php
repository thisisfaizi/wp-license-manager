<?php
/**
 * License service — issue, validate, renew, and manage license lifecycle.
 *
 * @package WPLM\Services
 */

namespace WPLM\Services;

use WPLM\Crypto\Fingerprint;
use WPLM\Crypto\KeyVault;
use WPLM\Crypto\Signer;
use WPLM\Models\License;
use WPLM\Repositories\ActivationLogRepository;
use WPLM\Repositories\BlacklistRepository;
use WPLM\Repositories\LicenseRepository;
use WPLM\Repositories\MachineRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates license issuance, validation (Section 5.3), renewal, status
 * transitions, and audit logging. All crypto exceptions propagate upward so
 * REST controllers can translate them into appropriate HTTP responses.
 */
class LicenseService {

	/** @var LicenseRepository */
	private LicenseRepository $license_repo;

	/** @var Signer */
	private Signer $signer;

	/** @var KeyVault */
	private KeyVault $vault;

	/** @var ActivationLogRepository */
	private ActivationLogRepository $log_repo;

	/** @var BlacklistRepository */
	private BlacklistRepository $blacklist_repo;

	/** @var MachineRepository */
	private MachineRepository $machine_repo;

	/** @var Fingerprint */
	private Fingerprint $fingerprint;

	/**
	 * @param LicenseRepository       $license_repo   License data-access layer.
	 * @param Signer                  $signer         Ed25519 signing service.
	 * @param KeyVault                $vault          AES-256-GCM encryption vault.
	 * @param ActivationLogRepository $log_repo       Activation event log.
	 * @param BlacklistRepository     $blacklist_repo Fingerprint/IP blacklist.
	 * @param MachineRepository       $machine_repo   Device/machine data-access layer.
	 * @param Fingerprint             $fingerprint    Fingerprint hashing service.
	 */
	public function __construct(
		LicenseRepository $license_repo,
		Signer $signer,
		KeyVault $vault,
		ActivationLogRepository $log_repo,
		BlacklistRepository $blacklist_repo,
		MachineRepository $machine_repo,
		Fingerprint $fingerprint
	) {
		$this->license_repo   = $license_repo;
		$this->signer         = $signer;
		$this->vault          = $vault;
		$this->log_repo       = $log_repo;
		$this->blacklist_repo = $blacklist_repo;
		$this->machine_repo   = $machine_repo;
		$this->fingerprint    = $fingerprint;
	}

	// -------------------------------------------------------------------------
	// Issuance
	// -------------------------------------------------------------------------

	/**
	 * Create a new license.
	 *
	 * The caller (GeneratorService batch, REST controller, WooCommerce handler)
	 * MUST supply key_string — this service does not generate it.
	 *
	 * Accepted $args keys:
	 *   key_string       string   (required)
	 *   product_id       int|null
	 *   order_id         int|null
	 *   user_id          int|null
	 *   max_activations  int|null
	 *   valid_for_days   int|null
	 *   expires_at       string|null  MySQL datetime
	 *   grace_days       int
	 *   overage_strategy string       deny|allow_1_25x|allow_2x
	 *   source           int          0 import|1 generator|2 api|3 woocommerce
	 *   is_floating      bool
	 *   created_by       int|null
	 *
	 * @param array $args License field values.
	 * @return License The newly created, fully hydrated license model.
	 * @throws \InvalidArgumentException When key_string is not supplied.
	 * @throws \RuntimeException         On crypto failure or DB insertion error.
	 */
	public function create( array $args ): License {
		$key_string = $args['key_string'] ?? '';

		if ( '' === $key_string ) {
			throw new \InvalidArgumentException( 'WPLM LicenseService::create(): key_string is required.' );
		}

		// Derive hash and encrypt the plaintext key.
		$hash      = Fingerprint::sha256( $key_string );
		$encrypted = $this->vault->encrypt( $key_string );

		// Build the signed payload for Ed25519 signing.
		$signature = $this->signer->sign(
			$this->build_signing_payload(
				$key_string,
				$args['expires_at'] ?? null,
				isset( $args['max_activations'] ) ? (int) $args['max_activations'] : null,
				isset( $args['product_id'] ) ? (int) $args['product_id'] : null
			)
		);

		// Assemble the insert row; status 2 = inactive (active on first activation).
		$insert_data = array(
			'license_key'      => $encrypted,
			'hash'             => $hash,
			'signature'        => $signature,
			'status'           => 2,
			'product_id'       => isset( $args['product_id'] ) ? (int) $args['product_id'] : null,
			'order_id'         => isset( $args['order_id'] ) ? (int) $args['order_id'] : null,
			'user_id'          => isset( $args['user_id'] ) ? (int) $args['user_id'] : null,
			'max_activations'  => isset( $args['max_activations'] ) ? (int) $args['max_activations'] : null,
			'valid_for_days'   => isset( $args['valid_for_days'] ) ? (int) $args['valid_for_days'] : null,
			'expires_at'       => $args['expires_at'] ?? null,
			'grace_days'       => (int) ( $args['grace_days'] ?? 0 ),
			'overage_strategy' => $args['overage_strategy'] ?? 'deny',
			'source'           => isset( $args['source'] ) ? (int) $args['source'] : 2,
			'is_floating'      => ! empty( $args['is_floating'] ) ? 1 : 0,
			'created_by'       => isset( $args['created_by'] ) ? (int) $args['created_by'] : null,
		);

		$id      = $this->license_repo->create( $insert_data );
		$license = $this->license_repo->find_by_id( $id );

		if ( null === $license ) {
			throw new \RuntimeException(
				sprintf( 'WPLM LicenseService: could not reload license after INSERT (id %d).', $id )
			);
		}

		/**
		 * Fires after a new license has been created and persisted.
		 *
		 * @param License $license The fully hydrated license model.
		 */
		do_action( 'wplm_license_created', $license );

		return $license;
	}

	// -------------------------------------------------------------------------
	// Retrieval
	// -------------------------------------------------------------------------

	/**
	 * Find a license by its plaintext key string (looks up by hash internally).
	 *
	 * @param string $key_string Plaintext license key.
	 * @return License|null
	 */
	public function get_by_key( string $key_string ): ?License {
		return $this->license_repo->find_by_key( $key_string );
	}

	/**
	 * Find a license by its primary key.
	 *
	 * @param int $id Row id.
	 * @return License|null
	 */
	public function get_by_id( int $id ): ?License {
		return $this->license_repo->find_by_id( $id );
	}

	/**
	 * Return a paginated, filtered list of licenses.
	 *
	 * @param array $args Filter/pagination arguments (see LicenseRepository::get_list()).
	 * @return array{ items: License[], total: int }
	 */
	public function get_list( array $args = array() ): array {
		return $this->license_repo->get_list( $args );
	}

	// -------------------------------------------------------------------------
	// Validation (Section 5.3)
	// -------------------------------------------------------------------------

	/**
	 * Validate a license key against the full Section 5.3 algorithm.
	 *
	 * @param string      $key_string  Plaintext license key from the client.
	 * @param string|null $fingerprint HMAC-SHA256 device fingerprint (optional).
	 * @param array       $context     Caller context: ip, country, meta, etc.
	 * @return array Validation response. Always contains 'valid' (bool) and 'code' on failure.
	 */
	public function validate( string $key_string, ?string $fingerprint = null, array $context = array() ): array {
		// Step 1: Look up license by hash.
		$license = $this->license_repo->find_by_key( $key_string );

		if ( null === $license ) {
			$this->log_event( 0, null, 'validate', 'fail', array_merge( $context, array( 'meta' => array( 'code' => 'license_not_found' ) ) ) );

			return array(
				'valid' => false,
				'code'  => 'license_not_found',
			);
		}

		// Step 2: Blacklist checks — fingerprint and IP.
		if ( null !== $fingerprint && '' !== $fingerprint ) {
			if ( $this->blacklist_repo->is_blacklisted( 'fingerprint', $fingerprint ) ) {
				$this->log_event(
					$license->id,
					null,
					'validate',
					'fail',
					array_merge(
						$context,
						array(
							'meta' => array(
								'code' => 'blacklisted',
								'type' => 'fingerprint',
							),
						)
					)
				);

				return array(
					'valid' => false,
					'code'  => 'blacklisted',
				);
			}
		}

		$request_ip = $context['ip'] ?? '';
		if ( '' !== $request_ip && $this->blacklist_repo->is_blacklisted( 'ip', $request_ip ) ) {
			$this->log_event(
				$license->id,
				null,
				'validate',
				'fail',
				array_merge(
					$context,
					array(
						'meta' => array(
							'code' => 'blacklisted',
							'type' => 'ip',
						),
					)
				)
			);

			return array(
				'valid' => false,
				'code'  => 'blacklisted',
			);
		}

		// Step 3: Status check — only 1 (active) and 2 (inactive) may proceed.
		$allowed_statuses = array( 1, 2 );

		if ( ! in_array( $license->status, $allowed_statuses, true ) ) {
			$code = $this->status_error_code( $license->status );
			$this->log_event( $license->id, null, 'validate', 'fail', array_merge( $context, array( 'meta' => array( 'code' => $code ) ) ) );

			return array(
				'valid' => false,
				'code'  => $code,
			);
		}

		// Step 4: Expiry check (including grace days).
		if ( ! $license->is_within_expiry() ) {
			$this->license_repo->update( $license->id, array( 'status' => 3 ) );
			$this->log_event( $license->id, null, 'validate', 'fail', array_merge( $context, array( 'meta' => array( 'code' => 'expired' ) ) ) );

			return array(
				'valid' => false,
				'code'  => 'expired',
			);
		}

		// Step 5: Per-fingerprint machine check. The client sends a RAW
		// fingerprint; machines are stored under its HMAC-SHA256 hash, so hash
		// before lookup (mirroring ActivationService/HeartbeatService). Without
		// this, an activated device is never matched and needs_activation stays
		// true forever.
		$needs_activation = false;
		$machine          = null;

		if ( null !== $fingerprint && '' !== $fingerprint ) {
			$fp_hash = $this->fingerprint->hash( $fingerprint );
			$machine = $this->machine_repo->find_by_license_and_fingerprint( $license->id, $fp_hash );

			if ( null === $machine || 1 !== $machine->status ) {
				// Machine not registered or not currently active → client must activate.
				$needs_activation = true;
				$machine          = null;
			}
		}

		// Step 6: Seat limit check for unknown machines.
		if ( $needs_activation && null !== $license->max_activations ) {
			if ( $license->activation_count >= $license->max_activations ) {
				$this->log_event(
					$license->id,
					null,
					'validate',
					'limit_exceeded',
					array_merge( $context, array( 'meta' => array( 'code' => 'machine_limit_exceeded' ) ) )
				);

				return array(
					'valid' => false,
					'code'  => 'machine_limit_exceeded',
				);
			}
		}

		// Step 7: Log validate success.
		$machine_id = null !== $machine ? $machine->id : null;
		$this->log_event( $license->id, $machine_id, 'validate', 'success', $context );

		// Build response.
		$response = array(
			'valid'            => true,
			'license'          => $license->to_array(),
			'signed_payload'   => $license->signature,
			'needs_activation' => $needs_activation,
		);

		/**
		 * Filter the validation response before it is returned to the caller.
		 *
		 * @param array   $response    The response array.
		 * @param License $license     The resolved license model.
		 * @param string  $key_string  The queried key string.
		 * @param array   $context     Caller context (ip, country, etc.).
		 */
		$response = (array) apply_filters( 'wplm_validation_response', $response, $license, $key_string, $context );

		return $response;
	}

	// -------------------------------------------------------------------------
	// Mutations
	// -------------------------------------------------------------------------

	/**
	 * Update arbitrary license fields.
	 *
	 * @param int   $id   License row id.
	 * @param array $data Column/value pairs to update.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		$current = $this->license_repo->find_by_id( $id );
		if ( null === $current ) {
			return false;
		}

		// Only real table columns may be written. The edit form also posts a
		// transient 'key_string' field; passing it through to $wpdb->update()
		// raises "Unknown column 'key_string'" and aborts the ENTIRE update, so
		// nothing (seats, expiry, …) gets saved. Whitelisting prevents that.
		$columns = array(
			'product_id',
			'order_id',
			'user_id',
			'max_activations',
			'activation_count',
			'valid_for_days',
			'is_floating',
			'overage_strategy',
			'status',
			'expires_at',
			'grace_days',
			'activated_at',
			'source',
			'created_by',
		);

		$update = array();
		foreach ( $columns as $col ) {
			if ( array_key_exists( $col, $data ) ) {
				$update[ $col ] = $data[ $col ];
			}
		}

		if ( array_key_exists( 'is_floating', $update ) ) {
			$update['is_floating'] = ! empty( $update['is_floating'] ) ? 1 : 0;
		}

		// Optional key rotation from the edit form: re-derive hash + ciphertext.
		$key_string  = isset( $data['key_string'] ) ? (string) $data['key_string'] : '';
		$key_changed = '' !== $key_string && Fingerprint::sha256( $key_string ) !== $current->hash;
		if ( $key_changed ) {
			$update['hash']        = Fingerprint::sha256( $key_string );
			$update['license_key'] = $this->vault->encrypt( $key_string );
		}

		// Re-sign the offline payload whenever a signed field (key/expires/max/
		// product_id) changes, so offline clients honour the updated limits and
		// product binding on next sync.
		$expires_changed = array_key_exists( 'expires_at', $update )
			&& (string) $update['expires_at'] !== (string) $current->expires_at;
		$max_changed     = array_key_exists( 'max_activations', $update )
			&& (int) $update['max_activations'] !== (int) $current->max_activations;
		$pid_changed     = array_key_exists( 'product_id', $update )
			&& (int) $update['product_id'] !== (int) $current->product_id;

		if ( $key_changed || $expires_changed || $max_changed || $pid_changed ) {
			// find_by_id() hydrates with the key already decrypted, so the
			// current model's license_key is plaintext.
			$plain = $key_changed ? $key_string : (string) $current->license_key;
			if ( '' !== $plain ) {
				$new_expires         = array_key_exists( 'expires_at', $update ) ? $update['expires_at'] : $current->expires_at;
				$new_max             = array_key_exists( 'max_activations', $update ) ? $update['max_activations'] : $current->max_activations;
				$new_pid             = array_key_exists( 'product_id', $update ) ? $update['product_id'] : $current->product_id;
				$update['signature'] = $this->signer->sign(
					$this->build_signing_payload(
						$plain,
						null !== $new_expires ? (string) $new_expires : null,
						null !== $new_max ? (int) $new_max : null,
						null !== $new_pid ? (int) $new_pid : null
					)
				);
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		return $this->license_repo->update( $id, $update );
	}

	/**
	 * Delete a license by primary key.
	 *
	 * @param int $id License row id.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		return $this->license_repo->delete( $id );
	}

	/**
	 * Renew a license by extending its expiry and marking it active.
	 *
	 * @param string $key_string     Plaintext license key.
	 * @param string $new_expires_at MySQL datetime string for the new expiry.
	 * @return License|false Updated license on success, false if key not found.
	 */
	public function renew( string $key_string, string $new_expires_at ): License|false {
		$license = $this->license_repo->find_by_key( $key_string );

		if ( null === $license ) {
			return false;
		}

		$old_status = $license->status;

		$this->license_repo->update(
			$license->id,
			array(
				'expires_at' => $new_expires_at,
				'status'     => 1,
			)
		);

		/**
		 * Fires when a license status changes due to renewal.
		 *
		 * @param License $license    The license model (pre-update snapshot).
		 * @param int     $old_status Previous status code.
		 * @param int     $new_status New status code (1 = active).
		 */
		do_action( 'wplm_license_status_changed', $license, $old_status, 1 );

		return $this->license_repo->find_by_id( $license->id );
	}

	/**
	 * Build the canonical Ed25519 signing payload for a license.
	 *
	 * Single source of truth shared by create(), the update() re-sign path, and
	 * the admin re-sign tool, so every issued token has an identical field shape.
	 * The `pid` field binds a license to a WooCommerce product id: SDKs that are
	 * configured with a product id reject any token whose `pid` does not match,
	 * preventing one product's key from validating inside another product. A null
	 * `pid` means the license is not product-locked.
	 *
	 * @param string      $key_string      Plaintext license key.
	 * @param string|null $expires_at      MySQL datetime, or null for perpetual.
	 * @param int|null    $max_activations Seat cap, or null for unlimited.
	 * @param int|null    $product_id      Bound product id, or null (unlocked).
	 * @return array<string, mixed>
	 */
	private function build_signing_payload( string $key_string, ?string $expires_at, ?int $max_activations, ?int $product_id ): array {
		return array(
			'key'     => $key_string,
			'expires' => $expires_at,
			'max'     => null !== $max_activations ? (int) $max_activations : null,
			'pid'     => null !== $product_id ? (int) $product_id : null,
			'iat'     => time(),
		);
	}

	/**
	 * Re-sign every license so each token carries the product-binding `pid`
	 * field. Optionally backfills a product id onto licenses that currently have
	 * none (e.g. generator/API/CSV keys), which is required before a
	 * product-locked SDK will accept them.
	 *
	 * @param int|null $backfill_product_id When set (> 0), assigns this product
	 *                                      id to any license whose product_id is
	 *                                      currently null before re-signing.
	 * @return array{total:int, resigned:int, backfilled:int, still_unbound:int}
	 */
	public function resign_all( ?int $backfill_product_id = null ): array {
		$per_page      = 100;
		$page          = 1;
		$total         = 0;
		$resigned      = 0;
		$backfilled    = 0;
		$still_unbound = 0;

		do {
			$batch = $this->license_repo->get_list(
				array(
					'per_page' => $per_page,
					'page'     => $page,
					'orderby'  => 'id',
					'order'    => 'ASC',
				)
			);
			$rows    = is_array( $batch['items'] ?? null ) ? $batch['items'] : array();
			$total   = (int) ( $batch['total'] ?? 0 );
			$fetched = count( $rows );

			foreach ( $rows as $license ) {
				$plain = (string) $license->license_key; // hydrated = plaintext.
				if ( '' === $plain ) {
					continue;
				}

				$product_id = $license->product_id;
				if ( null === $product_id && null !== $backfill_product_id && $backfill_product_id > 0 ) {
					$product_id = $backfill_product_id;
					++$backfilled;
				}

				if ( null === $product_id ) {
					++$still_unbound;
				}

				$signature = $this->signer->sign(
					$this->build_signing_payload(
						$plain,
						null !== $license->expires_at ? (string) $license->expires_at : null,
						null !== $license->max_activations ? (int) $license->max_activations : null,
						null !== $product_id ? (int) $product_id : null
					)
				);

				$data = array( 'signature' => $signature );
				if ( null !== $product_id && (int) $product_id !== (int) $license->product_id ) {
					$data['product_id'] = (int) $product_id;
				}

				$this->license_repo->update( $license->id, $data );
				++$resigned;
			}

			++$page;
		} while ( $fetched === $per_page && $resigned < $total );

		return array(
			'total'         => $total,
			'resigned'      => $resigned,
			'backfilled'    => $backfilled,
			'still_unbound' => $still_unbound,
		);
	}

	/**
	 * Change the status of a license, enforcing the terminated rule.
	 *
	 * @param int $id         License row id.
	 * @param int $new_status Target status code.
	 * @return bool
	 * @throws \RuntimeException When the license is terminated or not found.
	 */
	public function change_status( int $id, int $new_status ): bool {
		$license = $this->license_repo->find_by_id( $id );

		if ( null === $license ) {
			throw new \RuntimeException(
				sprintf( 'WPLM LicenseService::change_status(): license %d not found.', $id )
			);
		}

		if ( 6 === $license->status ) {
			throw new \RuntimeException( 'Cannot change status of a terminated license.' );
		}

		$old_status = $license->status;

		$this->license_repo->update( $id, array( 'status' => $new_status ) );

		/**
		 * Fires after a license status has been changed.
		 *
		 * @param License $license    The license model (pre-update snapshot).
		 * @param int     $old_status Previous status code.
		 * @param int     $new_status New status code.
		 */
		do_action( 'wplm_license_status_changed', $license, $old_status, $new_status );

		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Map a non-allowed license status to a human-readable error code string.
	 *
	 * @param int $status License status code.
	 * @return string Error code.
	 */
	private function status_error_code( int $status ): string {
		$map = array(
			0 => 'license_pending',
			3 => 'expired',
			4 => 'suspended',
			5 => 'revoked',
			6 => 'terminated',
		);

		return $map[ $status ] ?? 'license_invalid';
	}

	/**
	 * Write a row to the activation log.
	 *
	 * @param int      $license_id License row id (0 when license was not resolved).
	 * @param int|null $machine_id Machine row id, or null.
	 * @param string   $event      Event type: validate|activate|deactivate|heartbeat|revoke|deny.
	 * @param string   $result     Result: success|fail|limit_exceeded|expired|revoked.
	 * @param array    $context    Caller context: ip, country, meta, etc.
	 */
	private function log_event(
		int $license_id,
		?int $machine_id,
		string $event,
		string $result,
		array $context = array()
	): void {
		$this->log_repo->create(
			array(
				'license_id' => $license_id,
				'machine_id' => $machine_id,
				'event'      => $event,
				'result'     => $result,
				'ip_address' => $context['ip'] ?? null,
				'country'    => $context['country'] ?? null,
				'meta'       => $context['meta'] ?? array(),
			)
		);
	}
}
