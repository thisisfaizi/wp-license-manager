<?php
/**
 * The plan editor (M5-27a §2): a plan sells a licence profile, and each licence type (package) carries
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

	public function test_a_super_ledger_plan_saves_each_licence_type_template(): void {
		$result = $this->actions()->save_plan(
			array(
				'plan_name'    => 'Super Ledger Distribution',
				'plan_status'  => '1',
				'plan_profile' => 'super-ledger',
				'packages'     => array(
					$this->package_row( 'Distribution monthly', array( 'base', 'distribution' ), array( 'users' => '3', 'phones' => '2', 'seats' => '' ) ),
					$this->package_row( 'Extra phone', array(), array( 'phones' => '1' ) ),
				),
			)
		);

		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertSame( 'super-ledger', $this->make( PlanService::class )->get( $result['plan_id'] )->profile );
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
				'plan_profile' => 'super-ledger',
				'packages'     => array( $this->package_row( 'POS lifetime', array( 'pos' ), array(), array( 'billing_type' => 'lifetime' ) ) ),
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( 'Base', $result['warnings'][0] );
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
				'plan_profile' => 'super-ledger',
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

	public function test_an_unknown_profile_or_a_blank_name_is_refused(): void {
		$this->assertFalse( $this->actions()->save_plan( array( 'plan_name' => 'X', 'plan_profile' => 'nope' ) )['ok'] );
		$this->assertFalse( $this->actions()->save_plan( array( 'plan_name' => '  ' ) )['ok'] );
	}

	public function test_the_editor_shows_the_profile_and_each_licence_types_modules(): void {
		$saved = $this->actions()->save_plan(
			array(
				'plan_name'    => 'Super Ledger POS',
				'plan_profile' => 'super-ledger',
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

		$this->assertMatchesRegularExpression( '/<option value="super-ledger"\s+selected/', $html );
		$this->assertMatchesRegularExpression( '/name="packages\[0\]\[entitlements\]\[modules\]\[\]" value="pos"\s+checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="packages\[0\]\[entitlements\]\[modules\]\[\]" value="fbr"\s+checked/', $html );
		$this->assertMatchesRegularExpression( '/name="packages\[0\]\[entitlements\]\[limits\]\[users\]" value="2"/', $html );
		$this->assertStringContainsString( 'packages[__INDEX__][entitlements][modules][]', $html, 'New licence types get the template fields too.' );
	}
}
