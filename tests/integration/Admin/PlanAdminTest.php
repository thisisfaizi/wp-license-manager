<?php
/**
 * The plan editor: a plan sells a licence profile, and each licence type (package) carries
 * the entitlement template a purchase writes onto the licence.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Admin;

use WPLM\Admin\PlanAdminActions;
use WPLM\Admin\Screens\PlanListTable;
use WPLM\Repositories\GeneratorRepository;
use WPLM\Services\PlanService;
use WPLM\Tests\TestCase;

class PlanAdminTest extends TestCase {

	private function actions(): PlanAdminActions {
		return $this->make( PlanAdminActions::class );
	}

	private function package_row( string $name, array $modules, array $limits = array(), array $extra = array() ): array {
		return $extra + array(
			'name'             => $name,
			'billing_type'     => 'recurring',
			'billing_period'   => 'month',
			'billing_interval' => '1',
			'price'            => '5000',
			'status'           => '1',
			'entitlements'     => array(
				'modules' => $modules,
				'limits'  => $limits,
			),
		);
	}

	private function templates( int $plan_id ): array {
		$out = array();
		foreach ( $this->make( PlanService::class )->get( $plan_id )->packages as $package ) {
			$out[ $package->name ] = $package->entitlements;
		}
		return $out;
	}

	public function test_a_profile_plan_saves_each_licence_type_template(): void {
		$result = $this->actions()->save_plan(
			array(
				'plan_name'    => 'Acme Office Distribution',
				'plan_status'  => '1',
				'plan_profile' => 'acme-office',
				'packages'     => array(
					$this->package_row( 'Distribution monthly', array( 'base', 'distribution' ), array( 'users' => '3', 'phones' => '2', 'seats' => '' ) ),
					$this->package_row( 'Extra phone', array(), array( 'phones' => '1' ) ),
				),
			)
		);

		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertSame( 'acme-office', $this->make( PlanService::class )->get( $result['plan_id'] )->profile );
		$this->assertSame(
			array(
				'Distribution monthly' => array(
					array( 'kind' => 'module', 'code' => 'base', 'qty' => 0 ),
					array( 'kind' => 'module', 'code' => 'distribution', 'qty' => 0 ),
					array( 'kind' => 'limit', 'code' => 'users', 'qty' => 3 ),
					array( 'kind' => 'limit', 'code' => 'phones', 'qty' => 2 ),
				),
				'Extra phone'          => array(
					array( 'kind' => 'limit', 'code' => 'phones', 'qty' => 1 ),
				),
			),
			$this->templates( $result['plan_id'] )
		);
		$this->assertSame( array(), $result['warnings'], 'A licence type grants base.' );
	}

	public function test_a_profile_plan_where_nothing_grants_base_is_saved_with_a_warning(): void {
		$result = $this->actions()->save_plan(
			array(
				'plan_name'    => 'POS add-on',
				'plan_profile' => 'acme-office',
				'packages'     => array( $this->package_row( 'POS lifetime', array( 'pos' ), array(), array( 'billing_type' => 'lifetime' ) ) ),
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( 'Base', $result['warnings'][0] );
	}

	public function test_a_profile_without_a_base_module_never_warns_about_one(): void {
		$actions = new PlanAdminActions( $this->make( PlanService::class ), $this->registry_without_base_module(), $this->make( \WPLM\Services\EntitlementService::class ) );

		$result = $actions->save_plan(
			array(
				'plan_name'    => 'POS only',
				'plan_profile' => 'acme-office',
				'packages'     => array( $this->package_row( 'POS lifetime', array( 'pos' ), array(), array( 'billing_type' => 'lifetime' ) ) ),
			)
		);

		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	public function test_an_unknown_code_saves_nothing_not_even_the_profile_change(): void {
		$plan = $this->actions()->save_plan(
			array(
				'plan_name' => 'Classic plan',
				'packages'  => array( $this->package_row( 'Monthly', array() ) ),
			)
		);
		$this->assertTrue( $plan['ok'] );

		$result = $this->actions()->save_plan(
			array(
				'plan_id'      => (string) $plan['plan_id'],
				'plan_name'    => 'Renamed',
				'plan_profile' => 'acme-office',
				'packages'     => array( $this->package_row( 'Monthly', array( 'base', 'payroll' ) ) ),
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'payroll', $result['message'] );
		$stored = $this->make( PlanService::class )->get( $plan['plan_id'] );
		$this->assertNull( $stored->profile );
		$this->assertSame( 'Classic plan', $stored->name );
	}

	public function test_a_classic_plan_ignores_posted_templates(): void {
		$result = $this->actions()->save_plan(
			array(
				'plan_name' => 'Plugin licences',
				'packages'  => array( $this->package_row( 'Yearly', array( 'base' ), array( 'users' => '5' ) ) ),
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'Yearly' => array() ), $this->templates( $result['plan_id'] ) );
	}

	/** Prices are parsed exactly as the old save handler did: WooCommerce's decimal separator decides. */
	public function test_a_classic_plan_price_follows_the_stores_decimal_separator(): void {
		update_option( 'woocommerce_price_decimal_sep', ',' );
		update_option( 'woocommerce_price_thousand_sep', '.' );

		$result = $this->actions()->save_plan(
			array(
				'plan_name' => 'Another product',
				'packages'  => array( $this->package_row( 'Yearly', array(), array(), array( 'price' => '1234,50', 'signup_fee' => '10,25' ) ) ),
			)
		);

		$package = $this->make( PlanService::class )->get( $result['plan_id'] )->packages[0];
		$this->assertSame( (float) wc_format_decimal( '1234,50' ), (float) $package->price );
		$this->assertSame( 1234.5, (float) $package->price );
		$this->assertSame( 10.25, (float) $package->signup_fee );
	}

	public function test_an_unknown_profile_or_a_blank_name_is_refused(): void {
		$this->assertFalse( $this->actions()->save_plan( array( 'plan_name' => 'X', 'plan_profile' => 'nope' ) )['ok'] );
		$this->assertFalse( $this->actions()->save_plan( array( 'plan_name' => '  ' ) )['ok'] );
	}

	public function test_the_editor_shows_the_profile_and_each_licence_types_modules(): void {
		$saved = $this->actions()->save_plan(
			array(
				'plan_name'    => 'Acme Office POS',
				'plan_profile' => 'acme-office',
				'packages'     => array( $this->package_row( 'POS monthly', array( 'base', 'pos' ), array( 'users' => '2' ) ) ),
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['action'] = 'edit';
		$_GET['id']     = (string) $saved['plan_id'];

		ob_start();
		( new PlanListTable( $this->make( PlanService::class ), $this->make( GeneratorRepository::class ), $this->make( \WPLM\Licensing\ProfileRegistry::class ) ) )->render_page();
		$html = (string) ob_get_clean();
		unset( $_GET['action'], $_GET['id'] );

		$this->assertMatchesRegularExpression( '/<option value="acme-office"\s+selected/', $html );
		$this->assertMatchesRegularExpression( '/name="packages\[0\]\[entitlements\]\[modules\]\[\]" value="pos"\s+checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="packages\[0\]\[entitlements\]\[modules\]\[\]" value="fbr"\s+checked/', $html );
		$this->assertMatchesRegularExpression( '/name="packages\[0\]\[entitlements\]\[limits\]\[users\]" value="2"/', $html );
		$this->assertStringContainsString( 'packages[__INDEX__][entitlements][modules][]', $html, 'New licence types get the template fields too.' );
	}

	// ---------------------------------------------------------------------------------------------
	// The form per billing type (1.2.5).
	// ---------------------------------------------------------------------------------------------

	/** The stored package rows of a plan, keyed by name. */
	private function packages( int $plan_id ): array {
		$out = array();
		foreach ( $this->make( PlanService::class )->get( $plan_id )->packages as $package ) {
			$out[ $package->name ] = $package;
		}
		return $out;
	}

	/** Render the plan editor for a plan, optionally with a given profile registry. */
	private function editor( int $plan_id, ?\WPLM\Licensing\ProfileRegistry $registry = null ): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['action'] = 'edit';
		$_GET['id']     = (string) $plan_id;

		ob_start();
		( new PlanListTable( $this->make( PlanService::class ), $this->make( GeneratorRepository::class ), $registry ?? $this->make( \WPLM\Licensing\ProfileRegistry::class ) ) )->render_page();
		$html = (string) ob_get_clean();
		unset( $_GET['action'], $_GET['id'] );
		return $html;
	}

	/** The HTML of one visible package row (0-based), cut from the editor. */
	private function row_html( string $html, int $index ): string {
		$start = strpos( $html, 'name="packages[' . $index . '][id]"' );
		$this->assertNotFalse( $start, "Row {$index} is rendered." );
		$end = strpos( $html, 'name="packages[' . ( $index + 1 ) . '][id]"', $start );
		return substr( $html, $start, false === $end ? null : $end - $start );
	}

	public function test_a_lifetime_type_drops_every_recurring_field_even_when_they_are_not_posted(): void {
		$result = $this->actions()->save_plan(
			array(
				'plan_name' => 'Lifetimes',
				'packages'  => array(
					// Posted with stale recurring values (an old browser, or the API).
					array(
						'name'             => 'Posted',
						'billing_type'     => 'lifetime',
						'billing_interval' => '3',
						'billing_period'   => 'year',
						'price'            => '25000',
						'signup_fee'       => '500',
						'trial_days'       => '14',
						'length_cycles'    => '6',
						'valid_for_days'   => '30',
						'status'           => '1',
					),
					// What the editor posts now: the hidden fields are disabled, so their keys are missing.
					array(
						'name'         => 'Missing',
						'billing_type' => 'lifetime',
						'price'        => '25000',
						'status'       => '1',
					),
				),
			)
		);

		$this->assertTrue( $result['ok'], $result['message'] );
		foreach ( $this->packages( $result['plan_id'] ) as $name => $package ) {
			$this->assertSame( 'lifetime', $package->billing_type, $name );
			$this->assertSame( 1, $package->billing_interval, $name );
			$this->assertSame( 'month', $package->billing_period, $name );
			$this->assertSame( 0.0, $package->signup_fee, $name );
			$this->assertSame( 0, $package->trial_days, $name );
			$this->assertSame( 0, $package->length_cycles, $name );
			$this->assertNull( $package->valid_for_days, $name );
			$this->assertSame( 25000.0, $package->price, $name );
		}
		$this->assertSame( array(), $result['warnings'] );
	}

	public function test_a_recurring_type_keeps_its_schedule_and_drops_a_term(): void {
		$result = $this->actions()->save_plan(
			array(
				'plan_name' => 'Yearly',
				'packages'  => array(
					$this->package_row(
						'Yearly',
						array(),
						array(),
						array(
							'billing_period'   => 'year',
							'billing_interval' => '2',
							'signup_fee'       => '1000',
							'trial_days'       => '7',
							'length_cycles'    => '3',
							'valid_for_days'   => '30',
						)
					),
				),
			)
		);

		$package = $this->packages( $result['plan_id'] )['Yearly'];
		$this->assertSame( 'year', $package->billing_period );
		$this->assertSame( 2, $package->billing_interval );
		$this->assertSame( 1000.0, $package->signup_fee );
		$this->assertSame( 7, $package->trial_days );
		$this->assertSame( 3, $package->length_cycles );
		$this->assertNull( $package->valid_for_days, 'Only a one-time type has a term.' );
	}

	public function test_a_one_time_type_keeps_its_days_and_a_blank_term_is_kept_with_a_warning(): void {
		$result = $this->actions()->save_plan(
			array(
				'plan_name'    => 'Acme Office one-time',
				'plan_profile' => 'acme-office',
				'packages'     => array(
					$this->package_row( 'Year pass', array( 'base' ), array(), array( 'billing_type' => 'onetime', 'valid_for_days' => '365', 'trial_days' => '9' ) ),
					$this->package_row( 'Lifetime', array( 'base' ), array(), array( 'billing_type' => 'onetime', 'valid_for_days' => '' ) ),
				),
			)
		);

		$this->assertTrue( $result['ok'], $result['message'] );
		$packages = $this->packages( $result['plan_id'] );
		$this->assertSame( 365, $packages['Year pass']->valid_for_days );
		$this->assertSame( 0, $packages['Year pass']->trial_days );
		$this->assertNull( $packages['Lifetime']->valid_for_days, 'A blank term is kept: the row still grants for life.' );

		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( 'Lifetime: One-time needs "Valid for (days)"', $result['warnings'][0] );
		$this->assertStringContainsString( 'choose Lifetime instead', $result['warnings'][0] );
	}

	public function test_the_editor_shows_only_the_fields_of_each_rows_type(): void {
		$saved = $this->actions()->save_plan(
			array(
				'plan_name'    => 'Acme Office types',
				'plan_profile' => 'acme-office',
				'packages'     => array(
					$this->package_row( 'Monthly', array( 'base' ) ),
					$this->package_row( 'Lifetime', array( 'base' ), array(), array( 'billing_type' => 'lifetime' ) ),
					$this->package_row( 'Year pass', array( 'base' ), array(), array( 'billing_type' => 'onetime', 'valid_for_days' => '365' ) ),
				),
			)
		);
		$html = $this->editor( $saved['plan_id'] );

		$this->assertStringContainsString( 'One-time (fixed term)', $html );
		$this->assertStringContainsString( 'Computers per licence key', $html );
		$this->assertStringNotContainsString( 'Max seats', $html );
		$this->assertStringContainsString( 'Product limits such as users or phones are set in the box below', $html );

		$recurring_fields = array( 'billing_interval', 'billing_period', 'signup_fee', 'trial_days', 'length_cycles' );

		// Recurring: schedule fields live, the term hidden and disabled.
		$monthly = $this->row_html( $html, 0 );
		foreach ( $recurring_fields as $field ) {
			$this->assertMatchesRegularExpression( '/class="wplm-recurring-only">\s*<label[^>]*>[^<]*(<strong>[^<]*<\/strong>)?<br>\s*<(input|select)[^>]*name="packages\[0\]\[' . $field . '\]"(?![^>]*disabled)[^>]*>/', $monthly, "Recurring shows {$field}." );
		}
		$this->assertMatchesRegularExpression( '/class="wplm-onetime-only"\s*hidden>.*?name="packages\[0\]\[valid_for_days\]"[^>]*disabled/s', $monthly );

		// Lifetime: every schedule field and the term hidden and disabled.
		$lifetime = $this->row_html( $html, 1 );
		foreach ( $recurring_fields as $field ) {
			$this->assertMatchesRegularExpression( '/class="wplm-recurring-only"\s*hidden>\s*<label[^>]*>.*?name="packages\[1\]\[' . $field . '\]"[^>]*disabled/s', $lifetime, "Lifetime hides {$field}." );
		}
		$this->assertMatchesRegularExpression( '/name="packages\[1\]\[valid_for_days\]"[^>]*disabled/', $lifetime );

		// One-time on a profile plan: the term is shown (no longer classic-only), the schedule is not.
		$onetime = $this->row_html( $html, 2 );
		$this->assertMatchesRegularExpression( '/class="wplm-onetime-only">\s*<label[^>]*>[^<]*<br>\s*<input[^>]*name="packages\[2\]\[valid_for_days\]"[^>]*value="365"(?![^>]*disabled)[^>]*>/', $onetime );
		$this->assertMatchesRegularExpression( '/name="packages\[2\]\[signup_fee\]"[^>]*disabled/', $onetime );
		// Grace stays classic-only.
		$this->assertMatchesRegularExpression( '/class="wplm-classic-only"\s*hidden>\s*<label[^>]*>[^<]*<br>\s*<input[^>]*name="packages\[2\]\[grace_days\]"/', $onetime );

		// The script toggles rows on load, on a type change and after "+ Add license type".
		$this->assertStringContainsString( "classList.contains('wplm-pkg-type')", $html );
		$this->assertStringContainsString( 'applyAll();', $html );
	}

	public function test_a_profile_can_keep_a_module_off_licence_types_but_not_off_a_licence(): void {
		add_filter(
			'wplm_profile_template_module_codes',
			static function ( array $codes, \WPLM\Licensing\Profile $profile ): array {
				return 'acme-office' === $profile->code ? array_values( array_diff( $codes, array( 'fbr' ) ) ) : $codes;
			},
			10,
			2
		);
		$saved = $this->actions()->save_plan(
			array(
				'plan_name'    => 'Acme Office POS',
				'plan_profile' => 'acme-office',
				'packages'     => array( $this->package_row( 'POS monthly', array( 'base', 'pos' ) ) ),
			)
		);

		$html = $this->editor( $saved['plan_id'] );
		$this->assertStringNotContainsString( '[entitlements][modules][]" value="fbr"', $html, 'Not on the licence-type checkboxes.' );
		$this->assertStringContainsString( '[entitlements][modules][]" value="pos"', $html );

		// The licence's own screen still offers it as a line.
		set_current_screen( 'dashboard' );
		$bound          = $this->activated_profile_licence();
		$_GET['action'] = 'edit';
		$_GET['id']     = (string) $bound['license']->id;
		ob_start();
		( new \WPLM\Admin\Screens\LicenseListTable( $this->make( \WPLM\Repositories\LicenseRepository::class ) ) )->render_page();
		$licence = (string) ob_get_clean();
		unset( $_GET['action'], $_GET['id'] );
		$this->assertStringContainsString( 'value="module:fbr"', $licence );
	}

	public function test_template_excluded_codes_hide_a_module_unless_the_row_already_grants_it(): void {
		$saved = $this->actions()->save_plan(
			array(
				'plan_name'    => 'Acme Office legacy',
				'plan_profile' => 'acme-office',
				'packages'     => array(
					$this->package_row( 'Old row', array( 'base', 'fbr' ) ),
					$this->package_row( 'New row', array( 'base' ) ),
				),
			)
		);

		add_filter(
			'wplm_license_profiles',
			static function ( array $profiles ): array {
				$p     = $profiles['acme-office'];
				$fresh = new \WPLM\Licensing\Profile( $p->code, $p->label, $p->module_codes, $p->limit_codes, array(), $p->base_module );

				$fresh->template_excluded_codes = array( 'fbr' );
				$profiles['acme-office']        = $fresh;
				return $profiles;
			},
			20
		);
		$registry = new \WPLM\Licensing\ProfileRegistry();
		$this->assertSame( array( 'base', 'distribution', 'pos', 'factory', 'assets', 'subcontract' ), $registry->get( 'acme-office' )->template_module_codes() );
		$this->assertTrue( $registry->get( 'acme-office' )->allows( 'module', 'fbr' ), 'Still a valid code.' );

		$html = $this->editor( $saved['plan_id'], $registry );
		$this->assertMatchesRegularExpression( '/name="packages\[0\]\[entitlements\]\[modules\]\[\]" value="fbr"\s+checked/', $this->row_html( $html, 0 ), 'A row that grants it keeps it, so a re-save does not drop it.' );
		$this->assertStringNotContainsString( 'value="fbr"', $this->row_html( $html, 1 ) );
		$this->assertStringNotContainsString( 'packages[__INDEX__][entitlements][modules][]" value="fbr"', $html );
	}

	// ---------------------------------------------------------------------------------------------
	// Add-on plans (1.2.6)
	// ---------------------------------------------------------------------------------------------

	public function test_an_addon_plan_is_saved_as_one_with_no_base_warning_and_no_key_fields(): void {
		$result = $this->actions()->save_plan(
			array(
				'plan_name'    => 'Acme add-ons',
				'plan_profile' => 'acme-office',
				'plan_addon'   => '1',
				'packages'     => array(
					$this->package_row(
						'FBR monthly',
						array( 'fbr' ),
						array(),
						array(
							'signup_fee'      => '500',
							'trial_days'      => '14',
							'length_cycles'   => '12',
							'generator_id'    => '3',
							'max_activations' => '5',
							'grace_days'      => '9',
						)
					),
				),
			)
		);

		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertSame( array(), $result['warnings'], 'An add-on plan is not expected to grant base.' );
		$plan = $this->make( PlanService::class )->get( $result['plan_id'] );
		$this->assertTrue( $plan->is_addon() );
		$this->assertSame( 'addon', $plan->kind );

		$fbr = $this->packages( $result['plan_id'] )['FBR monthly'];
		$this->assertSame( 0.0, $fbr->signup_fee, 'Paid in full now: no sign-up fee.' );
		$this->assertSame( 0, $fbr->trial_days );
		$this->assertSame( 0, $fbr->length_cycles );
		$this->assertNull( $fbr->generator_id, 'No key is issued.' );
		$this->assertNull( $fbr->max_activations );
		$this->assertNull( $fbr->grace_days );
		$this->assertSame( 'month', $fbr->billing_period, 'Its schedule is kept: it must match the plan it joins.' );
	}

	public function test_an_addon_type_that_grants_base_is_saved_with_a_warning(): void {
		$result = $this->actions()->save_plan(
			array(
				'plan_name'    => 'Acme add-ons',
				'plan_profile' => 'acme-office',
				'plan_addon'   => '1',
				'packages'     => array( $this->package_row( 'Oops', array( 'base', 'fbr' ) ) ),
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( 'Oops grants Base (accounting)', $result['warnings'][0] );
	}

	public function test_an_addon_plan_needs_a_profile(): void {
		$result = $this->actions()->save_plan(
			array(
				'plan_name'  => 'Classic add-ons',
				'plan_addon' => '1',
				'packages'   => array( $this->package_row( 'Thing', array() ) ),
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 0, $result['plan_id'], 'Nothing was saved.' );

		$this->expectException( \InvalidArgumentException::class );
		$this->make( PlanService::class )->create_plan(
			array(
				'name' => 'Classic add-ons',
				'kind' => 'addon',
			)
		);
	}

	public function test_a_plan_saved_before_kinds_existed_reads_as_standalone(): void {
		$result = $this->actions()->save_plan(
			array(
				'plan_name'    => 'Acme Office',
				'plan_profile' => 'acme-office',
				'packages'     => array( $this->package_row( 'Monthly', array( 'base' ) ) ),
			)
		);

		$plan = $this->make( PlanService::class )->get( $result['plan_id'] );
		$this->assertFalse( $plan->is_addon() );
		$this->assertSame( 'standalone', $plan->kind );
	}

	public function test_the_editor_hides_key_fields_on_an_addon_plan_and_offers_every_module(): void {
		$addon = $this->actions()->save_plan(
			array(
				'plan_name'    => 'Acme add-ons',
				'plan_profile' => 'acme-office',
				'plan_addon'   => '1',
				'packages'     => array( $this->package_row( 'FBR monthly', array( 'fbr' ) ) ),
			)
		);
		$plain = $this->actions()->save_plan(
			array(
				'plan_name'    => 'Acme Office',
				'plan_profile' => 'acme-office',
				'packages'     => array( $this->package_row( 'Monthly', array( 'base' ) ) ),
			)
		);
		add_filter(
			'wplm_profile_template_module_codes',
			static fn( array $codes ) => array_values( array_diff( $codes, array( 'assets' ) ) )
		);

		$html = $this->editor( $addon['plan_id'] );
		$row  = $this->row_html( $html, 0 );
		$this->assertMatchesRegularExpression( '/id="wplm_plan_addon"[^>]*checked/', $html );
		$this->assertStringContainsString( 'This plan sells add-ons to an existing licence', $html );
		$this->assertMatchesRegularExpression( '/data-wplm-standalone-only\s*hidden>\s*<label>[^<]*<br>\s*<select[^>]*name="packages\[0\]\[generator_id\]"[^>]*disabled/', $row, 'No generator: no key is issued.' );
		$this->assertMatchesRegularExpression( '/name="packages\[0\]\[max_activations\]"[^>]*disabled/', $row, 'No computers per key.' );
		foreach ( array( 'signup_fee', 'trial_days', 'length_cycles' ) as $field ) {
			$this->assertMatchesRegularExpression( '/name="packages\[0\]\[' . $field . '\]"[^>]*disabled/', $row, "No {$field}." );
		}
		$this->assertMatchesRegularExpression( '/name="packages\[0\]\[billing_period\]"(?![^>]*disabled)/', $row, 'The schedule stays: it must match the plan.' );
		$this->assertStringContainsString( '[entitlements][modules][]" value="assets"', $row, 'An add-on may sell a module kept off licence types.' );

		$plain_html = $this->editor( $plain['plan_id'] );
		$this->assertStringNotContainsString( '[entitlements][modules][]" value="assets"', $plain_html );
		$this->assertMatchesRegularExpression( '/name="packages\[0\]\[generator_id\]"(?![^>]*disabled)/', $this->row_html( $plain_html, 0 ) );
	}
}
