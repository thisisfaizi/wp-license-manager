<?php
/**
 * Email template: Trial Ending Soon.
 *
 * Sent N days before the free trial ends.
 *
 * Available variables:
 *   @var \WPLM\Models\Subscription $subscription
 *   @var int                       $days_remaining  Days until trial ends.
 *   @var string                    $email_heading
 *   @var bool                      $sent_to_admin
 *   @var \WC_Email                 $email
 *
 * @package WPLM
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php
	printf(
		/* translators: %d number of days remaining in trial */
		esc_html( _n(
			'Your free trial ends in %d day. After that, your subscription will automatically renew at the standard rate.',
			'Your free trial ends in %d days. After that, your subscription will automatically renew at the standard rate.',
			$days_remaining,
			'wp-license-manager'
		) ),
		absint( $days_remaining )
	);
	?>
</p>

<table cellspacing="0" cellpadding="6" style="width:100%;border-collapse:collapse;">
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Subscription', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;">#<?php echo esc_html( $subscription->id ); ?></td>
	</tr>
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Trial Ends', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $subscription->trial_end ) ) ); ?></td>
	</tr>
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'First Charge', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;"><?php echo wp_kses_post( wc_price( $subscription->recurring_total, array( 'currency' => $subscription->currency ) ) ); ?></td>
	</tr>
</table>

<p style="margin-top:16px;">
	<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'wplm-subscriptions' ) ); ?>" style="background:#2271b1;color:#fff;padding:10px 20px;text-decoration:none;border-radius:3px;">
		<?php esc_html_e( 'Manage Subscription', 'wp-license-manager' ); ?>
	</a>
</p>

<?php
do_action( 'woocommerce_email_footer', $email );
