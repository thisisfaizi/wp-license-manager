<?php
/**
 * M5-27a step 1 — walk WPLM's existing purchase → activate → renewal paths on a
 * real WordPress + WooCommerce, and print what actually happens.
 *
 * Run: wp eval-file walk.php
 * Every row it creates is tagged "M527-AUDIT" and its ids are stored in the
 * option `m527_audit_ids`, so cleanup.php can remove exactly these rows.
 */

use WPLM\Plugin;
use WPLM\Services\ActivationService;
use WPLM\Services\LicenseService;
use WPLM\Services\PlanService;
use WPLM\Services\Subscriptions\RenewalProcessor;
use WPLM\Services\Subscriptions\SubscriptionService;
use WPLM\Repositories\SubscriptionRepository;
use WPLM\Integrations\WooCommerce\SelfServiceRenewal;

global $wpdb;
$c   = Plugin::get_instance()->container();
$ids = array( 'plans' => array(), 'products' => array(), 'orders' => array(), 'users' => array(), 'licenses' => array(), 'subscriptions' => array(), 'tokens' => array() );
$out = array();

$lic = static function ( int $id ) use ( $wpdb ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT id,status,expires_at,grace_days,max_activations,activation_count,product_id FROM {$wpdb->prefix}wplm_licenses WHERE id=%d", $id ), ARRAY_A );
};
$sub = static function ( int $id ) use ( $wpdb ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT id,status,license_id,next_payment,last_payment,payment_token_id,failed_attempts FROM {$wpdb->prefix}wplm_subscriptions WHERE id=%d", $id ), ARRAY_A );
};
$step = static function ( string $name, callable $fn ) use ( &$out ) {
	try {
		$out[ $name ] = $fn();
	} catch ( \Throwable $e ) {
		$out[ $name ] = array( 'THREW' => get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	}
};

// ---------------------------------------------------------------- A. setup
$plans = $c->make( PlanService::class );
$plan_id = $plans->create_plan( array( 'name' => 'M527-AUDIT plan' ) );
$plans->sync_packages(
	$plan_id,
	array(
		array( 'name' => 'M527-AUDIT monthly', 'billing_type' => 'recurring', 'billing_period' => 'month', 'billing_interval' => 1, 'price' => 1000, 'max_activations' => 1 ),
		array( 'name' => 'M527-AUDIT lifetime', 'billing_type' => 'lifetime', 'price' => 5000, 'max_activations' => 1 ),
	)
);
$ids['plans'][] = $plan_id;
$packages       = $plans->get( $plan_id )->packages;
$monthly        = $packages[0];
$lifetime       = $packages[1];

$product = new WC_Product_Simple();
$product->set_name( 'M527-AUDIT Super Ledger' );
$product->set_status( 'private' );
$product->set_regular_price( '1000' );
$product->update_meta_data( '_wplm_plan_id', $plan_id );
$product_id        = $product->save();
$ids['products'][] = $product_id;

$user_id = username_exists( 'm527audit' ) ?: wp_create_user( 'm527audit', wp_generate_password(), 'm527audit@example.test' );
if ( ! in_array( $user_id, $ids['users'], true ) ) {
	$ids['users'][] = $user_id;
}
$out['A.setup'] = compact( 'plan_id', 'product_id', 'user_id' ) + array( 'monthly_pkg' => $monthly->id, 'lifetime_pkg' => $lifetime->id );

// ------------------------------------------ B. buy the monthly plan by bank transfer
$license_id = 0;
$sub_id     = 0;
$step(
	'B.buy_monthly_bacs',
	static function () use ( $user_id, $product, $monthly, &$ids, &$license_id, &$sub_id, $lic, $sub ) {
		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		$item_id = $order->add_product( $product, 1 );
		$item    = $order->get_item( $item_id );
		$item->add_meta_data( '_wplm_package_id', $monthly->id, true );
		$item->save();
		$order->set_payment_method( 'bacs' );
		$order->calculate_totals();
		$order->save();
		$ids['orders'][] = $order->get_id();
		$order->update_status( 'on-hold', 'M527-AUDIT awaiting bank transfer' );
		$after_on_hold = get_post_meta( $order->get_id(), '_wplm_plan_fulfilled', true ) ?: wc_get_order( $order->get_id() )->get_meta( '_wplm_plan_fulfilled' );
		$order->update_status( 'completed', 'M527-AUDIT owner confirmed transfer' );
		$order = wc_get_order( $order->get_id() );
		foreach ( $order->get_items() as $it ) {
			$lids       = json_decode( (string) $it->get_meta( '_wplm_license_ids' ), true );
			$license_id = (int) ( $lids[0] ?? 0 );
			$sub_id     = (int) $it->get_meta( '_wplm_subscription_id' );
		}
		$ids['licenses'][]      = $license_id;
		$ids['subscriptions'][] = $sub_id;
		return array(
			'order'                    => $order->get_id(),
			'fulfilled_while_on_hold'  => $after_on_hold,
			'license'                  => $lic( $license_id ),
			'subscription'             => $sub( $sub_id ),
		);
	}
);

// ------------------------------------------------------------ C. activate a PC
$step(
	'C.activate_pc1',
	static function () use ( $c, $license_id, $lic ) {
		$license = $c->make( LicenseService::class )->get_by_id( $license_id );
		$m       = $c->make( ActivationService::class )->activate( $license->license_key, 'm527-audit-fp-1', array( 'hostname' => 'OFFICE-PC' ) );
		$m2      = $c->make( ActivationService::class )->activate( $license->license_key, 'm527-audit-fp-2', array( 'hostname' => 'SECOND-PC' ) );
		return array(
			'pc1'                   => is_wp_error( $m ) ? $m->get_error_code() : 'machine ' . $m->id,
			'pc2_over_limit'        => is_wp_error( $m2 ) ? $m2->get_error_code() : 'machine ' . $m2->id,
			'license'               => $lic( $license_id ),
			'signed_token_payload'  => json_decode( base64_decode( strtr( explode( '.', $license->signature )[0], '-_', '+/' ) ), true ),
		);
	}
);

// --------------------------- D. the month passes, no payment token (manual payer)
$step(
	'D.renewal_due_no_token',
	static function () use ( $c, $wpdb, $sub_id, $license_id, $lic, $sub ) {
		$wpdb->update( $wpdb->prefix . 'wplm_subscriptions', array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ), array( 'id' => $sub_id ) );
		$fired = 0;
		add_action( 'wplm_subscription_manual_renewal_due', static function () use ( &$fired ) { ++$fired; } );
		$c->make( RenewalProcessor::class )->process_due_renewals();
		$c->make( RenewalProcessor::class )->process_due_renewals();
		$license = $c->make( LicenseService::class )->get_by_id( $license_id );
		$v       = $c->make( LicenseService::class )->validate( $license->license_key, 'm527-audit-fp-1' );
		return array(
			'manual_renewal_due_fired_per_2_runs' => $fired,
			'listeners_other_than_ours'           => has_action( 'wplm_subscription_manual_renewal_due' ),
			'subscription'                        => $sub( $sub_id ),
			'license'                             => $lic( $license_id ),
			'validate_3_days_after_unpaid_due'    => array( 'valid' => $v['valid'], 'code' => $v['code'] ?? null ),
		);
	}
);

// ----------------------------- E. licence past expiry, then customer pays late
$step(
	'E.expired_then_self_service_renewal',
	static function () use ( $c, $wpdb, $sub_id, $license_id, $lic, $sub, &$ids ) {
		$wpdb->update( $wpdb->prefix . 'wplm_licenses', array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ), array( 'id' => $license_id ) );
		$ls      = $c->make( LicenseService::class );
		$license = $ls->get_by_id( $license_id );
		$v1      = $ls->validate( $license->license_key, 'm527-audit-fp-1' );
		$status_after_expired_validate = $lic( $license_id )['status'];

		$url   = $c->make( SelfServiceRenewal::class )->create_renewal_order( $sub_id );
		$oid   = (int) $wpdb->get_var( "SELECT MAX(order_id) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key='_wplm_subscription_id'" );
		if ( ! $oid ) {
			$oid = (int) $wpdb->get_var( "SELECT MAX(post_id) FROM {$wpdb->postmeta} WHERE meta_key='_wplm_subscription_id'" );
		}
		$ids['orders'][] = $oid;
		$order = wc_get_order( $oid );
		$order->set_payment_method( 'bacs' );
		$order->save();
		$order->update_status( 'completed', 'M527-AUDIT late renewal paid' );
		$v2 = $ls->validate( $license->license_key, 'm527-audit-fp-1' );
		return array(
			'validate_when_expired'         => array( 'valid' => $v1['valid'], 'code' => $v1['code'] ?? null ),
			'status_after_expired_validate' => $status_after_expired_validate,
			'renewal_order'                 => $oid,
			'pay_url_created'               => (bool) $url,
			'license_after_paid'            => $lic( $license_id ),
			'signature_refreshed'           => $ls->get_by_id( $license_id )->signature !== $license->signature,
			'subscription_after_paid'       => $sub( $sub_id ),
			'validate_after_paid'           => array( 'valid' => $v2['valid'], 'code' => $v2['code'] ?? null ),
		);
	}
);

// ------------------------------- F. a card customer whose charge fails (dunning)
$step(
	'F.card_charge_fails',
	static function () use ( $c, $wpdb, $user_id, $sub_id, $license_id, $lic, $sub, &$ids ) {
		$token = new WC_Payment_Token_CC();
		$token->set_token( 'm527-audit-tok' );
		$token->set_gateway_id( 'm527audit' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->set_user_id( $user_id );
		$token->save();
		$ids['tokens'][] = $token->get_id();
		$wpdb->update( $wpdb->prefix . 'wplm_subscriptions', array( 'payment_token_id' => $token->get_id(), 'next_payment' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), 'status' => 'active' ), array( 'id' => $sub_id ) );
		add_filter( 'wplm_gateway_charge', static fn() => new WP_Error( 'declined', 'M527-AUDIT card declined' ) );

		$rp = $c->make( RenewalProcessor::class );
		$rp->process_due_renewals();
		$after_first = array( 'subscription' => $sub( $sub_id ), 'license' => $lic( $license_id ) );

		// The retry day arrives: make the scheduled retry due and run cron again.
		$wpdb->update( $wpdb->prefix . 'wplm_subscriptions', array( 'next_payment' => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) ), array( 'id' => $sub_id ) );
		$picked = count( array_filter( $c->make( SubscriptionRepository::class )->get_due_for_renewal(), static fn( $s ) => $s->id === $sub_id ) );
		$rp->process_due_renewals();
		return array(
			'after_first_failure'           => $after_first,
			'retry_picked_up_by_due_query'  => $picked,
			'after_retry_day_cron'          => array( 'subscription' => $sub( $sub_id ), 'license' => $lic( $license_id ) ),
		);
	}
);

// ------------------------------- G. admin deactivates the same machine twice
$step(
	'G.admin_deactivate_twice',
	static function () use ( $c, $wpdb, $license_id, $lic ) {
		$mid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wplm_machines WHERE license_id=%d ORDER BY id LIMIT 1", $license_id ) );
		$as  = $c->make( ActivationService::class );
		$before = $lic( $license_id )['activation_count'];
		$as->deactivate_by_machine_id( $mid );
		$once = $lic( $license_id )['activation_count'];
		$as->deactivate_by_machine_id( $mid );
		$twice = $lic( $license_id )['activation_count'];
		return compact( 'mid', 'before', 'once', 'twice' ) + array( 'active_machines' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wplm_machines WHERE license_id=%d AND status=1", $license_id ) ) );
	}
);

update_option( 'm527_audit_ids', $ids, false );
echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
echo 'ids: ', wp_json_encode( $ids ), "\n";
