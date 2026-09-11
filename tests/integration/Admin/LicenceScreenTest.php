<?php
/**
 * The licence edit screen renders the entitlement panels for an entitlement licence,
 * and nothing new for a classic one. Behaviour behind each form is in LicenceAdminActionsTest.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Admin;

use WPLM\Admin\Screens\EntitlementPanel;
use WPLM\Admin\Screens\LicenseListTable;
use WPLM\Licensing\CheckInService;
use WPLM\Repositories\LicenseRepository;
use WPLM\Services\EntitlementService;
use WPLM\Tests\TestCase;

class LicenceScreenTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
	}

	public function tear_down(): void {
		unset( $_GET['id'], $_GET['action'] );
		parent::tear_down();
	}

	private function screen( int $license_id = 0 ): string {
		$_GET['action'] = $license_id > 0 ? 'edit' : 'add';
		$_GET['id']     = (string) $license_id;
		ob_start();
		( new LicenseListTable( $this->make( LicenseRepository::class ) ) )->render_page();
		return (string) ob_get_clean();
	}

	private function today( int $offset ): string {
		return EntitlementService::add_period( wp_date( 'Y-m-d' ), $offset, 'day' );
	}

	public function test_an_entitlement_licence_shows_its_grants_lines_computers_and_status(): void {
		$bound   = $this->activated_profile_licence();
		$service = $this->make( EntitlementService::class );
		$service->add_line( $bound['license']->id, array( 'kind' => 'module', 'code' => 'base', 'paid_through' => $this->today( 20 ) ) );
		$service->add_line( $bound['license']->id, array( 'kind' => 'module', 'code' => 'pos', 'paid_through' => $this->today( -2 ) ) );
		$service->add_line( $bound['license']->id, array( 'kind' => 'limit', 'code' => 'phones', 'qty' => 2 ) );
		$this->make( CheckInService::class )->check_in( $bound['license'], $bound['fp'], array(), array( 'phones' => 3 ) );

		$html = $this->screen( $bound['license']->id );

		$this->assertStringContainsString( 'id="wplm-lines"', $html );
		$this->assertStringContainsString( 'Point of Sale', $html );
		$this->assertStringContainsString( 'is-grace', $html, 'POS is two days past its date.' );
		$this->assertStringContainsString( 'over the limit', $html, 'Three phones in use, two allowed.' );
		$this->assertStringContainsString( 'value="offline_code"', $html );
		$this->assertStringContainsString( 'value="reset_moves"', $html );
		$this->assertMatchesRegularExpression( '/name="status_action" value="suspend">.*?<textarea[^>]*name="note"[^>]*required/s', $html );
		$this->assertStringNotContainsString( 'name="expires_at"', $html, 'An entitlement licence has no expiry field.' );
		$this->assertSame( 1, wp_verify_nonce( $this->nonce_in( $html ), EntitlementPanel::ACTION . '_' . $bound['license']->id ) );
	}

	public function test_a_classic_licence_keeps_its_form_and_gets_no_panels(): void {
		$classic = $this->make( \WPLM\Services\LicenseService::class )->create( array( 'key_string' => 'CLASSIC-' . wp_generate_password( 8, false ) ) );

		$html = $this->screen( $classic->id );

		$this->assertStringContainsString( 'name="expires_at"', $html );
		$this->assertStringNotContainsString( 'id="wplm-lines"', $html );
	}

	public function test_the_add_form_offers_the_licence_type_and_an_optional_key(): void {
		$html = $this->screen();

		$this->assertStringContainsString( '<option value="acme-office">', $html );
		$this->assertStringContainsString( 'name="key_string"', $html );
	}

	public function test_an_offline_code_is_shown_once_after_it_is_issued(): void {
		$bound = $this->activated_profile_licence();
		$this->make( CheckInService::class )->check_in( $bound['license'], $bound['fp'], array(), null );
		EntitlementPanel::flash(
			get_current_user_id(),
			array(
				'ok'      => true,
				'message' => 'Offline code issued.',
				'token'   => 'eyJ2IjoyfQ.c2lnbmF0dXJl',
			)
		);

		$this->assertStringContainsString( 'eyJ2IjoyfQ.c2lnbmF0dXJl</textarea>', $this->screen( $bound['license']->id ) );
		$this->assertStringNotContainsString( 'eyJ2IjoyfQ.c2lnbmF0dXJl', $this->screen( $bound['license']->id ) );
	}

	public function test_no_base_line_is_called_out_and_machine_names_are_escaped(): void {
		$bound = $this->activated_profile_licence();
		$this->make( EntitlementService::class )->add_line( $bound['license']->id, array( 'kind' => 'module', 'code' => 'pos' ) );
		$this->set_row( 'machines', $bound['machine']->id, array( 'name' => '<script>alert(1)</script>' ) );

		$html = $this->screen( $bound['license']->id );

		$this->assertStringContainsString( 'No Base (accounting) line: all of Acme Office is read-only', $html );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	public function test_the_list_offers_no_bare_revoke_or_delete_for_an_entitlement_licence(): void {
		$bound = $this->activated_profile_licence();
		$table = new LicenseListTable( $this->make( LicenseRepository::class ) );

		$cell = $table->column_license_key( $this->make( LicenseRepository::class )->find_by_id( $bound['license']->id ) );

		$this->assertStringNotContainsString( 'action=delete', $cell );
		$this->assertStringNotContainsString( 'action=revoke', $cell );
		$this->assertStringContainsString( '#wplm-status', $cell );
	}

	private function nonce_in( string $html ): string {
		preg_match( '/name="_wplm_nonce" value="([^"]+)"/', $html, $m );
		return $m[1] ?? '';
	}
}
