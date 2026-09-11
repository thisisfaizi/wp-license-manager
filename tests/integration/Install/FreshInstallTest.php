<?php
/**
 * Audit F9/F10: a fresh install must be able to sell, and a missing secret must not take the
 * whole site (and its admin) down.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Install;

use WPLM\Plugin;
use WPLM\Tests\TestCase;

class FreshInstallTest extends TestCase {

	/** F10: activation leaves a usable default key generator. */
	public function test_activation_seeds_a_default_generator(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wplm_generators" );
		delete_option( 'wplm_default_generator_id' );

		Plugin::activate();

		$id = (int) get_option( 'wplm_default_generator_id' );
		$this->assertGreaterThan( 0, $id );
		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wplm_generators WHERE id = %d", $id ) ) );

		Plugin::activate();
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wplm_generators" ), 'Re-activation does not add generators.' );
	}

	/** F10: when a licence cannot be issued, the order is not marked fulfilled and the owner is told. */
	public function test_failed_fulfilment_is_retryable_and_visible(): void {
		$plan     = $this->create_plan( array( array( 'name' => 'Lifetime', 'billing_type' => 'lifetime', 'price' => 10 ) ) );
		$product  = $this->create_plan_product( $plan['plan_id'] );
		$customer = $this->create_customer();

		$fail = static function () {
			throw new \RuntimeException( 'generator exploded' );
		};
		add_filter( 'wplm_generated_key_string', $fail );
		$bought = $this->buy( $product, $plan['packages'][0], $customer );
		remove_filter( 'wplm_generated_key_string', $fail );

		$order = wc_get_order( $bought['order']->get_id() );
		$this->assertSame( 0, $bought['license_id'] );
		$this->assertNotSame( '1', $order->get_meta( '_wplm_plan_fulfilled' ), 'A failed fulfilment must be retryable.' );
		$notes = implode( "\n", wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$this->assertStringContainsString( 'could not be issued', $notes, 'The owner sees why on the order.' );

		// Re-running fulfilment once the cause is fixed issues the licence.
		$order->update_status( 'processing' );
		$order->update_status( 'completed' );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '1', $order->get_meta( '_wplm_plan_fulfilled' ) );
	}

	/** F9: a missing encryption key must not fatal WordPress boot. */
	public function test_missing_secret_does_not_break_boot(): void {
		$saved = get_option( 'wplm_encryption_key' );
		delete_option( 'wplm_encryption_key' );

		$instance = new \ReflectionProperty( Plugin::class, 'instance' );
		$instance->setAccessible( true );
		$original = $instance->getValue();
		$instance->setValue( null, null );
		try {
			Plugin::get_instance();
			$this->assertTrue( true, 'Booting without a secret did not throw.' );
		} finally {
			$instance->setValue( null, $original );
			update_option( 'wplm_encryption_key', $saved, false );
		}
	}
}
