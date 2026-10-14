<?php
/**
 * Package extras: modules and quantities a customer ticks next to a licence type at purchase, or adds
 * to a licence they already have, priced by a product add-on through one filter.
 *
 * @package WPLM\Services
 */

namespace WPLM\Services;

use WPLM\Licensing\Profile;
use WPLM\Licensing\ProfileRegistry;
use WPLM\Models\Entitlement;
use WPLM\Models\Package;
use WPLM\Models\Plan;

defined( 'ABSPATH' ) || exit;

/**
 * WPLM stays product-agnostic: what may be ticked and what it costs come from the filter
 * `wplm_package_extras( array $offers, array $ctx )`. WPLM holds every offer to what an extra may be,
 * whatever the filter returns ({@see offers()}), checks a customer's choice against the offers
 * ({@see choose()}), and keeps a snapshot of what was sold on the order line ({@see snapshot()}).
 * Fulfilment writes from that snapshot only ({@see read_snapshot()}), so a later price change never
 * alters a sale.
 *
 * The context (`$ctx`) always has every key:
 * - `profile`      Profile          the plan's licence profile;
 * - `plan`         Plan             new purchase: the product's plan; existing licence: the add-on plan;
 * - `billing`      string           `month`, `year` or `lifetime` ({@see Package::billing_key()});
 * - `flow`         string           `new` or `existing`;
 * - `package`      Package|null     new: the chosen base package; existing: null;
 * - `license`      License|null     existing: the target licence; new: null;
 * - `held_modules` string[]         module codes already held (new: the base package's modules;
 *                                   existing: lifetime modules and those on the licence's subscription,
 *                                   which are not offered again; for requirements, every module still
 *                                   live on the licence counts).
 */
class PackageExtrasService {

	/** New purchase: extras ticked beside a licence type. */
	public const FLOW_NEW = 'new';

	/** An existing licence: extras added through the add-ons product. */
	public const FLOW_EXISTING = 'existing';

	/** Display groups an offer may name. */
	public const GROUPS = array( 'module', 'quantity', 'term' );

	/** The cycles a recurring extra on a lifetime licence may be billed on. */
	public const CYCLES = array( 'month', 'year' );

	/** @var ProfileRegistry */
	private ProfileRegistry $profiles;

	/** @var EntitlementService */
	private EntitlementService $entitlements;

	/**
	 * @param ProfileRegistry    $profiles     Known licence profiles.
	 * @param EntitlementService $entitlements Validates lines against a profile.
	 */
	public function __construct( ProfileRegistry $profiles, EntitlementService $entitlements ) {
		$this->profiles     = $profiles;
		$this->entitlements = $entitlements;
	}

	// -------------------------------------------------------------------------
	// Context
	// -------------------------------------------------------------------------

	/**
	 * The context for extras beside a licence type on a new purchase, or null when the package takes
	 * none: a plan with no licence profile, an add-on or built-at-purchase plan, a package with a
	 * trial, or a billing other than every month, every year or lifetime.
	 *
	 * @param Plan    $plan    The product's plan.
	 * @param Package $package The chosen licence type.
	 * @return array|null
	 */
	public function context_for_package( Plan $plan, Package $package ): ?array {
		if ( null === $plan->profile || $plan->is_addon() || $plan->is_dynamic() ) {
			return null;
		}
		if ( $package->trial_days > 0 ) {
			return null; // A trial is offered no extras (owner default).
		}
		$billing = $package->billing_key();
		$profile = $this->profiles->get( $plan->profile );
		if ( null === $billing || null === $profile ) {
			return null;
		}
		$held = array();
		foreach ( $package->entitlements as $line ) {
			if ( Entitlement::KIND_MODULE === ( $line['kind'] ?? '' ) ) {
				$held[] = (string) $line['code'];
			}
		}
		return array(
			'profile'      => $profile,
			'plan'         => $plan,
			'billing'      => $billing,
			'flow'         => self::FLOW_NEW,
			'package'      => $package,
			'license'      => null,
			'held_modules' => array_values( array_unique( $held ) ),
		);
	}

	// -------------------------------------------------------------------------
	// Offers
	// -------------------------------------------------------------------------

	/**
	 * The extras offered in a context, held to what an extra may be. Dropped: a bad or repeated id, a
	 * price that is not a finite number of 0 or more, a maximum below 1, lines the profile does not
	 * allow, a term offer without days, and a module offer for a module already held (a term offer is
	 * never "held": packs stack). Prices are rounded to the shop's decimals. A module-only or term offer
	 * is bought at most once.
	 *
	 * @param array $ctx The context (see the class description).
	 * @return array[] Offers, in display order.
	 */
	public function offers( array $ctx ): array {
		$held = array();
		return $this->sanitize( $ctx, $held );
	}

	/**
	 * Apply the filter and sanitise its offers.
	 *
	 * @param array $ctx  The context.
	 * @param array $held Filled with the offers refused because their module is already held (id => label).
	 * @return array[]
	 */
	private function sanitize( array $ctx, array &$held ): array {
		$profile = $ctx['profile'] ?? null;
		if ( ! $profile instanceof Profile ) {
			return array();
		}

		/**
		 * The extras a customer may tick in a context (see PackageExtrasService). Each offer:
		 * id, label, group, unit_price, max_qty, lines (per unit), requires, term_days, description.
		 *
		 * @param array[] $offers Empty until a product add-on prices its extras.
		 * @param array   $ctx    The context.
		 */
		$raw = apply_filters( 'wplm_package_extras', array(), $ctx );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$decimals = self::decimals();
		$held_mod = array_map( 'strval', (array) ( $ctx['held_modules'] ?? array() ) );
		$seen     = array();
		$out      = array();
		foreach ( $raw as $offer ) {
			if ( ! is_array( $offer ) ) {
				continue;
			}
			$id = isset( $offer['id'] ) && is_string( $offer['id'] ) ? $offer['id'] : '';
			if ( '' === $id || ! preg_match( '/^[a-z0-9:_-]+$/', $id ) || isset( $seen[ $id ] ) ) {
				continue;
			}
			// A recurring offer (v2): on a lifetime licence only, priced per cycle, never with a unit price.
			$recurring = null;
			if ( isset( $offer['recurring'] ) ) {
				if ( 'lifetime' !== ( $ctx['billing'] ?? '' ) || null !== ( $offer['unit_price'] ?? null ) ) {
					continue;
				}
				$recurring = self::cycle_prices( $offer['recurring'], $decimals );
				if ( null === $recurring ) {
					continue;
				}
				$price = null;
			} else {
				$price = $offer['unit_price'] ?? null;
				if ( ! is_numeric( $price ) || ! is_finite( (float) $price ) || (float) $price < 0 ) {
					continue;
				}
			}
			$max = isset( $offer['max_qty'] ) && is_numeric( $offer['max_qty'] ) ? (int) $offer['max_qty'] : 0;
			if ( $max < 1 ) {
				continue;
			}
			$days  = isset( $offer['term_days'] ) && null !== $offer['term_days'] && '' !== $offer['term_days'] ? (int) $offer['term_days'] : null;
			$group = in_array( $offer['group'] ?? '', self::GROUPS, true ) ? (string) $offer['group'] : 'module';
			if ( null !== $days && $days < 1 ) {
				continue;
			}
			if ( 'term' === $group && null === $days ) {
				continue; // A term with no days would be sold for life.
			}
			if ( null !== $days ) {
				if ( null !== $recurring ) {
					continue; // A dated pack is paid once, never per cycle.
				}
				$group = 'term';
			}

			$lines = $this->offer_lines( $profile, $offer['lines'] ?? null );
			if ( null === $lines ) {
				continue;
			}
			$modules = array();
			$limits  = false;
			foreach ( $lines as $line ) {
				if ( Entitlement::KIND_MODULE === $line['kind'] ) {
					$modules[] = $line['code'];
				} else {
					$limits = true;
				}
			}
			if ( ! $limits || null !== $days ) {
				$max = 1; // Nothing to multiply: a module, or a dated pack, is bought once.
			}

			$label = sanitize_text_field( (string) ( $offer['label'] ?? '' ) );
			$label = '' !== $label ? $label : $id;
			if ( null === $days && array() !== array_intersect( $modules, $held_mod ) ) {
				$held[ $id ] = $label;
				continue;
			}

			$seen[ $id ] = true;
			$out[]       = array(
				'id'          => $id,
				'label'       => $label,
				'group'       => $group,
				'unit_price'  => null !== $recurring ? null : round( (float) $price, $decimals ),
				'recurring'   => $recurring,
				'max_qty'     => $max,
				'lines'       => $lines,
				'requires'    => $this->requires( $profile, $offer['requires'] ?? array() ),
				'term_days'   => $days,
				'description' => sanitize_text_field( (string) ( $offer['description'] ?? '' ) ),
			);
		}
		return $out;
	}

	/**
	 * A recurring offer's prices: `month` and `year`, each a finite price of 0 or more (rounded) or null
	 * (not offered), at least one set. Null when the shape is wrong.
	 *
	 * @param mixed $raw      The offer's `recurring`.
	 * @param int   $decimals Shop decimals.
	 * @return array{month: float|null, year: float|null}|null
	 */
	private static function cycle_prices( $raw, int $decimals ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$out = array();
		foreach ( self::CYCLES as $cycle ) {
			$value = $raw[ $cycle ] ?? null;
			if ( null === $value || '' === $value ) {
				$out[ $cycle ] = null;
				continue;
			}
			if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value < 0 ) {
				return null;
			}
			$out[ $cycle ] = round( (float) $value, $decimals );
		}
		return null === $out['month'] && null === $out['year'] ? null : $out;
	}

	/**
	 * The cycle a line's recurring extras are billed on: the forced one, else the posted one, else
	 * monthly when every ticked recurring extra has a monthly price, else yearly.
	 *
	 * @param array[]     $offers The ticked recurring offers.
	 * @param string|null $posted `wplm_extras_cycle`.
	 * @return string
	 */
	public static function default_cycle( array $offers, ?string $posted ): string {
		if ( in_array( $posted, self::CYCLES, true ) ) {
			return $posted;
		}
		foreach ( $offers as $offer ) {
			if ( null === ( $offer['recurring']['month'] ?? null ) ) {
				return 'year';
			}
		}
		return 'month';
	}

	/**
	 * One unit's lines, valid for the profile: modules as qty 1, limits as their qty (at least 1).
	 *
	 * @param Profile $profile The profile.
	 * @param mixed   $lines   Raw lines.
	 * @return array[]|null Null when there are none or any is invalid.
	 */
	private function offer_lines( Profile $profile, $lines ): ?array {
		if ( ! is_array( $lines ) || array() === $lines ) {
			return null;
		}
		try {
			$normal = $this->entitlements->normalize_template( $profile, array_values( $lines ) );
		} catch ( \InvalidArgumentException $e ) {
			unset( $e );
			return null;
		}
		$out = array();
		foreach ( $normal as $line ) {
			$out[] = array(
				'kind' => $line['kind'],
				'code' => $line['code'],
				'qty'  => Entitlement::KIND_MODULE === $line['kind'] ? 1 : (int) $line['qty'],
			);
		}
		return $out;
	}

	/**
	 * An offer's requirements as any-of groups of module codes, every group to be met. A flat list of
	 * codes is read as one group. Codes the profile does not list are dropped, then empty groups.
	 *
	 * @param Profile $profile The profile.
	 * @param mixed   $raw     Raw requirements.
	 * @return array<int, string[]>
	 */
	private function requires( Profile $profile, $raw ): array {
		if ( ! is_array( $raw ) || array() === $raw ) {
			return array();
		}
		$flat = true;
		foreach ( $raw as $entry ) {
			if ( is_array( $entry ) ) {
				$flat = false;
				break;
			}
		}
		$groups = $flat ? array( $raw ) : $raw;
		$out    = array();
		foreach ( $groups as $group ) {
			$codes = array_values( array_intersect( array_map( 'strval', (array) $group ), $profile->module_codes ) );
			if ( array() !== $codes ) {
				$out[] = array_values( array_unique( $codes ) );
			}
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// A customer's choice
	// -------------------------------------------------------------------------

	/**
	 * Read a posted or stored choice: offer id => quantity, positive quantities only. Anything that is
	 * not a whole number of 0 or more is kept as -1, so {@see choose()} refuses it rather than guess.
	 *
	 * @param mixed $raw `wplm_extras` as posted or stored.
	 * @return array<string, int>
	 */
	public static function parse_choice( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $id => $qty ) {
			$id = sanitize_text_field( (string) $id );
			if ( '' === $id ) {
				continue;
			}
			if ( is_int( $qty ) ) {
				$n = $qty;
			} elseif ( is_string( $qty ) && preg_match( '/^\s*\d+\s*$/', $qty ) ) {
				$n = (int) $qty;
			} elseif ( '' === $qty || null === $qty || false === $qty ) {
				$n = 0;
			} else {
				$n = -1;
			}
			if ( 0 !== $n ) {
				$out[ $id ] = $n;
			}
		}
		return $out;
	}

	/**
	 * Price and check a choice against the offers of a context.
	 *
	 * Refused: an id that is not offered (a module already held says so), a quantity below 0 or above
	 * the offer's maximum, and a module whose requirement is not met — the profile's `requires` and the
	 * offer's own, against the modules held plus the modules ticked.
	 *
	 * A recurring offer (lifetime licence) is priced for one cycle, the same for every recurring offer of
	 * the line: `$cycle` when given (the posted `wplm_extras_cycle`, or the cycle an existing add-ons
	 * subscription forces), else {@see default_cycle()}. One with no price for that cycle is refused.
	 *
	 * @param array              $ctx    The context.
	 * @param array<string, int> $choice Offer id => quantity ({@see parse_choice()}).
	 * @param string|null        $cycle  `month` or `year` for recurring offers.
	 * @return array{items: array[], total: float, cycle: string|null}|\WP_Error
	 */
	public function choose( array $ctx, array $choice, ?string $cycle = null ) {
		$held   = array();
		$offers = array();
		foreach ( $this->sanitize( $ctx, $held ) as $offer ) {
			$offers[ $offer['id'] ] = $offer;
		}
		$profile  = $ctx['profile'];
		$decimals = self::decimals();

		$ticked_recurring = array();
		foreach ( $choice as $id => $qty ) {
			if ( (int) $qty > 0 && isset( $offers[ (string) $id ] ) && null !== $offers[ (string) $id ]['recurring'] ) {
				$ticked_recurring[] = $offers[ (string) $id ];
			}
		}
		$line_cycle = array() !== $ticked_recurring ? self::default_cycle( $ticked_recurring, $cycle ) : null;

		$items  = array();
		$ticked = array();
		$total  = 0.0;
		foreach ( $choice as $id => $qty ) {
			$id  = (string) $id;
			$qty = (int) $qty;
			if ( 0 === $qty ) {
				continue;
			}
			if ( isset( $held[ $id ] ) ) {
				return new \WP_Error(
					'wplm_extras_held',
					/* translators: %s: add-on name */
					sprintf( __( 'You already have %s, so it cannot be added again.', 'wp-license-manager' ), $held[ $id ] )
				);
			}
			if ( ! isset( $offers[ $id ] ) ) {
				return new \WP_Error( 'wplm_extras_unknown', __( 'One of the add-ons you chose is not available. Please choose again.', 'wp-license-manager' ) );
			}
			$offer = $offers[ $id ];
			if ( $qty < 0 || $qty > $offer['max_qty'] ) {
				return new \WP_Error(
					'wplm_extras_qty',
					/* translators: 1: add-on name, 2: largest quantity */
					sprintf( __( '%1$s: choose between 0 and %2$d.', 'wp-license-manager' ), $offer['label'], $offer['max_qty'] )
				);
			}
			$lines = array();
			foreach ( $offer['lines'] as $line ) {
				$line['qty'] = Entitlement::KIND_LIMIT === $line['kind'] ? $line['qty'] * $qty : 1;
				$lines[]     = $line;
				if ( Entitlement::KIND_MODULE === $line['kind'] ) {
					$ticked[] = $line['code'];
				}
			}
			$unit = $offer['unit_price'];
			if ( null !== $offer['recurring'] ) {
				$unit = $offer['recurring'][ $line_cycle ] ?? null;
				if ( null === $unit ) {
					return new \WP_Error(
						'wplm_extras_cycle',
						sprintf(
							/* translators: 1: add-on name, 2: "monthly" or "yearly" */
							__( '%1$s cannot be paid %2$s. Choose the other option.', 'wp-license-manager' ),
							$offer['label'],
							'year' === $line_cycle ? __( 'yearly', 'wp-license-manager' ) : __( 'monthly', 'wp-license-manager' )
						)
					);
				}
			}
			$amount  = round( $unit * $qty, $decimals );
			$total  += $amount;
			$items[] = array(
				'id'         => $id,
				'label'      => $offer['label'],
				'qty'        => $qty,
				'unit_price' => $unit,
				'amount'     => $amount,
				'lines'      => $lines,
				'term_days'  => $offer['term_days'],
				'cycle'      => null !== $offer['recurring'] ? $line_cycle : null,
			);
		}

		// Requirements: at least one module of each group, held already or ticked now. On an existing
		// licence any module still live there counts too (a dated pack, a line added by hand), as the
		// add-on checks read it.
		$have = array_merge( array_map( 'strval', (array) $ctx['held_modules'] ), $ticked );
		if ( ( $ctx['license'] ?? null ) instanceof \WPLM\Models\License ) {
			$today   = wp_date( 'Y-m-d' );
			$grace   = $profile->grace_days();
			$summary = EntitlementService::summarize_lines( $this->entitlements->lines( $ctx['license']->id ), $profile, $today, $grace );
			foreach ( $summary['modules'] as $code => $module ) {
				if ( EntitlementService::STATE_READ_ONLY !== EntitlementService::module_state( $module['until'], $today, $grace ) ) {
					$have[] = (string) $code;
				}
			}
		}
		$have = array_values( array_unique( $have ) );
		foreach ( $items as $item ) {
			$groups = $offers[ $item['id'] ]['requires'];
			foreach ( $item['lines'] as $line ) {
				if ( Entitlement::KIND_MODULE === $line['kind'] && ! empty( $profile->requires[ $line['code'] ] ) ) {
					$groups[] = array_map( 'strval', (array) $profile->requires[ $line['code'] ] );
				}
			}
			foreach ( $groups as $group ) {
				if ( array() === array_intersect( $group, $have ) ) {
					return new \WP_Error(
						'wplm_extras_requires',
						sprintf(
							/* translators: 1: add-on name, 2: module names joined by "or" */
							__( '%1$s needs %2$s.', 'wp-license-manager' ),
							$item['label'],
							implode( ' ' . __( 'or', 'wp-license-manager' ) . ' ', array_map( array( $profile, 'code_label' ), $group ) )
						)
					);
				}
			}
		}

		return array(
			'items' => $items,
			'total' => round( $total, $decimals ),
			'cycle' => $line_cycle,
		);
	}

	// -------------------------------------------------------------------------
	// Snapshot
	// -------------------------------------------------------------------------

	/**
	 * What an order line keeps of the extras sold: billing, flow and the priced items. The filter
	 * `wplm_package_extras_snapshot( array $snapshot, array $ctx )` may add keys (a price list version);
	 * unknown keys are kept, WPLM's own always win.
	 *
	 * `cycle` is the cycle the line's recurring extras (lifetime licence) are billed on, else null; each
	 * item carries its own `cycle` too (null for lifetime and term items) and its `unit_price` is the
	 * price charged for that cycle.
	 *
	 * WPLM's own keys besides `billing`, `flow`, `cycle` and `items` (passed in `$sale`):
	 * - new purchase: `base_price` and `base_signup_fee`, what the licence type cost at the sale (the
	 *   subscription is created at that price, whatever the package costs by the time it is fulfilled);
	 * - existing licence: `target_subscription_id` and `target_paid_through`, the subscription and
	 *   paid-through day the extras were sold against (null for a lifetime licence).
	 *
	 * @param array   $ctx   The context.
	 * @param array[] $items Items from {@see choose()}.
	 * @param array   $sale  WPLM's own extra keys (see above).
	 * @return array
	 */
	public function snapshot( array $ctx, array $items, array $sale = array() ): array {
		$cycle = null;
		foreach ( $items as $item ) {
			$cycle = $cycle ?? ( $item['cycle'] ?? null );
		}
		$own = array_merge(
			array(
				'billing' => (string) $ctx['billing'],
				'flow'    => (string) $ctx['flow'],
				'cycle'   => $cycle,
				'items'   => array_values( $items ),
			),
			$sale
		);

		/**
		 * Extra keys for the extras snapshot kept on an order line.
		 *
		 * @param array $snapshot billing, flow, items.
		 * @param array $ctx      The context.
		 */
		$filtered = apply_filters( 'wplm_package_extras_snapshot', $own, $ctx );
		return array_merge( is_array( $filtered ) ? $filtered : array(), $own );
	}

	/**
	 * Read and check an order line's extras snapshot before anything is written: the billing must be
	 * the one the package (or licence) is billed now, and every item's lines must be valid for the
	 * profile.
	 *
	 * @param string  $json    The `_wplm_extras` meta.
	 * @param string  $billing The billing the line must have (`month`, `year`, `lifetime`).
	 * @param Profile $profile The licence's profile.
	 * @return array The snapshot, its items checked.
	 * @throws \RuntimeException When it cannot be fulfilled as it is.
	 */
	public function read_snapshot( string $json, ?string $billing, Profile $profile ): array {
		$snapshot = json_decode( $json, true );
		if ( ! is_array( $snapshot ) || ! is_array( $snapshot['items'] ?? null ) ) {
			throw new \RuntimeException( 'the add-ons bought on this line are unreadable' );
		}
		if ( null === $billing || ( $snapshot['billing'] ?? null ) !== $billing ) {
			throw new \RuntimeException(
				sprintf( 'the add-ons were priced for "%1$s" billing, but the licence is billed "%2$s"', (string) ( $snapshot['billing'] ?? '' ), (string) $billing )
			);
		}
		$items = array();
		foreach ( $snapshot['items'] as $item ) {
			if ( ! is_array( $item ) ) {
				throw new \RuntimeException( 'an add-on bought on this line is malformed' );
			}
			$qty    = (int) ( $item['qty'] ?? 0 );
			$amount = $item['amount'] ?? null;
			$days   = isset( $item['term_days'] ) && null !== $item['term_days'] ? (int) $item['term_days'] : null;
			if ( $qty < 1 || ! is_numeric( $amount ) || ! is_finite( (float) $amount ) || (float) $amount < 0 || ( null !== $days && $days < 1 ) ) {
				throw new \RuntimeException( 'an add-on bought on this line is malformed' );
			}
			try {
				$lines = $this->entitlements->normalize_template( $profile, (array) ( $item['lines'] ?? array() ) );
			} catch ( \InvalidArgumentException $e ) {
				throw new \RuntimeException( 'an add-on bought on this line is not valid for the licence: ' . $e->getMessage() );
			}
			if ( array() === $lines ) {
				throw new \RuntimeException( 'an add-on bought on this line grants nothing' );
			}
			$cycle = $item['cycle'] ?? null;
			if ( null !== $cycle && ( ! in_array( $cycle, self::CYCLES, true ) || 'lifetime' !== $billing || null !== $days || $cycle !== ( $snapshot['cycle'] ?? null ) ) ) {
				throw new \RuntimeException( 'an add-on bought on this line has a billing cycle it cannot have' );
			}
			$item['cycle']     = $cycle;
			$item['qty']       = $qty;
			$item['amount']    = (float) $amount;
			$item['term_days'] = $days;
			$item['lines']     = $lines;
			$item['label']     = (string) ( $item['label'] ?? $item['id'] ?? '' );
			$items[]           = $item;
		}
		if ( array() === $items ) {
			throw new \RuntimeException( 'no add-ons were bought on this line' );
		}
		$snapshot['items'] = $items;
		return $snapshot;
	}

	/**
	 * The visible "Add-ons" text: "Factory; 2 × Extra phone".
	 *
	 * @param array[] $items Priced items.
	 * @return string
	 */
	public static function describe( array $items ): string {
		$parts = array();
		foreach ( $items as $item ) {
			$label   = (string) ( $item['label'] ?? '' );
			$qty     = (int) ( $item['qty'] ?? 1 );
			$parts[] = $qty > 1 ? $qty . ' × ' . $label : $label;
		}
		return implode( '; ', $parts );
	}

	/**
	 * The price data a form needs: currency and how a price is written.
	 *
	 * @return array{code: string, symbol: string, decimals: int, decimal_separator: string, thousand_separator: string, price_format: string}
	 */
	public static function currency(): array {
		$code = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		return array(
			'code'               => $code,
			'symbol'             => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol( $code ), ENT_QUOTES, 'UTF-8' ) : '',
			'decimals'           => self::decimals(),
			'decimal_separator'  => function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.',
			'thousand_separator' => function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',',
			'price_format'       => function_exists( 'get_woocommerce_price_format' ) ? get_woocommerce_price_format() : '%1$s%2$s',
		);
	}

	/** The shop's price decimals (at least 0). */
	public static function decimals(): int {
		return max( 0, function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2 );
	}
}
