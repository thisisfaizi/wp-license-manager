<?php
/**
 * Remove exactly the rows walk.php created (ids from the `m527_audit_ids` option).
 * Run: wp eval-file cleanup.php
 */
global $wpdb;
$ids = get_option( 'm527_audit_ids' );
if ( ! is_array( $ids ) ) {
	echo "nothing to clean\n";
	return;
}
$p = $wpdb->prefix;
foreach ( $ids['orders'] ?? array() as $id ) {
	if ( $o = wc_get_order( (int) $id ) ) {
		$o->delete( true );
	}
}
foreach ( $ids['products'] ?? array() as $id ) {
	if ( $pr = wc_get_product( (int) $id ) ) {
		$pr->delete( true );
	}
}
foreach ( $ids['tokens'] ?? array() as $id ) {
	WC_Payment_Tokens::delete( (int) $id );
}
foreach ( $ids['subscriptions'] ?? array() as $id ) {
	foreach ( array( 'wplm_subscription_items', 'wplm_subscription_notes', 'wplm_subscription_renewals' ) as $t ) {
		$wpdb->delete( $p . $t, array( 'subscription_id' => (int) $id ) );
	}
	$wpdb->delete( $p . 'wplm_subscriptions', array( 'id' => (int) $id ) );
}
foreach ( $ids['licenses'] ?? array() as $id ) {
	$mids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}wplm_machines WHERE license_id=%d", $id ) );
	foreach ( $mids as $mid ) {
		$wpdb->delete( $p . 'wplm_machine_components', array( 'machine_id' => (int) $mid ) );
	}
	$wpdb->delete( $p . 'wplm_machines', array( 'license_id' => (int) $id ) );
	$wpdb->delete( $p . 'wplm_activation_log', array( 'license_id' => (int) $id ) );
	$wpdb->delete( $p . 'wplm_license_meta', array( 'license_id' => (int) $id ) );
	$wpdb->delete( $p . 'wplm_licenses', array( 'id' => (int) $id ) );
}
foreach ( $ids['plans'] ?? array() as $id ) {
	$wpdb->delete( $p . 'wplm_packages', array( 'plan_id' => (int) $id ) );
	$wpdb->delete( $p . 'wplm_plans', array( 'id' => (int) $id ) );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $ids['users'] ?? array() as $id ) {
	$u = get_user_by( 'id', (int) $id );
	if ( $u && 'm527audit' === $u->user_login ) {
		wp_delete_user( (int) $id );
	}
}
delete_option( 'm527_audit_ids' );
echo "cleaned: ", wp_json_encode( $ids ), "\n";
