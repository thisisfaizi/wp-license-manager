<?php
/**
 * WP_List_Table implementation for the Webhooks admin screen.
 *
 * @package WPLM\Admin\Screens
 */

namespace WPLM\Admin\Screens;

defined( 'ABSPATH' ) || exit;

use WPLM\Repositories\WebhookRepository;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Paginated list of outbound webhook endpoints, plus an add/edit form.
 */
class WebhookListTable extends \WP_List_Table {

	/** @var WebhookRepository */
	private WebhookRepository $repo;

	/**
	 * @param WebhookRepository $repo
	 */
	public function __construct( WebhookRepository $repo ) {
		$this->repo = $repo;
		parent::__construct(
			array(
				'singular' => 'webhook',
				'plural'   => 'webhooks',
				'ajax'     => false,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Column definitions
	// -------------------------------------------------------------------------

	public function get_columns(): array {
		return array(
			'cb'         => '<input type="checkbox">',
			'name'       => __( 'Name', 'wp-license-manager' ),
			'target_url' => __( 'Delivery URL', 'wp-license-manager' ),
			'events'     => __( 'Events', 'wp-license-manager' ),
			'status'     => __( 'Status', 'wp-license-manager' ),
			'secret'     => __( 'Secret', 'wp-license-manager' ),
		);
	}

	public function get_sortable_columns(): array {
		return array(
			'name'   => array( 'name', false ),
			'status' => array( 'status', false ),
		);
	}

	public function get_bulk_actions(): array {
		return array(
			'bulk-enable'  => __( 'Enable', 'wp-license-manager' ),
			'bulk-disable' => __( 'Disable', 'wp-license-manager' ),
			'bulk-delete'  => __( 'Delete', 'wp-license-manager' ),
		);
	}

	// -------------------------------------------------------------------------
	// Cell renderers
	// -------------------------------------------------------------------------

	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'target_url':
				$url = (string) ( $item->target_url ?? '' );
				return $url
					? '<code>' . esc_html( $url ) . '</code>'
					: '&mdash;';

			case 'events':
				$events = is_array( $item->events ) ? $item->events : array();
				if ( empty( $events ) ) {
					return '&mdash;';
				}
				$tags = array_map(
					fn( $e ) => '<span class="wplm-event-tag">' . esc_html( $e ) . '</span>',
					array_slice( $events, 0, 5 )
				);
				$more = count( $events ) > 5
					? ' <em>+' . ( count( $events ) - 5 ) . ' ' . esc_html__( 'more', 'wp-license-manager' ) . '</em>'
					: '';
				return implode( ' ', $tags ) . $more;

			case 'status':
				$active = 1 === (int) ( $item->status ?? 1 );
				return $active
					? '<span class="wplm-badge wplm-status-1">' . esc_html__( 'Active', 'wp-license-manager' ) . '</span>'
					: '<span class="wplm-badge wplm-status-2">' . esc_html__( 'Disabled', 'wp-license-manager' ) . '</span>';

			case 'secret':
				$s = (string) ( $item->secret ?? '' );
				return $s
					? '<code>' . esc_html( substr( $s, 0, 8 ) ) . '&hellip;</code>'
					: '&mdash;';
		}
		return '&mdash;';
	}

	public function column_name( $item ): string {
		$id            = (int) $item->id;
		$edit_url      = admin_url( 'admin.php?page=wplm-webhooks&action=edit&id=' . $id );
		$del_url       = wp_nonce_url(
			admin_url( 'admin.php?page=wplm-webhooks&action=delete&id=' . $id ),
			'wplm_delete_webhook_' . $id
		);
		$toggle_action = 1 === (int) ( $item->status ?? 1 ) ? 'disable' : 'enable';
		$toggle_url    = wp_nonce_url(
			admin_url( 'admin.php?page=wplm-webhooks&action=' . $toggle_action . '&id=' . $id ),
			'wplm_' . $toggle_action . '_webhook_' . $id
		);
		$toggle_label  = 'disable' === $toggle_action
			? __( 'Disable', 'wp-license-manager' )
			: __( 'Enable', 'wp-license-manager' );

		$title = sprintf(
			'<a href="%s" class="row-title"><strong>%s</strong></a>',
			esc_url( $edit_url ),
			esc_html( $item->name ?: '#' . $id )
		);

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'wp-license-manager' ) ),
			'toggle' => sprintf( '<a href="%s">%s</a>', esc_url( $toggle_url ), esc_html( $toggle_label ) ),
			'delete' => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $del_url ),
				esc_js( __( 'Delete this webhook?', 'wp-license-manager' ) ),
				esc_html__( 'Delete', 'wp-license-manager' )
			),
		);

		return $title . $this->row_actions( $actions );
	}

	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="webhook[]" value="%d">', (int) $item->id );
	}

	// -------------------------------------------------------------------------
	// Data loading — loads all webhooks (typically few), paginates in PHP
	// -------------------------------------------------------------------------

	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'wplm_webhooks_per_page', 20 );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = max( 1, absint( $_GET['paged'] ?? 1 ) );

		$all   = $this->repo->get_all();
		$total = count( $all );

		$this->items = array_slice( $all, ( $paged - 1 ) * $per_page, $per_page );

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
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
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'delete' === $action && $id > 0 ) {
			check_admin_referer( 'wplm_delete_webhook_' . $id );
			$this->repo->delete( $id );
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-webhooks&deleted=1' ) );
			exit;
		}

		if ( 'enable' === $action && $id > 0 ) {
			check_admin_referer( 'wplm_enable_webhook_' . $id );
			$this->repo->update( $id, array( 'status' => 1 ) );
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-webhooks&saved=1' ) );
			exit;
		}

		if ( 'disable' === $action && $id > 0 ) {
			check_admin_referer( 'wplm_disable_webhook_' . $id );
			$this->repo->update( $id, array( 'status' => 0 ) );
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-webhooks&saved=1' ) );
			exit;
		}

		// Bulk actions.
		$bulk = $this->current_action();
		if ( in_array( $bulk, array( 'bulk-enable', 'bulk-disable', 'bulk-delete' ), true ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
			$ids = array_map( 'absint', (array) ( $_REQUEST['webhook'] ?? array() ) );
			foreach ( $ids as $wid ) {
				if ( $wid <= 0 ) {
					continue;
				}
				if ( 'bulk-delete' === $bulk ) {
					$this->repo->delete( $wid );
				} else {
					$this->repo->update( $wid, array( 'status' => 'bulk-enable' === $bulk ? 1 : 0 ) );
				}
			}
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-webhooks&saved=1' ) );
			exit;
		}

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$this->render_edit_form();
		} else {
			$this->render_list();
		}
	}

	private function render_list(): void {
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Webhooks', 'wp-license-manager' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wplm-webhooks&action=add' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'wp-license-manager' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php
			$example_rest = esc_url( home_url( '/wp-json/my-app/v1/wplm-webhook' ) );
			$example_file = esc_url( home_url( '/wplm-webhook.php' ) );
			?>
			<div class="notice notice-info" style="padding:12px 16px;">
				<p style="margin-top:0;"><strong><?php esc_html_e( 'What are webhooks?', 'wp-license-manager' ); ?></strong></p>
				<p>
					<?php esc_html_e( 'A webhook sends a real-time HTTP notification to a URL you control whenever something happens in WPLM — a license is created, a device is revoked, a subscription renews, and so on. Use them to sync customers to a CRM, post alerts to Slack, trigger fulfilment, or keep your own backend in step with your licensing.', 'wp-license-manager' ); ?>
				</p>

				<p style="margin-bottom:4px;"><strong><?php esc_html_e( 'Who receives a webhook?', 'wp-license-manager' ); ?></strong></p>
				<p style="margin-top:0;">
					<?php esc_html_e( 'Not your customers’ apps. Client software (a Flutter app, a licensed plugin, a desktop app) checks licenses by calling the REST API (validate / activate). A webhook is the reverse: WPLM pushes events to one of YOUR OWN server endpoints so your systems can react automatically without polling.', 'wp-license-manager' ); ?>
				</p>

				<p style="margin-bottom:4px;"><strong><?php esc_html_e( 'Can I use my own domain as the URL?', 'wp-license-manager' ); ?></strong></p>
				<p style="margin-top:0;">
					<?php esc_html_e( 'Yes. The Target URL can be any publicly reachable HTTPS endpoint you own — including one on this same domain. It just has to accept a POST request and return a 200 response. For example, a small receiver you publish on your site:', 'wp-license-manager' ); ?>
				</p>
				<p style="margin:4px 0;">
					<code><?php echo esc_html( $example_rest ); ?></code>
					&nbsp;<?php esc_html_e( '(a custom REST route)', 'wp-license-manager' ); ?><br>
					<code><?php echo esc_html( $example_file ); ?></code>
					&nbsp;<?php esc_html_e( '(a standalone PHP file in your web root)', 'wp-license-manager' ); ?>
				</p>
				<p style="margin-top:0;font-style:italic;">
					<?php esc_html_e( 'Tip: if you only need to react on THIS WordPress site, you don’t need a webhook at all — hook the wplm_* PHP actions directly (e.g. add_action(\'wplm_license_created\', …)). Webhooks are for separate apps/servers, or no-code tools like Slack, Zapier, or Make.', 'wp-license-manager' ); ?>
				</p>

				<p style="margin-bottom:4px;"><strong><?php esc_html_e( 'No code? Use a preset.', 'wp-license-manager' ); ?></strong>
					<?php esc_html_e( 'When adding a webhook, the “Send to” option lets you pick Slack, Discord, or Email — just paste an incoming-webhook URL (Slack/Discord) or an email address and you’re done. Choose “Custom endpoint” only if you want the raw signed JSON for your own backend.', 'wp-license-manager' ); ?>
				</p>

				<p style="margin-bottom:0;"><strong><?php esc_html_e( 'How to add one:', 'wp-license-manager' ); ?></strong>
					<?php esc_html_e( 'Click “Add New”, choose where to send it, paste the URL/address, tick the events you want, and save. For custom endpoints WPLM POSTs a JSON body { event, data, timestamp } signed with HMAC-SHA256 in the X-WPLM-Signature header. Failed deliveries retry hourly (up to 5 attempts within 24 hours). After saving, use “Send test delivery” to confirm it works.', 'wp-license-manager' ); ?>
				</p>
			</div>

			<?php
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $_GET['saved'] ) ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Webhook saved.', 'wp-license-manager' ); ?></p></div>
				<?php
			endif;
			if ( ! empty( $_GET['deleted'] ) ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Webhook deleted.', 'wp-license-manager' ); ?></p></div>
				<?php
			endif;
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			?>
			<form method="get">
				<input type="hidden" name="page" value="wplm-webhooks">
				<?php
				$this->prepare_items();
				$this->display();
				?>
			</form>
		</div>
		<?php
	}

	private function render_edit_form(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id      = absint( $_GET['id'] ?? 0 );
		$webhook = ( $id > 0 ) ? $this->repo->find_by_id( $id ) : null;
		$edit    = null !== $webhook;

		$all_events = array(
			'license.created',
			'license.activated',
			'license.deactivated',
			'license.revoked',
			'license.suspended',
			'license.reinstated',
			'license.terminated',
			'license.expired',
			'license.renewed',
			'machine.revoked',
			'subscription.created',
			'subscription.renewed',
			'subscription.payment_failed',
			'subscription.cancelled',
			'subscription.paused',
			'subscription.resumed',
			'subscription.expired',
		);

		$current_events = $edit && $webhook ? ( is_array( $webhook->events ) ? $webhook->events : array() ) : array();
		$cur_format     = $edit && $webhook ? $webhook->format : 'json';
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $edit ? __( 'Edit Webhook', 'wp-license-manager' ) : __( 'Add New Webhook', 'wp-license-manager' ) ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wplm-webhooks' ) ); ?>">&larr; <?php esc_html_e( 'Back to Webhooks', 'wp-license-manager' ); ?></a></p>
			<?php
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['wplm_test'] ) ) :
				$test_ok   = 'ok' === sanitize_text_field( wp_unslash( $_GET['wplm_test'] ) );
				$test_code = absint( $_GET['code'] ?? 0 );
				?>
				<div class="notice <?php echo $test_ok ? 'notice-success' : 'notice-error'; ?> is-dismissible">
					<p>
						<?php
						echo $test_ok
							? esc_html__( 'Test delivery sent successfully.', 'wp-license-manager' )
							: esc_html(
								sprintf(
									/* translators: %d: HTTP response code */
									__( 'Test delivery failed (response code %d). Check the URL/address and that your endpoint returns a 2xx status.', 'wp-license-manager' ),
									$test_code
								)
							);
						?>
					</p>
				</div>
				<?php
			endif;
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wplm_save_webhook', 'wplm_webhook_nonce' ); ?>
				<input type="hidden" name="action" value="wplm_save_webhook">
				<?php if ( $edit ) : ?>
					<input type="hidden" name="webhook_id" value="<?php echo esc_attr( (string) $id ); ?>">
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="wplm_wh_name"><?php esc_html_e( 'Name', 'wp-license-manager' ); ?></label></th>
							<td>
								<input type="text" id="wplm_wh_name" name="wh_name" value="<?php echo esc_attr( $edit && $webhook ? $webhook->name : '' ); ?>" class="regular-text" required>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wplm_wh_format"><?php esc_html_e( 'Send to', 'wp-license-manager' ); ?></label></th>
							<td>
								<select id="wplm_wh_format" name="wh_format">
									<option value="json" <?php selected( $cur_format, 'json' ); ?>><?php esc_html_e( 'Custom endpoint (signed JSON)', 'wp-license-manager' ); ?></option>
									<option value="slack" <?php selected( $cur_format, 'slack' ); ?>><?php esc_html_e( 'Slack', 'wp-license-manager' ); ?></option>
									<option value="discord" <?php selected( $cur_format, 'discord' ); ?>><?php esc_html_e( 'Discord', 'wp-license-manager' ); ?></option>
									<option value="email" <?php selected( $cur_format, 'email' ); ?>><?php esc_html_e( 'Email', 'wp-license-manager' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Slack / Discord: just paste an incoming-webhook URL — no code. Email: enter an address. Custom endpoint: your own URL receives a signed JSON payload.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wplm_wh_target_url"><span class="wplm-wh-target-label"><?php esc_html_e( 'Delivery URL', 'wp-license-manager' ); ?></span></label></th>
							<td>
								<input type="text" id="wplm_wh_target_url" name="wh_target_url" value="<?php echo esc_attr( $edit && $webhook ? $webhook->target_url : '' ); ?>" class="large-text" required>
								<p class="description wplm-wh-target-hint"><?php esc_html_e( 'HTTPS endpoint (or Slack/Discord webhook URL) to receive payloads.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>
						<script>
						(function($){
							function wplmWhUpdateTarget(){
								var f = $('#wplm_wh_format').val();
								var label = '<?php echo esc_js( __( 'Delivery URL', 'wp-license-manager' ) ); ?>';
								var hint  = '<?php echo esc_js( __( 'HTTPS endpoint that receives the signed JSON payload.', 'wp-license-manager' ) ); ?>';
								if ( f === 'slack' ) { label = '<?php echo esc_js( __( 'Slack webhook URL', 'wp-license-manager' ) ); ?>'; hint = '<?php echo esc_js( __( 'Paste your Slack “Incoming Webhook” URL (https://hooks.slack.com/…).', 'wp-license-manager' ) ); ?>'; }
								else if ( f === 'discord' ) { label = '<?php echo esc_js( __( 'Discord webhook URL', 'wp-license-manager' ) ); ?>'; hint = '<?php echo esc_js( __( 'Paste your Discord channel webhook URL (https://discord.com/api/webhooks/…).', 'wp-license-manager' ) ); ?>'; }
								else if ( f === 'email' ) { label = '<?php echo esc_js( __( 'Email address', 'wp-license-manager' ) ); ?>'; hint = '<?php echo esc_js( __( 'The address that should receive an email on each event.', 'wp-license-manager' ) ); ?>'; }
								$('.wplm-wh-target-label').text(label);
								$('.wplm-wh-target-hint').text(hint);
							}
							$(function(){ $('#wplm_wh_format').on('change', wplmWhUpdateTarget); wplmWhUpdateTarget(); });
						})(jQuery);
						</script>
						<tr>
							<th scope="row"><?php esc_html_e( 'Events', 'wp-license-manager' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Select events to listen for', 'wp-license-manager' ); ?></legend>
									<?php foreach ( $all_events as $event ) : ?>
										<label style="display:block;margin-bottom:4px;">
											<input
												type="checkbox"
												name="wh_events[]"
												value="<?php echo esc_attr( $event ); ?>"
												<?php checked( in_array( $event, $current_events, true ) ); ?>
											>
											<code><?php echo esc_html( $event ); ?></code>
										</label>
									<?php endforeach; ?>
								</fieldset>
							</td>
						</tr>
						<?php if ( $edit && $webhook ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'wp-license-manager' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="wh_status" value="1" <?php checked( 1, (int) $webhook->status ); ?>>
									<?php esc_html_e( 'Active', 'wp-license-manager' ); ?>
								</label>
							</td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>

				<?php submit_button( $edit ? __( 'Update Webhook', 'wp-license-manager' ) : __( 'Create Webhook', 'wp-license-manager' ) ); ?>
				</form>

				<?php if ( $edit && $webhook ) : ?>
					<?php
					$test_url = wp_nonce_url(
						admin_url( 'admin-post.php?action=wplm_test_webhook&webhook_id=' . $id ),
						'wplm_test_webhook_' . $id
					);
					?>
					<hr>
					<p>
						<a href="<?php echo esc_url( $test_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Send test delivery', 'wp-license-manager' ); ?></a>
						<span class="description" style="margin-left:8px;"><?php esc_html_e( 'Sends a sample “test.ping” event to this destination so you can confirm it works.', 'wp-license-manager' ); ?></span>
					</p>
				<?php endif; ?>
			</div>
			<?php
	}
}
