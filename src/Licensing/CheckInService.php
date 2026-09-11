<?php
/**
 * Check-in for entitlement licences: activate, check in, move — each answered with a fresh v2 token.
 *
 * @package WPLM\Licensing
 */

namespace WPLM\Licensing;

use WP_Error;
use WPLM\Crypto\Fingerprint;
use WPLM\Models\License;
use WPLM\Models\Machine;
use WPLM\Repositories\ActivationLogRepository;
use WPLM\Repositories\LicenseRepository;
use WPLM\Repositories\MachineRepository;
use WPLM\Services\ActivationService;

defined( 'ABSPATH' ) || exit;

/**
 * The server side of an entitlement licence's check-in loop:
 *
 * - **activate** and **check in** return a signed v2 token and the server time, and record the
 *   machine's usage counts, app version and the `fp` the token carries (so admin can issue an
 *   offline code later without a request);
 * - check-ins are **rate-limited per machine** (default 30 an hour, `wplm_check_in_rate_limit`);
 * - a self-service **move** (deactivating an active machine so the licence can go to another PC) is
 *   limited per licence (default 2 in 30 days, `wplm_move_limit`); the owner can reset the count.
 *
 * Classic licences never come here.
 */
class CheckInService {

	public const EVENT_CHECK_IN   = 'check_in';
	public const EVENT_MOVE       = 'move';
	public const EVENT_MOVE_RESET = 'move_reset';

	private const RATE_WINDOW = HOUR_IN_SECONDS;
	private const MOVE_WINDOW = 30 * DAY_IN_SECONDS;

	/** @var LicenseRepository */
	private LicenseRepository $licenses;

	/** @var MachineRepository */
	private MachineRepository $machines;

	/** @var ActivationLogRepository */
	private ActivationLogRepository $log;

	/** @var ActivationService */
	private ActivationService $activation;

	/** @var TokenV2Service */
	private TokenV2Service $tokens;

	/** @var ProfileRegistry */
	private ProfileRegistry $profiles;

	/** @var Fingerprint */
	private Fingerprint $fingerprint;

	/**
	 * @param LicenseRepository       $licenses    Licence data-access layer.
	 * @param MachineRepository       $machines    Machine data-access layer.
	 * @param ActivationLogRepository $log         Activation log.
	 * @param ActivationService       $activation  Seat accounting.
	 * @param TokenV2Service          $tokens      v2 token issuer.
	 * @param ProfileRegistry         $profiles    Known licence profiles.
	 * @param Fingerprint             $fingerprint Fingerprint HMAC.
	 */
	public function __construct(
		LicenseRepository $licenses,
		MachineRepository $machines,
		ActivationLogRepository $log,
		ActivationService $activation,
		TokenV2Service $tokens,
		ProfileRegistry $profiles,
		Fingerprint $fingerprint
	) {
		$this->licenses    = $licenses;
		$this->machines    = $machines;
		$this->log         = $log;
		$this->activation  = $activation;
		$this->tokens      = $tokens;
		$this->profiles    = $profiles;
		$this->fingerprint = $fingerprint;
	}

	/** Whether a licence is an entitlement licence (and so checks in here). */
	public function handles( ?License $license ): bool {
		return null !== $license && null !== $this->profiles->get( $license->profile );
	}

	// -------------------------------------------------------------------------
	// Activate / check in
	// -------------------------------------------------------------------------

	/**
	 * Activate this machine and hand it its first token.
	 *
	 * @param License $license   The licence the key resolved to.
	 * @param string  $key       Plaintext licence key.
	 * @param string  $client_fp The client's fingerprint value (lowercase 64-hex).
	 * @param array   $meta      name, hostname, platform, app_version, ip_address, components.
	 * @param mixed   $usage     Raw usage counts from the request.
	 * @return array{machine: Machine, token: string, server_time: int}|WP_Error
	 */
	public function activate( License $license, string $key, string $client_fp, array $meta, $usage ) {
		$invalid = $this->fingerprint_error( $client_fp );
		if ( null !== $invalid ) {
			return $invalid; // Before anything is created.
		}

		$existing = $this->machines->find_by_license_and_fingerprint( $license->id, $this->fingerprint->hash( $client_fp ) );
		if ( null !== $existing ) {
			$limited = $this->rate_limit_error( $license, $existing );
			if ( null !== $limited ) {
				return $limited;
			}
		}

		$machine = $this->activation->activate( $key, $client_fp, $meta );
		if ( is_wp_error( $machine ) ) {
			return $machine;
		}

		return $this->answer( $license->id, $machine, $client_fp, (string) ( $meta['app_version'] ?? '' ), $usage, (string) ( $meta['ip_address'] ?? '' ) );
	}

	/**
	 * Check in an activated machine: refresh its token and record what it reports.
	 *
	 * @param License $license   The licence the key resolved to.
	 * @param string  $client_fp The client's fingerprint value.
	 * @param array   $context   app_version, ip_address.
	 * @param mixed   $usage     Raw usage counts from the request.
	 * @return array{machine: Machine, token: string, server_time: int}|WP_Error
	 */
	public function check_in( License $license, string $client_fp, array $context, $usage ) {
		$invalid = $this->fingerprint_error( $client_fp );
		if ( null !== $invalid ) {
			return $invalid;
		}

		$machine = $this->machines->find_by_license_and_fingerprint( $license->id, $this->fingerprint->hash( $client_fp ) );
		if ( null === $machine ) {
			return new WP_Error( 'machine_not_found', __( 'No matching device activation found.', 'wp-license-manager' ), array( 'status' => 404 ) );
		}
		if ( ! $machine->is_active() ) {
			return new WP_Error( 'machine_inactive', __( 'This device activation is not active.', 'wp-license-manager' ), array( 'status' => 403 ) );
		}

		$limited = $this->rate_limit_error( $license, $machine );
		if ( null !== $limited ) {
			return $limited;
		}

		$answer = $this->answer( $license->id, $machine, $client_fp, (string) ( $context['app_version'] ?? '' ), $usage, (string) ( $context['ip_address'] ?? '' ) );
		if ( ! is_wp_error( $answer ) ) {
			$this->machines->update_heartbeat( $machine->id, null );
			/** This action is documented in HeartbeatService::record_heartbeat(). */
			do_action( 'wplm_heartbeat_received', $machine );
		}
		return $answer;
	}

	// -------------------------------------------------------------------------
	// Moves
	// -------------------------------------------------------------------------

	/**
	 * A self-service move: deactivate one of the licence's machines so the licence can be activated on
	 * another computer. Deactivating a machine that is already inactive is not a move.
	 *
	 * @param License $license The licence.
	 * @param Machine $machine One of its machines.
	 * @param int     $now     Unix time (default now).
	 * @return true|WP_Error
	 */
	public function move_off( License $license, Machine $machine, ?int $now = null ) {
		if ( $machine->license_id !== $license->id ) {
			return new WP_Error( 'wplm_machine_not_found', __( 'No matching device activation found for this license.', 'wp-license-manager' ), array( 'status' => 404 ) );
		}
		if ( ! $machine->is_active() ) {
			return true;
		}

		$now    = $now ?? time();
		$window = $this->move_window( $license, $now );
		if ( $window['used'] >= $window['limit'] ) {
			return new WP_Error(
				'wplm_move_limit_reached',
				sprintf(
					/* translators: 1: move limit, 2: days */
					__( 'This licence has been moved %1$d times in the last %2$d days. Contact support to move it again.', 'wp-license-manager' ),
					$window['limit'],
					(int) ( self::MOVE_WINDOW / DAY_IN_SECONDS )
				),
				array(
					'status'         => 429,
					'moves_limit'    => $window['limit'],
					'moves_reset_at' => null !== $window['reset_at'] ? gmdate( 'c', $window['reset_at'] ) : null,
				)
			);
		}

		$result = $this->activation->deactivate_by_machine_id( $machine->id, 'self_service_move' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->log->create(
			array(
				'license_id' => $license->id,
				'machine_id' => $machine->id,
				'event'      => self::EVENT_MOVE,
				'result'     => 'success',
			)
		);

		/**
		 * Fires after a self-service licence move freed a machine.
		 *
		 * @param License $license The licence.
		 * @param Machine $machine The machine that was deactivated.
		 */
		do_action( 'wplm_license_moved_off', $license, $machine );

		return true;
	}

	/**
	 * Self-service moves still allowed in the rolling window.
	 *
	 * @param License  $license The licence.
	 * @param int|null $now     Unix time (default now).
	 * @return int
	 */
	public function moves_left( License $license, ?int $now = null ): int {
		$window = $this->move_window( $license, $now ?? time() );
		return max( 0, $window['limit'] - $window['used'] );
	}

	/**
	 * The owner lets a customer move again: moves before this point no longer count.
	 *
	 * @param License $license The licence.
	 * @param int     $user_id The admin who reset it.
	 * @return void
	 */
	public function reset_moves( License $license, int $user_id ): void {
		$this->log->create(
			array(
				'license_id' => $license->id,
				'event'      => self::EVENT_MOVE_RESET,
				'result'     => 'success',
				'meta'       => array( 'user_id' => $user_id ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Issue the token, then record the check-in. Nothing is written when the token is refused
	 * (revoked, terminated, wrong machine), so a refused machine leaves no trace of having checked in.
	 *
	 * @return array{machine: Machine, token: string, server_time: int}|WP_Error
	 */
	private function answer( int $license_id, Machine $machine, string $client_fp, string $app_version, $usage, string $ip ) {
		$license = $this->licenses->find_by_id( $license_id );
		if ( null === $license ) {
			return new WP_Error( 'license_not_found', __( 'License key not found.', 'wp-license-manager' ), array( 'status' => 404 ) );
		}

		$now    = time();
		$issued = $this->tokens->issue( $license, $machine, $client_fp, array( 'now' => $now ) );
		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		$update = array( 'token_fp' => $client_fp );
		if ( '' !== $app_version ) {
			$update['app_version'] = $app_version;
		}
		$counts = $this->normalize_usage( $license, $usage );
		if ( null !== $counts ) {
			$update['usage_json'] = wp_json_encode( (object) $counts );
		}
		$this->machines->update( $machine->id, $update );

		$this->log->create(
			array(
				'license_id' => $license->id,
				'machine_id' => $machine->id,
				'event'      => self::EVENT_CHECK_IN,
				'result'     => 'success',
				'ip_address' => '' !== $ip ? $ip : null,
				'meta'       => array(
					'app_version' => '' !== $app_version ? $app_version : null,
					'usage'       => $counts,
				),
			)
		);

		return array(
			'machine'     => $this->machines->find_by_id( $machine->id ) ?? $machine,
			'token'       => $issued['token'],
			'server_time' => $now,
		);
	}

	/**
	 * Usage counts limited to the profile's limit codes, as non-negative integers. Null when the request
	 * carried no usable counts (the stored counts are then left as they are).
	 *
	 * @param License $license The licence.
	 * @param mixed   $usage   Raw request value.
	 * @return array<string, int>|null
	 */
	private function normalize_usage( License $license, $usage ): ?array {
		$profile = $this->profiles->get( $license->profile );
		if ( null === $profile || ! is_array( $usage ) ) {
			return null;
		}
		$counts = array();
		foreach ( $profile->limit_codes as $code ) {
			if ( isset( $usage[ $code ] ) && is_numeric( $usage[ $code ] ) ) {
				$counts[ $code ] = max( 0, (int) $usage[ $code ] );
			}
		}
		return empty( $counts ) ? null : $counts;
	}

	/** A 400 for anything but a lowercase 64-hex fingerprint value, else null. */
	private function fingerprint_error( string $client_fp ): ?WP_Error {
		if ( 1 === preg_match( '/^[0-9a-f]{64}$/', $client_fp ) ) {
			return null;
		}
		return new WP_Error( 'wplm_invalid_fingerprint', __( 'The device fingerprint must be 64 lowercase hex characters.', 'wp-license-manager' ), array( 'status' => 400 ) );
	}

	/** A 429 when the machine has used up its check-ins for the rolling hour, else null. */
	private function rate_limit_error( License $license, Machine $machine ): ?WP_Error {
		/**
		 * Filter the number of check-ins one machine may make per rolling hour.
		 *
		 * @param int     $limit   Default 30.
		 * @param License $license The licence.
		 */
		$limit  = max( 1, (int) apply_filters( 'wplm_check_in_rate_limit', 30, $license ) );
		$now    = time();
		$recent = $this->log->events_since( 'machine_id', $machine->id, self::EVENT_CHECK_IN, $now - self::RATE_WINDOW );
		if ( count( $recent ) < $limit ) {
			return null;
		}

		$oldest_counted = $recent[ count( $recent ) - $limit ]['at'];
		return new WP_Error(
			'wplm_rate_limited',
			__( 'Too many check-ins from this device. Try again later.', 'wp-license-manager' ),
			array(
				'status'      => 429,
				'retry_after' => max( 1, $oldest_counted + self::RATE_WINDOW - $now ),
			)
		);
	}

	/**
	 * Moves used in the rolling window (after the last owner reset) and when the oldest one expires.
	 *
	 * @return array{limit: int, used: int, reset_at: int|null}
	 */
	private function move_window( License $license, int $now ): array {
		/**
		 * Filter how many self-service moves a licence may make in 30 days.
		 *
		 * @param int     $limit   Default 2.
		 * @param License $license The licence.
		 */
		$limit = max( 0, (int) apply_filters( 'wplm_move_limit', 2, $license ) );
		$moves = $this->log->events_since(
			'license_id',
			$license->id,
			self::EVENT_MOVE,
			$now - self::MOVE_WINDOW,
			$this->log->last_event_id( $license->id, self::EVENT_MOVE_RESET )
		);

		$used = count( $moves );
		// The next move frees up when the move that keeps the count at the limit leaves the window.
		$freeing = $used >= $limit && $limit > 0 ? $moves[ $used - $limit ] : ( $moves[0] ?? null );

		return array(
			'limit'    => $limit,
			'used'     => $used,
			'reset_at' => null !== $freeing ? $freeing['at'] + self::MOVE_WINDOW : null,
		);
	}
}
