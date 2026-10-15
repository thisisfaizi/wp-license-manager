<?php
/**
 * Renewal processor — WP-Cron driven automatic rebilling for the native
 * subscription engine.
 *
 * @package WPLM\Services\Subscriptions
 */

namespace WPLM\Services\Subscriptions;

use WPLM\Models\Renewal;
use WPLM\Models\Subscription;
use WPLM\Repositories\RenewalRepository;
use WPLM\Repositories\SubscriptionRepository;
use WPLM\Services\EntitlementService;
use WPLM\Services\LicenseService;
use WPLM\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Processes all subscriptions that are due for renewal.
 *
 * Called from Cron\Scheduler on the `wplm_process_renewals` hook (hourly).
 * Each subscription whose next_payment ≤ now (UTC_TIMESTAMP(), whatever the MySQL time zone) and status ∈ {active, trial}
 * is processed individually in a try/catch so one failure does not block the
 * rest of the batch.
 */
class RenewalProcessor {

	/** Order meta: what a renewal payment moved (JSON), written by apply_payment(). */
	public const SNAPSHOT_META = '_wplm_renewal_snapshot';

	/** Order meta: a refunded renewal taken back (JSON: `at` UTC, `by` user id). */
	public const ROLLED_BACK_META = '_wplm_renewal_rolled_back';

	/** Option name prefix of the claim one subscription's rollback holds ({@see \WPLM\Support\Lock}). */
	public const LOCK_PREFIX = 'wplm_renewal_lock_';

	/** Seconds after which a rollback's claim is taken to be from a request that died. */
	private const LOCK_TTL = 300;

	/** @var SubscriptionRepository */
	private SubscriptionRepository $sub_repo;

	/** @var RenewalRepository */
	private RenewalRepository $renewal_repo;

	/** @var GatewayBridge */
	private GatewayBridge $gateway;

	/** @var DunningManager */
	private DunningManager $dunning;

	/** @var LicenseService */
	private LicenseService $license_service;

	/** @var BillingScheduler */
	private BillingScheduler $scheduler;

	/** @var SubscriptionService */
	private SubscriptionService $sub_service;

	/** @var EntitlementService */
	private EntitlementService $entitlements;

	/**
	 * @param SubscriptionRepository $sub_repo        Subscription data-access layer.
	 * @param RenewalRepository      $renewal_repo    Renewal data-access layer.
	 * @param GatewayBridge          $gateway         Payment gateway abstraction.
	 * @param DunningManager         $dunning         Failed-payment retry handler.
	 * @param LicenseService         $license_service License lifecycle service.
	 * @param BillingScheduler       $scheduler       Next-payment date computation.
	 * @param SubscriptionService    $sub_service     Subscription lifecycle service.
	 * @param EntitlementService     $entitlements    Entitlement lines (profile licences).
	 */
	public function __construct(
		SubscriptionRepository $sub_repo,
		RenewalRepository $renewal_repo,
		GatewayBridge $gateway,
		DunningManager $dunning,
		LicenseService $license_service,
		BillingScheduler $scheduler,
		SubscriptionService $sub_service,
		EntitlementService $entitlements
	) {
		$this->entitlements    = $entitlements;
		$this->sub_repo        = $sub_repo;
		$this->renewal_repo    = $renewal_repo;
		$this->gateway         = $gateway;
		$this->dunning         = $dunning;
		$this->license_service = $license_service;
		$this->scheduler       = $scheduler;
		$this->sub_service     = $sub_service;
	}

	// -------------------------------------------------------------------------
	// Batch processing
	// -------------------------------------------------------------------------

	/**
	 * Query and process all subscriptions that are due for renewal right now.
	 *
	 * Each subscription is wrapped in an individual try/catch so an error on
	 * one subscription does not prevent others from being processed.
	 *
	 * @return void
	 */
	public function process_due_renewals(): void {
		$subscriptions = $this->sub_repo->get_due_for_renewal();

		foreach ( $subscriptions as $sub ) {
			try {
				$this->process_single( $sub );
			} catch ( \Throwable $e ) {
				// Log silently so the batch continues; the WP admin will see
				// the failed_attempts counter on the subscription screen.
				Logger::error(
					sprintf(
						'RenewalProcessor: unhandled exception for subscription %d — %s',
						$sub->id,
						$e->getMessage()
					)
				);
			}
		}
	}

	// -------------------------------------------------------------------------
	// Single-subscription processing
	// -------------------------------------------------------------------------

	/**
	 * Process a single due subscription.
	 *
	 * Flow:
	 *   1. No payment token → the customer pays by hand: fire the manual-renewal action
	 *      (the WooCommerce integration issues and emails one renewal invoice per cycle).
	 *   2. Token present → charge via GatewayBridge.
	 *   3. Success → record the renewal and apply the payment (see apply_payment()).
	 *   4. Failure → hand off to DunningManager.
	 *
	 * @param Subscription $sub Subscription to process.
	 * @return void
	 */
	public function process_single( Subscription $sub ): void {
		// No token stored — fall back to manual invoice flow.
		if ( null === $sub->payment_token_id ) {
			/**
			 * Fires when a subscription with no stored payment token is due, or when every card
			 * retry has failed. Fires on every cron run while the cycle is unpaid; listeners must
			 * be idempotent per cycle.
			 *
			 * @param Subscription $sub The subscription awaiting manual renewal.
			 */
			do_action( 'wplm_subscription_manual_renewal_due', $sub );
			return;
		}

		// Attempt the automatic charge.
		$result = $this->gateway->charge(
			$sub->payment_token_id,
			$sub->recurring_total,
			$sub->currency,
			$sub->id
		);

		if ( is_wp_error( $result ) ) {
			// Delegate to dunning so the retry schedule is applied.
			$this->dunning->handle_failure( $sub );
			return;
		}

		// --- Success path ---

		/**
		 * Filter: create a WooCommerce renewal order for this subscription charge.
		 *
		 * @param int          $order_id Default 0 (no WC order created).
		 * @param Subscription $sub      The subscription being renewed.
		 */
		$order_id = (int) apply_filters( 'wplm_create_renewal_order', 0, $sub );

		$renewal = $this->apply_payment( $sub, $order_id > 0 ? $order_id : null, $sub->recurring_total, (string) ( $result['txn'] ?? '' ) );

		/**
		 * Fires after a subscription renewal charge is successfully processed.
		 *
		 * @param Subscription $sub     The renewed subscription.
		 * @param Renewal      $renewal The renewal record.
		 */
		do_action( 'wplm_subscription_renewed', $sub, $renewal );
	}

	/**
	 * Apply a confirmed renewal payment: extend what was paid for by one period, align next_payment
	 * with the new paid-through date, clear dunning, bring an on-hold subscription back to active, and
	 * record the renewal.
	 *
	 * What is extended: a classic licence's expiry (LicenseService::extend_term()), or — for an
	 * entitlement licence, which has no expiry — the subscription's dated entitlement lines
	 * (EntitlementService::extend_subscription_lines()). Both use the same grace rule.
	 *
	 * Shared by the cron card charge and paid renewal invoices, so both produce identical state.
	 *
	 * With an order, what the payment moved is kept on it (`_wplm_renewal_snapshot`, written before
	 * anything moves and completed after), so a full refund of that order takes back exactly this period
	 * ({@see roll_back()}); the order is stamped `_wplm_renewal_applied`, and a rollback stamp left by an
	 * earlier refund of it is cleared (completing a refunded renewal again applies it again).
	 *
	 * @param Subscription $sub      The subscription being paid.
	 * @param int|null     $order_id WooCommerce order that carried the payment, if any.
	 * @param float        $amount   Amount received.
	 * @param string       $txn      Gateway transaction reference.
	 * @param int|null     $now      Unix time of the payment (default: now).
	 * @return Renewal The recorded renewal.
	 */
	public function apply_payment( Subscription $sub, ?int $order_id, float $amount, string $txn = '', ?int $now = null ): Renewal {
		$now      = $now ?? time();
		$now_utc  = gmdate( 'Y-m-d H:i:s', $now );
		$interval = $this->scheduler->period_to_interval( $sub->billing_interval, $sub->billing_period );

		// What the payment is about to move, kept on its order before anything moves.
		$order    = null !== $order_id && $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$order    = $order instanceof \WC_Order ? $order : null;
		$snapshot = array(
			'subscription_id' => $sub->id,
			'interval'        => $sub->billing_interval,
			'period'          => $sub->billing_period,
			'before'          => self::state_of( $this->sub_repo->find_by_id( $sub->id ) ?? $sub ),
		);
		if ( null !== $order ) {
			$order->update_meta_data( self::SNAPSHOT_META, wp_json_encode( $snapshot ) );
			$order->update_meta_data( '_wplm_renewal_applied', '1' );
			$order->delete_meta_data( self::ROLLED_BACK_META );
			$order->save();
		}

		$next = null;
		// An add-ons-only subscription extends only its own lines, never the licence (1.3.0).
		if ( null !== $sub->license_id && ! $sub->is_addons_only() ) {
			$license = $this->license_service->get_by_id( $sub->license_id );
			$next    = $this->license_service->extend_term( $sub->license_id, $interval, $now );
			if ( null !== $next && null !== $license ) {
				$snapshot['license'] = array(
					'id'   => $sub->license_id,
					'from' => $license->expires_at,
					'to'   => $next,
				);
			}
		}
		if ( null === $next ) {
			$moved             = array();
			$next              = $this->entitlements->extend_subscription_lines( $sub->id, $sub->billing_interval, $sub->billing_period, $now, $moved );
			$snapshot['lines'] = $moved;
		}
		if ( null === $next ) {
			$next = $this->scheduler->next_payment( $sub, $now_utc );
		}

		$this->sub_repo->update(
			$sub->id,
			array(
				'last_payment'    => $now_utc,
				'next_payment'    => $next,
				'failed_attempts' => 0,
			)
		);

		if ( ! in_array( $sub->status, array( 'active', 'trial' ), true ) ) {
			$this->sub_service->update_status( $sub->id, 'active' );
		}

		$renewal_id = $this->renewal_repo->create(
			array(
				'subscription_id' => $sub->id,
				'order_id'        => $order_id,
				'type'            => 'renewal',
				'amount'          => $amount,
				'status'          => 'success',
				'gateway_txn'     => $txn,
				'scheduled_for'   => $sub->next_payment ?? $now_utc,
				'processed_at'    => $now_utc,
				'created_at'      => $now_utc,
			)
		);

		if ( null !== $order ) {
			$snapshot['after']      = self::state_of( $this->sub_repo->find_by_id( $sub->id ) ?? $sub );
			$snapshot['renewal_id'] = (int) $renewal_id;
			$order->update_meta_data( self::SNAPSHOT_META, wp_json_encode( $snapshot ) );
			$order->save();
		}

		$renewal                  = new Renewal();
		$renewal->id              = (int) $renewal_id;
		$renewal->subscription_id = $sub->id;
		$renewal->order_id        = $order_id;
		$renewal->amount          = $amount;
		$renewal->status          = 'success';

		return $renewal;
	}

	// -------------------------------------------------------------------------
	// A refunded renewal
	// -------------------------------------------------------------------------

	/**
	 * A full refund of a renewal order takes its period back (`woocommerce_order_status_refunded`, run
	 * before the add-ons' listeners at 20). Only for an order whose payment was applied
	 * (`_wplm_renewal_applied`) — a renewal invoice (`_wplm_is_renewal`) or an order a card renewal
	 * carried (`_wplm_renewal_snapshot`) — and not rolled back yet (`_wplm_renewal_rolled_back`).
	 *
	 * One rollback per subscription at a time (the claim `wplm_renewal_lock_{subscription id}`); the order
	 * is read again inside it and stamped `_wplm_renewal_rolled_back` (`at`, `by`), losing
	 * `_wplm_renewal_applied`, before anything moves, so a second run — another request, or a listener
	 * re-entering — finds it done. Completing the order again applies the payment again.
	 *
	 * From the snapshot taken at payment ({@see apply_payment()}), each line's paid-through date and a
	 * classic licence's expiry go back by what the renewal moved them ({@see EntitlementService::taken_back()},
	 * {@see LicenseService::expiry_taken_back()}): to where they were while they still carry what it set;
	 * by exactly one billing period while later renewals continued from it; not at all when a later
	 * renewal started afresh after this period had ended (the period is over) or the date is already
	 * earlier. A later renewal's own snapshot is then moved back the same way, so refunding it later still
	 * takes back exactly its own period, whatever order the refunds come in.
	 *
	 * The subscription is read again before it is written. While no later renewal was paid, its next
	 * payment, last payment and failed attempts come back as they were; else its next payment follows the
	 * dates moved back, as a payment sets it (the start of the day after the latest line, or the licence's
	 * expiry). When this was the latest renewal, an `active` or `trial` subscription is put on hold
	 * ({@see SubscriptionService::update_status()}): the cron neither charges a saved card nor invoices an
	 * on-hold subscription, so nothing is billed again until the owner resumes it (or the lines lapse after
	 * grace). Any other status is left as it is. When this was the latest renewal and a classic licence now
	 * ends before its grace is over, it is marked expired as validation would
	 * ({@see LicenseService::validate()}). An add-ons-only subscription moves only its own lines; a
	 * licence's status is never changed for it.
	 *
	 * Only the lines the renewal moved (its snapshot) go back, and the subscription's next payment follows
	 * them alone. A dated line that joined the subscription after the renewal (an add-on bought since,
	 * co-termed with the renewed period) is not in the snapshot and keeps the period it was bought for;
	 * the note names it ("Kept: Factory, added by order #N"). The next renewal does not charge for it
	 * while it is still paid through the period that renewal buys ({@see items_paid_ahead()}), so the
	 * lines come back in step. Nothing moves, and the order is not stamped, when applying the renewal was
	 * cut off part-way; the order says so.
	 *
	 * The renewal (and invoice) rows become `refunded` and the order is noted. Fires
	 * `wplm_entitlement_saved` (`rolled_back`) per line, `wplm_license_term_extended` with the earlier
	 * expiry, then `wplm_renewal_rolled_back`.
	 *
	 * A renewal paid before 1.3.0 has no snapshot: it is shifted back one billing period from the cycle it
	 * renewed (`_wplm_renewal_cycle`), only while the subscription's next payment is still what that
	 * renewal produced; otherwise the order is noted and nothing changes.
	 *
	 * @param \WC_Order $order The refunded order.
	 * @param int|null  $now   Unix time (tests).
	 * @return bool Whether anything was rolled back.
	 */
	public function roll_back( \WC_Order $order, ?int $now = null ): bool {
		$order = self::fresh( $order );
		if ( ! $this->rollable( $order ) ) {
			return false;
		}
		$snapshot = json_decode( (string) $order->get_meta( self::SNAPSHOT_META ), true );
		$sub_id   = is_array( $snapshot ) ? (int) ( $snapshot['subscription_id'] ?? 0 ) : (int) $order->get_meta( '_wplm_subscription_id' );
		$lock     = self::LOCK_PREFIX . ( $sub_id > 0 ? (string) $sub_id : 'order_' . $order->get_id() );
		if ( ! \WPLM\Support\Lock::claim( $lock, self::LOCK_TTL ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %d: subscription id */
					__( 'WPLM: renewal refunded while another change to subscription #%d was being made, so its period was not taken back. Set the order to Completed (nothing is extended again), then to Refunded again.', 'wp-license-manager' ),
					$sub_id
				)
			);
			return false;
		}
		try {
			$order = self::fresh( $order );
			if ( ! $this->rollable( $order ) ) {
				return false;
			}
			$snapshot = json_decode( (string) $order->get_meta( self::SNAPSHOT_META ), true );
			return is_array( $snapshot ) ? $this->roll_back_snapshot( $order, $snapshot, $now ) : $this->roll_back_legacy( $order, $now );
		} finally {
			\WPLM\Support\Lock::release( $lock );
		}
	}

	/**
	 * {@see roll_back()} from the snapshot the payment kept, the claim held.
	 *
	 * @param \WC_Order $order    The refunded order.
	 * @param array     $snapshot What the renewal moved.
	 * @param int|null  $now      Unix time (tests).
	 * @return bool
	 */
	private function roll_back_snapshot( \WC_Order $order, array $snapshot, ?int $now ): bool {
		if ( ! isset( $snapshot['after'] ) || ! is_array( $snapshot['after'] ) || ! is_array( $snapshot['before'] ?? null ) ) {
			$order->add_order_note( __( 'WPLM: renewal refunded, but applying it was cut off part-way, so WPLM cannot tell what it moved: nothing was changed. Check the subscription\'s dates by hand.', 'wp-license-manager' ) );
			return false;
		}

		$sub_id = (int) ( $snapshot['subscription_id'] ?? 0 );
		$sub    = $sub_id > 0 ? $this->sub_repo->find_by_id( $sub_id ) : null;
		$n      = (int) ( $snapshot['interval'] ?? 0 );
		$period = (string) ( $snapshot['period'] ?? '' );
		$spec   = $n > 0 && in_array( $period, array( 'day', 'week', 'month', 'year' ), true ) ? $this->scheduler->period_to_interval( $n, $period ) : '';
		$moves  = is_array( $snapshot['lines'] ?? null ) ? $snapshot['lines'] : array();

		// Lines that joined after the renewal keep the period they were bought for: named in the note.
		$kept = null !== $sub ? $this->joined_since( $sub_id, $moves ) : array();

		$renewal_id = (int) ( $snapshot['renewal_id'] ?? 0 );
		$latest     = null !== $sub && ! $this->renewal_repo->has_later_payment( $sub_id, $renewal_id );
		$mark       = null !== $sub ? $this->renewal_repo->latest_id( $sub_id ) : 0;
		$later      = null !== $sub && ! $latest ? $this->renewal_repo->later_renewal_orders( $sub_id, $renewal_id ) : array();

		$this->stamp_rolled_back( $order, $now );

		$lines   = array();
		$skipped = 0;
		$over    = 0;
		foreach ( $moves as $line_id => $move ) {
			$why  = null;
			$back = is_array( $move ) && isset( $move['from'], $move['to'] ) ? $this->entitlements->roll_back_line( (int) $line_id, (string) $move['from'], (string) $move['to'], $n, $period, $why, $order->get_id() ) : null;
			if ( null !== $back ) {
				$lines[ (int) $line_id ] = $back;
			} elseif ( 'over' === $why ) {
				++$over;
			} else {
				++$skipped;
			}
		}

		$license = null;
		if ( is_array( $snapshot['license'] ?? null ) && isset( $snapshot['license']['id'], $snapshot['license']['to'] ) ) {
			$why     = null;
			$license = $this->license_service->roll_back_term( (int) $snapshot['license']['id'], null !== ( $snapshot['license']['from'] ?? null ) ? (string) $snapshot['license']['from'] : null, (string) $snapshot['license']['to'], $spec, $why );
			if ( null === $license && 'over' === $why ) {
				++$over;
			}
		}

		$this->rebase_later( $later, $order->get_id(), $snapshot, $n, $period, $spec, $now );

		$next = null;
		if ( null !== $sub ) {
			// Read again: a payment may have been applied while the dates moved (a listener).
			$fresh  = $this->sub_repo->find_by_id( $sub_id ) ?? $sub;
			$latest = $latest && ! $this->renewal_repo->has_later_payment( $sub_id, $mark );
			$update = array();
			$before = $snapshot['before']['next_payment'] ?? null;
			$after  = $snapshot['after']['next_payment'] ?? null;
			if ( $latest && null !== $fresh->next_payment && null !== $before && null !== $after
				&& strtotime( (string) $after . ' UTC' ) > strtotime( (string) $before . ' UTC' )
				&& strtotime( $fresh->next_payment . ' UTC' ) === strtotime( (string) $after . ' UTC' ) ) {
				$update['next_payment'] = (string) $before;
			} elseif ( array() !== $lines || null !== $license ) {
				$derived = $this->next_payment_now( $sub_id, $snapshot, array_map( 'intval', array_keys( $moves ) ) );
				if ( null !== $derived && $derived !== $fresh->next_payment ) {
					$update['next_payment'] = $derived;
				}
			}
			if ( $latest ) {
				$update['last_payment']    = $snapshot['before']['last_payment'] ?? null;
				$update['failed_attempts'] = (int) ( $snapshot['before']['failed_attempts'] ?? 0 );
			}
			if ( array() !== $update ) {
				$this->sub_repo->update( $sub_id, $update );
			}
			$next = array_key_exists( 'next_payment', $update ) ? $update['next_payment'] : $fresh->next_payment;

			// The latest renewal of a classic licence, now over with its grace: what validation would do.
			if ( $latest && null !== $license && ! $sub->is_addons_only() ) {
				$this->expire_if_lapsed( (int) $snapshot['license']['id'] );
			}
		}

		$this->finish_roll_back( $order, $sub_id, $snapshot, $sub, $lines, $license, $next, $skipped, $over, $latest, $kept );
		return true;
	}

	/**
	 * `woocommerce_order_partially_refunded` on a renewal order: the period stays, and the order says so.
	 *
	 * @param \WC_Order $order The order.
	 * @return bool Whether it was a renewal (and noted).
	 */
	public function note_partial_refund( \WC_Order $order ): bool {
		$order = self::fresh( $order );
		if ( ! $this->rollable( $order ) ) {
			return false;
		}
		$order->add_order_note( __( 'WPLM: partial refund — the renewal period is unchanged; refund in full to take it back.', 'wp-license-manager' ) );
		return true;
	}

	/**
	 * The order as stored now.
	 *
	 * @param \WC_Order $order The order.
	 * @return \WC_Order
	 */
	private static function fresh( \WC_Order $order ): \WC_Order {
		$fresh = wc_get_order( $order->get_id() );
		return $fresh instanceof \WC_Order ? $fresh : $order;
	}

	/**
	 * Whether an order carries a renewal payment that was applied and not rolled back yet.
	 *
	 * @param \WC_Order $order The order.
	 * @return bool
	 */
	private function rollable( \WC_Order $order ): bool {
		$renewal = '1' === (string) $order->get_meta( '_wplm_is_renewal' ) || '' !== (string) $order->get_meta( self::SNAPSHOT_META );
		return $renewal && '1' === (string) $order->get_meta( '_wplm_renewal_applied' ) && '' === (string) $order->get_meta( self::ROLLED_BACK_META );
	}

	/**
	 * The order taken as rolled back — before anything moves, so a second run finds it done.
	 *
	 * @param \WC_Order $order The refunded order.
	 * @param int|null  $now   Unix time (tests).
	 * @return void
	 */
	private function stamp_rolled_back( \WC_Order $order, ?int $now ): void {
		$order->update_meta_data(
			self::ROLLED_BACK_META,
			wp_json_encode(
				array(
					'at' => gmdate( 'Y-m-d H:i:s', $now ?? time() ),
					'by' => get_current_user_id(),
				)
			)
		);
		$order->delete_meta_data( '_wplm_renewal_applied' );
		$order->save();
	}

	/**
	 * A subscription's dated lines a renewal did not move, paid past the earliest date its own lines go
	 * back to: lines that joined it after the renewal (co-termed with it). A rollback leaves them as they
	 * are — they keep the period they were bought for — and names them in its note.
	 *
	 * @param int   $subscription_id The subscription.
	 * @param array $moves           What the renewal moved: line id => from / to.
	 * @return array<int, \WPLM\Models\Entitlement> Line id => line.
	 */
	private function joined_since( int $subscription_id, array $moves ): array {
		$froms = array();
		foreach ( $moves as $move ) {
			if ( is_array( $move ) && isset( $move['from'] ) ) {
				$froms[] = (string) $move['from'];
			}
		}
		if ( array() === $froms ) {
			return array();
		}
		$back   = min( $froms );
		$joined = array();
		foreach ( $this->entitlements->subscription_lines( $subscription_id ) as $line ) {
			if ( null !== $line->paid_through && ! array_key_exists( $line->id, $moves ) && ! array_key_exists( (string) $line->id, $moves ) && strcmp( $line->paid_through, $back ) > 0 ) {
				$joined[ (int) $line->id ] = $line;
			}
		}
		return $joined;
	}

	/**
	 * Move the snapshots of the renewals paid after a refunded one back the way the refund moved the
	 * dates ({@see EntitlementService::taken_back()}, {@see LicenseService::expiry_taken_back()},
	 * {@see next_payment_taken_back()}), so each still names what it moved and a later refund of it takes
	 * back exactly its own period. Each moved snapshot keeps a record of it (`rebased`: order, at).
	 *
	 * @param int[]    $order_ids Orders of the later renewals.
	 * @param int      $refunded  The refunded order.
	 * @param array    $snapshot  What the refunded renewal moved.
	 * @param int      $n         Its billing interval.
	 * @param string   $period    Its billing period.
	 * @param string   $spec      Its interval spec ('' when unknown).
	 * @param int|null $now       Unix time (tests).
	 * @return void
	 */
	private function rebase_later( array $order_ids, int $refunded, array $snapshot, int $n, string $period, string $spec, ?int $now ): void {
		$moves   = is_array( $snapshot['lines'] ?? null ) ? $snapshot['lines'] : array();
		$license = is_array( $snapshot['license'] ?? null ) ? $snapshot['license'] : null;
		$classic = null !== $license;
		foreach ( $order_ids as $order_id ) {
			$order = $order_id !== $refunded ? wc_get_order( $order_id ) : null;
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$later = json_decode( (string) $order->get_meta( self::SNAPSHOT_META ), true );
			if ( ! is_array( $later ) ) {
				continue;
			}
			$was = wp_json_encode( $later );

			foreach ( $moves as $line_id => $move ) {
				if ( ! is_array( $move ) || ! isset( $move['from'], $move['to'], $later['lines'][ $line_id ] ) || ! is_array( $later['lines'][ $line_id ] ) ) {
					continue;
				}
				foreach ( array( 'from', 'to' ) as $key ) {
					if ( isset( $later['lines'][ $line_id ][ $key ] ) ) {
						$back = EntitlementService::taken_back( (string) $later['lines'][ $line_id ][ $key ], (string) $move['from'], (string) $move['to'], $n, $period );
						if ( null !== $back ) {
							$later['lines'][ $line_id ][ $key ] = $back;
						}
					}
				}
			}

			if ( null !== $license && isset( $license['id'], $license['to'], $later['license']['id'] ) && (int) $later['license']['id'] === (int) $license['id'] ) {
				foreach ( array( 'from', 'to' ) as $key ) {
					if ( isset( $later['license'][ $key ] ) ) {
						$back = LicenseService::expiry_taken_back( (string) $later['license'][ $key ], null !== ( $license['from'] ?? null ) ? (string) $license['from'] : null, (string) $license['to'], $spec );
						if ( false !== $back ) {
							$later['license'][ $key ] = $back;
						}
					}
				}
			}

			$before = $snapshot['before']['next_payment'] ?? null;
			$after  = $snapshot['after']['next_payment'] ?? null;
			if ( null !== $before && null !== $after ) {
				foreach ( array( 'before', 'after' ) as $key ) {
					if ( isset( $later[ $key ]['next_payment'] ) ) {
						$back = $this->next_payment_taken_back( (string) $later[ $key ]['next_payment'], (string) $before, (string) $after, $classic, $n, $period, $spec );
						if ( null !== $back ) {
							$later[ $key ]['next_payment'] = $back;
						}
					}
				}
			}

			if ( wp_json_encode( $later ) === $was ) {
				continue;
			}
			$later['rebased']   = is_array( $later['rebased'] ?? null ) ? $later['rebased'] : array();
			$later['rebased'][] = array(
				'order' => $refunded,
				'at'    => gmdate( 'Y-m-d H:i:s', $now ?? time() ),
			);
			$order->update_meta_data( self::SNAPSHOT_META, wp_json_encode( $later ) );
			$order->save();
		}
	}

	/**
	 * A renewal order paid before 1.3.0 (no snapshot): shift back one billing period from the cycle it
	 * renewed, only while the subscription's next payment is still exactly what that renewal produced —
	 * paid inside grace, from `_wplm_renewal_cycle` — so nothing a later payment moved is touched. Else
	 * the order is noted and nothing changes.
	 *
	 * @param \WC_Order $order The refunded order.
	 * @param int|null  $now   Unix time (tests).
	 * @return bool
	 */
	private function roll_back_legacy( \WC_Order $order, ?int $now ): bool {
		$sub_id = (int) $order->get_meta( '_wplm_subscription_id' );
		$sub    = $sub_id > 0 ? $this->sub_repo->find_by_id( $sub_id ) : null;
		$cycle  = (string) $order->get_meta( '_wplm_renewal_cycle' );
		if ( null === $sub || '' === $cycle || null === $sub->next_payment || false === strtotime( $cycle . ' UTC' ) ) {
			$order->add_order_note( __( 'WPLM: renewal refunded. It was paid before WPLM kept a record of what a renewal moved, and its subscription or cycle can no longer be read: nothing was changed. Check the subscription\'s dates by hand.', 'wp-license-manager' ) );
			return false;
		}

		$license = null !== $sub->license_id && ! $sub->is_addons_only() ? $this->license_service->get_by_id( $sub->license_id ) : null;
		$classic = null !== $license && null === $license->profile;
		if ( $classic ) {
			// LicenseService::extend_term() from the old end, paid inside grace.
			$produced    = ( new \DateTimeImmutable( $cycle, new \DateTimeZone( 'UTC' ) ) )
				->add( new \DateInterval( $this->scheduler->period_to_interval( $sub->billing_interval, $sub->billing_period ) ) )
				->format( 'Y-m-d H:i:s' );
			$old_through = null;
			$new_through = null;
		} else {
			// EntitlementService::extend_subscription_lines() from the old paid-through date, paid inside grace.
			$old_through = EntitlementService::paid_through_for( $cycle );
			$new_through = EntitlementService::renewed_paid_through( $old_through, $old_through, 0, $sub->billing_interval, $sub->billing_period );
			$produced    = ( new \DateTimeImmutable( EntitlementService::add_period( $new_through, 1, 'day' ) . ' 00:00:00', wp_timezone() ) )
				->setTimezone( new \DateTimeZone( 'UTC' ) )
				->format( 'Y-m-d H:i:s' );
		}
		if ( strtotime( $sub->next_payment . ' UTC' ) !== strtotime( $produced . ' UTC' ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: subscription id, 2: its next payment (UTC) */
					__( 'WPLM: renewal refunded. It was paid before WPLM kept a record of what a renewal moved, and subscription #%1$d has moved since (next payment %2$s UTC): nothing was changed. Check its dates by hand.', 'wp-license-manager' ),
					$sub->id,
					$sub->next_payment
				)
			);
			return false;
		}

		$this->stamp_rolled_back( $order, $now );

		$snapshot = array(
			'subscription_id' => $sub->id,
			'legacy'          => true,
			'before'          => array( 'next_payment' => $cycle ),
			'after'           => self::state_of( $sub ),
			'lines'           => array(),
		);
		$lines    = array();
		$skipped  = 0;
		$licensed = null;
		$kept     = array();
		if ( $classic ) {
			$snapshot['license'] = array(
				'id'   => $license->id,
				'from' => $cycle,
				'to'   => $produced,
			);
			$licensed            = $this->license_service->roll_back_term( $license->id, $cycle, $produced, $this->scheduler->period_to_interval( $sub->billing_interval, $sub->billing_period ) );
		} else {
			foreach ( $this->entitlements->subscription_lines( $sub->id ) as $line ) {
				if ( $new_through !== $line->paid_through ) {
					continue; // Not what this renewal set.
				}
				if ( null !== $line->order_id && $line->order_id > $order->get_id() ) {
					$kept[ $line->id ] = $line; // Joined on a later order, co-termed: keeps its period.
					continue;
				}
				$snapshot['lines'][ $line->id ] = array(
					'from' => $old_through,
					'to'   => $new_through,
				);
				$why                            = null;
				$back                           = $this->entitlements->roll_back_line( $line->id, $old_through, $new_through, $sub->billing_interval, $sub->billing_period, $why, $order->get_id() );
				if ( null === $back ) {
					++$skipped;
					continue;
				}
				$lines[ $line->id ] = $back;
			}
		}
		$this->sub_repo->update( $sub->id, array( 'next_payment' => $cycle ) );

		$this->finish_roll_back( $order, $sub->id, $snapshot, $sub, $lines, $licensed, $cycle, $skipped, 0, true, $kept );
		return true;
	}


	/**
	 * The records, the hooks and the note every rollback ends with (the order is stamped already).
	 *
	 * @param \WC_Order         $order    The refunded order.
	 * @param int               $sub_id   Its subscription.
	 * @param array             $snapshot What the renewal moved.
	 * @param Subscription|null $sub      The subscription (as it was before the rollback), if it exists.
	 * @param array             $lines    Lines moved back: id => from / to.
	 * @param array|null        $license  The licence's expiry moved back: from / to.
	 * @param string|null       $next     The subscription's next payment now (UTC).
	 * @param int               $skipped  Lines left as they were: removed, or moved back already.
	 * @param int               $over     Lines (or the licence) renewed afresh after this period ended.
	 * @param bool              $latest   Whether no later renewal was paid.
	 * @param array             $kept     Lines that joined after the renewal and keep their period: id => line.
	 * @return void
	 */
	private function finish_roll_back( \WC_Order $order, int $sub_id, array $snapshot, ?Subscription $sub, array $lines, ?array $license, ?string $next, int $skipped, int $over, bool $latest, array $kept = array() ): void {
		$this->renewal_repo->mark_order_refunded( $order->get_id() );

		// The latest renewal refunded: nothing is billed again until the owner resumes the subscription.
		$current = $sub_id > 0 ? $this->sub_repo->find_by_id( $sub_id ) : null;
		$held    = $latest && null !== $current && in_array( $current->status, array( 'active', 'trial' ), true );
		if ( $held ) {
			$this->sub_service->update_status( $sub_id, 'on-hold' );
			$this->sub_service->add_note(
				$sub_id,
				sprintf(
					/* translators: %d: order id */
					__( 'Renewal order #%d refunded — subscription put on hold; resume it to keep billing.', 'wp-license-manager' ),
					$order->get_id()
				)
			);
		}

		/**
		 * Fires after a refunded renewal order took its period back.
		 *
		 * @param int   $order_id        The refunded order.
		 * @param int   $subscription_id Its subscription.
		 * @param array $snapshot        What the renewal had moved (see RenewalProcessor::apply_payment()).
		 */
		do_action( 'wplm_renewal_rolled_back', $order->get_id(), $sub_id, $snapshot );

		$label = null !== $sub && $sub->is_addons_only()
			/* translators: 1: subscription id, 2: "Add-ons for licence ending ABCD" */
			? sprintf( __( 'subscription #%1$d (%2$s)', 'wp-license-manager' ), $sub_id, \WPLM\Services\AddonService::label_for( $sub ) )
			/* translators: %d: subscription id */
			: sprintf( __( 'subscription #%d', 'wp-license-manager' ), $sub_id );
		if ( null !== $license ) {
			$now_through = null !== $license['to'] ? $license['to'] . ' UTC' : __( 'no expiry', 'wp-license-manager' );
			$was_through = $license['from'] . ' UTC';
		} elseif ( array() !== $lines ) {
			$now_through = max( array_column( $lines, 'to' ) );
			$was_through = max( array_column( $lines, 'from' ) );
		} else {
			$now_through = null;
			$was_through = null;
		}
		$note = null !== $now_through
			? sprintf(
				/* translators: 1: subscription label, 2: new paid-through date, 3: old paid-through date, 4: next payment (UTC) */
				__( 'WPLM: renewal refunded: %1$s now paid through %2$s (was %3$s); next payment %4$s.', 'wp-license-manager' ),
				$label,
				$now_through,
				$was_through,
				null !== $next ? $next . ' UTC' : '—'
			)
			: sprintf(
				/* translators: 1: subscription label, 2: next payment (UTC) */
				__( 'WPLM: renewal refunded: nothing %1$s was paid through had to move back; next payment %2$s.', 'wp-license-manager' ),
				$label,
				null !== $next ? $next . ' UTC' : '—'
			);
		if ( $over > 0 ) {
			$note .= ' ' . sprintf(
				/* translators: %d: number of lines (or the licence) */
				_n( '%d term was renewed afresh after this period had ended, so the refunded period was already over: it was left as it is.', '%d terms were renewed afresh after this period had ended, so the refunded period was already over: they were left as they are.', $over, 'wp-license-manager' ),
				$over
			);
		}
		if ( $skipped > 0 ) {
			$note .= ' ' . sprintf(
				/* translators: %d: number of lines */
				_n( '%d line was removed or moved back since, and was left as it is.', '%d lines were removed or moved back since, and were left as they are.', $skipped, 'wp-license-manager' ),
				$skipped
			);
		}
		if ( array() !== $kept ) {
			$names = array();
			foreach ( $kept as $line ) {
				$names[] = null !== $line->order_id
					/* translators: 1: module or limit name, 2: order id, 3: its paid-through date */
					? sprintf( __( '%1$s, added by order #%2$d (paid through %3$s)', 'wp-license-manager' ), $this->entitlements->line_label( $line ), $line->order_id, (string) $line->paid_through )
					/* translators: 1: module or limit name, 2: its paid-through date */
					: sprintf( __( '%1$s (paid through %2$s)', 'wp-license-manager' ), $this->entitlements->line_label( $line ), (string) $line->paid_through );
			}
			$note .= ' ' . sprintf(
				/* translators: %s: the lines kept, separated by semicolons */
				__( 'Kept: %s — joined after this renewal, it keeps the period it was bought for; the next renewal does not charge for it while it is paid ahead.', 'wp-license-manager' ),
				implode( '; ', $names )
			);
		}
		if ( $held ) {
			$note .= ' ' . __( 'Renewal refunded — subscription put on hold; resume it to keep billing.', 'wp-license-manager' );
		}
		$order->add_order_note( $note );
	}

	/**
	 * Where a next payment (or a snapshot's) goes when a refunded renewal that moved it `before` →
	 * `after` is taken back, counted the way the renewal counted it: still `after` → `before`, exactly;
	 * reached from `after` by renewals that each continued from the old end → one period less (a classic
	 * licence's expiry by its interval; an entitlement subscription's, the start of a day in the site's
	 * time zone, by calendar periods of its paid-through date); else null (left as it is).
	 *
	 * @param string $current UTC datetime.
	 * @param string $before  Before the renewal (UTC).
	 * @param string $after   After it (UTC).
	 * @param bool   $classic Whether it follows a classic licence's expiry.
	 * @param int    $n       The renewal's billing interval.
	 * @param string $period  Its billing period.
	 * @param string $spec    Its interval spec ('' when unknown).
	 * @return string|null UTC datetime, or null when left as it is.
	 */
	private function next_payment_taken_back( string $current, string $before, string $after, bool $classic, int $n, string $period, string $spec ): ?string {
		$c = strtotime( $current . ' UTC' );
		$a = strtotime( $after . ' UTC' );
		$b = strtotime( $before . ' UTC' );
		if ( false === $c || false === $a || false === $b || $a <= $b || $c < $a ) {
			return null;
		}
		if ( $c === $a ) {
			return gmdate( 'Y-m-d H:i:s', $b );
		}
		if ( $classic ) {
			$back = LicenseService::expiry_taken_back( $current, null, $after, $spec );
			return is_string( $back ) ? $back : null;
		}
		$through = EntitlementService::paid_through_for( $current );
		if ( self::start_of_day_after( $through ) !== gmdate( 'Y-m-d H:i:s', $c ) ) {
			return null; // Not the start of a day: not what a payment sets.
		}
		$back = EntitlementService::one_period_back( $through, EntitlementService::paid_through_for( $after ), $n, $period );
		return null !== $back ? self::start_of_day_after( $back ) : null;
	}

	/**
	 * The next payment the dates now call for, as a payment sets it: a classic licence's expiry, or the
	 * start of the day after the subscription's latest dated line. Null when there is none.
	 *
	 * Only the lines the refunded renewal moved count: a line that joined since keeps its own period and
	 * does not set when the subscription is next billed.
	 *
	 * @param int   $subscription_id The subscription.
	 * @param array $snapshot        What the renewal moved (names the licence, if classic).
	 * @param int[] $line_ids        The lines the renewal moved.
	 * @return string|null UTC datetime.
	 */
	private function next_payment_now( int $subscription_id, array $snapshot, array $line_ids ): ?string {
		if ( is_array( $snapshot['license'] ?? null ) && isset( $snapshot['license']['id'] ) ) {
			$license = $this->license_service->get_by_id( (int) $snapshot['license']['id'] );
			return null !== $license ? $license->expires_at : null;
		}
		$latest = null;
		foreach ( $this->entitlements->subscription_lines( $subscription_id ) as $line ) {
			if ( null !== $line->paid_through && in_array( (int) $line->id, $line_ids, true ) && ( null === $latest || strcmp( $line->paid_through, $latest ) > 0 ) ) {
				$latest = $line->paid_through;
			}
		}
		return null !== $latest ? self::start_of_day_after( $latest ) : null;
	}

	/**
	 * The start of the day after a paid-through date, in the site's time zone, as UTC.
	 *
	 * @param string $through Y-m-d.
	 * @return string UTC datetime.
	 */
	private static function start_of_day_after( string $through ): string {
		return ( new \DateTimeImmutable( EntitlementService::add_period( $through, 1, 'day' ) . ' 00:00:00', wp_timezone() ) )
			->setTimezone( new \DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d H:i:s' );
	}

	/**
	 * A classic licence whose term is now over with its grace is marked expired — the check validation
	 * runs ({@see LicenseService::validate()}), here at once. Never a suspended, revoked or terminated one.
	 *
	 * @param int $license_id Licence id.
	 * @return void
	 */
	private function expire_if_lapsed( int $license_id ): void {
		$license = $this->license_service->get_by_id( $license_id );
		if ( null !== $license && null === $license->profile && in_array( $license->status, array( 1, 2 ), true ) && ! $license->is_within_expiry() ) {
			$this->license_service->change_status( $license_id, 3 );
		}
	}

	/**
	 * The subscription fields a renewal changes, as kept in a snapshot.
	 *
	 * @param Subscription $sub The subscription.
	 * @return array{next_payment: string|null, last_payment: string|null, status: string, failed_attempts: int}
	 */
	private static function state_of( Subscription $sub ): array {
		return array(
			'next_payment'    => $sub->next_payment,
			'last_payment'    => $sub->last_payment,
			'status'          => $sub->status,
			'failed_attempts' => $sub->failed_attempts,
		);
	}
}
