<?php
/**
 * WP_List_Table implementation for the Subscriptions admin screen.
 *
 * @package WPLM\Admin\Screens
 */

namespace WPLM\Admin\Screens;

defined( 'ABSPATH' ) || exit;

use WPLM\Repositories\RenewalRepository;
use WPLM\Repositories\SubscriptionRepository;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Paginated, filterable list of WPLM subscriptions with an admin edit screen.
 */
class SubscriptionListTable extends \WP_List_Table {

	/** @var SubscriptionRepository */
	private SubscriptionRepository $repo;

	/** @var RenewalRepository */
	private RenewalRepository $renewal_repo;

	/**
	 * Translated subscription-status labels keyed by status slug.
	 *
	 * @return array<string,string>
	 */
	private function status_labels(): array {
		return array(
			'pending'        => __( 'Pending', 'wp-license-manager' ),
			'trial'          => __( 'Trial', 'wp-license-manager' ),
			'active'         => __( 'Active', 'wp-license-manager' ),
			'on-hold'        => __( 'On Hold', 'wp-license-manager' ),
			'pending-cancel' => __( 'Pending Cancel', 'wp-license-manager' ),
			'cancelled'      => __( 'Cancelled', 'wp-license-manager' ),
			'expired'        => __( 'Expired', 'wp-license-manager' ),
			'suspended'      => __( 'Suspended', 'wp-license-manager' ),
		);
	}

	/**
	 * @param SubscriptionRepository $repo
	 * @param RenewalRepository      $renewal_repo
	 */
	public function __construct( SubscriptionRepository $repo, RenewalRepository $renewal_repo ) {
		$this->repo         = $repo;
		$this->renewal_repo = $renewal_repo;
		parent::__construct(
			array(
				'singular' => 'subscription',
				'plural'   => 'subscriptions',
				'ajax'     => false,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Column definitions
	// -------------------------------------------------------------------------

	public function get_columns(): array {
		return array(
			'cb'              => '<input type="checkbox">',
			'id'              => __( 'ID', 'wp-license-manager' ),
			'user_id'         => __( 'Customer', 'wp-license-manager' ),
			'status'          => __( 'Status', 'wp-license-manager' ),
			'recurring_total' => __( 'Amount', 'wp-license-manager' ),
			'billing_period'  => __( 'Period', 'wp-license-manager' ),
			'next_payment'    => __( 'Next Payment', 'wp-license-manager' ),
			'created_at'      => __( 'Created', 'wp-license-manager' ),
		);
	}

	public function get_sortable_columns(): array {
		return array(
			'id'           => array( 'id', true ),
			'status'       => array( 'status', false ),
			'next_payment' => array( 'next_payment', false ),
			'created_at'   => array( 'created_at', false ),
		);
	}

	public function get_bulk_actions(): array {
		return array(
			'bulk-cancel' => __( 'Cancel', 'wp-license-manager' ),
		);
	}

	// -------------------------------------------------------------------------
	// Cell renderers
	// -------------------------------------------------------------------------

	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'user_id':
				$uid = (int) ( $item->user_id ?? 0 );
				if ( ! $uid ) {
					return '&mdash;';
				}
				$user = get_userdata( $uid );
				if ( ! $user ) {
					return esc_html( '#' . $uid );
				}
				return sprintf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'user-edit.php?user_id=' . $uid ) ),
					esc_html( $user->display_name ?: $user->user_login )
				);

			case 'status':
				$s      = (string) ( $item->status ?? 'pending' );
				$labels = $this->status_labels();
				$label  = $labels[ $s ] ?? ucfirst( $s );
				return sprintf(
					'<span class="wplm-badge wplm-sub-%s">%s</span>',
					esc_attr( $s ),
					esc_html( $label )
				);

			case 'recurring_total':
				return function_exists( 'wc_price' )
					? wp_kses_post( wc_price( $item->recurring_total ?? 0, array( 'currency' => $item->currency ?? '' ) ) )
					: esc_html( number_format( (float) ( $item->recurring_total ?? 0 ), 2 ) );

			case 'billing_period':
				$interval = (int) ( $item->billing_interval ?? 1 );
				$period   = (string) ( $item->billing_period ?? '' );
				if ( 1 === $interval ) {
					return esc_html( ucfirst( $period ) );
				}
				/* translators: 1: interval number, 2: billing period */
				return esc_html( sprintf( __( 'Every %1$d %2$ss', 'wp-license-manager' ), $interval, $period ) );

			case 'next_payment':
				if ( empty( $item->next_payment ) ) {
					return '&mdash;';
				}
				return esc_html( wp_date( get_option( 'date_format' ), strtotime( $item->next_payment ) ) );

			case 'created_at':
				if ( empty( $item->created_at ) ) {
					return '&mdash;';
				}
				return esc_html( wp_date( get_option( 'date_format' ), strtotime( $item->created_at ) ) );
		}
		return '&mdash;';
	}

	public function column_id( $item ): string {
		$id         = (int) $item->id;
		$edit_url   = admin_url( 'admin.php?page=wplm-subscriptions&action=edit&id=' . $id );
		$cancel_url = wp_nonce_url(
			admin_url( 'admin.php?page=wplm-subscriptions&action=cancel&id=' . $id ),
			'wplm_cancel_subscription_' . $id
		);

		$actions = array(
			'edit'   => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'View / Edit', 'wp-license-manager' )
			),
			'cancel' => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $cancel_url ),
				esc_js( __( 'Cancel this subscription?', 'wp-license-manager' ) ),
				esc_html__( 'Cancel', 'wp-license-manager' )
			),
		);

		return '<strong><a href="' . esc_url( $edit_url ) . '">#' . esc_html( (string) $id ) . '</a></strong>'
			. $this->row_actions( $actions );
	}

	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="subscription[]" value="%d">', (int) $item->id );
	}

	// -------------------------------------------------------------------------
	// Data loading
	// -------------------------------------------------------------------------

	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'wplm_subscriptions_per_page', 20 );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged   = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$orderby = sanitize_key( $_GET['orderby'] ?? 'id' );
		$order   = strtoupper( sanitize_key( $_GET['order'] ?? 'DESC' ) );
		$status  = sanitize_text_field( wp_unslash( $_GET['wplm_sub_status'] ?? '' ) );
		// phpcs:enable

		$args = array(
			'per_page' => $per_page,
			'page'     => $paged,
			'orderby'  => $orderby,
			'order'    => $order,
		);
		if ( '' !== $status ) {
			$args['status'] = $status;
		}

		$result      = $this->repo->get_list( $args );
		$this->items = $result['items'];

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Table nav — status filter
	// -------------------------------------------------------------------------

	public function display_tablenav( $which ): void {
		parent::display_tablenav( $which );
		if ( 'top' !== $which ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = sanitize_key( $_GET['wplm_sub_status'] ?? '' );
		?>
		<div class="wplm-status-filter alignleft actions">
			<label for="wplm-sub-filter-status" class="screen-reader-text">
				<?php esc_html_e( 'Filter by status', 'wp-license-manager' ); ?>
			</label>
			<select id="wplm-sub-filter-status" name="wplm_sub_status">
				<option value=""><?php esc_html_e( 'All statuses', 'wp-license-manager' ); ?></option>
				<?php foreach ( $this->status_labels() as $s => $label ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $current, $s ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'wp-license-manager' ), 'button', 'filter_action', false ); ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Page-level render
	// -------------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-license-manager' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );
		$id     = absint( $_GET['id'] ?? 0 );
		// phpcs:enable

		// Edit screen.
		if ( 'edit' === $action && $id > 0 ) {
			$this->render_edit_form( $id );
			return;
		}

		// Handle cancel row action.
		if ( 'cancel' === $action && $id > 0 ) {
			check_admin_referer( 'wplm_cancel_subscription_' . $id );
			$this->repo->update_status( $id, 'cancelled' );
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-subscriptions&cancelled=1' ) );
			exit;
		}

		// Handle bulk cancel.
		if ( 'bulk-cancel' === $this->current_action() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
			$ids = array_map( 'absint', (array) ( $_REQUEST['subscription'] ?? array() ) );
			foreach ( $ids as $sid ) {
				if ( $sid > 0 ) {
					$this->repo->update_status( $sid, 'cancelled' );
				}
			}
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-subscriptions&bulk_cancelled=' . count( $ids ) ) );
			exit;
		}

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Subscriptions', 'wp-license-manager' ); ?></h1>
			<hr class="wp-header-end">
			<?php
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $_GET['cancelled'] ) ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Subscription cancelled.', 'wp-license-manager' ); ?></p></div>
				<?php
			endif;
			if ( ! empty( $_GET['saved'] ) ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Subscription updated.', 'wp-license-manager' ); ?></p></div>
				<?php
			endif;
			// phpcs:enable
			?>
			<form method="get">
				<input type="hidden" name="page" value="wplm-subscriptions">
				<?php
				$this->prepare_items();
				$this->display();
				?>
			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Edit / view form
	// -------------------------------------------------------------------------

	/** Render the subscription detail / edit form for a single subscription. */
	private function render_edit_form( int $id ): void {
		$sub = $this->repo->find_by_id( $id );

		if ( ! $sub ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>';
			esc_html_e( 'Subscription not found.', 'wp-license-manager' );
			echo '</p></div></div>';
			return;
		}

		$user     = $sub->user_id ? get_userdata( (int) $sub->user_id ) : null;
		$renewals = $this->renewal_repo->get_by_subscription( $id );

		$sub_labels   = $this->status_labels();
		$status_label = $sub_labels[ $sub->status ] ?? ucfirst( (string) $sub->status );
		?>
		<div class="wrap">
			<h1>
				<?php
				/* translators: %d: subscription ID */
				echo esc_html( sprintf( __( 'Subscription #%d', 'wp-license-manager' ), $id ) );
				?>
				<span class="wplm-badge wplm-sub-<?php echo esc_attr( (string) $sub->status ); ?>">
					<?php echo esc_html( $status_label ); ?>
				</span>
			</h1>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wplm-subscriptions' ) ); ?>">
					&larr; <?php esc_html_e( 'Back to Subscriptions', 'wp-license-manager' ); ?>
				</a>
			</p>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $_GET['saved'] ) ) :
				?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Subscription updated.', 'wp-license-manager' ); ?></p></div>
			<?php endif; ?>

			<div style="display:flex;gap:24px;align-items:flex-start;">
				<!-- Left column: details + edit form -->
				<div style="flex:1;max-width:640px;">
					<table class="form-table" style="background:#fff;border:1px solid #c3c4c7;padding:12px;">
						<tbody>
							<tr>
								<th><?php esc_html_e( 'Customer', 'wp-license-manager' ); ?></th>
								<td>
									<?php if ( $user ) : ?>
										<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . (int) $sub->user_id ) ); ?>">
											<?php echo esc_html( $user->display_name ?: $user->user_login ); ?>
										</a>
										(<?php echo esc_html( $user->user_email ); ?>)
									<?php else : ?>
										#<?php echo esc_html( (string) $sub->user_id ); ?>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Billing', 'wp-license-manager' ); ?></th>
								<td>
									<?php
									$amount   = function_exists( 'wc_price' )
										? wp_kses_post( wc_price( $sub->recurring_total, array( 'currency' => $sub->currency ) ) )
										: esc_html( number_format( (float) $sub->recurring_total, 2 ) . ' ' . $sub->currency );
									$interval = (int) $sub->billing_interval;
									$period   = (string) $sub->billing_period;
									$cycle    = 1 === $interval
										/* translators: %s: billing period */
										? sprintf( __( 'every %s', 'wp-license-manager' ), $period )
										/* translators: 1: interval, 2: period */
										: sprintf( __( 'every %1$d %2$ss', 'wp-license-manager' ), $interval, $period );
									echo $amount . ' ' . esc_html( $cycle ); // phpcs:ignore
									?>
								</td>
							</tr>
							<?php if ( $sub->signup_fee ) : ?>
							<tr>
								<th><?php esc_html_e( 'Sign-up Fee', 'wp-license-manager' ); ?></th>
								<td>
									<?php
									echo function_exists( 'wc_price' )
										? wp_kses_post( wc_price( $sub->signup_fee, array( 'currency' => $sub->currency ) ) )
										: esc_html( number_format( (float) $sub->signup_fee, 2 ) );
									?>
								</td>
							</tr>
							<?php endif; ?>
							<tr>
								<th><?php esc_html_e( 'Next Payment', 'wp-license-manager' ); ?></th>
								<td><?php echo $sub->next_payment ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $sub->next_payment ) ) ) : '&mdash;'; ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Trial End', 'wp-license-manager' ); ?></th>
								<td><?php echo $sub->trial_end ? esc_html( wp_date( get_option( 'date_format' ), strtotime( $sub->trial_end ) ) ) : '&mdash;'; ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'End Date', 'wp-license-manager' ); ?></th>
								<td><?php echo $sub->end_date ? esc_html( wp_date( get_option( 'date_format' ), strtotime( $sub->end_date ) ) ) : esc_html__( 'Until cancelled', 'wp-license-manager' ); ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Bound License', 'wp-license-manager' ); ?></th>
								<td>
									<?php if ( $sub->license_id ) : ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=wplm-licenses&action=edit&id=' . (int) $sub->license_id ) ); ?>">
											<?php
											/* translators: %d: license ID */
											echo esc_html( sprintf( __( 'License #%d', 'wp-license-manager' ), (int) $sub->license_id ) );
											?>
										</a>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Payment Method', 'wp-license-manager' ); ?></th>
								<td><?php echo $sub->payment_method ? esc_html( $sub->payment_method ) : '&mdash;'; ?></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Parent Order', 'wp-license-manager' ); ?></th>
								<td>
									<?php if ( $sub->parent_order_id ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( (int) $sub->parent_order_id ) ?: admin_url( 'post.php?post=' . (int) $sub->parent_order_id . '&action=edit' ) ); ?>">
											#<?php echo esc_html( (string) $sub->parent_order_id ); ?>
										</a>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Created', 'wp-license-manager' ); ?></th>
								<td><?php echo $sub->created_at ? esc_html( wp_date( get_option( 'date_format' ), strtotime( $sub->created_at ) ) ) : '&mdash;'; ?></td>
							</tr>
						</tbody>
					</table>

					<!-- Status / amount edit form -->
					<h2 style="margin-top:24px;"><?php esc_html_e( 'Update Subscription', 'wp-license-manager' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'wplm_update_subscription_' . $id, 'wplm_sub_nonce' ); ?>
						<input type="hidden" name="action" value="wplm_update_subscription">
						<input type="hidden" name="subscription_id" value="<?php echo esc_attr( (string) $id ); ?>">

						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th scope="row"><label for="wplm_sub_new_status"><?php esc_html_e( 'Status', 'wp-license-manager' ); ?></label></th>
									<td>
										<select id="wplm_sub_new_status" name="sub_status">
											<?php foreach ( $this->status_labels() as $s => $label ) : ?>
												<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $sub->status, $s ); ?>>
													<?php echo esc_html( $label ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wplm_sub_recurring_total"><?php esc_html_e( 'Recurring Amount', 'wp-license-manager' ); ?></label></th>
									<td>
										<input
											type="text"
											id="wplm_sub_recurring_total"
											name="sub_recurring_total"
											value="<?php echo esc_attr( number_format( (float) $sub->recurring_total, 4, '.', '' ) ); ?>"
											class="small-text"
										>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wplm_sub_next_payment"><?php esc_html_e( 'Next Payment Date', 'wp-license-manager' ); ?></label></th>
									<td>
										<input
											type="datetime-local"
											id="wplm_sub_next_payment"
											name="sub_next_payment"
											value="<?php echo $sub->next_payment ? esc_attr( str_replace( ' ', 'T', substr( $sub->next_payment, 0, 16 ) ) ) : ''; ?>"
										>
										<p class="description"><?php esc_html_e( 'Leave empty to clear. Stored as UTC.', 'wp-license-manager' ); ?></p>
									</td>
								</tr>
							</tbody>
						</table>

						<?php submit_button( __( 'Update Subscription', 'wp-license-manager' ), 'primary', 'submit', false ); ?>
					</form>
				</div>

				<!-- Right column: quick action buttons -->
				<div style="flex:0 0 220px;">
					<div style="background:#fff;border:1px solid #c3c4c7;padding:16px;">
						<h3 style="margin-top:0;"><?php esc_html_e( 'Actions', 'wp-license-manager' ); ?></h3>

						<?php
						$actions_html = array();
						$can_pause    = in_array( $sub->status, array( 'active', 'trial' ), true );
						$can_resume   = 'on-hold' === $sub->status;
						$can_cancel   = ! in_array( $sub->status, array( 'cancelled', 'expired' ), true );

						if ( $can_pause ) :
							$pause_url      = wp_nonce_url(
								admin_url( 'admin.php?page=wplm-subscriptions&action=pause&id=' . $id ),
								'wplm_pause_subscription_' . $id
							);
							$actions_html[] = sprintf(
								'<a href="%s" class="button" style="width:100%%;margin-bottom:8px;">%s</a>',
								esc_url( $pause_url ),
								esc_html__( 'Pause (On Hold)', 'wp-license-manager' )
							);
						endif;

						if ( $can_resume ) :
							$resume_url     = wp_nonce_url(
								admin_url( 'admin.php?page=wplm-subscriptions&action=resume&id=' . $id ),
								'wplm_resume_subscription_' . $id
							);
							$actions_html[] = sprintf(
								'<a href="%s" class="button button-primary" style="width:100%%;margin-bottom:8px;">%s</a>',
								esc_url( $resume_url ),
								esc_html__( 'Resume', 'wp-license-manager' )
							);
						endif;

						if ( $can_cancel ) :
							$cancel_url     = wp_nonce_url(
								admin_url( 'admin.php?page=wplm-subscriptions&action=cancel_from_edit&id=' . $id ),
								'wplm_cancel_subscription_' . $id
							);
							$actions_html[] = sprintf(
								'<a href="%s" class="button submitdelete" style="width:100%%;margin-bottom:8px;" onclick="return confirm(\'%s\');">%s</a>',
								esc_url( $cancel_url ),
								esc_js( __( 'Cancel this subscription? The bound license will be revoked.', 'wp-license-manager' ) ),
								esc_html__( 'Cancel Now', 'wp-license-manager' )
							);
						endif;

						echo implode( '', $actions_html ); // phpcs:ignore
						?>
					</div>
				</div>
			</div>

			<!-- Renewal history -->
			<h2 style="margin-top:32px;"><?php esc_html_e( 'Renewal History', 'wp-license-manager' ); ?></h2>
			<?php $this->render_renewals_table( $renewals ); ?>
		</div>
		<?php
	}

	/**
	 * Render the renewal history table.
	 *
	 * @param \WPLM\Models\Renewal[] $renewals
	 */
	private function render_renewals_table( array $renewals ): void {
		if ( empty( $renewals ) ) {
			echo '<p>' . esc_html__( 'No renewals yet.', 'wp-license-manager' ) . '</p>';
			return;
		}

		$status_classes = array(
			'success' => 'wplm-status-1',
			'failed'  => 'wplm-status-5',
			'pending' => 'wplm-status-0',
		);
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'ID', 'wp-license-manager' ); ?></th>
					<th><?php esc_html_e( 'Type', 'wp-license-manager' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'wp-license-manager' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wp-license-manager' ); ?></th>
					<th><?php esc_html_e( 'Order', 'wp-license-manager' ); ?></th>
					<th><?php esc_html_e( 'Scheduled', 'wp-license-manager' ); ?></th>
					<th><?php esc_html_e( 'Processed', 'wp-license-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $renewals as $r ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $r->id ); ?></td>
						<td><?php echo esc_html( ucfirst( (string) $r->type ) ); ?></td>
						<td>
							<?php
							echo function_exists( 'wc_price' )
								? wp_kses_post( wc_price( $r->amount ) )
								: esc_html( number_format( (float) $r->amount, 2 ) );
							?>
						</td>
						<td>
							<?php
							$st  = (string) $r->status;
							$cls = $status_classes[ $st ] ?? '';
							printf(
								'<span class="wplm-badge %s">%s</span>',
								esc_attr( $cls ),
								esc_html( ucfirst( $st ) )
							);
							?>
						</td>
						<td>
							<?php
							if ( $r->order_id ) {
								$link = get_edit_post_link( (int) $r->order_id );
								echo $link
									? sprintf( '<a href="%s">#%s</a>', esc_url( $link ), esc_html( (string) $r->order_id ) )
									: esc_html( '#' . $r->order_id );
							} else {
								echo '&mdash;';
							}
							?>
						</td>
						<td><?php echo $r->scheduled_for ? esc_html( wp_date( get_option( 'date_format' ), strtotime( $r->scheduled_for ) ) ) : '&mdash;'; ?></td>
						<td><?php echo $r->processed_at ? esc_html( wp_date( get_option( 'date_format' ), strtotime( $r->processed_at ) ) ) : '&mdash;'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// -------------------------------------------------------------------------
	// Inline action handlers called from render_page() before edit routing
	// -------------------------------------------------------------------------

	/** Handle pause/resume actions from the edit screen action buttons. */
	public function handle_inline_actions(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );
		$id     = absint( $_GET['id'] ?? 0 );
		// phpcs:enable

		if ( ! $id ) {
			return false;
		}

		if ( 'pause' === $action ) {
			check_admin_referer( 'wplm_pause_subscription_' . $id );
			$this->repo->update_status( $id, 'on-hold' );
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-subscriptions&action=edit&id=' . $id . '&saved=1' ) );
			exit;
		}

		if ( 'resume' === $action ) {
			check_admin_referer( 'wplm_resume_subscription_' . $id );
			$this->repo->update_status( $id, 'active' );
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-subscriptions&action=edit&id=' . $id . '&saved=1' ) );
			exit;
		}

		if ( 'cancel_from_edit' === $action ) {
			check_admin_referer( 'wplm_cancel_subscription_' . $id );
			$this->repo->update_status( $id, 'cancelled' );
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-subscriptions&action=edit&id=' . $id . '&saved=1' ) );
			exit;
		}

		return false;
	}
}
