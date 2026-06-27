<?php
/**
 * WP_List_Table implementation for the Licenses admin screen.
 *
 * @package WPLM\Admin\Screens
 */

namespace WPLM\Admin\Screens;

defined( 'ABSPATH' ) || exit;

use WPLM\Repositories\LicenseRepository;
use WPLM\Repositories\GeneratorRepository;

// WP_List_Table is not always loaded outside the list-tables context.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders a paginated, filterable, bulk-actionable list of WPLM licenses
 * and owns the add/edit form used to create or update individual license records.
 */
class LicenseListTable extends \WP_List_Table {

	/** @var LicenseRepository */
	private LicenseRepository $repo;

	/**
	 * Translated status-code to human-readable label map.
	 *
	 * @return array<int,string>
	 */
	private function status_labels(): array {
		return array(
			0 => __( 'Pending', 'wp-license-manager' ),
			1 => __( 'Active', 'wp-license-manager' ),
			2 => __( 'Inactive', 'wp-license-manager' ),
			3 => __( 'Expired', 'wp-license-manager' ),
			4 => __( 'Suspended', 'wp-license-manager' ),
			5 => __( 'Revoked', 'wp-license-manager' ),
			6 => __( 'Terminated', 'wp-license-manager' ),
		);
	}

	/**
	 * @param LicenseRepository $repo License data-access layer.
	 */
	public function __construct( LicenseRepository $repo ) {
		$this->repo = $repo;

		parent::__construct(
			array(
				'singular' => 'license',
				'plural'   => 'licenses',
				'ajax'     => false,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Column definitions
	// -------------------------------------------------------------------------

	/**
	 * Return the column header map.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		$columns = array(
			'cb'               => '<input type="checkbox">',
			'license_key'      => __( 'License Key', 'wp-license-manager' ),
			'status'           => __( 'Status', 'wp-license-manager' ),
			'product_id'       => __( 'Product', 'wp-license-manager' ),
			'activation_count' => __( 'Activations', 'wp-license-manager' ),
			'expires_at'       => __( 'Expires', 'wp-license-manager' ),
			'created_at'       => __( 'Created', 'wp-license-manager' ),
		);

		/**
		 * Filter the columns shown on the Licenses list table.
		 *
		 * @param array<string,string> $columns Column slug => header label.
		 * @param string                $context List-table context identifier.
		 */
		return (array) apply_filters( 'wplm_list_table_columns', $columns, 'licenses' );
	}

	/**
	 * Return which columns support server-side sorting.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function get_sortable_columns(): array {
		return array(
			'status'     => array( 'status', false ),
			'expires_at' => array( 'expires_at', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	// -------------------------------------------------------------------------
	// Bulk actions
	// -------------------------------------------------------------------------

	/**
	 * Return the available bulk actions.
	 *
	 * @return array<string,string>
	 */
	public function get_bulk_actions(): array {
		return array(
			'bulk-revoke'  => __( 'Revoke', 'wp-license-manager' ),
			'bulk-suspend' => __( 'Suspend', 'wp-license-manager' ),
			'bulk-export'  => __( 'Export CSV', 'wp-license-manager' ),
		);
	}

	// -------------------------------------------------------------------------
	// Cell renderers
	// -------------------------------------------------------------------------

	/**
	 * Default cell renderer for columns without a dedicated method.
	 *
	 * @param object $item        License model object.
	 * @param string $column_name Column slug.
	 * @return string Escaped HTML.
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {

			case 'status':
				$code   = (int) $item->status;
				$labels = $this->status_labels();
				$label  = $labels[ $code ] ?? __( 'Unknown', 'wp-license-manager' );

				return sprintf(
					'<span class="wplm-badge wplm-status-%d">%s</span>',
					$code,
					esc_html( $label )
				);

			case 'product_id':
				if ( empty( $item->product_id ) ) {
					return '&mdash;';
				}
				$pid   = (int) $item->product_id;
				$link  = get_edit_post_link( $pid );
				$title = get_the_title( $pid );
				if ( $link && $title ) {
					return sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $title ) );
				}
				return esc_html( '#' . $pid );

			case 'activation_count':
				$used = (int) $item->activation_count;
				$max  = null !== $item->max_activations ? (int) $item->max_activations : null;
				return esc_html( $used ) . ' / ' . ( null !== $max ? esc_html( $max ) : '&infin;' );

			case 'expires_at':
				if ( empty( $item->expires_at ) ) {
					return esc_html__( 'Never', 'wp-license-manager' );
				}
				return esc_html( wp_date( get_option( 'date_format' ), strtotime( $item->expires_at ) ) );

			case 'created_at':
				if ( empty( $item->created_at ) ) {
					return '&mdash;';
				}
				return esc_html( wp_date( get_option( 'date_format' ), strtotime( $item->created_at ) ) );

			default:
				return '&mdash;';
		}
	}

	/**
	 * Render the license key cell with a truncated key and row actions.
	 *
	 * The full key is never displayed in the list; only first-4 and last-4 chars
	 * are shown to prevent keys appearing in browser history / logs.
	 *
	 * @param object $item License model object.
	 * @return string HTML output.
	 */
	public function column_license_key( $item ): string {
		$key = (string) $item->license_key;

		if ( strlen( $key ) > 8 ) {
			$display = esc_html( substr( $key, 0, 4 ) ) . '&bull;&bull;&bull;&bull;' . esc_html( substr( $key, -4 ) );
		} else {
			$display = str_repeat( '&bull;', max( 8, strlen( $key ) ) );
		}

		$id       = (int) $item->id;
		$edit_url = admin_url( 'admin.php?page=wplm-licenses&action=edit&id=' . $id );

		$title = sprintf(
			'<a href="%s" class="row-title">%s</a>',
			esc_url( $edit_url ),
			$display
		);

		$revoke_url = wp_nonce_url(
			admin_url( 'admin.php?page=wplm-licenses&action=revoke&id=' . $id ),
			'wplm_revoke_license_' . $id
		);
		$delete_url = wp_nonce_url(
			admin_url( 'admin.php?page=wplm-licenses&action=delete&id=' . $id ),
			'wplm_delete_license_' . $id
		);

		$actions = array(
			'edit'   => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit', 'wp-license-manager' )
			),
			'revoke' => sprintf(
				'<a href="%s" class="wplm-action-revoke">%s</a>',
				esc_url( $revoke_url ),
				esc_html__( 'Revoke', 'wp-license-manager' )
			),
			'delete' => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Are you sure you want to permanently delete this license?', 'wp-license-manager' ) ),
				esc_html__( 'Delete', 'wp-license-manager' )
			),
		);

		return $title . $this->row_actions( $actions );
	}

	/**
	 * Render the bulk-action checkbox cell.
	 *
	 * @param object $item License model object.
	 * @return string HTML output.
	 */
	public function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="license[]" value="%d">',
			(int) $item->id
		);
	}

	// -------------------------------------------------------------------------
	// Bulk actions
	// -------------------------------------------------------------------------

	/**
	 * Process a selected bulk action (revoke / suspend / export).
	 *
	 * Must be called before any output is produced (the CSV export streams a
	 * file and the revoke/suspend paths issue a redirect). Returns early when
	 * no WPLM bulk action is pending.
	 *
	 * @return void
	 */
	public function handle_bulk_actions(): void {
		$action = $this->current_action();

		if ( ! in_array( $action, array( 'bulk-revoke', 'bulk-suspend', 'bulk-export' ), true ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above.
		$ids = array_filter( array_map( 'absint', (array) ( $_REQUEST['license'] ?? array() ) ) );

		if ( empty( $ids ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-licenses' ) );
			exit;
		}

		if ( 'bulk-export' === $action ) {
			$this->export_csv( $ids );
			// export_csv() streams and exits.
		}

		$service = \WPLM\Plugin::get_instance()->container()->make( \WPLM\Services\RevocationService::class );
		$count   = 0;

		foreach ( $ids as $id ) {
			try {
				if ( 'bulk-revoke' === $action ) {
					$service->revoke_license( (int) $id );
				} else {
					$service->suspend_license( (int) $id );
				}
				++$count;
			} catch ( \Throwable $e ) {
				unset( $e ); // Skip licenses that cannot transition (e.g. terminated).
			}
		}

		$result_key = ( 'bulk-revoke' === $action ) ? 'revoked' : 'suspended';
		wp_safe_redirect( admin_url( 'admin.php?page=wplm-licenses&' . $result_key . '=' . $count ) );
		exit;
	}

	/**
	 * Stream the given licenses as a CSV download and terminate the request.
	 *
	 * @param int[] $ids License row IDs to export.
	 * @return void
	 */
	private function export_csv( array $ids ): void {
		$columns = array(
			'id',
			'license_key',
			'status',
			'product_id',
			'order_id',
			'user_id',
			'max_activations',
			'activation_count',
			'is_floating',
			'overage_strategy',
			'valid_for_days',
			'expires_at',
			'created_at',
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wplm-licenses-' . gmdate( 'Ymd-His' ) . '.csv' );

		$labels = $this->status_labels();
		$out    = fopen( 'php://output', 'w' );

		fputcsv( $out, $columns );

		foreach ( $ids as $id ) {
			$license = $this->repo->find_by_id( (int) $id );
			if ( ! $license ) {
				continue;
			}

			fputcsv(
				$out,
				array(
					$license->id,
					$license->license_key,
					$labels[ (int) $license->status ] ?? (string) $license->status,
					$license->product_id,
					$license->order_id,
					$license->user_id,
					$license->max_activations,
					$license->activation_count,
					$license->is_floating,
					$license->overage_strategy,
					$license->valid_for_days,
					$license->expires_at,
					$license->created_at,
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	// -------------------------------------------------------------------------
	// Data loading
	// -------------------------------------------------------------------------

	/**
	 * Fetch items from the repository and configure pagination.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'wplm_licenses_per_page', 20 );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged      = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$orderby    = sanitize_key( $_GET['orderby'] ?? 'created_at' );
		$order      = strtoupper( sanitize_key( $_GET['order'] ?? 'DESC' ) );
		$status_raw = isset( $_GET['wplm_status'] ) ? sanitize_text_field( wp_unslash( $_GET['wplm_status'] ) ) : '';
		$status_arg = ( '' !== $status_raw ) ? (int) $status_raw : null;
		$product_id = ! empty( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$args = array(
			'per_page' => $per_page,
			'page'     => $paged,
			'orderby'  => $orderby,
			'order'    => $order,
		);

		if ( null !== $status_arg ) {
			$args['status'] = $status_arg;
		}
		if ( null !== $product_id ) {
			$args['product_id'] = $product_id;
		}

		$result = $this->repo->get_list( $args );

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

	/**
	 * Add a status-filter dropdown above the table on the 'top' position.
	 *
	 * @param string $which 'top' or 'bottom'.
	 * @return void
	 */
	public function display_tablenav( $which ): void {
		parent::display_tablenav( $which );

		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['wplm_status'] ) && '' !== $_GET['wplm_status']
			? (int) $_GET['wplm_status']
			: '';
		?>
		<div class="wplm-status-filter alignleft actions">
			<label for="wplm-filter-status" class="screen-reader-text">
				<?php esc_html_e( 'Filter by status', 'wp-license-manager' ); ?>
			</label>
			<select id="wplm-filter-status" name="wplm_status">
				<option value=""><?php esc_html_e( 'All statuses', 'wp-license-manager' ); ?></option>
				<?php foreach ( $this->status_labels() as $code => $label ) : ?>
					<option value="<?php echo esc_attr( (string) $code ); ?>" <?php selected( $current, $code ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'wp-license-manager' ), 'button', 'filter_action', false ); ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Page-level render methods
	// -------------------------------------------------------------------------

	/**
	 * Route to the list or add/edit form based on the 'action' query param.
	 *
	 * @return void
	 */
	public function render_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );
		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$this->render_edit_form();
		} else {
			$this->render_list();
		}
	}

	/**
	 * Render the license list page.
	 *
	 * @return void
	 */
	public function render_list(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-license-manager' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'License saved.', 'wp-license-manager' )
				. '</p></div>';
		}
		if ( ! empty( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'License deleted.', 'wp-license-manager' )
				. '</p></div>';
		}
		if ( isset( $_GET['revoked'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html(
					sprintf(
						/* translators: %d: number of licenses revoked */
						_n( '%d license revoked.', '%d licenses revoked.', absint( $_GET['revoked'] ), 'wp-license-manager' ),
						absint( $_GET['revoked'] )
					)
				)
				. '</p></div>';
		}
		if ( isset( $_GET['suspended'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html(
					sprintf(
						/* translators: %d: number of licenses suspended */
						_n( '%d license suspended.', '%d licenses suspended.', absint( $_GET['suspended'] ), 'wp-license-manager' ),
						absint( $_GET['suspended'] )
					)
				)
				. '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Licenses', 'wp-license-manager' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wplm-licenses&action=add' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'wp-license-manager' ); ?>
			</a>
			<hr class="wp-header-end">
			<form method="get">
				<input type="hidden" name="page" value="wplm-licenses">
				<?php $this->search_box( __( 'Search Licenses', 'wp-license-manager' ), 'wplm-license' ); ?>
				<?php
				$this->prepare_items();
				$this->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the add/edit license form.
	 *
	 * Loads existing data when the 'id' query param is present and the license exists.
	 *
	 * @return void
	 */
	public function render_edit_form(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-license-manager' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$license_id = absint( $_GET['id'] ?? 0 );
		$license    = null;
		$is_edit    = false;

		if ( $license_id > 0 ) {
			$license = $this->repo->find_by_id( $license_id );
			$is_edit = null !== $license;
		}

		// Attempt to load generators for the select (non-fatal if unavailable).
		$generators = array();
		try {
			$gen_repo   = \WPLM\Plugin::get_instance()->container()->make( GeneratorRepository::class );
			$generators = $gen_repo->get_all();
		} catch ( \Throwable $e ) {
			unset( $e ); // Silently skip if container not bootstrapped in this context.
		}

		$overage_options = array(
			'deny'        => __( 'Deny', 'wp-license-manager' ),
			'allow_1_25x' => __( 'Allow 1.25&times; seats', 'wp-license-manager' ),
			'allow_2x'    => __( 'Allow 2&times; seats', 'wp-license-manager' ),
		);

		$page_title = $is_edit
			? __( 'Edit License', 'wp-license-manager' )
			: __( 'Add New License', 'wp-license-manager' );

		$current_overage = $is_edit && $license ? $license->overage_strategy : get_option( 'wplm_default_overage_strategy', 'deny' );
		$current_max_act = $is_edit && $license && null !== $license->max_activations
			? $license->max_activations
			: get_option( 'wplm_default_max_activations', 1 );

		// Resolve human-readable labels for searchable ID fields.
		$current_product_id    = $is_edit && $license ? (int) ( $license->product_id ?? 0 ) : 0;
		$current_product_label = '';
		if ( $current_product_id > 0 ) {
			$pt = get_the_title( $current_product_id );
			if ( $pt ) {
				$current_product_label = sprintf( '#%d — %s', $current_product_id, $pt );
			} else {
				$current_product_label = '#' . $current_product_id;
			}
		}

		$current_order_id    = $is_edit && $license ? (int) ( $license->order_id ?? 0 ) : 0;
		$current_order_label = '';
		if ( $current_order_id > 0 ) {
			if ( function_exists( 'wc_get_order' ) ) {
				$o = wc_get_order( $current_order_id );
				$current_order_label = $o
					? sprintf( '#%d — %s', $current_order_id, $o->get_formatted_billing_full_name() )
					: '#' . $current_order_id;
			} else {
				$current_order_label = '#' . $current_order_id;
			}
		}

		$current_user_id    = $is_edit && $license ? (int) ( $license->user_id ?? 0 ) : 0;
		$current_user_label = '';
		if ( $current_user_id > 0 ) {
			$u = get_userdata( $current_user_id );
			$current_user_label = $u
				? sprintf( '#%d — %s (%s)', $current_user_id, $u->display_name, $u->user_email )
				: '#' . $current_user_id;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $page_title ); ?></h1>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wplm-licenses' ) ); ?>">
					&larr; <?php esc_html_e( 'Back to Licenses', 'wp-license-manager' ); ?>
				</a>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wplm_save_license', 'wplm_license_nonce' ); ?>
				<input type="hidden" name="action" value="wplm_save_license">
				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="license_id" value="<?php echo esc_attr( (string) $license_id ); ?>">
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tbody>

						<tr>
							<th scope="row">
								<label for="wplm_generator_id"><?php esc_html_e( 'Generator', 'wp-license-manager' ); ?></label>
							</th>
							<td>
								<select id="wplm_generator_id" name="generator_id">
									<option value=""><?php esc_html_e( '— Select generator —', 'wp-license-manager' ); ?></option>
									<?php foreach ( $generators as $gen ) : ?>
										<option value="<?php echo esc_attr( (string) $gen->id ); ?>">
											<?php echo esc_html( $gen->name ?? ( '#' . $gen->id ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Select a generator to auto-format the license key on creation.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wplm_product_search"><?php esc_html_e( 'Product', 'wp-license-manager' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="wplm_product_search"
									class="wplm-id-search regular-text"
									data-type="product"
									data-hidden="wplm_product_id_val"
									autocomplete="off"
									value="<?php echo esc_attr( $current_product_label ); ?>"
									placeholder="<?php esc_attr_e( 'Type to search products…', 'wp-license-manager' ); ?>"
								>
								<input type="hidden" id="wplm_product_id_val" name="product_id" value="<?php echo esc_attr( (string) $current_product_id ); ?>">
								<p class="description"><?php esc_html_e( 'Search by name, or type the numeric ID directly and press Tab.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wplm_order_search"><?php esc_html_e( 'Order', 'wp-license-manager' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="wplm_order_search"
									class="wplm-id-search regular-text"
									data-type="order"
									data-hidden="wplm_order_id_val"
									autocomplete="off"
									value="<?php echo esc_attr( $current_order_label ); ?>"
									placeholder="<?php esc_attr_e( 'Type to search orders…', 'wp-license-manager' ); ?>"
								>
								<input type="hidden" id="wplm_order_id_val" name="order_id" value="<?php echo esc_attr( (string) $current_order_id ); ?>">
								<p class="description"><?php esc_html_e( 'Search by order #, billing name, or email.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wplm_user_search"><?php esc_html_e( 'User', 'wp-license-manager' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="wplm_user_search"
									class="wplm-id-search regular-text"
									data-type="user"
									data-hidden="wplm_user_id_val"
									autocomplete="off"
									value="<?php echo esc_attr( $current_user_label ); ?>"
									placeholder="<?php esc_attr_e( 'Type to search users…', 'wp-license-manager' ); ?>"
								>
								<input type="hidden" id="wplm_user_id_val" name="user_id" value="<?php echo esc_attr( (string) $current_user_id ); ?>">
								<p class="description"><?php esc_html_e( 'Search by display name, username, or email.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wplm_max_activations"><?php esc_html_e( 'Max Activations', 'wp-license-manager' ); ?></label>
							</th>
							<td>
								<input
									type="number"
									id="wplm_max_activations"
									name="max_activations"
									value="<?php echo esc_attr( (string) $current_max_act ); ?>"
									class="small-text"
									min="1"
								>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wplm_expires_at"><?php esc_html_e( 'Expires At', 'wp-license-manager' ); ?></label>
							</th>
							<td>
								<input
									type="date"
									id="wplm_expires_at"
									name="expires_at"
									value="<?php echo esc_attr( $is_edit && $license && $license->expires_at ? substr( $license->expires_at, 0, 10 ) : '' ); ?>"
									class="regular-text"
								>
								<p class="description"><?php esc_html_e( 'Leave blank for a perpetual (never-expiring) license.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wplm_grace_days"><?php esc_html_e( 'Grace Days', 'wp-license-manager' ); ?></label>
							</th>
							<td>
								<input
									type="number"
									id="wplm_grace_days"
									name="grace_days"
									value="<?php echo esc_attr( (string) ( $is_edit && $license ? $license->grace_days : 0 ) ); ?>"
									class="small-text"
									min="0"
								>
								<p class="description"><?php esc_html_e( 'Days after expiry during which the license continues to validate.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wplm_overage_strategy"><?php esc_html_e( 'Overage Strategy', 'wp-license-manager' ); ?></label>
							</th>
							<td>
								<select id="wplm_overage_strategy" name="overage_strategy">
									<?php foreach ( $overage_options as $val => $label ) : ?>
										<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current_overage, $val ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Floating License', 'wp-license-manager' ); ?></th>
							<td>
								<label for="wplm_is_floating">
									<input
										type="checkbox"
										id="wplm_is_floating"
										name="is_floating"
										value="1"
										<?php checked( $is_edit && $license ? (bool) $license->is_floating : false ); ?>
									>
									<?php esc_html_e( 'Enable floating-seat mode (seats freed automatically on heartbeat timeout).', 'wp-license-manager' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="wplm_valid_for_days"><?php esc_html_e( 'Valid For (days)', 'wp-license-manager' ); ?></label>
							</th>
							<td>
								<input
									type="number"
									id="wplm_valid_for_days"
									name="valid_for_days"
									value="<?php echo esc_attr( (string) ( $is_edit && $license ? ( $license->valid_for_days ?? '' ) : '' ) ); ?>"
									class="small-text"
									min="1"
								>
								<p class="description"><?php esc_html_e( 'When set, expiry is computed from first activation. Overrides the Expires At date.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>

					</tbody>
				</table>

				<?php
				submit_button(
					$is_edit
						? __( 'Update License', 'wp-license-manager' )
						: __( 'Create License', 'wp-license-manager' )
				);
				?>
			</form>
		</div>
		<?php
	}
}
