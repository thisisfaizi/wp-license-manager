<?php
/**
 * Check-in over the real REST routes: an entitlement licence's machine activates and checks in, receives a fresh
 * v2 token each time, reports its usage, is rate-limited, and may move its licence only twice a month.
 * Classic licences keep their old responses.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Rest;

use WPLM\Crypto\Signer;
use WPLM\Licensing\CheckInService;
use WPLM\Repositories\MachineRepository;
use WPLM\Services\EntitlementService;
use WPLM\Services\LicenseService;
use WPLM\Tests\TestCase;

class CheckInTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );
	}

	private function post( string $route, array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/wplm/v1/' . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_do_request( $request );
	}

	private function profile_licence( int $seats = 1 ): \WPLM\Models\License {
		$license = $this->make( LicenseService::class )->create(
			array(
				'key_string'      => 'SL-' . wp_generate_password( 16, false ),
				'profile'         => 'acme-office',
				'max_activations' => $seats,
			)
		);
		$this->make( EntitlementService::class )->add_line( $license->id, array( 'kind' => 'module', 'code' => 'base', 'paid_through' => '2026-10-10' ) );
		return $license;
	}

	private function error_code( \WP_REST_Response $response ): string {
		return (string) ( $response->get_data()['code'] ?? '' );
	}

	private function machine_by_fp( int $license_id, string $fp ): ?\WPLM\Models\Machine {
		return $this->make( MachineRepository::class )->find_by_license_and_fingerprint( $license_id, $this->make( \WPLM\Crypto\Fingerprint::class )->hash( $fp ) );
	}

	/** Age every log row of a licence by the given seconds (time travel for the limits). */
	private function age_log( int $license_id, int $seconds ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wplm_activation_log SET created_at = DATE_SUB(created_at, INTERVAL %d SECOND) WHERE license_id = %d", $seconds, $license_id ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Activate and check in
	// ---------------------------------------------------------------------------------------------

	public function test_activate_returns_a_verifiable_token_and_records_the_machine(): void {
		$license = $this->profile_licence();
		$fp      = $this->client_fp( 'office' );

		$response = $this->post(
			'activate',
			array(
				'license_key' => $license->license_key,
				'fingerprint' => $fp,
				'app_version' => '1.4.0',
				'usage'       => array(
					'users'  => 4,
					'seats'  => 1,
					'phones' => 2,
				),
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data()['data'];
		$this->assertIsInt( $data['server_time'] );
		$this->assertEqualsWithDelta( time(), $data['server_time'], 5 );

		$payload = $this->make( Signer::class )->verify( $data['token_v2'] );
		$this->assertIsArray( $payload, 'The token verifies against the public key.' );
		$this->assertSame( $fp, $payload['fp'] );
		$this->assertSame( $data['id'], $payload['mid'], 'Still the machine response, plus the token.' );
		$this->assertSame( '2026-10-10', $payload['modules']['base']['until'] );

		$machine = $this->machine_by_fp( $license->id, $fp );
		$this->assertSame( $fp, $machine->token_fp );
		$this->assertSame( '1.4.0', $machine->app_version );
		$this->assertSame(
			array(
				'users'  => 4,
				'seats'  => 1,
				'phones' => 2,
			),
			$machine->usage
		);
	}

	public function test_a_malformed_fingerprint_is_refused_before_anything_is_created(): void {
		$license = $this->profile_licence();

		$response = $this->post(
			'activate',
			array(
				'license_key' => $license->license_key,
				'fingerprint' => 'raw-hardware-id-not-hashed',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wplm_invalid_fingerprint', $this->error_code( $response ) );
		$this->assertSame( array(), $this->make( MachineRepository::class )->get_by_license( $license->id ) );
	}

	public function test_check_in_returns_a_fresh_token_and_replaces_the_usage(): void {
		$license = $this->profile_licence();
		$fp      = $this->client_fp( 'office' );
		$this->post( 'activate', array( 'license_key' => $license->license_key, 'fingerprint' => $fp, 'usage' => array( 'users' => 1 ) ) );
		$this->make( EntitlementService::class )->add_line( $license->id, array( 'kind' => 'module', 'code' => 'pos', 'paid_through' => null ) );

		$response = $this->post(
			'heartbeat',
			array(
				'license_key' => $license->license_key,
				'fingerprint' => $fp,
				'app_version' => '1.5.0',
				'usage'       => array(
					'users'   => 6,
					'phones'  => -3,
					'seats'   => 'many',
					'printer' => 9,
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $this->make( Signer::class )->verify( $response->get_data()['data']['token_v2'] );
		$this->assertNull( $payload['modules']['pos']['until'], 'A line added since activation is in the next token.' );

		$machine = $this->machine_by_fp( $license->id, $fp );
		$this->assertSame(
			array(
				'users'  => 6,
				'phones' => 0,
			),
			$machine->usage,
			'Known codes only, never negative; a non-number is dropped.'
		);
		$this->assertSame( '1.5.0', $machine->app_version );
		$this->assertNotNull( $machine->last_heartbeat_at );
	}

	public function test_a_suspended_office_learns_it_at_check_in(): void {
		$license = $this->profile_licence();
		$fp      = $this->client_fp( 'office' );
		$this->post( 'activate', array( 'license_key' => $license->license_key, 'fingerprint' => $fp ) );
		$this->make( LicenseService::class )->change_status( $license->id, 4 );

		$response = $this->post( 'heartbeat', array( 'license_key' => $license->license_key, 'fingerprint' => $fp ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'suspended', $this->make( Signer::class )->verify( $response->get_data()['data']['token_v2'] )['status'] );
	}

	public function test_a_revoked_office_gets_no_token(): void {
		$license = $this->profile_licence();
		$fp      = $this->client_fp( 'office' );
		$this->post( 'activate', array( 'license_key' => $license->license_key, 'fingerprint' => $fp ) );
		$this->set_row( 'licenses', $license->id, array( 'status' => 5 ) );

		$response = $this->post( 'heartbeat', array( 'license_key' => $license->license_key, 'fingerprint' => $fp ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'license_revoked', $this->error_code( $response ) );
	}

	public function test_an_unknown_or_deactivated_machine_is_told_so(): void {
		$license = $this->profile_licence( 2 );
		$fp      = $this->client_fp( 'office' );
		$this->post( 'activate', array( 'license_key' => $license->license_key, 'fingerprint' => $fp ) );

		$unknown = $this->post( 'heartbeat', array( 'license_key' => $license->license_key, 'fingerprint' => $this->client_fp( 'reinstalled' ) ) );
		$this->assertSame( 404, $unknown->get_status() );
		$this->assertSame( 'machine_not_found', $this->error_code( $unknown ) );

		$this->set_row( 'machines', $this->machine_by_fp( $license->id, $fp )->id, array( 'status' => 2 ) );
		$inactive = $this->post( 'heartbeat', array( 'license_key' => $license->license_key, 'fingerprint' => $fp ) );
		$this->assertSame( 403, $inactive->get_status() );
		$this->assertSame( 'machine_inactive', $this->error_code( $inactive ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Rate limit
	// ---------------------------------------------------------------------------------------------

	public function test_check_ins_are_limited_to_30_per_machine_per_hour(): void {
		$license = $this->profile_licence( 2 );
		$fp      = $this->client_fp( 'office' );
		$other   = $this->client_fp( 'second-office-pc' );
		$this->post( 'activate', array( 'license_key' => $license->license_key, 'fingerprint' => $fp ) );
		$this->post( 'activate', array( 'license_key' => $license->license_key, 'fingerprint' => $other ) );

		for ( $i = 1; $i < 30; $i++ ) { // The activation was check-in number 1.
			$this->assertSame( 200, $this->post( 'heartbeat', array( 'license_key' => $license->license_key, 'fingerprint' => $fp ) )->get_status(), "Check-in {$i} is allowed." );
		}

		$limited = $this->post( 'heartbeat', array( 'license_key' => $license->license_key, 'fingerprint' => $fp ) );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( 'wplm_rate_limited', $this->error_code( $limited ) );
		$this->assertGreaterThan( 0, $limited->get_data()['data']['retry_after'] );

		$this->assertSame( 200, $this->post( 'heartbeat', array( 'license_key' => $license->license_key, 'fingerprint' => $other ) )->get_status(), 'Another machine is not affected.' );

		$this->age_log( $license->id, HOUR_IN_SECONDS + 1 );
		$this->assertSame( 200, $this->post( 'heartbeat', array( 'license_key' => $license->license_key, 'fingerprint' => $fp ) )->get_status(), 'An hour later it works again.' );
	}

	// ---------------------------------------------------------------------------------------------
	// Moves
	// ---------------------------------------------------------------------------------------------

	private function move_off( \WPLM\Models\License $license, string $fp ): \WP_REST_Response {
		return $this->post( 'deactivate', array( 'license_key' => $license->license_key, 'fingerprint' => $fp ) );
	}

	public function test_self_service_moves_are_limited_to_two_per_30_days(): void {
		$license = $this->profile_licence( 1 );

		foreach ( array( 'pc-a', 'pc-b' ) as $pc ) {
			$this->assertSame( 201, $this->post( 'activate', array( 'license_key' => $license->license_key, 'fingerprint' => $this->client_fp( $pc ) ) )->get_status() );
			$this->assertSame( 200, $this->move_off( $license, $this->client_fp( $pc ) )->get_status(), "Moving off {$pc} is allowed." );
		}
		$this->assertSame( 201, $this->post( 'activate', array( 'license_key' => $license->license_key, 'fingerprint' => $this->client_fp( 'pc-c' ) ) )->get_status() );

		$refused = $this->move_off( $license, $this->client_fp( 'pc-c' ) );

		$this->assertSame( 429, $refused->get_status() );
		$this->assertSame( 'wplm_move_limit_reached', $this->error_code( $refused ) );
		$this->assertNotEmpty( $refused->get_data()['data']['moves_reset_at'] );
		$this->assertTrue( $this->machine_by_fp( $license->id, $this->client_fp( 'pc-c' ) )->is_active(), 'The refused move changes nothing.' );

		$this->age_log( $license->id, 30 * DAY_IN_SECONDS + 1 );
		$this->assertSame( 200, $this->move_off( $license, $this->client_fp( 'pc-c' ) )->get_status(), 'The window rolls.' );
	}

	public function test_the_owner_can_reset_the_move_limit(): void {
		$license = $this->profile_licence( 1 );
		$checkin = $this->make( CheckInService::class );
		foreach ( array( 'pc-a', 'pc-b' ) as $pc ) {
			$this->post( 'activate', array( 'license_key' => $license->license_key, 'fingerprint' => $this->client_fp( $pc ) ) );
			$this->move_off( $license, $this->client_fp( $pc ) );
		}
		$this->post( 'activate', array( 'license_key' => $license->license_key, 'fingerprint' => $this->client_fp( 'pc-c' ) ) );
		$this->assertSame( 0, $checkin->moves_left( $license ) );

		$checkin->reset_moves( $license, 1 );

		$this->assertSame( 2, $checkin->moves_left( $license ) );
		$this->assertSame( 200, $this->move_off( $license, $this->client_fp( 'pc-c' ) )->get_status() );
	}

	public function test_deactivating_an_already_inactive_machine_is_not_a_move(): void {
		$license = $this->profile_licence( 1 );
		$fp      = $this->client_fp( 'pc-a' );
		$this->post( 'activate', array( 'license_key' => $license->license_key, 'fingerprint' => $fp ) );

		$this->move_off( $license, $fp );
		$this->move_off( $license, $fp );
		$this->move_off( $license, $fp );

		$this->assertSame( 1, 2 - $this->make( CheckInService::class )->moves_left( $license ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Classic licences are untouched
	// ---------------------------------------------------------------------------------------------

	public function test_classic_licences_keep_their_responses_and_have_no_move_limit(): void {
		$license = $this->make( LicenseService::class )->create(
			array(
				'key_string'      => 'CLASSIC-' . wp_generate_password( 12, false ),
				'max_activations' => 1,
			)
		);

		$activated = $this->post( 'activate', array( 'license_key' => $license->license_key, 'fingerprint' => 'raw-fp-1' ) );
		$this->assertSame( 201, $activated->get_status() );
		$this->assertArrayNotHasKey( 'token_v2', $activated->get_data()['data'] );

		$beat = $this->post( 'heartbeat', array( 'license_key' => $license->license_key, 'fingerprint' => 'raw-fp-1' ) );
		$this->assertSame( 200, $beat->get_status() );
		$this->assertArrayNotHasKey( 'token_v2', $beat->get_data()['data'] );

		foreach ( array( 'raw-fp-1', 'raw-fp-2', 'raw-fp-3' ) as $i => $raw ) {
			if ( $i > 0 ) {
				$this->post( 'activate', array( 'license_key' => $license->license_key, 'fingerprint' => $raw ) );
			}
			$this->assertSame( 200, $this->move_off( $license, $raw )->get_status() );
		}
	}
}
