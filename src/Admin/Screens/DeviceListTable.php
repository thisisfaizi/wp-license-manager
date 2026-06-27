<?php
/**
 * WP_List_Table implementation for the Devices (Machines) admin screen.
 *
 * @package WPLM\Admin\Screens
 */

namespace WPLM\Admin\Screens;

defined( 'ABSPATH' ) || exit;

use WPLM\Repositories\MachineRepository;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Paginated, filterable list of activated machines/devices.
 */
class DeviceListTable extends \WP_List_Table {

	/** @var MachineRepository */
	private MachineRepository $repo;

	/**
	 * Translated device-status labels keyed by status code.
	 *
	 * @return array<int,string>
	 */
	private function status_labels(): array {
		return array(
			1 => __( 'Active', 'wp-license-manager' ),
			2 => __( 'Deactivated', 'wp-license-manager' ),
			3 => __( 'Revoked', 'wp-license-manager' ),
		);
	}

	/**
	 * @param MachineRepository $repo
	 */
	public function __construct( MachineRepository $repo ) {
		$this->repo = $repo;
		parent::__construct(
			array(
				'singular' => 'device',
				'plural'   => 'devices',
				'ajax'     => false,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Column definitions
	// -------------------------------------------------------------------------

	public function get_columns(): array {
		return array(
			'cb'                => '<input type="checkbox">',
			'id'                => __( 'ID', 'wp-license-manager' ),
			'license_id'        => __( 'License', 'wp-license-manager' ),
			'label'             => __( 'Label / Hostname', 'wp-license-manager' ),
			'status'            => __( 'Status', 'wp-license-manager' ),
			'fingerprint'       => __( 'Fingerprint', 'wp-license-manager' ),
			'ip_address'        => __( 'IP Address', 'wp-license-manager' ),
			'last_heartbeat_at' => __( 'Last Heartbeat', 'wp-license-manager' ),
		);
	}

	public function get_sortable_columns(): array {
		return array(
			'id'                => array( 'id', true ),
			'license_id'        => array( 'license_id', false ),
			'status'            => array( 'status', false ),
			'last_heartbeat_at' => array( 'last_heartbeat_at', false ),
		);
	}

	public function get_bulk_actions(): array {
		return array(
			'bulk-deactivate' => __( 'Deactivate', 'wp-license-manager' ),
			'bulk-revoke'     => __( 'Revoke', 'wp-license-manager' ),
		);
	}

	// -------------------------------------------------------------------------
	// Cell renderers
	// -------------------------------------------------------------------------

	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'license_id':
				$lid = (int) ( $item->license_id ?? 0 );
				if ( ! $lid ) {
					return '&mdash;';
				}
				return sprintf(
					'<a href="%s">#%d</a>',
					esc_url( admin_url( 'admin.php?page=wplm-licenses&action=edit&id=' . $lid ) ),
					$lid
				);

			case 'label':
				$label    = (string) ( $item->label ?? '' );
				$hostname = (string) ( $item->hostname ?? '' );
				$display  = $label ?: $hostname;
				return $display ? esc_html( $display ) : '<em>' . esc_html__( 'Unknown', 'wp-license-manager' ) . '</em>';

			case 'status':
				$code   = (int) ( $item->status ?? 1 );
				$labels = $this->status_labels();
				$label  = $labels[ $code ] ?? __( 'Unknown', 'wp-license-manager' );
				return sprintf(
					'<span class="wplm-badge wplm-device-%d">%s</span>',
					$code,
					esc_html( $label )
				);

			case 'fingerprint':
				$fp = (string) ( $item->fingerprint ?? '' );
				if ( ! $fp ) {
					return '&mdash;';
				}
				return '<code>' . esc_html( substr( $fp, 0, 8 ) ) . '&hellip;</code>';

			case 'ip_address':
				$ip = (string) ( $item->ip_address ?? '' );
				return $ip ? esc_html( $ip ) : '&mdash;';

			case 'last_heartbeat_at':
				if ( empty( $item->last_heartbeat_at ) ) {
					return '&mdash;';
				}
				return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item->last_heartbeat_at ) ) );
		}
		return '&mdash;';
	}

	public function column_id( $item ): string {
		$id     = (int) $item->id;
		$status = (int) ( $item->status ?? 1 );

		$actions = array();

		if ( 1 === $status ) {
			$deact_url             = wp_nonce_url(
				admin_url( 'admin.php?page=wplm-devices&action=deactivate&id=' . $id ),
				'wplm_deactivate_device_' . $id
			);
			$actions['deactivate'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $deact_url ),
				esc_html__( 'Deactivate', 'wp-license-manager' )
			);
		}

		if ( 2 === $status ) {
			$react_url              = wp_nonce_url(
				admin_url( 'admin.php?page=wplm-devices&action=reactivate&id=' . $id ),
				'wplm_reactivate_device_' . $id
			);
			$actions['reactivate'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $react_url ),
				esc_html__( 'Reactivate', 'wp-license-manager' )
			);
		}

		if ( 3 !== $status ) {
			$revoke_url        = wp_nonce_url(
				admin_url( 'admin.php?page=wplm-devices&action=revoke&id=' . $id ),
				'wplm_revoke_device_' . $id
			);
			$actions['revoke'] = sprintf(
				'<a href="%s" class="wplm-action-revoke" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $revoke_url ),
				esc_js( __( 'Permanently revoke this device?', 'wp-license-manager' ) ),
				esc_html__( 'Revoke', 'wp-license-manager' )
			);
		}

		return '<strong>#' . esc_html( (string) $id ) . '</strong>' . $this->row_actions( $actions );
	}

	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="device[]" value="%d">', (int) $item->id );
	}

	// -------------------------------------------------------------------------
	// Data loading
	// -------------------------------------------------------------------------

	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'wplm_devices_per_page', 20 );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged      = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$orderby    = sanitize_key( $_GET['orderby'] ?? 'id' );
		$order      = strtoupper( sanitize_key( $_GET['order'] ?? 'DESC' ) );
		$status_raw = isset( $_GET['wplm_device_status'] ) ? sanitize_text_field( wp_unslash( $_GET['wplm_device_status'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$args = array(
			'per_page' => $per_page,
			'page'     => $paged,
			'orderby'  => $orderby,
			'order'    => $order,
		);
		if ( '' !== $status_raw ) {
			$args['status'] = (int) $status_raw;
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
		$current = isset( $_GET['wplm_device_status'] ) && '' !== $_GET['wplm_device_status']
			? (int) $_GET['wplm_device_status']
			: '';
		?>
		<div class="wplm-status-filter alignleft actions">
			<select name="wplm_device_status">
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

		if ( 'deactivate' === $action && $id > 0 ) {
			check_admin_referer( 'wplm_deactivate_device_' . $id );
			$this->repo->deactivate( $id );
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-devices&deactivated=1' ) );
			exit;
		}

		if ( 'reactivate' === $action && $id > 0 ) {
			check_admin_referer( 'wplm_reactivate_device_' . $id );
			if ( $this->repo->reactivate( $id ) ) {
				// Mirror the seat increment on the parent license.
				$machine = $this->repo->find_by_id( $id );
				if ( $machine ) {
					$c = \WPLM\Plugin::get_instance()->container();
					$c->make( \WPLM\Repositories\LicenseRepository::class )->increment_activation_count( $machine->license_id );
				}
			}
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-devices&reactivated=1' ) );
			exit;
		}

		if ( 'revoke' === $action && $id > 0 ) {
			check_admin_referer( 'wplm_revoke_device_' . $id );
			$this->repo->revoke( $id );
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-devices&revoked=1' ) );
			exit;
		}

		// Bulk actions.
		$bulk = $this->current_action();
		if ( in_array( $bulk, array( 'bulk-deactivate', 'bulk-revoke' ), true ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
			$ids = array_map( 'absint', (array) ( $_REQUEST['device'] ?? array() ) );
			foreach ( $ids as $did ) {
				if ( $did > 0 ) {
					if ( 'bulk-deactivate' === $bulk ) {
						$this->repo->deactivate( $did );
					} else {
						$this->repo->revoke( $did );
					}
				}
			}
			$param = 'bulk-deactivate' === $bulk ? 'deactivated' : 'revoked';
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-devices&' . $param . '=' . count( $ids ) ) );
			exit;
		}

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Devices', 'wp-license-manager' ); ?></h1>
			<hr class="wp-header-end">
			<?php
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $_GET['deactivated'] ) ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Device(s) deactivated.', 'wp-license-manager' ); ?></p></div>
				<?php
			endif;
			if ( ! empty( $_GET['reactivated'] ) ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Device reactivated.', 'wp-license-manager' ); ?></p></div>
				<?php
			endif;
			if ( ! empty( $_GET['revoked'] ) ) :
				?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Device(s) revoked.', 'wp-license-manager' ); ?></p></div>
				<?php
			endif;
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			?>
			<form method="get">
				<input type="hidden" name="page" value="wplm-devices">
				<?php
				$this->prepare_items();
				$this->display();
				?>
			</form>
		</div>
		<?php
	}
}
