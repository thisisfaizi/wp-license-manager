<?php
/**
 * Static validation and sanitization helpers for REST param callbacks.
 *
 * @package WPLM\Support
 */

namespace WPLM\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Provides static helpers used in REST route validate_callback and
 * sanitize_callback definitions throughout WPLM.
 */
class Validator {

	/**
	 * Check whether a value is a positive integer (> 0).
	 *
	 * Accepts true integers and numeric strings that resolve to a positive int.
	 *
	 * @param mixed $val Value to test.
	 * @return bool
	 */
	public static function is_positive_int( $val ): bool {
		if ( ! is_numeric( $val ) ) {
			return false;
		}

		$int = (int) $val;

		return $int > 0 && (string) $int === (string) (int) $val;
	}

	/**
	 * Sanitize a value intended to serve as a license key or similar identifier.
	 *
	 * Strips unsafe characters via sanitize_text_field() then upper-cases the result
	 * so stored keys are normalised regardless of how the client submitted them.
	 *
	 * @param string $val Raw value from the request.
	 * @return string Upper-cased, sanitized string.
	 */
	public static function sanitize_key_string( string $val ): string {
		return strtoupper( sanitize_text_field( $val ) );
	}

	/**
	 * Sanitize a device fingerprint value.
	 *
	 * Applies sanitize_text_field() and hard-caps the result at 64 characters
	 * (matching the CHAR(64) fingerprint column in wplm_machines).
	 *
	 * @param string $val Raw fingerprint from the request.
	 * @return string Sanitized fingerprint, max 64 characters.
	 */
	public static function sanitize_fingerprint( string $val ): string {
		return substr( sanitize_text_field( $val ), 0, 64 );
	}

	/**
	 * Sanitize a URL parameter.
	 *
	 * Uses esc_url_raw() which is appropriate for URLs that will be stored or
	 * used in HTTP requests (not echoed into HTML attributes).
	 *
	 * @param string $val Raw URL from the request.
	 * @return string Sanitized URL.
	 */
	public static function sanitize_url( string $val ): string {
		return esc_url_raw( $val );
	}

	/**
	 * Sanitize an array of event name strings.
	 *
	 * Each element is passed through sanitize_text_field() to strip tags and
	 * extra whitespace. Non-string elements are cast to string first.
	 *
	 * @param array $val Array of raw event name values.
	 * @return array Sanitized array with the same keys.
	 */
	public static function sanitize_events( array $val ): array {
		return array_map( 'sanitize_text_field', $val );
	}

	/**
	 * Validate that an overage strategy string is one of the allowed values.
	 *
	 * Allowed values correspond to the overage_strategy column options in
	 * wplm_licenses: deny, allow_1_25x, allow_2x.
	 *
	 * @param string $val Candidate strategy value.
	 * @return bool True when the value is an accepted overage strategy.
	 */
	public static function validate_overage_strategy( string $val ): bool {
		return in_array( $val, array( 'deny', 'allow_1_25x', 'allow_2x' ), true );
	}

	/**
	 * Validate that a billing period string is one of the allowed calendar units.
	 *
	 * Allowed values: day, week, month, year — matching the native subscription
	 * engine's supported billing intervals.
	 *
	 * @param string $val Candidate billing period value.
	 * @return bool True when the value is an accepted billing period.
	 */
	public static function validate_billing_period( string $val ): bool {
		return in_array( $val, array( 'day', 'week', 'month', 'year' ), true );
	}
}
