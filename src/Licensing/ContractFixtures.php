<?php
/**
 * Contract fixtures — the signed cases Super Ledger verifies without running PHP.
 *
 * @package WPLM\Licensing
 */

namespace WPLM\Licensing;

use WPLM\Crypto\CompactToken;
use WPLM\Models\Entitlement;

defined( 'ABSPATH' ) || exit;

/**
 * M5-27a §9. Writes `public_key.txt`, one file per case and `cases.json` (the fixed "now", the raw
 * fingerprint and each case's expected outcome). Super Ledger commits the output at
 * `app/packages/core/test/fixtures/licensing/` (M5-27b) and both repositories treat it as the contract.
 *
 * - Tokens are composed by {@see TokenV2Service::compose()} and signed by {@see CompactToken} — the
 *   same code real tokens go through. Only the key differs.
 * - The keypair is **derived from a fixed public seed**, so regenerating is byte-identical (Ed25519
 *   signatures are deterministic) and a diff in Super Ledger always means a real contract change.
 *   Everyone can derive that key: **it must never be trusted by a release build.**
 * - Expected module states follow the program file: active until 3 days before `until`, due soon
 *   within 3 days, grace for `graceDays` after it, then read-only. Dates are calendar dates in
 *   Asia/Karachi. Cases avoid boundary days, so they pin the contract without pinning rounding.
 */
class ContractFixtures {

	/** Seed label for the fixture keypair. Changing it changes every fixture. */
	private const SEED_LABEL = 'wplm-super-ledger-contract-fixtures-v1';

	/** The fixed "now": 2026-09-11 10:00:00 UTC (15:00 in Karachi). */
	public const NOW = 1789120800;

	public const TODAY = '2026-09-11';

	public const RAW_FINGERPRINT = 'SL-FIXTURE-OFFICE-PC-01';

	private const LID = 1042;
	private const MID = 88;

	/** @var ProfileRegistry */
	private ProfileRegistry $profiles;

	/**
	 * @param ProfileRegistry $profiles Known licence profiles.
	 */
	public function __construct( ProfileRegistry $profiles ) {
		$this->profiles = $profiles;
	}

	/**
	 * Write the fixtures into a directory (created if missing; existing fixture files are replaced).
	 *
	 * @param string $dir Output directory.
	 * @return string[] Files written.
	 * @throws \RuntimeException When the directory cannot be written.
	 */
	public function write( string $dir ): array {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( sprintf( 'Cannot create %s.', $dir ) );
		}
		$written = array();
		foreach ( $this->generate() as $name => $content ) {
			$path = trailingslashit( $dir ) . $name;
			if ( false === file_put_contents( $path, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				throw new \RuntimeException( sprintf( 'Cannot write %s.', $path ) );
			}
			$written[] = $path;
		}
		return $written;
	}

	/**
	 * Build every fixture file in memory.
	 *
	 * @return array<string, string> File name => content.
	 */
	public function generate(): array {
		$pair    = sodium_crypto_sign_seed_keypair( hash( 'sha256', self::SEED_LABEL, true ) );
		$secret  = sodium_crypto_sign_secretkey( $pair );
		$public  = sodium_crypto_sign_publickey( $pair );
		$profile = $this->profiles->get( ProfileRegistry::SUPER_LEDGER );
		$fp      = hash( 'sha256', 'super-ledger|' . self::RAW_FINGERPRINT );
		$grace   = Profile::DEFAULT_GRACE_DAYS;
		$window  = Profile::DEFAULT_CHECK_IN_DAYS * DAY_IN_SECONDS;
		$now     = self::NOW;

		$base_ok = array( 'module', 'base', 0, '2026-10-10' );

		$token = function ( array $lines, array $over = array() ) use ( $profile, $fp, $grace, $window, $now, $secret ) {
			$iat     = $over['iat'] ?? $now;
			$payload = TokenV2Service::compose(
				$profile,
				self::LID,
				self::MID,
				$over['fp'] ?? $fp,
				$iat,
				$over['checkInBy'] ?? $iat + $window,
				$grace,
				! empty( $over['suspended'] ),
				array_map( array( self::class, 'line' ), $lines ),
				self::TODAY
			);
			foreach ( $over['inject'] ?? array() as $key => $value ) {
				$payload[ $key ] = is_array( $value ) && is_array( $payload[ $key ] ?? null ) ? array_merge( $payload[ $key ], $value ) : $value;
			}
			return CompactToken::sign( TokenV2Service::wire( $payload ), $secret );
		};

		$active_office = array(
			'state'  => 'active',
			'reason' => null,
		);
		$limits_none   = array(
			'users'  => 0,
			'seats'  => 0,
			'phones' => 0,
		);

		$cases = array(
			array(
				'name'        => 'active',
				'description' => 'Base and distribution paid through 2026-10-10: everything active.',
				'token'       => $token( array( $base_ok, array( 'module', 'distribution', 0, '2026-10-10' ) ) ),
				'expect'      => array(
					'office'  => $active_office,
					'modules' => array(
						'base'         => 'active',
						'distribution' => 'active',
					),
					'limits'  => $limits_none,
				),
			),
			array(
				'name'        => 'due_soon',
				'description' => 'Base paid through 2026-09-13, two days away: due soon.',
				'token'       => $token( array( array( 'module', 'base', 0, '2026-09-13' ) ) ),
				'expect'      => array(
					'office'  => $active_office,
					'modules' => array( 'base' => 'dueSoon' ),
					'limits'  => $limits_none,
				),
			),
			array(
				'name'        => 'grace',
				'description' => 'Base paid through 2026-09-08, three days ago: inside the 7-day grace.',
				'token'       => $token( array( array( 'module', 'base', 0, '2026-09-08' ) ) ),
				'expect'      => array(
					'office'  => $active_office,
					'modules' => array( 'base' => 'grace' ),
					'limits'  => $limits_none,
				),
			),
			array(
				'name'        => 'lapsed_base',
				'description' => 'Base paid through 2026-08-31, eleven days ago: past grace, so the whole office is read-only.',
				'token'       => $token( array( array( 'module', 'base', 0, '2026-08-31' ) ) ),
				'expect'      => array(
					'office'  => array(
						'state'  => 'readOnly',
						'reason' => 'unpaid',
					),
					'modules' => array( 'base' => 'readOnly' ),
					'limits'  => $limits_none,
				),
			),
			array(
				'name'        => 'lapsed_module_only',
				'description' => 'Base paid, distribution paid through 2026-08-20: only distribution is read-only.',
				'token'       => $token( array( $base_ok, array( 'module', 'distribution', 0, '2026-08-20' ) ) ),
				'expect'      => array(
					'office'  => $active_office,
					'modules' => array(
						'base'         => 'active',
						'distribution' => 'readOnly',
					),
					'limits'  => $limits_none,
				),
			),
			array(
				'name'        => 'lifetime_module',
				'description' => 'POS bought for life (until null) next to a monthly base.',
				'token'       => $token( array( $base_ok, array( 'module', 'pos', 0, null ) ) ),
				'expect'      => array(
					'office'  => $active_office,
					'modules' => array(
						'base' => 'active',
						'pos'  => 'active',
					),
					'limits'  => $limits_none,
				),
			),
			array(
				'name'        => 'suspended',
				'description' => 'Paid up, but the owner suspended the licence.',
				'token'       => $token( array( $base_ok ), array( 'suspended' => true ) ),
				'expect'      => array(
					'office'  => array(
						'state'  => 'readOnly',
						'reason' => 'suspended',
					),
					'modules' => array( 'base' => 'active' ),
					'limits'  => $limits_none,
				),
			),
			array(
				'name'        => 'other_machine',
				'description' => 'A valid token issued to a different computer (fp of another fingerprint).',
				'token'       => $token( array( $base_ok ), array( 'fp' => hash( 'sha256', 'super-ledger|SL-FIXTURE-SOME-OTHER-PC' ) ) ),
				'expect'      => array(
					'office'  => array(
						'state'  => 'readOnly',
						'reason' => 'otherMachine',
					),
					'modules' => array( 'base' => 'active' ),
					'limits'  => $limits_none,
				),
			),
			array(
				'name'        => 'check_in_overdue',
				'description' => 'Paid up, last checked in 9 days ago: checkInBy passed 2 days ago.',
				'token'       => $token( array( $base_ok ), array( 'iat' => $now - 9 * DAY_IN_SECONDS ) ),
				'expect'      => array(
					'office'  => array(
						'state'  => 'readOnly',
						'reason' => 'notCheckedIn',
					),
					'modules' => array( 'base' => 'active' ),
					'limits'  => $limits_none,
				),
			),
			array(
				'name'        => 'summed_limits',
				'description' => 'users 3 (paid) + 2 (paid through 2026-09-04, inside grace) + 4 (2026-09-03, past grace, not counted); seats 1 for life; phones 2.',
				'token'       => $token(
					array(
						$base_ok,
						array( 'limit', 'users', 3, '2026-10-10' ),
						array( 'limit', 'users', 2, '2026-09-04' ),
						array( 'limit', 'users', 4, '2026-09-03' ),
						array( 'limit', 'seats', 1, null ),
						array( 'limit', 'phones', 2, '2026-10-10' ),
					)
				),
				'expect'      => array(
					'office'  => $active_office,
					'modules' => array( 'base' => 'active' ),
					'limits'  => array(
						'users'  => 5,
						'seats'  => 1,
						'phones' => 2,
					),
				),
			),
			array(
				'name'        => 'unknown_module_code',
				'description' => 'The token lists a module the app does not know ("payroll", lifetime). It is ignored, never granted.',
				'token'       => $token( array( $base_ok ), array( 'inject' => array( 'modules' => array( 'payroll' => array( 'until' => null ) ) ) ) ),
				'expect'      => array(
					'office'  => $active_office,
					'modules' => array( 'base' => 'active' ),
					'limits'  => $limits_none,
				),
			),
			array(
				'name'        => 'wrong_product',
				'description' => 'Correctly signed, but for another product (pid "other-product"). Rejected.',
				'token'       => $token( array( $base_ok ), array( 'inject' => array( 'pid' => 'other-product' ) ) ),
				'expect'      => array(
					'accepted' => false,
					'why'      => 'pid is not super-ledger',
				),
			),
		);

		// Tampered: the active token with one payload character changed, signature kept.
		$active_token = $cases[0]['token'];
		$tampered     = $active_token;
		$tampered[10] = 'A' === $tampered[10] ? 'B' : 'A';
		array_splice(
			$cases,
			11,
			0,
			array(
				array(
					'name'        => 'tampered',
					'description' => 'The "active" token with one payload character changed. The signature no longer verifies.',
					'token'       => $tampered,
					'expect'      => array(
						'accepted' => false,
						'why'      => 'signature does not verify',
					),
				),
			)
		);

		$cases[]           = array(
			'name'        => 'fleet_notice_valid',
			'description' => 'A fleet notice issued now, extending check-in by 20 days.',
			'token'       => CompactToken::sign( FleetNotice::build( $now + 20 * DAY_IN_SECONDS, $now ), $secret ),
			'expect'      => array( 'accepted' => true ),
		);
		$too_long          = FleetNotice::build( $now + 30 * DAY_IN_SECONDS, $now );
		$too_long['until'] = $now + 31 * DAY_IN_SECONDS; // The CLI refuses this; the fixture proves the app does too.
		$cases[]           = array(
			'name'        => 'fleet_notice_too_long',
			'description' => 'A correctly signed notice claiming 31 days. The app must not stretch check-in past iat + 30 days.',
			'token'       => CompactToken::sign( $too_long, $secret ),
			'expect'      => array(
				'accepted' => false,
				'why'      => 'until is more than 30 days after iat',
			),
		);

		$files    = array( 'public_key.txt' => base64_encode( $public ) . "\n" ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- key and token encoding.
		$manifest = array(
			'warning'         => 'TEST KEYPAIR derived from a public seed. Never trust public_key.txt in a release build.',
			'generated_by'    => 'wp wplm contract-fixtures (WPLM ' . ( defined( 'WPLM_VERSION' ) ? WPLM_VERSION : 'dev' ) . ')',
			'now'             => $now,
			'now_iso'         => gmdate( 'c', $now ),
			'timezone'        => 'Asia/Karachi',
			'today'           => self::TODAY,
			'raw_fingerprint' => self::RAW_FINGERPRINT,
			'fp'              => $fp,
			'grace_days'      => $grace,
			'due_soon_days'   => 3,
			'cases'           => array(),
		);

		foreach ( $cases as $case ) {
			$file           = $case['name'] . '.token';
			$files[ $file ] = $case['token'] . "\n";
			$expect         = $case['expect'] + array(
				'signature' => 'tampered' === $case['name'] ? 'invalid' : 'valid',
				'accepted'  => true,
			);
			ksort( $expect );
			$manifest['cases'][] = array(
				'name'        => $case['name'],
				'file'        => $file,
				'description' => $case['description'],
				'expect'      => $expect,
			);
		}

		$files['cases.json'] = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
		return $files;
	}

	/**
	 * A line from a compact tuple: [kind, code, qty, paid_through].
	 *
	 * @param array $t Tuple.
	 * @return Entitlement
	 */
	private static function line( array $t ): Entitlement {
		return Entitlement::from_row(
			array(
				'kind'         => $t[0],
				'code'         => $t[1],
				'qty'          => $t[2],
				'paid_through' => $t[3],
			)
		);
	}
}
