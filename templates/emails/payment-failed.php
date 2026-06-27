<?php
/**
 * Email template: Renewal Payment Failed.
 *
 * Sent when a renewal charge fails and dunning begins.
 *
 * Available variables:
 *   @var \WPLM\Models\Subscription $subscription
 *   @var \WPLM\Models\Renewal      $renewal
 *   @var int                       $attempts_remaining  Dunning retries left.
 *   @var string                    $retry_date          Human-readable date of next retry attempt.
 *   @var string                    $email_heading
 *   @var bool                      $sent_to_admin
 *   @var \WC_Email                 $email
 *
 * @package WPLM
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php esc_html_e( 'We were unable to process your renewal payment. Please update your payment method to keep your subscription active.', 'wp-license-manager' ); ?></p>

<table cellspacing="0" cellpadding="6" style="width:100%;border-collapse:collapse;">
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Subscription', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;">#<?php echo esc_html( $subscription->id ); ?></td>
	</tr>
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Amount Due', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;"><?php echo wp_kses_post( wc_price( $subscription->recurring_total, array( 'currency' => $subscription->currency ) ) ); ?></td>
	</tr>
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Status', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;"><?php echo esc_html( $subscription->status_label() ); ?></td>
	</tr>
	<?php if ( $attempts_remaining > 0 ) : ?>
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Next Retry', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;"><?php echo esc_html( $retry_date ); ?></td>
	</tr>
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Retries Remaining', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;"><?php echo esc_html( $attempts_remaining ); ?></td>
	</tr>
	<?php else : ?>
	<tr>
		<td colspan="2" style="border:1px solid #e5e5e5;padding:8px;color:#c00;">
			<?php esc_html_e( 'All retry attempts have been exhausted. Your subscription has been cancelled.', 'wp-license-manager' ); ?>
		</td>
	</tr>
	<?php endif; ?>
</table>

<p style="margin-top:16px;">
	<?php esc_html_e( 'To keep your subscription active, please update your payment method:', 'wp-license-manager' ); ?>
</p>

<p>
	<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'payment-methods' ) ); ?>" style="background:#c00;color:#fff;padding:10px 20px;text-decoration:none;border-radius:3px;">
		<?php esc_html_e( 'Update Payment Method', 'wp-license-manager' ); ?>
	</a>
	&nbsp;
	<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'wplm-subscriptions' ) ); ?>" style="background:#666;color:#fff;padding:10px 20px;text-decoration:none;border-radius:3px;">
		<?php esc_html_e( 'View Subscription', 'wp-license-manager' ); ?>
	</a>
</p>

<?php
do_action( 'woocommerce_email_footer', $email );
