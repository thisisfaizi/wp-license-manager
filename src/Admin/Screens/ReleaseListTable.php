<?php
/**
 * WP_List_Table implementation for the Releases admin screen.
 *
 * @package WPLM\Admin\Screens
 */

namespace WPLM\Admin\Screens;

defined( 'ABSPATH' ) || exit;

use WPLM\Repositories\ReleaseRepository;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Paginated, filterable list of software releases, plus an add/edit form.
 */
class ReleaseListTable extends \WP_List_Table {

	/** @var ReleaseRepository */
	private ReleaseRepository $repo;

	/**
	 * Translated release-channel labels keyed by channel slug.
	 *
	 * @return array<string,string>
	 */
	private function channel_labels(): array {
		return array(
			'stable' => __( 'Stable', 'wp-license-manager' ),
			'beta'   => __( 'Beta', 'wp-license-manager' ),
			'rc'     => __( 'Release Candidate', 'wp-license-manager' ),
		);
	}

	/**
	 * @param ReleaseRepository $repo
	 */
	public function __construct( ReleaseRepository $repo ) {
		$this->repo = $repo;
		parent::__construct(
			array(
				'singular' => 'release',
				'plural'   => 'releases',
				'ajax'     => false,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Column definitions
	// -------------------------------------------------------------------------

	public function get_columns(): array {
		return array(
			'cb'          => '<input type="checkbox">',
			'version'     => __( 'Version', 'wp-license-manager' ),
			'product_id'  => __( 'Product', 'wp-license-manager' ),
			'channel'     => __( 'Channel', 'wp-license-manager' ),
			'file_path'   => __( 'File / URL', 'wp-license-manager' ),
			'released_at' => __( 'Released', 'wp-license-manager' ),
		);
	}

	public function get_sortable_columns(): array {
		return array(
			'version'     => array( 'version', false ),
			'product_id'  => array( 'product_id', false ),
			'channel'     => array( 'channel', false ),
			'released_at' => array( 'released_at', true ),
		);
	}

	public function get_bulk_actions(): array {
		return array(
			'bulk-delete' => __( 'Delete', 'wp-license-manager' ),
		);
	}

	// -------------------------------------------------------------------------
	// Cell renderers
	// -------------------------------------------------------------------------

	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'product_id':
				$pid = (int) ( $item->product_id ?? 0 );
				if ( ! $pid ) {
					return '&mdash;';
				}
				$title = get_the_title( $pid );
				$link  = get_edit_post_link( $pid );
				return $link
					? sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $title ?: '#' . $pid ) )
					: esc_html( '#' . $pid );

			case 'channel':
				$ch     = (string) ( $item->channel ?? 'stable' );
				$labels = $this->channel_labels();
				$label  = $labels[ $ch ] ?? esc_html( ucfirst( $ch ) );
				return sprintf(
					'<span class="wplm-badge wplm-channel-%s">%s</span>',
					esc_attr( $ch ),
					esc_html( $label )
				);

			case 'file_path':
				$path = (string) ( $item->file_path ?? '' );
				if ( ! $path ) {
					return '&mdash;';
				}
				return sprintf(
					'<a href="%s" target="_blank" rel="noopener">%s</a>',
					esc_url( $path ),
					esc_html( basename( $path ) )
				);

			case 'released_at':
				if ( empty( $item->released_at ) ) {
					return '&mdash;';
				}
				return esc_html( wp_date( get_option( 'date_format' ), strtotime( $item->released_at ) ) );
		}
		return '&mdash;';
	}

	public function column_version( $item ): string {
		$id       = (int) $item->id;
		$edit_url = admin_url( 'admin.php?page=wplm-releases&action=edit&id=' . $id );
		$del_url  = wp_nonce_url(
			admin_url( 'admin.php?page=wplm-releases&action=delete&id=' . $id ),
			'wplm_delete_release_' . $id
		);

		$title = sprintf(
			'<a href="%s" class="row-title"><strong>%s</strong></a>',
			esc_url( $edit_url ),
			esc_html( $item->version ?: 'v?' )
		);

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'wp-license-manager' ) ),
			'delete' => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $del_url ),
				esc_js( __( 'Delete this release?', 'wp-license-manager' ) ),
				esc_html__( 'Delete', 'wp-license-manager' )
			),
		);

		return $title . $this->row_actions( $actions );
	}

	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="release[]" value="%d">', (int) $item->id );
	}

	// -------------------------------------------------------------------------
	// Data loading
	// -------------------------------------------------------------------------

	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'wplm_releases_per_page', 20 );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged   = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$orderby = sanitize_key( $_GET['orderby'] ?? 'released_at' );
		$order   = strtoupper( sanitize_key( $_GET['order'] ?? 'DESC' ) );
		$channel = sanitize_text_field( wp_unslash( $_GET['wplm_channel'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$args = array(
			'per_page' => $per_page,
			'page'     => $paged,
			'orderby'  => $orderby,
			'order'    => $order,
		);
		if ( '' !== $channel ) {
			$args['channel'] = $channel;
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
	// Table nav — channel filter
	// -------------------------------------------------------------------------

	public function display_tablenav( $which ): void {
		parent::display_tablenav( $which );
		if ( 'top' !== $which ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = sanitize_key( $_GET['wplm_channel'] ?? '' );
		?>
		<div class="wplm-status-filter alignleft actions">
			<select name="wplm_channel">
				<option value=""><?php esc_html_e( 'All channels', 'wp-license-manager' ); ?></option>
				<?php foreach ( $this->channel_labels() as $ch => $label ) : ?>
					<option value="<?php echo esc_attr( $ch ); ?>" <?php selected( $current, $ch ); ?>>
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

		// Handle delete.
		if ( 'delete' === $action && $id > 0 ) {
			check_admin_referer( 'wplm_delete_release_' . $id );
			$this->repo->delete( $id );
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-releases&deleted=1' ) );
			exit;
		}

		// Bulk delete.
		if ( 'bulk-delete' === $this->current_action() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
			$ids = array_map( 'absint', (array) ( $_REQUEST['release'] ?? array() ) );
			foreach ( $ids as $rid ) {
				if ( $rid > 0 ) {
					$this->repo->delete( $rid );
				}
			}
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-releases&deleted=' . count( $ids ) ) );
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
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Releases', 'wp-license-manager' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wplm-releases&action=add' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'wp-license-manager' ); ?>
			</a>
			<hr class="wp-header-end">
			<?php
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $_GET['saved'] ) ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Release saved.', 'wp-license-manager' ); ?></p></div>
				<?php
			endif;
			if ( ! empty( $_GET['deleted'] ) ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Release(s) deleted.', 'wp-license-manager' ); ?></p></div>
				<?php
			endif;
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			?>
			<form method="get">
				<input type="hidden" name="page" value="wplm-releases">
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
		$release = ( $id > 0 ) ? $this->repo->find_by_id( $id ) : null;
		$edit    = null !== $release;

		$v = function ( string $field, $default = '' ) use ( $release, $edit ) {
			if ( ! $edit || null === $release ) {
				return $default;
			}
			return $release->$field ?? $default;
		};
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $edit ? __( 'Edit Release', 'wp-license-manager' ) : __( 'Add New Release', 'wp-license-manager' ) ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wplm-releases' ) ); ?>">&larr; <?php esc_html_e( 'Back to Releases', 'wp-license-manager' ); ?></a></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wplm_save_release', 'wplm_release_nonce' ); ?>
				<input type="hidden" name="action" value="wplm_save_release">
				<?php if ( $edit ) : ?>
					<input type="hidden" name="release_id" value="<?php echo esc_attr( (string) $id ); ?>">
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="wplm_rel_product_id"><?php esc_html_e( 'Product ID', 'wp-license-manager' ); ?></label></th>
							<td>
								<input type="number" id="wplm_rel_product_id" name="rel_product_id" value="<?php echo esc_attr( (string) $v( 'product_id', '' ) ); ?>" class="regular-text" min="1">
								<p class="description"><?php esc_html_e( 'WooCommerce product ID this release belongs to.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wplm_rel_version"><?php esc_html_e( 'Version', 'wp-license-manager' ); ?></label></th>
							<td>
								<input type="text" id="wplm_rel_version" name="rel_version" value="<?php echo esc_attr( (string) $v( 'version' ) ); ?>" class="regular-text" placeholder="1.2.3" required>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wplm_rel_channel"><?php esc_html_e( 'Channel', 'wp-license-manager' ); ?></label></th>
							<td>
								<select id="wplm_rel_channel" name="rel_channel">
									<?php foreach ( $this->channel_labels() as $ch => $label ) : ?>
										<option value="<?php echo esc_attr( $ch ); ?>" <?php selected( (string) $v( 'channel', 'stable' ), $ch ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wplm_rel_file_path"><?php esc_html_e( 'Download URL', 'wp-license-manager' ); ?></label></th>
							<td>
								<input type="url" id="wplm_rel_file_path" name="rel_file_path" value="<?php echo esc_attr( (string) $v( 'file_path' ) ); ?>" class="large-text">
								<p class="description"><?php esc_html_e( 'Direct URL to the package file. License validation is enforced server-side before the redirect.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wplm_rel_file_hash"><?php esc_html_e( 'SHA-256 Hash', 'wp-license-manager' ); ?></label></th>
							<td>
								<input type="text" id="wplm_rel_file_hash" name="rel_file_hash" value="<?php echo esc_attr( (string) $v( 'file_hash' ) ); ?>" class="large-text" placeholder="e3b0c44...">
								<p class="description"><?php esc_html_e( 'Optional: SHA-256 hex digest for client-side integrity checks.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wplm_rel_min_app_version"><?php esc_html_e( 'Min App Version', 'wp-license-manager' ); ?></label></th>
							<td>
								<input type="text" id="wplm_rel_min_app_version" name="rel_min_app_version" value="<?php echo esc_attr( (string) $v( 'min_app_version' ) ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Minimum client version required to install this release. Leave blank for any.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wplm_rel_changelog"><?php esc_html_e( 'Changelog', 'wp-license-manager' ); ?></label></th>
							<td>
								<textarea id="wplm_rel_changelog" name="rel_changelog" rows="8" class="large-text"><?php echo esc_textarea( (string) $v( 'changelog' ) ); ?></textarea>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( $edit ? __( 'Update Release', 'wp-license-manager' ) : __( 'Publish Release', 'wp-license-manager' ) ); ?>
			</form>
		</div>
		<?php
	}
}
