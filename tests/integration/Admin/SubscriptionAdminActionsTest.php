<?php
/**
 * The Subscriptions screen's Pause / Resume / Cancel buttons (and the row and bulk Cancel) go through
 * SubscriptionService (owner decision D3): the same webhooks, emails and status side effects as the
 * REST API and My Account, never a bare status write.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Admin;

use WPLM\Admin\Screens\SubscriptionListTable;
use WPLM\Repositories\RenewalRepository;
use WPLM\Repositories\SubscriptionRepository;
use WPLM\Repositories\WebhookRepository;
use WPLM\Services\Subscriptions\SubscriptionService;
use WPLM\Tests\TestCase;

/** Thrown in place of the redirect's exit. */
class SubscriptionAdminRedirect extends \Exception {}

class SubscriptionAdminActionsTest extends TestCase {

	/** @var array<int, array{0:int, 1:string, 2:string}> `wplm_subscription_status_changed`: id, old, new. */
	private array $changed = array();

	/** @var int[] `wplm_subscription_cancelled`. */
	private array $cancelled = array();

	/** @var string[] Webhook events delivered. */
	private array $events = array();

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_action(
			'wplm_subscription_status_changed',
			function ( $sub, $old, $new ) {
				$this->changed[] = array( $sub->id, $old, $new );
			},
			10,
			3
		);
		add_action(
			'wplm_subscription_cancelled',
			function ( $sub ) {
				$this->cancelled[] = $sub->id;
			}
		);
		$this->make( WebhookRepository::class )->create(
			array(
				'name'       => 'Test',
				'target_url' => 'https://hooks.example.org/wplm',
				'events'     => array( 'subscription.paused', 'subscription.resumed', 'subscription.cancelled' ),
				'secret'     => 'sekret',
				'status'     => 1,
			)
		);
		add_filter(
			'wplm_webhook_payload',
			function ( $payload, $event ) {
				$this->events[] = $event;
				return $payload;
			},
			10,
			2
		);
		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			)
		);
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new SubscriptionAdminRedirect( (string) $location );
			}
		);
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		unset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'], $_REQUEST['_wpnonce'], $_REQUEST['action'], $_REQUEST['subscription'], $_POST['action'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function subscription( string $status = 'active' ): int {
		$sub = $this->make( SubscriptionService::class )->create(
			array(
				'user_id'          => $this->create_customer(),
				'billing_interval' => 1,
				'billing_period'   => 'month',
				'recurring_total'  => 1000,
				'currency'         => 'PKR',
				'next_payment'     => $this->utc( 10 * DAY_IN_SECONDS ),
			)
		);
		$this->set_row( 'subscriptions', $sub->id, array( 'status' => $status ) );
		$this->events = array(); // Only what the buttons send.
		return $sub->id;
	}

	private function table(): SubscriptionListTable {
		return new SubscriptionListTable(
			$this->make( SubscriptionRepository::class ),
			$this->make( RenewalRepository::class ),
			$this->make( SubscriptionService::class )
		);
	}

	/** Click an edit-screen button: returns where it redirects to. */
	private function click( string $action, int $id, string $nonce ): string {
		$_GET['action']       = $action;
		$_GET['id']           = (string) $id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( $nonce . $id );
		try {
			$this->table()->handle_inline_actions();
		} catch ( SubscriptionAdminRedirect $redirect ) {
			return $redirect->getMessage();
		}
		$this->fail( 'Expected a redirect.' );
	}

	public function test_pause_goes_through_the_service(): void {
		$id = $this->subscription();

		$to = $this->click( 'pause', $id, 'wplm_pause_subscription_' );

		$this->assertSame( 'on-hold', $this->subscription_row( $id )['status'] );
		$this->assertSame( array( array( $id, 'active', 'on-hold' ) ), $this->changed, 'The status-changed hook fired.' );
		$this->assertSame( array( 'subscription.paused' ), $this->events, 'The paused webhook was sent.' );
		$this->assertStringContainsString( 'saved=1', $to );
	}

	public function test_resume_goes_through_the_service(): void {
		$id = $this->subscription( 'on-hold' );

		$to = $this->click( 'resume', $id, 'wplm_resume_subscription_' );

		$this->assertSame( 'active', $this->subscription_row( $id )['status'] );
		$this->assertSame( array( array( $id, 'on-hold', 'active' ) ), $this->changed );
		$this->assertSame( array( 'subscription.resumed' ), $this->events );
		$this->assertStringContainsString( 'saved=1', $to );
	}

	public function test_cancel_from_the_edit_screen_goes_through_the_service(): void {
		$id = $this->subscription();

		$this->click( 'cancel_from_edit', $id, 'wplm_cancel_subscription_' );

		$this->assertSame( 'cancelled', $this->subscription_row( $id )['status'] );
		$this->assertSame( array( array( $id, 'active', 'cancelled' ) ), $this->changed );
		$this->assertSame( array( $id ), $this->cancelled, 'The cancelled hook fired.' );
		$this->assertSame( array( 'subscription.cancelled' ), $this->events, 'The cancelled webhook was sent.' );
		$subjects = array_map( static fn( $m ) => $m['subject'], tests_retrieve_phpmailer_instance()->mock_sent );
		$this->assertContains( 'Your subscription has been cancelled', $subjects, 'The customer was emailed.' );
	}

	public function test_a_button_the_status_no_longer_allows_changes_nothing_and_says_so(): void {
		$id = $this->subscription( 'cancelled' );

		$to = $this->click( 'resume', $id, 'wplm_resume_subscription_' );

		$this->assertSame( 'cancelled', $this->subscription_row( $id )['status'] );
		$this->assertSame( array(), $this->changed );
		$this->assertStringContainsString( 'failed=1', $to );
	}

	public function test_a_button_without_a_valid_nonce_changes_nothing(): void {
		$id                   = $this->subscription();
		$_GET['action']       = 'pause';
		$_GET['id']           = (string) $id;
		$_REQUEST['_wpnonce'] = 'not-a-nonce';
		try {
			$this->table()->handle_inline_actions();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}

		$this->assertSame( 'active', $this->subscription_row( $id )['status'] );
		$this->assertSame( array(), $this->changed );
	}

	public function test_the_row_and_bulk_cancel_go_through_the_service(): void {
		$row  = $this->subscription();
		$bulk = array( $this->subscription(), $this->subscription( 'on-hold' ) );

		$_GET['action']       = 'cancel';
		$_GET['id']           = (string) $row;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'wplm_cancel_subscription_' . $row );
		try {
			$this->table()->render_page();
			$this->fail( 'Expected a redirect.' );
		} catch ( SubscriptionAdminRedirect $redirect ) {
			$this->assertStringContainsString( 'cancelled=1', $redirect->getMessage() );
		}
		unset( $_GET['action'], $_GET['id'] );

		$_REQUEST['action']       = 'bulk-cancel';
		$_REQUEST['subscription'] = array_map( 'strval', $bulk );
		$_REQUEST['_wpnonce']     = wp_create_nonce( 'bulk-subscriptions' );
		try {
			$this->table()->render_page();
			$this->fail( 'Expected a redirect.' );
		} catch ( SubscriptionAdminRedirect $redirect ) {
			$this->assertStringContainsString( 'bulk_cancelled=2&bulk_failed=0', $redirect->getMessage() );
		}

		foreach ( array_merge( array( $row ), $bulk ) as $id ) {
			$this->assertSame( 'cancelled', $this->subscription_row( $id )['status'] );
		}
		$this->assertSame( array_merge( array( $row ), $bulk ), $this->cancelled, 'Each fired the cancelled hook.' );
		$this->assertSame( array( 'subscription.cancelled', 'subscription.cancelled', 'subscription.cancelled' ), $this->events );
	}

	/** @dataProvider bad_bulk_nonces */
	public function test_bulk_cancel_without_the_list_nonce_cancels_nothing( ?string $nonce ): void {
		$ids                      = array( $this->subscription(), $this->subscription() );
		$_REQUEST['action']       = 'bulk-cancel';
		$_REQUEST['subscription'] = array_map( 'strval', $ids );
		if ( 'other-action' === $nonce ) {
			$nonce = wp_create_nonce( 'wplm_cancel_subscription_' . $ids[0] ); // A real nonce, for the row action.
		}
		if ( null !== $nonce ) {
			$_REQUEST['_wpnonce'] = $nonce;
		}
		$died = false;
		try {
			$this->table()->render_page();
		} catch ( \WPDieException $e ) {
			$died = true;
		} catch ( SubscriptionAdminRedirect $redirect ) {
			$this->fail( 'Redirected as if done: ' . $redirect->getMessage() );
		}

		$this->assertTrue( $died, 'Refused.' );
		foreach ( $ids as $id ) {
			$this->assertSame( 'active', $this->subscription_row( $id )['status'] );
		}
		$this->assertSame( array(), $this->cancelled, 'No cancelled hook.' );
		$this->assertSame( array(), $this->events, 'No webhook.' );
		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent, 'No email.' );
	}

	public static function bad_bulk_nonces(): array {
		return array(
			'none'             => array( null ),
			'forged'           => array( 'not-a-nonce' ),
			'another action\'s' => array( 'other-action' ),
		);
	}

	public function test_cancelling_an_ended_subscription_again_sends_nothing(): void {
		$open  = $this->subscription();
		$ended = array( $this->subscription( 'cancelled' ), $this->subscription( 'expired' ) );

		$_REQUEST['action']       = 'bulk-cancel';
		$_REQUEST['subscription'] = array_map( 'strval', array_merge( array( $open ), $ended ) );
		$_REQUEST['_wpnonce']     = wp_create_nonce( 'bulk-subscriptions' );
		try {
			$this->table()->render_page();
			$this->fail( 'Expected a redirect.' );
		} catch ( SubscriptionAdminRedirect $redirect ) {
			$this->assertStringContainsString( 'bulk_cancelled=1&bulk_failed=2', $redirect->getMessage(), 'Only what was cancelled is counted; the rest is reported.' );
		}

		$this->assertSame( array( $open ), $this->cancelled, 'Only the open one is cancelled.' );
		$this->assertSame( array( 'subscription.cancelled' ), $this->events, 'One webhook.' );
		$this->assertSame( 'expired', $this->subscription_row( $ended[1] )['status'] );
		$subjects = array_map( static fn( $m ) => $m['subject'], tests_retrieve_phpmailer_instance()->mock_sent );
		$this->assertCount( 1, array_keys( $subjects, 'Your subscription has been cancelled', true ), 'One email.' );

		$to = $this->click( 'cancel_from_edit', $ended[0], 'wplm_cancel_subscription_' );
		$this->assertStringContainsString( 'failed=1', $to );
		$this->assertSame( array( $open ), $this->cancelled );
	}
}
