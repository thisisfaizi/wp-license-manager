<?php
/**
 * WooCommerce order admin meta box — shows issued license keys and subscription link.
 *
 * @package WPLM\Integrations\WooCommerce
 */

namespace WPLM\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WPLM\Repositories\LicenseRepository;

/**
 * Adds a "WPLM License Keys" meta box to the WooCommerce order admin screen
 * listing the license keys issued for each line item, with quick revoke and
 * resend actions. Subscription orders also link to the subscription record.
 *
 * Compatible with both Classic Orders (post-based) and HPOS (WC 7.1+).
 */
class OrderMetaBox {

	/** @var LicenseRepository */
	private LicenseRepository $license_repo;

	/**
	 * @param LicenseRepository $license_repo
	 */
	public function __construct( LicenseRepository $license_repo ) {
		$this->license_repo = $license_repo;
	}

	/** Register hooks. */
	public function register(): void {
		// Classic order edit screen (post-based).
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

		// HPOS (WooCommerce 7.1+ custom order tables).
		add_action( 'woocommerce_order_data_after_order_details', array( $this, 'maybe_render_hpos' ) );

		// Quick revoke action.
		add_action( 'admin_post_wplm_revoke_order_license', array( $this, 'handle_revoke' ) );
		// Quick resend action.
		add_action( 'admin_post_wplm_resend_order_license', array( $this, 'handle_resend' ) );
	}

	// -------------------------------------------------------------------------
	// Meta box registration
	// -------------------------------------------------------------------------

	/** Register the meta box on WC classic order screens. */
	public function add_meta_box(): void {
		$screens = array( 'shop_order' );

		foreach ( $screens as $screen ) {
			add_meta_box(
				'wplm-order-licenses',
				__( 'WPLM License Keys', 'wp-license-manager' ),
				array( $this, 'render' ),
				$screen,
				'side',
				'high'
			);
		}
	}

	/**
	 * HPOS: WC fires this action inside the order-edit page. Wrap it in a
	 * styled card to match the meta-box look without needing a formal meta_box.
	 *
	 * @param \WC_Order $order
	 */
	public function maybe_render_hpos( \WC_Order $order ): void {
		// Only render if HPOS is active (wc_get_container exists and DualWriteController registers it).
		if ( ! did_action( 'woocommerce_order_data_after_order_details' ) ) {
			return;
		}
		echo '<div class="wplm-hpos-metabox" style="background:#fff;border:1px solid #ccd0d4;padding:12px 16px;margin-top:12px;">';
		echo '<h2 style="font-size:14px;margin:0 0 8px;">' . esc_html__( 'WPLM License Keys', 'wp-license-manager' ) . '</h2>';
		$this->render_content( $order );
		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Render callback for classic WP_Post / add_meta_box().
	 *
	 * @param \WP_Post|\WC_Order $post_or_order
	 */
	public function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order
			? $post_or_order
			: wc_get_order( $post_or_order->ID );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->render_content( $order );
	}

	/**
	 * Shared render logic for both classic and HPOS screens.
	 *
	 * @param \WC_Order $order
	 */
	private function render_content( \WC_Order $order ): void {
		$order_id    = $order->get_id();
		$has_content = false;

		/** @var \WC_Order_Item_Product $item */
		foreach ( $order->get_items() as $item ) {
			$json   = $item->get_meta( '_wplm_license_ids' );
			$sub_id = (int) $item->get_meta( '_wplm_subscription_id' );

			if ( empty( $json ) && ! $sub_id ) {
				continue;
			}

			$has_content = true;
			$ids         = ! empty( $json ) ? (array) json_decode( $json, true ) : array();

			echo '<p><strong>' . esc_html( $item->get_name() ) . '</strong></p>';

			if ( $sub_id > 0 ) {
				$sub_url = admin_url( 'admin.php?page=wplm-subscriptions&action=edit&id=' . $sub_id );
				echo '<p>';
				printf(
					'<a href="%s">%s</a>',
					esc_url( $sub_url ),
					/* translators: %d: subscription ID */
					esc_html( sprintf( __( 'Subscription #%d', 'wp-license-manager' ), $sub_id ) )
				);
				echo '</p>';
			}

			foreach ( $ids as $license_id ) {
				$license_id = (int) $license_id;
				$license    = $this->license_repo->find_by_id( $license_id );

				if ( ! $license ) {
					continue;
				}

				// Status badge.
				$status_map   = array(
					0 => array( 'Pending', '#c3c4c7', '#000' ),
					1 => array( 'Active', '#00a32a', '#fff' ),
					2 => array( 'Inactive', '#72777c', '#fff' ),
					3 => array( 'Expired', '#d63638', '#fff' ),
					4 => array( 'Suspended', '#dba617', '#fff' ),
					5 => array( 'Revoked', '#d63638', '#fff' ),
					6 => array( 'Terminated', '#000', '#fff' ),
				);
				$status_info  = $status_map[ $license->status ] ?? array( 'Unknown', '#c3c4c7', '#000' );
				$status_badge = sprintf(
					'<span style="display:inline-block;padding:1px 6px;border-radius:3px;background:%s;color:%s;font-size:11px;">%s</span>',
					esc_attr( $status_info[1] ),
					esc_attr( $status_info[2] ),
					esc_html( $status_info[0] )
				);

				// Key display (plaintext is unavailable at this point — show first chars of encrypted value).
				$key_display = $license->license_key
					? '<code>' . esc_html( substr( $license->license_key, 0, 16 ) ) . '&hellip;</code>'
					: '<em>' . esc_html__( 'N/A', 'wp-license-manager' ) . '</em>';

				// Action URLs.
				$revoke_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=wplm_revoke_order_license&license_id=' . $license_id . '&order_id=' . $order_id ),
					'wplm_revoke_order_license_' . $license_id
				);
				$resend_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=wplm_resend_order_license&license_id=' . $license_id . '&order_id=' . $order_id ),
					'wplm_resend_order_license_' . $license_id
				);

				$edit_url = admin_url( 'admin.php?page=wplm-licenses&action=edit&id=' . $license_id );

				echo '<div style="margin-bottom:8px;padding:6px 8px;border:1px solid #ddd;border-radius:3px;">';
				echo '<div>' . $key_display . ' ' . $status_badge . '</div>'; // phpcs:ignore
				echo '<div style="margin-top:4px;font-size:12px;">';
				printf(
					'<a href="%s">%s</a> | ',
					esc_url( $edit_url ),
					esc_html__( 'Edit', 'wp-license-manager' )
				);
				if ( 5 !== $license->status && 6 !== $license->status ) {
					printf(
						'<a href="%s" onclick="return confirm(\'%s\');" style="color:#d63638;">%s</a> | ',
						esc_url( $revoke_url ),
						esc_js( __( 'Revoke this license key?', 'wp-license-manager' ) ),
						esc_html__( 'Revoke', 'wp-license-manager' )
					);
				}
				printf(
					'<a href="%s">%s</a>',
					esc_url( $resend_url ),
					esc_html__( 'Resend', 'wp-license-manager' )
				);
				echo '</div>';
				echo '</div>';
			}

			echo '<hr>';
		}

		if ( ! $has_content ) {
			echo '<p>' . esc_html__( 'No license keys issued for this order.', 'wp-license-manager' ) . '</p>';
		}
	}

	// -------------------------------------------------------------------------
	// Action handlers
	// -------------------------------------------------------------------------

	/** Revoke a license from the order meta box quick action. */
	public function handle_revoke(): void {
		$license_id = absint( $_GET['license_id'] ?? 0 );
		$order_id   = absint( $_GET['order_id'] ?? 0 );

		check_admin_referer( 'wplm_revoke_order_license_' . $license_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-license-manager' ) );
		}

		if ( $license_id ) {
			$license = $this->license_repo->find_by_id( $license_id );
			if ( $license ) {
				wplm_revoke_license( $license->license_key );
			}
		}

		$redirect = $order_id
			? get_edit_post_link( $order_id, 'raw' ) ?? admin_url( 'post.php?post=' . $order_id . '&action=edit' )
			: admin_url( 'edit.php?post_type=shop_order' );

		wp_safe_redirect( $redirect );
		exit;
	}

	/** Resend license delivery email from the order meta box quick action. */
	public function handle_resend(): void {
		$license_id = absint( $_GET['license_id'] ?? 0 );
		$order_id   = absint( $_GET['order_id'] ?? 0 );

		check_admin_referer( 'wplm_resend_order_license_' . $license_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wp-license-manager' ) );
		}

		if ( $license_id && $order_id ) {
			$order   = wc_get_order( $order_id );
			$license = $this->license_repo->find_by_id( $license_id );

			if ( $order instanceof \WC_Order && $license ) {
				// Reuse the CheckoutHandler email logic by firing the action.
				do_action( 'wplm_order_licenses_issued', $order_id, array( $license ) );

				// Simple direct email fallback.
				wp_mail(
					$order->get_billing_email(),
					__( 'Your License Key (Resent)', 'wp-license-manager' ),
					apply_filters(
						'wplm_email_license_keys',
						$license->license_key,
						$order,
						array( $license )
					)
				);
			}
		}

		$redirect = $order_id
			? get_edit_post_link( $order_id, 'raw' ) ?? admin_url( 'post.php?post=' . $order_id . '&action=edit' )
			: admin_url( 'edit.php?post_type=shop_order' );

		wp_safe_redirect( add_query_arg( 'wplm_resent', '1', $redirect ) );
		exit;
	}
}
