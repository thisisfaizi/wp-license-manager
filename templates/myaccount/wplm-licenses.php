<?php
/**
 * My Account — Licenses table template.
 *
 * @var \WPLM\Models\License[] $licenses
 * @package WPLM
 */

defined( 'ABSPATH' ) || exit;
?>

<h2><?php esc_html_e( 'My Licenses', 'wp-license-manager' ); ?></h2>

<?php if ( empty( $licenses ) ) : ?>
	<p><?php esc_html_e( 'You have no license keys yet. Purchase a licensed product to get started.', 'wp-license-manager' ); ?></p>
<?php else : ?>
<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'License Key', 'wp-license-manager' ); ?></th>
			<th><?php esc_html_e( 'Status', 'wp-license-manager' ); ?></th>
			<th><?php esc_html_e( 'Activations', 'wp-license-manager' ); ?></th>
			<th><?php esc_html_e( 'Expires', 'wp-license-manager' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'wp-license-manager' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $licenses as $license ) : ?>
		<tr>
			<td>
				<code><?php
					$key = $license->license_key;
					echo esc_html( substr( $key, 0, 4 ) . '-****-****-' . substr( $key, -4 ) );
				?></code>
			</td>
			<td>
				<span class="wplm-badge wplm-status-<?php echo esc_attr( $license->status ); ?>">
					<?php echo esc_html( $license->status_label() ); ?>
				</span>
			</td>
			<td>
				<?php
				echo esc_html(
					$license->activation_count . ' / ' .
					( null === $license->max_activations ? '∞' : $license->max_activations )
				);
				?>
			</td>
			<td>
				<?php
				if ( $license->expires_at ) {
					echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $license->expires_at ) ) );
				} else {
					esc_html_e( 'Never', 'wp-license-manager' );
				}
				?>
			</td>
			<td>
				<?php if ( $license->is_active() ) : ?>
					<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'wplm-licenses' ) . '?view=' . $license->id ); ?>" class="button">
						<?php esc_html_e( 'View Devices', 'wp-license-manager' ); ?>
					</a>
				<?php endif; ?>
			</td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>
