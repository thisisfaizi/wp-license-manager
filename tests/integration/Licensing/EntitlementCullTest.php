<?php
/**
 * An entitlement licence checks in every few hours and carries its own deadline (checkInBy), so the
 * floating-licence zombie cull must never deactivate its machines.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Licensing;

use WPLM\Repositories\LicenseRepository;
use WPLM\Repositories\MachineRepository;
use WPLM\Services\HeartbeatService;
use WPLM\Services\LicenseService;
use WPLM\Tests\TestCase;

class EntitlementCullTest extends TestCase {

	public function test_the_cull_never_deactivates_an_entitlement_licences_machine(): void {
		$bound = $this->activated_profile_licence();
		// A machine that already carries a floating lease and a heartbeat from long ago: what a profile
		// licence saved with "Floating" ticked looked like before this fix.
		$this->set_row(
			'machines',
			$bound['machine']->id,
			array(
				'last_heartbeat_at' => $this->utc( -6 * HOUR_IN_SECONDS ),
				'lease_expires_at'  => $this->utc( -6 * HOUR_IN_SECONDS ),
			)
		);

		$this->assertSame( 0, $this->make( HeartbeatService::class )->cull_zombies() );
		$this->assertTrue( $this->make( MachineRepository::class )->find_by_id( $bound['machine']->id )->is_active() );
	}

	public function test_a_classic_floating_licence_is_still_culled(): void {
		$license = $this->make( LicenseService::class )->create(
			array(
				'key_string'      => 'CL-' . wp_generate_password( 16, false ),
				'max_activations' => 1,
				'is_floating'     => true,
			)
		);
		$machine = $this->make( \WPLM\Services\ActivationService::class )->activate( $license->license_key, 'classic-pc' );
		$this->assertNotWPError( $machine );
		$this->set_row( 'machines', $machine->id, array( 'last_heartbeat_at' => $this->utc( -HOUR_IN_SECONDS ) ) );

		$this->assertSame( 1, $this->make( HeartbeatService::class )->cull_zombies() );
		$this->assertFalse( $this->make( MachineRepository::class )->find_by_id( $machine->id )->is_active() );
	}

	public function test_a_classic_licence_that_is_not_floating_is_never_culled(): void {
		$license = $this->make( LicenseService::class )->create(
			array(
				'key_string'      => 'CL-' . wp_generate_password( 16, false ),
				'max_activations' => 1,
			)
		);
		$machine = $this->make( \WPLM\Services\ActivationService::class )->activate( $license->license_key, 'desk-pc' );
		$this->assertNotWPError( $machine );
		global $wpdb;
		$this->assertTrue(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			(bool) $wpdb->get_var( $wpdb->prepare( "SELECT lease_expires_at IS NULL FROM {$wpdb->prefix}wplm_machines WHERE id = %d", $machine->id ) ),
			'stored as SQL NULL, not a zero date the model would have to paper over'
		);
		$this->set_row( 'machines', $machine->id, array( 'last_heartbeat_at' => $this->utc( -DAY_IN_SECONDS ) ) );

		$this->assertSame( 0, $this->make( HeartbeatService::class )->cull_zombies() );
		$this->assertTrue( $this->make( MachineRepository::class )->find_by_id( $machine->id )->is_active() );
	}

	public function test_a_zero_date_lease_written_before_the_fix_is_not_a_lease(): void {
		$license = $this->make( LicenseService::class )->create(
			array(
				'key_string'      => 'CL-' . wp_generate_password( 16, false ),
				'max_activations' => 1,
			)
		);
		$machine = $this->make( \WPLM\Services\ActivationService::class )->activate( $license->license_key, 'old-row-pc' );
		global $wpdb;
		// What a non-strict MySQL stored before the fix. A strict server refuses to write a zero date, so the
		// session's mode is cleared for this one statement and put back.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$mode = $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
		$wpdb->query( "SET SESSION sql_mode = ''" );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}wplm_machines SET lease_expires_at = '0000-00-00 00:00:00', last_heartbeat_at = %s WHERE id = %d",
				$this->utc( -DAY_IN_SECONDS ),
				$machine->id
			)
		);
		$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode = %s', (string) $mode ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$this->assertSame( 0, $this->make( HeartbeatService::class )->cull_zombies() );
		$this->assertNull( $this->make( MachineRepository::class )->find_by_id( $machine->id )->lease_expires_at );
	}

	public function test_upgrading_to_1_2_1_clears_the_zero_date_leases(): void {
		$license = $this->make( LicenseService::class )->create(
			array(
				'key_string'      => 'CL-' . wp_generate_password( 16, false ),
				'max_activations' => 1,
			)
		);
		$machine = $this->make( \WPLM\Services\ActivationService::class )->activate( $license->license_key, 'upgraded-pc' );
		global $wpdb;
		$table = $wpdb->prefix . 'wplm_machines';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$mode = $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
		$wpdb->query( "SET SESSION sql_mode = ''" );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET lease_expires_at = '0000-00-00 00:00:00' WHERE id = %d", $machine->id ) );
		$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode = %s', (string) $mode ) );

		( new \WPLM\Install\Migrator( '1.2.0' ) )->run();

		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT lease_expires_at FROM {$table} WHERE id = %d", $machine->id ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	public function test_an_entitlement_licence_cannot_be_made_floating(): void {
		$service = $this->make( LicenseService::class );
		$license = $service->create(
			array(
				'key_string'      => 'SL-' . wp_generate_password( 16, false ),
				'profile'         => 'acme-office',
				'max_activations' => 1,
				'is_floating'     => true,
			)
		);
		$this->assertFalse( $license->is_floating, 'created floating' );

		$service->update( $license->id, array( 'is_floating' => true ) );
		$this->assertFalse(
			$this->make( LicenseRepository::class )->find_by_id( $license->id )->is_floating,
			'updated to floating'
		);
	}

	public function test_activating_an_entitlement_licence_gives_its_machine_no_lease(): void {
		$license = $this->make( LicenseService::class )->create(
			array(
				'key_string'      => 'SL-' . wp_generate_password( 16, false ),
				'profile'         => 'acme-office',
				'max_activations' => 1,
			)
		);
		// Force the flag past the service, as a row saved before this fix would have it.
		$this->set_row( 'licenses', $license->id, array( 'is_floating' => 1 ) );

		$machine = $this->make( \WPLM\Services\ActivationService::class )->activate( $license->license_key, $this->client_fp( 'pc' ) );

		$this->assertNotWPError( $machine );
		$this->assertNull( $machine->lease_expires_at );
	}
}
