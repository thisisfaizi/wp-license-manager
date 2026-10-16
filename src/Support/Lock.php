<?php
/**
 * A short-lived claim shared by concurrent requests.
 *
 * @package WPLM\Support
 */

namespace WPLM\Support;

defined( 'ABSPATH' ) || exit;

/**
 * An atomic insert of an option row, which fails while another request holds it. A claim older than
 * its time-to-live is taken to be from a request that died, and is taken over. The option API is not
 * used: `add_option()` cannot be relied on to fail on an existing row, and claims are never cached.
 *
 * Used for one order's fulfilment (`wplm_fulfil_lock_{order id}`) and for add-ons written to one
 * licence (`wplm_addons_lock_{licence id}`).
 */
class Lock {

	/**
	 * Claim a lock.
	 *
	 * @param string $name Option name.
	 * @param int    $ttl  Seconds after which a claim is taken over.
	 * @return bool Whether this request holds the claim.
	 */
	public static function claim( string $name, int $ttl ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic claim.
		$claimed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) );
		if ( 1 === (int) $claimed ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the claim, never cached.
		$since = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( (int) $since > time() - $ttl ) {
			return false; // Another request holds it now.
		}
		// A stale claim: take it over, unless another request just did.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic take-over.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) time(), $name, $since ) );
		return 1 === (int) $taken;
	}

	/**
	 * Release a lock.
	 *
	 * @param string $name Option name.
	 * @return void
	 */
	public static function release( string $name ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the claim, never cached.
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
	}
}
