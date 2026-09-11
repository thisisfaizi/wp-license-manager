<?php
/**
 * The result of an admin action, carried across its redirect.
 *
 * @package WPLM\Admin
 */

namespace WPLM\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * One pending result per admin, kept briefly in a transient and shown once on the next page view.
 * Results are `array{ok: bool, message: string, warnings?: string[]}` plus whatever the page needs.
 */
final class Flash {

	/** How long a result waits to be shown, in seconds. */
	private const TTL = 300;

	/** Keep a result for the admin's next page view. */
	public static function set( int $user_id, array $result ): void {
		set_transient( self::key( $user_id ), $result, self::TTL );
	}

	/** Take (and forget) the admin's pending result, or null. */
	public static function take( int $user_id ): ?array {
		$result = get_transient( self::key( $user_id ) );
		delete_transient( self::key( $user_id ) );
		return is_array( $result ) ? $result : null;
	}

	/** Print a result as admin notices: the message, then any warnings. */
	public static function render( ?array $result ): void {
		if ( null === $result ) {
			return;
		}
		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			! empty( $result['ok'] ) ? 'notice-success' : 'notice-error',
			esc_html( (string) ( $result['message'] ?? '' ) )
		);
		foreach ( (array) ( $result['warnings'] ?? array() ) as $warning ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( (string) $warning ) );
		}
	}

	private static function key( int $user_id ): string {
		return 'wplm_admin_flash_' . $user_id;
	}
}
