<?php
/**
 * My Account → Licenses: a customer sees their licence's computers and, for an entitlement licence,
 * what is paid for and a Move button that frees a computer (counted against the move limit).
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Account;

use WPLM\Integrations\WooCommerce\MyAccountLicences;
use WPLM\Licensing\CheckInService;
use WPLM\Repositories\MachineRepository;
use WPLM\Services\EntitlementService;
use WPLM\Services\LicenseService;
use WPLM\Tests\TestCase;

class MyAccountLicencesTest extends TestCase {

	private function account(): MyAccountLicences {
		return $this->make( MyAccountLicences::class );
	}

	/** An entitlement licence owned by a new customer, activated on one computer. */
	private function owned( string $raw = 'office-pc', int $seats = 1 ): array {
		$bound    = $this->activated_profile_licence( $raw );
		$customer = $this->create_customer();
		$this->set_row(
			'licenses',
			$bound['license']->id,
			array(
				'user_id'         => $customer,
				'max_activations' => $seats,
			)
		);
		$bound['customer'] = $customer;
		return $this->reload( $bound );
	}

	private function machine_status( int $id ): int {
		return $this->make( MachineRepository::class )->find_by_id( $id )->status;
	}

	private function view( int $user_id, int $license_id ): string {
		wp_set_current_user( $user_id );
		ob_start();
		$this->account()->render_view( $user_id, $license_id );
		return (string) ob_get_clean();
	}

	public function test_the_owner_moves_a_computer_off_their_licence(): void {
		$bound = $this->owned();

		$result = $this->account()->move( $bound['customer'], $bound['license']->id, $bound['machine']->id );

		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertSame( 2, $this->machine_status( $bound['machine']->id ) );
		$this->assertSame( 1, $this->make( CheckInService::class )->moves_left( $this->reload( $bound )['license'] ) );
	}

	public function test_nobody_else_can_move_it_and_a_foreign_computer_is_refused(): void {
		$mine     = $this->owned( 'pc-a' );
		$stranger = $this->create_customer();
		$other    = $this->owned( 'pc-b' );

		$this->assertFalse( $this->account()->move( $stranger, $mine['license']->id, $mine['machine']->id )['ok'] );
		$this->assertFalse( $this->account()->move( $mine['customer'], $mine['license']->id, $other['machine']->id )['ok'] );

		$this->assertSame( 1, $this->machine_status( $mine['machine']->id ) );
		$this->assertSame( 1, $this->machine_status( $other['machine']->id ) );
	}

	public function test_a_third_move_in_30_days_is_refused_with_when_it_frees_up(): void {
		$bound   = $this->owned( 'pc-1', 3 );
		$service = $this->make( \WPLM\Services\ActivationService::class );
		$second  = $service->activate( $bound['license']->license_key, $this->client_fp( 'pc-2' ) );
		$third   = $service->activate( $bound['license']->license_key, $this->client_fp( 'pc-3' ) );

		$this->assertTrue( $this->account()->move( $bound['customer'], $bound['license']->id, $bound['machine']->id )['ok'] );
		$this->assertTrue( $this->account()->move( $bound['customer'], $bound['license']->id, $second->id )['ok'] );
		$result = $this->account()->move( $bound['customer'], $bound['license']->id, $third->id );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( wp_date( (string) get_option( 'date_format' ), time() + 30 * DAY_IN_SECONDS ), $result['message'] );
		$this->assertSame( 1, $this->machine_status( $third->id ) );
	}

	public function test_a_classic_licence_has_no_move_button_to_press(): void {
		$customer = $this->create_customer();
		$license  = $this->make( LicenseService::class )->create(
			array(
				'key_string' => 'CLASSIC-' . wp_generate_password( 8, false ),
				'user_id'    => $customer,
			)
		);
		$machine = $this->make( \WPLM\Services\ActivationService::class )->activate( $license->license_key, hash( 'sha256', 'classic-pc' ) );

		$this->assertFalse( $this->account()->move( $customer, $license->id, $machine->id )['ok'] );
		$this->assertStringNotContainsString( 'wplm_move_machine', $this->view( $customer, $license->id ) );
	}

	public function test_the_owner_sees_computers_modules_and_moves(): void {
		$bound = $this->owned();
		$this->make( EntitlementService::class )->add_line( $bound['license']->id, array( 'kind' => 'module', 'code' => 'pos', 'paid_through' => EntitlementService::add_period( wp_date( 'Y-m-d' ), 20, 'day' ) ) );
		$this->set_row( 'machines', $bound['machine']->id, array( 'name' => 'Counter PC' ) );

		$html = $this->view( $bound['customer'], $bound['license']->id );

		$this->assertStringContainsString( 'Counter PC', $html );
		$this->assertStringContainsString( 'Point of Sale', $html );
		$this->assertStringContainsString( 'value="wplm_move_machine"', $html );
		$this->assertStringContainsString( '2 more', $html );
	}

	public function test_someone_elses_licence_shows_nothing_of_it(): void {
		$bound    = $this->owned();
		$stranger = $this->create_customer();
		$this->set_row( 'machines', $bound['machine']->id, array( 'name' => 'Private PC' ) );

		$html = $this->view( $stranger, $bound['license']->id );

		$this->assertStringNotContainsString( 'Private PC', $html );
		$this->assertStringNotContainsString( 'wplm_move_machine', $html );
	}

	/** Listing without an explicit sort built "ORDER BY  DESC", so My Licenses always looked empty. */
	public function test_a_customer_list_without_a_sort_finds_their_licences(): void {
		$bound = $this->owned();

		$this->assertSame( array( $bound['license']->id ), array_map( static fn( $l ) => $l->id, wplm_get_licenses( array( 'user_id' => $bound['customer'] ) )['items'] ) );
	}

	public function test_the_list_shows_modules_instead_of_never_expires_for_an_entitlement_licence(): void {
		$bound = $this->owned();
		$this->make( EntitlementService::class )->add_line( $bound['license']->id, array( 'kind' => 'module', 'code' => 'base', 'paid_through' => '2030-01-15' ) );
		wp_set_current_user( $bound['customer'] );

		ob_start();
		$this->account()->render_list( $bound['customer'] );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Base (accounting)', $html );
		$this->assertStringNotContainsString( 'Never', $html );
		$this->assertStringContainsString( 'view=' . $bound['license']->id, $html );
	}
}
