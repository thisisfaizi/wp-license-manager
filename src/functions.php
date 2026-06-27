<?php
/**
 * WPLM global functions — public PHP API (Section 11).
 *
 * This file is required by Plugin::boot() after the container is initialised.
 * Every function is wrapped in function_exists() to prevent redeclaration errors
 * when the file is accidentally included more than once.
 *
 * Retrieval pattern for services:
 *   $c = \WPLM\Plugin::get_instance()->container();
 *   $svc = $c->make( \WPLM\Services\SomeService::class );
 *
 * @package WPLM
 */

defined( 'ABSPATH' ) || exit;

// =============================================================================
// LICENSE FUNCTIONS
// =============================================================================

if ( ! function_exists( 'wplm_create_license' ) ) {
	/**
	 * Create a new license.
	 *
	 * The $args array must include 'key_string'. All other fields are optional;
	 * see LicenseService::create() for the full list of accepted keys.
	 *
	 * @param array $args License field values. Required: key_string.
	 * @return \WPLM\Models\License The newly created, fully hydrated license model.
	 * @throws \InvalidArgumentException When key_string is not supplied.
	 * @throws \RuntimeException         On crypto failure or DB insertion error.
	 */
	function wplm_create_license( array $args ): \WPLM\Models\License {
		$c = \WPLM\Plugin::get_instance()->container();
		return $c->make( \WPLM\Services\LicenseService::class )->create( $args );
	}
}

if ( ! function_exists( 'wplm_get_license' ) ) {
	/**
	 * Retrieve a single license by integer ID or plaintext key string.
	 *
	 * When $key is numeric it is treated as a row ID; otherwise it is looked up
	 * as a plaintext key string (hashed internally for the DB query).
	 *
	 * @param int|string $key Integer row ID or plaintext license key string.
	 * @return \WPLM\Models\License|null License model, or null when not found.
	 */
	function wplm_get_license( $key ): ?\WPLM\Models\License {
		$c   = \WPLM\Plugin::get_instance()->container();
		$svc = $c->make( \WPLM\Services\LicenseService::class );

		if ( is_numeric( $key ) ) {
			return $svc->get_by_id( (int) $key );
		}

		return $svc->get_by_key( (string) $key );
	}
}

if ( ! function_exists( 'wplm_get_licenses' ) ) {
	/**
	 * Return a paginated, filtered list of licenses.
	 *
	 * Supported $args keys: status, product_id, order_id, user_id, per_page,
	 * page, orderby, order. See LicenseRepository::get_list() for full details.
	 *
	 * @param array $args Optional filter and pagination arguments.
	 * @return array{ items: \WPLM\Models\License[], total: int }
	 */
	function wplm_get_licenses( array $args = array() ): array {
		$c = \WPLM\Plugin::get_instance()->container();
		return $c->make( \WPLM\Services\LicenseService::class )->get_list( $args );
	}
}

if ( ! function_exists( 'wplm_update_license' ) ) {
	/**
	 * Update arbitrary fields on an existing license.
	 *
	 * When $key is numeric it is used as the row ID directly. When it is a
	 * string, the license is first resolved to obtain its ID.
	 *
	 * @param int|string $key  Integer row ID or plaintext license key string.
	 * @param array      $data Column/value pairs to update.
	 * @return bool True when at least one row was affected.
	 */
	function wplm_update_license( $key, array $data ): bool {
		$c   = \WPLM\Plugin::get_instance()->container();
		$svc = $c->make( \WPLM\Services\LicenseService::class );

		if ( is_numeric( $key ) ) {
			$id = (int) $key;
		} else {
			$license = $svc->get_by_key( (string) $key );
			if ( null === $license ) {
				return false;
			}
			$id = $license->id;
		}

		return $svc->update( $id, $data );
	}
}

if ( ! function_exists( 'wplm_delete_license' ) ) {
	/**
	 * Permanently delete a license row.
	 *
	 * When $key is numeric it is used as the row ID directly. When it is a
	 * string, the license is first resolved to obtain its ID.
	 *
	 * @param int|string $key Integer row ID or plaintext license key string.
	 * @return bool True when the row was deleted.
	 */
	function wplm_delete_license( $key ): bool {
		$c   = \WPLM\Plugin::get_instance()->container();
		$svc = $c->make( \WPLM\Services\LicenseService::class );

		if ( is_numeric( $key ) ) {
			$id = (int) $key;
		} else {
			$license = $svc->get_by_key( (string) $key );
			if ( null === $license ) {
				return false;
			}
			$id = $license->id;
		}

		return $svc->delete( $id );
	}
}

if ( ! function_exists( 'wplm_validate_license' ) ) {
	/**
	 * Validate a license key against the full Section 5.3 algorithm.
	 *
	 * Returns a response array that always contains a 'valid' boolean key.
	 * On failure it also includes a 'code' string describing the reason.
	 *
	 * @param string      $key         Plaintext license key from the client.
	 * @param string|null $fingerprint Optional HMAC-SHA256 device fingerprint.
	 * @return array Validation response: ['valid' => bool, 'code' => string, ...].
	 */
	function wplm_validate_license( string $key, ?string $fingerprint = null ): array {
		$c = \WPLM\Plugin::get_instance()->container();
		return $c->make( \WPLM\Services\LicenseService::class )->validate( $key, $fingerprint );
	}
}

if ( ! function_exists( 'wplm_renew_license' ) ) {
	/**
	 * Renew a license by extending its expiry date and marking it active.
	 *
	 * @param string $key   Plaintext license key string.
	 * @param string $until MySQL datetime string for the new expiry (e.g. '2026-12-31 23:59:59').
	 * @return \WPLM\Models\License|false Updated license model on success, false if key not found.
	 */
	function wplm_renew_license( string $key, string $until ): \WPLM\Models\License|false {
		$c = \WPLM\Plugin::get_instance()->container();
		return $c->make( \WPLM\Services\LicenseService::class )->renew( $key, $until );
	}
}

if ( ! function_exists( 'wplm_revoke_license' ) ) {
	/**
	 * Permanently revoke a license (status → 5).
	 *
	 * Resolves the license by key string, then delegates to RevocationService.
	 *
	 * @param string $key Plaintext license key string.
	 * @return bool True on success.
	 * @throws \RuntimeException When the license is not found or already terminated.
	 */
	function wplm_revoke_license( string $key ): bool {
		$c       = \WPLM\Plugin::get_instance()->container();
		$license = $c->make( \WPLM\Services\LicenseService::class )->get_by_key( $key );

		if ( null === $license ) {
			return false;
		}

		return $c->make( \WPLM\Services\RevocationService::class )->revoke_license( $license->id );
	}
}

if ( ! function_exists( 'wplm_suspend_license' ) ) {
	/**
	 * Temporarily suspend a license (status → 4).
	 *
	 * All activations are denied while suspended. Use wplm_reinstate_license()
	 * to lift the suspension.
	 *
	 * @param string $key Plaintext license key string.
	 * @return bool True on success.
	 * @throws \RuntimeException When the license is not found or already terminated.
	 */
	function wplm_suspend_license( string $key ): bool {
		$c       = \WPLM\Plugin::get_instance()->container();
		$license = $c->make( \WPLM\Services\LicenseService::class )->get_by_key( $key );

		if ( null === $license ) {
			return false;
		}

		return $c->make( \WPLM\Services\RevocationService::class )->suspend_license( $license->id );
	}
}

if ( ! function_exists( 'wplm_reinstate_license' ) ) {
	/**
	 * Reinstate a previously suspended or revoked license (status → 2 inactive).
	 *
	 * Only licenses with status 4 (suspended) or 5 (revoked) can be reinstated.
	 *
	 * @param string $key Plaintext license key string.
	 * @return bool True on success.
	 * @throws \RuntimeException When the license is not found, terminated, or not in a reinstate-able state.
	 */
	function wplm_reinstate_license( string $key ): bool {
		$c       = \WPLM\Plugin::get_instance()->container();
		$license = $c->make( \WPLM\Services\LicenseService::class )->get_by_key( $key );

		if ( null === $license ) {
			return false;
		}

		return $c->make( \WPLM\Services\RevocationService::class )->reinstate_license( $license->id );
	}
}

if ( ! function_exists( 'wplm_terminate_license' ) ) {
	/**
	 * Irreversibly terminate a license (status → 6).
	 *
	 * This is a permanent kill switch; the status cannot be changed afterward.
	 *
	 * @param string $key Plaintext license key string.
	 * @return bool True on success.
	 * @throws \RuntimeException When the license is not found.
	 */
	function wplm_terminate_license( string $key ): bool {
		$c       = \WPLM\Plugin::get_instance()->container();
		$license = $c->make( \WPLM\Services\LicenseService::class )->get_by_key( $key );

		if ( null === $license ) {
			return false;
		}

		return $c->make( \WPLM\Services\RevocationService::class )->terminate_license( $license->id );
	}
}

// =============================================================================
// DEVICE / MACHINE FUNCTIONS
// =============================================================================

if ( ! function_exists( 'wplm_activate_device' ) ) {
	/**
	 * Activate a device (machine) against a license key.
	 *
	 * Implements the Section 6.1 activation flow including blacklist checks,
	 * seat ceiling enforcement, and lease computation for floating licenses.
	 *
	 * @param string $key         Plaintext license key supplied by the client.
	 * @param string $fingerprint Raw (unhashed) device fingerprint from the client.
	 * @param array  $meta        Optional metadata: name, hostname, platform, app_version,
	 *                            ip_address, components (array).
	 * @return \WPLM\Models\Machine|\WP_Error Machine model on success, WP_Error on failure.
	 */
	function wplm_activate_device( string $key, string $fingerprint, array $meta = array() ): \WPLM\Models\Machine|\WP_Error {
		$c = \WPLM\Plugin::get_instance()->container();
		return $c->make( \WPLM\Services\ActivationService::class )->activate( $key, $fingerprint, $meta );
	}
}

if ( ! function_exists( 'wplm_deactivate_device' ) ) {
	/**
	 * Deactivate a device identified by its raw fingerprint for a given license key.
	 *
	 * The fingerprint is hashed internally before the database lookup.
	 *
	 * @param string $key         Plaintext license key.
	 * @param string $fingerprint Raw (unhashed) device fingerprint.
	 * @return bool|\WP_Error True on success (or already deactivated), WP_Error on failure.
	 */
	function wplm_deactivate_device( string $key, string $fingerprint ): bool|\WP_Error {
		$c = \WPLM\Plugin::get_instance()->container();
		return $c->make( \WPLM\Services\ActivationService::class )->deactivate( $key, $fingerprint );
	}
}

if ( ! function_exists( 'wplm_deactivate_device_by_id' ) ) {
	/**
	 * Deactivate a device by its machine row id.
	 *
	 * Unlike wplm_deactivate_device(), this does NOT re-hash a fingerprint — use
	 * it when you already hold the stored machine id (e.g. the account page),
	 * since the stored fingerprint is already HMAC-hashed and hashing it again
	 * would never match.
	 *
	 * @param int $machine_id Machine row id.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	function wplm_deactivate_device_by_id( int $machine_id ): bool|\WP_Error {
		$c = \WPLM\Plugin::get_instance()->container();
		return $c->make( \WPLM\Services\ActivationService::class )->deactivate_by_machine_id( $machine_id );
	}
}

if ( ! function_exists( 'wplm_get_devices' ) ) {
	/**
	 * Return all machines (devices) registered to a license key.
	 *
	 * Fetches all machine statuses (active, deactivated, revoked) for the
	 * resolved license. Returns an empty array when the key is not found.
	 *
	 * @param string $key Plaintext license key string.
	 * @return \WPLM\Models\Machine[] Array of Machine models (may be empty).
	 */
	function wplm_get_devices( string $key ): array {
		$c       = \WPLM\Plugin::get_instance()->container();
		$license = $c->make( \WPLM\Services\LicenseService::class )->get_by_key( $key );

		if ( null === $license ) {
			return array();
		}

		/** @var \WPLM\Repositories\MachineRepository $machine_repo */
		$machine_repo = $c->make( \WPLM\Repositories\MachineRepository::class );

		// Pass -1 to get all statuses (active, deactivated, revoked).
		return $machine_repo->get_by_license( $license->id, -1 );
	}
}

if ( ! function_exists( 'wplm_revoke_device' ) ) {
	/**
	 * Revoke a single machine / device by its row ID (machine status → 3).
	 *
	 * Does not revoke the parent license; only blocks this specific fingerprint.
	 *
	 * @param int $machine_id Machine row ID.
	 * @return bool True on success.
	 * @throws \RuntimeException When the machine is not found.
	 */
	function wplm_revoke_device( int $machine_id ): bool {
		$c = \WPLM\Plugin::get_instance()->container();
		return $c->make( \WPLM\Services\RevocationService::class )->revoke_device( $machine_id );
	}
}

if ( ! function_exists( 'wplm_record_heartbeat' ) ) {
	/**
	 * Record a heartbeat ping for an active floating-license device.
	 *
	 * Refreshes the lease expiry on the machine row and writes a heartbeat log
	 * entry. The fingerprint is hashed internally before the database lookup.
	 *
	 * @param string $fingerprint Raw (unhashed) device fingerprint from the client.
	 * @param int    $license_id  License row ID (must be resolved by the caller).
	 * @return \WPLM\Models\Machine|\WP_Error Refreshed Machine model on success, WP_Error on failure.
	 */
	function wplm_record_heartbeat( string $fingerprint, int $license_id ): \WPLM\Models\Machine|\WP_Error {
		$c = \WPLM\Plugin::get_instance()->container();
		return $c->make( \WPLM\Services\HeartbeatService::class )->record_heartbeat( $fingerprint, $license_id );
	}
}

// =============================================================================
// SUBSCRIPTION FUNCTIONS
// =============================================================================

if ( ! function_exists( 'wplm_create_subscription' ) ) {
	/**
	 * Create a new subscription.
	 *
	 * Required $args keys: user_id, billing_interval, billing_period,
	 * recurring_total, currency. See SubscriptionService::create() for full details.
	 *
	 * @param array $args Subscription field values.
	 * @return \WPLM\Models\Subscription The newly created subscription model.
	 * @throws \InvalidArgumentException When a required field is missing.
	 * @throws \RuntimeException         On database failure.
	 */
	function wplm_create_subscription( array $args ): \WPLM\Models\Subscription {
		$c = \WPLM\Plugin::get_instance()->container();
		return $c->make( \WPLM\Services\Subscriptions\SubscriptionService::class )->create( $args );
	}
}

if ( ! function_exists( 'wplm_get_subscription' ) ) {
	/**
	 * Retrieve a single subscription by its row ID.
	 *
	 * @param int $id Subscription row ID.
	 * @return \WPLM\Models\Subscription|null Subscription model, or null when not found.
	 */
	function wplm_get_subscription( int $id ): ?\WPLM\Models\Subscription {
		$c = \WPLM\Plugin::get_instance()->container();
		return $c->make( \WPLM\Services\Subscriptions\SubscriptionService::class )->get( $id );
	}
}

if ( ! function_exists( 'wplm_get_subscriptions' ) ) {
	/**
	 * Return a list of subscriptions, optionally filtered.
	 *
	 * Supported $args keys: user_id (int). When user_id is provided only that
	 * user's subscriptions are returned; otherwise all subscriptions are returned.
	 *
	 * @param array $args Optional filter arguments.
	 * @return \WPLM\Models\Subscription[]
	 */
	function wplm_get_subscriptions( array $args = array() ): array {
		$c = \WPLM\Plugin::get_instance()->container();
		return $c->make( \WPLM\Services\Subscriptions\SubscriptionService::class )->get_list( $args );
	}
}

if ( ! function_exists( 'wplm_pause_subscription' ) ) {
	/**
	 * Pause (put on-hold) an active or trial subscription.
	 *
	 * Sets subscription status to 'on-hold' and suspends the bound license.
	 * Only subscriptions with status 'active' or 'trial' may be paused.
	 *
	 * @param int $id Subscription row ID.
	 * @return bool True on success.
	 * @throws \RuntimeException When the subscription does not exist or cannot be paused.
	 */
	function wplm_pause_subscription( int $id ): bool {
		$c = \WPLM\Plugin::get_instance()->container();
		return $c->make( \WPLM\Services\Subscriptions\SubscriptionService::class )->pause( $id );
	}
}

if ( ! function_exists( 'wplm_resume_subscription' ) ) {
	/**
	 * Resume a paused (on-hold) subscription.
	 *
	 * Sets subscription status to 'active' and reinstates the bound license.
	 * Only subscriptions with status 'on-hold' may be resumed.
	 *
	 * @param int $id Subscription row ID.
	 * @return bool True on success.
	 * @throws \RuntimeException When the subscription does not exist or cannot be resumed.
	 */
	function wplm_resume_subscription( int $id ): bool {
		$c = \WPLM\Plugin::get_instance()->container();
		return $c->make( \WPLM\Services\Subscriptions\SubscriptionService::class )->resume( $id );
	}
}

if ( ! function_exists( 'wplm_cancel_subscription' ) ) {
	/**
	 * Cancel a subscription, either immediately or at the end of the current period.
	 *
	 * When $atPeriodEnd is true the status becomes 'pending-cancel' (license stays
	 * active until end_date). When false the status becomes 'cancelled' immediately
	 * and the bound license is revoked.
	 *
	 * @param int  $id            Subscription row ID.
	 * @param bool $at_period_end When true, cancel at end of current billing period.
	 * @return bool True on success.
	 * @throws \RuntimeException When the subscription does not exist.
	 */
	function wplm_cancel_subscription( int $id, bool $at_period_end = false ): bool {
		$c = \WPLM\Plugin::get_instance()->container();
		return $c->make( \WPLM\Services\Subscriptions\SubscriptionService::class )->cancel( $id, $at_period_end );
	}
}

if ( ! function_exists( 'wplm_switch_subscription' ) ) {
	/**
	 * Switch a subscription to a new billing plan (upgrade or downgrade).
	 *
	 * Proration is calculated automatically and can be overridden via the
	 * 'wplm_switch_proration' filter. The switch is recorded as a renewal row
	 * of type 'switch'.
	 *
	 * @param int   $id       Subscription row ID.
	 * @param array $new_plan New plan details. Accepted keys: product_id, variation_id,
	 *                        recurring_total, billing_interval, billing_period, max_activations.
	 * @return \WPLM\Models\Subscription|\WP_Error Updated subscription on success, WP_Error on failure.
	 */
	function wplm_switch_subscription( int $id, array $new_plan ): \WPLM\Models\Subscription|\WP_Error {
		$c = \WPLM\Plugin::get_instance()->container();
		return $c->make( \WPLM\Services\Subscriptions\SwitchManager::class )->switch_plan( $id, $new_plan );
	}
}

if ( ! function_exists( 'wplm_process_renewal' ) ) {
	/**
	 * Manually trigger the renewal processor for a single subscription.
	 *
	 * Charges the stored payment token via the gateway bridge, records the
	 * renewal, advances the next_payment date, and extends the license expiry.
	 * When no token is stored the 'wplm_subscription_manual_renewal_due' action
	 * is fired so the caller can trigger an invoice email.
	 *
	 * @param int $id Subscription row ID.
	 * @return void
	 * @throws \RuntimeException When the subscription does not exist (propagated from the processor).
	 */
	function wplm_process_renewal( int $id ): void {
		$c   = \WPLM\Plugin::get_instance()->container();
		$sub = $c->make( \WPLM\Repositories\SubscriptionRepository::class )->find_by_id( $id );

		if ( null === $sub ) {
			return;
		}

		$c->make( \WPLM\Services\Subscriptions\RenewalProcessor::class )->process_single( $sub );
	}
}

// =============================================================================
// LICENSE META FUNCTIONS
// =============================================================================

if ( ! function_exists( 'wplm_add_license_meta' ) ) {
	/**
	 * Add a new meta entry for a license.
	 *
	 * Unlike update, this always inserts a new row even if the key already exists,
	 * supporting multiple values per meta key.
	 *
	 * @param int    $id  License row ID.
	 * @param string $key Meta key.
	 * @param mixed  $val Meta value (arrays/objects are JSON-encoded).
	 * @return bool True on success.
	 */
	function wplm_add_license_meta( int $id, string $key, $val ): bool {
		global $wpdb;

		$table  = $wpdb->prefix . 'wplm_license_meta';
		$value  = is_array( $val ) || is_object( $val ) ? wp_json_encode( $val ) : (string) $val;
		$result = $wpdb->insert(
			$table,
			array(
				'license_id' => $id,
				'meta_key'   => $key,
				'meta_value' => $value,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		return false !== $result && $result > 0;
	}
}

if ( ! function_exists( 'wplm_get_license_meta' ) ) {
	/**
	 * Retrieve a meta value for a license by meta key.
	 *
	 * When multiple rows exist for the same key, the most recently inserted value
	 * is returned. Returns null when no matching row is found.
	 *
	 * @param int    $id  License row ID.
	 * @param string $key Meta key.
	 * @return mixed|null Scalar string value, or null when not found.
	 */
	function wplm_get_license_meta( int $id, string $key ): mixed {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_license_meta';
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM `{$table}` WHERE license_id = %d AND meta_key = %s ORDER BY id DESC LIMIT 1",
				$id,
				$key
			)
		);

		return $value;
	}
}

if ( ! function_exists( 'wplm_update_license_meta' ) ) {
	/**
	 * Update an existing meta value for a license, inserting if no row exists.
	 *
	 * When multiple rows exist for the key only the first (lowest ID) is updated.
	 *
	 * @param int    $id  License row ID.
	 * @param string $key Meta key.
	 * @param mixed  $val New meta value (arrays/objects are JSON-encoded).
	 * @return bool True on success.
	 */
	function wplm_update_license_meta( int $id, string $key, $val ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'wplm_license_meta';
		$value = is_array( $val ) || is_object( $val ) ? wp_json_encode( $val ) : (string) $val;

		// Check if the row already exists.
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE license_id = %d AND meta_key = %s ORDER BY id ASC LIMIT 1",
				$id,
				$key
			)
		);

		if ( null !== $existing_id ) {
			$result = $wpdb->update(
				$table,
				array( 'meta_value' => $value ),
				array( 'id' => (int) $existing_id ),
				array( '%s' ),
				array( '%d' )
			);
			return false !== $result;
		}

		// No existing row — insert.
		$result = $wpdb->insert(
			$table,
			array(
				'license_id' => $id,
				'meta_key'   => $key,
				'meta_value' => $value,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		return false !== $result && $result > 0;
	}
}

if ( ! function_exists( 'wplm_delete_license_meta' ) ) {
	/**
	 * Delete all meta rows for a license matching the given key.
	 *
	 * @param int    $id  License row ID.
	 * @param string $key Meta key.
	 * @return bool True when at least one row was deleted.
	 */
	function wplm_delete_license_meta( int $id, string $key ): bool {
		global $wpdb;

		$table  = $wpdb->prefix . 'wplm_license_meta';
		$result = $wpdb->delete(
			$table,
			array(
				'license_id' => $id,
				'meta_key'   => $key,
			),
			array( '%d', '%s' )
		);

		return false !== $result && $result > 0;
	}
}

// =============================================================================
// UTILITY FUNCTIONS
// =============================================================================

if ( ! function_exists( 'wplm_blacklist_add' ) ) {
	/**
	 * Add an entry to the WPLM blacklist.
	 *
	 * Accepted types: 'fingerprint', 'ip', 'email'.
	 *
	 * @param string $type   Entry type: fingerprint | ip | email.
	 * @param string $value  The value to blacklist.
	 * @param string $reason Human-readable reason (optional, stored for auditing).
	 * @return bool True when the row was inserted successfully.
	 */
	function wplm_blacklist_add( string $type, string $value, string $reason = '' ): bool {
		$c = \WPLM\Plugin::get_instance()->container();
		return $c->make( \WPLM\Services\RevocationService::class )->blacklist_add( $type, $value, $reason );
	}
}

if ( ! function_exists( 'wplm_generate_keys' ) ) {
	/**
	 * Generate a batch of unique license key strings from a generator configuration.
	 *
	 * Uses the generator's charset, chunk length, separator, prefix, and suffix to
	 * build each key. Uniqueness is verified against existing hashes in the DB;
	 * up to 5 retries per slot before throwing a RuntimeException.
	 *
	 * This function returns raw key strings only. To also create license rows, call
	 * GeneratorService::generate_batch() directly with a $create_fn callback.
	 *
	 * @param int $generator_id Row ID of the generator configuration to use.
	 * @param int $count        Number of key strings to generate.
	 * @return string[] Array of unique plaintext key strings.
	 * @throws \InvalidArgumentException When the generator ID does not exist.
	 * @throws \RuntimeException         When a unique key cannot be produced after max retries.
	 */
	function wplm_generate_keys( int $generator_id, int $count ): array {
		$c = \WPLM\Plugin::get_instance()->container();
		return $c->make( \WPLM\Services\GeneratorService::class )->generate_batch( $generator_id, $count );
	}
}
