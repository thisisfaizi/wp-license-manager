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
 *
 * Since 1.3.0 an add-on plan may also sell **package extras** priced by the `wplm_package_extras`
 * filter instead of, or beside, stored packages ({@see extras_context()},
 * {@see apply_extras_to_licence()}); a new licence bought with extras gets them through the same line
 * writer ({@see apply_extras()}).
 */
class AddonService {

	/** Licence statuses that may take an add-on: active, and inactive (issued, no computer yet). */
	public const LICENCE_STATUSES = array( 1, 2 );

	/** Subscription statuses an add-on may join. */
	public const SUBSCRIPTION_STATUSES = array( 'active', 'trial', 'on-hold' );

	/** Seconds after which a licence's add-ons lock is taken to be from a request that died. */
	public const LICENCE_LOCK_TTL = 300;

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

	/** @var PackageExtrasService|null */
	private ?PackageExtrasService $extras;

	/**
	 * While lines are taken back to retry a write (a rollback, or leftovers of an interrupted one), an
	 * add-ons-only subscription left with no items is kept: the retry bills it again.
	 *
	 * @var bool
	 */
	private bool $keep_empty_addons = false;

	/**
	 * @param EntitlementService        $entitlements  Entitlement lines.
	 * @param EntitlementRepository     $lines         Line data-access layer.
	 * @param LicenseRepository         $licenses      Licence data-access layer.
	 * @param PlanService               $plans         Plans.
	 * @param ProfileRegistry           $profiles      Licence profiles.
	 * @param SubscriptionRepository    $subscriptions Subscriptions.
	 * @param PackageExtrasService|null $extras        Package extras (resolved from the container when null).
	 */
	public function __construct(
		EntitlementService $entitlements,
		EntitlementRepository $lines,
		LicenseRepository $licenses,
		PlanService $plans,
		ProfileRegistry $profiles,
		SubscriptionRepository $subscriptions,
		?PackageExtrasService $extras = null
	) {
		$this->extras        = $extras;
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
	 * The customer's licences an add-on plan can be added to, each with the plan's packages that fit it
	 * and the package extras offered for it (the `wplm_package_extras` filter, for the licence's
	 * billing). A licence is listed when a package fits it or an extra is offered for it.
	 *
	 * @param int      $user_id The customer.
	 * @param Plan     $plan    An add-on plan (with its packages loaded).
	 * @param int|null $now     Unix time (tests).
	 * @return array<int, array{license: License, packages: int[], extras: array[]}> Keyed by licence id.
	 */
	public function eligible_targets( int $user_id, Plan $plan, ?int $now = null ): array {
		if ( $user_id <= 0 || ! $plan->is_addon() || null === $plan->profile ) {
			return array();
		}
		$targets = array();
		foreach ( $this->licences_of( $user_id ) as $license ) {
			if ( $plan->profile !== $license->profile ) {
				continue;
			}
			$fits = array();
			foreach ( $plan->packages as $package ) {
				if ( 1 === (int) $package->status && true === $this->check( $license, $package, $now ) ) {
					$fits[] = $package->id;
				}
			}
			$extras = $this->extras_offers( $plan, $license, $now );
			if ( array() !== $fits || array() !== $extras ) {
				$targets[ $license->id ] = array(
					'license'  => $license,
					'packages' => $fits,
					'extras'   => $extras,
				);
			}
		}
		return $targets;
	}

	/**
	 * A customer's licences, oldest first (at most 100).
	 *
	 * @param int $user_id The customer.
	 * @return License[]
	 */
	private function licences_of( int $user_id ): array {
		$found = $this->licenses->get_list(
			array(
				'user_id'  => $user_id,
				'per_page' => 100,
				'orderby'  => 'id',
				'order'    => 'ASC',
			)
		);
		return $found['items'] ?? array();
	}

	/**
	 * The customer's licences of the plan's profile that no add-on of the plan fits, each with the
	 * reason to show. The most telling refusal wins: anything but "billed differently" (an overdue plan
	 * refuses its own monthly add-ons and, being monthly, every lifetime one too).
	 *
	 * @param int        $user_id  The customer.
	 * @param Plan       $plan     An add-on plan (with its packages loaded).
	 * @param int|null   $now      Unix time (tests).
	 * @param array|null $eligible {@see eligible_targets()} for the same customer and plan, when the
	 *                             caller already has it (so the offers and checks are not run twice).
	 * @return array<int, array{license: License, reason: string}> Keyed by licence id.
	 */
	public function blocked_targets( int $user_id, Plan $plan, ?int $now = null, ?array $eligible = null ): array {
		if ( $user_id <= 0 || ! $plan->is_addon() || null === $plan->profile ) {
			return array();
		}
		$eligible = $eligible ?? $this->eligible_targets( $user_id, $plan, $now );
		$blocked  = array();
		foreach ( $this->licences_of( $user_id ) as $license ) {
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
			// No stored package said why (or only "billed differently"): ask the extras.
			if ( null === $reason || 'wplm_addon_billing_mismatch' === $reason->get_error_code() ) {
				$why    = $this->extras_refusal( $plan, $license, $now );
				$reason = null !== $why ? $why : $reason;
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
	// Package extras for an existing licence
	// -------------------------------------------------------------------------

	/**
	 * The `wplm_package_extras` context for adding extras to a licence through an add-on plan, or null
	 * when the licence cannot be priced for extras: another product, or billed other than every month,
	 * every year or lifetime (a fixed-term or base-less licence, another interval).
	 *
	 * Held modules are those the licence already has for the same term — a lifetime line, or a line
	 * on the subscription that bills the licence — as the stored add-ons' "already has" rule reads it.
	 *
	 * @param Plan     $plan    The add-on plan.
	 * @param License  $license The licence.
	 * @param int|null $now     Unix time (tests).
	 * @return array|null
	 */
	public function extras_context( Plan $plan, License $license, ?int $now = null ): ?array {
		unset( $now );
		if ( ! $plan->is_addon() || null === $plan->profile || $plan->profile !== $license->profile ) {
			return null;
		}
		$profile = $this->profiles->get( $license->profile );
		if ( null === $profile ) {
			return null;
		}
		$lines   = $this->entitlements->lines( $license->id );
		$billing = $this->billing( $license, $profile, $lines );
		$key     = self::billing_key_of( $billing );
		if ( null === $key ) {
			return null;
		}
		$sub    = $billing['subscription'];
		$addons = 'lifetime' === $key ? $this->addons_subscription( $license ) : null;
		$held   = array();
		foreach ( $lines as $line ) {
			if ( Entitlement::KIND_MODULE === $line->kind
				&& ( null === $line->paid_through
					|| ( null !== $sub && $line->subscription_id === $sub->id )
					|| ( null !== $addons && $line->subscription_id === $addons->id ) ) ) {
				$held[] = $line->code;
			}
		}
		return array(
			'profile'      => $profile,
			'plan'         => $plan,
			'billing'      => $key,
			'flow'         => PackageExtrasService::FLOW_EXISTING,
			'package'      => null,
			'license'      => $license,
			'held_modules' => array_values( array_unique( $held ) ),
		);
	}

	/**
	 * What extras for a licence are sold against: the subscription that bills it and the day it is
	 * paid through (both null for a lifetime licence). Kept in the snapshot at checkout, so fulfilment
	 * can tell when the plan renewed or changed in between.
	 *
	 * @param License $license The licence.
	 * @return array{target_subscription_id: int|null, target_paid_through: string|null}
	 */
	public function extras_target( License $license ): array {
		$profile = $this->profiles->get( $license->profile );
		$billing = null !== $profile ? $this->billing( $license, $profile, $this->entitlements->lines( $license->id ) ) : array( 'type' => 'none' );
		$sub     = 'recurring' === $billing['type'] ? $billing['subscription'] : null;
		$addons  = $this->addons_subscription( $license );
		return array(
			'target_subscription_id' => null !== $sub ? (int) $sub->id : null,
			'target_paid_through'    => null !== $sub ? $billing['paid_through'] : null,
			'addons_subscription_id' => null !== $addons ? (int) $addons->id : null,
			'addons_paid_through'    => null !== $addons ? $this->addons_paid_through( $addons ) : null,
		);
	}

	/**
	 * The open add-ons-only subscription of a licence: recurring add-ons on a lifetime licence (v2).
	 *
	 * @param License $license The licence.
	 * @return Subscription|null
	 */
	public function addons_subscription( License $license ): ?Subscription {
		return $this->subscriptions->find_addons_for_license( $license->id );
	}

	/**
	 * Create the add-ons-only subscription of a lifetime licence (v2): pending until its first add-on
	 * is written, billed every 1 `$cycle`, its first period paid now (next payment = now + 1 cycle), and
	 * linked through `addons_license_id` — never `license_id`, so its status never reaches the licence.
	 *
	 * @param License   $license The lifetime licence.
	 * @param \WC_Order $order   The order that pays its first period.
	 * @param string    $cycle   `month` or `year`.
	 * @param int|null  $now     Unix time (tests).
	 * @return Subscription
	 */
	public function create_addons_subscription( License $license, \WC_Order $order, string $cycle, ?int $now = null ): Subscription {
		if ( ! in_array( $cycle, PackageExtrasService::CYCLES, true ) ) {
			throw new \RuntimeException( 'unknown add-ons billing cycle' );
		}
		// The link to the licence lives in a column the 1.3.0 upgrade adds: make sure it has run.
		if ( ! SubscriptionRepository::has_addons_column() ) {
			\WPLM\Plugin::get_instance()->maybe_upgrade_db();
			if ( ! SubscriptionRepository::has_addons_column() ) {
				throw new \RuntimeException( 'the WP License Manager 1.3.0 database upgrade has not run yet (open any admin page, then retry)' );
			}
		}
		$next  = ( new \DateTimeImmutable( '@' . ( $now ?? time() ) ) )->modify( '+1 ' . $cycle )->format( 'Y-m-d H:i:s' );
		$token = null;
		if ( class_exists( '\WC_Payment_Tokens' ) ) {
			$default = \WC_Payment_Tokens::get_customer_default_token( $order->get_customer_id() );
			$token   = $default ? $default->get_id() : null;
		}
		return $this->subscription_service()->create(
			array(
				'user_id'           => (int) $order->get_customer_id(),
				'billing_interval'  => 1,
				'billing_period'    => $cycle,
				'recurring_total'   => 0,
				'currency'          => $order->get_currency() ?: ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD' ),
				'next_payment'      => $next,
				'parent_order_id'   => $order->get_id(),
				'payment_method'    => $order->get_payment_method(),
				'payment_token_id'  => $token,
				'addons_license_id' => $license->id,
			)
		);
	}

	/** The subscription service (from the container: the constructor is unchanged). */
	private function subscription_service(): \WPLM\Services\Subscriptions\SubscriptionService {
		return \WPLM\Plugin::get_instance()->container()->make( \WPLM\Services\Subscriptions\SubscriptionService::class );
	}

	/**
	 * {@see addons_label()} from anywhere (admin screens, templates).
	 *
	 * @param Subscription $sub The subscription.
	 * @return string
	 */
	public static function label_for( Subscription $sub ): string {
		if ( ! $sub->is_addons_only() ) {
			return '';
		}
		return \WPLM\Plugin::get_instance()->container()->make( self::class )->addons_label( $sub );
	}

	/**
	 * "Add-ons for licence ending ABCD": how an add-ons-only subscription is shown.
	 *
	 * @param Subscription $sub The subscription.
	 * @return string Empty when it is not an add-ons-only subscription.
	 */
	public function addons_label( Subscription $sub ): string {
		if ( ! $sub->is_addons_only() ) {
			return '';
		}
		$license = $this->licenses->find_by_id( (int) $sub->addons_license_id );
		/* translators: %s: "licence ending ABCD" */
		return null !== $license ? sprintf( __( 'Add-ons for %s', 'wp-license-manager' ), self::licence_label( $license ) ) : __( 'Add-ons for a deleted licence', 'wp-license-manager' );
	}

	/**
	 * The cycle new recurring add-ons of a licence are forced to: its add-ons subscription's, or null
	 * (none yet: the customer chooses).
	 *
	 * @param License $license The licence.
	 * @return string|null `month` or `year`.
	 */
	public function addons_cycle( License $license ): ?string {
		$sub = $this->addons_subscription( $license );
		if ( null === $sub || 1 !== (int) $sub->billing_interval || ! in_array( $sub->billing_period, PackageExtrasService::CYCLES, true ) ) {
			return null;
		}
		return $sub->billing_period;
	}

	/**
	 * An order of the licence's owner, paid or not yet, whose monthly / yearly add-ons for the licence
	 * are still to be written: pending payment, failed (the customer can still pay it from My account),
	 * on hold (a bank transfer awaiting the shop), processing, or completed with its add-ons not added
	 * (awaiting a retry). Its add-ons open — or join — the licence's one add-ons subscription when it is
	 * completed, on its cycle, so until then that cycle is the licence's: an order on the other cycle
	 * could be paid for and never added. A paid one (on hold, processing, completed) comes before one
	 * not paid yet, which may never be. A line refunded in full (its whole quantity, or its whole total)
	 * no longer counts: the shop took it back.
	 *
	 * @param License $license    The lifetime licence.
	 * @param int     $skip_order An order not to count (the one being paid for now), 0 for none.
	 * @param bool    $paid_only  Count only orders paid for (or awaiting the shop's confirmation).
	 * @return array{order: \WC_Order, cycle: string}|null The oldest such order and its cycle.
	 */
	public function unfulfilled_addons_cycle( License $license, int $skip_order = 0, bool $paid_only = false ): ?array {
		$unpaid = null;
		foreach ( $this->unfulfilled_addon_lines( $license, $skip_order, $paid_only ) as $line ) {
			$snapshot = json_decode( (string) $line['item']->get_meta( '_wplm_extras' ), true );
			$cycle    = is_array( $snapshot ) ? ( $snapshot['cycle'] ?? null ) : null;
			if ( ! in_array( $cycle, PackageExtrasService::CYCLES, true ) ) {
				continue;
			}
			$found = array(
				'order' => $line['order'],
				'cycle' => $cycle,
			);
			if ( $line['paid'] ) {
				return $found;
			}
			$unpaid = $unpaid ?? $found;
		}
		return $unpaid;
	}

	/**
	 * The add-on lines for a licence that orders of its owner are still to write, oldest order first:
	 * orders pending payment or failed (unless `$paid_only`), on hold, processing, or completed with
	 * their add-ons not all added. A line already added, taken back, or refunded in full is not one, nor
	 * a line fulfilment would not add to the licence ({@see adds_to_licence()}).
	 *
	 * @param License $license    The licence.
	 * @param int     $skip_order An order not to count, 0 for none.
	 * @param bool    $paid_only  Only orders paid for (or awaiting the shop's confirmation).
	 * @return array<int, array{order: \WC_Order, item: \WC_Order_Item_Product, paid: bool}>
	 */
	private function unfulfilled_addon_lines( License $license, int $skip_order, bool $paid_only ): array {
		if ( ! function_exists( 'wc_get_orders' ) || (int) $license->user_id <= 0 ) {
			return array();
		}
		$paid   = array( 'on-hold', 'processing', 'completed' );
		$orders = wc_get_orders(
			array(
				'customer_id' => (int) $license->user_id,
				'status'      => $paid_only ? $paid : array_merge( array( 'pending', 'failed' ), $paid ),
				'type'        => 'shop_order',
				'limit'       => -1,
				'orderby'     => 'date',
				'order'       => 'ASC',
			)
		);
		$found  = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order || $order->get_id() === $skip_order
				|| ( 'completed' === $order->get_status() && '1' === (string) $order->get_meta( '_wplm_plan_fulfilled' ) ) ) {
				continue;
			}
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product || (int) $item->get_meta( '_wplm_addon_license_id' ) !== (int) $license->id
					|| '' !== (string) $item->get_meta( '_wplm_entitlement_ids' ) || '1' === (string) $item->get_meta( '_wplm_addon_reverted' )
					|| self::refunded_in_full( $order, $item ) || ! $this->adds_to_licence( $item ) ) {
					continue;
				}
				$found[] = array(
					'order' => $order,
					'item'  => $item,
					'paid'  => in_array( $order->get_status(), $paid, true ),
				);
			}
		}
		return $found;
	}

	/**
	 * Whether fulfilment adds an order line to the licence it names (`_wplm_addon_license_id`) rather
	 * than issuing a licence for it, routed as {@see \WPLM\Integrations\WooCommerce\PlanCheckout} routes
	 * it: extras with no licence type (an add-on plan's), or a stored package whose plan is an add-on
	 * plan — or that no longer exists (fulfilment fails for it). A licence type's line is never one,
	 * whatever licence id it carries: orders placed before 1.3.0 may carry a stray one.
	 *
	 * @param \WC_Order_Item_Product $item The order line.
	 * @return bool
	 */
	private function adds_to_licence( \WC_Order_Item_Product $item ): bool {
		if ( '' !== (string) $item->get_meta( '_wplm_dynamic_package' ) ) {
			return false;
		}
		$package_id = (int) $item->get_meta( '_wplm_package_id' );
		if ( $package_id <= 0 ) {
			return '' !== (string) $item->get_meta( '_wplm_extras' );
		}
		$package = $this->package( $package_id );
		if ( null === $package ) {
			return true;
		}
		$plan = $this->plan( $package->plan_id );
		return null !== $plan && $plan->is_addon();
	}

	/**
	 * The modules paid orders of a licence's owner will add to it when completed (or added again, for a
	 * completed one awaiting a retry): each with the oldest such order. Monthly / yearly extras for a
	 * lifetime licence join its add-ons subscription; `$addons_subscription = false` leaves them out,
	 * as a stored add-on's "already has" check does not count that subscription's lines. Term items
	 * (packs) and dated one-time packs are never counted: they stack.
	 *
	 * @param License $license             The licence.
	 * @param int     $skip_order          An order not to count (the one being paid for now), 0 for none.
	 * @param bool    $addons_subscription Count modules that join the licence's add-ons subscription.
	 * @return array<string, \WC_Order> Module code => order.
	 */
	public function unfulfilled_addon_modules( License $license, int $skip_order = 0, bool $addons_subscription = true ): array {
		$modules = array();
		foreach ( $this->unfulfilled_addon_lines( $license, $skip_order, true ) as $line ) {
			$item       = $line['item'];
			$package_id = (int) $item->get_meta( '_wplm_package_id' );
			if ( $package_id > 0 ) {
				$package = $this->package( $package_id );
				$codes   = null !== $package ? self::package_module_codes( $package ) : array();
			} else {
				$snapshot = json_decode( (string) $item->get_meta( '_wplm_extras' ), true );
				$items    = is_array( $snapshot ) && is_array( $snapshot['items'] ?? null ) ? $snapshot['items'] : array();
				$cycle    = is_array( $snapshot ) ? ( $snapshot['cycle'] ?? null ) : null;
				$codes    = $addons_subscription || ! in_array( $cycle, PackageExtrasService::CYCLES, true ) ? self::items_module_codes( $items ) : array();
			}
			foreach ( $codes as $code ) {
				$modules[ $code ] = $modules[ $code ] ?? $line['order'];
			}
		}
		return $modules;
	}

	/**
	 * Why extras for a licence cannot be sold now: a module they add is already being added by a paid
	 * order of the customer's not completed yet ({@see unfulfilled_addon_modules()}) — both would be
	 * charged, and the second refused at fulfilment. Null when none is.
	 *
	 * @param License $license    The licence.
	 * @param array[] $items      The extras (items with `term_days` and `lines`).
	 * @param int     $skip_order An order not to count (the one being paid for now), 0 for none.
	 * @return \WP_Error|null
	 */
	public function extras_unfulfilled_problem( License $license, array $items, int $skip_order = 0 ): ?\WP_Error {
		$codes = self::items_module_codes( $items );
		if ( array() === $codes ) {
			return null;
		}
		$pending = array_intersect_key( $this->unfulfilled_addon_modules( $license, $skip_order ), array_flip( $codes ) );
		return array() === $pending ? null : $this->unfulfilled_error( $license, $pending );
	}

	/**
	 * {@see extras_unfulfilled_problem()} for a stored add-on, as its own "already has" check reads it:
	 * a package that adds a limit is never refused for its modules, and a module-only one is refused
	 * when every module it adds is held or being added by a paid order not completed yet.
	 *
	 * @param License $license    The licence.
	 * @param Package $package    The add-on.
	 * @param int     $skip_order An order not to count (the one being paid for now), 0 for none.
	 * @return \WP_Error|null
	 */
	public function package_unfulfilled_problem( License $license, Package $package, int $skip_order = 0 ): ?\WP_Error {
		$codes = self::package_module_codes( $package );
		foreach ( $package->entitlements as $template ) {
			if ( Entitlement::KIND_MODULE !== ( $template['kind'] ?? '' ) ) {
				return null;
			}
		}
		if ( array() === $codes ) {
			return null;
		}
		$pending = array_intersect_key( $this->unfulfilled_addon_modules( $license, $skip_order, false ), array_flip( $codes ) );
		if ( array() === $pending || array() !== array_diff( $codes, array_keys( $pending ), $this->package_held_modules( $license ) ) ) {
			return null;
		}
		return $this->unfulfilled_error( $license, $pending );
	}

	/**
	 * The modules a licence holds as a stored add-on's "already has" check reads them: a lifetime line,
	 * or a line on the subscription that bills the licence.
	 *
	 * @param License $license The licence.
	 * @return string[]
	 */
	private function package_held_modules( License $license ): array {
		$profile = $this->profiles->get( $license->profile );
		if ( null === $profile ) {
			return array();
		}
		$lines = $this->entitlements->lines( $license->id );
		$sub   = $this->billing( $license, $profile, $lines )['subscription'] ?? null;
		$held  = array();
		foreach ( $lines as $line ) {
			if ( Entitlement::KIND_MODULE === $line->kind && ( null === $line->paid_through || ( null !== $sub && $line->subscription_id === $sub->id ) ) ) {
				$held[] = $line->code;
			}
		}
		return array_values( array_unique( $held ) );
	}

	/**
	 * "Your paid order #12, not completed yet, already adds Factory to your licence ending ABCD."
	 *
	 * @param License                  $license The licence.
	 * @param array<string, \WC_Order> $pending Module code => the order adding it.
	 * @return \WP_Error
	 */
	private function unfulfilled_error( License $license, array $pending ): \WP_Error {
		$order   = reset( $pending );
		$codes   = array_keys( array_filter( $pending, static fn( $o ) => $o->get_id() === $order->get_id() ) );
		$profile = $this->profiles->get( $license->profile );
		$names   = implode( ', ', null !== $profile ? array_map( array( $profile, 'code_label' ), $codes ) : $codes );
		$message = 'completed' === $order->get_status()
			/* translators: 1: order number, 2: module names, 3: licence label (licence ending ABCD) */
			? __( 'Your order #%1$s is still adding %2$s to your %3$s. Please contact us.', 'wp-license-manager' )
			/* translators: 1: order number, 2: module names, 3: licence label (licence ending ABCD) */
			: __( 'Your paid order #%1$s, not completed yet, already adds %2$s to your %3$s.', 'wp-license-manager' );
		return $this->error( 'addons_unfulfilled', sprintf( $message, $order->get_order_number(), $names, self::licence_label( $license ) ) );
	}

	/**
	 * The modules extras add for the plan's term: module lines of every item that is not a term item
	 * (a pack, which stacks).
	 *
	 * @param array[] $items Extras items (`term_days`, `lines`).
	 * @return string[]
	 */
	private static function items_module_codes( array $items ): array {
		$codes = array();
		foreach ( $items as $extra ) {
			if ( ! is_array( $extra ) || ( isset( $extra['term_days'] ) && null !== $extra['term_days'] ) ) {
				continue;
			}
			foreach ( (array) ( $extra['lines'] ?? array() ) as $line ) {
				if ( is_array( $line ) && Entitlement::KIND_MODULE === ( $line['kind'] ?? '' ) ) {
					$codes[] = (string) $line['code'];
				}
			}
		}
		return array_values( array_unique( $codes ) );
	}

	/**
	 * The modules a stored add-on adds for the licence's term; none for a dated one-time pack (it
	 * stacks).
	 *
	 * @param Package $package The add-on.
	 * @return string[]
	 */
	private static function package_module_codes( Package $package ): array {
		if ( Package::TYPE_ONETIME === $package->billing_type && (int) $package->valid_for_days > 0 ) {
			return array();
		}
		$codes = array();
		foreach ( $package->entitlements as $template ) {
			if ( Entitlement::KIND_MODULE === ( $template['kind'] ?? '' ) ) {
				$codes[] = (string) $template['code'];
			}
		}
		return array_values( array_unique( $codes ) );
	}

	/**
	 * A stored package by id.
	 *
	 * @param int $package_id Package id.
	 * @return Package|null
	 */
	private function package( int $package_id ): ?Package {
		$package = \WPLM\Plugin::get_instance()->container()->make( \WPLM\Repositories\PackageRepository::class )->find_by_id( $package_id );
		return $package instanceof Package ? $package : null;
	}

	/**
	 * Whether the shop refunded an order line in full: its whole quantity, or its whole total. Such a
	 * line is taken back like a reverted one: fulfilment never adds it, nor opens an add-ons
	 * subscription for it, and it holds nothing (the licence's cycle, leftovers of an interrupted
	 * attempt). Read live, never stamped: a refund the shop deletes makes the line payable again.
	 *
	 * @param \WC_Order              $order The order.
	 * @param \WC_Order_Item_Product $item  Its line.
	 * @return bool
	 */
	public static function refunded_in_full( \WC_Order $order, \WC_Order_Item_Product $item ): bool {
		$qty = (int) $item->get_quantity();
		if ( $qty > 0 && abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) ) >= $qty ) {
			return true;
		}
		$total = round( (float) $item->get_total(), 4 );
		return $total > 0 && round( (float) $order->get_total_refunded_for_item( $item->get_id() ), 4 ) >= $total;
	}

	/**
	 * Why an order not paid yet (paid later: from My account, the order-pay page or the block
	 * checkout) must not be paid now: add-ons on it for an existing licence that could no longer be
	 * added when it is completed. Every such line is checked as fulfilment will check it
	 * ({@see line_payment_problem()}): the licence still takes them, it has not since got a module they
	 * add (another order), no paid order of the customer's not completed yet adds one
	 * ({@see unfulfilled_addon_modules()}), and the add-on checks pass. A licence type's line is issued
	 * as a new licence, so it is not checked, whatever licence id it carries ({@see adds_to_licence()}).
	 * Monthly / yearly add-ons for a lifetime licence
	 * must also still fit its add-ons: not billed on the other cycle now (its add-ons subscription's,
	 * or a paid order's that will open it), not on another subscription than the one they were sold
	 * against, and one that can take them ({@see addons_join_problem()}). Checked before payment, as
	 * add to cart and checkout check a new order: a customer is never charged for add-ons that could
	 * not then be added. A line already added, taken back, or refunded in full is not checked.
	 *
	 * @param \WC_Order $order The order.
	 * @return \WP_Error|null
	 */
	public function payment_problem( \WC_Order $order ): ?\WP_Error {
		$cannot = __( 'They could not be added now, so this order cannot be paid: cancel it, and place a new order if you still want them.', 'wp-license-manager' );
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || (int) $item->get_meta( '_wplm_addon_license_id' ) <= 0
				|| '' !== (string) $item->get_meta( '_wplm_entitlement_ids' ) || '1' === (string) $item->get_meta( '_wplm_addon_reverted' )
				|| self::refunded_in_full( $order, $item ) || ! $this->adds_to_licence( $item ) ) {
				continue;
			}
			$snapshot = json_decode( (string) $item->get_meta( '_wplm_extras' ), true );
			$cycle    = is_array( $snapshot ) ? ( $snapshot['cycle'] ?? null ) : null;
			$license  = $this->licenses->find_by_id( (int) $item->get_meta( '_wplm_addon_license_id' ) );
			if ( null === $license ) {
				/* translators: %s: order number */
				return $this->error( 'addons_pay_licence', sprintf( __( 'Your order #%s adds add-ons to a licence that no longer exists.', 'wp-license-manager' ), $order->get_order_number() ) . ' ' . $cannot );
			}
			$problem = $this->line_payment_problem( $order, $item, $license );
			if ( null !== $problem ) {
				/* translators: 1: order number, 2: licence label (licence ending ABCD) */
				$adds = sprintf( __( 'Your order #%1$s adds add-ons to your %2$s.', 'wp-license-manager' ), $order->get_order_number(), self::licence_label( $license ) );
				return $this->error( 'addons_pay_line', $adds . ' ' . $problem . ' ' . $cannot );
			}
			if ( ! in_array( $cycle, PackageExtrasService::CYCLES, true ) ) {
				continue;
			}
			/* translators: 1: order number, 2: licence label (licence ending ABCD), 3: billing schedule (every month) */
			$what   = sprintf( __( 'Your order #%1$s adds monthly / yearly add-ons to your %2$s, billed %3$s.', 'wp-license-manager' ), $order->get_order_number(), self::licence_label( $license ), self::schedule( 1, $cycle ) );
			$addons = $this->addons_subscription( $license );
			$sold   = $snapshot['addons_subscription_id'] ?? null;
			if ( null !== $sold && ( null === $addons || (int) $sold !== (int) $addons->id ) && ! $this->ended_unbilled( (int) $sold ) ) {
				/* translators: %d: subscription id */
				return $this->error( 'addons_pay_sold_sub', $what . ' ' . sprintf( __( 'They were sold against subscription #%d, which no longer bills them.', 'wp-license-manager' ), (int) $sold ) . ' ' . $cannot );
			}
			if ( null !== $addons ) {
				if ( 1 !== (int) $addons->billing_interval || $cycle !== $addons->billing_period ) {
					/* translators: %s: billing schedule (every year) */
					return $this->error( 'addons_pay_cycle', $what . ' ' . sprintf( __( 'That licence\'s monthly / yearly add-ons are now billed %s.', 'wp-license-manager' ), self::schedule( (int) $addons->billing_interval, $addons->billing_period ) ) . ' ' . $cannot );
				}
				$problem = $this->addons_subscription_problem( $addons );
				if ( null !== $problem ) {
					return $this->error( 'addons_pay_join', $what . ' ' . $problem->get_error_message() );
				}
				continue;
			}
			$other = $this->unfulfilled_addons_cycle( $license, $order->get_id(), true );
			if ( null !== $other && $other['cycle'] !== $cycle ) {
				/* translators: 1: order number, 2: billing schedule (every year) */
				return $this->error( 'addons_pay_cycle', $what . ' ' . sprintf( __( 'Your paid order #%1$s adds them billed %2$s, and one licence\'s monthly / yearly add-ons are billed one way.', 'wp-license-manager' ), $other['order']->get_order_number(), self::schedule( 1, $other['cycle'] ) ) . ' ' . $cannot );
			}
		}
		return null;
	}

	/**
	 * Why one add-on line of an order paid later could not be added to its licence now, as fulfilment
	 * checks it: package extras through {@see checked_extras()}, a stored add-on through
	 * {@see check_for_user()} at the line's quantity. Null when it could.
	 *
	 * @param \WC_Order              $order   The order.
	 * @param \WC_Order_Item_Product $item    Its add-on line.
	 * @param License                $license The licence it adds to.
	 * @return string|null A sentence for the customer.
	 */
	private function line_payment_problem( \WC_Order $order, \WC_Order_Item_Product $item, License $license ): ?string {
		try {
			$package_id = (int) $item->get_meta( '_wplm_package_id' );
			if ( $package_id > 0 ) {
				$package = $this->package( $package_id );
				if ( null === $package ) {
					return __( 'The add-on bought is no longer sold.', 'wp-license-manager' );
				}
				$ok = $this->check_for_user( (int) $order->get_customer_id(), $license, $package, (int) $item->get_quantity() );
				if ( is_wp_error( $ok ) ) {
					return $ok->get_error_message();
				}
				$pending = $this->package_unfulfilled_problem( $license, $package, $order->get_id() );
				return null !== $pending ? $pending->get_error_message() : null;
			}
			$json = (string) $item->get_meta( '_wplm_extras' );
			if ( '' === $json ) {
				return null;
			}
			$plan = $this->plan( (int) $item->get_meta( '_wplm_plan_id' ) );
			if ( null === $plan || ! $plan->is_addon() ) {
				return __( 'The add-ons they were bought from are no longer sold.', 'wp-license-manager' );
			}
			$this->checked_extras( $order, $license, $plan, $json, null, true );
			return null;
		} catch ( \RuntimeException $e ) {
			$message = rtrim( $e->getMessage(), '. ' );
			return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $message, 0, 1 ) ) . mb_substr( $message, 1 ) . '.' : ucfirst( $message ) . '.';
		}
	}

	/**
	 * The cycle the product page offers a licence's new monthly / yearly add-ons on, when it is not the
	 * customer's to choose: its add-ons subscription's ({@see addons_cycle()}), else that of an order
	 * whose add-ons are still to be written ({@see unfulfilled_addons_cycle()}).
	 *
	 * @param License $license    The licence.
	 * @param int     $skip_order An order not to count, 0 for none.
	 * @return string|null `month` or `year`; null when the customer chooses.
	 */
	public function offered_cycle( License $license, int $skip_order = 0 ): ?string {
		$cycle = $this->addons_cycle( $license );
		if ( null === $cycle ) {
			$pending = $this->unfulfilled_addons_cycle( $license, $skip_order );
			$cycle   = null !== $pending ? $pending['cycle'] : null;
		}
		return $cycle;
	}

	/**
	 * Why new recurring add-ons cannot join a lifetime licence's add-ons subscription right now, or
	 * null when they can (or it has none: one is opened). Checked before payment — on the product
	 * page, at add to cart and at checkout — and again at fulfilment, so a customer is never charged for
	 * a monthly or yearly add-on that could not then be added, or that would never renew:
	 *
	 * - set to cancel at the end of its period (`pending-cancel`): it never renews;
	 * - on hold: paused by the customer while still paid up (resume it), else unpaid;
	 * - its paid period over (a renewal not yet paid, in grace): the new add-on would be billed again
	 *   at once.
	 *
	 * A pending subscription (opened, nothing billed yet) is fine: its first period starts when the
	 * add-ons are written ({@see open_addons_period()}). Lifetime-priced add-ons never join it and are
	 * never refused for it.
	 *
	 * @param License  $license The lifetime licence.
	 * @param int|null $now     Unix time (tests).
	 * @return \WP_Error|null
	 */
	public function addons_join_problem( License $license, ?int $now = null ): ?\WP_Error {
		$addons = $this->addons_subscription( $license );
		return null !== $addons ? $this->addons_subscription_problem( $addons, $now ) : null;
	}

	/**
	 * {@see addons_join_problem()} for a given add-ons subscription.
	 *
	 * @param Subscription $addons The add-ons subscription.
	 * @param int|null     $now    Unix time (tests).
	 * @return \WP_Error|null
	 */
	private function addons_subscription_problem( Subscription $addons, ?int $now = null ): ?\WP_Error {
		if ( 'pending' === $addons->status ) {
			return null;
		}
		if ( 'pending-cancel' === $addons->status ) {
			return $this->error( 'addons_ending', __( 'The monthly / yearly add-ons of that licence are set to end at the end of their period. Renew them under My Account → Subscriptions first, then add this.', 'wp-license-manager' ) );
		}
		if ( ! in_array( $addons->status, array( 'active', 'trial', 'on-hold' ), true ) ) {
			return $this->error( 'addons_ended', __( 'The monthly / yearly add-ons of that licence have ended. Please contact us.', 'wp-license-manager' ) );
		}
		$through = $this->addons_paid_through( $addons );
		$today   = wp_date( 'Y-m-d', $now ?? time() );
		$overdue = null === $through || strcmp( $through, $today ) < 0;
		if ( 'on-hold' === $addons->status && ! $overdue ) {
			return $this->error( 'addons_paused', __( 'The monthly / yearly add-ons of that licence are paused. Resume them under My Account → Subscriptions first, then add this.', 'wp-license-manager' ) );
		}
		if ( 'on-hold' === $addons->status || $overdue ) {
			return $this->error( 'addons_overdue', __( 'Pay the licence\'s add-ons renewal first, then add this.', 'wp-license-manager' ) );
		}
		return null;
	}

	/**
	 * The day an add-ons subscription is paid through: its lines' latest, else the day before its next
	 * payment.
	 *
	 * @param Subscription $sub The add-ons subscription.
	 * @return string|null Y-m-d.
	 */
	public function addons_paid_through( Subscription $sub ): ?string {
		$through = null;
		foreach ( $this->lines->get_by_subscription( $sub->id ) as $line ) {
			if ( null !== $line->paid_through && ( null === $through || strcmp( $line->paid_through, $through ) > 0 ) ) {
				$through = $line->paid_through;
			}
		}
		if ( null === $through && null !== $sub->next_payment ) {
			$through = EntitlementService::paid_through_for( $sub->next_payment );
		}
		return $through;
	}

	/**
	 * An add-ons subscription an order line recorded (`_wplm_addons_subscription_id`), when it may still
	 * bill that line's add-ons: pending (opened, nothing billed yet), active or in trial. One that was
	 * cancelled, expired, put on hold or set to cancel since is never reused: it would never renew them.
	 *
	 * @param int $id Subscription id (0 = none recorded).
	 * @return Subscription|null
	 */
	public function reusable_addons_subscription( int $id ): ?Subscription {
		$sub = $id > 0 ? $this->subscriptions->find_by_id( $id ) : null;
		return null !== $sub && $sub->is_addons_only() && in_array( $sub->status, array( 'pending', 'active', 'trial' ), true ) ? $sub : null;
	}

	/**
	 * Start the first period of an add-ons subscription that bills nothing yet (pending, no items) now:
	 * next payment = now + 1 cycle. It was opened when the order was first fulfilled, which may have been
	 * long before the add-ons are finally written (a retry, or another order joining it later); its first
	 * period is the one paid now, so the lines are paid through a full cycle from today and it is not
	 * due again at once.
	 *
	 * @param Subscription $sub The add-ons subscription.
	 * @param int|null     $now Unix time (tests).
	 * @return Subscription The subscription as it is now.
	 */
	public function open_addons_period( Subscription $sub, ?int $now = null ): Subscription {
		if ( 'pending' !== $sub->status || array() !== $this->subscriptions->get_items( $sub->id ) ) {
			return $sub;
		}
		$next = ( new \DateTimeImmutable( '@' . ( $now ?? time() ) ) )
			->modify( '+' . max( 1, (int) $sub->billing_interval ) . ' ' . $sub->billing_period )
			->format( 'Y-m-d H:i:s' );
		$this->subscriptions->update( $sub->id, array( 'next_payment' => $next ) );
		return $this->subscriptions->find_by_id( $sub->id ) ?? $sub;
	}

	/**
	 * Before a line's package extras are written (a retry), recognise what an interrupted earlier attempt
	 * wrote but never recorded ({@see adopt_unstamped()}).
	 *
	 * @param \WC_Order              $order   The order.
	 * @param \WC_Order_Item_Product $item    Its extras line.
	 * @param License                $license The licence the extras are on.
	 * @param string                 $json    The line's `_wplm_extras` snapshot.
	 * @return Entitlement[]|null The lines, now recorded on the line; null when nothing was left over.
	 * @throws \RuntimeException When what was left over is not exactly this line's add-ons.
	 */
	public function adopt_unstamped_extras( \WC_Order $order, $item, License $license, string $json ): ?array {
		return $this->adopt_unstamped(
			$order,
			$item,
			$license,
			fn(): array => $this->snapshot_units( $license, $json ),
			'_wplm_addon_subscription_item_ids'
		);
	}

	/**
	 * The units a line's package extras write ({@see write_units()}), read from its snapshot as it was
	 * sold: only their lines matter here, and they were checked when written.
	 *
	 * @param License $license The licence.
	 * @param string  $json    The line's `_wplm_extras` snapshot.
	 * @return array[]
	 * @throws \RuntimeException When the snapshot cannot be read.
	 */
	private function snapshot_units( License $license, string $json ): array {
		$profile = $this->profiles->get( $license->profile );
		if ( null === $profile ) {
			throw new \RuntimeException( 'the licence has no licence profile' );
		}
		$raw = json_decode( $json, true );
		$raw = is_array( $raw ) && is_string( $raw['billing'] ?? null ) ? $raw['billing'] : null;
		return $this->extras_units( $this->extras()->read_snapshot( $json, $raw, $profile )['items'] );
	}

	/**
	 * The one unit a stored add-on writes: its package's lines × the line's quantity.
	 *
	 * @param Package $package The add-on.
	 * @param int     $qty     The order line's quantity.
	 * @return array[]
	 */
	private static function package_units( Package $package, int $qty ): array {
		$qty   = max( 1, $qty );
		$lines = array();
		foreach ( $package->entitlements as $template ) {
			$kind    = (string) ( $template['kind'] ?? '' );
			$lines[] = array(
				'kind' => $kind,
				'code' => (string) ( $template['code'] ?? '' ),
				'qty'  => Entitlement::KIND_LIMIT === $kind ? (int) ( $template['qty'] ?? 0 ) * $qty : 0,
			);
		}
		return array(
			array(
				'lines'      => $lines,
				'qty'        => $qty,
				'line_total' => round( $package->price * $qty, 4 ),
				'meta'       => array( 'package_id' => $package->id ),
			),
		);
	}

	/**
	 * Before an order line's add-ons are written (a retry), recognise what an interrupted earlier attempt
	 * already wrote but never recorded: this order's add-on lines on the licence that no line of the order
	 * is stamped with (`_wplm_entitlement_ids`) — a crash between writing them and stamping the line.
	 *
	 * They are never taken back: the customer has them, and their subscription bills them. When they are
	 * exactly the lines this order line writes (same kinds, codes and quantities, in the order they are
	 * written), they are **adopted** — recorded on the line, any subscription item the attempt did not get
	 * to attached to the subscription the lines are on, a pending add-ons subscription started — and
	 * nothing is checked or written again: they were checked before they were written, and checking them
	 * now would only find them already there. When they are not, they may be another line's of the order
	 * (still to be recorded, same licence): exactly what that line adds, or the first of its lines (an
	 * attempt cut off part-way through writing that line) and not the first of this line's. Then nothing
	 * is adopted here, this line is written as usual, and that line deals with them in its turn. Anything
	 * else — the first of this line's own lines (this line's attempt was cut off part-way), or lines no
	 * line can be told apart for — changes nothing and refuses the line, naming the lines, for the owner
	 * to sort out by hand. Lines are written in order, one at a time, so an attempt cut off part-way
	 * always leaves the first of a line's lines.
	 *
	 * @param \WC_Order              $order     The order.
	 * @param \WC_Order_Item_Product $item      Its line.
	 * @param License                $license   The licence the line adds to.
	 * @param callable               $units     Returns the units the line writes, as {@see write_units()}
	 *                                          takes them (only called when something was left over).
	 * @param string                 $items_key `_wplm_addon_subscription_item_ids` (JSON list) or
	 *                                          `_wplm_addon_subscription_item_id` (one id).
	 * @return Entitlement[]|null The lines, now recorded on the line; null when nothing was left over for
	 *                            this line.
	 * @throws \RuntimeException When what was left over is not exactly this line's add-ons.
	 */
	private function adopt_unstamped( \WC_Order $order, $item, License $license, callable $units, string $items_key ): ?array {
		$stamped = array();
		foreach ( $order->get_items() as $other ) {
			if ( ! $other instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$ids = json_decode( (string) $other->get_meta( '_wplm_entitlement_ids' ), true );
			if ( is_array( $ids ) ) {
				$stamped = array_merge( $stamped, array_map( 'intval', $ids ) );
			}
		}
		$left = array_values( array_diff( $this->order_line_ids( $order->get_id(), $license->id ), $stamped ) );
		if ( array() === $left ) {
			return null;
		}
		sort( $left );
		$lines = array();
		foreach ( $left as $id ) {
			$line = $this->lines->find_by_id( (int) $id );
			if ( null !== $line ) {
				$lines[] = $line;
			}
		}
		// Another line of the order still to be recorded for this licence may have written them.
		$units  = $units();
		$refuse = fn(): ?\RuntimeException => $this->leftovers_refusal( $order, $item, $license, $left, $lines, $units );
		$by_row = count( $lines ) === count( $left ) ? self::match_rows( $lines, $units, true ) : null;
		if ( null === $by_row ) {
			return self::refused( $refuse() );
		}

		// Each unit billed on a subscription has its item there; one the attempt did not get to is
		// attached, to the subscription its lines are on — once everything has been checked.
		$plan   = array(); // Unit => [subscription, existing item id or null, line ids].
		$addons = array();
		foreach ( $units as $u => $unit ) {
			$unit_lines = $by_row[ $u ] ?? array();
			$sub_ids    = array_unique( array_map( static fn( Entitlement $l ) => (int) $l->subscription_id, $unit_lines ) );
			if ( count( $sub_ids ) > 1 ) {
				return self::refused( $refuse() );
			}
			$sub_id = (int) reset( $sub_ids );
			if ( $sub_id <= 0 ) {
				continue; // For life, or a dated pack: never on a subscription.
			}
			$sub = $this->subscriptions->find_by_id( $sub_id );
			if ( null === $sub ) {
				return self::refused( $refuse() );
			}
			if ( $sub->is_addons_only() && in_array( $sub->status, array( 'cancelled', 'expired' ), true ) ) {
				// Recorded there, they would lapse at their paid-through day and never renew.
				\WPLM\Support\Logger::error( sprintf( 'AddonService: order %d left add-on lines %s on licence %d unrecorded, on add-ons subscription %d, which has ended; nothing was changed.', $order->get_id(), implode( ', ', $left ), $license->id, $sub->id ) );
				throw new \RuntimeException(
					sprintf(
						/* translators: 1: line ids, 2: licence label, 3: subscription id */
						__( 'an earlier attempt added lines #%1$s to %2$s and was cut off before recording them. They are on add-ons subscription #%3$d, which has ended, so they would never renew. Nothing was changed and nothing was added; the lines stay on the licence until the day they are paid through. To have this line added and billed on the licence\'s add-ons subscription, remove those lines on the licence screen', 'wp-license-manager' ),
						implode( ', #', $left ),
						self::licence_label( $license ),
						$sub->id
					)
				);
			}
			$ids = array_map( static fn( Entitlement $l ) => (int) $l->id, $unit_lines );
			sort( $ids );
			$found = null;
			foreach ( $this->subscriptions->get_items( $sub_id ) as $sub_item ) {
				$billed = array_map( 'intval', (array) ( $sub_item->meta['entitlement_ids'] ?? array() ) );
				sort( $billed );
				if ( empty( $sub_item->meta['addon'] ) || array() === array_intersect( $billed, $ids ) ) {
					continue;
				}
				if ( $billed !== $ids || (int) ( $sub_item->meta['order_item_id'] ?? 0 ) !== (int) $item->get_id() ) {
					return self::refused( $refuse() ); // Billed for another line: not this line's to adopt.
				}
				$found = (int) $sub_item->id;
			}
			$plan[ $u ] = array( $sub, $found, $ids );
			if ( $sub->is_addons_only() ) {
				$addons[ $sub_id ] = true;
			}
		}
		$sub_items = array();
		foreach ( $plan as $u => list( $sub, $found, $ids ) ) {
			$meta = (array) ( $units[ $u ]['meta'] ?? array() );
			if ( $sub->is_addons_only() ) {
				$meta['addons_only'] = true;
			}
			$sub_items[] = $found ?? $this->attach( $sub, $order, $item, max( 1, (int) $units[ $u ]['qty'] ), (float) $units[ $u ]['line_total'], $meta, $license, $ids );
		}

		if ( array() !== $sub_items ) {
			$item->update_meta_data( $items_key, '_wplm_addon_subscription_item_id' === $items_key ? $sub_items[0] : wp_json_encode( $sub_items ) );
		}
		$item->update_meta_data( '_wplm_entitlement_ids', wp_json_encode( $left ) );
		$item->save_meta_data();
		foreach ( array_keys( $addons ) as $sub_id ) {
			$this->activate_addons_subscription( (int) $sub_id );
		}

		$profile = $this->profiles->get( $license->profile );
		\WPLM\Support\Logger::error( sprintf( 'AddonService: order %d had written add-on lines %s on licence %d without recording them (an interrupted fulfilment); recorded now, nothing written twice.', $order->get_id(), implode( ', ', $left ), $license->id ) );
		$order->add_order_note(
			sprintf(
				/* translators: 1: what was added, 2: licence label */
				__( 'WPLM: %1$s had already been added to %2$s by an earlier attempt that was cut off before it was recorded. It is recorded now; nothing was added or billed twice.', 'wp-license-manager' ),
				null !== $profile ? self::describe_lines( $lines, $profile, false ) : __( 'The add-ons', 'wp-license-manager' ),
				self::licence_label( $license )
			)
		);
		return $lines;
	}

	/**
	 * {@see adopt_unstamped()}'s way out when the leftovers are not this line's: throw the refusal, or —
	 * when another line of the order still to be recorded may own them — adopt nothing (null).
	 *
	 * @param \RuntimeException|null $refusal The refusal, or null.
	 * @return null
	 * @throws \RuntimeException The refusal.
	 */
	private static function refused( ?\RuntimeException $refusal ) {
		if ( null !== $refusal ) {
			throw $refusal;
		}
		return null;
	}

	/**
	 * Whether lines are what units write, in the order they are written: all of it (`$whole`), or its
	 * first lines (an attempt cut off part-way).
	 *
	 * @param Entitlement[] $lines The lines, oldest first.
	 * @param array[]       $units Units, as {@see write_units()} takes them.
	 * @param bool          $whole All of it, or its first lines.
	 * @return array<int, Entitlement[]>|null The lines by unit; null when they are not.
	 */
	private static function match_rows( array $lines, array $units, bool $whole ): ?array {
		$by_row = array();
		$next   = 0;
		foreach ( $units as $u => $unit ) {
			foreach ( (array) ( $unit['lines'] ?? array() ) as $expected ) {
				$line = $lines[ $next ] ?? null;
				if ( null === $line ) {
					return $whole ? null : $by_row;
				}
				$kind = (string) ( $expected['kind'] ?? '' );
				$qty  = Entitlement::KIND_LIMIT === $kind ? (int) ( $expected['qty'] ?? 0 ) : 0;
				if ( $kind !== $line->kind || (string) ( $expected['code'] ?? '' ) !== $line->code
					|| ( Entitlement::KIND_LIMIT === $kind && $qty !== (int) $line->qty ) ) {
					return null;
				}
				$by_row[ $u ][] = $line;
				++$next;
			}
		}
		return count( $lines ) === $next ? $by_row : null;
	}

	/**
	 * Why leftovers ({@see adopt_unstamped()}) are not adopted by this line: null when they are another
	 * line's of the order — exactly what it adds, or the first of its lines and not the first of this
	 * line's — so this line is written as usual and that line deals with them; else the refusal.
	 *
	 * @param \WC_Order              $order   The order.
	 * @param \WC_Order_Item_Product $item    This line.
	 * @param License                $license The licence.
	 * @param int[]                  $left    The leftover line ids.
	 * @param Entitlement[]          $lines   Those lines, oldest first.
	 * @param array[]                $units   The units this line writes.
	 * @return \RuntimeException|null
	 */
	private function leftovers_refusal( \WC_Order $order, $item, License $license, array $left, array $lines, array $units ): ?\RuntimeException {
		if ( count( $lines ) === count( $left ) ) {
			$theirs = false;
			foreach ( $order->get_items() as $other ) {
				if ( ! $other instanceof \WC_Order_Item_Product || $other->get_id() === $item->get_id()
					|| '' !== (string) $other->get_meta( '_wplm_entitlement_ids' ) || '1' === (string) $other->get_meta( '_wplm_addon_reverted' )
					|| self::refunded_in_full( $order, $other ) ) {
					continue; // A line refunded in full is never added: nothing left over is its.
				}
				$other_units = $this->line_units( $other, $license );
				if ( null === $other_units ) {
					continue;
				}
				if ( null !== self::match_rows( $lines, $other_units, true ) ) {
					return null; // Exactly that line's: whichever line records them, the licence gets what was paid for.
				}
				$theirs = $theirs || null !== self::match_rows( $lines, $other_units, false );
			}
			if ( $theirs && null === self::match_rows( $lines, $units, false ) ) {
				return null; // The first of that line's lines, never of this one's: that line is refused for them.
			}
		}
		\WPLM\Support\Logger::error( sprintf( 'AddonService: order %d left add-on lines %s on licence %d unrecorded, and they are not exactly what its line adds; nothing was changed.', $order->get_id(), implode( ', ', $left ), $license->id ) );
		return new \RuntimeException(
			sprintf(
				/* translators: 1: line ids, 2: licence label */
				__( 'an earlier attempt was cut off part-way: it left add-on lines #%1$s on %2$s without recording them, and they are not all this line adds. Nothing was changed and nothing was added; those lines stay on the licence until someone removes them. To finish, remove those lines on the licence screen and set the order to Completed again: the whole line is then added', 'wp-license-manager' ),
				implode( ', #', $left ),
				self::licence_label( $license )
			)
		);
	}

	/**
	 * The units another line of the order writes on a licence, or null when it writes none there (or
	 * they cannot be read): package extras for the licence it adds to or issued, or a stored add-on for
	 * the licence it adds to.
	 *
	 * @param \WC_Order_Item_Product $other   The line.
	 * @param License                $license The licence.
	 * @return array[]|null
	 */
	private function line_units( \WC_Order_Item_Product $other, License $license ): ?array {
		$adds   = (int) $other->get_meta( '_wplm_addon_license_id' ) === (int) $license->id;
		$issued = json_decode( (string) $other->get_meta( '_wplm_license_ids' ), true );
		$issued = is_array( $issued ) && in_array( (int) $license->id, array_map( 'intval', $issued ), true );
		$json   = (string) $other->get_meta( '_wplm_extras' );
		try {
			if ( '' !== $json && ( $adds || $issued ) ) {
				return $this->snapshot_units( $license, $json );
			}
			$package_id = (int) $other->get_meta( '_wplm_package_id' );
			if ( '' === $json && $adds && $package_id > 0 ) {
				$package = \WPLM\Plugin::get_instance()->container()->make( \WPLM\Repositories\PackageRepository::class )->find_by_id( $package_id );
				return $package instanceof Package ? self::package_units( $package, (int) $other->get_quantity() ) : null;
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return null;
	}

	/**
	 * A pending add-ons subscription starts billing once its add-ons are written. A listener on the
	 * status change that fails must not undo that: the add-ons are written and recorded, and a
	 * subscription left pending would never renew them.
	 *
	 * @param int $id Subscription id.
	 * @return void
	 */
	private function activate_addons_subscription( int $id ): void {
		$sub = $this->subscriptions->find_by_id( $id );
		if ( null === $sub || 'pending' !== $sub->status ) {
			return;
		}
		try {
			$this->subscription_service()->update_status( $id, 'active' );
		} catch ( \Throwable $e ) {
			\WPLM\Support\Logger::error( 'AddonService: starting add-ons subscription ' . $id . ' raised — ' . $e->getMessage() );
			$fresh = $this->subscriptions->find_by_id( $id );
			if ( null !== $fresh && 'pending' === $fresh->status ) {
				$this->subscriptions->update_status( $id, 'active' );
			}
		}
	}

	/**
	 * The extras key of a licence's billing ({@see billing()}): `month`, `year`, `lifetime` or null.
	 *
	 * @param array $billing From {@see billing()}.
	 * @return string|null
	 */
	public static function billing_key_of( array $billing ): ?string {
		if ( 'lifetime' === $billing['type'] ) {
			return 'lifetime';
		}
		$sub = $billing['subscription'] ?? null;
		if ( 'recurring' === $billing['type'] && $sub instanceof Subscription
			&& 1 === (int) $sub->billing_interval && in_array( $sub->billing_period, array( 'month', 'year' ), true ) ) {
			return $sub->billing_period;
		}
		return null;
	}

	/**
	 * The extras offered to a licence, when it may take add-ons at all right now (the add-on checks on
	 * an empty add-on of the licence's billing: status, trial, overdue, base module).
	 *
	 * @param Plan     $plan    The add-on plan.
	 * @param License  $license The licence.
	 * @param int|null $now     Unix time (tests).
	 * @return array[] Offers ({@see PackageExtrasService::offers()}); empty when none or refused.
	 */
	public function extras_offers( Plan $plan, License $license, ?int $now = null ): array {
		$ctx = $this->extras_context( $plan, $license, $now );
		if ( null === $ctx || true !== $this->check( $license, $this->extras_package( $plan, $ctx['billing'] ), $now ) ) {
			return array();
		}
		return $this->joinable_offers( $this->extras()->offers( $ctx ), $license, $now );
	}

	/**
	 * Offers without the monthly / yearly ones a lifetime licence cannot take now
	 * ({@see addons_join_problem()}).
	 *
	 * @param array[]  $offers  Offers.
	 * @param License  $license The licence.
	 * @param int|null $now     Unix time (tests).
	 * @return array[]
	 */
	private function joinable_offers( array $offers, License $license, ?int $now ): array {
		$recurring = array_filter( $offers, static fn( $o ) => null !== ( $o['recurring'] ?? null ) );
		if ( array() === $recurring || null === $this->addons_join_problem( $license, $now ) ) {
			return $offers;
		}
		return array_values( array_filter( $offers, static fn( $o ) => null === ( $o['recurring'] ?? null ) ) );
	}

	/**
	 * Why a licence is offered no extras, or null when it is (or when the plan's stored packages
	 * already say why: a plan with packages and no extras keeps its own reason).
	 *
	 * @param Plan     $plan    The add-on plan.
	 * @param License  $license The licence.
	 * @param int|null $now     Unix time (tests).
	 * @return \WP_Error|null
	 */
	private function extras_refusal( Plan $plan, License $license, ?int $now ): ?\WP_Error {
		$ctx    = $this->extras_context( $plan, $license, $now );
		$stored = array() !== array_filter( $plan->packages, static fn( Package $p ) => 1 === (int) $p->status );
		$probe  = $this->extras_package( $plan, null !== $ctx ? $ctx['billing'] : $this->probe_billing( $license ) );
		$result = $this->check( $license, $probe, $now );
		if ( is_wp_error( $result ) ) {
			return null === $ctx && $stored && 'wplm_addon_billing_mismatch' === $result->get_error_code() ? null : $result;
		}
		if ( null === $ctx ) {
			return $stored ? null : $this->error( 'no_extras', __( 'Add-ons are added to a licence billed monthly, yearly or for life.', 'wp-license-manager' ) );
		}
		$offers = $this->extras()->offers( $ctx );
		if ( array() === $this->joinable_offers( $offers, $license, $now ) ) {
			$why = array() !== $offers ? $this->addons_join_problem( $license, $now ) : null;
			if ( null !== $why ) {
				return $why;
			}
			return $stored ? null : $this->error( 'no_extras', __( 'There is nothing more to add to that licence right now.', 'wp-license-manager' ) );
		}
		return null;
	}

	/**
	 * The billing to probe a licence with when it has no extras key: its own (so the add-on checks give
	 * their reason, e.g. a fixed-term licence), else monthly.
	 *
	 * @param License $license The licence.
	 * @return string
	 */
	private function probe_billing( License $license ): string {
		$profile = $this->profiles->get( $license->profile );
		if ( null === $profile ) {
			return 'month';
		}
		$billing = $this->billing( $license, $profile, $this->entitlements->lines( $license->id ) );
		return 'lifetime' === $billing['type'] ? 'lifetime' : 'month';
	}

	/**
	 * The add-on an extras purchase is checked as: in memory (id 0), of the add-on plan, billed like
	 * the licence, granting the items' lines, priced at their total.
	 *
	 * @param Plan    $plan    The add-on plan.
	 * @param string  $billing `month`, `year` or `lifetime`.
	 * @param array[] $items   Priced items (empty for "may this licence take add-ons at all").
	 * @return Package
	 */
	public function extras_package( Plan $plan, string $billing, array $items = array() ): Package {
		$pkg          = new Package();
		$pkg->id      = 0;
		$pkg->plan_id = (int) $plan->id;
		$pkg->name    = __( 'Add-ons', 'wp-license-manager' );
		if ( 'lifetime' === $billing ) {
			$pkg->billing_type = Package::TYPE_LIFETIME;
		} else {
			$pkg->billing_type     = Package::TYPE_RECURRING;
			$pkg->billing_period   = 'year' === $billing ? 'year' : 'month';
			$pkg->billing_interval = 1;
		}
		$total = 0.0;
		$lines = array();
		foreach ( $items as $item ) {
			$total += (float) ( $item['amount'] ?? 0 );
			foreach ( (array) ( $item['lines'] ?? array() ) as $line ) {
				$lines[] = $line;
			}
		}
		$pkg->price        = $total;
		$pkg->entitlements = $lines;
		return $pkg;
	}

	/**
	 * Add the extras of an add-on order line to the licence it was bought for. The add-on checks run
	 * on the extras as one in-memory add-on ({@see extras_package()}); the licence must still be billed
	 * the way the extras were priced. Then {@see apply_extras()} writes them: recurring items join the
	 * licence's subscription, paid through the plan's day; lifetime items are for life; term items get
	 * their own dated line and never join a subscription.
	 *
	 * Checked again here, per item, because another order may have changed the licence between the sale
	 * and now: an item whose module the licence now holds for the same term (bought on another order
	 * that completed first) refuses the whole line — it would be billed twice at every renewal. A term
	 * item is never "held": packs stack.
	 *
	 * The extras are aligned to the plan's paid-through day as it is NOW. When the snapshot recorded the
	 * subscription and day at the sale: another subscription now refuses the line; a different day (the
	 * plan renewed between the sale and completion) is applied with an order note saying so, so the shop
	 * sees the cycle the customer received. An overdue plan is refused by the add-on checks.
	 *
	 * @param \WC_Order              $order    The order.
	 * @param \WC_Order_Item_Product $item     Its extras line.
	 * @param License                $license  The licence it is added to.
	 * @param Plan                   $plan     The add-on plan.
	 * @param string                 $json     The line's `_wplm_extras` snapshot.
	 * @param int|null               $now      Unix time (tests).
	 * @return Entitlement[] The lines written.
	 * @throws \RuntimeException When they cannot be added.
	 */
	public function apply_extras_to_licence( \WC_Order $order, $item, License $license, Plan $plan, string $json, ?int $now = null ): array {
		return $this->with_licence_lock( $license, fn() => $this->apply_extras_to_licence_locked( $order, $item, $license, $plan, $json, $now ) );
	}

	/**
	 * Run a check-then-write on a licence's add-ons while no other request does: two orders completed
	 * at the same moment for the same licence must not both pass the "already has" check, nor both
	 * open an add-ons subscription. Everything is read again inside the lock.
	 *
	 * @param License  $license The licence.
	 * @param callable $write   The check and the write.
	 * @return mixed What `$write` returns.
	 * @throws \RuntimeException When another request is adding to the licence now (retryable).
	 */
	private function with_licence_lock( License $license, callable $write ) {
		$name = 'wplm_addons_lock_' . $license->id;
		if ( ! \WPLM\Support\Lock::claim( $name, self::LICENCE_LOCK_TTL ) ) {
			throw new \RuntimeException( __( 'another order is adding to this licence right now. Nothing was added', 'wp-license-manager' ) );
		}
		try {
			return $write();
		} finally {
			\WPLM\Support\Lock::release( $name );
		}
	}

	/**
	 * {@see apply_extras_to_licence()} under the licence's add-ons lock.
	 *
	 * @return Entitlement[]
	 * @throws \RuntimeException When they cannot be added.
	 */
	private function apply_extras_to_licence_locked( \WC_Order $order, $item, License $license, Plan $plan, string $json, ?int $now ): array {
		// Written by an earlier attempt that was cut off before recording them: recorded, never redone.
		$adopted = $this->adopt_unstamped_extras( $order, $item, $license, $json );
		if ( null !== $adopted ) {
			return $adopted;
		}
		list( , $snapshot, $sub, $through ) = $this->checked_extras( $order, $license, $plan, $json, $now );
		$sold_through                       = $snapshot['target_paid_through'] ?? null;

		// Recurring add-ons on a lifetime licence (v2): join its add-ons subscription, or open one.
		$addons         = null;
		$addons_through = null;
		$cycle          = $snapshot['cycle'] ?? null;
		if ( null !== $cycle ) {
			list( $addons, $addons_through ) = $this->addons_target_for( $order, $item, $license, $snapshot, (string) $cycle, $now );
		}

		$written = $this->apply_extras( $order, $item, $license, $snapshot['items'], $sub, $through, $now, $addons, $addons_through );
		if ( null !== $sub && null !== $sold_through && $sold_through !== $through ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: paid-through day at the sale, 2: paid-through day now */
					__( 'WPLM: the plan renewed after this sale (it was paid through %1$s). The add-ons are aligned with the plan and cover through %2$s, with no extra charge. Charge the difference by hand if that was not intended.', 'wp-license-manager' ),
					$sold_through,
					(string) $through
				)
			);
		}
		return $written;
	}

	/**
	 * Check an extras line for an existing licence as fulfilment adds it: the licence still takes
	 * add-ons priced like these, the snapshot reads, no module the licence now holds for the same term,
	 * the add-on checks ({@see check_for_user()}), and the subscription the line was sold against still
	 * bills the licence. Run again before an order is paid later ({@see payment_problem()}), so a
	 * customer is never charged for a line fulfilment would refuse.
	 *
	 * @param \WC_Order $order          The order.
	 * @param License   $license        The licence.
	 * @param Plan      $plan           The add-on plan.
	 * @param string    $json           The line's `_wplm_extras` snapshot.
	 * @param int|null  $now            Unix time (tests).
	 * @param bool      $before_payment Worded for the customer about to pay, not the shop.
	 * @return array{0: array, 1: array, 2: Subscription|null, 3: string|null} The context, the snapshot,
	 *                                                                          the subscription recurring
	 *                                                                          extras join and its day.
	 * @throws \RuntimeException When the line cannot be added.
	 */
	private function checked_extras( \WC_Order $order, License $license, Plan $plan, string $json, ?int $now, bool $before_payment = false ): array {
		$ctx = $this->extras_context( $plan, $license, $now );
		if ( null === $ctx ) {
			throw new \RuntimeException( 'that licence cannot take add-ons priced like these' );
		}
		$snapshot = $this->extras()->read_snapshot( $json, $ctx['billing'], $ctx['profile'] );
		if ( PackageExtrasService::FLOW_EXISTING !== ( $snapshot['flow'] ?? '' ) ) {
			throw new \RuntimeException( 'the add-ons on this line were not sold for an existing licence' );
		}
		foreach ( $snapshot['items'] as $extra ) {
			if ( null !== $extra['term_days'] ) {
				continue;
			}
			$held = array();
			foreach ( $extra['lines'] as $line ) {
				if ( Entitlement::KIND_MODULE === $line['kind'] && in_array( $line['code'], $ctx['held_modules'], true ) ) {
					$held[] = $ctx['profile']->code_label( $line['code'] );
				}
			}
			if ( array() !== $held ) {
				throw new \RuntimeException(
					sprintf(
						$before_payment
							/* translators: 1: licence label, 2: module names */
							? __( '%1$s already has %2$s (added since this order was placed, e.g. by another order)', 'wp-license-manager' )
							/* translators: 1: licence label, 2: module names */
							: __( '%1$s already has %2$s (added since this order was placed, e.g. by another order). Nothing was added; refund this line or remove it from the order', 'wp-license-manager' ),
						ucfirst( self::licence_label( $license ) ),
						implode( ', ', array_unique( $held ) )
					)
				);
			}
		}
		$ok = $this->check_for_user( (int) $order->get_customer_id(), $license, $this->extras_package( $plan, $ctx['billing'], $snapshot['items'] ), 1, $now );
		if ( is_wp_error( $ok ) ) {
			throw new \RuntimeException( $ok->get_error_message() );
		}
		// Before payment only: at fulfilment the first order completed adds the module, and the other is
		// refused by the "already has" check above. Counted here, two paid orders would refuse each other.
		$pending = $before_payment ? $this->extras_unfulfilled_problem( $license, $snapshot['items'], $order->get_id() ) : null;
		if ( null !== $pending ) {
			throw new \RuntimeException( $pending->get_error_message() );
		}
		$billing = $this->billing( $license, $ctx['profile'], $this->entitlements->lines( $license->id ) );
		$sub     = 'recurring' === $billing['type'] ? $billing['subscription'] : null;
		$through = null !== $sub ? $billing['paid_through'] : null;

		$sold_sub = $snapshot['target_subscription_id'] ?? null;
		if ( null !== $sold_sub && ( null === $sub || (int) $sold_sub !== (int) $sub->id ) ) {
			throw new \RuntimeException(
				sprintf(
					$before_payment
						/* translators: %d: subscription id */
						? __( 'the licence is no longer billed on subscription #%d, which these add-ons were sold against', 'wp-license-manager' )
						/* translators: %d: subscription id */
						: __( 'the licence is no longer billed on subscription #%d, which these add-ons were sold against. Nothing was added', 'wp-license-manager' ),
					(int) $sold_sub
				)
			);
		}
		return array( $ctx, $snapshot, $sub, $through );
	}

	/**
	 * The add-ons-only subscription an existing lifetime licence's recurring add-ons join, and its
	 * paid-through day. Its open one when it has one: billed the same cycle, able to take them
	 * ({@see addons_join_problem()}: not on hold, overdue or set to end), and — when the sale recorded
	 * one — the same one (a renewal since the sale is noted). Otherwise a new one (reused on a retry
	 * through `_wplm_addons_subscription_id` while it can still bill them).
	 *
	 * @return array{0: Subscription, 1: string|null}
	 * @throws \RuntimeException When the add-ons cannot join.
	 */
	private function addons_target_for( \WC_Order $order, $item, License $license, array $snapshot, string $cycle, ?int $now ): array {
		$addons = $this->reusable_addons_subscription( (int) $item->get_meta( '_wplm_addons_subscription_id' ) );
		$sold   = $snapshot['addons_subscription_id'] ?? null;
		$gone   = false;
		if ( null === $addons ) {
			$addons = $this->addons_subscription( $license );
			if ( null !== $sold && ( null === $addons || (int) $sold !== $addons->id ) ) {
				// One that ended before it billed anything (another order opened it, then was refunded)
				// held no dates to align with: these were priced a full cycle, as with none at all.
				$gone = $this->ended_unbilled( (int) $sold );
				if ( ! $gone ) {
					throw new \RuntimeException(
						/* translators: %d: subscription id */
						sprintf( __( 'the licence\'s add-ons are no longer billed on subscription #%d, which these add-ons were sold against. Nothing was added', 'wp-license-manager' ), (int) $sold )
					);
				}
			}
		}
		if ( null === $addons ) {
			$addons = $this->create_addons_subscription( $license, $order, $cycle, $now );
			$item->update_meta_data( '_wplm_addons_subscription_id', $addons->id );
			$item->save_meta_data();
			if ( $gone ) {
				$this->note_sold_sub_gone( $order, (int) $sold, $addons );
			}
			return array( $addons, $this->addons_paid_through( $addons ) );
		}
		if ( $gone ) {
			$this->note_sold_sub_gone( $order, (int) $sold, $addons );
		}
		$addons = $this->open_addons_period( $addons, $now );
		if ( $cycle !== $addons->billing_period || 1 !== (int) $addons->billing_interval ) {
			throw new \RuntimeException(
				/* translators: 1: subscription id, 2: cycle */
				sprintf( __( 'the licence\'s add-ons are billed on subscription #%1$d every %2$s; these were priced otherwise. Nothing was added', 'wp-license-manager' ), $addons->id, $addons->billing_period )
			);
		}
		$problem = $this->addons_subscription_problem( $addons, $now );
		if ( null !== $problem ) {
			throw new \RuntimeException( $problem->get_error_message() );
		}
		$through      = $this->addons_paid_through( $addons );
		$sold_through = $snapshot['addons_paid_through'] ?? null;
		if ( null !== $sold_through && $sold_through !== $through ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: paid-through day at the sale, 2: paid-through day now */
					__( 'WPLM: the licence\'s add-ons renewed after this sale (they were paid through %1$s). These add-ons are aligned with them and cover through %2$s, with no extra charge. Charge the difference by hand if that was not intended.', 'wp-license-manager' ),
					$sold_through,
					(string) $through
				)
			);
		}
		return array( $addons, $through );
	}

	/**
	 * Whether an add-ons subscription has ended without ever billing anything: gone, or cancelled /
	 * expired with no items and no lines on it — opened for an order whose add-ons were never written,
	 * then closed (that order refunded, or cancelled by hand).
	 *
	 * @param int $id Subscription id.
	 * @return bool
	 */
	private function ended_unbilled( int $id ): bool {
		if ( array() !== $this->lines->get_by_subscription( $id ) ) {
			return false;
		}
		$sub = $this->subscriptions->find_by_id( $id );
		return null === $sub
			|| ( $sub->is_addons_only() && in_array( $sub->status, array( 'cancelled', 'expired' ), true ) && array() === $this->subscriptions->get_items( $id ) );
	}

	/**
	 * The order note when add-ons sold against an add-ons subscription that has since ended unbilled
	 * are billed on another.
	 *
	 * @param \WC_Order     $order  The order.
	 * @param int           $sold   The subscription they were sold against.
	 * @param Subscription  $addons The one they join.
	 * @return void
	 */
	private function note_sold_sub_gone( \WC_Order $order, int $sold, Subscription $addons ): void {
		$order->add_order_note(
			sprintf(
				/* translators: 1: subscription id at the sale, 2: subscription id now */
				__( 'WPLM: the add-ons subscription #%1$d these add-ons were sold against ended before it billed anything. They are billed on add-ons subscription #%2$d instead.', 'wp-license-manager' ),
				$sold,
				$addons->id
			)
		);
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
		return $this->with_licence_lock( $license, fn() => $this->apply_locked( $order, $item, $package, $license, $now ) );
	}

	/**
	 * {@see apply()} under the licence's add-ons lock.
	 *
	 * @return Entitlement[]
	 * @throws \RuntimeException When the add-on cannot be added.
	 */
	private function apply_locked( \WC_Order $order, $item, Package $package, License $license, ?int $now ): array {
		$qty   = max( 1, (int) $item->get_quantity() );
		$lines = self::package_units( $package, $qty )[0]['lines'];

		// Written by an earlier attempt that was cut off before recording them: recorded, never redone.
		$adopted = $this->adopt_unstamped(
			$order,
			$item,
			$license,
			static fn(): array => self::package_units( $package, $qty ),
			'_wplm_addon_subscription_item_id'
		);
		if ( null !== $adopted ) {
			return $adopted;
		}

		$ok = $this->check_for_user( (int) $order->get_customer_id(), $license, $package, (int) $item->get_quantity(), $now );
		if ( is_wp_error( $ok ) ) {
			throw new \RuntimeException( $ok->get_error_message() );
		}

		$profile = $this->profiles->get( $license->profile );
		$billing = $this->billing( $license, $profile, $this->entitlements->lines( $license->id ) );
		$sub     = 'recurring' === $billing['type'] ? $billing['subscription'] : null;

		if ( array() === $lines ) {
			throw new \RuntimeException( 'the add-on grants nothing' );
		}
		// A one-time type with days is a dated pack; one with no days (older rows) is for life.
		$days = null === $sub && Package::TYPE_ONETIME === $package->billing_type && (int) $package->valid_for_days > 0 ? (int) $package->valid_for_days : null;

		$written = $this->write_units(
			$order,
			$item,
			$license,
			$profile,
			array(
				array(
					'lines'      => $lines,
					'qty'        => $qty,
					'line_total' => round( $package->price * $qty, 4 ),
					'term_days'  => $days,
					'meta'       => array( 'package_id' => $package->id ),
				),
			),
			$sub,
			null !== $sub ? $billing['paid_through'] : null,
			$now
		);

		if ( array() !== $written['sub_items'] ) {
			$item->update_meta_data( '_wplm_addon_subscription_item_id', $written['sub_items'][0] );
		}
		$item->update_meta_data( '_wplm_entitlement_ids', wp_json_encode( $written['ids'] ) );
		$item->save_meta_data();

		$order->add_order_note(
			sprintf(
				/* translators: 1: what was added, 2: licence label */
				__( 'WPLM: added %1$s to %2$s.', 'wp-license-manager' ),
				self::describe_lines( $written['lines'], $profile, false ),
				self::licence_label( $license )
			)
		);

		return $written['lines'];
	}

	/**
	 * Write the package extras of an order line onto a licence: one subscription item per extra that
	 * joins the subscription (recurring), lines for life when there is none (lifetime), and a dated line
	 * for a term extra, which never joins a subscription. Used for a licence issued with extras and for
	 * extras added to an existing licence ({@see apply_extras_to_licence()}).
	 *
	 * Leaves `_wplm_entitlement_ids` and, when items joined a subscription,
	 * `_wplm_addon_subscription_item_ids` (JSON) on the order item. Nothing is checked here beyond the
	 * lines themselves: the caller has.
	 *
	 * @param \WC_Order              $order        The order.
	 * @param \WC_Order_Item_Product $item         Its line.
	 * @param License                $license      The licence.
	 * @param array[]                $items        Checked snapshot items ({@see PackageExtrasService::read_snapshot()}).
	 * @param Subscription|null      $sub            The subscription recurring extras join, or null.
	 * @param string|null            $paid_through   The plan's paid-through day (with a subscription).
	 * @param int|null               $now            Unix time (tests).
	 * @param Subscription|null      $addons_sub     Lifetime licence: the add-ons-only subscription that
	 *                                               items with a `cycle` join (v2).
	 * @param string|null            $addons_through Its paid-through day.
	 * @return Entitlement[] The lines written.
	 * @throws \RuntimeException When they cannot be written (nothing is left behind).
	 */
	public function apply_extras( \WC_Order $order, $item, License $license, array $items, ?Subscription $sub, ?string $paid_through, ?int $now = null, ?Subscription $addons_sub = null, ?string $addons_through = null ): array {
		$profile = $this->profiles->get( $license->profile );
		if ( null === $profile ) {
			throw new \RuntimeException( 'the licence has no licence profile' );
		}
		$units = $this->extras_units( $items );
		foreach ( array_values( $items ) as $u => $extra ) {
			$unit = $units[ $u ];
			if ( null !== ( $extra['cycle'] ?? null ) ) {
				if ( null === $addons_sub || $extra['cycle'] !== $addons_sub->billing_period ) {
					throw new \RuntimeException( 'recurring add-ons on a lifetime licence need an add-ons subscription billed the same way' );
				}
				$unit['sub']                 = $addons_sub;
				$unit['through']             = $addons_through;
				$unit['meta']['addons_only'] = true;
			}
			$units[ $u ] = $unit;
		}
		if ( array() === $units ) {
			throw new \RuntimeException( 'no add-ons were bought on this line' );
		}

		$written = $this->write_units( $order, $item, $license, $profile, $units, $sub, $paid_through, $now );

		// Recorded before anything else can fail: a retry finds the line done and never writes (or
		// bills) it again.
		if ( array() !== $written['sub_items'] ) {
			$item->update_meta_data( '_wplm_addon_subscription_item_ids', wp_json_encode( $written['sub_items'] ) );
		}
		$item->update_meta_data( '_wplm_entitlement_ids', wp_json_encode( $written['ids'] ) );
		$item->save_meta_data();

		// A new add-ons subscription starts billing once it bills something.
		if ( null !== $addons_sub ) {
			$this->activate_addons_subscription( $addons_sub->id );
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: what was added, 2: licence label */
				__( 'WPLM: added %1$s to %2$s.', 'wp-license-manager' ),
				self::describe_lines( $written['lines'], $profile, false ),
				self::licence_label( $license )
			)
		);

		return $written['lines'];
	}

	/**
	 * The units {@see write_units()} writes for checked snapshot items, before any joins a subscription.
	 *
	 * @param array[] $items Checked snapshot items ({@see PackageExtrasService::read_snapshot()}).
	 * @return array[]
	 */
	private function extras_units( array $items ): array {
		$units = array();
		foreach ( array_values( $items ) as $extra ) {
			$units[] = array(
				'lines'      => (array) $extra['lines'],
				'qty'        => max( 1, (int) $extra['qty'] ),
				'line_total' => round( (float) $extra['amount'], 4 ),
				'term_days'  => null !== ( $extra['term_days'] ?? null ) ? (int) $extra['term_days'] : null,
				'meta'       => array(
					'package_id' => 0,
					'extra_id'   => (string) ( $extra['id'] ?? '' ),
					'label'      => (string) ( $extra['label'] ?? '' ),
				),
			);
		}
		return $units;
	}

	/**
	 * The one line writer behind stored add-ons and package extras. Every line is validated before any
	 * is written. Each unit's lines are paid through `$paid_through` and join `$sub` (one subscription
	 * item per unit, the recurring total re-derived from the items), unless the unit is a term
	 * (`term_days`): a dated line from {@see one_time_through()}, never on a subscription. With no
	 * subscription and no term the lines are for life.
	 *
	 * If anything fails part-way, every line this call wrote is removed again (through the service, so
	 * subscription items already attached are detached with them) and the error is rethrown: a retry
	 * starts clean.
	 *
	 * @param \WC_Order              $order        The order.
	 * @param \WC_Order_Item_Product $item         Its line.
	 * @param License                $license      The licence.
	 * @param Profile                $profile      Its profile.
	 * @param array[]                $units        Each: lines (kind, code, qty — already multiplied), qty, line_total, term_days, meta.
	 * @param Subscription|null      $sub          The subscription recurring units join.
	 * @param string|null            $paid_through The plan's paid-through day.
	 * @param int|null               $now          Unix time (tests).
	 * @return array{lines: Entitlement[], ids: int[], sub_items: int[]}
	 * @throws \RuntimeException When the lines cannot be written.
	 */
	private function write_units( \WC_Order $order, $item, License $license, Profile $profile, array $units, ?Subscription $sub, ?string $paid_through, ?int $now ): array {
		$now      = $now ?? time();
		$today    = wp_date( 'Y-m-d', $now );
		$existing = $this->entitlements->lines( $license->id );

		$rows  = array();
		$owner = array(); // Row index => unit index.
		foreach ( $units as $u => $unit ) {
			$days = isset( $unit['term_days'] ) && null !== $unit['term_days'] ? (int) $unit['term_days'] : null;
			// A unit may name its own subscription (a recurring add-on on a lifetime licence joins the
			// add-ons-only subscription); otherwise the call's.
			$usub               = array_key_exists( 'sub', $unit ) ? $unit['sub'] : $sub;
			$uthrough           = array_key_exists( 'through', $unit ) ? $unit['through'] : $paid_through;
			$join               = null !== $usub && null === $days;
			$units[ $u ]['sub'] = $join ? $usub : null;
			foreach ( $unit['lines'] as $line ) {
				$kind = (string) ( $line['kind'] ?? '' );
				$code = (string) ( $line['code'] ?? '' );
				if ( null !== $days ) {
					$through = $this->one_time_through( $existing, $kind, $code, $today, $profile->grace_days(), $days );
				} elseif ( $join ) {
					$through = $uthrough;
				} else {
					$through = null;
				}
				$rows[]  = array(
					'kind'            => $kind,
					'code'            => $code,
					'qty'             => Entitlement::KIND_LIMIT === $kind ? (int) ( $line['qty'] ?? 0 ) : 0,
					'paid_through'    => $through,
					'source'          => 'addon',
					'subscription_id' => $join ? $usub->id : null,
					'order_id'        => $order->get_id(),
				);
				$owner[] = $u;
			}
		}
		if ( array() === $rows ) {
			throw new \RuntimeException( 'the add-on grants nothing' );
		}

		$before    = $this->order_line_ids( $order->get_id(), $license->id );
		$sub_items = array();
		try {
			$written = $this->entitlements->add_lines( $license->id, $rows );
			$by_unit = array();
			foreach ( $written as $i => $line ) {
				$by_unit[ $owner[ $i ] ][] = $line->id;
			}
			foreach ( $units as $u => $unit ) {
				if ( null === $unit['sub'] ) {
					continue;
				}
				$sub_items[] = $this->attach( $unit['sub'], $order, $item, (int) $unit['qty'], (float) $unit['line_total'], (array) ( $unit['meta'] ?? array() ), $license, $by_unit[ $u ] ?? array() );
			}
		} catch ( \Throwable $e ) {
			// A subscription opened for these add-ons stays open (pending) for the retry.
			$this->keep_empty_addons = true;
			try {
				foreach ( array_diff( $this->order_line_ids( $order->get_id(), $license->id ), $before ) as $id ) {
					$this->entitlements->delete_line( (int) $id );
				}
				foreach ( $sub_items as $sub_item ) {
					$this->detach_item( $sub_item );
				}
			} finally {
				$this->keep_empty_addons = false;
			}
			throw new \RuntimeException( 'the add-on could not be added: ' . $e->getMessage(), 0, $e );
		}

		return array(
			'lines'     => $written,
			'ids'       => array_map( static fn( Entitlement $l ) => $l->id, $written ),
			'sub_items' => $sub_items,
		);
	}

	/**
	 * Ids of the add-on lines an order wrote on a licence.
	 *
	 * @param int $order_id   Order id.
	 * @param int $license_id Licence id.
	 * @return int[]
	 */
	private function order_line_ids( int $order_id, int $license_id ): array {
		$ids = array();
		foreach ( $this->lines->get_by_order( $order_id ) as $line ) {
			if ( $license_id === $line->license_id && 'addon' === $line->source ) {
				$ids[] = $line->id;
			}
		}
		return $ids;
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
	 * {@see line_deleted()}). A licence the order added to is otherwise untouched: its status and its
	 * other lines stay. A licence the order issued with extras is suspended or revoked by the refund
	 * handler ({@see \WPLM\Integrations\WooCommerce\CheckoutHandler::handle_order_refunded()}); its
	 * extras lines are removed here all the same. Safe to run twice.
	 *
	 * @param \WC_Order $order The refunded order.
	 * @return int Lines removed.
	 */
	public function revert( \WC_Order $order ): int {
		$issued  = 0; // Lines on a licence this order issued.
		$added   = 0; // Lines on a licence the customer already had.
		$touched = array(
			'issued' => false,
			'added'  => false,
		);
		$on_subs = array(); // Subscriptions the removed lines were on.

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$ids = json_decode( (string) $item->get_meta( '_wplm_entitlement_ids' ), true );
			if ( ! is_array( $ids ) ) {
				continue;
			}
			$kind             = '' !== (string) $item->get_meta( '_wplm_license_ids' ) ? 'issued' : 'added';
			$touched[ $kind ] = true;
			foreach ( $ids as $id ) {
				$line = $this->lines->find_by_id( (int) $id );
				// Only add-on lines: one a plan switch moved into the plan (source plan) stays.
				if ( null !== $line && 'addon' === $line->source && $this->entitlements->delete_line( $line->id ) ) {
					$on_subs[] = (int) $line->subscription_id;
					$issued   += 'issued' === $kind ? 1 : 0;
					$added    += 'added' === $kind ? 1 : 0;
				}
			}
			// The billing goes with the lines; this also covers an item whose lines were removed by hand.
			$sub_items   = json_decode( (string) $item->get_meta( '_wplm_addon_subscription_item_ids' ), true );
			$sub_items   = is_array( $sub_items ) ? array_map( 'intval', $sub_items ) : array();
			$sub_items[] = (int) $item->get_meta( '_wplm_addon_subscription_item_id' );
			foreach ( array_filter( $sub_items ) as $sub_item ) {
				$this->detach_item( $sub_item );
			}
			$item->delete_meta_data( '_wplm_entitlement_ids' );
			$item->delete_meta_data( '_wplm_addon_subscription_item_id' );
			$item->delete_meta_data( '_wplm_addon_subscription_item_ids' );
			$item->update_meta_data( '_wplm_addon_reverted', '1' );
			$item->save_meta_data();
		}

		// Anything else this order wrote (an interrupted fulfilment that never stamped its item).
		foreach ( $this->lines->get_by_order( $order->get_id() ) as $line ) {
			if ( 'addon' !== $line->source ) {
				continue;
			}
			$license = $this->licenses->find_by_id( $line->license_id );
			$kind    = null !== $license && $order->get_id() === $license->order_id ? 'issued' : 'added';
			if ( $this->entitlements->delete_line( $line->id ) ) {
				$on_subs[]        = (int) $line->subscription_id;
				$issued          += 'issued' === $kind ? 1 : 0;
				$added           += 'added' === $kind ? 1 : 0;
				$touched[ $kind ] = true;
			}
		}

		// An add-ons subscription the order opened but never billed (its add-ons were never written)
		// ends with the order: a later purchase must not join it with its old dates. So does one never
		// billed that this order's removed lines were on (it joined one another order opened, which was
		// refunded first): left pending with nothing on it, it would decide the licence's cycle for good.
		// Never one that holds lines: another order sold against it wrote them (its request cut off
		// before their item was attached), and they are paid for and renew on it.
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$on_subs[] = (int) $item->get_meta( '_wplm_addons_subscription_id' );
			}
		}
		foreach ( array_unique( array_filter( $on_subs ) ) as $sub_id ) {
			$this->end_unbilled_addons_subscription( (int) $sub_id );
		}

		if ( $touched['issued'] ) {
			$note = 'revoke' === get_option( 'wplm_refund_action', 'suspend' )
				/* translators: %d: number of lines */
				? _n( 'WPLM: refunded — %d add-on line bought with the licence removed. The licence issued by this order is revoked.', 'WPLM: refunded — %d add-on lines bought with the licence removed. The licence issued by this order is revoked.', $issued, 'wp-license-manager' )
				/* translators: %d: number of lines */
				: _n( 'WPLM: refunded — %d add-on line bought with the licence removed. The licence issued by this order is suspended.', 'WPLM: refunded — %d add-on lines bought with the licence removed. The licence issued by this order is suspended.', $issued, 'wp-license-manager' );
			$order->add_order_note( sprintf( $note, $issued ) );
		}
		if ( $touched['added'] ) {
			$order->add_order_note(
				/* translators: %d: number of lines */
				sprintf( _n( 'WPLM: refunded — %d add-on line removed from the licence. The licence itself is unchanged.', 'WPLM: refunded — %d add-on lines removed from the licence. The licence itself is unchanged.', $added, 'wp-license-manager' ), $added )
			);
		}
		return $issued + $added;
	}

	/**
	 * Cancel an add-ons subscription that never billed anything and holds nothing: add-ons-only, still
	 * pending (its first add-ons were never written), with no items and no lines. Left open, a later
	 * purchase would join it with its old dates, and it would decide the licence's add-ons cycle for
	 * good. One holding lines is never ended: another order sold against it wrote them, and they are paid
	 * for and renew on it. Cancelled, never deleted.
	 *
	 * @param int $id Subscription id.
	 * @return bool Whether it was cancelled.
	 */
	public function end_unbilled_addons_subscription( int $id ): bool {
		$sub = $id > 0 ? $this->subscriptions->find_by_id( $id ) : null;
		if ( null === $sub || ! $sub->is_addons_only() || 'pending' !== $sub->status || array() !== $this->subscriptions->get_items( $sub->id )
			|| array() !== $this->lines->get_by_subscription( $sub->id ) ) {
			return false;
		}
		$this->subscription_service()->cancel( $sub->id );
		return true;
	}

	/**
	 * After a partial refund: a line refunded in full that opened an add-ons subscription and was never
	 * added ({@see refunded_in_full()}) is never added now, so that subscription — never billed, nothing
	 * on it — ends with it ({@see end_unbilled_addons_subscription()}), as it would with a full refund.
	 *
	 * @param \WC_Order $order The order.
	 * @return void
	 */
	public function end_refunded_lines_addons_subscriptions( \WC_Order $order ): void {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && (int) $item->get_meta( '_wplm_addons_subscription_id' ) > 0
				&& '' === (string) $item->get_meta( '_wplm_entitlement_ids' ) && self::refunded_in_full( $order, $item ) ) {
				$this->end_unbilled_addons_subscription( (int) $item->get_meta( '_wplm_addons_subscription_id' ) );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Billing
	// -------------------------------------------------------------------------

	/**
	 * Add one add-on (a stored add-on, or one package extra) to the subscription's items and re-derive
	 * the recurring total from them.
	 *
	 * @param Subscription           $sub        The subscription.
	 * @param \WC_Order              $order      The order.
	 * @param \WC_Order_Item_Product $item       Its line.
	 * @param int                    $qty        Quantity.
	 * @param float                  $line_total What one cycle bills for it.
	 * @param array                  $meta       Item meta (package_id; extra_id and label for an extra).
	 * @param License                $license    The licence.
	 * @param int[]                  $line_ids   The lines it pays for.
	 * @return int The subscription item id.
	 */
	private function attach( Subscription $sub, \WC_Order $order, $item, int $qty, float $line_total, array $meta, License $license, array $line_ids ): int {
		$this->reconcile( $sub );
		$item_id = $this->subscriptions->add_item(
			$sub->id,
			array(
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => $item->get_variation_id() ?: null,
				'quantity'     => $qty,
				'line_total'   => $line_total,
				'meta'         => array_merge(
					array( 'addon' => true ),
					$meta,
					array(
						'order_id'        => $order->get_id(),
						'order_item_id'   => $item->get_id(),
						'license_id'      => $license->id,
						'entitlement_ids' => array_values( array_map( 'intval', $line_ids ) ),
					)
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

		// An add-ons-only subscription left with nothing to bill ends (its licence is untouched).
		if ( ! $this->keep_empty_addons && null !== $sub && $sub->is_addons_only() && array() === $this->subscriptions->get_items( $subscription_id )
			&& ! in_array( $sub->status, array( 'cancelled', 'expired' ), true ) ) {
			$this->subscription_service()->cancel( $subscription_id );
		}
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

	/** The package extras service (lazily, so the older six-argument constructor keeps working). */
	private function extras(): PackageExtrasService {
		if ( null === $this->extras ) {
			$this->extras = \WPLM\Plugin::get_instance()->container()->make( PackageExtrasService::class );
		}
		return $this->extras;
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
