<?php
/**
 * Upgrading a real older database: the new table and columns appear, the version is stamped, data
 * survives, and running the installer again changes nothing.
 *
 * MySQL commits implicitly on DDL, so this test cannot rely on the per-test rollback: it turns off
 * the temporary-table rewrite (a TEMPORARY table is invisible to SHOW TABLES), deletes what it
 * inserted, and always restores the current schema in tear_down.
 *
 * @package WPLM\Tests
 */

namespace WPLM\Tests\Integration\Install;

use WPLM\Install\Installer;
use WPLM\Plugin;
use WPLM\Repositories\EntitlementRepository;
use WPLM\Services\LicenseService;
use WPLM\Tests\TestCase;

class SchemaUpgradeTest extends TestCase {

	/** @var int[] Licence ids committed by DDL, removed in tear_down. */
	private array $license_ids = array();

	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function tear_down(): void {
		global $wpdb;
		( new Installer() )->run();
		foreach ( $this->license_ids as $id ) {
			$wpdb->delete( $wpdb->prefix . 'wplm_entitlements', array( 'license_id' => $id ) );
			$wpdb->delete( $wpdb->prefix . 'wplm_licenses', array( 'id' => $id ) );
		}
		$wpdb->query( 'COMMIT' );
		parent::tear_down();
	}

	private function has_column( string $table, string $column ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$wpdb->prefix}wplm_{$table}` LIKE %s", $column ) );
	}

	private function has_table( string $table ): bool {
		global $wpdb;
		$full = $wpdb->prefix . 'wplm_' . $table;
		return $full === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
	}

	/** Put the schema back to what 1.0.8 shipped: no grace_days, no profiles, no entitlements. */
	private function downgrade_to_1_0_8(): void {
		global $wpdb;
		$p = $wpdb->prefix . 'wplm_';
		$wpdb->query( "DROP TABLE IF EXISTS {$p}entitlements" );
		$wpdb->query( "ALTER TABLE {$p}licenses DROP INDEX profile, DROP COLUMN profile" );
		$wpdb->query( "ALTER TABLE {$p}plans DROP COLUMN profile" );
		$wpdb->query( "ALTER TABLE {$p}packages DROP COLUMN grace_days, DROP COLUMN entitlements" );
		$wpdb->query( "ALTER TABLE {$p}machines DROP COLUMN usage_json, DROP COLUMN token_fp" );
		update_option( 'wplm_db_version', '1.0.8' );
		$wpdb->query( 'COMMIT' );

		$this->assertFalse( $this->has_table( 'entitlements' ), 'Precondition: the downgrade took.' );
		$this->assertFalse( $this->has_column( 'licenses', 'profile' ) );
	}

	public function test_the_version_is_not_stamped_while_the_entitlements_table_is_missing(): void {
		$this->downgrade_to_1_0_8();
		$block = static function ( array $queries ): array {
			return array_filter( $queries, static fn( $sql ) => false === strpos( (string) $sql, 'wplm_entitlements' ) );
		};
		add_filter( 'dbdelta_create_queries', $block );

		( new Installer() )->run();
		remove_filter( 'dbdelta_create_queries', $block );

		$this->assertFalse( $this->has_table( 'entitlements' ), 'Precondition: creation was blocked.' );
		$this->assertSame( '1.0.8', get_option( 'wplm_db_version' ), 'Stamping now would stop maybe_upgrade_db() from ever retrying.' );

		Plugin::get_instance()->maybe_upgrade_db();
		$this->assertTrue( $this->has_table( 'entitlements' ), 'The next request retries and completes.' );
		$this->assertSame( WPLM_DB_VERSION, get_option( 'wplm_db_version' ) );
	}

	public function test_an_older_database_is_upgraded_and_a_second_run_changes_nothing(): void {
		global $wpdb;

		$classic             = $this->make( LicenseService::class )->create( array( 'key_string' => 'PRE-' . wp_generate_password( 12, false ) ) );
		$this->license_ids[] = $classic->id;
		$this->downgrade_to_1_0_8();

		Plugin::get_instance()->maybe_upgrade_db();

		$this->assertSame( '', $wpdb->last_error );
		$this->assertSame( WPLM_DB_VERSION, get_option( 'wplm_db_version' ), 'Stamped only once every table exists.' );
		$this->assertTrue( $this->has_table( 'entitlements' ) );
		foreach ( array( array( 'licenses', 'profile' ), array( 'plans', 'profile' ), array( 'packages', 'grace_days' ), array( 'packages', 'entitlements' ), array( 'machines', 'usage_json' ), array( 'machines', 'token_fp' ) ) as $col ) {
			$this->assertTrue( $this->has_column( $col[0], $col[1] ), "{$col[0]}.{$col[1]} added." );
		}
		$this->assertNull( $this->license_row( $classic->id )['profile'], 'An existing licence stays classic.' );

		$this->set_row( 'licenses', $classic->id, array( 'profile' => 'acme-office' ) );
		$line_id = $this->make( EntitlementRepository::class )->create(
			array(
				'license_id' => $classic->id,
				'kind'       => 'module',
				'code'       => 'base',
			)
		);
		$wpdb->query( 'COMMIT' );

		Plugin::activate();
		Plugin::activate();

		$this->assertSame( '', $wpdb->last_error );
		$this->assertNotNull( $this->make( EntitlementRepository::class )->find_by_id( $line_id ), 'A line survives re-activation.' );
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wplm_entitlements WHERE license_id = " . (int) $classic->id ) );
		$this->assertSame( WPLM_DB_VERSION, get_option( 'wplm_db_version' ) );
	}

	/** 1.2.1 → 1.2.2 (WPLM 1.2.6): plans gain a kind, lines gain the order that added them; rows survive. */
	public function test_a_1_2_1_database_gains_plan_kinds_and_line_orders_and_keeps_its_rows(): void {
		global $wpdb;
		$p = $wpdb->prefix . 'wplm_';

		$plan_id             = $this->make( \WPLM\Services\PlanService::class )->create_plan(
			array(
				'name'    => 'Pre-1.2.6 plan',
				'profile' => 'acme-office',
			)
		);
		$licence             = $this->make( LicenseService::class )->create(
			array(
				'key_string' => 'PRE-' . wp_generate_password( 12, false ),
				'profile'    => 'acme-office',
			)
		);
		$this->license_ids[] = $licence->id;
		$line_id             = $this->make( EntitlementRepository::class )->create(
			array(
				'license_id' => $licence->id,
				'kind'       => 'module',
				'code'       => 'base',
			)
		);

		$wpdb->query( "ALTER TABLE {$p}entitlements DROP INDEX order_id, DROP COLUMN order_id" );
		$wpdb->query( "ALTER TABLE {$p}plans DROP COLUMN kind" );
		update_option( 'wplm_db_version', '1.2.1' );
		$wpdb->query( 'COMMIT' );
		$this->assertFalse( $this->has_column( 'plans', 'kind' ), 'Precondition: the downgrade took.' );
		$this->assertFalse( $this->has_column( 'entitlements', 'order_id' ) );

		Plugin::get_instance()->maybe_upgrade_db();

		$this->assertSame( '', $wpdb->last_error );
		$this->assertSame( WPLM_DB_VERSION, get_option( 'wplm_db_version' ) );
		$this->assertTrue( $this->has_column( 'plans', 'kind' ) );
		$this->assertTrue( $this->has_column( 'entitlements', 'order_id' ) );
		$this->assertNotEmpty( $wpdb->get_results( "SHOW INDEX FROM {$p}entitlements WHERE Key_name = 'order_id'" ), 'The order lookup is indexed.' );

		$plan = $this->make( \WPLM\Services\PlanService::class )->get( $plan_id );
		$this->assertSame( 'standalone', $plan->kind, 'An existing plan keeps selling its own licence.' );
		$line = $this->make( EntitlementRepository::class )->find_by_id( $line_id );
		$this->assertNotNull( $line, 'The line survives.' );
		$this->assertNull( $line->order_id );

		$wpdb->delete( $p . 'plans', array( 'id' => $plan_id ) );
	}

	/** 1.2.2 → 1.3.0: subscriptions gain addons_license_id (add-ons-only subscriptions); rows survive. */
	public function test_a_1_2_2_database_gains_addons_only_subscriptions_and_keeps_its_rows(): void {
		global $wpdb;
		$p = $wpdb->prefix . 'wplm_';
		$wpdb->query( "ALTER TABLE {$p}subscriptions DROP INDEX addons_license_id, DROP COLUMN addons_license_id" );
		$wpdb->insert(
			$p . 'subscriptions',
			array(
				'user_id'         => 1,
				'license_id'      => 42,
				'status'          => 'active',
				'recurring_total' => 5000,
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$sub_id = (int) $wpdb->insert_id;
		update_option( 'wplm_db_version', '1.2.2' );
		$wpdb->query( 'COMMIT' );
		$this->assertFalse( $this->has_column( 'subscriptions', 'addons_license_id' ), 'Precondition: the downgrade took.' );

		Plugin::get_instance()->maybe_upgrade_db();

		$this->assertSame( '', $wpdb->last_error );
		$this->assertSame( '1.3.0', get_option( 'wplm_db_version' ) );
		$this->assertTrue( $this->has_column( 'subscriptions', 'addons_license_id' ) );
		$this->assertNotEmpty( $wpdb->get_results( "SHOW INDEX FROM {$p}subscriptions WHERE Key_name = 'addons_license_id'" ) );
		$sub = $this->make( \WPLM\Repositories\SubscriptionRepository::class )->find_by_id( $sub_id );
		$this->assertSame( 42, $sub->license_id, 'An existing subscription keeps its licence.' );
		$this->assertFalse( $sub->is_addons_only() );

		$wpdb->delete( $p . 'subscriptions', array( 'id' => $sub_id ) );
	}

	/** Between uploading 1.3.0 and the upgrade (the next admin page), subscriptions are still created. */
	public function test_subscriptions_are_created_before_the_1_3_0_upgrade_has_run(): void {
		global $wpdb;
		$p = $wpdb->prefix . 'wplm_';
		$wpdb->query( "ALTER TABLE {$p}subscriptions DROP INDEX addons_license_id, DROP COLUMN addons_license_id" );
		update_option( 'wplm_db_version', '1.2.2' );
		$wpdb->query( 'COMMIT' );

		$sub = $this->make( \WPLM\Services\Subscriptions\SubscriptionService::class )->create(
			array(
				'user_id'          => 1,
				'billing_interval' => 1,
				'billing_period'   => 'month',
				'recurring_total'  => 5000,
				'currency'         => 'PKR',
			)
		);
		$this->assertGreaterThan( 0, $sub->id );
		$this->assertFalse( $sub->is_addons_only() );
		$repo = $this->make( \WPLM\Repositories\SubscriptionRepository::class );
		$this->assertNull( $repo->find_addons_for_license( 42 ), 'No query on a column that is not there yet.' );
		$this->assertSame( array(), $repo->get_addons_for_license( 42 ) );
		$this->assertSame( '', $wpdb->last_error );

		$wpdb->delete( $p . 'subscriptions', array( 'id' => $sub->id ) );
	}

	/**
	 * A storefront order completed before any admin, REST or cron request ran the 1.3.0 upgrade still
	 * gets its add-ons subscription: the upgrade runs on every request (it is a version check), and
	 * opening an add-ons subscription runs it first if it has not.
	 */
	public function test_an_addons_subscription_opened_before_the_1_3_0_upgrade_runs_it_first(): void {
		global $wpdb;
		$this->assertNotFalse( has_action( 'init', array( Plugin::get_instance(), 'maybe_upgrade_db' ) ), 'Every request checks the schema version.' );

		$p = $wpdb->prefix . 'wplm_';
		$wpdb->query( "ALTER TABLE {$p}subscriptions DROP INDEX addons_license_id, DROP COLUMN addons_license_id" );
		update_option( 'wplm_db_version', '1.2.2' );
		$wpdb->query( 'COMMIT' );

		$license     = new \WPLM\Models\License();
		$license->id = 424242;
		$order       = new \WC_Order();
		$order->set_customer_id( 1 );
		$order->set_currency( 'PKR' );
		$sub = $this->make( \WPLM\Services\AddonService::class )->create_addons_subscription( $license, $order, 'month' );

		$this->assertSame( '', $wpdb->last_error );
		$this->assertTrue( $this->has_column( 'subscriptions', 'addons_license_id' ) );
		$this->assertSame( 424242, $sub->addons_license_id, 'Linked to its licence.' );
		$this->assertTrue( $sub->is_addons_only() );

		$wpdb->delete( $p . 'subscriptions', array( 'id' => $sub->id ) );
	}
}
