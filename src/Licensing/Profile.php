<?php
/**
 * Licence profile — what a product's entitlement lines may contain and how its v2 token is timed.
 *
 * @package WPLM\Licensing
 */

namespace WPLM\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * A licence with a profile is an **entitlement licence**: what it grants and until when lives in
 * its entitlement lines (`wplm_entitlements`), not in `expires_at`, and its machines receive a v2
 * token. A licence without a profile is a classic licence and nothing about it changes.
 *
 * The code lists are closed: a line whose code is not listed is refused on save, because a typo in
 * admin would otherwise sell nothing without anyone noticing.
 */
final class Profile {

	/** Default days a machine may go without checking in. */
	public const DEFAULT_CHECK_IN_DAYS = 7;

	/** Default days an unpaid module keeps working after its paid-through date. */
	public const DEFAULT_GRACE_DAYS = 7;

	/** @var string */
	public string $code;

	/** @var string */
	public string $label;

	/** @var string[] */
	public array $module_codes;

	/** @var string[] */
	public array $limit_codes;

	/**
	 * The module without which the whole product is read-only (for example a base accounting module),
	 * or null when every module stands alone. Admin warns when a plan or licence lacks it, and the
	 * read-only email words its lapse as the whole product.
	 *
	 * @var string|null
	 */
	public ?string $base_module;

	/** @var array<string, string> */
	private array $labels;

	/**
	 * @param string                $code         Wire identifier, signed into the token as `pid`.
	 * @param string                $label        Admin and customer-facing product name.
	 * @param string[]              $module_codes Allowed module codes.
	 * @param string[]              $limit_codes  Allowed limit codes.
	 * @param array<string, string> $labels       Human names for module and limit codes.
	 * @param string|null           $base_module  One of the module codes, or null; any other value is ignored.
	 */
	public function __construct( string $code, string $label, array $module_codes, array $limit_codes, array $labels = array(), ?string $base_module = null ) {
		$this->code         = $code;
		$this->label        = $label;
		$this->module_codes = array_values( $module_codes );
		$this->limit_codes  = array_values( $limit_codes );
		$this->labels       = $labels;
		$this->base_module  = in_array( $base_module, $this->module_codes, true ) ? $base_module : null;
	}

	/** The human name of a module or limit code; the code itself when none is registered. */
	public function code_label( string $code ): string {
		return $this->labels[ $code ] ?? $code;
	}

	/** Whether a line kind + code pair is allowed for this profile. */
	public function allows( string $kind, string $code ): bool {
		if ( 'module' === $kind ) {
			return in_array( $code, $this->module_codes, true );
		}
		if ( 'limit' === $kind ) {
			return in_array( $code, $this->limit_codes, true );
		}
		return false;
	}

	/** Days a machine may go without a successful check-in (setting, default 7). */
	public function check_in_days(): int {
		return $this->setting( 'check_in_days', self::DEFAULT_CHECK_IN_DAYS, 1 );
	}

	/** Days a lapsed module keeps working (setting, default 7). */
	public function grace_days(): int {
		return $this->setting( 'grace_days', self::DEFAULT_GRACE_DAYS, 0 );
	}

	/** The option name holding one of this profile's settings. */
	public function option_name( string $key ): string {
		return 'wplm_profile_' . str_replace( '-', '_', $this->code ) . '_' . $key;
	}

	/**
	 * Read an integer setting with a floor.
	 *
	 * @param string $key     Setting key.
	 * @param int    $fallback Default when unset.
	 * @param int    $min     Smallest accepted value.
	 * @return int
	 */
	private function setting( string $key, int $fallback, int $min ): int {
		$raw = get_option( $this->option_name( $key ), '' );
		if ( '' === $raw || null === $raw || ! is_numeric( $raw ) ) {
			return $fallback;
		}
		return max( $min, (int) $raw );
	}
}
