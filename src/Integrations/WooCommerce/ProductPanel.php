<?php
/**
 * WooCommerce product panel — licensing + subscription fields on the product edit screen.
 *
 * @package WPLM\Integrations\WooCommerce
 */

namespace WPLM\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Licensing" tab to the WooCommerce product data meta box.
 *
 * Two mutually exclusive modes:
 *   - Subscription Plan assigned (_wplm_plan_id): the product sells the plan's
 *     packages; the simple licensing fields are hidden.
 *   - No plan: simple one-time licensing (key source, generator, seats, expiry,
 *     overage) issues a single license key on purchase.
 */
class ProductPanel {

	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_license_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_license_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_license_meta' ) );
	}

	// -------------------------------------------------------------------------
	// Tab registration
	// -------------------------------------------------------------------------

	/** Add the Licensing tab to the product data panel. */
	public function add_license_tab( array $tabs ): array {
		$tabs['wplm_license'] = array(
			'label'    => __( 'Licensing', 'wp-license-manager' ),
			'target'   => 'wplm_license_data',
			'class'    => array(),
			'priority' => 70,
		);
		return $tabs;
	}

	// -------------------------------------------------------------------------
	// Panel render
	// -------------------------------------------------------------------------

	/** Render the licensing panel (core licensing fields only). */
	public function render_license_panel(): void {
		echo '<div id="wplm_license_data" class="panel woocommerce_options_panel">';

		// ---- Core licensing ----
		echo '<div class="options_group">';

		// Subscription Plan assignment (recommended). When a plan is selected the
		// product sells the plan's packages and the fields below are ignored.
		woocommerce_wp_select(
			array(
				'id'          => '_wplm_plan_id',
				'label'       => __( 'Subscription Plan', 'wp-license-manager' ),
				'description' => __( 'Assign a reusable plan. Customers choose one of its license types on the product page. Leave as “None” to use the simple licensing fields below.', 'wp-license-manager' ),
				'desc_tip'    => true,
				'options'     => $this->get_plan_options(),
			)
		);

		echo '</div><div class="options_group wplm-simple-licensing">';

		woocommerce_wp_checkbox(
			array(
				'id'          => '_wplm_is_licensed',
				'label'       => __( 'Enable Licensing', 'wp-license-manager' ),
				'description' => __( 'Issue a license key when this product is purchased (for products NOT using a plan).', 'wp-license-manager' ),
			)
		);

		woocommerce_wp_select(
			array(
				'id'      => '_wplm_key_source',
				'label'   => __( 'Key Source', 'wp-license-manager' ),
				'options' => array(
					'generator' => __( 'Generate from generator', 'wp-license-manager' ),
					'pool'      => __( 'Draw from existing pool', 'wp-license-manager' ),
				),
			)
		);

		woocommerce_wp_select(
			array(
				'id'          => '_wplm_generator_id',
				'label'       => __( 'Generator', 'wp-license-manager' ),
				'description' => __( 'The WPLM generator used to produce keys for this product.', 'wp-license-manager' ),
				'options'     => $this->get_generator_options(),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_wplm_max_activations',
				'label'             => __( 'Max Activations (seats)', 'wp-license-manager' ),
				'description'       => __( 'Leave empty to use the generator default.', 'wp-license-manager' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '1' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_wplm_valid_for_days',
				'label'             => __( 'Valid for (days)', 'wp-license-manager' ),
				'description'       => __( 'Days from first activation. Leave empty for no relative expiry.', 'wp-license-manager' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '0' ),
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'          => '_wplm_single_key_multi_seat',
				'label'       => __( 'Single key, quantity = seats', 'wp-license-manager' ),
				'description' => __( 'Issue one key with max activations = order quantity, instead of one key per unit.', 'wp-license-manager' ),
			)
		);

		woocommerce_wp_select(
			array(
				'id'      => '_wplm_overage_strategy',
				'label'   => __( 'Overage Strategy', 'wp-license-manager' ),
				'options' => array(
					''            => __( '— Use global default —', 'wp-license-manager' ),
					'deny'        => __( 'Deny (hard limit)', 'wp-license-manager' ),
					'allow_1_25x' => __( 'Allow 1.25× seats', 'wp-license-manager' ),
					'allow_2x'    => __( 'Allow 2× seats', 'wp-license-manager' ),
				),
			)
		);

		echo '</div>'; // .options_group

		echo '</div>'; // #wplm_license_data

		// Hide the simple-licensing fields when a subscription plan is assigned
		// (the plan's packages then define billing + licensing).
		?>
		<script>
		(function($){
			function wplmToggleSimpleLicensing(){
				var hasPlan = $('#_wplm_plan_id').val() && $('#_wplm_plan_id').val() !== '0';
				$('.wplm-simple-licensing').toggle( ! hasPlan );
			}
			$(function(){
				$('#_wplm_plan_id').on('change', wplmToggleSimpleLicensing);
				wplmToggleSimpleLicensing();
			});
		})(jQuery);
		</script>
		<?php
	}

	/**
	 * Build the Generator <select> options: id => "Name (#id)".
	 *
	 * @return array<int|string, string>
	 */
	private function get_generator_options(): array {
		$options = array( '' => __( '— Select a generator —', 'wp-license-manager' ) );

		try {
			$repo       = \WPLM\Plugin::get_instance()->container()->make( \WPLM\Repositories\GeneratorRepository::class );
			$generators = $repo->get_all();
			foreach ( $generators as $generator ) {
				/* translators: 1: generator name, 2: generator id */
				$options[ $generator->id ] = sprintf( '%1$s (#%2$d)', $generator->name, $generator->id );
			}
		} catch ( \Throwable $e ) {
			// Fall through to the empty option if the container/table is unavailable.
			unset( $e );
		}

		if ( 1 === count( $options ) ) {
			$options[''] = __( '— No generators yet — create one under Licenses → Generators —', 'wp-license-manager' );
		}

		return $options;
	}

	/**
	 * Build the Subscription Plan <select> options: id => "Plan name".
	 *
	 * @return array<int|string, string>
	 */
	private function get_plan_options(): array {
		$options = array( '' => __( '— None (no subscription plan) —', 'wp-license-manager' ) );

		try {
			$service = \WPLM\Plugin::get_instance()->container()->make( \WPLM\Services\PlanService::class );
			foreach ( $service->get_all( true ) as $plan ) {
				$count = count( $plan->packages );
				$options[ $plan->id ] = sprintf(
					/* translators: 1: plan name, 2: number of license types */
					_n( '%1$s (%2$d license type)', '%1$s (%2$d license types)', $count, 'wp-license-manager' ),
					$plan->name,
					$count
				);
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		return $options;
	}

	// -------------------------------------------------------------------------
	// Save
	// -------------------------------------------------------------------------

	/** Save all licensing + subscription meta when the product is saved. */
	public function save_license_meta( int $post_id ): void {
		// WooCommerce verifies this nonce before firing woocommerce_process_product_meta;
		// re-checking here makes the guard explicit and satisfies static analysis.
		if ( ! isset( $_POST['woocommerce_meta_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' )
		) {
			return;
		}

		// Subscription plan assignment.
		update_post_meta(
			$post_id,
			'_wplm_plan_id',
			absint( $_POST['_wplm_plan_id'] ?? 0 )
		);

		// Core licensing meta.
		update_post_meta(
			$post_id,
			'_wplm_is_licensed',
			isset( $_POST['_wplm_is_licensed'] ) ? 'yes' : 'no'
		);

		$fields_text = array( '_wplm_key_source', '_wplm_overage_strategy' );
		foreach ( $fields_text as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		$max = absint( $_POST['_wplm_max_activations'] ?? 0 );
		update_post_meta( $post_id, '_wplm_max_activations', $max ?: '' );

		$days = absint( $_POST['_wplm_valid_for_days'] ?? 0 );
		update_post_meta( $post_id, '_wplm_valid_for_days', $days ?: '' );

		update_post_meta(
			$post_id,
			'_wplm_generator_id',
			absint( $_POST['_wplm_generator_id'] ?? 0 )
		);

		update_post_meta(
			$post_id,
			'_wplm_single_key_multi_seat',
			isset( $_POST['_wplm_single_key_multi_seat'] ) ? 'yes' : 'no'
		);
		// phpcs:enable
	}
}
