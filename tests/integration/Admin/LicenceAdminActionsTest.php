<?php
/**
 * The owner's actions on an entitlement licence's screen: lines, status with a
 * note, offline codes, move resets, and the licence form's profile. Each is tested through the action
 * service the admin-post handlers call.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Admin;

use WPLM\Admin\LicenceAdminActions;
use WPLM\Crypto\Signer;
use WPLM\Licensing\CheckInService;
use WPLM\Repositories\ActivationLogRepository;
use WPLM\Repositories\LicenseRepository;
use WPLM\Services\EntitlementService;
use WPLM\Tests\TestCase;

class LicenceAdminActionsTest extends TestCase {

	private const NOW   = 1789120800; // 2026-09-11 10:00 UTC.
	private const ADMIN = 7;

	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Asia/Karachi' );
	}

	private function actions(): LicenceAdminActions {
		return $this->make( LicenceAdminActions::class );
	}

	private function lines( int $license_id ): array {
		return $this->make( EntitlementService::class )->lines( $license_id );
	}

	private function licence_status( int $id ): int {
		return (int) $this->license_row( $id )['status'];
	}

	private function logged( int $license_id, string $event ): array {
		return array_values( array_filter( $this->make( ActivationLogRepository::class )->get_by_license( $license_id ), static fn( $e ) => $event === $e->event ) );
	}

	// ---------------------------------------------------------------------
	// Lines
	// ---------------------------------------------------------------------

	public function test_adding_a_dated_module_and_a_lifetime_limit(): void {
		$bound = $this->activated_profile_licence();

		$this->assertTrue( $this->actions()->save_line( $bound['license']->id, array( 'code' => 'module:distribution', 'paid_through' => '2026-10-10' ) )['ok'] );
		$result = $this->actions()->save_line( $bound['license']->id, array( 'code' => 'limit:phones', 'qty' => '2', 'paid_through' => '' ) );
		$this->assertTrue( $result['ok'], $result['message'] );

		$lines = $this->lines( $bound['license']->id );
		$this->assertCount( 2, $lines );
		$this->assertSame( array( 'distribution', '2026-10-10', 'manual' ), array( $lines[0]->code, $lines[0]->paid_through, $lines[0]->source ) );
		$this->assertSame( array( 'phones', 2, null ), array( $lines[1]->code, $lines[1]->qty, $lines[1]->paid_through ) );
	}

	public function test_an_invalid_line_is_refused_with_a_reason_and_writes_nothing(): void {
		$bound = $this->activated_profile_licence();

		foreach ( array(
			array( 'code' => 'module:payroll', 'paid_through' => '2026-10-10' ),
			array( 'code' => 'module:pos', 'paid_through' => '2026-02-30' ),
			array( 'code' => 'limit:users', 'qty' => '0' ),
			array( 'code' => 'nonsense' ),
		) as $input ) {
			$result = $this->actions()->save_line( $bound['license']->id, $input );
			$this->assertFalse( $result['ok'], wp_json_encode( $input ) );
			$this->assertNotSame( '', $result['message'] );
		}
		$this->assertCount( 0, $this->lines( $bound['license']->id ) );
	}

	public function test_editing_a_line_changes_its_date_and_quantity(): void {
		$bound = $this->activated_profile_licence();
		$line  = $this->make( EntitlementService::class )->add_line( $bound['license']->id, array( 'kind' => 'limit', 'code' => 'users', 'qty' => 3, 'paid_through' => '2026-10-10' ) );

		$result = $this->actions()->save_line( $bound['license']->id, array( 'line_id' => (string) $line->id, 'qty' => '5', 'paid_through' => '2026-09-01' ) );

		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertSame( array( 5, '2026-09-01' ), array( $this->lines( $bound['license']->id )[0]->qty, $this->lines( $bound['license']->id )[0]->paid_through ) );
	}

	public function test_a_line_of_another_licence_cannot_be_edited_extended_or_deleted_from_this_one(): void {
		$mine   = $this->activated_profile_licence( 'pc-a' );
		$theirs = $this->activated_profile_licence( 'pc-b' );
		$line   = $this->make( EntitlementService::class )->add_line( $theirs['license']->id, array( 'kind' => 'module', 'code' => 'base', 'paid_through' => '2026-10-10' ) );

		$this->assertFalse( $this->actions()->save_line( $mine['license']->id, array( 'line_id' => (string) $line->id, 'paid_through' => '2030-01-01' ) )['ok'] );
		$this->assertFalse( $this->actions()->extend_line( $mine['license']->id, $line->id, 12, self::NOW )['ok'] );
		$this->assertFalse( $this->actions()->delete_line( $mine['license']->id, $line->id )['ok'] );

		$this->assertSame( '2026-10-10', $this->lines( $theirs['license']->id )[0]->paid_through );
	}

	public function test_extend_uses_the_renewal_rule(): void {
		$bound   = $this->activated_profile_licence();
		$service = $this->make( EntitlementService::class );
		$inside  = $service->add_line( $bound['license']->id, array( 'kind' => 'module', 'code' => 'base', 'paid_through' => '2026-09-05' ) ); // 6 days late: inside grace.
		$after   = $service->add_line( $bound['license']->id, array( 'kind' => 'module', 'code' => 'pos', 'paid_through' => '2026-08-31' ) ); // 11 days late: read-only.

		$this->assertTrue( $this->actions()->extend_line( $bound['license']->id, $inside->id, 1, self::NOW )['ok'] );
		$this->assertTrue( $this->actions()->extend_line( $bound['license']->id, $after->id, 2, self::NOW )['ok'] );

		$by_code = array_column( array_map( static fn( $l ) => array( $l->code, $l->paid_through ), $this->lines( $bound['license']->id ) ), 1, 0 );
		$this->assertSame( '2026-10-05', $by_code['base'], 'Inside grace: continues from the old end.' );
		$this->assertSame( '2026-11-10', $by_code['pos'], 'After grace: two months from today.' );
	}

	public function test_a_lifetime_line_cannot_be_extended_and_months_are_bounded(): void {
		$bound    = $this->activated_profile_licence();
		$lifetime = $this->make( EntitlementService::class )->add_line( $bound['license']->id, array( 'kind' => 'module', 'code' => 'pos' ) );
		$dated    = $this->make( EntitlementService::class )->add_line( $bound['license']->id, array( 'kind' => 'module', 'code' => 'base', 'paid_through' => '2026-10-10' ) );

		$this->assertFalse( $this->actions()->extend_line( $bound['license']->id, $lifetime->id, 1, self::NOW )['ok'] );
		$this->assertFalse( $this->actions()->extend_line( $bound['license']->id, $dated->id, 0, self::NOW )['ok'] );
		$this->assertFalse( $this->actions()->extend_line( $bound['license']->id, $dated->id, 37, self::NOW )['ok'] );
	}

	public function test_deleting_a_line(): void {
		$bound = $this->activated_profile_licence();
		$line  = $this->make( EntitlementService::class )->add_line( $bound['license']->id, array( 'kind' => 'module', 'code' => 'fbr' ) );

		$this->assertTrue( $this->actions()->delete_line( $bound['license']->id, $line->id )['ok'] );
		$this->assertCount( 0, $this->lines( $bound['license']->id ) );
	}

	// ---------------------------------------------------------------------
	// Status with a note (D7)
	// ---------------------------------------------------------------------

	public function test_suspending_and_revoking_require_a_note(): void {
		$bound = $this->activated_profile_licence();

		foreach ( array( 'suspend', 'revoke' ) as $action ) {
			$result = $this->actions()->change_status( $bound['license']->id, $action, '   ', self::ADMIN );
			$this->assertFalse( $result['ok'], $action );
		}
		$this->assertSame( 1, $this->licence_status( $bound['license']->id ) );
	}

	public function test_suspend_then_reinstate_is_logged_and_the_office_hears_it_at_check_in(): void {
		$bound   = $this->activated_profile_licence();
		$checkin = $this->make( CheckInService::class );

		$this->assertTrue( $this->actions()->change_status( $bound['license']->id, 'suspend', 'Cheque bounced', self::ADMIN )['ok'] );
		$this->assertSame( 4, $this->licence_status( $bound['license']->id ) );
		$token = $checkin->check_in( $this->reload( $bound )['license'], $bound['fp'], array(), null );
		$this->assertSame( 'suspended', $this->make( Signer::class )->verify( $token['token'] )['status'] );

		$this->assertTrue( $this->actions()->change_status( $bound['license']->id, 'reinstate', '', self::ADMIN )['ok'] );
		$token = $checkin->check_in( $this->reload( $bound )['license'], $bound['fp'], array(), null );
		$this->assertSame( 'active', $this->make( Signer::class )->verify( $token['token'] )['status'], 'Reinstate unlocks at the next check-in.' );

		$notes = $this->logged( $bound['license']->id, 'status_note' );
		$this->assertCount( 2, $notes );
		// MySQL's JSON type reorders object keys, so compare sorted.
		$metas = array_map(
			static function ( $e ) {
				$meta = $e->meta;
				ksort( $meta );
				return $meta;
			},
			$notes
		);
		$this->assertContains( array( 'action' => 'suspend', 'note' => 'Cheque bounced', 'user_id' => self::ADMIN ), $metas );
		$this->assertContains( array( 'action' => 'reinstate', 'note' => '', 'user_id' => self::ADMIN ), $metas );
	}

	public function test_revoking_needs_a_note_and_works_with_one(): void {
		$bound = $this->activated_profile_licence();

		$this->assertTrue( $this->actions()->change_status( $bound['license']->id, 'revoke', 'Chargeback on order 991', self::ADMIN )['ok'] );
		$this->assertSame( 5, $this->licence_status( $bound['license']->id ) );
	}

	public function test_an_unknown_status_action_is_refused(): void {
		$bound = $this->activated_profile_licence();

		$this->assertFalse( $this->actions()->change_status( $bound['license']->id, 'terminate', 'x', self::ADMIN )['ok'] );
		$this->assertSame( 1, $this->licence_status( $bound['license']->id ) );
	}

	// ---------------------------------------------------------------------
	// Machines
	// ---------------------------------------------------------------------

	public function test_an_offline_code_is_issued_for_a_machine_of_this_licence_only(): void {
		$bound = $this->activated_profile_licence( 'pc-a' );
		$this->make( CheckInService::class )->check_in( $bound['license'], $bound['fp'], array(), null );
		$other = $this->activated_profile_licence( 'pc-b' );
		$this->make( CheckInService::class )->check_in( $other['license'], $other['fp'], array(), null );

		$result = $this->actions()->offline_code( $bound['license']->id, $bound['machine']->id, 45, self::ADMIN, self::NOW );
		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertSame( self::NOW + 45 * DAY_IN_SECONDS, $this->make( Signer::class )->verify( $result['token'] )['checkInBy'] );

		$this->assertFalse( $this->actions()->offline_code( $bound['license']->id, $other['machine']->id, 30, self::ADMIN, self::NOW )['ok'] );
	}

	public function test_an_offline_code_failure_is_explained(): void {
		$bound = $this->activated_profile_licence(); // Never checked in: no signed fp yet.
		$this->set_row( 'machines', $bound['machine']->id, array( 'token_fp' => null ) );

		$result = $this->actions()->offline_code( $bound['license']->id, $bound['machine']->id, 30, self::ADMIN, self::NOW );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'checked in', $result['message'] );
	}

	public function test_resetting_moves_gives_the_customer_their_moves_back(): void {
		$bound   = $this->activated_profile_licence();
		$checkin = $this->make( CheckInService::class );
		$checkin->move_off( $bound['license'], $bound['machine'] );
		$second = $this->make( \WPLM\Services\ActivationService::class )->activate( $bound['license']->license_key, $this->client_fp( 'pc-2' ) );
		$checkin->move_off( $this->reload( $bound )['license'], $second );
		$this->assertSame( 0, $checkin->moves_left( $this->reload( $bound )['license'] ) );

		$this->assertTrue( $this->actions()->reset_moves( $bound['license']->id, self::ADMIN )['ok'] );

		$this->assertSame( 2, $checkin->moves_left( $this->reload( $bound )['license'] ) );
	}

	// ---------------------------------------------------------------------
	// The licence form
	// ---------------------------------------------------------------------

	public function test_the_licence_form_creates_an_entitlement_licence(): void {
		$result = $this->actions()->save_licence(
			array(
				'key_string'      => 'SL-FORM-0001',
				'profile'         => 'acme-office',
				'max_activations' => '1',
				'expires_at'      => '2027-01-01',
			)
		);

		$this->assertTrue( $result['ok'], $result['message'] );
		$row = $this->license_row( $result['license_id'] );
		$this->assertSame( 'acme-office', $row['profile'] );
		$this->assertNull( $row['expires_at'], 'An entitlement licence never stores an expiry.' );
	}

	public function test_the_licence_form_generates_a_key_when_none_is_typed(): void {
		$result = $this->actions()->save_licence(
			array(
				'key_string' => '',
				'profile'    => 'acme-office',
			)
		);

		$this->assertTrue( $result['ok'], $result['message'] );
		$license = $this->make( LicenseRepository::class )->find_by_id( $result['license_id'] );
		$this->assertNotSame( '', $license->license_key, 'The default generator made the key.' );
	}

	public function test_the_licence_form_refuses_an_unknown_profile(): void {
		$result = $this->actions()->save_licence(
			array(
				'key_string' => 'SL-FORM-0002',
				'profile'    => 'no-such-product',
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertNotSame( '', $result['message'] );
	}

	public function test_the_licence_form_keeps_a_classic_licence_classic(): void {
		$result = $this->actions()->save_licence(
			array(
				'key_string' => 'CLASSIC-0001',
				'profile'    => '',
				'expires_at' => '2027-01-01',
			)
		);

		$this->assertTrue( $result['ok'], $result['message'] );
		$row = $this->license_row( $result['license_id'] );
		$this->assertNull( $row['profile'] );
		$this->assertStringStartsWith( '2027-01-01', (string) $row['expires_at'] );
	}

	public function test_editing_keeps_the_profile_when_the_form_does_not_send_one(): void {
		$bound = $this->activated_profile_licence();

		$result = $this->actions()->save_licence(
			array(
				'license_id'      => (string) $bound['license']->id,
				'max_activations' => '3',
			)
		);

		$this->assertTrue( $result['ok'], $result['message'] );
		$row = $this->license_row( $bound['license']->id );
		$this->assertSame( 'acme-office', $row['profile'] );
		$this->assertSame( 3, (int) $row['max_activations'] );
	}

	// ---------------------------------------------------------------------
	// The screen's single form handler
	// ---------------------------------------------------------------------

	public function test_dispatch_routes_each_panel_form(): void {
		$bound = $this->activated_profile_licence();
		$id    = (string) $bound['license']->id;

		$added = $this->actions()->dispatch( array( 'license_id' => $id, 'do' => 'line_save', 'code' => 'module:base', 'paid_through' => '2026-10-10' ), self::ADMIN );
		$this->assertTrue( $added['ok'], $added['message'] );
		$line = $this->lines( $bound['license']->id )[0];

		$this->assertTrue( $this->actions()->dispatch( array( 'license_id' => $id, 'do' => 'line_extend', 'line_id' => (string) $line->id, 'months' => '1' ), self::ADMIN )['ok'] );
		$this->assertTrue( $this->actions()->dispatch( array( 'license_id' => $id, 'do' => 'status', 'status_action' => 'suspend', 'note' => 'Asked to pause' ), self::ADMIN )['ok'] );
		$this->assertTrue( $this->actions()->dispatch( array( 'license_id' => $id, 'do' => 'reset_moves' ), self::ADMIN )['ok'] );
		$this->assertTrue( $this->actions()->dispatch( array( 'license_id' => $id, 'do' => 'line_delete', 'line_id' => (string) $line->id ), self::ADMIN )['ok'] );

		$this->assertSame( 4, $this->licence_status( $bound['license']->id ) );
		$this->assertCount( 0, $this->lines( $bound['license']->id ) );
	}

	public function test_dispatch_refuses_an_unknown_form_or_a_missing_licence(): void {
		$bound = $this->activated_profile_licence();

		$this->assertFalse( $this->actions()->dispatch( array( 'license_id' => (string) $bound['license']->id, 'do' => 'drop_tables' ), self::ADMIN )['ok'] );
		$this->assertFalse( $this->actions()->dispatch( array( 'license_id' => '999999', 'do' => 'reset_moves' ), self::ADMIN )['ok'] );
	}

	// ---------------------------------------------------------------------
	// Bulk actions on the list
	// ---------------------------------------------------------------------

	public function test_bulk_suspend_and_revoke_leave_entitlement_licences_to_their_screen(): void {
		$profile = $this->activated_profile_licence();
		$classic = $this->make( \WPLM\Services\LicenseService::class )->create( array( 'key_string' => 'CLASSIC-' . wp_generate_password( 8, false ) ) );

		$result = $this->actions()->bulk_status( array( $profile['license']->id, $classic->id ), 'suspend' );

		$this->assertSame( array( 'changed' => 1, 'skipped' => 1 ), $result );
		$this->assertSame( 1, $this->licence_status( $profile['license']->id ) );
		$this->assertSame( 4, (int) $this->make( LicenseRepository::class )->find_by_id( $classic->id )->status );
	}
}
