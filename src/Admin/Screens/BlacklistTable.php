<?php
/**
 * WP_List_Table implementation for the Blacklist admin screen.
 *
 * @package WPLM\Admin\Screens
 */

namespace WPLM\Admin\Screens;

defined( 'ABSPATH' ) || exit;

use WPLM\Repositories\BlacklistRepository;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Paginated list of blacklisted keys, fingerprints, and IPs, plus an add form.
 */
class BlacklistTable extends \WP_List_Table {

	/** @var BlacklistRepository */
	private BlacklistRepository $repo;

	/**
	 * Translated blacklist-type labels keyed by type slug.
	 *
	 * @return array<string,string>
	 */
	private function type_labels(): array {
		return array(
			'key'         => __( 'License Key', 'wp-license-manager' ),
			'fingerprint' => __( 'Fingerprint', 'wp-license-manager' ),
			'ip'          => __( 'IP Address', 'wp-license-manager' ),
			'email'       => __( 'Email', 'wp-license-manager' ),
		);
	}

	/**
	 * @param BlacklistRepository $repo
	 */
	public function __construct( BlacklistRepository $repo ) {
		$this->repo = $repo;
		parent::__construct(
			array(
				'singular' => 'entry',
				'plural'   => 'entries',
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
			'type'       => __( 'Type', 'wp-license-manager' ),
			'value'      => __( 'Value', 'wp-license-manager' ),
			'reason'     => __( 'Reason', 'wp-license-manager' ),
			'created_at' => __( 'Added', 'wp-license-manager' ),
		);
	}

	public function get_sortable_columns(): array {
		return array(
			'type'       => array( 'type', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	public function get_bulk_actions(): array {
		return array(
			'bulk-remove' => __( 'Remove from Blacklist', 'wp-license-manager' ),
		);
	}

	// -------------------------------------------------------------------------
	// Cell renderers
	// -------------------------------------------------------------------------

	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'type':
				$t      = (string) ( $item->type ?? 'ip' );
				$labels = $this->type_labels();
				$label  = $labels[ $t ] ?? esc_html( ucfirst( $t ) );
				return sprintf(
					'<span class="wplm-badge wplm-bl-%s">%s</span>',
					esc_attr( $t ),
					esc_html( $label )
				);

			case 'value':
				return '<code>' . esc_html( (string) ( $item->value ?? '' ) ) . '</code>';

			case 'reason':
				return $item->reason ? esc_html( $item->reason ) : '&mdash;';

			case 'created_at':
				if ( empty( $item->created_at ) ) {
					return '&mdash;';
				}
				return esc_html( wp_date( get_option( 'date_format' ), strtotime( $item->created_at ) ) );
		}
		return '&mdash;';
	}

	public function column_type( $item ): string {
		$id         = (int) $item->id;
		$remove_url = wp_nonce_url(
			admin_url( 'admin.php?page=wplm-blacklist&action=remove&id=' . $id ),
			'wplm_remove_blacklist_' . $id
		);
		$t          = (string) ( $item->type ?? 'ip' );
		$bl_labels  = $this->type_labels();
		$label      = $bl_labels[ $t ] ?? esc_html( ucfirst( $t ) );

		$title = sprintf(
			'<span class="wplm-badge wplm-bl-%s">%s</span>',
			esc_attr( $t ),
			esc_html( $label )
		);

		$actions = array(
			'remove' => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $remove_url ),
				esc_js( __( 'Remove this entry from the blacklist?', 'wp-license-manager' ) ),
				esc_html__( 'Remove', 'wp-license-manager' )
			),
		);

		return $title . $this->row_actions( $actions );
	}

	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="entry[]" value="%d">', (int) $item->id );
	}

	// -------------------------------------------------------------------------
	// Data loading — loads all entries, paginates in PHP
	// -------------------------------------------------------------------------

	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'wplm_blacklist_per_page', 20 );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$type  = sanitize_key( $_GET['wplm_bl_type'] ?? '' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$all   = $this->repo->get_all( $type );
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
	// Table nav — type filter
	// -------------------------------------------------------------------------

	public function display_tablenav( $which ): void {
		parent::display_tablenav( $which );
		if ( 'top' !== $which ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = sanitize_key( $_GET['wplm_bl_type'] ?? '' );
		?>
		<div class="wplm-status-filter alignleft actions">
			<select name="wplm_bl_type">
				<option value=""><?php esc_html_e( 'All types', 'wp-license-manager' ); ?></option>
				<?php foreach ( $this->type_labels() as $t => $label ) : ?>
					<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $current, $t ); ?>>
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
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Handle remove.
		if ( 'remove' === $action && $id > 0 ) {
			check_admin_referer( 'wplm_remove_blacklist_' . $id );
			$this->repo->remove( $id );
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-blacklist&removed=1' ) );
			exit;
		}

		// Bulk remove.
		if ( 'bulk-remove' === $this->current_action() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
			$ids = array_map( 'absint', (array) ( $_REQUEST['entry'] ?? array() ) );
			foreach ( $ids as $eid ) {
				if ( $eid > 0 ) {
					$this->repo->remove( $eid );
				}
			}
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-blacklist&removed=' . count( $ids ) ) );
			exit;
		}

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Blacklist', 'wp-license-manager' ); ?></h1>
			<hr class="wp-header-end">

			<?php
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $_GET['added'] ) ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Entry added to blacklist.', 'wp-license-manager' ); ?></p></div>
				<?php
			endif;
			if ( ! empty( $_GET['removed'] ) ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Entry removed from blacklist.', 'wp-license-manager' ); ?></p></div>
				<?php
			endif;
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			?>

			<!-- Add entry form -->
			<div class="wplm-add-form" style="background:#fff;border:1px solid #c3c4c7;padding:16px;margin-bottom:20px;max-width:600px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Add to Blacklist', 'wp-license-manager' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wplm_add_blacklist', 'wplm_blacklist_nonce' ); ?>
					<input type="hidden" name="action" value="wplm_add_blacklist">
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="wplm_bl_type"><?php esc_html_e( 'Type', 'wp-license-manager' ); ?></label></th>
								<td>
									<select id="wplm_bl_type" name="bl_type">
										<?php foreach ( $this->type_labels() as $t => $label ) : ?>
											<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wplm_bl_value"><?php esc_html_e( 'Value', 'wp-license-manager' ); ?></label></th>
								<td>
									<input type="text" id="wplm_bl_value" name="bl_value" class="regular-text" required>
									<p class="description"><?php esc_html_e( 'The key string, HMAC fingerprint, IP address, or email to block.', 'wp-license-manager' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="wplm_bl_reason"><?php esc_html_e( 'Reason', 'wp-license-manager' ); ?></label></th>
								<td>
									<input type="text" id="wplm_bl_reason" name="bl_reason" class="regular-text">
									<p class="description"><?php esc_html_e( 'Optional note for audit purposes.', 'wp-license-manager' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>
					<?php submit_button( __( 'Add to Blacklist', 'wp-license-manager' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<!-- Entries list -->
			<form method="get">
				<input type="hidden" name="page" value="wplm-blacklist">
				<?php
				$this->prepare_items();
				$this->display();
				?>
			</form>
		</div>
		<?php
	}
}
