<?php
/**
 * Subscription model.
 *
 * @package WPLM\Models
 */

namespace WPLM\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a subscription record from wplm_subscriptions.
 */
class Subscription {

	public int $id               = 0;
	public ?int $parent_order_id = null;
	public int $user_id          = 0;
	public ?int $license_id      = null;

	/**
	 * Status: pending | trial | active | on-hold | pending-cancel | cancelled | expired
	 */
	public string $status        = 'pending';
	public int $billing_interval = 1;

	/**
	 * Billing period: day | week | month | year
	 */
	public string $billing_period  = 'month';
	public float $recurring_total  = 0.0;
	public string $currency        = 'USD';
	public float $signup_fee       = 0.0;
	public ?string $trial_end      = null;
	public ?string $next_payment   = null;
	public ?string $last_payment   = null;
	public ?string $end_date       = null;
	public ?string $payment_method = null;
	public ?int $payment_token_id  = null;
	public int $failed_attempts    = 0;
	public string $created_at      = '';
	public string $updated_at      = '';

	/**
	 * Whether the subscription is currently active (not in trial, on-hold, etc.).
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return 'active' === $this->status;
	}

	/**
	 * Whether the subscription is currently in a trial period.
	 *
	 * @return bool
	 */
	public function is_in_trial(): bool {
		if ( 'trial' !== $this->status ) {
			return false;
		}
		if ( null === $this->trial_end ) {
			return true;
		}
		return strtotime( $this->trial_end ) > time();
	}

	/**
	 * Human-readable label for the current status.
	 *
	 * @return string
	 */
	public function status_label(): string {
		$labels = array(
			'pending'        => __( 'Pending', 'wp-license-manager' ),
			'trial'          => __( 'Trial', 'wp-license-manager' ),
			'active'         => __( 'Active', 'wp-license-manager' ),
			'on-hold'        => __( 'On Hold', 'wp-license-manager' ),
			'pending-cancel' => __( 'Pending Cancellation', 'wp-license-manager' ),
			'cancelled'      => __( 'Cancelled', 'wp-license-manager' ),
			'expired'        => __( 'Expired', 'wp-license-manager' ),
		);
		return $labels[ $this->status ] ?? __( 'Unknown', 'wp-license-manager' );
	}

	/**
	 * Create a Subscription from a database row.
	 *
	 * @param array $row Raw DB row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$s                   = new self();
		$s->id               = (int) ( $row['id'] ?? 0 );
		$s->parent_order_id  = isset( $row['parent_order_id'] ) ? (int) $row['parent_order_id'] : null;
		$s->user_id          = (int) ( $row['user_id'] ?? 0 );
		$s->license_id       = isset( $row['license_id'] ) ? (int) $row['license_id'] : null;
		$s->status           = $row['status'] ?? 'pending';
		$s->billing_interval = (int) ( $row['billing_interval'] ?? 1 );
		$s->billing_period   = $row['billing_period'] ?? 'month';
		$s->recurring_total  = (float) ( $row['recurring_total'] ?? 0.0 );
		$s->currency         = $row['currency'] ?? 'USD';
		$s->signup_fee       = (float) ( $row['signup_fee'] ?? 0.0 );
		$s->trial_end        = $row['trial_end'] ?? null;
		$s->next_payment     = $row['next_payment'] ?? null;
		$s->last_payment     = $row['last_payment'] ?? null;
		$s->end_date         = $row['end_date'] ?? null;
		$s->payment_method   = $row['payment_method'] ?? null;
		$s->payment_token_id = isset( $row['payment_token_id'] ) ? (int) $row['payment_token_id'] : null;
		$s->failed_attempts  = (int) ( $row['failed_attempts'] ?? 0 );
		$s->created_at       = $row['created_at'] ?? '';
		$s->updated_at       = $row['updated_at'] ?? '';
		return $s;
	}

	/**
	 * Convert to a plain array for REST responses.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'id'               => $this->id,
			'parent_order_id'  => $this->parent_order_id,
			'user_id'          => $this->user_id,
			'license_id'       => $this->license_id,
			'status'           => $this->status,
			'status_label'     => $this->status_label(),
			'billing_interval' => $this->billing_interval,
			'billing_period'   => $this->billing_period,
			'recurring_total'  => $this->recurring_total,
			'currency'         => $this->currency,
			'signup_fee'       => $this->signup_fee,
			'trial_end'        => $this->trial_end,
			'next_payment'     => $this->next_payment,
			'last_payment'     => $this->last_payment,
			'end_date'         => $this->end_date,
			'payment_method'   => $this->payment_method,
			'payment_token_id' => $this->payment_token_id,
			'failed_attempts'  => $this->failed_attempts,
			'created_at'       => $this->created_at,
			'updated_at'       => $this->updated_at,
		);
	}
}
