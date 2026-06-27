<?php
/**
 * Subscription renewal model.
 *
 * @package WPLM\Models
 */

namespace WPLM\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a renewal attempt record from wplm_subscription_renewals.
 */
class Renewal {

	public int $id              = 0;
	public int $subscription_id = 0;
	public ?int $order_id       = null;

	/**
	 * Renewal type: renewal | switch | resubscribe
	 */
	public string $type  = 'renewal';
	public float $amount = 0.0;

	/**
	 * Renewal status: success | failed | pending
	 */
	public string $status = 'pending';

	public ?string $gateway_txn  = null;
	public string $scheduled_for = '';
	public ?string $processed_at = null;
	public string $created_at    = '';

	/**
	 * Create a Renewal from a database row.
	 *
	 * @param array $row Raw DB row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$r                  = new self();
		$r->id              = (int) ( $row['id'] ?? 0 );
		$r->subscription_id = (int) ( $row['subscription_id'] ?? 0 );
		$r->order_id        = isset( $row['order_id'] ) ? (int) $row['order_id'] : null;
		$r->type            = $row['type'] ?? 'renewal';
		$r->amount          = (float) ( $row['amount'] ?? 0.0 );
		$r->status          = $row['status'] ?? 'pending';
		$r->gateway_txn     = $row['gateway_txn'] ?? null;
		$r->scheduled_for   = $row['scheduled_for'] ?? '';
		$r->processed_at    = $row['processed_at'] ?? null;
		$r->created_at      = $row['created_at'] ?? '';
		return $r;
	}

	/**
	 * Convert to a plain array for REST responses.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'subscription_id' => $this->subscription_id,
			'order_id'        => $this->order_id,
			'type'            => $this->type,
			'amount'          => $this->amount,
			'status'          => $this->status,
			'gateway_txn'     => $this->gateway_txn,
			'scheduled_for'   => $this->scheduled_for,
			'processed_at'    => $this->processed_at,
			'created_at'      => $this->created_at,
		);
	}
}
