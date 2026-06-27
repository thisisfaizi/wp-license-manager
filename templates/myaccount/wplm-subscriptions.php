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

				<?php
				// Show Renew for any non-cancelled, non-on-hold subscription.
				// The customer can renew early (extends from current expiry), or
				// renew after expiry (extends from today).
				if ( ! in_array( $sub->status, array( 'cancelled', 'on-hold' ), true ) ) :
					$renew_price  = function_exists( 'wc_price' )
						? wc_price( $sub->recurring_total, array( 'currency' => $sub->currency ) )
						: number_format_i18n( (float) $sub->recurring_total, 2 );
					$renew_period = ( (int) $sub->billing_interval > 1 )
						? $sub->billing_interval . ' ' . $sub->billing_period
						: $sub->billing_period;
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<input type="hidden" name="action" value="wplm_sub_action">
						<input type="hidden" name="wplm_action" value="renew">
						<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $sub->id ); ?>">
						<?php wp_nonce_field( 'wplm_sub_action_' . $sub->id ); ?>
						<button type="submit" class="button">
							<?php
							printf(
								/* translators: 1: price with currency, 2: billing period label */
								esc_html__( 'Renew — %1$s / %2$s', 'wp-license-manager' ),
								wp_kses_post( $renew_price ),
								esc_html( $renew_period )
							);
							?>
						</button>
					</form>
				<?php endif; ?>
			</td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>
