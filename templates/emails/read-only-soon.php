<?php
/**
 * Email template: an entitlement licence's module will become read-only.
 *
 * Sent on the module's paid-through day. The body only; LapseNoticeService wraps it in
 * the WooCommerce email header and footer.
 *
 * Available variables:
 *   @var \WPLM\Models\License $license
 *   @var string               $product_name      The licence profile's label.
 *   @var bool                 $whole_product     True when the profile's base module lapses (everything goes read-only).
 *   @var string[]             $module_names      Names of the modules the notice is about.
 *   @var string               $last_working_day  Formatted date: the last day of grace.
 *   @var string               $read_only_on      Formatted date: the first read-only day.
 *   @var string               $account_url       My Account subscriptions page, or ''.
 *
 * @package WPLM
 */

defined( 'ABSPATH' ) || exit;
?>

<p>
	<?php
	if ( $whole_product ) {
		printf(
			/* translators: 1: product name, 2: licence id */
			esc_html__( 'The payment for your %1$s (licence #%2$d) is overdue.', 'wp-license-manager' ),
			esc_html( $product_name ),
			absint( $license->id )
		);
	} else {
		printf(
			/* translators: 1: module names, 2: product name, 3: licence id */
			esc_html__( 'The payment for %1$s in your %2$s (licence #%3$d) is overdue.', 'wp-license-manager' ),
			esc_html( wp_sprintf_l( '%l', $module_names ) ),
			esc_html( $product_name ),
			absint( $license->id )
		);
	}
	?>
</p>

<p>
	<?php
	printf(
		/* translators: 1: date */
		esc_html__( 'It keeps working normally until the end of %1$s.', 'wp-license-manager' ),
		'<strong>' . esc_html( $last_working_day ) . '</strong>'
	);
	?>
</p>

<p>
	<?php
	printf(
		/* translators: 1: date */
		esc_html__( 'From %1$s it becomes read-only: you can still view, print, export and back up your records, but you cannot add or change anything. Nothing is deleted.', 'wp-license-manager' ),
		'<strong>' . esc_html( $read_only_on ) . '</strong>'
	);
	?>
</p>

<p>
	<?php
	printf(
		/* translators: 1: product name */
		esc_html__( 'As soon as your payment is received, %1$s unlocks the next time your computer connects to the internet.', 'wp-license-manager' ),
		esc_html( $product_name )
	);
	?>
</p>

<?php if ( '' !== $account_url ) : ?>
<p style="margin-top:16px;">
	<a href="<?php echo esc_url( $account_url ); ?>" style="background:#2271b1;color:#fff;padding:10px 20px;text-decoration:none;border-radius:3px;">
		<?php esc_html_e( 'View your subscription', 'wp-license-manager' ); ?>
	</a>
</p>
<?php endif; ?>
