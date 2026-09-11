<?php
/**
 * Contract fixtures (M5-27a §9): signed tokens and notices, produced by the same composition code as
 * real tokens but with a fixed test keypair, that Super Ledger's M5-27b tests verify without PHP.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Licensing;

use WPLM\Crypto\CompactToken;
use WPLM\Crypto\Signer;
use WPLM\Licensing\ContractFixtures;
use WPLM\Tests\TestCase;

class ContractFixturesTest extends TestCase {

	private string $dir;

	public function set_up(): void {
		parent::set_up();
		$this->dir = wp_normalize_path( dirname( __DIR__, 2 ) ) . '/.tmp/fixtures-' . wp_generate_password( 8, false );
	}

	public function tear_down(): void {
		foreach ( $this->files() as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup.
		}
		if ( is_dir( $this->dir ) ) {
			@rmdir( $this->dir ); // phpcs:ignore -- best effort: Windows may hold the directory briefly after its files are unlinked.
		}
		parent::tear_down();
	}

	/** Absolute paths of the generated files, sorted. */
	private function files(): array {
		if ( ! is_dir( $this->dir ) ) {
			return array();
		}
		$names = array_values( array_diff( scandir( $this->dir ), array( '.', '..' ) ) );
		sort( $names );
		return array_map( fn( $n ) => $this->dir . '/' . $n, $names );
	}

	private function generate(): array {
		$this->make( ContractFixtures::class )->write( $this->dir );
		$cases = json_decode( (string) file_get_contents( $this->dir . '/cases.json' ), true );
		$this->assertIsArray( $cases );
		return $cases;
	}

	private function read_payload( array $manifest, string $name ): ?array {
		$public = base64_decode( trim( (string) file_get_contents( $this->dir . '/public_key.txt' ) ), true );
		foreach ( $manifest['cases'] as $case ) {
			if ( $case['name'] === $name ) {
				return CompactToken::verify( trim( (string) file_get_contents( $this->dir . '/' . $case['file'] ) ), $public );
			}
		}
		$this->fail( "No fixture case {$name}." );
	}

	public function test_every_case_in_the_spec_is_present_with_a_file_and_an_expectation(): void {
		$manifest = $this->generate();

		$names = array_column( $manifest['cases'], 'name' );
		$this->assertSame(
			array( 'active', 'due_soon', 'grace', 'lapsed_base', 'lapsed_module_only', 'lifetime_module', 'suspended', 'other_machine', 'check_in_overdue', 'summed_limits', 'unknown_module_code', 'tampered', 'wrong_product', 'fleet_notice_valid', 'fleet_notice_too_long' ),
			$names
		);
		foreach ( $manifest['cases'] as $case ) {
			$this->assertFileExists( $this->dir . '/' . $case['file'] );
			$this->assertArrayHasKey( 'expect', $case );
			$this->assertNotEmpty( $case['description'] );
		}
		$this->assertSame( hash( 'sha256', 'super-ledger|' . $manifest['raw_fingerprint'] ), $manifest['fp'] );
		$this->assertSame( 'Asia/Karachi', $manifest['timezone'] );
		$this->assertStringContainsString( 'TEST', $manifest['warning'] );
	}

	public function test_signatures_verify_except_the_tampered_one(): void {
		$manifest = $this->generate();
		$public   = base64_decode( trim( (string) file_get_contents( $this->dir . '/public_key.txt' ) ), true );

		foreach ( $manifest['cases'] as $case ) {
			$verified = CompactToken::verify( trim( (string) file_get_contents( $this->dir . '/' . $case['file'] ) ), $public );
			if ( 'tampered' === $case['name'] ) {
				$this->assertNull( $verified );
				$this->assertSame( 'invalid', $case['expect']['signature'] );
			} else {
				$this->assertIsArray( $verified, $case['name'] );
				$this->assertSame( 'valid', $case['expect']['signature'], $case['name'] );
			}
		}
	}

	public function test_the_payloads_say_what_the_case_names_claim(): void {
		$m   = $this->generate();
		$now = $m['now'];

		$active = $this->read_payload( $m, 'active' );
		$this->assertSame( $m['fp'], $active['fp'] );
		$this->assertSame( 'super-ledger', $active['pid'] );
		$this->assertGreaterThan( $now, $active['checkInBy'] );

		$this->assertSame( '2026-09-13', $this->read_payload( $m, 'due_soon' )['modules']['base']['until'] );
		$this->assertSame( '2026-09-08', $this->read_payload( $m, 'grace' )['modules']['base']['until'] );
		$this->assertSame( '2026-08-31', $this->read_payload( $m, 'lapsed_base' )['modules']['base']['until'] );

		$module_only = $this->read_payload( $m, 'lapsed_module_only' );
		$this->assertSame( '2026-10-10', $module_only['modules']['base']['until'] );
		$this->assertSame( '2026-08-20', $module_only['modules']['distribution']['until'] );

		$this->assertNull( $this->read_payload( $m, 'lifetime_module' )['modules']['pos']['until'] );
		$this->assertSame( 'suspended', $this->read_payload( $m, 'suspended' )['status'] );
		$this->assertNotSame( $m['fp'], $this->read_payload( $m, 'other_machine' )['fp'] );
		$this->assertLessThan( $now, $this->read_payload( $m, 'check_in_overdue' )['checkInBy'] );
		$this->assertSame(
			array(
				'users'  => 5,
				'seats'  => 1,
				'phones' => 2,
			),
			$this->read_payload( $m, 'summed_limits' )['limits']
		);
		$this->assertArrayHasKey( 'payroll', $this->read_payload( $m, 'unknown_module_code' )['modules'] );
		$this->assertSame( 'karobar', $this->read_payload( $m, 'wrong_product' )['pid'] );

		$this->assertSame( $now + 20 * DAY_IN_SECONDS, $this->read_payload( $m, 'fleet_notice_valid' )['until'] );
		$too_long = $this->read_payload( $m, 'fleet_notice_too_long' );
		$this->assertGreaterThan( 30 * DAY_IN_SECONDS, $too_long['until'] - $too_long['iat'] );
	}

	public function test_fixtures_are_deterministic_and_never_use_the_site_key(): void {
		$this->generate();
		$first = array_map( 'file_get_contents', $this->files() );
		$this->assertCount( 17, $first, 'public_key.txt, cases.json and 15 case files.' );

		$this->generate();
		$second = array_map( 'file_get_contents', $this->files() );

		$this->assertSame( $first, $second, 'Regenerating produces byte-identical files, so a diff in Super Ledger means a real change.' );
		$this->assertNotSame( $this->make( Signer::class )->get_public_key_base64(), trim( (string) file_get_contents( $this->dir . '/public_key.txt' ) ) );
	}
}
