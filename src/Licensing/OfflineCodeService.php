<?php
/**
 * Offline renewal codes — a v2 token with a longer check-in deadline, for an office with no internet.
 *
 * @package WPLM\Licensing
 */

namespace WPLM\Licensing;

use WP_Error;
use WPLM\Repositories\ActivationLogRepository;
use WPLM\Repositories\LicenseRepository;
use WPLM\Repositories\MachineRepository;

defined( 'ABSPATH' ) || exit;

/**
 * M5-27a §6. The owner presses "Offline renewal code" for one machine and sends the result over
 * WhatsApp; it is pasted into Super Ledger's Licence screen. **The code is an ordinary v2 token** for
 * that machine — same signature, same `fp`, same paid-through dates — whose only difference is a
 * `checkInBy` the owner chose. There is no second format to secure, and it cannot extend what is paid.
 */
class OfflineCodeService {

	public const EVENT = 'offline_code';

	public const MIN_DAYS = 1;
	public const MAX_DAYS = 365;

	/** @var LicenseRepository */
	private LicenseRepository $licenses;

	/** @var MachineRepository */
	private MachineRepository $machines;

	/** @var ActivationLogRepository */
	private ActivationLogRepository $log;

	/** @var TokenV2Service */
	private TokenV2Service $tokens;

	/**
	 * @param LicenseRepository       $licenses Licence data-access layer.
	 * @param MachineRepository       $machines Machine data-access layer.
	 * @param ActivationLogRepository $log      Activation log.
	 * @param TokenV2Service          $tokens   v2 token issuer.
	 */
	public function __construct( LicenseRepository $licenses, MachineRepository $machines, ActivationLogRepository $log, TokenV2Service $tokens ) {
		$this->licenses = $licenses;
		$this->machines = $machines;
		$this->log      = $log;
		$this->tokens   = $tokens;
	}

	/**
	 * Issue a code for one machine.
	 *
	 * @param int      $machine_id Machine row id.
	 * @param int      $days       Days until the office must check in again (1–365).
	 * @param int      $user_id    The admin issuing it (logged).
	 * @param int|null $now        Unix time (default now).
	 * @return array{token: string, check_in_by: int, payload: array<string, mixed>}|WP_Error
	 */
	public function issue( int $machine_id, int $days, int $user_id, ?int $now = null ) {
		if ( $days < self::MIN_DAYS || $days > self::MAX_DAYS ) {
			return new WP_Error(
				'wplm_invalid_days',
				/* translators: 1: minimum days, 2: maximum days */
				sprintf( __( 'Choose between %1$d and %2$d days.', 'wp-license-manager' ), self::MIN_DAYS, self::MAX_DAYS ),
				array( 'status' => 400 )
			);
		}

		$machine = $this->machines->find_by_id( $machine_id );
		$license = null !== $machine ? $this->licenses->find_by_id( $machine->license_id ) : null;
		if ( null === $machine || null === $license ) {
			return new WP_Error( 'wplm_machine_not_found', __( 'Machine not found.', 'wp-license-manager' ), array( 'status' => 404 ) );
		}
		if ( null === $license->profile ) {
			return new WP_Error( 'wplm_not_entitlement_license', __( 'This licence does not use entitlement tokens.', 'wp-license-manager' ), array( 'status' => 400 ) );
		}
		if ( null === $machine->token_fp ) {
			return new WP_Error(
				'wplm_no_check_in_yet',
				__( 'This computer has not checked in since the licence server was upgraded, so its code cannot be bound to it yet. It must connect to the internet once.', 'wp-license-manager' ),
				array( 'status' => 409 )
			);
		}

		$now         = $now ?? time();
		$check_in_by = $now + $days * DAY_IN_SECONDS;
		$issued      = $this->tokens->issue(
			$license,
			$machine,
			$machine->token_fp,
			array(
				'now'         => $now,
				'check_in_by' => $check_in_by,
			)
		);
		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		$this->log->create(
			array(
				'license_id' => $license->id,
				'machine_id' => $machine->id,
				'event'      => self::EVENT,
				'result'     => 'success',
				'meta'       => array(
					'user_id'     => $user_id,
					'days'        => $days,
					'check_in_by' => $check_in_by,
				),
			)
		);

		return array(
			'token'       => $issued['token'],
			'check_in_by' => $check_in_by,
			'payload'     => $issued['payload'],
		);
	}
}
