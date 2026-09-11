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
 * The field shape is a contract with the client (Super Ledger verifies it offline), fixed by the
 * contract fixtures. Signed with the same Ed25519 key and format as v1 tokens.
 *
 * `fp` is the value the client sent — for Super Ledger `sha256_hex('super-ledger|' + raw)` — so the
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
		$grace_days  = $profile->grace_days();
		$summary     = $this->entitlements->summarize( $license, $profile, wp_date( 'Y-m-d', $now ), $grace_days );

		return array(
			'v'         => 2,
			'pid'       => $profile->code,
			'lid'       => $license->id,
			'mid'       => $machine->id,
			'fp'        => $client_fp,
			'iat'       => $now,
			'srv'       => $now,
			'checkInBy' => $check_in_by,
			'graceDays' => $grace_days,
			'status'    => 4 === $license->status ? 'suspended' : 'active',
			'modules'   => $summary['modules'],
			'limits'    => $summary['limits'],
		);
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

		// JSON maps, never lists: an empty PHP array would encode as [] and break a Map decode.
		$wire            = $payload;
		$wire['modules'] = (object) $payload['modules'];
		$wire['limits']  = (object) $payload['limits'];

		return array(
			'token'   => $this->signer->sign( $wire ),
			'payload' => $payload,
		);
	}
}
