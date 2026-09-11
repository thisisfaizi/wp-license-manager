<?php
// H. Card renewal succeeds after the licence already hit "expired" (status 3).
use WPLM\Plugin; use WPLM\Services\LicenseService; use WPLM\Services\Subscriptions\RenewalProcessor;
global $wpdb; $c = Plugin::get_instance()->container(); $ids = get_option('m527_audit_ids');
$lid = $ids['licenses'][0]; $sid = $ids['subscriptions'][0]; $p = $wpdb->prefix;
$wpdb->update("{$p}wplm_licenses", ['expires_at'=>gmdate('Y-m-d H:i:s', time()-5*DAY_IN_SECONDS), 'status'=>1], ['id'=>$lid]);
$ls = $c->make(LicenseService::class); $key = $ls->get_by_id($lid)->license_key;
$v1 = $ls->validate($key, 'm527-audit-fp-1');
$wpdb->update("{$p}wplm_subscriptions", ['status'=>'active','next_payment'=>gmdate('Y-m-d H:i:s', time()-60),'failed_attempts'=>0], ['id'=>$sid]);
add_filter('wplm_gateway_charge', fn() => ['txn'=>'M527-AUDIT-OK']);
$c->make(RenewalProcessor::class)->process_due_renewals();
$row = $wpdb->get_row($wpdb->prepare("SELECT status,expires_at FROM {$p}wplm_licenses WHERE id=%d",$lid), ARRAY_A);
$tok = json_decode(base64_decode(strtr(explode('.', $ls->get_by_id($lid)->signature)[0], '-_', '+/')), true);
$v2 = $ls->validate($key, 'm527-audit-fp-1');
echo wp_json_encode(['validate_before'=>$v1['code']??'valid','license_after_successful_charge'=>$row,'token_expires'=>$tok['expires'],'validate_after'=>$v2['valid']?'valid':$v2['code']], JSON_PRETTY_PRINT), "\n";
