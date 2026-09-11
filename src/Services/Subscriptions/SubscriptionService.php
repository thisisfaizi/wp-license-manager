<?php
/**
 * Subscription service — create, pause, resume, cancel, and status management
 * for the native subscription engine.
 *
 * @package WPLM\Services\Subscriptions
 */

namespace WPLM\Services\Subscriptions;

use WPLM\Models\Subscription;
use WPLM\Repositories\RenewalRepository;
use WPLM\Repositories\SubscriptionRepository;
use WPLM\Services\LicenseService;

defined( 'ABSPATH' ) || exit;

/**
 * Central lifecycle controller for WPLM subscriptions.
 *
 * Handles creation, status transitions, license synchronisation,
 * and audit note writing. All status changes go through update_status()
 * so the subscription→license sync in Table 9.2 is enforced in one place.
 */
class SubscriptionService {

	// License status constants (mirrors spec Section 5.1).
	const LICENSE_STATUS_ACTIVE    = 1;
	const LICENSE_STATUS_SUSPENDED = 4;
	const LICENSE_STATUS_REVOKED   = 5;

	/** @var SubscriptionRepository */
	private SubscriptionRepository $sub_repo;

	/** @var RenewalRepository */
	private RenewalRepository $renewal_repo;

	/** @var LicenseService */
	private LicenseService $license_service;

	/**
	 * @param SubscriptionRepository $sub_repo        Subscription data-access layer.
	 * @param RenewalRepository      $renewal_repo    Renewal data-access layer.
	 * @param LicenseService         $license_service License lifecycle service.
	 */
	public function __construct(
		SubscriptionRepository $sub_repo,
		RenewalRepository $renewal_repo,
		LicenseService $license_service
	) {
		$this->sub_repo        = $sub_repo;
		$this->renewal_repo    = $renewal_repo;
		$this->license_service = $license_service;
	}

	// -------------------------------------------------------------------------
	// Create
	// -------------------------------------------------------------------------

	/**
	 * Create a new subscription.
	 *
	 * Required $args keys: user_id, billing_interval, billing_period,
	 * recurring_total, currency.
	 *
	 * Optional keys: trial_end, next_payment, parent_order_id, license_id,
	 * signup_fee, payment_method, payment_token_id, end_date, items (array).
	 *
	 * @param array $args Column/value pairs plus optional 'items' array.
	 * @return Subscription The newly created subscription model.
	 * @throws \InvalidArgumentException When a required field is missing.
	 * @throws \RuntimeException         On database failure (propagated from repo).
	 */
	public function create( array $args ): Subscription {
		// Validate required fields.
		$required = array( 'user_id', 'billing_interval', 'billing_period', 'recurring_total', 'currency' );
		foreach ( $required as $field ) {
			if ( ! isset( $args[ $field ] ) || '' === (string) $args[ $field ] ) {
				throw new \InvalidArgumentException(
					sprintf( 'WPLM SubscriptionService: required field "%s" is missing or empty.', $field )
				);
			}
		}

		// Determine initial status.
		$status = isset( $args['trial_end'] ) && '' !== $args['trial_end'] ? 'trial' : 'pending';

		// Determine next_payment — caller may supply an explicit value.
		$next_payment = $args['next_payment'] ?? null;
		if ( null === $next_payment && 'trial' === $status ) {
			$next_payment = $args['trial_end'];
		}

		$data = array(
			'user_id'          => (int) $args['user_id'],
			'billing_interval' => (int) $args['billing_interval'],
			'billing_period'   => (string) $args['billing_period'],
			'recurring_total'  => (float) $args['recurring_total'],
			'currency'         => (string) $args['currency'],
			'status'           => $status,
			'next_payment'     => $next_payment,
			'parent_order_id'  => isset( $args['parent_order_id'] ) ? (int) $args['parent_order_id'] : null,
			'license_id'       => isset( $args['license_id'] ) ? (int) $args['license_id'] : null,
			'signup_fee'       => isset( $args['signup_fee'] ) ? (float) $args['signup_fee'] : 0.0,
			'trial_end'        => $args['trial_end'] ?? null,
			'payment_method'   => $args['payment_method'] ?? null,
			'payment_token_id' => isset( $args['payment_token_id'] ) ? (int) $args['payment_token_id'] : null,
			'end_date'         => $args['end_date'] ?? null,
			'failed_attempts'  => 0,
		);

		$id = $this->sub_repo->create( $data );

		// Insert subscription items if provided.
		if ( ! empty( $args['items'] ) && is_array( $args['items'] ) ) {
			global $wpdb;
			$items_table = $wpdb->prefix . 'wplm_subscription_items';

			foreach ( $args['items'] as $item ) {
				$wpdb->insert(
					$items_table,
					array(
						'subscription_id' => $id,
						'product_id'      => (int) ( $item['product_id'] ?? 0 ),
						'variation_id'    => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : null,
						'quantity'        => (int) ( $item['quantity'] ?? 1 ),
						'line_total'      => (float) ( $item['line_total'] ?? 0.0 ),
						'meta'            => isset( $item['meta'] ) ? wp_json_encode( $item['meta'] ) : '[]',
					)
				);
			}
		}

		$sub = $this->sub_repo->find_by_id( $id );

		if ( null === $sub ) {
			throw new \RuntimeException(
				sprintf( 'WPLM SubscriptionService: could not reload subscription after INSERT (id %d).', $id )
			);
		}

		/**
		 * Fires after a new subscription has been created.
		 *
		 * @param Subscription $sub The new subscription record.
		 */
		do_action( 'wplm_subscription_created', $sub );

		return $sub;
	}

	// -------------------------------------------------------------------------
	// Finders
	// -------------------------------------------------------------------------

	/**
	 * Retrieve a single subscription by primary key.
	 *
	 * @param int $id Subscription row id.
	 * @return Subscription|null
	 */
	public function get( int $id ): ?Subscription {
		return $this->sub_repo->find_by_id( $id );
	}

	/**
	 * Return a list of subscriptions, optionally filtered by user.
	 *
	 * When $args['user_id'] is set, only that user's subscriptions are returned.
	 * Otherwise all subscriptions are returned (newest first).
	 *
	 * @param array $args Optional filter args. Accepts 'user_id' (int).
	 * @return Subscription[]
	 */
	public function get_list( array $args = array() ): array {
		if ( ! empty( $args['user_id'] ) ) {
			return $this->sub_repo->get_by_user( (int) $args['user_id'] );
		}

		return $this->sub_repo->get_all();
	}

	// -------------------------------------------------------------------------
	// License binding
	// -------------------------------------------------------------------------

	/**
	 * Bind an issued license to a subscription.
	 *
	 * Call this after the license has been created so the subscription knows
	 * which license to sync status changes to.
	 *
	 * @param int $sub_id     Subscription row id.
	 * @param int $license_id License row id.
	 * @return bool
	 */
	public function bind_license( int $sub_id, int $license_id ): bool {
		return $this->sub_repo->update( $sub_id, array( 'license_id' => $license_id ) );
	}

	// -------------------------------------------------------------------------
	// Lifecycle transitions
	// -------------------------------------------------------------------------

	/**
	 * Pause a subscription (put it on-hold). The bound licence is not locked: it keeps
	 * working until its paid term + grace runs out. To lock a customer, suspend the licence.
	 *
	 * Only subscriptions with status 'active' or 'trial' may be paused.
	 *
	 * @param int $id Subscription row id.
	 * @return bool True on success.
	 * @throws \RuntimeException When the subscription does not exist or has the wrong status.
	 */
	public function pause( int $id ): bool {
		$sub = $this->load_or_fail( $id );

		if ( ! in_array( $sub->status, array( 'active', 'trial' ), true ) ) {
			throw new \RuntimeException(
				sprintf(
					'WPLM SubscriptionService: subscription %d cannot be paused (current status: %s).',
					$id,
					$sub->status
				)
			);
		}

		$this->update_status( $id, 'on-hold' );

		return true;
	}

	/**
	 * Resume a paused (on-hold) subscription and reinstate its bound license.
	 *
	 * Only subscriptions with status 'on-hold' may be resumed.
	 *
	 * @param int $id Subscription row id.
	 * @return bool True on success.
	 * @throws \RuntimeException When the subscription does not exist or has the wrong status.
	 */
	public function resume( int $id ): bool {
		$sub = $this->load_or_fail( $id );

		if ( 'on-hold' !== $sub->status ) {
			throw new \RuntimeException(
				sprintf(
					'WPLM SubscriptionService: subscription %d cannot be resumed (current status: %s).',
					$id,
					$sub->status
				)
			);
		}

		$this->update_status( $id, 'active' );

		return true;
	}

	/**
	 * Cancel a subscription, either immediately or at the end of the current period.
	 *
	 * @param int  $id             Subscription row id.
	 * @param bool $at_period_end  When true, sets status to 'pending-cancel' (license
	 *                             stays active until end_date). When false, immediately
	 *                             sets status to 'cancelled'. The licence runs to the end of the paid
	 *                             period (a legacy perpetual licence gets that end date).
	 * @return bool True on success.
	 * @throws \RuntimeException When the subscription does not exist.
	 */
	public function cancel( int $id, bool $at_period_end = false ): bool {
		$sub = $this->load_or_fail( $id );

		if ( $at_period_end ) {
			$this->update_status( $id, 'pending-cancel' );
		} else {
			$this->update_status( $id, 'cancelled' );

			/**
			 * Fires after a subscription is immediately cancelled.
			 *
			 * @param Subscription $sub The cancelled subscription.
			 */
			do_action( 'wplm_subscription_cancelled', $sub );
		}

		return true;
	}

	/**
	 * Update a subscription's status and sync the bound license accordingly.
	 *
	 * Licence sync map (revised for audit F3/F4 — billing never locks by status):
	 *   active / trial      → pending, inactive or expired licence → 1 (active), if its term is current;
	 *                         a suspended, revoked or terminated licence is left alone
	 *   on-hold             → no change (the licence lapses at expires_at + grace)
	 *   pending-cancel      → no change
	 *   cancelled / expired → no status change; a perpetual licence gets expires_at = paid-through
	 *
	 * @param int    $id     Subscription row id.
	 * @param string $status New status string.
	 * @return bool True on success.
	 * @throws \RuntimeException When the subscription does not exist.
	 */
	public function update_status( int $id, string $status ): bool {
		$sub        = $this->load_or_fail( $id );
		$old_status = $sub->status;

		$this->sub_repo->update_status( $id, $status );

		// Sync the licence. Billing never locks a licence by changing its status: access ends
		// because the paid term (expires_at + grace) runs out, and only an owner action
		// (suspend, revoke, terminate, refund) changes status. Audit F3/F4; Super Ledger D7.
		if ( null !== $sub->license_id ) {
			$license = $this->license_service->get_by_id( $sub->license_id );

			if ( null !== $license ) {
				switch ( $status ) {
					case 'active':
					case 'trial':
						// Bring a pending/inactive/expired licence to active; never lift a manual lock.
						if ( in_array( $license->status, array( 0, 2, 3 ), true ) && $license->is_within_expiry() ) {
							$this->license_service->change_status( $sub->license_id, self::LICENSE_STATUS_ACTIVE );
						}
						break;

					case 'cancelled':
					case 'expired':
						// A legacy perpetual licence bound to this subscription ends with the paid
						// period instead of living forever; a timed licence simply runs out.
						if ( null === $license->expires_at ) {
							$paid_until = ( null !== $sub->next_payment && strtotime( $sub->next_payment . ' UTC' ) > time() )
								? $sub->next_payment
								: gmdate( 'Y-m-d H:i:s' );
							$this->license_service->update( $sub->license_id, array( 'expires_at' => $paid_until ) );
						}
						break;

					case 'on-hold':
					case 'pending-cancel':
					default:
						break;
				}
			}
		}

		// Reload the sub so the fired action gets the updated record.
		$updated_sub = $this->sub_repo->find_by_id( $id ) ?? $sub;

		/**
		 * Fires after a subscription's status has changed.
		 *
		 * @param Subscription $updated_sub The updated subscription record.
		 * @param string       $old_status  The previous status.
		 * @param string       $status      The new status.
		 */
		do_action( 'wplm_subscription_status_changed', $updated_sub, $old_status, $status );

		return true;
	}

	// -------------------------------------------------------------------------
	// Notes
	// -------------------------------------------------------------------------

	/**
	 * Add an audit/status note to a subscription.
	 *
	 * @param int    $subscription_id Subscription row id.
	 * @param string $note            Note text.
	 * @param bool   $is_customer     Whether the note is visible to the customer.
	 * @return void
	 */
	public function add_note( int $subscription_id, string $note, bool $is_customer = false ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'wplm_subscription_notes',
			array(
				'subscription_id' => $subscription_id,
				'note'            => $note,
				'is_customer'     => (int) $is_customer,
				'created_by'      => get_current_user_id(),
				'created_at'      => current_time( 'mysql' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Load a subscription by id or throw a RuntimeException if not found.
	 *
	 * @param int $id Subscription row id.
	 * @return Subscription
	 * @throws \RuntimeException
	 */
	private function load_or_fail( int $id ): Subscription {
		$sub = $this->sub_repo->find_by_id( $id );

		if ( null === $sub ) {
			throw new \RuntimeException(
				sprintf( 'WPLM SubscriptionService: subscription with id %d not found.', $id )
			);
		}

		return $sub;
	}
}
