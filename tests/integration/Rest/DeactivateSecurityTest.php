<?php
/**
 * Audit F11: the public POST /wplm/v1/deactivate accepted a bare machine_id, so anyone could
 * deactivate any customer's device by counting upward.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Rest;

use WPLM\Services\ActivationService;
use WPLM\Services\LicenseService;
use WPLM\Tests\TestCase;

class DeactivateSecurityTest extends TestCase {

	private function activated_machine(): array {
		$license = $this->make( LicenseService::class )->create(
			array(
				'key_string'      => 'TEST-' . wp_generate_password( 12, false ),
				'max_activations' => 2,
			)
		);
		$machine = $this->make( ActivationService::class )->activate( $license->license_key, 'fp-owner-pc' );
		$this->assertNotWPError( $machine );
		return array( $license, $machine );
	}

	private function post( array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/wplm/v1/deactivate' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_do_request( $request );
	}

	private function machine_status( int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wplm_machines WHERE id = %d", $id ) );
	}

	public function test_anonymous_machine_id_alone_is_refused(): void {
		[ , $machine ] = $this->activated_machine();
		wp_set_current_user( 0 );

		$response = $this->post( array( 'machine_id' => $machine->id ) );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertSame( 1, $this->machine_status( $machine->id ), 'The device stays active.' );
	}

	public function test_machine_id_with_another_licence_key_is_refused(): void {
		[ , $machine ]  = $this->activated_machine();
		[ $other ]      = $this->activated_machine();
		wp_set_current_user( 0 );

		$response = $this->post( array( 'machine_id' => $machine->id, 'license_key' => $other->license_key ) );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertSame( 1, $this->machine_status( $machine->id ) );
	}

	public function test_licence_key_and_fingerprint_still_deactivate(): void {
		[ $license, $machine ] = $this->activated_machine();
		wp_set_current_user( 0 );

		$response = $this->post( array( 'license_key' => $license->license_key, 'fingerprint' => 'fp-owner-pc' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotSame( 1, $this->machine_status( $machine->id ) );
	}
}
