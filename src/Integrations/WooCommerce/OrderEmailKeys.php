<?php
/**
 * The licence keys, inside WooCommerce's own completed-order email.
 *
 * @package WPLM\Integrations\WooCommerce
 */

namespace WPLM\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WPLM\Licensing\ProfileRegistry;
use WPLM\Repositories\EntitlementRepository;
use WPLM\Repositories\LicenseRepository;
use WPLM\Services\AddonService;
use WPLM\Support\Logger;
use WPLM\Support\SetupPdf;

/**
 * Prints a licence block under the order table of the customer's completed-order email.
 *
 * This is the email a customer actually opens, so it is where the key belongs — a separate
 * plain-text message is easy to miss, easy to lose, and easy to mistake for spam.
 *
 * It renders only for the customer's *completed* order email: the processing email is sent before a
 * licence exists, and the admin copies have no business carrying keys.
 *
 * An order that bought add-ons for an existing licence issues no key: its email says what was added
 * to which licence ("Added to your licence ending ABCD: …") and attaches nothing.
 */
class OrderEmailKeys {

	/** @var LicenseRepository */
	private LicenseRepository $licenses;

	/** @var EntitlementRepository|null */
	private ?EntitlementRepository $lines;

	/** @var ProfileRegistry|null */
	private ?ProfileRegistry $profiles;

	/**
	 * @param LicenseRepository          $licenses Reads the licences issued for an order.
	 * @param EntitlementRepository|null $lines    Reads the add-on lines an order added.
	 * @param ProfileRegistry|null       $profiles Names what those lines grant.
	 */
	public function __construct( LicenseRepository $licenses, ?EntitlementRepository $lines = null, ?ProfileRegistry $profiles = null ) {
		$this->licenses = $licenses;
		$this->lines    = $lines;
		$this->profiles = $profiles;
	}

	/** Register the email hooks. */
	public function register(): void {
		add_filter( 'woocommerce_email_attachments', array( $this, 'attach_setup_sheet' ), 10, 4 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render' ), 10, 4 );
	}

	/**
	 * Attach the setup sheet to the customer's completed-order email.
	 *
	 * The key, the office address and the connector command travel as a PDF rather than as text in
	 * the body. A long random token next to an `.exe` reads to a mail filter like malware — the one
	 * message that carried it in the body was the one message that never arrived — and a PDF is
	 * also the thing a customer keeps rather than loses in a thread.
	 *
	 * @param array  $attachments Paths already attached.
	 * @param string $email_id    Which email is being sent.
	 * @param mixed  $order       The order.
	 * @param mixed  $email       The WC_Email being sent.
	 * @return array
	 */
	public function attach_setup_sheet( $attachments, $email_id = '', $order = null, $email = null ) {
		$attachments = is_array( $attachments ) ? $attachments : array();

		if ( 'customer_completed_order' !== $email_id || ! $order instanceof \WC_Order ) {
			return $attachments;
		}

		$keys = $this->keys_for( $order );
		if ( empty( $keys ) ) {
			return $attachments;
		}

		$path = $this->write_setup_sheet( $order, $keys );

		if ( '' !== $path ) {
			$attachments[] = $path;
		}

		return $attachments;
	}

	/**
	 * Write the setup sheet to a temporary file and return its path.
	 *
	 * It is written to the system temp directory, never below `uploads/`, because it holds a
	 * licence key and a live tunnel credential and nothing that sensitive belongs anywhere a web
	 * server will serve. The file is deleted once the request that mailed it ends.
	 *
	 * @param \WC_Order $order The order.
	 * @param string[]  $keys  Its licence keys.
	 * @return string Path, or '' when the file could not be written.
	 */
	private function write_setup_sheet( \WC_Order $order, array $keys ): string {
		$pdf = new SetupPdf();
		$pdf->heading( __( 'Your Super Ledger setup', 'wp-license-manager' ) );
		$pdf->text(
			sprintf(
				/* translators: %s: order number */
				__( 'Order %s. Keep this sheet — it is everything needed to set up.', 'wp-license-manager' ),
				$order->get_order_number()
			)
		);
		$pdf->space();

		$pdf->label( _n( 'Licence key', 'Licence keys', count( $keys ), 'wp-license-manager' ) );
		foreach ( $keys as $key ) {
			$pdf->value( $key );
		}

		/**
		 * Extra sections for the setup sheet, as label => value pairs.
		 *
		 * @param array<string, string> $sections Sections to add.
		 * @param \WC_Order             $order    The order being emailed.
		 */
		$sections = (array) apply_filters( 'wplm_setup_sheet_sections', array(), $order );

		foreach ( $sections as $label => $value ) {
			$pdf->label( (string) $label );
			$pdf->value( (string) $value );
		}

		$dir  = get_temp_dir();
		$name = 'super-ledger-setup-' . $order->get_order_number() . '.pdf';
		$path = trailingslashit( $dir ) . wp_unique_filename( $dir, $name );

		if ( false === file_put_contents( $path, $pdf->render() ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			\WPLM\Support\Logger::error( 'WPLM: the setup sheet for order ' . $order->get_id() . ' could not be written to ' . $dir );
			return '';
		}

		// The mail has been handed to the transport by the time the request ends, so the sheet has
		// served its purpose and should not linger on disk with a key in it.
		add_action(
			'shutdown',
			static function () use ( $path ): void {
				if ( file_exists( $path ) ) {
					wp_delete_file( $path );
				}
			}
		);

		return $path;
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
			$this->render_addons( $order, (bool) $plain_text );
			return;
		}

		// The key, the address and the connector command are in the attached PDF, never in the body
		// — see attach_setup_sheet(). All this says is that the attachment is there and matters.
		$heading = __( 'Your Super Ledger licence', 'wp-license-manager' );
		$body    = __( 'Your licence key and setup details are in the PDF attached to this email. Keep it safe — it is everything needed to set up.', 'wp-license-manager' );

		if ( $plain_text ) {
			echo "\n\n" . esc_html( strtoupper( $heading ) ) . "\n" . esc_html( $body ) . "\n\n";
		} else {
			echo '<div style="margin:24px 0;padding:16px;border:1px solid #e0e0e0;border-radius:4px;">';
			echo '<h2 style="margin:0 0 12px;font-size:16px;">' . esc_html( $heading ) . '</h2>';
			echo '<p style="margin:0;">' . esc_html( $body ) . '</p>';
			echo '</div>';
		}

		// One order can issue a new licence and add to an existing one.
		$this->render_addons( $order, (bool) $plain_text );
	}

	/**
	 * Say what an add-on order added, per licence. Read from the lines by order id: the order object
	 * WooCommerce hands the email was loaded before fulfilment, so its items do not yet show the lines.
	 *
	 * @param \WC_Order $order      The order.
	 * @param bool      $plain_text Whether the email is plain text.
	 * @return void
	 */
	private function render_addons( \WC_Order $order, bool $plain_text ): void {
		if ( null === $this->lines || null === $this->profiles ) {
			return;
		}
		$by_licence = array();
		foreach ( $this->lines->get_by_order( $order->get_id() ) as $line ) {
			if ( 'addon' === $line->source ) {
				$by_licence[ $line->license_id ][] = $line;
			}
		}
		$sentences = array();
		foreach ( $by_licence as $license_id => $lines ) {
			$license = $this->licenses->find_by_id( (int) $license_id );
			$profile = null !== $license ? $this->profiles->get( $license->profile ) : null;
			if ( null === $profile ) {
				continue;
			}
			$sentences[] = sprintf(
				/* translators: 1: "licence ending ABCD", 2: what was added */
				__( 'Added to your %1$s: %2$s.', 'wp-license-manager' ),
				AddonService::licence_label( $license ),
				AddonService::describe_lines( $lines, $profile )
			);
		}
		if ( array() === $sentences ) {
			return;
		}
		$heading = __( 'Added to your licence', 'wp-license-manager' );
		$after   = __( 'Your licence key stays the same. Each computer picks up the change the next time it checks in.', 'wp-license-manager' );

		if ( $plain_text ) {
			echo "\n\n" . esc_html( strtoupper( $heading ) ) . "\n" . esc_html( implode( "\n", $sentences ) ) . "\n" . esc_html( $after ) . "\n\n";
			return;
		}
		echo '<div style="margin:24px 0;padding:16px;border:1px solid #e0e0e0;border-radius:4px;">';
		echo '<h2 style="margin:0 0 12px;font-size:16px;">' . esc_html( $heading ) . '</h2>';
		foreach ( $sentences as $sentence ) {
			echo '<p style="margin:0 0 8px;">' . esc_html( $sentence ) . '</p>';
		}
		echo '<p style="margin:0;">' . esc_html( $after ) . '</p>';
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
