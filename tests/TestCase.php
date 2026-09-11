<?php
/**
 * Base class for WPLM integration tests.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests;

use WPLM\Plugin;
use WPLM\Services\PlanService;

/**
 * Real WordPress + WooCommerce, one transaction per test (rolled back by WP_UnitTestCase).
 * Helpers build plans, buy them through real WooCommerce orders, and move time by editing
 * the stored dates — never by sleeping.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/** Resolve a service from the plugin container. */
	protected function make( string $class ) {
		return Plugin::get_instance()->container()->make( $class );
	}

	/**
	 * Create a plan with the given packages; returns the hydrated packages in order.
	 *
	 * @param array[] $packages Package field arrays (see PlanService::sync_packages()).
	 * @return array{plan_id:int, packages:\WPLM\Models\Package[]}
	 */
	protected function create_plan( array $packages, array $plan = array() ): array {
		/** @var PlanService $plans */
		$plans   = $this->make( PlanService::class );
		$plan_id = $plans->create_plan( $plan + array( 'name' => 'Test plan' ) );
		$plans->sync_packages( $plan_id, $packages );
		return array(
			'plan_id'  => $plan_id,
			'packages' => $plans->get( $plan_id )->packages,
		);
	}

	/** A private WooCommerce product bound to a plan. */
	protected function create_plan_product( int $plan_id ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Licensed product' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10' );
		$product->update_meta_data( '_wplm_plan_id', $plan_id );
		$product->save();
		return $product;
	}

	/** A customer with a billing email. */
	protected function create_customer(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_user_meta( $user_id, 'billing_email', 'customer' . $user_id . '@example.org' );
		return $user_id;
	}

	/**
	 * Buy a package through a real WooCommerce order and confirm payment the way the owner
	 * does for a bank transfer: on-hold, then Completed.
	 *
	 * @return array{order:\WC_Order, license_id:int, subscription_id:int}
	 */
	protected function buy( \WC_Product $product, \WPLM\Models\Package $package, int $customer_id, string $payment_method = 'bacs' ): array {
		$order   = wc_create_order( array( 'customer_id' => $customer_id ) );
		$item_id = $order->add_product( $product, 1 );
		$item    = $order->get_item( $item_id );
		$item->add_meta_data( '_wplm_package_id', $package->id, true );
		$item->save();
		$order->set_payment_method( $payment_method );
		$order->set_billing_email( 'customer' . $customer_id . '@example.org' );
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'on-hold' );
		$order->update_status( 'completed' );

		$order           = wc_get_order( $order->get_id() );
		$license_id      = 0;
		$subscription_id = 0;
		foreach ( $order->get_items() as $line ) {
			$ids             = json_decode( (string) $line->get_meta( '_wplm_license_ids' ), true );
			$license_id      = (int) ( $ids[0] ?? 0 );
			$subscription_id = (int) $line->get_meta( '_wplm_subscription_id' );
		}
		return array(
			'order'           => $order,
			'license_id'      => $license_id,
			'subscription_id' => $subscription_id,
		);
	}

	/** What the test product's client sends as its fingerprint: sha256_hex('acme-office|' + raw). */
	protected function client_fp( string $raw ): string {
		return hash( 'sha256', 'acme-office|' . $raw );
	}

	/**
	 * An entitlement licence of the test profile (no lines yet) activated on one machine.
	 *
	 * @return array{license:\WPLM\Models\License, machine:\WPLM\Models\Machine, fp:string}
	 */
	protected function activated_profile_licence( string $raw_fingerprint = 'office-pc' ): array {
		$license = $this->make( \WPLM\Services\LicenseService::class )->create(
			array(
				'key_string'      => 'SL-' . wp_generate_password( 16, false ),
				'profile'         => 'acme-office',
				'max_activations' => 1,
			)
		);
		$fp      = $this->client_fp( $raw_fingerprint );
		$machine = $this->make( \WPLM\Services\ActivationService::class )->activate( $license->license_key, $fp );
		$this->assertNotWPError( $machine );
		return $this->reload(
			array(
				'license' => $license,
				'machine' => $machine,
				'fp'      => $fp,
			)
		);
	}

	/** Re-read the licence and machine of a bound pair from the database. */
	protected function reload( array $bound ): array {
		$bound['license'] = $this->make( \WPLM\Repositories\LicenseRepository::class )->find_by_id( $bound['license']->id );
		$bound['machine'] = $this->make( \WPLM\Repositories\MachineRepository::class )->find_by_id( $bound['machine']->id );
		return $bound;
	}

	/** The raw licences row. */
	protected function license_row( int $id ): array {
		global $wpdb;
		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wplm_licenses WHERE id = %d", $id ), ARRAY_A );
	}

	/** The raw subscriptions row. */
	protected function subscription_row( int $id ): array {
		global $wpdb;
		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wplm_subscriptions WHERE id = %d", $id ), ARRAY_A );
	}

	/** Overwrite columns on a WPLM table row (time travel). */
	protected function set_row( string $table, int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'wplm_' . $table, $data, array( 'id' => $id ) );
	}

	/** A UTC MySQL datetime offset from now by the given seconds. */
	protected function utc( int $offset_seconds = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $offset_seconds );
	}

	/** Orders linked to a subscription (filtered in PHP: meta queries need HPOS). */
	protected function renewal_orders( int $subscription_id ): array {
		$orders = wc_get_orders(
			array(
				'limit' => -1,
				'type'  => 'shop_order',
			)
		);
		return array_values(
			array_filter(
				$orders,
				static fn( $o ) => (string) $subscription_id === (string) $o->get_meta( '_wplm_subscription_id' )
			)
		);
	}

	/** Assert two MySQL datetimes are within a few seconds of each other. */
	protected function assertSameMoment( string $expected, ?string $actual, int $tolerance = 5, string $message = '' ): void {
		$this->assertNotNull( $actual, $message ?: 'Expected a datetime, got NULL.' );
		$this->assertLessThanOrEqual( $tolerance, abs( strtotime( $expected . ' UTC' ) - strtotime( $actual . ' UTC' ) ), $message ?: "Expected {$expected}, got {$actual}." );
	}
}
