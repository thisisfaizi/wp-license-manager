<?php
/**
 * Admin screen for subscription Plans and their Packages.
 *
 * @package WPLM\Admin\Screens
 */

namespace WPLM\Admin\Screens;

defined( 'ABSPATH' ) || exit;

use WPLM\Repositories\GeneratorRepository;
use WPLM\Services\PlanService;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists subscription plans and renders the plan editor (plan fields + a
 * repeatable set of package rows). Plans are reusable and assigned to any
 * native WooCommerce product from the product Licensing tab.
 */
class PlanListTable extends \WP_List_Table {

	/** @var PlanService */
	private PlanService $service;

	/** @var GeneratorRepository */
	private GeneratorRepository $generator_repo;

	/**
	 * @param PlanService         $service        Plan business logic.
	 * @param GeneratorRepository $generator_repo Generator data-access (for package generator select).
	 */
	public function __construct( PlanService $service, GeneratorRepository $generator_repo ) {
		$this->service        = $service;
		$this->generator_repo = $generator_repo;
		parent::__construct(
			array(
				'singular' => 'plan',
				'plural'   => 'plans',
				'ajax'     => false,
			)
		);
	}

	// -------------------------------------------------------------------------
	// List table
	// -------------------------------------------------------------------------

	/** @return array<string,string> */
	public function get_columns(): array {
		return array(
			'name'     => __( 'Plan', 'wp-license-manager' ),
			'packages' => __( 'License Types', 'wp-license-manager' ),
			'status'   => __( 'Status', 'wp-license-manager' ),
			'id'       => __( 'ID', 'wp-license-manager' ),
		);
	}

	/**
	 * @param object $item   Plan model.
	 * @param string $column Column slug.
	 * @return string
	 */
	public function column_default( $item, $column ): string {
		switch ( $column ) {
			case 'status':
				return 1 === (int) $item->status
					? '<span style="color:#00a32a;">' . esc_html__( 'Active', 'wp-license-manager' ) . '</span>'
					: '<span style="color:#72777c;">' . esc_html__( 'Inactive', 'wp-license-manager' ) . '</span>';
			case 'id':
				return (string) (int) $item->id;
			default:
				return '';
		}
	}

	/**
	 * @param object $item Plan model.
	 * @return string
	 */
	public function column_packages( $item ): string {
		$names = array_map( static fn( $p ) => $p->name, $item->packages );
		$names = array_filter( $names );
		return $names ? esc_html( implode( ', ', $names ) ) : '<em>' . esc_html__( 'No license types yet', 'wp-license-manager' ) . '</em>';
	}

	/**
	 * @param object $item Plan model.
	 * @return string
	 */
	public function column_name( $item ): string {
		$edit_url   = admin_url( 'admin.php?page=wplm-plans&action=edit&id=' . (int) $item->id );
		$delete_url = wp_nonce_url(
			admin_url( 'admin.php?page=wplm-plans&action=delete&id=' . (int) $item->id ),
			'wplm_delete_plan_' . (int) $item->id
		);

		$actions = array(
			'edit'   => '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'wp-license-manager' ) . '</a>',
			'delete' => '<a href="' . esc_url( $delete_url ) . '" style="color:#d63638;" onclick="return confirm(\'' . esc_js( __( 'Delete this plan and all its license types?', 'wp-license-manager' ) ) . '\');">' . esc_html__( 'Delete', 'wp-license-manager' ) . '</a>',
		);

		return '<strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $item->name ) . '</a></strong>' . $this->row_actions( $actions );
	}

	/** Load plans into the table. */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = $this->service->get_all();
	}

	// -------------------------------------------------------------------------
	// Routing
	// -------------------------------------------------------------------------

	/** Handle inline delete then render the list or the editor. */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-license-manager' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );
		$id     = absint( $_GET['id'] ?? 0 );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'delete' === $action && $id > 0 ) {
			check_admin_referer( 'wplm_delete_plan_' . $id );
			$this->service->delete_plan( $id );
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-plans&deleted=1' ) );
			exit;
		}

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$this->render_edit_form( $id );
		} else {
			$this->render_list();
		}
	}

	/** Render the plans list. */
	private function render_list(): void {
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Subscription Plans', 'wp-license-manager' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wplm-plans&action=add' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'wp-license-manager' ); ?></a>
			<hr class="wp-header-end">
			<p class="description"><?php esc_html_e( 'Plans are reusable. Create a plan with one or more license types (Monthly, Yearly, Lifetime, benefit-based), then assign the plan to any WooCommerce product on its Licensing tab.', 'wp-license-manager' ); ?></p>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $_GET['saved'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Plan saved.', 'wp-license-manager' ) . '</p></div>';
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $_GET['deleted'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Plan deleted.', 'wp-license-manager' ) . '</p></div>';
			}
			$this->prepare_items();
			$this->display();
			?>
		</div>
		<?php
	}

	/**
	 * Render the add/edit plan form.
	 *
	 * @param int $id Plan id (0 for a new plan).
	 * @return void
	 */
	private function render_edit_form( int $id ): void {
		$plan       = $id > 0 ? $this->service->get( $id ) : null;
		$name       = $plan ? $plan->name : '';
		$desc       = $plan && $plan->description ? $plan->description : '';
		$status     = $plan ? (int) $plan->status : 1;
		$packages   = $plan ? $plan->packages : array();
		$generators = $this->generator_repo->get_all();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $id > 0 ? __( 'Edit Plan', 'wp-license-manager' ) : __( 'Add Plan', 'wp-license-manager' ) ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wplm_save_plan">
				<input type="hidden" name="plan_id" value="<?php echo esc_attr( (string) $id ); ?>">
				<?php wp_nonce_field( 'wplm_save_plan' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wplm_plan_name"><?php esc_html_e( 'Plan name', 'wp-license-manager' ); ?></label></th>
						<td><input name="plan_name" id="wplm_plan_name" type="text" class="regular-text" value="<?php echo esc_attr( $name ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="wplm_plan_desc"><?php esc_html_e( 'Description', 'wp-license-manager' ); ?></label></th>
						<td><textarea name="plan_description" id="wplm_plan_desc" class="large-text" rows="2"><?php echo esc_textarea( $desc ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'wp-license-manager' ); ?></th>
						<td><label><input type="checkbox" name="plan_status" value="1" <?php checked( 1, $status ); ?>> <?php esc_html_e( 'Active', 'wp-license-manager' ); ?></label></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'License Types', 'wp-license-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Each license type is a purchasable tier with its own price and rules. For Lifetime / one-time license types the billing period is ignored.', 'wp-license-manager' ); ?></p>

				<div id="wplm-packages">
					<?php
					if ( empty( $packages ) ) {
						$this->render_package_row( null, '__INDEX__', $generators, false );
						$this->render_package_row( $this->blank_package(), 0, $generators, true );
					} else {
						$this->render_package_row( null, '__INDEX__', $generators, false );
						foreach ( $packages as $i => $pkg ) {
							$this->render_package_row( $pkg, $i, $generators, true );
						}
					}
					?>
				</div>

				<p><button type="button" class="button" id="wplm-add-package"><?php esc_html_e( '+ Add license type', 'wp-license-manager' ); ?></button></p>

				<?php submit_button( __( 'Save Plan', 'wp-license-manager' ) ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wplm-plans' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Cancel', 'wp-license-manager' ); ?></a>
			</form>
		</div>

		<script>
		(function(){
			var wrap = document.getElementById('wplm-packages');
			var tpl  = document.getElementById('wplm-package-tpl');
			var n    = <?php echo (int) max( 1, count( $packages ) ); ?>;
			document.getElementById('wplm-add-package').addEventListener('click', function(){
				var html = tpl.innerHTML.replace(/__INDEX__/g, n);
				var div  = document.createElement('div');
				div.innerHTML = html;
				wrap.appendChild(div.firstElementChild);
				n++;
			});
			wrap.addEventListener('click', function(e){
				if ( e.target && e.target.classList.contains('wplm-remove-package') ) {
					e.preventDefault();
					var row = e.target.closest('.wplm-package-row');
					if ( row ) { row.parentNode.removeChild(row); }
				}
			});
		})();
		</script>
		<?php
	}

	/** A blank package object for the first empty row. */
	private function blank_package() {
		return \WPLM\Models\Package::from_row( array() );
	}

	/**
	 * Render one package row (or the hidden template when $visible is false).
	 *
	 * @param \WPLM\Models\Package|null $pkg        Package or null for the template.
	 * @param int|string                $index      Row index (or __INDEX__ placeholder).
	 * @param array                     $generators Generator models for the select.
	 * @param bool                      $visible    Whether this is a real row or the hidden template.
	 * @return void
	 */
	private function render_package_row( $pkg, $index, array $generators, bool $visible ): void {
		$p = $pkg ?: \WPLM\Models\Package::from_row( array() );
		$f = 'packages[' . $index . ']';

		if ( ! $visible ) {
			echo '<script type="text/template" id="wplm-package-tpl">';
		}
		?>
		<div class="wplm-package-row" style="border:1px solid #dcdcde;border-radius:4px;padding:12px;margin-bottom:12px;background:#fff;">
			<input type="hidden" name="<?php echo esc_attr( $f ); ?>[id]" value="<?php echo esc_attr( (string) $p->id ); ?>">
			<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
				<p style="flex:2 1 200px;">
					<label><strong><?php esc_html_e( 'Name', 'wp-license-manager' ); ?></strong><br>
					<input type="text" class="widefat" name="<?php echo esc_attr( $f ); ?>[name]" value="<?php echo esc_attr( $p->name ); ?>" placeholder="<?php esc_attr_e( 'e.g. Monthly', 'wp-license-manager' ); ?>"></label>
				</p>
				<p style="flex:1 1 130px;">
					<label><strong><?php esc_html_e( 'Type', 'wp-license-manager' ); ?></strong><br>
					<select class="widefat wplm-pkg-type" name="<?php echo esc_attr( $f ); ?>[billing_type]">
						<option value="recurring" <?php selected( 'recurring', $p->billing_type ); ?>><?php esc_html_e( 'Recurring', 'wp-license-manager' ); ?></option>
						<option value="lifetime" <?php selected( 'lifetime', $p->billing_type ); ?>><?php esc_html_e( 'Lifetime', 'wp-license-manager' ); ?></option>
						<option value="onetime" <?php selected( 'onetime', $p->billing_type ); ?>><?php esc_html_e( 'One-time', 'wp-license-manager' ); ?></option>
					</select></label>
				</p>
				<p style="flex:1 1 120px;">
					<label><strong><?php esc_html_e( 'Price', 'wp-license-manager' ); ?></strong><br>
					<input type="text" class="widefat" name="<?php echo esc_attr( $f ); ?>[price]" value="<?php echo esc_attr( (string) $p->price ); ?>"></label>
				</p>
				<p style="flex:1 1 110px;">
					<label><strong><?php esc_html_e( 'Every', 'wp-license-manager' ); ?></strong><br>
					<input type="number" min="1" class="widefat" name="<?php echo esc_attr( $f ); ?>[billing_interval]" value="<?php echo esc_attr( (string) $p->billing_interval ); ?>"></label>
				</p>
				<p style="flex:1 1 110px;">
					<label><strong><?php esc_html_e( 'Period', 'wp-license-manager' ); ?></strong><br>
					<select class="widefat" name="<?php echo esc_attr( $f ); ?>[billing_period]">
						<option value="day" <?php selected( 'day', $p->billing_period ); ?>><?php esc_html_e( 'Day', 'wp-license-manager' ); ?></option>
						<option value="week" <?php selected( 'week', $p->billing_period ); ?>><?php esc_html_e( 'Week', 'wp-license-manager' ); ?></option>
						<option value="month" <?php selected( 'month', $p->billing_period ); ?>><?php esc_html_e( 'Month', 'wp-license-manager' ); ?></option>
						<option value="year" <?php selected( 'year', $p->billing_period ); ?>><?php esc_html_e( 'Year', 'wp-license-manager' ); ?></option>
					</select></label>
				</p>
			</div>
			<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-top:8px;">
				<p style="flex:1 1 120px;">
					<label><?php esc_html_e( 'Sign-up fee', 'wp-license-manager' ); ?><br>
					<input type="text" class="widefat" name="<?php echo esc_attr( $f ); ?>[signup_fee]" value="<?php echo esc_attr( (string) $p->signup_fee ); ?>"></label>
				</p>
				<p style="flex:1 1 110px;">
					<label><?php esc_html_e( 'Trial days', 'wp-license-manager' ); ?><br>
					<input type="number" min="0" class="widefat" name="<?php echo esc_attr( $f ); ?>[trial_days]" value="<?php echo esc_attr( (string) $p->trial_days ); ?>"></label>
				</p>
				<p style="flex:1 1 110px;">
					<label><?php esc_html_e( 'Length (cycles)', 'wp-license-manager' ); ?><br>
					<input type="number" min="0" class="widefat" name="<?php echo esc_attr( $f ); ?>[length_cycles]" value="<?php echo esc_attr( (string) $p->length_cycles ); ?>"></label>
				</p>
				<p style="flex:1 1 160px;">
					<label><?php esc_html_e( 'Generator', 'wp-license-manager' ); ?><br>
					<select class="widefat" name="<?php echo esc_attr( $f ); ?>[generator_id]">
						<option value=""><?php esc_html_e( '— Plugin default —', 'wp-license-manager' ); ?></option>
						<?php foreach ( $generators as $g ) : ?>
							<option value="<?php echo esc_attr( (string) $g->id ); ?>" <?php selected( $g->id, (int) $p->generator_id ); ?>><?php echo esc_html( $g->name . ' (#' . $g->id . ')' ); ?></option>
						<?php endforeach; ?>
					</select></label>
				</p>
				<p style="flex:1 1 120px;">
					<label><?php esc_html_e( 'Max seats', 'wp-license-manager' ); ?><br>
					<input type="number" min="1" class="widefat" name="<?php echo esc_attr( $f ); ?>[max_activations]" value="<?php echo esc_attr( null !== $p->max_activations ? (string) $p->max_activations : '' ); ?>"></label>
				</p>
			</div>
			<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;margin-top:8px;">
				<p style="flex:2 1 240px;">
					<label><?php esc_html_e( 'Benefits (one per line)', 'wp-license-manager' ); ?><br>
					<textarea class="widefat" rows="3" name="<?php echo esc_attr( $f ); ?>[benefits]"><?php echo esc_textarea( implode( "\n", $p->benefits ) ); ?></textarea></label>
				</p>
				<p style="flex:1 1 120px;">
					<label><input type="checkbox" name="<?php echo esc_attr( $f ); ?>[status]" value="1" <?php checked( 1, (int) $p->status ); ?>> <?php esc_html_e( 'Active', 'wp-license-manager' ); ?></label>
				</p>
				<p style="flex:0 0 auto;margin-left:auto;">
					<button type="button" class="button-link wplm-remove-package" style="color:#d63638;"><?php esc_html_e( 'Remove', 'wp-license-manager' ); ?></button>
				</p>
			</div>
		</div>
		<?php
		if ( ! $visible ) {
			echo '</script>';
		}
	}
}
