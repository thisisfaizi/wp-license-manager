<?php
/**
 * The fleet extension notice (M5-27a §7): if the licence site is down, the owner signs a short notice
 * from the offline key backup that stretches every office's check-in deadline — by at most 30 days,
 * and never the paid-through dates. It must be producible with WordPress stopped.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Licensing;

use WPLM\Crypto\CompactToken;
use WPLM\Licensing\FleetNotice;
use WPLM\Tests\TestCase;

class FleetNoticeTest extends TestCase {

	private const IAT = 1789120800; // 2026-09-11 10:00:00 UTC.

	/** @var array{sec: string, pub: string} */
	private array $keypair;

	private string $keypair_file;

	public function set_up(): void {
		parent::set_up();
		$pair               = sodium_crypto_sign_keypair();
		$this->keypair      = array(
			'sec' => sodium_crypto_sign_secretkey( $pair ),
			'pub' => sodium_crypto_sign_publickey( $pair ),
		);
		$this->keypair_file = wp_tempnam( 'wplm-keypair' );
		file_put_contents(
			$this->keypair_file,
			wp_json_encode(
				array(
					'sec' => base64_encode( $this->keypair['sec'] ),
					'pub' => base64_encode( $this->keypair['pub'] ),
				)
			)
		);
	}

	public function tear_down(): void {
		wp_delete_file( $this->keypair_file );
		parent::tear_down();
	}

	public function test_a_notice_signs_the_contract_shape(): void {
		$token = FleetNotice::sign( FleetNotice::build( self::IAT + 20 * DAY_IN_SECONDS, self::IAT ), $this->keypair['sec'] );

		$this->assertSame(
			array(
				'v'     => 1,
				'pid'   => 'super-ledger',
				'kind'  => 'extend-check-in',
				'until' => self::IAT + 20 * DAY_IN_SECONDS,
				'iat'   => self::IAT,
			),
			CompactToken::verify( $token, $this->keypair['pub'] )
		);
	}

	public function test_more_than_30_days_or_a_past_date_is_refused(): void {
		$this->assertIsArray( FleetNotice::build( self::IAT + 30 * DAY_IN_SECONDS, self::IAT ), 'Exactly 30 days is allowed.' );

		foreach ( array( self::IAT + 30 * DAY_IN_SECONDS + 1, self::IAT, self::IAT - 1 ) as $until ) {
			try {
				FleetNotice::build( $until, self::IAT );
				$this->fail( "until={$until} should be refused" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_a_date_means_the_end_of_that_day_in_pakistan(): void {
		$this->assertSame( strtotime( '2026-09-30 23:59:59 +05:00' ), FleetNotice::parse_until( '2026-09-30', 'Asia/Karachi' ) );
		$this->assertSame( strtotime( '2026-09-30T12:00:00Z' ), FleetNotice::parse_until( '2026-09-30T12:00:00Z', 'Asia/Karachi' ), 'A full ISO time is taken as given.' );

		$this->expectException( \InvalidArgumentException::class );
		FleetNotice::parse_until( '30/09/2026', 'Asia/Karachi' );
	}

	public function test_a_mismatched_keypair_file_is_refused(): void {
		$other = sodium_crypto_sign_keypair();
		$this->expectException( \InvalidArgumentException::class );
		CompactToken::keypair_from_json(
			wp_json_encode(
				array(
					'sec' => base64_encode( $this->keypair['sec'] ),
					'pub' => base64_encode( sodium_crypto_sign_publickey( $other ) ),
				)
			)
		);
	}

	/** The owner's disaster path: plain PHP with sodium, no WordPress, no database. */
	public function test_the_standalone_script_runs_without_wordpress(): void {
		$php = getenv( 'WP_PHP_BINARY' ) ?: 'php';
		$cmd = sprintf(
			'%s %s --until=%s --keypair-file=%s --now=%d 2>&1',
			$php,
			escapeshellarg( dirname( __DIR__, 3 ) . '/bin/fleet-notice.php' ),
			escapeshellarg( '2026-09-25' ),
			escapeshellarg( $this->keypair_file ),
			self::IAT
		);
		exec( $cmd, $out, $code );

		$this->assertSame( 0, $code, implode( "\n", $out ) );
		$tokens = preg_grep( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', array_map( 'trim', $out ) );
		$this->assertCount( 1, $tokens, 'Exactly one notice on the output.' );
		$notice = CompactToken::verify( (string) reset( $tokens ), $this->keypair['pub'] );
		$this->assertSame( 'extend-check-in', $notice['kind'] );
		$this->assertSame( strtotime( '2026-09-25 23:59:59 +05:00' ), $notice['until'] );
		$this->assertSame( self::IAT, $notice['iat'] );

		$out = array();
		exec( str_replace( escapeshellarg( '2026-09-25' ), escapeshellarg( '2026-10-25' ), $cmd ), $out, $code );
		$this->assertSame( 1, $code, 'More than 30 days: refused with a message.' );
		$this->assertStringContainsString( '30 days', implode( "\n", $out ) );
	}
}
