<?php
/**
 * My Account — Subscriptions table template.
 *
 * @var \WPLM\Models\Subscription[] $subscriptions
 * @package WPLM
 */

defined( 'ABSPATH' ) || exit;
?>

<h2><?php esc_html_e( 'My Subscriptions', 'wp-license-manager' ); ?></h2>

<?php if ( empty( $subscriptions ) ) : ?>
	<p><?php esc_html_e( 'You have no active subscriptions.', 'wp-license-manager' ); ?></p>
<?php else : ?>
<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
	<thead>
		<tr>
			<th><?php esc_html_e( '#', 'wp-license-manager' ); ?></th>
			<th><?php esc_html_e( 'Status', 'wp-license-manager' ); ?></th>
			<th><?php esc_html_e( 'Amount', 'wp-license-manager' ); ?></th>
			<th><?php esc_html_e( 'Next Payment', 'wp-license-manager' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'wp-license-manager' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $subscriptions as $sub ) : ?>
		<tr>
			<td><?php echo esc_html( '#' . $sub->id ); ?></td>
			<td>
				<span class="wplm-badge">
					<?php echo esc_html( $sub->status_label() ); ?>
				</span>
			</td>
			<td>
				<?php echo esc_html( wc_price( $sub->recurring_total, array( 'currency' => $sub->currency ) ) . ' / ' . $sub->billing_period ); ?>
			</td>
			<td>
				<?php
				if ( $sub->next_payment ) {
					echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $sub->next_payment ) ) );
				} else {
					echo '—';
				}
				?>
			</td>
			<td class="wplm-sub-actions">
				<?php if ( $sub->is_active() ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<input type="hidden" name="action" value="wplm_sub_action">
						<input type="hidden" name="wplm_action" value="pause">
						<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $sub->id ); ?>">
						<?php wp_nonce_field( 'wplm_sub_action_' . $sub->id ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Pause', 'wp-license-manager' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<input type="hidden" name="action" value="wplm_sub_action">
						<input type="hidden" name="wplm_action" value="cancel">
						<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $sub->id ); ?>">
						<input type="hidden" name="at_period_end" value="1">
						<?php wp_nonce_field( 'wplm_sub_action_' . $sub->id ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Cancel', 'wp-license-manager' ); ?></button>
					</form>
				<?php elseif ( 'on-hold' === $sub->status ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<input type="hidden" name="action" value="wplm_sub_action">
						<input type="hidden" name="wplm_action" value="resume">
						<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $sub->id ); ?>">
						<?php wp_nonce_field( 'wplm_sub_action_' . $sub->id ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Resume', 'wp-license-manager' ); ?></button>
					</form>
				<?php endif; ?>
			</td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>
