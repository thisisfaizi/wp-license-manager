<?php
/**
 * WP_List_Table implementation for the Generators admin screen.
 *
 * @package WPLM\Admin\Screens
 */

namespace WPLM\Admin\Screens;

defined( 'ABSPATH' ) || exit;

use WPLM\Repositories\GeneratorRepository;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Paginated list of license key generators, plus an add/edit form.
 */
class GeneratorListTable extends \WP_List_Table {

	/** @var GeneratorRepository */
	private GeneratorRepository $repo;

	/**
	 * @param GeneratorRepository $repo
	 */
	public function __construct( GeneratorRepository $repo ) {
		$this->repo = $repo;
		parent::__construct(
			array(
				'singular' => 'generator',
				'plural'   => 'generators',
				'ajax'     => false,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Column definitions
	// -------------------------------------------------------------------------

	public function get_columns(): array {
		return array(
			'cb'           => '<input type="checkbox">',
			'name'         => __( 'Name', 'wp-license-manager' ),
			'preview'      => __( 'Pattern Preview', 'wp-license-manager' ),
			'prefix'       => __( 'Prefix', 'wp-license-manager' ),
			'chunks'       => __( 'Chunks', 'wp-license-manager' ),
			'chunk_length' => __( 'Chunk Length', 'wp-license-manager' ),
			'separator'    => __( 'Separator', 'wp-license-manager' ),
			'created_at'   => __( 'Created', 'wp-license-manager' ),
		);
	}

	public function get_sortable_columns(): array {
		return array(
			'name'       => array( 'name', false ),
			'created_at' => array( 'created_at', true ),
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
			case 'preview':
				$sep    = esc_html( $item->separator ?: '-' );
				$chunk  = str_repeat( 'X', (int) $item->chunk_length );
				$chunks = implode( $sep, array_fill( 0, max( 1, (int) $item->chunks ), $chunk ) );
				$prefix = $item->prefix ? esc_html( $item->prefix ) . $sep : '';
				return '<code>' . $prefix . $chunks . '</code>';

			case 'prefix':
				return $item->prefix ? esc_html( $item->prefix ) : '&mdash;';

			case 'chunks':
				return esc_html( (string) $item->chunks );

			case 'chunk_length':
				return esc_html( (string) $item->chunk_length );

			case 'separator':
				return $item->separator ? '<code>' . esc_html( $item->separator ) . '</code>' : '&mdash;';

			case 'created_at':
				if ( empty( $item->created_at ) ) {
					return '&mdash;';
				}
				return esc_html( wp_date( get_option( 'date_format' ), strtotime( $item->created_at ) ) );
		}
		return '&mdash;';
	}

	public function column_name( $item ): string {
		$id       = (int) $item->id;
		$edit_url = admin_url( 'admin.php?page=wplm-generators&action=edit&id=' . $id );
		$del_url  = wp_nonce_url(
			admin_url( 'admin.php?page=wplm-generators&action=delete&id=' . $id ),
			'wplm_delete_generator_' . $id
		);

		$title = sprintf(
			'<a href="%s" class="row-title"><strong>%s</strong></a>',
			esc_url( $edit_url ),
			esc_html( $item->name ?: '#' . $id )
		);

		$actions = array(
			'edit'   => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit', 'wp-license-manager' )
			),
			'delete' => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $del_url ),
				esc_js( __( 'Delete this generator? Existing licenses will be unaffected.', 'wp-license-manager' ) ),
				esc_html__( 'Delete', 'wp-license-manager' )
			),
		);

		return $title . $this->row_actions( $actions );
	}

	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="generator[]" value="%d">', (int) $item->id );
	}

	// -------------------------------------------------------------------------
	// Data loading — loads all, paginates in PHP (generators are few)
	// -------------------------------------------------------------------------

	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'wplm_generators_per_page', 20 );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

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

		// Handle delete.
		if ( 'delete' === $action && $id > 0 ) {
			check_admin_referer( 'wplm_delete_generator_' . $id );
			$this->repo->delete( $id );
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-generators&deleted=1' ) );
			exit;
		}

		// Bulk delete.
		if ( 'bulk-delete' === $this->current_action() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
			$ids = array_map( 'absint', (array) ( $_REQUEST['generator'] ?? array() ) );
			foreach ( $ids as $gid ) {
				if ( $gid > 0 ) {
					$this->repo->delete( $gid );
				}
			}
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-generators&deleted=' . count( $ids ) ) );
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
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Generators', 'wp-license-manager' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wplm-generators&action=add' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'wp-license-manager' ); ?>
			</a>
			<hr class="wp-header-end">
			<?php
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $_GET['saved'] ) ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Generator saved.', 'wp-license-manager' ); ?></p></div>
				<?php
			endif;
			if ( ! empty( $_GET['deleted'] ) ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Generator(s) deleted.', 'wp-license-manager' ); ?></p></div>
				<?php
			endif;
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			?>
			<form method="get">
				<input type="hidden" name="page" value="wplm-generators">
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
		$id   = absint( $_GET['id'] ?? 0 );
		$gen  = ( $id > 0 ) ? $this->repo->find_by_id( $id ) : null;
		$edit = null !== $gen;

		$title = $edit
			? __( 'Edit Generator', 'wp-license-manager' )
			: __( 'Add New Generator', 'wp-license-manager' );

		$v = function ( string $field, $default = '' ) use ( $gen, $edit ) {
			if ( ! $edit || null === $gen ) {
				return $default;
			}
			return $gen->$field ?? $default;
		};
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $title ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wplm-generators' ) ); ?>">&larr; <?php esc_html_e( 'Back to Generators', 'wp-license-manager' ); ?></a></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wplm_save_generator', 'wplm_generator_nonce' ); ?>
				<input type="hidden" name="action" value="wplm_save_generator">
				<?php if ( $edit ) : ?>
					<input type="hidden" name="generator_id" value="<?php echo esc_attr( (string) $id ); ?>">
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="wplm_gen_name"><?php esc_html_e( 'Name', 'wp-license-manager' ); ?></label></th>
							<td>
								<input type="text" id="wplm_gen_name" name="gen_name" value="<?php echo esc_attr( (string) $v( 'name' ) ); ?>" class="regular-text" required>
								<p class="description"><?php esc_html_e( 'Friendly identifier shown in the license edit form.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wplm_gen_prefix"><?php esc_html_e( 'Prefix', 'wp-license-manager' ); ?></label></th>
							<td>
								<input type="text" id="wplm_gen_prefix" name="gen_prefix" value="<?php echo esc_attr( (string) $v( 'prefix' ) ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Optional string prepended to every key (e.g. ACME-).', 'wp-license-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wplm_gen_suffix"><?php esc_html_e( 'Suffix', 'wp-license-manager' ); ?></label></th>
							<td>
								<input type="text" id="wplm_gen_suffix" name="gen_suffix" value="<?php echo esc_attr( (string) $v( 'suffix' ) ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Optional string appended to every key.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wplm_gen_chunks"><?php esc_html_e( 'Chunks', 'wp-license-manager' ); ?></label></th>
							<td>
								<input type="number" id="wplm_gen_chunks" name="gen_chunks" value="<?php echo esc_attr( (string) $v( 'chunks', 4 ) ); ?>" class="small-text" min="1" max="20">
								<p class="description"><?php esc_html_e( 'Number of character groups separated by the separator.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wplm_gen_chunk_length"><?php esc_html_e( 'Chunk Length', 'wp-license-manager' ); ?></label></th>
							<td>
								<input type="number" id="wplm_gen_chunk_length" name="gen_chunk_length" value="<?php echo esc_attr( (string) $v( 'chunk_length', 4 ) ); ?>" class="small-text" min="1" max="32">
								<p class="description"><?php esc_html_e( 'Characters per chunk.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wplm_gen_separator"><?php esc_html_e( 'Separator', 'wp-license-manager' ); ?></label></th>
							<td>
								<input type="text" id="wplm_gen_separator" name="gen_separator" value="<?php echo esc_attr( (string) $v( 'separator', '-' ) ); ?>" class="small-text" maxlength="5">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wplm_gen_charset"><?php esc_html_e( 'Character Set', 'wp-license-manager' ); ?></label></th>
							<td>
								<input type="text" id="wplm_gen_charset" name="gen_charset" value="<?php echo esc_attr( (string) $v( 'charset', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789' ) ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Pool of characters used to fill each chunk.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wplm_gen_max_activations"><?php esc_html_e( 'Default Max Activations', 'wp-license-manager' ); ?></label></th>
							<td>
								<input type="number" id="wplm_gen_max_activations" name="gen_default_max_activations" value="<?php echo esc_attr( (string) $v( 'default_max_activations', '' ) ); ?>" class="small-text" min="1">
								<p class="description"><?php esc_html_e( 'Pre-fills Max Activations when creating a license with this generator. Leave blank to use the global default.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wplm_gen_valid_days"><?php esc_html_e( 'Default Valid-for Days', 'wp-license-manager' ); ?></label></th>
							<td>
								<input type="number" id="wplm_gen_valid_days" name="gen_default_valid_days" value="<?php echo esc_attr( (string) $v( 'default_valid_days', '' ) ); ?>" class="small-text" min="1">
								<p class="description"><?php esc_html_e( 'Pre-fills "Valid for N days from activation" on licenses. Leave blank for none.', 'wp-license-manager' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php
				submit_button(
					$edit
						? __( 'Update Generator', 'wp-license-manager' )
						: __( 'Create Generator', 'wp-license-manager' )
				);
				?>
			</form>
		</div>
		<?php
	}
}
