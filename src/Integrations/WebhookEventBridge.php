<?php
/**
 * Bridges internal WPLM action hooks to outbound webhook deliveries.
 *
 * @package WPLM\Integrations
 */

namespace WPLM\Integrations;

defined( 'ABSPATH' ) || exit;

use WPLM\Services\WebhookService;

/**
 * Listens to the plugin's internal `wplm_*` action hooks and forwards each to
 * {@see WebhookService::dispatch()} using the dotted event slugs that the
 * Webhooks admin screen / REST API expose (e.g. license.created).
 *
 * This is the layer that makes registered webhooks actually fire on events.
 */
class WebhookEventBridge {

	/** @var WebhookService */
	private WebhookService $webhooks;

	/**
	 * @param WebhookService $webhooks Outbound dispatcher.
	 */
	public function __construct( WebhookService $webhooks ) {
		$this->webhooks = $webhooks;
	}

	/** Wire every internal action hook to its webhook event. */
	public function register(): void {
		add_action( 'wplm_license_created', array( $this, 'on_license_created' ), 10, 1 );
		add_action( 'wplm_license_revoked', array( $this, 'on_license_revoked' ), 10, 1 );
		add_action( 'wplm_license_terminated', array( $this, 'on_license_terminated' ), 10, 1 );
		add_action( 'wplm_license_status_changed', array( $this, 'on_license_status_changed' ), 10, 3 );

		add_action( 'wplm_machine_activated', array( $this, 'on_machine_activated' ), 10, 2 );
		add_action( 'wplm_machine_deactivated', array( $this, 'on_machine_deactivated' ), 10, 2 );
		add_action( 'wplm_machine_revoked', array( $this, 'on_machine_revoked' ), 10, 1 );

		add_action( 'wplm_subscription_created', array( $this, 'on_subscription_created' ), 10, 1 );
		add_action( 'wplm_subscription_renewed', array( $this, 'on_subscription_renewed' ), 10, 2 );
		add_action( 'wplm_subscription_payment_failed', array( $this, 'on_subscription_payment_failed' ), 10, 2 );
		add_action( 'wplm_subscription_cancelled', array( $this, 'on_subscription_cancelled' ), 10, 1 );
		add_action( 'wplm_subscription_status_changed', array( $this, 'on_subscription_status_changed' ), 10, 3 );
	}

	// -------------------------------------------------------------------------
	// License events
	// -------------------------------------------------------------------------

	/** @param mixed $license License model. */
	public function on_license_created( $license ): void {
		$this->webhooks->dispatch( 'license.created', $this->payload( $license ) );
	}

	/** @param mixed $license License model. */
	public function on_license_revoked( $license ): void {
		$this->webhooks->dispatch( 'license.revoked', $this->payload( $license ) );
	}

	/** @param mixed $license License model. */
	public function on_license_terminated( $license ): void {
		$this->webhooks->dispatch( 'license.terminated', $this->payload( $license ) );
	}

	/**
	 * Derive suspended / reinstated / expired / renewed events from a status
	 * change. Revoked + terminated have their own dedicated hooks above.
	 *
	 * @param mixed $license License model.
	 * @param int   $old     Previous status code.
	 * @param int   $new     New status code.
	 * @return void
	 */
	public function on_license_status_changed( $license, $old, $new ): void {
		$old = (int) $old;
		$new = (int) $new;

		if ( 4 === $new ) {
			$event = 'license.suspended';
		} elseif ( 3 === $new ) {
			$event = 'license.expired';
		} elseif ( in_array( $old, array( 4, 5 ), true ) && 2 === $new ) {
			$event = 'license.reinstated';
		} elseif ( 3 === $old && 1 === $new ) {
			$event = 'license.renewed';
		} else {
			return;
		}

		$this->webhooks->dispatch( $event, $this->payload( $license ) );
	}

	// -------------------------------------------------------------------------
	// Machine events
	// -------------------------------------------------------------------------

	/**
	 * @param mixed $machine Machine model.
	 * @param mixed $license License model.
	 * @return void
	 */
	public function on_machine_activated( $machine, $license = null ): void {
		$this->webhooks->dispatch(
			'license.activated',
			array_merge( $this->payload( $license ), array( 'machine' => $this->payload( $machine ) ) )
		);
	}

	/**
	 * @param mixed $machine Machine model.
	 * @param mixed $license License model.
	 * @return void
	 */
	public function on_machine_deactivated( $machine, $license = null ): void {
		$this->webhooks->dispatch(
			'license.deactivated',
			array_merge( $this->payload( $license ), array( 'machine' => $this->payload( $machine ) ) )
		);
	}

	/** @param mixed $machine Machine model. */
	public function on_machine_revoked( $machine ): void {
		$this->webhooks->dispatch( 'machine.revoked', $this->payload( $machine ) );
	}

	// -------------------------------------------------------------------------
	// Subscription events
	// -------------------------------------------------------------------------

	/** @param mixed $subscription Subscription model. */
	public function on_subscription_created( $subscription ): void {
		$this->webhooks->dispatch( 'subscription.created', $this->payload( $subscription ) );
	}

	/**
	 * @param mixed $subscription Subscription model.
	 * @param mixed $renewal      Renewal model (optional).
	 * @return void
	 */
	public function on_subscription_renewed( $subscription, $renewal = null ): void {
		$this->webhooks->dispatch(
			'subscription.renewed',
			array_merge( $this->payload( $subscription ), array( 'renewal' => $this->payload( $renewal ) ) )
		);
	}

	/**
	 * @param mixed $subscription Subscription model.
	 * @param int   $attempt      Dunning attempt number.
	 * @return void
	 */
	public function on_subscription_payment_failed( $subscription, $attempt = 0 ): void {
		$this->webhooks->dispatch(
			'subscription.payment_failed',
			array_merge( $this->payload( $subscription ), array( 'attempt' => (int) $attempt ) )
		);
	}

	/** @param mixed $subscription Subscription model. */
	public function on_subscription_cancelled( $subscription ): void {
		$this->webhooks->dispatch( 'subscription.cancelled', $this->payload( $subscription ) );
	}

	/**
	 * Derive paused / resumed / expired events from a subscription status change.
	 * Cancelled has its own dedicated hook above.
	 *
	 * @param mixed  $subscription Subscription model.
	 * @param string $old          Previous status.
	 * @param string $new          New status.
	 * @return void
	 */
	public function on_subscription_status_changed( $subscription, $old, $new ): void {
		if ( 'on-hold' === $new ) {
			$event = 'subscription.paused';
		} elseif ( 'on-hold' === $old && 'active' === $new ) {
			$event = 'subscription.resumed';
		} elseif ( 'expired' === $new ) {
			$event = 'subscription.expired';
		} else {
			return;
		}

		$this->webhooks->dispatch( $event, $this->payload( $subscription ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a serialisable payload from a model object.
	 *
	 * @param mixed $model A WPLM model (or null).
	 * @return array
	 */
	private function payload( $model ): array {
		if ( is_object( $model ) && method_exists( $model, 'to_array' ) ) {
			return (array) $model->to_array();
		}
		if ( is_object( $model ) ) {
			return (array) get_object_vars( $model );
		}
		return is_array( $model ) ? $model : array();
	}
}
