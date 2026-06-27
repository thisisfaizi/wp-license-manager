<?php
/**
 * Email template: Subscription Created.
 *
 * Sent to the customer when a new WPLM subscription is created.
 *
 * Available variables:
 *   @var \WPLM\Models\Subscription $subscription
 *   @var \WPLM\Models\License      $license       (may be null until issued)
 *   @var \WC_Order                 $order         Parent order.
 *   @var string                    $email_heading
 *   @var bool                      $sent_to_admin
 *   @var \WC_Email                 $email
 *
 * @package WPLM
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php
	printf(
		/* translators: customer display name */
		esc_html__( 'Hi %s,', 'wp-license-manager' ),
		esc_html( $order->get_formatted_billing_full_name() )
	);
?></p>

<p><?php esc_html_e( 'Your subscription has been created. Here are the details:', 'wp-license-manager' ); ?></p>

<table cellspacing="0" cellpadding="6" style="width:100%;border-collapse:collapse;">
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Subscription ID', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;">#<?php echo esc_html( $subscription->id ); ?></td>
	</tr>
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Status', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;"><?php echo esc_html( $subscription->status_label() ); ?></td>
	</tr>
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Amount', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;">
			<?php echo wp_kses_post( wc_price( $subscription->recurring_total, array( 'currency' => $subscription->currency ) ) ); ?>
			<?php
			printf(
				/* translators: billing period e.g. "month" */
				esc_html__( '/ %s', 'wp-license-manager' ),
				esc_html( $subscription->billing_period )
			);
			?>
		</td>
	</tr>
	<?php if ( $subscription->trial_end ) : ?>
	<tr>
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'Trial Ends', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $subscription->trial_end ) ) ); ?></td>
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
		<th style="text-align:left;border:1px solid #e5e5e5;padding:8px;"><?php esc_html_e( 'License Key', 'wp-license-manager' ); ?></th>
		<td style="border:1px solid #e5e5e5;padding:8px;font-family:monospace;"><?php echo esc_html( $license->license_key ); ?></td>
	</tr>
	<?php endif; ?>
</table>

<p style="margin-top:16px;">
	<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'wplm-subscriptions' ) ); ?>" style="background:#2271b1;color:#fff;padding:10px 20px;text-decoration:none;border-radius:3px;">
		<?php esc_html_e( 'Manage Subscriptions', 'wp-license-manager' ); ?>
	</a>
</p>

<?php
do_action( 'woocommerce_email_footer', $email );
