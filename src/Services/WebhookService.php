<?php
/**
 * Webhook service — outbound event dispatch and delivery retry.
 *
 * @package WPLM\Services
 */

namespace WPLM\Services;

use WPLM\Models\Webhook;
use WPLM\Repositories;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatches event notifications to registered endpoints whenever WPLM fires an
 * internal event, and retries failed deliveries on a schedule.
 *
 * Delivery formats (per webhook):
 *   - json    : signed JSON envelope { event, data, timestamp } with an
 *               HMAC-SHA256 X-WPLM-Signature header (for your own backends/SDKs).
 *   - slack   : a chat message posted to a Slack incoming-webhook URL.
 *   - discord : a chat message posted to a Discord webhook URL.
 *   - email   : an email sent to the address in the target field.
 *
 * The slack / discord / email presets let a store owner wire up notifications
 * with zero code — just paste a URL or email address.
 */
class WebhookService {

	/** @var Repositories\WebhookRepository */
	private Repositories\WebhookRepository $webhook_repo;

	/**
	 * @param Repositories\WebhookRepository $webhook_repo
	 */
	public function __construct( Repositories\WebhookRepository $webhook_repo ) {
		$this->webhook_repo = $webhook_repo;
	}

	// -------------------------------------------------------------------------
	// Dispatch
	// -------------------------------------------------------------------------

	/**
	 * Deliver an event to every active webhook that subscribes to it.
	 *
	 * @param string $event   Event slug (e.g. 'license.activated', 'machine.revoked').
	 * @param array  $payload Associative array of event data to include in the body.
	 * @return void
	 */
	public function dispatch( string $event, array $payload ): void {
		$webhooks = $this->webhook_repo->get_active();

		if ( empty( $webhooks ) ) {
			return;
		}

		/**
		 * Filter a webhook payload before delivery (e.g. to strip sensitive fields).
		 *
		 * @param array  $payload The event data.
		 * @param string $event   The event slug.
		 */
		$payload = (array) apply_filters( 'wplm_webhook_payload', $payload, $event );

		foreach ( $webhooks as $webhook ) {
			if ( ! in_array( $event, $webhook->events, true ) ) {
				continue;
			}

			$result = $this->deliver( $webhook, $event, $payload );

			$this->webhook_repo->log_delivery(
				array(
					'webhook_id'    => $webhook->id,
					'event'         => $event,
					'payload'       => $payload,
					'response_code' => $result['code'],
					'attempts'      => 1,
					'delivered_at'  => $result['ok'] ? current_time( 'mysql' ) : null,
					'created_at'    => current_time( 'mysql' ),
				)
			);
		}
	}

	/**
	 * Send a one-off test delivery to a single webhook (used by the admin
	 * "Send test" button). Does not write to the delivery log.
	 *
	 * @param Webhook $webhook The webhook to test.
	 * @return array{ code:int, ok:bool }
	 */
	public function send_test( Webhook $webhook ): array {
		return $this->deliver(
			$webhook,
			'test.ping',
			array(
				'id'      => 0,
				'message' => __( 'This is a test delivery from WP License Manager.', 'wp-license-manager' ),
				'site'    => home_url(),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Delivery (format-aware)
	// -------------------------------------------------------------------------

	/**
	 * Deliver one event to one webhook using its configured format.
	 *
	 * @param Webhook $webhook The destination webhook.
	 * @param string  $event   Event slug.
	 * @param array   $payload Event data.
	 * @return array{ code:int, ok:bool } Response code and success flag.
	 */
	private function deliver( Webhook $webhook, string $event, array $payload ): array {
		$format = $webhook->format ?: 'json';

		// Email preset — the target field holds an address.
		if ( 'email' === $format ) {
			$envelope = wp_json_encode(
				array(
					'event'     => $event,
					'data'      => $payload,
					'timestamp' => time(),
				),
				JSON_PRETTY_PRINT
			);

			$sent = wp_mail(
				$webhook->target_url,
				sprintf( '[%s] %s', get_bloginfo( 'name' ), $event ),
				$this->human_message( $event, $payload ) . "\n\n" . $envelope
			);

			return array(
				'code' => $sent ? 200 : 0,
				'ok'   => (bool) $sent,
			);
		}

		// Build the request body per format.
		if ( 'slack' === $format ) {
			$body    = wp_json_encode( array( 'text' => $this->human_message( $event, $payload ) ) );
			$headers = array( 'Content-Type' => 'application/json' );
		} elseif ( 'discord' === $format ) {
			$body    = wp_json_encode( array( 'content' => $this->human_message( $event, $payload ) ) );
			$headers = array( 'Content-Type' => 'application/json' );
		} else {
			// Default signed JSON envelope.
			$body    = wp_json_encode(
				array(
					'event'     => $event,
					'data'      => $payload,
					'timestamp' => time(),
				)
			);
			$headers = array(
				'Content-Type'     => 'application/json',
				'X-WPLM-Signature' => 'sha256=' . hash_hmac( 'sha256', $body, $webhook->secret ),
			);
		}

		$response = wp_remote_post(
			$webhook->target_url,
			array(
				'body'      => $body,
				'headers'   => $headers,
				'timeout'   => 10,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'code' => 0,
				'ok'   => false,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return array(
			'code' => $code,
			'ok'   => $code >= 200 && $code < 300,
		);
	}

	/**
	 * Build a short human-readable message for chat / email deliveries.
	 *
	 * @param string $event   Event slug.
	 * @param array  $payload Event data.
	 * @return string
	 */
	private function human_message( string $event, array $payload ): string {
		$id = isset( $payload['id'] ) && (int) $payload['id'] > 0 ? ' #' . (int) $payload['id'] : '';

		return sprintf(
			/* translators: 1: site name, 2: event slug, 3: optional record id */
			__( '%1$s — WPLM event: %2$s%3$s', 'wp-license-manager' ),
			get_bloginfo( 'name' ),
			$event,
			$id
		);
	}

	// -------------------------------------------------------------------------
	// Retry failed deliveries
	// -------------------------------------------------------------------------

	/**
	 * Re-attempt delivery for failed webhook log entries.
	 *
	 * Eligible rows: delivered_at IS NULL AND attempts < 5 AND created within
	 * the last 24 hours. Processes at most 50 rows per invocation to avoid
	 * PHP timeouts when called from WP-Cron.
	 *
	 * @return void
	 */
	public function retry_failed(): void {
		global $wpdb;

		$log_table = $wpdb->prefix . 'wplm_webhook_log';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			"SELECT l.id, l.webhook_id, l.event, l.payload, l.attempts
			 FROM `{$log_table}` l
			 WHERE l.delivered_at IS NULL
			   AND l.attempts < 5
			   AND l.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
			 ORDER BY l.id ASC
			 LIMIT 50",
			ARRAY_A
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$webhook = $this->webhook_repo->find_by_id( (int) $row['webhook_id'] );

			if ( ! $webhook || ! $webhook->is_active() ) {
				// Mark as permanently failed (max attempts) so it isn't retried.
				$wpdb->update(
					$log_table,
					array( 'attempts' => 5 ),
					array( 'id' => (int) $row['id'] ),
					array( '%d' ),
					array( '%d' )
				);
				continue;
			}

			$payload_data = is_string( $row['payload'] )
				? ( json_decode( $row['payload'], true ) ?? array() )
				: array();

			$result      = $this->deliver( $webhook, (string) $row['event'], $payload_data );
			$new_attempts = (int) $row['attempts'] + 1;

			$update_data   = array(
				'attempts'      => $new_attempts,
				'response_code' => $result['code'],
			);
			$update_format = array( '%d', '%d' );

			if ( $result['ok'] ) {
				$update_data['delivered_at'] = current_time( 'mysql' );
				$update_format[]             = '%s';
			}

			$wpdb->update(
				$log_table,
				$update_data,
				array( 'id' => (int) $row['id'] ),
				$update_format,
				array( '%d' )
			);
		}
	}
}
