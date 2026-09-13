<?php
/**
 * The licence keys, inside WooCommerce's own completed-order email.
 *
 * @package WPLM\Integrations\WooCommerce
 */

namespace WPLM\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WPLM\Repositories\LicenseRepository;

/**
 * Prints a licence block under the order table of the customer's completed-order email.
 *
 * This is the email a customer actually opens, so it is where the key belongs — a separate
 * plain-text message is easy to miss, easy to lose, and easy to mistake for spam.
 *
 * It renders only for the customer's *completed* order email: the processing email is sent before a
 * licence exists, and the admin copies have no business carrying keys.
 */
class OrderEmailKeys {

	/** @var LicenseRepository */
	private LicenseRepository $licenses;

	/**
	 * @param LicenseRepository $licenses Reads the licences issued for an order.
	 */
	public function __construct( LicenseRepository $licenses ) {
		$this->licenses = $licenses;
	}

	/** Register the email hook. */
	public function register(): void {
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render' ), 10, 4 );
	}

	/**
	 * Print the licence block.
	 *
	 * @param mixed $order         The order being emailed.
	 * @param bool  $sent_to_admin Whether this is the admin copy.
	 * @param bool  $plain_text    Whether the email is plain text.
	 * @param mixed $email         The WC_Email being sent.
	 * @return void
	 */
	public function render( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( $sent_to_admin || ! $order instanceof \WC_Order ) {
			return;
		}

		$email_id = $email instanceof \WC_Email ? $email->id : '';
		if ( 'customer_completed_order' !== $email_id ) {
			return;
		}

		$keys = $this->keys_for( $order );
		if ( empty( $keys ) ) {
			return;
		}

		$heading = __( 'Your Super Ledger licence', 'wp-license-manager' );
		$label   = _n( 'Licence key', 'Licence keys', count( $keys ), 'wp-license-manager' );

		/**
		 * Extra lines to show under the keys, such as an office address.
		 *
		 * @param string[]  $extra Lines to print, already translated.
		 * @param \WC_Order $order The order being emailed.
		 */
		$extra = (array) apply_filters( 'wplm_email_order_licence_lines', array(), $order );

		if ( $plain_text ) {
			echo "\n\n" . esc_html( strtoupper( $heading ) ) . "\n";
			echo esc_html( $label ) . ":\n";
			foreach ( $keys as $key ) {
				echo esc_html( $key ) . "\n";
			}
			foreach ( $extra as $line ) {
				echo "\n" . esc_html( $line ) . "\n";
			}
			echo "\n";
			return;
		}

		echo '<div style="margin:24px 0;padding:16px;border:1px solid #e0e0e0;border-radius:4px;">';
		echo '<h2 style="margin:0 0 12px;font-size:16px;">' . esc_html( $heading ) . '</h2>';
		echo '<p style="margin:0 0 4px;font-weight:600;">' . esc_html( $label ) . '</p>';
		foreach ( $keys as $key ) {
			echo '<p style="margin:0 0 12px;font-family:monospace;font-size:15px;">' . esc_html( $key ) . '</p>';
		}
		foreach ( $extra as $line ) {
			echo '<p style="margin:0 0 8px;">' . nl2br( esc_html( $line ) ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * The plaintext keys issued for this order.
	 *
	 * Queried by order id rather than read from `_wplm_license_ids` on the line items: the order
	 * object WooCommerce hands the email was loaded before fulfilment wrote that meta, so its items
	 * still report no licence and the customer would get an email with the block missing.
	 *
	 * @param \WC_Order $order The order.
	 * @return string[]
	 */
	private function keys_for( \WC_Order $order ): array {
		$found = $this->licenses->get_list(
			array(
				'order_id' => $order->get_id(),
				'per_page' => 50,
				'orderby'  => 'id',
				'order'    => 'ASC',
			)
		);

		$keys = array();

		foreach ( $found['items'] as $license ) {
			if ( '' !== $license->license_key ) {
				$keys[] = $license->license_key;
			}
		}

		return $keys;
	}
}
