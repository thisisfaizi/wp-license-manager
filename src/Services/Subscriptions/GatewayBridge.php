<?php
/**
 * Gateway bridge — WooCommerce payment-token charge abstraction for the native
 * subscription engine.
 *
 * @package WPLM\Services\Subscriptions
 */

namespace WPLM\Services\Subscriptions;

defined( 'ABSPATH' ) || exit;

/**
 * Thin adapter between the subscription renewal processor and whichever
 * WooCommerce payment gateway the merchant has configured.
 *
 * DESIGN INTENT — WPLM does not bundle gateway-specific code. Instead it
 * exposes the `wplm_gateway_charge` filter so merchant code (or a gateway
 * add-on plugin) can hook in a real charge implementation. The bridge itself
 * handles only token loading and the filter dispatch; if no handler is
 * registered it returns a WP_Error rather than throwing, so the caller can
 * treat it like any other failed charge.
 */
class GatewayBridge {

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Attempt to charge a stored WooCommerce payment token for the given amount.
	 *
	 * Process:
	 *   1. Load the WC_Payment_Token identified by $token_id. If not found,
	 *      return WP_Error( 'invalid_token', … ).
	 *   2. Apply the `wplm_gateway_charge` filter. If a hooked handler returns
	 *      a non-null value, that value is returned directly — it MUST be either
	 *      an associative array (success) or a WP_Error (failure).
	 *      Expected success array shape: [ 'txn' => string, … ].
	 *   3. If no handler is registered, return WP_Error( 'no_gateway', … ).
	 *      The merchant MUST hook `wplm_gateway_charge` to a real gateway.
	 *
	 * @param int    $token_id        WC payment token id (woocommerce_payment_tokens).
	 * @param float  $amount          Amount to charge in $currency units.
	 * @param string $currency        ISO 4217 currency code (e.g. 'USD').
	 * @param int    $subscription_id WPLM subscription id (for logging / meta).
	 * @return array|\WP_Error Associative result array on success, WP_Error on failure.
	 */
	public function charge( int $token_id, float $amount, string $currency, int $subscription_id ) {
		// 1. Load the WooCommerce payment token.
		$token = \WC_Payment_Tokens::get( $token_id );

		if ( ! $token ) {
			return new \WP_Error(
				'invalid_token',
				sprintf(
					/* translators: %d: payment token ID */
					__( 'Payment token %d not found or has been deleted.', 'wp-license-manager' ),
					$token_id
				)
			);
		}

		/**
		 * Filter: attempt the gateway charge for a subscription renewal.
		 *
		 * Handlers MUST return either:
		 *   - An associative array on success, containing at minimum a 'txn' key
		 *     with the gateway transaction reference string.
		 *   - A WP_Error on failure.
		 *
		 * Return null (the default) to signal that no handler is registered.
		 *
		 * @param array|WP_Error|null $result          Initially null; replace with a result.
		 * @param \WC_Payment_Token   $token           The loaded WC payment token.
		 * @param float               $amount          Amount to charge.
		 * @param string              $currency        ISO currency code.
		 * @param int                 $subscription_id WPLM subscription id.
		 */
		$result = apply_filters(
			'wplm_gateway_charge',
			null,
			$token,
			$amount,
			$currency,
			$subscription_id
		);

		// 2. If a handler returned a result, return it directly.
		if ( null !== $result ) {
			return $result;
		}

		// 3. No handler registered — return an actionable WP_Error.
		return new \WP_Error(
			'no_gateway',
			__(
				'No payment gateway handler registered. Hook the wplm_gateway_charge filter to integrate your gateway.',
				'wp-license-manager'
			)
		);
	}

	/**
	 * Return the WooCommerce gateway id associated with a stored payment token.
	 *
	 * @param int $token_id WC payment token id.
	 * @return string Gateway id string, or empty string if the token is not found.
	 */
	public function get_token_gateway( int $token_id ): string {
		$token = \WC_Payment_Tokens::get( $token_id );
		return $token ? $token->get_gateway_id() : '';
	}
}
