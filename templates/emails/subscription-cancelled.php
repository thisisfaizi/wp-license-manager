<?php
/**
 * Email template: Subscription Cancelled.
 *
 * Sent when a subscription is cancelled (by the customer or system).
 *
 * Available variables:
 *   @var \WPLM\Models\Subscription $subscription
 *   @var \WPLM\Models\License      $license
 *   @var bool                      $at_period_end   True if active until period end.
 *   @var string                    $email_heading
 *   @var bool                      $sent_to_admin
 *   @var \WC_Email                 $email
 *
 * @package WPLM
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<?php if ( ! empty( $at_period_end ) ) : ?>
<p><?php esc_html_e( 'Your subscription has been set to cancel at the end of the current billing period. You will continue to have access until then.', 'wp-license-manager' ); ?></p>
<?php else : ?>
<p><?php esc_html_e( 'Your subscription has been cancelled. Access to any associated software licenses has been revoked.', 'wp-license-manager' ); ?></p>
<?php endif; ?>

<table cellspacing="0" cellpadding="6" style="width:100%;border-collapse:collapse;">
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Subscription', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;">#<?php echo esc_html( $subscription->id ); ?></td>
	</tr>
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Status', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;"><?php echo esc_html( $subscription->status_label() ); ?></td>
	</tr>
	<?php if ( ! empty( $at_period_end ) && $subscription->end_date ) : ?>
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Access Ends', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $subscription->end_date ) ) ); ?></td>
	</tr>
	<?php endif; ?>
</table>

<p style="margin-top:16px;">
	<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'wplm-subscriptions' ) ); ?>" style="background:#2271b1;color:#fff;padding:10px 20px;text-decoration:none;border-radius:3px;">
		<?php esc_html_e( 'View Subscriptions', 'wp-license-manager' ); ?>
	</a>
</p>

<?php
do_action( 'woocommerce_email_footer', $email );
