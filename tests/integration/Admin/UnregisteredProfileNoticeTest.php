<?php
/**
 * A licence type nobody registers (its add-on was deactivated) stops its licences' tokens, so every
 * admin screen says so.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Admin;

use WPLM\Admin\UnregisteredProfileNotice;
use WPLM\Licensing\Profile;
use WPLM\Licensing\ProfileRegistry;
use WPLM\Services\LicenseService;
use WPLM\Tests\TestCase;

class UnregisteredProfileNoticeTest extends TestCase {

	private function notice(): UnregisteredProfileNotice {
		return new UnregisteredProfileNotice( new ProfileRegistry() );
	}

	private function html(): string {
		ob_start();
		$this->notice()->render();
		return (string) ob_get_clean();
	}

	private function licence_with_profile( string $code ): int {
		$id = $this->make( LicenseService::class )->create( array( 'key_string' => 'K-' . wp_generate_password( 16, false ) ) )->id;
		$this->set_row( 'licenses', $id, array( 'profile' => $code ) ); // As left behind by a deactivated add-on.
		return $id;
	}

	public function test_registered_profiles_and_classic_licences_raise_nothing(): void {
		$this->make( LicenseService::class )->create( array( 'key_string' => 'CLASSIC-' . wp_generate_password( 12, false ) ) );
		$this->licence_with_profile( 'acme-office' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( array(), $this->notice()->missing() );
		$this->assertSame( '', $this->html() );
	}

	public function test_licences_and_plans_of_an_unregistered_profile_are_named_to_administrators(): void {
		$this->licence_with_profile( 'gone-product' );
		$this->licence_with_profile( 'gone-product' );
		$plan = $this->create_plan( array(), array( 'name' => 'Old plan' ) );
		$this->set_row( 'plans', $plan['plan_id'], array( 'profile' => 'also-gone' ) );

		$this->assertSame( array( 'also-gone', 'gone-product' ), $this->notice()->missing() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertStringContainsString( 'also-gone, gone-product', $this->html() );
		$this->assertStringContainsString( 'read-only', $this->html() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->assertSame( '', $this->html(), 'Only administrators, who can reactivate plugins, see it.' );
	}

	/** Before an install or update has created the columns, admin screens show no database error. */
	public function test_a_missing_table_or_column_prints_nothing(): void {
		global $wpdb;
		$this->licence_with_profile( 'gone-product' );
		$plans = $wpdb->prefix . 'wplm_plans';
		add_filter( 'query', static fn( $sql ) => str_replace( "`{$plans}`", "`{$plans}_not_created_yet`", $sql ) );
		$shown = $wpdb->show_errors();

		ob_start();
		$missing = $this->notice()->missing();
		$output  = (string) ob_get_clean();
		$wpdb->show_errors( $shown );

		$this->assertSame( '', $output );
		$this->assertSame( array( 'gone-product' ), $missing, 'What can be read is still reported.' );
		$this->assertFalse( $wpdb->suppress_errors(), 'Error display is restored.' );
	}

	public function test_the_notice_clears_once_the_add_on_registers_the_profile_again(): void {
		$this->licence_with_profile( 'gone-product' );
		add_filter(
			'wplm_license_profiles',
			static function ( array $profiles ): array {
				$profiles['gone-product'] = new Profile( 'gone-product', 'Gone Product', array( 'base' ), array() );
				return $profiles;
			}
		);

		$this->assertSame( array(), $this->notice()->missing() );
	}
}
