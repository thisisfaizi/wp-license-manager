<?php
/**
 * Entitlement service — validates, writes and summarises the lines of a profile licence.
 *
 * @package WPLM\Services
 */

namespace WPLM\Services;

use WPLM\Licensing\Profile;
use WPLM\Licensing\ProfileRegistry;
use WPLM\Models\Entitlement;
use WPLM\Models\License;
use WPLM\Models\Package;
use WPLM\Repositories\EntitlementRepository;
use WPLM\Repositories\LicenseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Owns entitlement lines. Every write is checked against the licence's profile, so a line with an
 * unknown code, the wrong kind, or a malformed date is refused instead of silently granting nothing.
 *
 * Dates: `paid_through` is the inclusive last paid **calendar day in the site's time zone**. The
 * server stores UTC datetimes everywhere else; {@see paid_through_for()} is the one conversion.
 */
class EntitlementService {

	/** @var EntitlementRepository */
	private EntitlementRepository $repo;

	/** @var LicenseRepository */
	private LicenseRepository $licenses;

	/** @var ProfileRegistry */
	private ProfileRegistry $profiles;

	/**
	 * @param EntitlementRepository $repo     Line data-access layer.
	 * @param LicenseRepository     $licenses Licence data-access layer.
	 * @param ProfileRegistry       $profiles Known licence profiles.
	 */
	public function __construct( EntitlementRepository $repo, LicenseRepository $licenses, ProfileRegistry $profiles ) {
		$this->repo     = $repo;
		$this->licenses = $licenses;
		$this->profiles = $profiles;
	}

	// -------------------------------------------------------------------------
	// Reads
	// -------------------------------------------------------------------------

	/**
	 * Every line of a licence.
	 *
	 * @param int $license_id Licence id.
	 * @return Entitlement[]
	 */
	public function lines( int $license_id ): array {
		return $this->repo->get_by_license( $license_id );
	}

	/**
	 * What the licence grants on a given day, in the v2 token's shape.
	 *
	 * - `modules[code].until` is the latest `paid_through` among that module's lines, or null when
	 *   any of them is lifetime. Lapsed modules are listed: the app decides what "lapsed" does.
	 * - `limits[code]` sums `qty` over lines that are lifetime or paid through on or after
	 *   `today − grace_days`. Every limit code of the profile is present, 0 when nothing grants it.
	 *
	 * @param License $license    The licence.
	 * @param Profile $profile    Its profile.
	 * @param string  $today      The site-time-zone date to evaluate (Y-m-d).
	 * @param int     $grace_days Grace applied to limit lines.
	 * @return array{modules: array<string, array{until: string|null}>, limits: array<string, int>}
	 */
	public function summarize( License $license, Profile $profile, string $today, int $grace_days ): array {
		$modules = array();
		$limits  = array_fill_keys( $profile->limit_codes, 0 );
		$cutoff  = ( new \DateTimeImmutable( $today, new \DateTimeZone( 'UTC' ) ) )
			->modify( '-' . max( 0, $grace_days ) . ' days' )
			->format( 'Y-m-d' );

		foreach ( $this->repo->get_by_license( $license->id ) as $line ) {
			if ( ! $profile->allows( $line->kind, $line->code ) ) {
				continue; // A code the profile no longer lists grants nothing.
			}

			if ( Entitlement::KIND_MODULE === $line->kind ) {
				if ( ! array_key_exists( $line->code, $modules ) ) {
					$modules[ $line->code ] = $line->paid_through;
				} elseif ( null === $line->paid_through || null === $modules[ $line->code ] ) {
					$modules[ $line->code ] = null;
				} elseif ( strcmp( $line->paid_through, (string) $modules[ $line->code ] ) > 0 ) {
					$modules[ $line->code ] = $line->paid_through;
				}
				continue;
			}

			if ( null === $line->paid_through || strcmp( $line->paid_through, $cutoff ) >= 0 ) {
				$limits[ $line->code ] += $line->qty;
			}
		}

		ksort( $modules );
		$shaped = array();
		foreach ( $modules as $code => $until ) {
			$shaped[ $code ] = array( 'until' => $until );
		}

		return array(
			'modules' => $shaped,
			'limits'  => $limits,
		);
	}

	/**
	 * The inclusive paid-through date for a term that ends at a UTC datetime: the day before the end,
	 * as a calendar date in the site's time zone.
	 *
	 * @param string $utc_end End of the paid term (UTC, Y-m-d H:i:s).
	 * @return string Y-m-d
	 */
	public static function paid_through_for( string $utc_end ): string {
		return ( new \DateTimeImmutable( $utc_end, new \DateTimeZone( 'UTC' ) ) )
			->setTimezone( wp_timezone() )
			->modify( '-1 day' )
			->format( 'Y-m-d' );
	}

	/**
	 * Add whole periods to a calendar date, clamping month and year steps to the last day of a shorter
	 * month (2027-01-31 + 1 month = 2027-02-28, never 2027-03-03).
	 *
	 * @param string $date   Y-m-d.
	 * @param int    $n      Number of periods.
	 * @param string $period day|week|month|year.
	 * @return string Y-m-d
	 */
	public static function add_period( string $date, int $n, string $period ): string {
		$d = new \DateTimeImmutable( $date, new \DateTimeZone( 'UTC' ) );

		if ( 'day' === $period || 'week' === $period ) {
			$days = 'week' === $period ? 7 * $n : $n;
			return $d->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' );
		}

		// Count months from year 0, so negative steps borrow a year correctly.
		$total = (int) $d->format( 'Y' ) * 12 + (int) $d->format( 'n' ) - 1 + ( 'year' === $period ? 12 * $n : $n );
		$year  = intdiv( $total, 12 );
		$month = $total % 12 + 1;
		$last  = (int) ( new \DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), new \DateTimeZone( 'UTC' ) ) )->format( 't' );

		return sprintf( '%04d-%02d-%02d', $year, $month, min( (int) $d->format( 'j' ), $last ) );
	}

	/**
	 * Apply a renewal payment to a subscription's lines and return the subscription's next payment.
	 *
	 * Moves only lines paid by this subscription that have a date; lifetime lines never move. Per line,
	 * with `grace` = the licence profile's grace setting and dates in the site's time zone:
	 * - paid on or before `paid_through + grace` → the new period continues from the old end;
	 * - paid after that (the module had gone read-only) → a full period from today.
	 *
	 * @param int      $subscription_id Subscription that was paid.
	 * @param int      $interval        Billing interval (e.g. 1).
	 * @param string   $period          day|week|month|year.
	 * @param int|null $now             Unix time of the payment (default: now).
	 * @return string|null The next payment (UTC datetime): the start of the day after the latest new
	 *                     paid-through date, in the site's time zone. Null when no dated line moved.
	 */
	public function extend_subscription_lines( int $subscription_id, int $interval, string $period, ?int $now = null ): ?string {
		$now    = $now ?? time();
		$today  = wp_date( 'Y-m-d', $now );
		$latest = null;
		$grace  = array();

		foreach ( $this->repo->get_by_subscription( $subscription_id ) as $line ) {
			if ( null === $line->paid_through ) {
				continue;
			}

			if ( ! array_key_exists( $line->license_id, $grace ) ) {
				$license                    = $this->licenses->find_by_id( $line->license_id );
				$profile                    = null !== $license ? $this->profiles->get( $license->profile ) : null;
				$grace[ $line->license_id ] = null !== $profile ? $profile->grace_days() : null;
			}
			if ( null === $grace[ $line->license_id ] ) {
				continue; // The licence is gone or no longer a profile licence.
			}

			$grace_end = self::add_period( $line->paid_through, $grace[ $line->license_id ], 'day' );
			$base      = strcmp( $today, $grace_end ) <= 0 ? self::add_period( $line->paid_through, 1, 'day' ) : $today;
			$through   = self::add_period( self::add_period( $base, max( 1, $interval ), $period ), -1, 'day' );

			$this->repo->update( $line->id, array( 'paid_through' => $through ) );
			/** This action is documented in EntitlementService::add_line(). */
			do_action( 'wplm_entitlement_saved', $this->repo->find_by_id( $line->id ), 'extended' );

			if ( null === $latest || strcmp( $through, $latest ) > 0 ) {
				$latest = $through;
			}
		}

		if ( null === $latest ) {
			return null;
		}

		return ( new \DateTimeImmutable( self::add_period( $latest, 1, 'day' ) . ' 00:00:00', wp_timezone() ) )
			->setTimezone( new \DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d H:i:s' );
	}

	// -------------------------------------------------------------------------
	// Writes
	// -------------------------------------------------------------------------

	/**
	 * Add a line to a profile licence.
	 *
	 * @param int   $license_id Licence id.
	 * @param array $line       kind, code, qty (limits), paid_through (Y-m-d|null), source, subscription_id.
	 * @return Entitlement
	 * @throws \InvalidArgumentException When the licence has no profile or the line is invalid.
	 */
	public function add_line( int $license_id, array $line ): Entitlement {
		$profile = $this->profile_of( $license_id );
		$data    = $this->normalize( $profile, $line );

		$data['license_id'] = $license_id;
		$created            = $this->repo->find_by_id( $this->repo->create( $data ) );

		/**
		 * Fires after an entitlement line is added or changed.
		 *
		 * @param Entitlement $created The line as stored.
		 * @param string      $op      'added' or 'updated'.
		 */
		do_action( 'wplm_entitlement_saved', $created, 'added' );

		return $created;
	}

	/**
	 * Change a line. Unsupplied fields keep their value; the result is validated as a whole.
	 *
	 * @param int   $id      Line id.
	 * @param array $changes Fields to change.
	 * @return Entitlement
	 * @throws \InvalidArgumentException When the line is missing or the result is invalid.
	 */
	public function update_line( int $id, array $changes ): Entitlement {
		$current = $this->repo->find_by_id( $id );
		if ( null === $current ) {
			throw new \InvalidArgumentException( sprintf( 'Entitlement line %d does not exist.', $id ) );
		}

		unset( $changes['license_id'], $changes['id'] );
		$merged = array_merge( $current->to_array(), $changes );
		$data   = $this->normalize( $this->profile_of( $current->license_id ), $merged );

		$this->repo->update( $id, $data );
		$updated = $this->repo->find_by_id( $id );

		/** This action is documented in EntitlementService::add_line(). */
		do_action( 'wplm_entitlement_saved', $updated, 'updated' );

		return $updated;
	}

	/**
	 * Remove a line.
	 *
	 * @param int $id Line id.
	 * @return bool True when a line was removed.
	 */
	public function delete_line( int $id ): bool {
		$current = $this->repo->find_by_id( $id );
		if ( null === $current || ! $this->repo->delete( $id ) ) {
			return false;
		}

		/**
		 * Fires after an entitlement line is removed.
		 *
		 * @param Entitlement $current The removed line.
		 */
		do_action( 'wplm_entitlement_deleted', $current );

		return true;
	}

	/**
	 * Write a package's entitlement template onto a licence. The whole template is validated before
	 * anything is written, so a bad template writes no lines at all.
	 *
	 * @param License     $license         Profile licence.
	 * @param Package     $package         Purchased package.
	 * @param int|null    $subscription_id Subscription that pays for the lines, if recurring.
	 * @param string|null $paid_through    Inclusive paid-through date; null for lifetime.
	 * @return Entitlement[]
	 * @throws \InvalidArgumentException When the template is invalid for the licence's profile.
	 */
	public function grant_package( License $license, Package $package, ?int $subscription_id, ?string $paid_through ): array {
		$profile = $this->profile_of( $license->id );
		$rows    = array();
		foreach ( $package->entitlements as $template_line ) {
			$rows[] = $this->normalize(
				$profile,
				array_merge(
					(array) $template_line,
					array(
						'paid_through'    => $paid_through,
						'source'          => 'plan',
						'subscription_id' => $subscription_id,
					)
				)
			);
		}

		$granted = array();
		foreach ( $rows as $row ) {
			$row['license_id'] = $license->id;
			$line              = $this->repo->find_by_id( $this->repo->create( $row ) );
			/** This action is documented in EntitlementService::add_line(). */
			do_action( 'wplm_entitlement_saved', $line, 'added' );
			$granted[] = $line;
		}
		return $granted;
	}

	/**
	 * Validate a package template (kind + code + qty per line) against a profile.
	 *
	 * @param Profile $profile  Profile the plan sells.
	 * @param array   $template Raw template lines.
	 * @return array<int, array{kind: string, code: string, qty: int}> Normalised template.
	 * @throws \InvalidArgumentException On any invalid line.
	 */
	public function normalize_template( Profile $profile, array $template ): array {
		$out = array();
		foreach ( $template as $line ) {
			$data  = $this->normalize( $profile, (array) $line );
			$out[] = array(
				'kind' => $data['kind'],
				'code' => $data['code'],
				'qty'  => $data['qty'],
			);
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * The profile of a licence, which must exist and be an entitlement licence.
	 *
	 * @param int $license_id Licence id.
	 * @return Profile
	 * @throws \InvalidArgumentException Otherwise.
	 */
	private function profile_of( int $license_id ): Profile {
		$license = $this->licenses->find_by_id( $license_id );
		if ( null === $license ) {
			throw new \InvalidArgumentException( sprintf( 'Licence %d does not exist.', $license_id ) );
		}
		$profile = $this->profiles->get( $license->profile );
		if ( null === $profile ) {
			throw new \InvalidArgumentException( sprintf( 'Licence %d has no licence profile, so it cannot carry entitlement lines.', $license_id ) );
		}
		return $profile;
	}

	/**
	 * Validate one line into storable columns.
	 *
	 * @param Profile $profile Profile to check codes against.
	 * @param array   $line    Raw line.
	 * @return array{kind: string, code: string, qty: int, paid_through: string|null, source: string, subscription_id: int|null}
	 * @throws \InvalidArgumentException On any invalid field.
	 */
	private function normalize( Profile $profile, array $line ): array {
		$kind = (string) ( $line['kind'] ?? '' );
		$code = (string) ( $line['code'] ?? '' );

		if ( Entitlement::KIND_MODULE !== $kind && Entitlement::KIND_LIMIT !== $kind ) {
			throw new \InvalidArgumentException( sprintf( 'Entitlement kind must be "module" or "limit", got "%s".', $kind ) );
		}
		if ( ! $profile->allows( $kind, $code ) ) {
			$allowed = Entitlement::KIND_MODULE === $kind ? $profile->module_codes : $profile->limit_codes;
			throw new \InvalidArgumentException( sprintf( 'Unknown %1$s code "%2$s" for %3$s. Allowed: %4$s.', $kind, $code, $profile->label, implode( ', ', $allowed ) ) );
		}

		$qty = 0;
		if ( Entitlement::KIND_LIMIT === $kind ) {
			$qty = isset( $line['qty'] ) && is_numeric( $line['qty'] ) ? (int) $line['qty'] : 0;
			if ( $qty < 1 ) {
				throw new \InvalidArgumentException( sprintf( 'A "%s" limit line must add at least 1.', $code ) );
			}
		}

		$paid_through = $line['paid_through'] ?? null;
		if ( '' === $paid_through ) {
			$paid_through = null;
		}
		if ( null !== $paid_through ) {
			$paid_through = (string) $paid_through;
			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $paid_through, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Paid-through must be a date (YYYY-MM-DD), got "%s".', $paid_through ) );
			}
		}

		$source = (string) ( $line['source'] ?? 'manual' );
		if ( ! in_array( $source, Entitlement::SOURCES, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Entitlement source must be one of %1$s, got "%2$s".', implode( ', ', Entitlement::SOURCES ), $source ) );
		}

		$subscription_id = isset( $line['subscription_id'] ) && (int) $line['subscription_id'] > 0 ? (int) $line['subscription_id'] : null;

		return array(
			'kind'            => $kind,
			'code'            => $code,
			'qty'             => $qty,
			'paid_through'    => $paid_through,
			'source'          => $source,
			'subscription_id' => $subscription_id,
		);
	}
}
