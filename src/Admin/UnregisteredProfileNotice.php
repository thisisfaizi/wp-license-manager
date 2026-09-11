<?php
/**
 * Warns when licences or plans use a licence profile that no active plugin registers.
 *
 * @package WPLM\Admin
 */

namespace WPLM\Admin;

defined( 'ABSPATH' ) || exit;

use WPLM\Licensing\ProfileRegistry;

/**
 * A profile comes from a product's add-on plugin. If that add-on is deactivated on the licence
 * server, every licence of the product stops getting v2 tokens: each computer goes read-only when its
 * check-in window runs out, paid or not. Nothing else would tell the owner, so every admin screen says
 * so until the add-on is back.
 */
class UnregisteredProfileNotice {

	/** @var ProfileRegistry */
	private ProfileRegistry $profiles;

	public function __construct( ProfileRegistry $profiles ) {
		$this->profiles = $profiles;
	}

	/** Hook the notice. */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Profile codes used by a licence or a plan that nothing registers.
	 *
	 * @return string[] Sorted codes.
	 */
	public function missing(): array {
		global $wpdb;
		$licenses = $wpdb->prefix . 'wplm_licenses';
		$plans    = $wpdb->prefix . 'wplm_plans';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table names only; `licenses.profile` is indexed.
		$used = array_merge(
			(array) $wpdb->get_col( "SELECT DISTINCT profile FROM `{$licenses}` WHERE profile IS NOT NULL AND profile <> ''" ),
			(array) $wpdb->get_col( "SELECT DISTINCT profile FROM `{$plans}` WHERE profile IS NOT NULL AND profile <> ''" )
		);
		// phpcs:enable

		$missing = array_values( array_diff( array_unique( $used ), array_keys( $this->profiles->all() ) ) );
		sort( $missing );
		return $missing;
	}

	/** Print the notice for administrators. */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$missing = $this->missing();
		if ( array() === $missing ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Licences are not being renewed.', 'wp-license-manager' ),
			esc_html(
				sprintf(
					/* translators: %s: comma-separated licence profile codes */
					__( 'Licences or plans use the licence type %s, but no active plugin registers it. Those computers get no new tokens and go read-only when their check-in window runs out, paid or not. Activate the product\'s licensing add-on again.', 'wp-license-manager' ),
					implode( ', ', $missing )
				)
			)
		);
	}
}
