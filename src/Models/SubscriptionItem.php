<?php
/**
 * Subscription item model.
 *
 * @package WPLM\Models
 */

namespace WPLM\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a line item within a subscription from wplm_subscription_items.
 */
class SubscriptionItem {

	public int $id              = 0;
	public int $subscription_id = 0;
	public int $product_id      = 0;
	public ?int $variation_id   = null;
	public int $quantity        = 1;
	public float $line_total    = 0.0;

	/** Decoded JSON meta payload for this line item. */
	public array $meta = array();

	/**
	 * Create a SubscriptionItem from a database row.
	 * JSON-decodes the `meta` column safely.
	 *
	 * @param array $row Raw DB row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$i                  = new self();
		$i->id              = (int) ( $row['id'] ?? 0 );
		$i->subscription_id = (int) ( $row['subscription_id'] ?? 0 );
		$i->product_id      = (int) ( $row['product_id'] ?? 0 );
		$i->variation_id    = isset( $row['variation_id'] ) ? (int) $row['variation_id'] : null;
		$i->quantity        = (int) ( $row['quantity'] ?? 1 );
		$i->line_total      = (float) ( $row['line_total'] ?? 0.0 );
		$i->meta            = json_decode( $row['meta'] ?? '[]', true ) ?? array();
		return $i;
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
			'product_id'      => $this->product_id,
			'variation_id'    => $this->variation_id,
			'quantity'        => $this->quantity,
			'line_total'      => $this->line_total,
			'meta'            => $this->meta,
		);
	}
}
