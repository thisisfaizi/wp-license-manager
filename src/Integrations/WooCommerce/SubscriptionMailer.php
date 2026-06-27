<?php
/**
 * Sends WPLM subscription lifecycle emails from the templates/emails/ templates.
 *
 * @package WPLM\Integrations\WooCommerce
 */

namespace WPLM\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use WPLM\Models\Subscription;
use WPLM\Repositories\LicenseRepository;
use WPLM\Support\Logger;

/**
 * Hooks the subscription lifecycle action hooks and delivers the matching HTML
 * email to the subscriber. Each rendered body passes through the
 * `wplm_subscription_email_content` filter so it can be customised.
 *
 * Templates live in templates/emails/ and are WooCommerce-styled (they call the
 * woocommerce_email_header / _footer actions for the wrapper).
 */
class SubscriptionMailer {

	/** @var LicenseRepository */
	private LicenseRepository $license_repo;

	/**
	 * @param LicenseRepository $license_repo License data-access layer.
	 */
	public function __construct( LicenseRepository $license_repo ) {
		$this->license_repo = $license_repo;
	}

	/** Register the lifecycle hooks. */
	public function register(): void {
		add_action( 'wplm_subscription_created', array( $this, 'on_created' ), 10, 1 );
		add_action( 'wplm_subscription_renewed', array( $this, 'on_renewed' ), 10, 2 );
		add_action( 'wplm_subscription_payment_failed', array( $this, 'on_payment_failed' ), 10, 2 );
		add_action( 'wplm_subscription_cancelled', array( $this, 'on_cancelled' ), 10, 1 );
	}

	// -------------------------------------------------------------------------
	// Hook callbacks
	// -------------------------------------------------------------------------

	/** @param Subscription $subscription Newly created subscription. */
	public function on_created( $subscription ): void {
		$this->send(
			'subscription-created',
			__( 'Your subscription is active', 'wp-license-manager' ),
			$subscription,
			array( 'order' => $this->resolve_order( $subscription ) )
		);
	}

	/**
	 * @param Subscription $subscription Renewed subscription.
	 * @param mixed        $renewal      Renewal model (optional).
	 */
	public function on_renewed( $subscription, $renewal = null ): void {
		$this->send(
			'renewal-processed',
			__( 'Your subscription renewal was processed', 'wp-license-manager' ),
			$subscription,
			array(
				'renewal'       => $renewal,
				'renewal_order' => $this->resolve_order( $subscription ),
			)
		);
	}

	/**
	 * @param Subscription $subscription Subscription with the failed payment.
	 * @param int          $attempt      Current dunning attempt number.
	 */
	public function on_payment_failed( $subscription, $attempt = 0 ): void {
		$this->send(
			'payment-failed',
			__( 'Action required: subscription payment failed', 'wp-license-manager' ),
			$subscription,
			array(
				'renewal'            => null,
				'attempts_remaining' => max( 0, 3 - (int) $attempt ),
				'retry_date'         => $subscription->next_payment
					? date_i18n( get_option( 'date_format' ), strtotime( (string) $subscription->next_payment ) )
					: '',
			)
		);
	}

	/** @param Subscription $subscription Cancelled subscription. */
	public function on_cancelled( $subscription ): void {
		$this->send(
			'subscription-cancelled',
			__( 'Your subscription has been cancelled', 'wp-license-manager' ),
			$subscription,
			array( 'at_period_end' => 'pending-cancel' === ( $subscription->status ?? '' ) )
		);
	}

	// -------------------------------------------------------------------------
	// Rendering / sending
	// -------------------------------------------------------------------------

	/**
	 * Render a template and send it to the subscriber.
	 *
	 * @param string       $template     Template basename (without .php).
	 * @param string       $subject      Email subject / heading.
	 * @param Subscription $subscription The subscription.
	 * @param array        $extra        Extra variables exposed to the template.
	 * @return void
	 */
	private function send( string $template, string $subject, $subscription, array $extra = array() ): void {
		if ( ! $subscription instanceof Subscription ) {
			return;
		}

		$path = WPLM_PLUGIN_DIR . 'templates/emails/' . $template . '.php';
		if ( ! file_exists( $path ) ) {
			return;
		}

		$to = $this->resolve_email( $subscription );
		if ( '' === $to ) {
			return;
		}

		// Shared template variables.
		$license       = ( $subscription->license_id ?? 0 )
			? $this->license_repo->find_by_id( (int) $subscription->license_id )
			: null;
		$email_heading = $subject;
		$sent_to_admin = false;
		$email         = null;

		// Expose extras (order, renewal, etc.) as locals for the template.
		$order         = $extra['order'] ?? null;
		$renewal_order = $extra['renewal_order'] ?? null;
		$renewal       = $extra['renewal'] ?? null;
		$attempts_remaining = $extra['attempts_remaining'] ?? 0;
		$retry_date    = $extra['retry_date'] ?? '';
		$at_period_end = $extra['at_period_end'] ?? false;

		try {
			ob_start();
			include $path;
			$body = (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			// Never let a template error break the subscription lifecycle.
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			Logger::error( 'SubscriptionMailer: failed to render ' . $template . ' — ' . $e->getMessage() );
			return;
		}

		/**
		 * Filter a subscription email body before delivery.
		 *
		 * @param string       $body         Rendered HTML email body.
		 * @param string       $template     Template identifier (e.g. 'renewal-processed').
		 * @param Subscription $subscription The subscription the email concerns.
		 */
		$body = (string) apply_filters( 'wplm_subscription_email_content', $body, $template, $subscription );

		wp_mail(
			$to,
			$subject,
			$body,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	/**
	 * Resolve the subscriber's email address.
	 *
	 * @param Subscription $subscription Subscription.
	 * @return string Email address, or '' when none can be determined.
	 */
	private function resolve_email( Subscription $subscription ): string {
		$order = $this->resolve_order( $subscription );
		if ( $order instanceof \WC_Order && $order->get_billing_email() ) {
			return $order->get_billing_email();
		}

		$user = ( $subscription->user_id ?? 0 ) ? get_user_by( 'id', (int) $subscription->user_id ) : false;
		return $user ? (string) $user->user_email : '';
	}

	/**
	 * Resolve the parent WooCommerce order, if available.
	 *
	 * @param Subscription $subscription Subscription.
	 * @return \WC_Order|null
	 */
	private function resolve_order( Subscription $subscription ) {
		if ( empty( $subscription->parent_order_id ) || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( (int) $subscription->parent_order_id );
		return $order instanceof \WC_Order ? $order : null;
	}
}
