<?php
/**
 * Saving the plan editor, as a plain call the admin-post handler makes.
 *
 * @package WPLM\Admin
 */

namespace WPLM\Admin;

defined( 'ABSPATH' ) || exit;

use WPLM\Licensing\Profile;
use WPLM\Licensing\ProfileRegistry;
use WPLM\Models\Entitlement;
use WPLM\Models\Plan;
use WPLM\Services\EntitlementService;
use WPLM\Services\PlanService;

/**
 * A plan may sell a licence profile. Each licence type (package) of such a plan carries an
 * entitlement template: the modules it grants and how much it adds to each of the profile's limits.
 * Every template is checked **before** the plan or its packages change, so a bad template
 * leaves the plan exactly as it was.
 */
class PlanAdminActions {

	/** @var PlanService */
	private PlanService $plans;

	/** @var ProfileRegistry */
	private ProfileRegistry $profiles;

	/** @var EntitlementService */
	private EntitlementService $entitlements;

	public function __construct( PlanService $plans, ProfileRegistry $profiles, EntitlementService $entitlements ) {
		$this->plans        = $plans;
		$this->profiles     = $profiles;
		$this->entitlements = $entitlements;
	}

	/**
	 * Create or update a plan and its packages from the editor.
	 *
	 * @param array $post Unslashed form fields: plan_id, plan_name, plan_description, plan_status,
	 *                    plan_profile, plan_kind (standalone|addon|dynamic; the pre-1.2.7 checkbox
	 *                    plan_addon is still accepted), packages[] (each with entitlements[modules][] and
	 *                    entitlements[limits][code]).
	 * @return array{ok: bool, message: string, plan_id: int, warnings: string[]}
	 */
	public function save_plan( array $post ): array {
		$plan_id = absint( $post['plan_id'] ?? 0 );
		$name    = sanitize_text_field( (string) ( $post['plan_name'] ?? '' ) );
		$code    = sanitize_key( (string) ( $post['plan_profile'] ?? '' ) );

		if ( '' === trim( $name ) ) {
			return $this->result( false, __( 'Give the plan a name.', 'wp-license-manager' ), $plan_id );
		}
		$profile = '' === $code ? null : $this->profiles->get( $code );
		if ( '' !== $code && null === $profile ) {
			/* translators: %s: profile code */
			return $this->result( false, sprintf( __( 'Unknown licence type "%s".', 'wp-license-manager' ), $code ), $plan_id );
		}
		$kind  = self::posted_kind( $post );
		$addon = Plan::KIND_ADDON === $kind;
		if ( $addon && null === $profile ) {
			return $this->result( false, __( 'An add-on plan adds modules and limits to a customer\'s existing licence, so it needs a licence type with modules and limits. Choose one, or choose "This plan sells a licence".', 'wp-license-manager' ), $plan_id );
		}
		if ( Plan::KIND_DYNAMIC === $kind ) {
			return $this->save_dynamic_plan( $plan_id, $name, $post, $profile );
		}

		$packages = array();
		foreach ( (array) ( $post['packages'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || '' === trim( sanitize_text_field( (string) ( $row['name'] ?? '' ) ) ) ) {
				continue; // An empty row (e.g. an unused "add licence type" row) is skipped.
			}
			$package = $this->package_fields( $row );
			if ( null !== $profile ) {
				$package['entitlements'] = $this->template( $row );
			}
			$packages[] = $package;
		}

		// Check every template against the profile before anything is written.
		$warnings = $this->term_warnings( $packages );
		if ( null !== $profile ) {
			$grants_base = false;
			foreach ( $packages as $package ) {
				try {
					$lines = $this->entitlements->normalize_template( $profile, $package['entitlements'] );
				} catch ( \InvalidArgumentException $e ) {
					/* translators: 1: licence type name, 2: reason */
					return $this->result( false, sprintf( __( '%1$s: %2$s', 'wp-license-manager' ), $package['name'], $e->getMessage() ), $plan_id );
				}
				$package_grants_base = false;
				foreach ( $lines as $line ) {
					$package_grants_base = $package_grants_base || ( Entitlement::KIND_MODULE === $line['kind'] && $profile->base_module === $line['code'] );
				}
				$grants_base = $grants_base || $package_grants_base;
				if ( $addon && $package_grants_base ) {
					$warnings[] = sprintf(
						/* translators: 1: licence type name, 2: base module name */
						__( '%1$s grants %2$s. An add-on is added to a licence that already has %2$s; %2$s belongs on the plan that sells the licence.', 'wp-license-manager' ),
						$package['name'],
						$profile->code_label( (string) $profile->base_module )
					);
				}
			}
			if ( ! $addon && null !== $profile->base_module && ! $grants_base ) {
				$warnings[] = sprintf(
					/* translators: 1: base module name, 2: product name */
					__( 'No licence type in this plan grants %1$s. That is right for an add-on plan, but a customer who buys only this plan cannot use %2$s.', 'wp-license-manager' ),
					$profile->code_label( $profile->base_module ),
					$profile->label
				);
			}
		}

		// An add-on plan may sell only package extras (1.3.0), priced by a plugin: no rows needed then.
		if ( $addon && array() === $packages && ! has_filter( 'wplm_package_extras' ) ) {
			$warnings[] = __( 'This add-on plan has no add-on types, and no active plugin prices add-ons (the wplm_package_extras filter), so its products cannot be bought yet.', 'wp-license-manager' );
		}

		$fields = array(
			'name'        => $name,
			'description' => sanitize_textarea_field( (string) ( $post['plan_description'] ?? '' ) ),
			'status'      => empty( $post['plan_status'] ) ? 0 : 1,
			'profile'     => null !== $profile ? $profile->code : null,
			'kind'        => $kind,
		);

		try {
			if ( $plan_id > 0 ) {
				$this->plans->update_plan( $plan_id, $fields );
			} else {
				$plan_id = $this->plans->create_plan( $fields );
			}
			$this->plans->sync_packages( $plan_id, $packages );
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			return $this->result( false, $e->getMessage(), $plan_id );
		}

		return $this->result( true, __( 'Plan saved.', 'wp-license-manager' ), $plan_id, $warnings );
	}

	/**
	 * The plan kind the editor posted: `plan_kind` (standalone|addon|dynamic), or the checkbox
	 * `plan_addon` that editors before 1.2.7 posted.
	 *
	 * @param array $post Unslashed form fields.
	 * @return string
	 */
	private static function posted_kind( array $post ): string {
		$kind = sanitize_key( (string) ( $post['plan_kind'] ?? '' ) );
		if ( in_array( $kind, Plan::KINDS, true ) ) {
			return $kind;
		}
		return ! empty( $post['plan_addon'] ) ? Plan::KIND_ADDON : Plan::KIND_STANDALONE;
	}

	/**
	 * Save a plan whose package is built at purchase. It has no licence types of its own, so the
	 * posted rows are ignored and the plan's stored packages are left as they are (old orders and
	 * subscriptions may still point at them; a dynamic plan never sells them).
	 *
	 * @param int          $plan_id Plan id (0 = new).
	 * @param string       $name    Plan name.
	 * @param array        $post    Unslashed form fields.
	 * @param Profile|null $profile The licence profile it sells.
	 * @return array{ok: bool, message: string, plan_id: int, warnings: string[]}
	 */
	private function save_dynamic_plan( int $plan_id, string $name, array $post, ?Profile $profile ): array {
		if ( null === $profile ) {
			return $this->result( false, __( 'A plan built at purchase writes modules and limits onto the licence, so it needs a licence type with modules and limits. Choose one, or choose another kind of plan.', 'wp-license-manager' ), $plan_id );
		}
		$fields = array(
			'name'        => $name,
			'description' => sanitize_textarea_field( (string) ( $post['plan_description'] ?? '' ) ),
			'status'      => empty( $post['plan_status'] ) ? 0 : 1,
			'profile'     => $profile->code,
			'kind'        => Plan::KIND_DYNAMIC,
		);
		try {
			if ( $plan_id > 0 ) {
				$this->plans->update_plan( $plan_id, $fields );
			} else {
				$plan_id = $this->plans->create_plan( $fields );
			}
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			return $this->result( false, $e->getMessage(), $plan_id );
		}
		$warnings = array();
		if ( ! has_filter( 'wplm_dynamic_package' ) ) {
			$warnings[] = __( 'No active plugin builds packages for this plan (the wplm_dynamic_package filter), so its products cannot be bought yet.', 'wp-license-manager' );
		}
		return $this->result( true, __( 'Plan saved.', 'wp-license-manager' ), $plan_id, $warnings );
	}

	/**
	 * A one-time licence type with no "Valid for (days)" grants for life, exactly like Lifetime. That is
	 * kept so older rows keep selling what they sold, but it is almost never meant, so it is called out.
	 *
	 * @param array[] $packages Rows from {@see package_fields()}.
	 * @return string[]
	 */
	private function term_warnings( array $packages ): array {
		$warnings = array();
		foreach ( $packages as $package ) {
			if ( 'onetime' === $package['billing_type'] && (int) $package['valid_for_days'] < 1 ) {
				$warnings[] = sprintf(
					/* translators: %s: licence type name */
					__( '%s: One-time needs "Valid for (days)"; blank grants for life — choose Lifetime instead.', 'wp-license-manager' ),
					$package['name']
				);
			}
		}
		return $warnings;
	}

	/**
	 * The storable fields of one package row.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private function package_fields( array $row ): array {
		return array(
			'id'               => absint( $row['id'] ?? 0 ),
			'name'             => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
			'billing_type'     => sanitize_key( (string) ( $row['billing_type'] ?? 'recurring' ) ),
			'billing_period'   => sanitize_key( (string) ( $row['billing_period'] ?? 'month' ) ),
			'billing_interval' => absint( $row['billing_interval'] ?? 1 ),
			'price'            => self::parse_decimal( $row['price'] ?? '0' ),
			'signup_fee'       => self::parse_decimal( $row['signup_fee'] ?? '0' ),
			'trial_days'       => absint( $row['trial_days'] ?? 0 ),
			'length_cycles'    => absint( $row['length_cycles'] ?? 0 ),
			'generator_id'     => absint( $row['generator_id'] ?? 0 ),
			'max_activations'  => '' !== (string) ( $row['max_activations'] ?? '' ) ? absint( $row['max_activations'] ) : '',
			'grace_days'       => '' !== (string) ( $row['grace_days'] ?? '' ) ? absint( $row['grace_days'] ) : '',
			'valid_for_days'   => '' !== (string) ( $row['valid_for_days'] ?? '' ) ? absint( $row['valid_for_days'] ) : '',
			'overage_strategy' => sanitize_key( (string) ( $row['overage_strategy'] ?? 'deny' ) ),
			'benefits'         => sanitize_textarea_field( (string) ( $row['benefits'] ?? '' ) ),
			'status'           => empty( $row['status'] ) ? 0 : 1,
		);
	}

	/**
	 * The entitlement template posted with a package row: checked modules, then limits with a quantity.
	 * Codes are passed through as posted so an unknown one is reported, not silently dropped.
	 *
	 * @param array $row Raw row.
	 * @return array<int, array{kind: string, code: string, qty: int}>
	 */
	private function template( array $row ): array {
		$posted   = (array) ( $row['entitlements'] ?? array() );
		$template = array();
		foreach ( array_unique( array_map( 'strval', (array) ( $posted['modules'] ?? array() ) ) ) as $code ) {
			$template[] = array(
				'kind' => Entitlement::KIND_MODULE,
				'code' => sanitize_key( $code ),
				'qty'  => 0,
			);
		}
		foreach ( (array) ( $posted['limits'] ?? array() ) as $code => $qty ) {
			if ( '' === trim( (string) $qty ) || absint( $qty ) < 1 ) {
				continue; // Blank or zero adds nothing.
			}
			$template[] = array(
				'kind' => Entitlement::KIND_LIMIT,
				'code' => sanitize_key( (string) $code ),
				'qty'  => absint( $qty ),
			);
		}
		return $template;
	}

	/** Parse a price the way WooCommerce does (its decimal separator setting), else digits and a dot. */
	public static function parse_decimal( $value ): float {
		if ( function_exists( 'wc_format_decimal' ) ) {
			return (float) wc_format_decimal( $value );
		}
		return (float) preg_replace( '/[^0-9.\-]/', '', (string) $value );
	}

	/** @return array{ok: bool, message: string, plan_id: int, warnings: string[]} */
	private function result( bool $ok, string $message, int $plan_id, array $warnings = array() ): array {
		return array(
			'ok'       => $ok,
			'message'  => $message,
			'plan_id'  => $plan_id,
			'warnings' => $warnings,
		);
	}
}
