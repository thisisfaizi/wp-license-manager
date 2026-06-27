<?php
/**
 * Webhook model.
 *
 * @package WPLM\Models
 */

namespace WPLM\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a webhook endpoint from wplm_webhooks.
 */
class Webhook {

	public int $id            = 0;
	public string $name       = '';
	public string $target_url = '';

	/** Decoded JSON array of event slugs to listen for. */
	public array $events = array();

	public string $secret = '';

	/**
	 * Delivery format: json (signed envelope) | slack | discord | email.
	 */
	public string $format = 'json';

	/**
	 * Status: 1 = active, 0 = paused.
	 */
	public int $status = 1;

	public string $created_at = '';

	/**
	 * Whether this webhook is currently active.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return 1 === $this->status;
	}

	/**
	 * Create a Webhook from a database row.
	 * JSON-decodes the `events` column safely.
	 *
	 * @param array $row Raw DB row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$w             = new self();
		$w->id         = (int) ( $row['id'] ?? 0 );
		$w->name       = $row['name'] ?? '';
		$w->target_url = $row['target_url'] ?? '';
		$w->events     = json_decode( $row['events'] ?? '[]', true ) ?? array();
		$w->secret     = $row['secret'] ?? '';
		$w->format     = $row['format'] ?? 'json';
		$w->status     = (int) ( $row['status'] ?? 1 );
		$w->created_at = $row['created_at'] ?? '';
		return $w;
	}

	/**
	 * Convert to a plain array for REST responses (excludes secret).
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'id'         => $this->id,
			'name'       => $this->name,
			'target_url' => $this->target_url,
			'events'     => $this->events,
			'format'     => $this->format,
			'status'     => $this->status,
		);
	}
}
