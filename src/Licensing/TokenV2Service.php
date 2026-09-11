<?php
/**
 * v2 token — the signed, machine-bound entitlement statement for a profile licence.
 *
 * @package WPLM\Licensing
 */

namespace WPLM\Licensing;

use WP_Error;
use WPLM\Crypto\Fingerprint;
use WPLM\Crypto\Signer;
use WPLM\Models\License;
use WPLM\Models\Machine;
use WPLM\Services\EntitlementService;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and signs the v2 token one activated machine of a profile licence holds:
 *
 *     {v, pid, lid, mid, fp, iat, srv, checkInBy, graceDays, status, modules, limits}
 *
 * The field shape is a contract with the client, which verifies it offline; a product's add-on pins it
 * with contract fixtures. Signed with the same Ed25519 key and format as v1 tokens.
 *
 * `fp` is the value the client sent (typically a hash of its product code and a raw machine id), so the
 * client can compare it; the server matches it against the machine's stored HMAC. The server never
 * locks for non-payment here: `status` is `suspended` only when the owner suspended the licence, and
 * a lapsed module is simply listed with a past `until`.
 */
class TokenV2Service {

	/** @var EntitlementService */
	private EntitlementService $entitlements;

	/** @var ProfileRegistry */
	private ProfileRegistry $profiles;

	/** @var Signer */
	private Signer $signer;

	/** @var Fingerprint */
	private Fingerprint $fingerprint;

	/**
	 * @param EntitlementService $entitlements Line summaries.
	 * @param ProfileRegistry    $profiles     Known licence profiles.
	 * @param Signer             $signer       Ed25519 signer.
	 * @param Fingerprint        $fingerprint  Fingerprint HMAC.
	 */
	public function __construct( EntitlementService $entitlements, ProfileRegistry $profiles, Signer $signer, Fingerprint $fingerprint ) {
		$this->entitlements = $entitlements;
		$this->profiles     = $profiles;
		$this->signer       = $signer;
		$this->fingerprint  = $fingerprint;
	}

	/**
	 * Build the payload for one machine.
	 *
	 * @param License $license   The licence.
	 * @param Machine $machine   One of its machines.
	 * @param string  $client_fp The fingerprint value the client sent (lowercase 64-hex).
	 * @param array   $opts      now (int, unix; default time()), check_in_by (int, unix; overrides the
	 *                           check-in window — offline renewal codes).
	 * @return array<string, mixed>|WP_Error
	 */
	public function payload( License $license, Machine $machine, string $client_fp, array $opts = array() ) {
		$profile = $this->profiles->get( $license->profile );
		if ( null === $profile ) {
			return new WP_Error( 'wplm_not_entitlement_license', __( 'This licence does not use entitlement tokens.', 'wp-license-manager' ), array( 'status' => 400 ) );
		}
		if ( 5 === $license->status ) {
			return new WP_Error( 'license_revoked', __( 'This license has been revoked.', 'wp-license-manager' ), array( 'status' => 403 ) );
		}
		if ( 6 === $license->status ) {
			return new WP_Error( 'license_terminated', __( 'This license has been permanently terminated.', 'wp-license-manager' ), array( 'status' => 403 ) );
		}
		if ( $machine->license_id !== $license->id || ! $machine->is_active() ) {
			return new WP_Error( 'wplm_machine_not_active', __( 'This device is not activated on this licence.', 'wp-license-manager' ), array( 'status' => 403 ) );
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $client_fp ) ) {
			return new WP_Error( 'wplm_invalid_fingerprint', __( 'The device fingerprint must be 64 lowercase hex characters.', 'wp-license-manager' ), array( 'status' => 400 ) );
		}
		if ( ! hash_equals( $machine->fingerprint, $this->fingerprint->hash( $client_fp ) ) ) {
			return new WP_Error( 'wplm_fingerprint_mismatch', __( 'The device fingerprint does not match this activation.', 'wp-license-manager' ), array( 'status' => 403 ) );
		}

		$now         = isset( $opts['now'] ) ? (int) $opts['now'] : time();
		$check_in_by = isset( $opts['check_in_by'] ) ? (int) $opts['check_in_by'] : $now + $profile->check_in_days() * DAY_IN_SECONDS;

		return self::compose(
			$profile,
			$license->id,
			$machine->id,
			$client_fp,
			$now,
			$check_in_by,
			$profile->grace_days(),
			4 === $license->status,
			$this->entitlements->lines( $license->id ),
			wp_date( 'Y-m-d', $now )
		);
	}

	/**
	 * The pure payload composition, shared by payload() and the contract fixtures.
	 *
	 * @param Profile                           $profile     Licence profile.
	 * @param int                               $lid         Licence id.
	 * @param int                               $mid         Machine id.
	 * @param string                            $fp          Client fingerprint value.
	 * @param int                               $now         Issue time (unix).
	 * @param int                               $check_in_by Check-in deadline (unix).
	 * @param int                               $grace_days  Grace signed into the token.
	 * @param bool                              $suspended   Whether the owner suspended the licence.
	 * @param \WPLM\Models\Entitlement[]        $lines       The licence's lines.
	 * @param string                            $today       Site-time-zone date of $now (Y-m-d).
	 * @return array<string, mixed>
	 */
	public static function compose( Profile $profile, int $lid, int $mid, string $fp, int $now, int $check_in_by, int $grace_days, bool $suspended, array $lines, string $today ): array {
		$summary = EntitlementService::summarize_lines( $lines, $profile, $today, $grace_days );

		return array(
			'v'         => 2,
			'pid'       => $profile->code,
			'lid'       => $lid,
			'mid'       => $mid,
			'fp'        => $fp,
			'iat'       => $now,
			'srv'       => $now,
			'checkInBy' => $check_in_by,
			'graceDays' => $grace_days,
			'status'    => $suspended ? 'suspended' : 'active',
			'modules'   => $summary['modules'],
			'limits'    => $summary['limits'],
		);
	}

	/**
	 * The payload as it is signed: JSON maps, never lists (an empty PHP array would encode as [] and
	 * break a Map decode).
	 *
	 * @param array<string, mixed> $payload Composed payload.
	 * @return array<string, mixed>
	 */
	public static function wire( array $payload ): array {
		$payload['modules'] = (object) $payload['modules'];
		$payload['limits']  = (object) $payload['limits'];
		return $payload;
	}

	/**
	 * Build and sign the token for one machine.
	 *
	 * @param License $license   The licence.
	 * @param Machine $machine   One of its machines.
	 * @param string  $client_fp The fingerprint value the client sent.
	 * @param array   $opts      See payload().
	 * @return array{token: string, payload: array<string, mixed>}|WP_Error
	 */
	public function issue( License $license, Machine $machine, string $client_fp, array $opts = array() ) {
		$payload = $this->payload( $license, $machine, $client_fp, $opts );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		return array(
			'token'   => $this->signer->sign( self::wire( $payload ) ),
			'payload' => $payload,
		);
	}
}
