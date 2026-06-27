<?php
/**
 * Email template: Subscription Expired.
 *
 * Sent when a subscription reaches its fixed end date.
 *
 * Available variables:
 *   @var \WPLM\Models\Subscription $subscription
 *   @var \WPLM\Models\License      $license
 *   @var string                    $email_heading
 *   @var bool                      $sent_to_admin
 *   @var \WC_Email                 $email
 *
 * @package WPLM
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php esc_html_e( 'Your subscription has expired. To continue using the software, please renew or start a new subscription.', 'wp-license-manager' ); ?></p>

<table cellspacing="0" cellpadding="6" style="width:100%;border-collapse:collapse;">
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Subscription', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;">#<?php echo esc_html( $subscription->id ); ?></td>
	</tr>
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Expired On', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $subscription->end_date ?? current_time( 'mysql' ) ) ) ); ?></td>
	</tr>
</table>

<p style="margin-top:16px;">
	<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" style="background:#2271b1;color:#fff;padding:10px 20px;text-decoration:none;border-radius:3px;">
		<?php esc_html_e( 'Renew Subscription', 'wp-license-manager' ); ?>
	</a>
</p>

<?php
do_action( 'woocommerce_email_footer', $email );
