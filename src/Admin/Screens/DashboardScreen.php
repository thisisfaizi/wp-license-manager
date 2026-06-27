<?php
/**
 * Dashboard screen renderer for the WPLM admin area.
 *
 * @package WPLM\Admin\Screens
 */

namespace WPLM\Admin\Screens;

defined( 'ABSPATH' ) || exit;

use WPLM\Services\AnalyticsService;

/**
 * Renders the WPLM Dashboard page with four data widgets:
 *   - Seat utilisation
 *   - License status breakdown
 *   - Per-day validations (last 7 days)
 *   - Monthly recurring revenue + subscription counts
 */
class DashboardScreen {

	/** @var AnalyticsService */
	private AnalyticsService $analytics;

	/**
	 * @param AnalyticsService $analytics Analytics service instance.
	 */
	public function __construct( AnalyticsService $analytics ) {
		$this->analytics = $analytics;
	}

	// -------------------------------------------------------------------------
	// Page render
	// -------------------------------------------------------------------------

	/**
	 * Output the full dashboard page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-license-manager' ) );
		}
		?>
		<div class="wrap wplm-dashboard">
			<h1><?php esc_html_e( 'License Manager Dashboard', 'wp-license-manager' ); ?></h1>
			<div class="wplm-dashboard-widgets">
				<?php $this->render_seat_widget(); ?>
				<?php $this->render_status_widget(); ?>
				<?php $this->render_validation_widget(); ?>
				<?php $this->render_mrr_widget(); ?>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Widget renderers
	// -------------------------------------------------------------------------

	/**
	 * Render the seat utilisation card.
	 *
	 * Displays active seats, total seat capacity, and a utilisation percentage.
	 *
	 * @return void
	 */
	public function render_seat_widget(): void {
		$data   = $this->analytics->get_seat_utilization();
		$active = (int) ( $data['active_seats'] ?? 0 );
		$total  = (int) ( $data['total_seats'] ?? 0 );
		$pct    = (float) ( $data['utilization_pct'] ?? 0.0 );
		?>
		<div class="wplm-widget wplm-widget--seat-utilization">
			<h2 class="wplm-widget__title"><?php esc_html_e( 'Seat Utilization', 'wp-license-manager' ); ?></h2>
			<div class="wplm-widget__body">
				<p class="wplm-widget__stat">
					<strong><?php esc_html_e( 'Active seats:', 'wp-license-manager' ); ?></strong>
					<?php echo esc_html( number_format_i18n( $active ) ); ?>
				</p>
				<p class="wplm-widget__stat">
					<strong><?php esc_html_e( 'Total capacity:', 'wp-license-manager' ); ?></strong>
					<?php echo esc_html( number_format_i18n( $total ) ); ?>
				</p>
				<p class="wplm-widget__stat">
					<strong><?php esc_html_e( 'Utilization:', 'wp-license-manager' ); ?></strong>
					<?php
					// translators: %s is a percentage value such as 42.50.
					echo esc_html( sprintf( __( '%s%%', 'wp-license-manager' ), number_format_i18n( $pct, 2 ) ) );
					?>
				</p>
				<?php if ( $total > 0 ) : ?>
					<div class="wplm-progress-bar" role="progressbar" aria-valuenow="<?php echo esc_attr( $pct ); ?>" aria-valuemin="0" aria-valuemax="100">
						<div class="wplm-progress-bar__fill" style="width:<?php echo esc_attr( min( 100, $pct ) ); ?>%"></div>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the license status breakdown widget.
	 *
	 * Displays a labelled list of each status code and its count.
	 *
	 * @return void
	 */
	public function render_status_widget(): void {
		$breakdown = $this->analytics->get_status_breakdown();

		$labels = array(
			0 => __( 'Pending', 'wp-license-manager' ),
			1 => __( 'Active', 'wp-license-manager' ),
			2 => __( 'Inactive', 'wp-license-manager' ),
			3 => __( 'Expired', 'wp-license-manager' ),
			4 => __( 'Suspended', 'wp-license-manager' ),
			5 => __( 'Revoked', 'wp-license-manager' ),
			6 => __( 'Terminated', 'wp-license-manager' ),
		);
		?>
		<div class="wplm-widget wplm-widget--status-breakdown">
			<h2 class="wplm-widget__title"><?php esc_html_e( 'License Status Breakdown', 'wp-license-manager' ); ?></h2>
			<div class="wplm-widget__body">
				<?php if ( empty( $breakdown ) ) : ?>
					<p><?php esc_html_e( 'No licenses found.', 'wp-license-manager' ); ?></p>
				<?php else : ?>
					<ul class="wplm-status-list">
						<?php foreach ( $labels as $code => $label ) : ?>
							<?php $count = (int) ( $breakdown[ $code ] ?? 0 ); ?>
							<li class="wplm-status-list__item">
								<span class="wplm-badge wplm-status-<?php echo esc_attr( $code ); ?>">
									<?php echo esc_html( $label ); ?>
								</span>
								<span class="wplm-status-list__count">
									<?php echo esc_html( number_format_i18n( $count ) ); ?>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the per-day validation widget.
	 *
	 * Displays a table of date → event count for the last 7 days.
	 *
	 * @return void
	 */
	public function render_validation_widget(): void {
		$rows = $this->analytics->get_validations_per_day( 7 );
		?>
		<div class="wplm-widget wplm-widget--validations">
			<h2 class="wplm-widget__title"><?php esc_html_e( 'Validations (last 7 days)', 'wp-license-manager' ); ?></h2>
			<div class="wplm-widget__body">
				<?php if ( empty( $rows ) ) : ?>
					<p><?php esc_html_e( 'No validation events recorded in the last 7 days.', 'wp-license-manager' ); ?></p>
				<?php else : ?>
					<table class="widefat striped wplm-validation-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Date', 'wp-license-manager' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Validations', 'wp-license-manager' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['date'] ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $row['count'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the MRR and subscription counts widget.
	 *
	 * @return void
	 */
	public function render_mrr_widget(): void {
		$mrr    = $this->analytics->get_mrr();
		$counts = $this->analytics->get_subscription_counts();

		$active  = (int) ( $counts['active'] ?? 0 );
		$trial   = (int) ( $counts['trial'] ?? 0 );
		$on_hold = (int) ( $counts['on-hold'] ?? 0 );
		?>
		<div class="wplm-widget wplm-widget--mrr">
			<h2 class="wplm-widget__title"><?php esc_html_e( 'Revenue & Subscriptions', 'wp-license-manager' ); ?></h2>
			<div class="wplm-widget__body">
				<p class="wplm-widget__stat wplm-widget__stat--highlight">
					<strong><?php esc_html_e( 'MRR:', 'wp-license-manager' ); ?></strong>
					<?php
					$currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
					echo esc_html( $currency_symbol . number_format_i18n( $mrr, 2 ) );
					?>
				</p>
				<p class="wplm-widget__stat">
					<strong><?php esc_html_e( 'Active subscribers:', 'wp-license-manager' ); ?></strong>
					<?php echo esc_html( number_format_i18n( $active ) ); ?>
				</p>
				<p class="wplm-widget__stat">
					<strong><?php esc_html_e( 'Trial subscriptions:', 'wp-license-manager' ); ?></strong>
					<?php echo esc_html( number_format_i18n( $trial ) ); ?>
				</p>
				<p class="wplm-widget__stat">
					<strong><?php esc_html_e( 'On hold:', 'wp-license-manager' ); ?></strong>
					<?php echo esc_html( number_format_i18n( $on_hold ) ); ?>
				</p>
				<?php if ( ! empty( $counts ) ) : ?>
					<details class="wplm-widget__details">
						<summary><?php esc_html_e( 'All subscription statuses', 'wp-license-manager' ); ?></summary>
						<ul class="wplm-status-list">
							<?php foreach ( $counts as $status => $count ) : ?>
								<li class="wplm-status-list__item">
									<span><?php echo esc_html( ucfirst( (string) $status ) ); ?></span>
									<span class="wplm-status-list__count"><?php echo esc_html( number_format_i18n( (int) $count ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
