<?php
/**
 * Email template: Renewal Payment Processed.
 *
 * Sent when a subscription renewal is successfully charged.
 *
 * Available variables:
 *   @var \WPLM\Models\Subscription $subscription
 *   @var \WPLM\Models\Renewal      $renewal
 *   @var \WPLM\Models\License      $license
 *   @var \WC_Order                 $renewal_order  WooCommerce renewal order (may be null).
 *   @var string                    $email_heading
 *   @var bool                      $sent_to_admin
 *   @var \WC_Email                 $email
 *
 * @package WPLM
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php esc_html_e( 'Your subscription has been renewed. Here is your renewal summary:', 'wp-license-manager' ); ?></p>

<table cellspacing="0" cellpadding="6" style="width:100%;border-collapse:collapse;">
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Subscription', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;">#<?php echo esc_html( $subscription->id ); ?></td>
	</tr>
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Amount Charged', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;"><?php echo wp_kses_post( wc_price( $renewal->amount, array( 'currency' => $subscription->currency ) ) ); ?></td>
	</tr>
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Date', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $renewal->processed_at ?? $renewal->created_at ) ) ); ?></td>
	</tr>
	<?php if ( ! empty( $renewal->gateway_txn ) ) : ?>
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Transaction ID', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;"><?php echo esc_html( $renewal->gateway_txn ); ?></td>
	</tr>
	<?php endif; ?>
	<?php if ( $subscription->next_payment ) : ?>
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Next Payment', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $subscription->next_payment ) ) ); ?></td>
	</tr>
	<?php endif; ?>
	<?php if ( $license ) : ?>
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'License Valid Until', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;">
			<?php
			if ( $license->expires_at ) {
				echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $license->expires_at ) ) );
			} else {
				esc_html_e( 'Never expires', 'wp-license-manager' );
			}
			?>
		</td>
	</tr>
	<?php endif; ?>
</table>

<?php if ( $renewal_order ) : ?>
<p style="margin-top:16px;">
	<a href="<?php echo esc_url( $renewal_order->get_view_order_url() ); ?>" style="background:#2271b1;color:#fff;padding:10px 20px;text-decoration:none;border-radius:3px;">
		<?php esc_html_e( 'View Invoice', 'wp-license-manager' ); ?>
	</a>
</p>
<?php endif; ?>

<?php
do_action( 'woocommerce_email_footer', $email );
