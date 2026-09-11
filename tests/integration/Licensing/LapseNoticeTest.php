<?php
/**
 * The "will become read-only" email: sent on a module's paid-through day, once, with the
 * day read-only starts. Nothing on the server locks; the email is the customer's warning.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Licensing;

use WPLM\Cron\Scheduler;
use WPLM\Licensing\LapseNoticeService;
use WPLM\Repositories\ActivationLogRepository;
use WPLM\Services\EntitlementService;
use WPLM\Services\LicenseService;
use WPLM\Tests\TestCase;

class LapseNoticeTest extends TestCase {

	private const NOW   = 1789120800; // 2026-09-11 10:00 UTC = 15:00 in Karachi.
	private const TODAY = '2026-09-11';

	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Asia/Karachi' );
		update_option( 'date_format', 'Y-m-d' );
		reset_phpmailer_instance();
	}

	private function notices(): LapseNoticeService {
		return $this->make( LapseNoticeService::class );
	}

	/** An entitlement licence owned by a customer, with the given module lines (code => paid_through). */
	private function licence( array $modules, array $licence = array() ): \WPLM\Models\License {
		$customer = $this->create_customer();
		$license  = $this->make( LicenseService::class )->create(
			$licence + array(
				'key_string' => 'SL-' . wp_generate_password( 16, false ),
				'profile'    => 'acme-office',
				'user_id'    => $customer,
			)
		);
		foreach ( $modules as $code => $paid_through ) {
			$this->make( EntitlementService::class )->add_line(
				$license->id,
				array(
					'kind'         => 'module',
					'code'         => $code,
					'paid_through' => $paid_through,
				)
			);
		}
		return $license;
	}

	private function day( int $offset ): string {
		return EntitlementService::add_period( self::TODAY, $offset, 'day' );
	}

	/** @return array<int, array{to: string, subject: string, body: string}> */
	private function sent(): array {
		$out = array();
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			$out[] = array(
				'to'      => (string) ( $mail['to'][0][0] ?? '' ),
				'subject' => (string) $mail['subject'],
				'body'    => (string) $mail['body'],
			);
		}
		return $out;
	}

	private function email_of( \WPLM\Models\License $license ): string {
		return get_userdata( (int) $license->user_id )->user_email;
	}

	public function test_on_the_paid_through_day_the_customer_is_told_the_day_read_only_starts(): void {
		$license = $this->licence( array( 'base' => self::TODAY ) );

		$this->assertSame( 1, $this->notices()->run( self::NOW ) );

		$sent = $this->sent();
		$this->assertCount( 1, $sent );
		$this->assertSame( $this->email_of( $license ), $sent[0]['to'] );
		// Paid through 11 Sep, grace 7 days (12–18 Sep), read-only from 19 Sep.
		$this->assertSame( 'Your Acme Office will become read-only on 2026-09-19', $sent[0]['subject'] );
		$this->assertStringContainsString( '2026-09-18', $sent[0]['body'], 'The last working day is named.' );
		$this->assertStringContainsString( 'nothing is deleted', strtolower( $sent[0]['body'] ) );
	}

	public function test_it_is_sent_once_however_often_the_job_runs(): void {
		$this->licence( array( 'base' => self::TODAY ) );

		$this->notices()->run( self::NOW );
		$this->notices()->run( self::NOW + HOUR_IN_SECONDS );
		$this->notices()->run( self::NOW + DAY_IN_SECONDS );

		$this->assertCount( 1, $this->sent() );
	}

	public function test_nothing_is_sent_before_the_paid_through_day(): void {
		$this->licence( array( 'base' => $this->day( 1 ) ) );

		$this->assertSame( 0, $this->notices()->run( self::NOW ) );
		$this->assertCount( 0, $this->sent() );
	}

	public function test_a_missed_run_catches_up_inside_grace_but_not_once_read_only_has_started(): void {
		$last_grace_day = $this->licence( array( 'base' => $this->day( -7 ) ) ); // Read-only from tomorrow.
		$already_locked = $this->licence( array( 'base' => $this->day( -8 ) ) ); // Read-only since today.

		$this->notices()->run( self::NOW );

		$to = array_column( $this->sent(), 'to' );
		$this->assertSame( array( $this->email_of( $last_grace_day ) ), $to );
		$this->assertStringEndsWith( '2026-09-12', $this->sent()[0]['subject'] );
		$this->assertNotContains( $this->email_of( $already_locked ), $to );
	}

	public function test_a_lifetime_line_for_the_same_module_means_it_never_lapses(): void {
		$license = $this->licence( array( 'pos' => self::TODAY ) );
		$this->make( EntitlementService::class )->add_line(
			$license->id,
			array(
				'kind'         => 'module',
				'code'         => 'pos',
				'paid_through' => null,
			)
		);

		$this->assertSame( 0, $this->notices()->run( self::NOW ) );
	}

	public function test_a_module_lapsing_on_its_own_names_the_module(): void {
		$this->licence(
			array(
				'base' => $this->day( 30 ),
				'pos'  => self::TODAY,
			)
		);

		$this->notices()->run( self::NOW );

		$sent = $this->sent();
		$this->assertCount( 1, $sent );
		$this->assertStringContainsString( 'Point of Sale', $sent[0]['body'] );
		$this->assertStringNotContainsString( 'Distribution', $sent[0]['body'] );
	}

	/** Only the profile's own base module is worded as the whole product. */
	public function test_without_a_base_module_every_lapse_names_its_modules(): void {
		$this->licence( array( 'base' => self::TODAY ) );
		$notices = new LapseNoticeService(
			$this->make( \WPLM\Repositories\EntitlementRepository::class ),
			$this->make( \WPLM\Repositories\LicenseRepository::class ),
			$this->make( ActivationLogRepository::class ),
			$this->registry_without_base_module()
		);

		$this->assertSame( 1, $notices->run( self::NOW ) );
		$this->assertSame( 'Base (accounting) in your Acme Office will become read-only on 2026-09-19', $this->sent()[0]['subject'] );
	}

	public function test_suspended_revoked_and_classic_licences_are_not_emailed(): void {
		$suspended = $this->licence( array( 'base' => self::TODAY ) );
		$this->set_row( 'licenses', $suspended->id, array( 'status' => 4 ) );
		$revoked = $this->licence( array( 'base' => self::TODAY ) );
		$this->set_row( 'licenses', $revoked->id, array( 'status' => 5 ) );
		$classic = $this->licence( array( 'base' => self::TODAY ) );
		$this->set_row( 'licenses', $classic->id, array( 'profile' => null ) );

		$this->assertSame( 0, $this->notices()->run( self::NOW ) );
		$this->assertCount( 0, $this->sent() );
	}

	public function test_a_renewal_that_moves_the_date_starts_a_new_cycle(): void {
		$license = $this->licence( array( 'base' => self::TODAY ) );
		$this->notices()->run( self::NOW );

		$line = $this->make( EntitlementService::class )->lines( $license->id )[0];
		$this->make( EntitlementService::class )->update_line( $line->id, array( 'paid_through' => '2026-10-11' ) );
		$this->notices()->run( self::NOW + 2 * DAY_IN_SECONDS );
		$this->assertCount( 1, $this->sent(), 'Paid: nothing more this cycle.' );

		$this->notices()->run( self::NOW + 30 * DAY_IN_SECONDS ); // 2026-10-11.
		$this->assertCount( 2, $this->sent() );
		$this->assertStringEndsWith( '2026-10-19', $this->sent()[1]['subject'] );
	}

	public function test_the_grace_setting_moves_the_read_only_date(): void {
		update_option( 'wplm_profile_acme_office_grace_days', '3' );
		$this->licence( array( 'base' => self::TODAY ) );

		$this->notices()->run( self::NOW );

		$this->assertStringEndsWith( '2026-09-15', $this->sent()[0]['subject'] );
	}

	public function test_the_order_billing_email_is_preferred_and_each_notice_is_logged(): void {
		$order = wc_create_order();
		$order->set_billing_email( 'accounts@office.example' );
		$order->save();
		$license = $this->licence( array( 'base' => self::TODAY ), array( 'order_id' => $order->get_id() ) );

		$this->notices()->run( self::NOW );

		$this->assertSame( 'accounts@office.example', $this->sent()[0]['to'] );
		$logged = array_values( array_filter( $this->make( ActivationLogRepository::class )->get_by_license( $license->id ), static fn( $e ) => 'lapse_notice' === $e->event ) );
		$this->assertCount( 1, $logged );
		$meta = is_array( $logged[0]->meta ) ? $logged[0]->meta : json_decode( (string) $logged[0]->meta, true );
		$this->assertSame( 'success', $logged[0]->result );
		$this->assertSame( self::TODAY, $meta['until'] );
		$this->assertSame( array( 'base' ), $meta['modules'] );
		$this->assertSame( '2026-09-19', $meta['read_only_on'] );
	}

	public function test_a_licence_with_no_address_is_logged_once_and_not_retried(): void {
		$license = $this->make( LicenseService::class )->create(
			array(
				'key_string' => 'SL-' . wp_generate_password( 16, false ),
				'profile'    => 'acme-office',
			)
		);
		$this->make( EntitlementService::class )->add_line( $license->id, array( 'kind' => 'module', 'code' => 'base', 'paid_through' => self::TODAY ) );

		$this->notices()->run( self::NOW );
		$this->notices()->run( self::NOW + HOUR_IN_SECONDS );

		$this->assertCount( 0, $this->sent() );
		$logged = array_values( array_filter( $this->make( ActivationLogRepository::class )->get_by_license( $license->id ), static fn( $e ) => 'lapse_notice' === $e->event ) );
		$this->assertCount( 1, $logged );
		$this->assertSame( 'no_recipient', $logged[0]->result );
	}

	public function test_a_failed_send_is_retried_on_the_next_run(): void {
		$this->licence( array( 'base' => self::TODAY ) );
		$fail = static fn() => false;
		add_filter( 'pre_wp_mail', $fail );
		$this->notices()->run( self::NOW );
		remove_filter( 'pre_wp_mail', $fail );

		$this->assertSame( 1, $this->notices()->run( self::NOW + HOUR_IN_SECONDS ) );
		$this->assertCount( 1, $this->sent() );
	}

	public function test_the_job_is_scheduled_hourly(): void {
		wp_clear_scheduled_hook( 'wplm_lapse_notices' );
		$this->make( Scheduler::class )->schedule_events();

		$this->assertSame( 'hourly', wp_get_schedule( 'wplm_lapse_notices' ) );
		$this->assertNotFalse( has_action( 'wplm_lapse_notices' ) );
	}
}
