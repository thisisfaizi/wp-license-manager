<?php
/**
 * The "will become read-only" email for entitlement licences.
 *
 * @package WPLM\Licensing
 */

namespace WPLM\Licensing;

defined( 'ABSPATH' ) || exit;

use WPLM\Models\License;
use WPLM\Repositories\ActivationLogRepository;
use WPLM\Repositories\EntitlementRepository;
use WPLM\Repositories\LicenseRepository;
use WPLM\Services\EntitlementService;
use WPLM\Support\Logger;

/**
 * Nothing on the server locks an unpaid office (D7): its modules' `until` passes, the app counts the
 * grace days, then turns read-only. This tells the customer the date before it happens.
 *
 * On a module's paid-through day the licence's customer is emailed once, naming the day read-only
 * starts (`paid_through + grace + 1`). A run that was missed catches up while the module is still in
 * grace, and never once read-only has started. Each notice is logged as `lapse_notice` against the
 * licence; a module and date already notified (or found to have no address) is not sent again, and
 * a renewal that moves the date starts a new cycle. A send that fails is retried on the next run.
 */
final class LapseNoticeService {

	/** Activation-log event for a notice. */
	public const EVENT = 'lapse_notice';

	/** Licence statuses that are never emailed: suspended, revoked, terminated. */
	private const SKIP_STATUSES = array( 4, 5, 6 );

	/** @var EntitlementRepository */
	private EntitlementRepository $lines;

	/** @var LicenseRepository */
	private LicenseRepository $licenses;

	/** @var ActivationLogRepository */
	private ActivationLogRepository $log;

	/** @var ProfileRegistry */
	private ProfileRegistry $profiles;

	public function __construct( EntitlementRepository $lines, LicenseRepository $licenses, ActivationLogRepository $log, ProfileRegistry $profiles ) {
		$this->lines    = $lines;
		$this->licenses = $licenses;
		$this->log      = $log;
		$this->profiles = $profiles;
	}

	/**
	 * Send every notice that is due.
	 *
	 * @param int|null $now Unix time (tests); defaults to now.
	 * @return int Emails sent.
	 */
	public function run( ?int $now = null ): int {
		$now   = $now ?? time();
		$today = wp_date( 'Y-m-d', $now );
		$sent  = 0;

		foreach ( $this->profiles->all() as $profile ) {
			$grace = $profile->grace_days();
			$from  = EntitlementService::add_period( $today, -$grace, 'day' );
			foreach ( $this->lines->license_ids_with_modules_through( $profile->code, $from, $today, self::SKIP_STATUSES ) as $license_id ) {
				$sent += $this->notify( $license_id, $profile, $from, $today, $grace );
			}
		}

		return $sent;
	}

	/**
	 * Notify one licence of each paid-through date that is due and not yet notified.
	 *
	 * @return int Emails sent.
	 */
	private function notify( int $license_id, Profile $profile, string $from, string $today, int $grace ): int {
		// The query already chose this profile's licences in a status that is emailed.
		$license = $this->licenses->find_by_id( $license_id );
		if ( null === $license ) {
			return 0;
		}

		// A module's `until` is what its token says: the latest line, or none when any line is lifetime.
		$modules = EntitlementService::summarize_lines( $this->lines->get_by_license( $license_id ), $profile, $today, $grace )['modules'];
		$due     = array();
		foreach ( $modules as $code => $module ) {
			$until = $module['until'];
			if ( null !== $until && strcmp( $until, $from ) >= 0 && strcmp( $until, $today ) <= 0 ) {
				$due[ $until ][] = $code;
			}
		}
		if ( array() === $due ) {
			return 0;
		}

		$handled = $this->handled( $license_id, $from );
		ksort( $due );
		$sent = 0;

		foreach ( $due as $until => $codes ) {
			$codes = array_values( array_filter( $codes, static fn( $code ) => ! isset( $handled[ $until . '|' . $code ] ) ) );
			if ( array() === $codes ) {
				continue;
			}

			$read_only_on = EntitlementService::add_period( $until, $grace + 1, 'day' );
			$meta         = array(
				'until'        => $until,
				'modules'      => $codes,
				'read_only_on' => $read_only_on,
			);

			$to = $this->recipient( $license );
			if ( '' === $to ) {
				$this->record( $license_id, 'no_recipient', $meta );
				Logger::error( sprintf( 'WPLM: licence %d has no customer email; its read-only notice for %s was not sent.', $license_id, $until ) );
				continue;
			}

			if ( ! $this->send( $to, $license, $profile, $codes, $until, $read_only_on, $grace ) ) {
				Logger::error( sprintf( 'WPLM: the read-only notice for licence %d (%s) could not be sent; it is retried on the next run.', $license_id, $until ) );
				continue;
			}

			$this->record( $license_id, 'success', $meta + array( 'to' => $to ) );
			++$sent;

			/**
			 * A "will become read-only" notice was emailed.
			 *
			 * @param License  $license      The licence.
			 * @param string[] $codes        Module codes it names.
			 * @param string   $read_only_on First read-only day (Y-m-d, site time zone).
			 */
			do_action( 'wplm_lapse_notice_sent', $license, $codes, $read_only_on );
		}

		return $sent;
	}

	/**
	 * `until|code` pairs already notified, or found to have no address, since a date.
	 *
	 * @return array<string, true>
	 */
	private function handled( int $license_id, string $since_date ): array {
		$since   = ( new \DateTimeImmutable( $since_date . ' 00:00:00', wp_timezone() ) )->getTimestamp();
		$handled = array();
		foreach ( $this->log->events_with_meta_since( $license_id, self::EVENT, $since ) as $event ) {
			if ( ! in_array( $event['result'], array( 'success', 'no_recipient' ), true ) ) {
				continue;
			}
			foreach ( (array) ( $event['meta']['modules'] ?? array() ) as $code ) {
				$handled[ ( $event['meta']['until'] ?? '' ) . '|' . $code ] = true;
			}
		}
		return $handled;
	}

	/** Log a notice against the licence. */
	private function record( int $license_id, string $result, array $meta ): void {
		$this->log->create(
			array(
				'license_id' => $license_id,
				'event'      => self::EVENT,
				'result'     => $result,
				'meta'       => $meta,
			)
		);
	}

	/** The customer's address: the purchase order's billing email, else the licence owner's account email. */
	private function recipient( License $license ): string {
		$to = '';
		if ( $license->order_id && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $license->order_id );
			if ( $order instanceof \WC_Order ) {
				$to = (string) $order->get_billing_email();
			}
		}
		if ( '' === $to && $license->user_id ) {
			$user = get_userdata( $license->user_id );
			$to   = $user ? (string) $user->user_email : '';
		}

		/**
		 * Filter the address a read-only notice is sent to ('' sends nothing).
		 *
		 * @param string  $to      Resolved address.
		 * @param License $license The licence.
		 */
		$to = (string) apply_filters( 'wplm_lapse_notice_recipient', $to, $license );
		return is_email( $to ) ? $to : '';
	}

	/**
	 * Render and send one notice.
	 *
	 * @param string[] $codes Module codes the notice names.
	 */
	private function send( string $to, License $license, Profile $profile, array $codes, string $until, string $read_only_on, int $grace ): bool {
		$whole_office = in_array( 'base', $codes, true );
		$module_names = array_map( array( $profile, 'code_label' ), $codes );
		$read_only    = $this->format_date( $read_only_on );

		$subject = $whole_office
			/* translators: 1: product name, 2: date */
			? sprintf( __( 'Your %1$s will become read-only on %2$s', 'wp-license-manager' ), $profile->label, $read_only )
			/* translators: 1: module names, 2: product name, 3: date */
			: sprintf( __( '%1$s in your %2$s will become read-only on %3$s', 'wp-license-manager' ), wp_sprintf_l( '%l', $module_names ), $profile->label, $read_only );

		$vars = array(
			'email_heading'    => $subject,
			'license'          => $license,
			'product_name'     => $profile->label,
			'whole_office'     => $whole_office,
			'module_names'     => $module_names,
			'last_working_day' => $this->format_date( EntitlementService::add_period( $until, $grace, 'day' ) ),
			'read_only_on'     => $read_only,
			'account_url'      => function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'wplm-subscriptions' ) : '',
		);

		$content = $this->render( $vars );
		if ( null === $content ) {
			return false;
		}

		if ( function_exists( 'WC' ) && WC() ) {
			$mailer = WC()->mailer();
			return (bool) $mailer->send( $to, $subject, $mailer->wrap_message( $subject, $content ) );
		}
		return (bool) wp_mail( $to, $subject, $content, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/** Render the template, or null when it fails. */
	private function render( array $vars ): ?string {
		$path = WPLM_PLUGIN_DIR . 'templates/emails/read-only-soon.php';
		try {
			ob_start();
			( static function ( string $wplm_template, array $wplm_vars ): void {
				extract( $wplm_vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template locals.
				include $wplm_template;
			} )( $path, $vars );
			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			Logger::error( 'WPLM: the read-only notice template failed — ' . $e->getMessage() );
			return null;
		}
	}

	/** A Y-m-d site-time-zone date in the site's date format. */
	private function format_date( string $date ): string {
		$noon = new \DateTimeImmutable( $date . ' 12:00:00', wp_timezone() );
		return wp_date( (string) get_option( 'date_format' ), $noon->getTimestamp() );
	}
}
