<?php
/**
 * WooCommerce My Account portal — Licenses and Subscriptions endpoints.
 *
 * @package WPLM\Integrations\WooCommerce
 */

namespace WPLM\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WPLM\Services\Subscriptions\SubscriptionService;

/**
 * Registers "Licenses" and "Subscriptions" tabs in the WooCommerce My Account
 * area. Customers can view their license keys and manage subscriptions
 * (pause, resume, cancel, change payment method) from these pages.
 */
class MyAccountSubscriptions {

	private SubscriptionService $sub_service;

	public function __construct( SubscriptionService $sub_service ) {
		$this->sub_service = $sub_service;
	}

	public function register(): void {
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_items' ) );
		add_action( 'init', array( $this, 'add_endpoints' ) );
		add_action( 'woocommerce_account_wplm-licenses_endpoint', array( $this, 'render_licenses' ) );
		add_action( 'woocommerce_account_wplm-subscriptions_endpoint', array( $this, 'render_subscriptions' ) );
		add_action( 'admin_post_wplm_sub_action', array( $this, 'handle_sub_action' ) );
		add_action( 'admin_post_nopriv_wplm_sub_action', array( $this, 'handle_sub_action' ) );
	}

	/** Register rewrite endpoints for the My Account tabs. */
	public function add_endpoints(): void {
		add_rewrite_endpoint( 'wplm-licenses', EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( 'wplm-subscriptions', EP_ROOT | EP_PAGES );
	}

	/** Inject WPLM tabs into the My Account nav. */
	public function add_menu_items( array $items ): array {
		// Insert before the logout link.
		$logout = array_splice( $items, array_search( 'customer-logout', array_keys( $items ), true ) );

		$items['wplm-licenses']      = __( 'Licenses', 'wp-license-manager' );
		$items['wplm-subscriptions'] = __( 'Subscriptions', 'wp-license-manager' );

		return array_merge( $items, $logout );
	}

	/** Render the My Licenses tab. */
	public function render_licenses(): void {
		$user_id  = get_current_user_id();
		$result   = wplm_get_licenses( array( 'user_id' => $user_id ) );
		$licenses = $result['items'] ?? array();

		wc_get_template(
			'myaccount/wplm-licenses.php',
			array( 'licenses' => $licenses ),
			'',
			WPLM_PLUGIN_DIR . 'templates/'
		);
	}

	/** Render the My Subscriptions tab. */
	public function render_subscriptions(): void {
		$subscriptions = wplm_get_subscriptions( array( 'user_id' => get_current_user_id() ) );

		wc_get_template(
			'myaccount/wplm-subscriptions.php',
			array( 'subscriptions' => $subscriptions ),
			'',
			WPLM_PLUGIN_DIR . 'templates/'
		);
	}

	/** Handle pause / resume / cancel POST actions from My Account. */
	public function handle_sub_action(): void {
		$nonce  = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) );
		$action = sanitize_text_field( wp_unslash( $_POST['wplm_action'] ?? '' ) );
		$sub_id = absint( $_POST['subscription_id'] ?? 0 );

		if ( ! wp_verify_nonce( $nonce, 'wplm_sub_action_' . $sub_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'wp-license-manager' ) );
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wc_get_account_endpoint_url( 'wplm-subscriptions' ) );
			exit;
		}

		$sub = wplm_get_subscription( $sub_id );
		if ( ! $sub || (int) $sub->user_id !== get_current_user_id() ) {
			wp_die( esc_html__( 'Subscription not found.', 'wp-license-manager' ) );
		}

		switch ( $action ) {
			case 'pause':
				wplm_pause_subscription( $sub_id );
				break;
			case 'resume':
				wplm_resume_subscription( $sub_id );
				break;
			case 'cancel':
				$at_end = ! empty( $_POST['at_period_end'] );
				wplm_cancel_subscription( $sub_id, $at_end );
				break;
			case 'renew':
				$pay_url = function_exists( 'wplm_start_renewal' ) ? wplm_start_renewal( $sub_id ) : false;
				if ( false !== $pay_url ) {
					wp_safe_redirect( $pay_url );
					exit;
				}
				break;
		}

		wp_safe_redirect( wc_get_account_endpoint_url( 'wplm-subscriptions' ) );
		exit;
	}
}
