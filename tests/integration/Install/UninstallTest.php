<?php
/**
 * Deleting the plugin: keeps everything unless "Delete all data" is ticked (before 1.3.2 it wiped by
 * default, and its own table list missed plans, packages and entitlements), and the notice for a
 * second installed copy, whose Delete runs that copy's uninstall.
 *
 * The test database turns DROP TABLE into DROP TEMPORARY TABLE, so drops are asserted from the
 * queries sent. A real uninstall is checked on a scratch site.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Install;

use WPLM\Admin\DuplicateCopyNotice;
use WPLM\Install\Installer;
use WPLM\Install\Tables;
use WPLM\Install\Uninstaller;
use WPLM\Tests\TestCase;

class UninstallTest extends TestCase {

	/** @var string[] DROP statements sent during the test. */
	private array $drops = array();

	public function set_up(): void {
		parent::set_up();
		$this->drops = array();
		add_filter( 'query', array( $this, 'record_drop' ), 1 );
	}

	public function tear_down(): void {
		remove_filter( 'query', array( $this, 'record_drop' ), 1 );
		parent::tear_down();
	}

	/** @internal */
	public function record_drop( string $query ): string {
		if ( preg_match( '/^\s*DROP\s+TABLE/i', $query ) ) {
			$this->drops[] = $query;
		}
		return $query;
	}

	public function test_the_table_list_is_every_table_the_installer_creates(): void {
		global $wpdb;
		$schema = new \ReflectionMethod( Installer::class, 'get_schema' );
		preg_match_all(
			'/CREATE TABLE ' . preg_quote( $wpdb->prefix, '/' ) . 'wplm_(\w+) \(/',
			implode( "\n", $schema->invoke( new Installer(), '' ) ),
			$m
		);

		$created = $m[1];
		$listed  = Tables::NAMES;
		sort( $created );
		sort( $listed );
		$this->assertCount( 18, $created );
		$this->assertSame( $created, $listed );
	}

	public function test_by_default_deleting_the_plugin_keeps_everything(): void {
		$licence = $this->activated_profile_licence()['license'];
		update_option( 'wplm_signing_keypair', 'kept' );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wplm_process_renewals' );

		$this->assertFalse( Uninstaller::should_wipe() );
		$this->assertFalse( Uninstaller::run() );

		$this->assertSame( array(), $this->drops );
		$this->assertSame( $licence->id, (int) $this->license_row( $licence->id )['id'] );
		$this->assertSame( 'kept', get_option( 'wplm_signing_keypair' ) );
		$this->assertFalse( wp_next_scheduled( 'wplm_process_renewals' ), 'Its handler goes with the plugin.' );
	}

	public function test_ticked_it_drops_every_table_and_every_wplm_option(): void {
		update_option( Uninstaller::DELETE_OPTION, 1 );
		update_option( 'wplm_signing_keypair', 'gone' );
		update_option( 'wplm_fulfil_lock_42', 'gone' );
		set_transient( 'wplm_flash_1', 'gone', HOUR_IN_SECONDS );
		update_option( 'blogname', 'Licence server' );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wplm_lapse_notices' );

		$this->assertTrue( Uninstaller::run() );

		global $wpdb;
		$dropped = array();
		foreach ( $this->drops as $q ) {
			if ( preg_match( '/`' . preg_quote( $wpdb->prefix, '/' ) . 'wplm_(\w+)`/', $q, $m ) ) {
				$dropped[] = $m[1];
			}
		}
		$this->assertSame( Tables::NAMES, $dropped );

		$this->assertFalse( get_option( 'wplm_signing_keypair' ) );
		$this->assertFalse( get_option( 'wplm_fulfil_lock_42' ) );
		$this->assertFalse( get_option( Uninstaller::DELETE_OPTION ) );
		$this->assertFalse( get_transient( 'wplm_flash_1' ) );
		$this->assertSame( 'Licence server', get_option( 'blogname' ) );
		$this->assertFalse( wp_next_scheduled( 'wplm_lapse_notices' ) );
	}

	public function test_the_old_keep_option_still_wins(): void {
		update_option( Uninstaller::DELETE_OPTION, 1 );
		update_option( Uninstaller::LEGACY_KEEP_OPTION, 1 );

		$this->assertFalse( Uninstaller::run() );
		$this->assertSame( array(), $this->drops );
	}

	public function test_an_unticked_box_saves_as_off(): void {
		$page = new \WPLM\Admin\Settings\SettingsPage( $this->make( \WPLM\Licensing\ProfileRegistry::class ) );
		$this->assertSame( 0, $page->_sanitize_checkbox( null ) );
		$this->assertSame( 1, $page->_sanitize_checkbox( '1' ) );
	}

	public function test_a_second_copy_under_another_folder_is_found(): void {
		$plugins = array(
			'wp-license-manager/wp-license-manager.php'      => array(
				'Name'    => 'WP License Manager',
				'Version' => '1.3.2',
			),
			'wp-license-manager-main/wp-license-manager.php' => array(
				'Name'    => 'WP License Manager',
				'Version' => '1.3.0',
			),
			'woocommerce/woocommerce.php'                    => array(
				'Name'    => 'WooCommerce',
				'Version' => '9.0',
			),
		);

		$this->assertSame(
			array( 'wp-license-manager-main/wp-license-manager.php' => '1.3.0' ),
			( new DuplicateCopyNotice() )->other_copies( $plugins, 'wp-license-manager/wp-license-manager.php' )
		);
		$this->assertSame( array(), ( new DuplicateCopyNotice() )->other_copies( array_slice( $plugins, 0, 1 ), 'wp-license-manager/wp-license-manager.php' ) );
	}
}
