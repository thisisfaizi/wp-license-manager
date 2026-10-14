<?php
/**
 * Add-ons: modules and limits a customer buys for a licence they already have.
 *
 * @package WPLM\Services
 */

namespace WPLM\Services;

use WPLM\Licensing\Profile;
use WPLM\Licensing\ProfileRegistry;
use WPLM\Models\Entitlement;
use WPLM\Models\License;
use WPLM\Models\Package;
use WPLM\Models\Plan;
use WPLM\Models\Subscription;
use WPLM\Repositories\EntitlementRepository;
use WPLM\Repositories\LicenseRepository;
use WPLM\Repositories\SubscriptionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * A plan whose kind is `addon` sells lines for an **existing** entitlement licence: no key is issued,
 * the lines are written onto the licence the customer picked, each stamped with `source = 'addon'`
 * and the order that bought it.
 *
 * How long a line lasts follows the add-on's type, which must match how the licence is billed
 * ({@see check()}):
 *
 * - **Recurring** (a monthly licence buys monthly add-ons, a yearly one yearly; not during the plan's
 *   trial, nor while it is overdue): the customer pays the full price now, the line is paid through the
 *   same day as the plan, it joins the plan's
 *   subscription (a subscription item, and the recurring total re-derived from the items), and every
 *   renewal extends it with the plan ({@see EntitlementService::extend_subscription_lines()}).
 * - **Lifetime**: for life.
 * - **One-time (fixed term)**: `today + days − 1`; a second purchase of the same module continues
 *   from the live line's end (the renewal rule), so packs stack.
 *
 * A full refund takes exactly the order's lines back ({@see revert()}) and never touches the licence's
 * status. Removing an add-on line in admin also removes its billing from the subscription
 * ({@see line_deleted()}).
 */
class AddonService {

	/** Licence statuses that may take an add-on: active, and inactive (issued, no computer yet). */
	public const LICENCE_STATUSES = array( 1, 2 );

	/** Subscription statuses an add-on may join. */
	public const SUBSCRIPTION_STATUSES = array( 'active', 'trial', 'on-hold' );

	/** @var EntitlementService */
	private EntitlementService $entitlements;

	/** @var EntitlementRepository */
	private EntitlementRepository $lines;

	/** @var LicenseRepository */
	private LicenseRepository $licenses;

	/** @var PlanService */
	private PlanService $plans;

	/** @var ProfileRegistry */
	private ProfileRegistry $profiles;

	/** @var SubscriptionRepository */
	private SubscriptionRepository $subscriptions;

	public function __construct(
		EntitlementService $entitlements,
		EntitlementRepository $lines,
		LicenseRepository $licenses,
		PlanService $plans,
		ProfileRegistry $profiles,
		SubscriptionRepository $subscriptions
	) {
		$this->entitlements  = $entitlements;
		$this->lines         = $lines;
		$this->licenses      = $licenses;
		$this->plans         = $plans;
		$this->profiles      = $profiles;
		$this->subscriptions = $subscriptions;
	}

	/** Keep a subscription's billing in step when an add-on line is removed, by any path. */
	public function register(): void {
		add_action( 'wplm_entitlement_deleted', array( $this, 'line_deleted' ), 10, 1 );
	}

	// -------------------------------------------------------------------------
	// Eligibility
	// -------------------------------------------------------------------------

	/**
	 * How a licence is billed, read from its anchor lines: the profile's base module, or (a profile
	 * with no base module) the lines its plan wrote.
	 *
	 * @param License       $license The licence.
	 * @param Profile       $profile Its profile.
	 * @param Entitlement[] $lines   Its lines.
	 * @return array{type: string, subscription: Subscription|null, paid_through: string|null}
	 *         type is `recurring`, `lifetime`, `fixed` (dated, no subscription) or `none`.
	 */
	public function billing( License $license, Profile $profile, array $lines ): array {
		unset( $license );
		$anchor = array_values(
			array_filter(
				$lines,
				static function ( Entitlement $line ) use ( $profile ): bool {
					if ( null !== $profile->base_module ) {
						return Entitlement::KIND_MODULE === $line->kind && $profile->base_module === $line->code;
					}
					return 'plan' === $line->source;
				}
			)
		);

		$out = array(
			'type'         => 'none',
			'subscription' => null,
			'paid_through' => null,
		);
		if ( array() === $anchor ) {
			return $out;
		}
		foreach ( $anchor as $line ) {
			if ( null === $line->paid_through ) {
				$out['type'] = 'lifetime';
				return $out;
			}
		}

		// The subscription that pays for the latest anchor line.
		usort( $anchor, static fn( Entitlement $a, Entitlement $b ) => strcmp( (string) $b->paid_through, (string) $a->paid_through ) ?: $b->id <=> $a->id );
		foreach ( $anchor as $line ) {
			if ( null === $line->subscription_id ) {
				continue;
			}
			$sub = $this->subscriptions->find_by_id( $line->subscription_id );
			if ( null === $sub ) {
				continue;
			}
			$through = null;
			foreach ( $anchor as $same ) {
				if ( $same->subscription_id === $sub->id && ( null === $through || strcmp( (string) $same->paid_through, $through ) > 0 ) ) {
					$through = (string) $same->paid_through;
				}
			}
			return array(
				'type'         => 'recurring',
				'subscription' => $sub,
				'paid_through' => $through,
			);
		}

		$out['type']         = 'fixed';
		$out['paid_through'] = $anchor[0]->paid_through;
		return $out;
	}

	/**
	 * Whether an add-on package may be added to a licence. Ownership is not checked here — the caller
	 * knows who is buying ({@see check_for_user()}).
	 *
	 * Refused, in order: a package that is not an add-on; another product's licence; a suspended,
	 * revoked, expired or terminated licence (these three are final). Then: no base module, or a
	 * read-only one; a subscription that is not active, in trial or on hold; a type that does not match
	 * the licence's billing; a recurring add-on while the plan is in trial, or overdue (on hold, or paid
	 * through a day before today — in grace); modules the licence already has for the same term; a module whose
	 * requirement ({@see Profile::$requires}) is not met. Last, the filter
	 * `wplm_addon_eligibility( true|\WP_Error $result, License, Package, Profile, array $summary,
	 * ?Subscription $subscription )` may refuse (return a `WP_Error`), or reword a refusal; returning
	 * `true` cannot lift WPLM's own refusal.
	 *
	 * @param License  $license The target licence.
	 * @param Package  $package The add-on.
	 * @param int|null $now     Unix time (tests).
	 * @return true|\WP_Error
	 */
	public function check( License $license, Package $package, ?int $now = null ) {
		$plan = $this->plan( $package->plan_id );
		if ( null === $plan || ! $plan->is_addon() ) {
			return $this->error( 'not_addon', __( 'This is not an add-on.', 'wp-license-manager' ) );
		}
		$profile = $this->profiles->get( $license->profile );
		if ( null === $profile || $plan->profile !== $license->profile ) {
			return $this->error( 'wrong_product', __( 'This add-on is for a different product than that licence.', 'wp-license-manager' ) );
		}
		if ( ! in_array( $license->status, self::LICENCE_STATUSES, true ) ) {
			return $this->error(
				'licence_status',
				/* translators: %s: licence status, e.g. Suspended */
				sprintf( __( 'That licence is %s, so nothing can be added to it. Please contact us.', 'wp-license-manager' ), strtolower( $license->status_label() ) )
			);
		}

		$today   = wp_date( 'Y-m-d', $now ?? time() );
		$grace   = $profile->grace_days();
		$lines   = $this->entitlements->lines( $license->id );
		$summary = EntitlementService::summarize_lines( $lines, $profile, $today, $grace );
		$billing = $this->billing( $license, $profile, $lines );
		$result  = $this->soft_checks( $package, $profile, $lines, $summary, $billing, $today, $grace );

		/**
		 * Filter whether an add-on may be added to a licence.
		 *
		 * WPLM's verdict is passed in, refusal included, so a product can reword it. Return a WP_Error
		 * to refuse (its message is shown to the customer). Returning true cannot lift WPLM's refusal.
		 *
		 * @param true|\WP_Error     $result       WPLM's verdict.
		 * @param License            $license      The target licence.
		 * @param Package            $package      The add-on.
		 * @param Profile            $profile      The licence's profile.
		 * @param array              $summary      What the licence grants today ({@see EntitlementService::summarize_lines()}).
		 * @param Subscription|null  $subscription The subscription a recurring licence is billed on.
		 */
		$filtered = apply_filters( 'wplm_addon_eligibility', $result, $license, $package, $profile, $summary, $billing['subscription'] );

		return is_wp_error( $filtered ) ? $filtered : $result;
	}

	/**
	 * {@see check()} for a customer: the licence must also be theirs.
	 *
	 * @param int      $user_id The customer (0 = logged out).
	 * @param License  $license The target licence.
	 * @param Package  $package The add-on.
	 * @param int      $qty     Quantity bought.
	 * @param int|null $now     Unix time (tests).
	 * @return true|\WP_Error
	 */
	public function check_for_user( int $user_id, License $license, Package $package, int $qty = 1, ?int $now = null ) {
		if ( $user_id <= 0 ) {
			return $this->error( 'logged_out', __( 'Log in to add this to your licence.', 'wp-license-manager' ) );
		}
		if ( (int) $license->user_id !== $user_id ) {
			return $this->error( 'not_yours', __( 'That licence is not on your account.', 'wp-license-manager' ) );
		}
		$quantity = $this->check_quantity( $package, $qty );
		if ( is_wp_error( $quantity ) ) {
			return $quantity;
		}
		return $this->check( $license, $package, $now );
	}

	/**
	 * A quantity multiplies what a package adds to each limit. A package that grants only modules has
	 * nothing to multiply, so more than one is refused rather than charged for nothing.
	 *
	 * @param Package $package The add-on.
	 * @param int     $qty     Quantity.
	 * @return true|\WP_Error
	 */
	public function check_quantity( Package $package, int $qty ) {
		if ( $qty <= 1 ) {
			return true;
		}
		foreach ( $package->entitlements as $line ) {
			if ( Entitlement::KIND_LIMIT === ( $line['kind'] ?? '' ) ) {
				return true;
			}
		}
		/* translators: %s: add-on name */
		return $this->error( 'quantity', sprintf( __( '"%s" adds modules, so it can only be added once.', 'wp-license-manager' ), $package->name ) );
	}

	/**
	 * The customer's licences an add-on plan can be added to, each with the plan's packages that fit it.
	 *
	 * @param int      $user_id The customer.
	 * @param Plan     $plan    An add-on plan (with its packages loaded).
	 * @param int|null $now     Unix time (tests).
	 * @return array<int, array{license: License, packages: int[]}> Keyed by licence id.
	 */
	public function eligible_targets( int $user_id, Plan $plan, ?int $now = null ): array {
		if ( $user_id <= 0 || ! $plan->is_addon() || null === $plan->profile ) {
			return array();
		}
		$found   = $this->licenses->get_list(
			array(
				'user_id'  => $user_id,
				'per_page' => 100,
				'orderby'  => 'id',
				'order'    => 'ASC',
			)
		);
		$targets = array();
		foreach ( $found['items'] ?? array() as $license ) {
			if ( $plan->profile !== $license->profile ) {
				continue;
			}
			$fits = array();
			foreach ( $plan->packages as $package ) {
				if ( 1 === (int) $package->status && true === $this->check( $license, $package, $now ) ) {
					$fits[] = $package->id;
				}
			}
			if ( array() !== $fits ) {
				$targets[ $license->id ] = array(
					'license'  => $license,
					'packages' => $fits,
				);
			}
		}
		return $targets;
	}

	/**
	 * The customer's licences of the plan's profile that no add-on of the plan fits, each with the
	 * reason to show. The most telling refusal wins: anything but "billed differently" (an overdue plan
	 * refuses its own monthly add-ons and, being monthly, every lifetime one too).
	 *
	 * @param int      $user_id The customer.
	 * @param Plan     $plan    An add-on plan (with its packages loaded).
	 * @param int|null $now     Unix time (tests).
	 * @return array<int, array{license: License, reason: string}> Keyed by licence id.
	 */
	public function blocked_targets( int $user_id, Plan $plan, ?int $now = null ): array {
		if ( $user_id <= 0 || ! $plan->is_addon() || null === $plan->profile ) {
			return array();
		}
		$eligible = $this->eligible_targets( $user_id, $plan, $now );
		$found    = $this->licenses->get_list(
			array(
				'user_id'  => $user_id,
				'per_page' => 100,
				'orderby'  => 'id',
				'order'    => 'ASC',
			)
		);
		$blocked  = array();
		foreach ( $found['items'] ?? array() as $license ) {
			if ( $plan->profile !== $license->profile || isset( $eligible[ $license->id ] ) ) {
				continue;
			}
			$reason = null;
			foreach ( $plan->packages as $package ) {
				if ( 1 !== (int) $package->status ) {
					continue;
				}
				$result = $this->check( $license, $package, $now );
				if ( ! is_wp_error( $result ) ) {
					continue;
				}
				if ( null === $reason || 'wplm_addon_billing_mismatch' === $reason->get_error_code() ) {
					$reason = $result;
				}
				if ( 'wplm_addon_billing_mismatch' !== $reason->get_error_code() ) {
					break;
				}
			}
			if ( null !== $reason ) {
				$blocked[ $license->id ] = array(
					'license' => $license,
					'reason'  => $reason->get_error_message(),
				);
			}
		}
		return $blocked;
	}

	// -------------------------------------------------------------------------
	// Fulfilment
	// -------------------------------------------------------------------------

	/**
	 * Add a paid add-on to the licence the customer picked. Everything is checked and every line is
	 * validated before anything is written; if joining the subscription then fails, the lines written
	 * are deleted again, so a retry starts clean.
	 *
	 * Leaves `_wplm_entitlement_ids` (and, recurring, `_wplm_addon_subscription_item_id`) on the order
	 * item; deliberately not `_wplm_license_ids`, which means "a key was issued for this line" to every
	 * reader of it (refunds, tunnel provisioning, the order screen).
	 *
	 * @param \WC_Order              $order   The order.
	 * @param \WC_Order_Item_Product $item    Its add-on line.
	 * @param Package                $package The add-on.
	 * @param License                $license The licence it is added to.
	 * @param int|null               $now     Unix time (tests).
	 * @return Entitlement[] The lines written.
	 * @throws \RuntimeException When the add-on cannot be added.
	 */
	public function apply( \WC_Order $order, $item, Package $package, License $license, ?int $now = null ): array {
		$ok = $this->check_for_user( (int) $order->get_customer_id(), $license, $package, (int) $item->get_quantity(), $now );
		if ( is_wp_error( $ok ) ) {
			throw new \RuntimeException( $ok->get_error_message() );
		}

		$profile = $this->profiles->get( $license->profile );
		$qty     = max( 1, (int) $item->get_quantity() );
		$now     = $now ?? time();
		$today   = wp_date( 'Y-m-d', $now );
		$lines   = $this->entitlements->lines( $license->id );
		$billing = $this->billing( $license, $profile, $lines );
		$sub     = 'recurring' === $billing['type'] ? $billing['subscription'] : null;

		$rows = array();
		foreach ( $package->entitlements as $template ) {
			$kind = (string) ( $template['kind'] ?? '' );
			$code = (string) ( $template['code'] ?? '' );
			if ( null !== $sub ) {
				$through = $billing['paid_through'];
			} elseif ( Package::TYPE_ONETIME === $package->billing_type && (int) $package->valid_for_days > 0 ) {
				$through = $this->one_time_through( $lines, $kind, $code, $today, $profile->grace_days(), (int) $package->valid_for_days );
			} else {
				$through = null; // Lifetime, or an older one-time type with no days.
			}
			$rows[] = array(
				'kind'            => $kind,
				'code'            => $code,
				'qty'             => Entitlement::KIND_LIMIT === $kind ? (int) ( $template['qty'] ?? 0 ) * $qty : 0,
				'paid_through'    => $through,
				'source'          => 'addon',
				'subscription_id' => null !== $sub ? $sub->id : null,
				'order_id'        => $order->get_id(),
			);
		}
		if ( array() === $rows ) {
			throw new \RuntimeException( 'the add-on grants nothing' );
		}

		$written = $this->entitlements->add_lines( $license->id, $rows );
		$ids     = array_map( static fn( Entitlement $l ) => $l->id, $written );

		if ( null !== $sub ) {
			try {
				$sub_item_id = $this->attach( $sub, $order, $item, $package, $qty, $license, $ids );
			} catch ( \Throwable $e ) {
				foreach ( $ids as $id ) {
					$this->lines->delete( $id );
				}
				throw new \RuntimeException( 'the add-on could not join the subscription: ' . $e->getMessage() );
			}
			$item->update_meta_data( '_wplm_addon_subscription_item_id', $sub_item_id );
		}
		$item->update_meta_data( '_wplm_entitlement_ids', wp_json_encode( $ids ) );
		$item->save_meta_data();

		$order->add_order_note(
			sprintf(
				/* translators: 1: what was added, 2: licence label */
				__( 'WPLM: added %1$s to %2$s.', 'wp-license-manager' ),
				self::describe_lines( $written, $profile, false ),
				self::licence_label( $license )
			)
		);

		return $written;
	}

	/**
	 * The paid-through date of a one-time line: `today + days − 1`, or — when the licence already has a
	 * dated line for the same module that no subscription pays for (an earlier pack, or one the owner
	 * added by hand) — continuing from the latest such line's end, by the renewal rule (inside grace from
	 * the old end, after grace from today). Limits never stack: each pack adds its own quantity for its
	 * own term.
	 *
	 * @param Entitlement[] $lines Licence lines.
	 * @param string        $kind  module|limit.
	 * @param string        $code  Code.
	 * @param string        $today Y-m-d.
	 * @param int           $grace Profile grace.
	 * @param int           $days  Days the pack lasts.
	 * @return string Y-m-d
	 */
	private function one_time_through( array $lines, string $kind, string $code, string $today, int $grace, int $days ): string {
		$latest = null;
		if ( Entitlement::KIND_MODULE === $kind ) {
			foreach ( $lines as $line ) {
				if ( Entitlement::KIND_MODULE === $line->kind && $code === $line->code
					&& null === $line->subscription_id && null !== $line->paid_through
					&& ( null === $latest || strcmp( $line->paid_through, $latest ) > 0 ) ) {
					$latest = $line->paid_through;
				}
			}
		}
		if ( null === $latest ) {
			return EntitlementService::add_period( $today, $days - 1, 'day' );
		}
		return EntitlementService::renewed_paid_through( $latest, $today, $grace, $days, 'day' );
	}

	/**
	 * Take back what a refunded order added: its lines (and with them their billing, through
	 * {@see line_deleted()}). The licence itself, its status and its other lines are untouched.
	 * Safe to run twice.
	 *
	 * @param \WC_Order $order The refunded order.
	 * @return int Lines removed.
	 */
	public function revert( \WC_Order $order ): int {
		$removed = 0;
		$touched = false;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$ids = json_decode( (string) $item->get_meta( '_wplm_entitlement_ids' ), true );
			if ( ! is_array( $ids ) ) {
				continue;
			}
			$touched = true;
			foreach ( $ids as $id ) {
				if ( $this->entitlements->delete_line( (int) $id ) ) {
					++$removed;
				}
			}
			// The billing goes with the lines; this also covers an item whose lines were removed by hand.
			$sub_item = (int) $item->get_meta( '_wplm_addon_subscription_item_id' );
			if ( $sub_item > 0 ) {
				$this->detach_item( $sub_item );
			}
			$item->delete_meta_data( '_wplm_entitlement_ids' );
			$item->delete_meta_data( '_wplm_addon_subscription_item_id' );
			$item->update_meta_data( '_wplm_addon_reverted', '1' );
			$item->save_meta_data();
		}

		// Anything else this order wrote (an interrupted fulfilment that never stamped its item).
		foreach ( $this->lines->get_by_order( $order->get_id() ) as $line ) {
			if ( 'addon' === $line->source && $this->entitlements->delete_line( $line->id ) ) {
				++$removed;
				$touched = true;
			}
		}

		if ( $touched ) {
			$order->add_order_note(
				/* translators: %d: number of lines */
				sprintf( _n( 'WPLM: refunded — %d add-on line removed from the licence. The licence itself is unchanged.', 'WPLM: refunded — %d add-on lines removed from the licence. The licence itself is unchanged.', $removed, 'wp-license-manager' ), $removed )
			);
		}
		return $removed;
	}

	// -------------------------------------------------------------------------
	// Billing
	// -------------------------------------------------------------------------

	/**
	 * Add the add-on to the subscription's items and re-derive the recurring total from them.
	 *
	 * @return int The subscription item id.
	 */
	private function attach( Subscription $sub, \WC_Order $order, $item, Package $package, int $qty, License $license, array $line_ids ): int {
		$this->reconcile( $sub );
		$item_id = $this->subscriptions->add_item(
			$sub->id,
			array(
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => $item->get_variation_id() ?: null,
				'quantity'     => $qty,
				'line_total'   => round( $package->price * $qty, 4 ),
				'meta'         => array(
					'addon'           => true,
					'package_id'      => $package->id,
					'order_id'        => $order->get_id(),
					'order_item_id'   => $item->get_id(),
					'license_id'      => $license->id,
					'entitlement_ids' => array_values( array_map( 'intval', $line_ids ) ),
				),
			)
		);
		$this->recalculate( $sub->id );
		return $item_id;
	}

	/**
	 * `wplm_entitlement_deleted` callback: an add-on line on a subscription stops being billed once the
	 * last line its purchase wrote is gone.
	 *
	 * @param mixed $line The removed line.
	 * @return void
	 */
	public function line_deleted( $line ): void {
		if ( ! $line instanceof Entitlement || 'addon' !== $line->source || null === $line->subscription_id ) {
			return;
		}
		foreach ( $this->subscriptions->get_items( $line->subscription_id ) as $sub_item ) {
			$ids = array_map( 'intval', (array) ( $sub_item->meta['entitlement_ids'] ?? array() ) );
			if ( empty( $sub_item->meta['addon'] ) || ! in_array( $line->id, $ids, true ) ) {
				continue;
			}
			$left = array_values( array_diff( $ids, array( $line->id ) ) );
			$left = array_values( array_filter( $left, fn( $id ) => null !== $this->lines->find_by_id( $id ) ) );
			if ( array() === $left ) {
				$this->detach_item( $sub_item->id, $line->subscription_id );
			} else {
				$this->subscriptions->update_item( $sub_item->id, array( 'meta' => array( 'entitlement_ids' => $left ) + $sub_item->meta ) );
			}
		}
	}

	/**
	 * Remove one add-on item from its subscription and re-derive the total. Does nothing when it is gone.
	 *
	 * @param int      $sub_item_id     Subscription item id.
	 * @param int|null $subscription_id Its subscription, when known.
	 */
	private function detach_item( int $sub_item_id, ?int $subscription_id = null ): void {
		if ( null === $subscription_id ) {
			global $wpdb;
			$found           = $wpdb->get_var( $wpdb->prepare( "SELECT subscription_id FROM {$wpdb->prefix}wplm_subscription_items WHERE id = %d", $sub_item_id ) );
			$subscription_id = null !== $found ? (int) $found : null;
		}
		if ( null === $subscription_id ) {
			return;
		}
		$sub = $this->subscriptions->find_by_id( $subscription_id );
		if ( null !== $sub ) {
			$this->reconcile( $sub );
		}
		$this->subscriptions->delete_item( $sub_item_id );
		$this->recalculate( $subscription_id );
	}

	/**
	 * Make the items add up to the recurring total before an add-on changes them, so re-deriving the
	 * total keeps what was there: a subscription created before it had items, or whose amount was
	 * edited by hand, gets one "not itemised" item for the difference.
	 *
	 * @param Subscription $sub Subscription.
	 */
	private function reconcile( Subscription $sub ): void {
		$fresh = $this->subscriptions->find_by_id( $sub->id ) ?? $sub;
		$items = $this->subscriptions->get_items( $fresh->id );
		$sum   = 0.0;
		$adj   = null;
		foreach ( $items as $sub_item ) {
			$sum += $sub_item->line_total;
			if ( ! empty( $sub_item->meta['adjustment'] ) ) {
				$adj = $sub_item;
			}
		}
		$diff = round( $fresh->recurring_total - $sum, 4 );
		if ( abs( $diff ) < 0.00005 ) {
			return;
		}
		if ( null !== $adj ) {
			$this->subscriptions->update_item( $adj->id, array( 'line_total' => round( $adj->line_total + $diff, 4 ) ) );
			return;
		}
		$this->subscriptions->add_item(
			$fresh->id,
			array(
				'product_id' => 0,
				'quantity'   => 1,
				'line_total' => $diff,
				'meta'       => array(
					'adjustment' => true,
					'note'       => 'Recurring amount not itemised (set before add-ons, or edited by hand).',
				),
			)
		);
	}

	/**
	 * The recurring total is the sum of the subscription's items — never incremented, so a retry or a
	 * repeated removal cannot double-count.
	 *
	 * @param int $subscription_id Subscription id.
	 */
	public function recalculate( int $subscription_id ): void {
		$sum = 0.0;
		foreach ( $this->subscriptions->get_items( $subscription_id ) as $sub_item ) {
			$sum += $sub_item->line_total;
		}
		$this->subscriptions->update( $subscription_id, array( 'recurring_total' => round( $sum, 4 ) ) );
	}

	// -------------------------------------------------------------------------
	// Storefront helpers
	// -------------------------------------------------------------------------

	/**
	 * Where a customer buys add-ons for a licence: the first published product whose plan sells
	 * add-ons for the licence's profile, with `?wplm_license=` preselecting it. '' when there is none.
	 *
	 * @param License $license The licence.
	 * @return string
	 */
	public function product_url_for( License $license ): string {
		if ( null === $license->profile || ! in_array( $license->status, self::LICENCE_STATUSES, true ) ) {
			return '';
		}
		$plan_ids = array();
		foreach ( $this->plans->get_all( true ) as $plan ) {
			if ( $plan->is_addon() && $plan->profile === $license->profile ) {
				$plan_ids[] = (string) $plan->id;
			}
		}
		if ( array() === $plan_ids ) {
			return '';
		}
		$products = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one product lookup per account page.
				'meta_query'     => array(
					array(
						'key'     => '_wplm_plan_id',
						'value'   => $plan_ids,
						'compare' => 'IN',
					),
				),
			)
		);
		if ( empty( $products ) ) {
			return '';
		}
		return add_query_arg( 'wplm_license', $license->id, get_permalink( (int) $products[0] ) );
	}

	/** "Licence ending ABCD": how a customer recognises a licence without seeing its key. */
	public static function licence_label( License $license ): string {
		/* translators: %s: last four characters of the licence key */
		return sprintf( __( 'licence ending %s', 'wp-license-manager' ), strtoupper( substr( $license->license_key, -4 ) ) );
	}

	/**
	 * What lines grant, in words: "FBR (paid through 12 Nov 2026), +2 Users (for life)".
	 *
	 * @param Entitlement[] $lines   Lines.
	 * @param Profile       $profile Their profile.
	 * @param bool          $dates   Whether to say until when.
	 * @return string
	 */
	public static function describe_lines( array $lines, Profile $profile, bool $dates = true ): string {
		$parts = array();
		foreach ( $lines as $line ) {
			$what = Entitlement::KIND_LIMIT === $line->kind
				? '+' . $line->qty . ' ' . $profile->code_label( $line->code )
				: $profile->code_label( $line->code );
			if ( $dates ) {
				$what .= null === $line->paid_through
					? ' (' . __( 'for life', 'wp-license-manager' ) . ')'
					/* translators: %s: date */
					: ' (' . sprintf( __( 'paid through %s', 'wp-license-manager' ), wp_date( (string) get_option( 'date_format' ), ( new \DateTimeImmutable( $line->paid_through . ' 12:00:00', wp_timezone() ) )->getTimestamp() ) ) . ')';
			}
			$parts[] = $what;
		}
		return implode( ', ', $parts );
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * The checks a product may reword through `wplm_addon_eligibility`.
	 *
	 * @return true|\WP_Error
	 */
	private function soft_checks( Package $package, Profile $profile, array $lines, array $summary, array $billing, string $today, int $grace ) {
		if ( null !== $profile->base_module ) {
			$base = $profile->code_label( $profile->base_module );
			if ( ! array_key_exists( $profile->base_module, $summary['modules'] ) ) {
				/* translators: %s: base module name */
				return $this->error( 'no_base', sprintf( __( 'That licence has no %s, so add-ons cannot be added to it yet.', 'wp-license-manager' ), $base ) );
			}
			if ( EntitlementService::STATE_READ_ONLY === EntitlementService::module_state( $summary['modules'][ $profile->base_module ]['until'], $today, $grace ) ) {
				/* translators: %s: base module name */
				return $this->error( 'base_read_only', sprintf( __( 'That licence\'s %s is unpaid and read-only. Renew it first, then add to it.', 'wp-license-manager' ), $base ) );
			}
		} elseif ( 'none' === $billing['type'] ) {
			return $this->error( 'no_base', __( 'That licence has nothing to add to yet.', 'wp-license-manager' ) );
		}

		$sub = $billing['subscription'];
		switch ( $billing['type'] ) {
			case 'recurring':
				if ( ! in_array( $sub->status, self::SUBSCRIPTION_STATUSES, true ) ) {
					return $this->error(
						'subscription_inactive',
						/* translators: %s: subscription status */
						sprintf( __( 'That licence\'s subscription is %s. Renew it first, then add to it.', 'wp-license-manager' ), strtolower( $sub->status_label() ) )
					);
				}
				if ( ! $package->is_recurring() || $package->billing_period !== $sub->billing_period || (int) $package->billing_interval !== (int) $sub->billing_interval ) {
					return $this->error(
						'billing_mismatch',
						sprintf(
							/* translators: %s: billing schedule, e.g. "every month" */
							__( 'That licence is billed %s, so it takes add-ons billed the same way.', 'wp-license-manager' ),
							self::schedule( (int) $sub->billing_interval, $sub->billing_period )
						)
					);
				}
				// A recurring add-on is paid in full now and ends with the plan's paid period. During a
				// trial that period is the trial, and once the plan is overdue (in grace, on hold) it is
				// already over: the add-on would be paid for again at the very next renewal.
				if ( 'trial' === $sub->status ) {
					return $this->error( 'trial', __( 'Add-ons can be bought after your trial ends.', 'wp-license-manager' ) );
				}
				if ( 'on-hold' === $sub->status || null === $billing['paid_through'] || strcmp( (string) $billing['paid_through'], $today ) < 0 ) {
					return $this->error( 'overdue', __( 'Pay your plan\'s renewal first, then add this.', 'wp-license-manager' ) );
				}
				break;
			case 'lifetime':
				if ( $package->is_recurring() ) {
					return $this->error( 'billing_mismatch', __( 'That licence is for life, so it takes lifetime add-ons, not monthly or yearly ones.', 'wp-license-manager' ) );
				}
				break;
			default:
				return $this->error( 'fixed_term', __( 'Add-ons cannot be added to a fixed-term licence. Please contact us.', 'wp-license-manager' ) );
		}

		// Modules the licence already has for the same term: buying them again would charge for nothing.
		$modules = array();
		$limits  = false;
		foreach ( $package->entitlements as $template ) {
			if ( Entitlement::KIND_MODULE === ( $template['kind'] ?? '' ) ) {
				$modules[] = (string) $template['code'];
			} else {
				$limits = true;
			}
		}
		if ( ! $limits && array() !== $modules ) {
			$has = array();
			foreach ( $modules as $code ) {
				foreach ( $lines as $line ) {
					if ( Entitlement::KIND_MODULE === $line->kind && $code === $line->code
						&& ( null === $line->paid_through || ( null !== $sub && $line->subscription_id === $sub->id ) ) ) {
						$has[] = $profile->code_label( $code );
						break;
					}
				}
			}
			if ( count( $has ) === count( $modules ) ) {
				/* translators: %s: module names */
				return $this->error( 'already_has', sprintf( __( 'That licence already has %s.', 'wp-license-manager' ), implode( ', ', $has ) ) );
			}
		}

		// Requirements: at least one of the listed modules, live on the licence or in this same add-on.
		foreach ( $modules as $code ) {
			$needs = (array) ( $profile->requires[ $code ] ?? array() );
			if ( array() === $needs ) {
				continue;
			}
			$met = false;
			foreach ( $needs as $need ) {
				if ( in_array( $need, $modules, true ) ) {
					$met = true;
					break;
				}
				if ( array_key_exists( $need, $summary['modules'] )
					&& EntitlementService::STATE_READ_ONLY !== EntitlementService::module_state( $summary['modules'][ $need ]['until'], $today, $grace ) ) {
					$met = true;
					break;
				}
			}
			if ( ! $met ) {
				return $this->error(
					'requires',
					sprintf(
						/* translators: 1: module name, 2: required module names joined by "or" */
						__( '%1$s needs %2$s on the licence.', 'wp-license-manager' ),
						$profile->code_label( $code ),
						implode( ' ' . __( 'or', 'wp-license-manager' ) . ' ', array_map( array( $profile, 'code_label' ), $needs ) )
					)
				);
			}
		}

		return true;
	}

	/** "every month", "every year", "every 3 months". */
	public static function schedule( int $interval, string $period ): string {
		$names = array(
			'day'   => array( __( 'day', 'wp-license-manager' ), __( 'days', 'wp-license-manager' ) ),
			'week'  => array( __( 'week', 'wp-license-manager' ), __( 'weeks', 'wp-license-manager' ) ),
			'month' => array( __( 'month', 'wp-license-manager' ), __( 'months', 'wp-license-manager' ) ),
			'year'  => array( __( 'year', 'wp-license-manager' ), __( 'years', 'wp-license-manager' ) ),
		);
		$name  = $names[ $period ] ?? array( $period, $period );
		if ( $interval > 1 ) {
			/* translators: 1: number of periods, 2: period name (plural) */
			return sprintf( __( 'every %1$d %2$s', 'wp-license-manager' ), $interval, $name[1] );
		}
		/* translators: %s: period name */
		return sprintf( __( 'every %s', 'wp-license-manager' ), $name[0] );
	}

	/** A plan by id (not memoised: this service is a container singleton and plans change). */
	private function plan( int $plan_id ): ?Plan {
		return $this->plans->get( $plan_id );
	}

	/** A refusal with a customer-facing message. */
	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( 'wplm_addon_' . $code, $message );
	}
}
