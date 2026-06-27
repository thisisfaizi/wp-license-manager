<?php
/**
 * Lightweight logging wrapper for WPLM.
 *
 * @package WPLM\Support
 */

namespace WPLM\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps error_log() with [WPLM] prefixes and conditional debug gating.
 *
 * Usage:
 *   Logger::debug( 'License validated', [ 'key' => $key ] );
 *   Logger::error( 'DB write failed', [ 'sql' => $sql, 'error' => $err ] );
 *
 * Debug messages are suppressed unless WP_DEBUG_LOG is truthy.
 * Error messages are always written regardless of WP_DEBUG_LOG.
 */
class Logger {

	/**
	 * Log a debug-level message.
	 *
	 * Only writes to the log when WP_DEBUG_LOG is defined and truthy,
	 * preventing noise on production sites.
	 *
	 * @param string $message Human-readable description of the event.
	 * @param array  $context Optional key-value context data, JSON-encoded and appended.
	 * @return void
	 */
	public static function debug( string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		error_log( self::format( 'debug', $message, $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Log an error-level message.
	 *
	 * Always writes to the log regardless of WP_DEBUG_LOG so that operational
	 * errors are never silently swallowed on any environment.
	 *
	 * @param string $message Human-readable description of the error.
	 * @param array  $context Optional key-value context data, JSON-encoded and appended.
	 * @return void
	 */
	public static function error( string $message, array $context = array() ): void {
		error_log( self::format( 'error', $message, $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the final log string in the WPLM format.
	 *
	 * Format: [WPLM][{level}] {message}[ {json_context}]
	 *
	 * @param string $level   Log level label, e.g. 'debug' or 'error'.
	 * @param string $message The message text.
	 * @param array  $context Context array; omitted from the string when empty.
	 * @return string Formatted log line.
	 */
	private static function format( string $level, string $message, array $context ): string {
		$line = sprintf( '[WPLM][%s] %s', $level, $message );

		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		return $line;
	}
}
