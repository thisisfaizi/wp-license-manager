<?php
/**
 * My Account — one licence's computers, and for an entitlement licence its modules and the Move button.
 *
 * @var \WPLM\Models\License|null        $license     The licence, or null when it is not the customer's.
 * @var \WPLM\Licensing\Profile|null     $profile     Its licence profile (null for a classic licence).
 * @var array<string, array{label: string, until: string|null, state: string}> $modules
 * @var \WPLM\Models\Machine[]           $machines    Active computers.
 * @var int                              $moves_left  Self-service moves left in the next 30 days.
 * @var array|null                       $flash       The result of the last Move, if any.
 * @var string                           $move_action admin-post action of the Move button.
 * @var string                           $back_url    The licences list.
 * @package WPLM
 */

defined( 'ABSPATH' ) || exit;

$state_labels = array(
	'active'    => __( 'Active', 'wp-license-manager' ),
	'due_soon'  => __( 'Renews soon', 'wp-license-manager' ),
	'grace'     => __( 'Payment overdue', 'wp-license-manager' ),
	'read_only' => __( 'Read-only', 'wp-license-manager' ),
	'lifetime'  => __( 'Lifetime', 'wp-license-manager' ),
);
?>

<p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'My Licenses', 'wp-license-manager' ); ?></a></p>

<?php if ( null !== $flash ) : ?>
	<div class="<?php echo esc_attr( ! empty( $flash['ok'] ) ? 'woocommerce-message' : 'woocommerce-error' ); ?>" role="alert"><?php echo esc_html( (string) $flash['message'] ); ?></div>
<?php endif; ?>

<?php if ( null === $license ) : ?>
	<p><?php esc_html_e( 'That licence was not found in your account.', 'wp-license-manager' ); ?></p>
	<?php return; ?>
<?php endif; ?>

<h2>
	<?php
	/* translators: %s: last four characters of the licence key */
	echo esc_html( sprintf( __( 'Licence ending %s', 'wp-license-manager' ), substr( $license->license_key, -4 ) ) );
	?>
</h2>

<?php if ( null !== $profile ) : ?>
	<h3><?php esc_html_e( 'What you have paid for', 'wp-license-manager' ); ?></h3>
	<table class="shop_table shop_table_responsive">
		<thead><tr>
			<th><?php esc_html_e( 'Module', 'wp-license-manager' ); ?></th>
			<th><?php esc_html_e( 'Paid through', 'wp-license-manager' ); ?></th>
			<th><?php esc_html_e( 'State', 'wp-license-manager' ); ?></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $modules ) ) : ?>
			<tr><td colspan="3"><?php esc_html_e( 'No modules yet.', 'wp-license-manager' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $modules as $module ) : ?>
			<tr>
				<td><?php echo esc_html( $module['label'] ); ?></td>
				<td><?php echo esc_html( null === $module['until'] ? __( 'Lifetime', 'wp-license-manager' ) : date_i18n( get_option( 'date_format' ), strtotime( $module['until'] . ' 12:00:00' ) ) ); ?></td>
				<td><?php echo esc_html( $state_labels[ $module['state'] ] ?? $module['state'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<h3><?php esc_html_e( 'Computers using this licence', 'wp-license-manager' ); ?></h3>
<?php if ( empty( $machines ) ) : ?>
	<p><?php esc_html_e( 'No computer is using this licence. Activate the software with your licence key.', 'wp-license-manager' ); ?></p>
<?php else : ?>
	<table class="shop_table shop_table_responsive">
		<thead><tr>
			<th><?php esc_html_e( 'Computer', 'wp-license-manager' ); ?></th>
			<th><?php esc_html_e( 'Activated', 'wp-license-manager' ); ?></th>
			<?php if ( null !== $profile ) : ?>
				<th><?php esc_html_e( 'Move', 'wp-license-manager' ); ?></th>
			<?php endif; ?>
		</tr></thead>
		<tbody>
		<?php foreach ( $machines as $machine ) : ?>
			<tr>
				<td><?php echo esc_html( $machine->name ?: ( $machine->hostname ?: __( 'Computer', 'wp-license-manager' ) ) ); ?></td>
				<td><?php echo esc_html( $machine->activated_at ? date_i18n( get_option( 'date_format' ), strtotime( $machine->activated_at ) ) : '—' ); ?></td>
				<?php if ( null !== $profile ) : ?>
					<td>
						<?php if ( $moves_left > 0 ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this computer from your licence? The software on it will stop being licensed. Then activate it on the new computer with your licence key.', 'wp-license-manager' ) ); ?>');">
								<input type="hidden" name="action" value="<?php echo esc_attr( $move_action ); ?>">
								<input type="hidden" name="license_id" value="<?php echo esc_attr( (string) $license->id ); ?>">
								<input type="hidden" name="machine_id" value="<?php echo esc_attr( (string) $machine->id ); ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( $move_action . '_' . $machine->id ) ); ?>">
								<button type="submit" class="button"><?php esc_html_e( 'Move to another computer', 'wp-license-manager' ); ?></button>
							</form>
						<?php else : ?>
							<?php esc_html_e( 'No moves left this month. Contact support.', 'wp-license-manager' ); ?>
						<?php endif; ?>
					</td>
				<?php endif; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( null !== $profile ) : ?>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: moves left */
					_n( 'You can move this licence to another computer %d more time in the next 30 days.', 'You can move this licence to another computer %d more times in the next 30 days.', $moves_left, 'wp-license-manager' ),
					$moves_left
				)
			);
			?>
		</p>
	<?php endif; ?>
<?php endif; ?>
