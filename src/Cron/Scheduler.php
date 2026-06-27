<?php
/**
 * WP-Cron event registration for WPLM background tasks.
 *
 * @package WPLM\Cron
 */

namespace WPLM\Cron;

defined( 'ABSPATH' ) || exit;

use WPLM\Services\Subscriptions\RenewalProcessor;
use WPLM\Services\HeartbeatService;
use WPLM\Services\WebhookService;

/**
 * Registers and dispatches three recurring WP-Cron events:
 *
 * 1. wplm_process_renewals  — hourly    — charge due subscription renewals.
 * 2. wplm_cull_zombies      — 5-minute  — deactivate dead heartbeat machines.
 * 3. wplm_retry_webhooks    — hourly    — retry any queued failed webhook deliveries.
 *
 * Call register() once during plugin boot (after 'init' at the latest).
 */
class Scheduler {

	/** @var RenewalProcessor */
	private RenewalProcessor $renewal_processor;

	/** @var HeartbeatService */
	private HeartbeatService $heartbeat_service;

	/** @var WebhookService */
	private WebhookService $webhook_service;

	/**
	 * @param RenewalProcessor $renewal_processor Processes due subscription renewals.
	 * @param HeartbeatService $heartbeat_service  Culls zombie (stale) machine records.
	 * @param WebhookService   $webhook_service    Retries failed outbound webhooks.
	 */
	public function __construct(
		RenewalProcessor $renewal_processor,
		HeartbeatService $heartbeat_service,
		WebhookService $webhook_service
	) {
		$this->renewal_processor = $renewal_processor;
		$this->heartbeat_service = $heartbeat_service;
		$this->webhook_service   = $webhook_service;
	}

	/**
	 * Attach all WP-Cron hooks and schedule events that are not yet scheduled.
	 *
	 * Must be called during plugin boot (not on an action hook — Plugin::boot()
	 * calls this directly so the filters and actions are registered on every request).
	 *
	 * wp_schedule_event() internally calls wp_get_schedules() which fires the
	 * 'cron_schedules' filter. Our add_cron_intervals() callback uses __() so
	 * it must not run before 'init' (when the textdomain is loaded). We therefore
	 * register all filters/actions immediately but defer the scheduling calls.
	 *
	 * @return void
	 */
	public function register(): void {
		// Register the custom 5-minute interval before scheduling checks run.
		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );

		// Bind WP actions to dispatcher methods.
		add_action( 'wplm_process_renewals', array( $this, 'run_renewals' ) );
		add_action( 'wplm_cull_zombies', array( $this, 'run_cull_zombies' ) );
		add_action( 'wplm_retry_webhooks', array( $this, 'run_retry_webhooks' ) );

		// Defer wp_schedule_event() to 'init' so the textdomain is loaded before
		// wp_get_schedules() fires (which triggers add_cron_intervals → __()).
		add_action( 'init', array( $this, 'schedule_events' ) );
	}

	/**
	 * Ensure each cron event is scheduled. Called on 'init'.
	 *
	 * @return void
	 */
	public function schedule_events(): void {
		if ( ! wp_next_scheduled( 'wplm_process_renewals' ) ) {
			wp_schedule_event( time(), 'hourly', 'wplm_process_renewals' );
		}

		if ( ! wp_next_scheduled( 'wplm_cull_zombies' ) ) {
			wp_schedule_event( time(), 'wplm_five_minutes', 'wplm_cull_zombies' );
		}

		if ( ! wp_next_scheduled( 'wplm_retry_webhooks' ) ) {
			wp_schedule_event( time(), 'hourly', 'wplm_retry_webhooks' );
		}
	}

	/**
	 * Inject the custom 'wplm_five_minutes' schedule into WordPress cron intervals.
	 *
	 * Hooked on 'cron_schedules'.
	 *
	 * @param array $schedules Existing cron schedule definitions.
	 * @return array Schedules array with 'wplm_five_minutes' added.
	 */
	public function add_cron_intervals( array $schedules ): array {
		if ( ! isset( $schedules['wplm_five_minutes'] ) ) {
			$schedules['wplm_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 Minutes (WPLM)', 'wp-license-manager' ),
			);
		}

		return $schedules;
	}

	// -------------------------------------------------------------------------
	// Cron dispatcher methods — bound to WP action hooks above.
	// -------------------------------------------------------------------------

	/**
	 * Dispatch the renewal processor to charge all subscriptions due for renewal.
	 *
	 * Hooked on 'wplm_process_renewals' (hourly).
	 *
	 * @return void
	 */
	public function run_renewals(): void {
		$this->renewal_processor->process_due_renewals();
	}

	/**
	 * Dispatch the heartbeat service to cull zombie machines that have missed
	 * their heartbeat window.
	 *
	 * Hooked on 'wplm_cull_zombies' (every 5 minutes).
	 *
	 * @return void
	 */
	public function run_cull_zombies(): void {
		$this->heartbeat_service->cull_zombies();
	}

	/**
	 * Dispatch the webhook service to retry previously failed deliveries.
	 *
	 * Hooked on 'wplm_retry_webhooks' (hourly).
	 *
	 * @return void
	 */
	public function run_retry_webhooks(): void {
		$this->webhook_service->retry_failed();
	}
}
