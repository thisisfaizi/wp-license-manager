<?php
/**
 * Database installer — creates all 14 WPLM tables via dbDelta.
 *
 * @package WPLM\Install
 */

namespace WPLM\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Installer creates (or upgrades) every WPLM database table on activation.
 * All schema changes must be expressed in the SQL returned by get_schema() so
 * dbDelta can diff them. Add versioned migration stubs to Migrator for data
 * transformations that SQL diffs cannot handle.
 */
class Installer {

	/** Run the full install: create tables, store DB version, run migrations. */
	public function run(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		foreach ( $this->get_schema( $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}

		// Ensure the customer-facing "My Licenses" account page exists.
		$this->ensure_account_page();

		// Only stamp the DB version once we have confirmed every table exists.
		// Otherwise a failed dbDelta would still mark the schema as current and
		// the safety-net in Plugin::maybe_upgrade_db() would never retry.
		if ( ! $this->all_tables_exist() ) {
			return;
		}

		$installed = get_option( 'wplm_db_version', '' );
		update_option( 'wplm_db_version', WPLM_DB_VERSION );

		if ( $installed && version_compare( $installed, WPLM_DB_VERSION, '<' ) ) {
			( new Migrator( $installed ) )->run();
		}
	}

	/**
	 * Create the front-end "My Licenses" page (holding the
	 * [wplm_license_manager] shortcode) if it does not already exist, and store
	 * its id in the wplm_account_page_id option.
	 *
	 * @return void
	 */
	private function ensure_account_page(): void {
		$existing = (int) get_option( 'wplm_account_page_id', 0 );
		if ( $existing > 0 && 'publish' === get_post_status( $existing ) ) {
			return;
		}

		// Reuse an existing page that already contains the shortcode, if any.
		$found = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				's'              => '[wplm_license_manager]',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		if ( ! empty( $found ) ) {
			update_option( 'wplm_account_page_id', (int) $found[0] );
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'My Licenses', 'wp-license-manager' ),
				'post_name'    => 'my-licenses',
				'post_content' => '[wplm_license_manager]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'wplm_account_page_id', (int) $page_id );
		}
	}

	/**
	 * Verify that every WPLM table was created successfully.
	 *
	 * @return bool True only if all expected tables exist.
	 */
	private function all_tables_exist(): bool {
		global $wpdb;
		$p = $wpdb->prefix . 'wplm_';

		$tables = array(
			'licenses',
			'machines',
			'machine_components',
			'activation_log',
			'generators',
			'api_keys',
			'releases',
			'webhooks',
			'webhook_log',
			'blacklist',
			'license_meta',
			'subscriptions',
			'subscription_items',
			'subscription_renewals',
			'subscription_notes',
			'plans',
			'packages',
		);

		foreach ( $tables as $table ) {
			$full  = $p . $table;
			$found = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $full )
			);
			if ( $found !== $full ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns every CREATE TABLE statement for dbDelta.
	 * dbDelta requires: two spaces before field definitions, PRIMARY KEY on its own
	 * line, and no trailing comma before the closing paren.
	 *
	 * @param string $charset_collate The charset/collate string from $wpdb.
	 * @return string[]
	 */
	private function get_schema( string $charset_collate ): array {
		global $wpdb;
		$p = $wpdb->prefix . 'wplm_';

		return array(
			// 1 — licenses
			"CREATE TABLE {$p}licenses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  license_key LONGTEXT NOT NULL,
  hash CHAR(64) NOT NULL,
  signature TEXT DEFAULT NULL,
  product_id BIGINT DEFAULT NULL,
  order_id BIGINT DEFAULT NULL,
  user_id BIGINT DEFAULT NULL,
  status TINYINT NOT NULL DEFAULT 0,
  max_activations INT DEFAULT NULL,
  activation_count INT NOT NULL DEFAULT 0,
  is_floating TINYINT NOT NULL DEFAULT 0,
  overage_strategy VARCHAR(20) NOT NULL DEFAULT 'deny',
  valid_for_days INT DEFAULT NULL,
  activated_at DATETIME DEFAULT NULL,
  expires_at DATETIME DEFAULT NULL,
  grace_days INT NOT NULL DEFAULT 0,
  source TINYINT NOT NULL DEFAULT 2,
  created_at DATETIME NOT NULL,
  created_by BIGINT DEFAULT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY hash (hash),
  KEY product_id (product_id),
  KEY order_id (order_id),
  KEY user_id (user_id),
  KEY status (status),
  KEY expires_at (expires_at)
) ENGINE=InnoDB {$charset_collate};",

			// 2 — machines (devices)
			"CREATE TABLE {$p}machines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  license_id BIGINT NOT NULL,
  fingerprint CHAR(64) NOT NULL,
  name VARCHAR(191) DEFAULT NULL,
  hostname VARCHAR(191) DEFAULT NULL,
  ip_address VARBINARY(16) DEFAULT NULL,
  platform VARCHAR(60) DEFAULT NULL,
  app_version VARCHAR(40) DEFAULT NULL,
  lease_expires_at DATETIME DEFAULT NULL,
  last_heartbeat_at DATETIME DEFAULT NULL,
  status TINYINT NOT NULL DEFAULT 1,
  activated_at DATETIME NOT NULL,
  deactivated_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY license_fingerprint (license_id, fingerprint),
  KEY license_id (license_id),
  KEY status (status),
  KEY last_heartbeat_at (last_heartbeat_at)
) ENGINE=InnoDB {$charset_collate};",

			// 3 — machine_components
			"CREATE TABLE {$p}machine_components (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  machine_id BIGINT NOT NULL,
  fingerprint CHAR(64) NOT NULL,
  kind VARCHAR(40) NOT NULL DEFAULT 'custom',
  name VARCHAR(191) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY machine_id (machine_id)
) ENGINE=InnoDB {$charset_collate};",

			// 4 — activation_log
			"CREATE TABLE {$p}activation_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  license_id BIGINT NOT NULL,
  machine_id BIGINT DEFAULT NULL,
  event VARCHAR(30) NOT NULL,
  result VARCHAR(20) NOT NULL,
  ip_address VARBINARY(16) DEFAULT NULL,
  country CHAR(2) DEFAULT NULL,
  meta JSON DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY license_id (license_id),
  KEY created_at (created_at),
  KEY event (event)
) ENGINE=InnoDB {$charset_collate};",

			// 5 — generators
			"CREATE TABLE {$p}generators (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(191) NOT NULL,
  charset VARCHAR(191) NOT NULL DEFAULT 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
  chunks TINYINT NOT NULL DEFAULT 4,
  chunk_length TINYINT NOT NULL DEFAULT 4,
  `separator` VARCHAR(8) NOT NULL DEFAULT '-',
  prefix VARCHAR(40) NOT NULL DEFAULT '',
  suffix VARCHAR(40) NOT NULL DEFAULT '',
  default_max_activations INT DEFAULT NULL,
  default_valid_days INT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id)
) ENGINE=InnoDB {$charset_collate};",

			// 6 — api_keys
			"CREATE TABLE {$p}api_keys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  description VARCHAR(191) DEFAULT NULL,
  permissions VARCHAR(12) NOT NULL DEFAULT 'read',
  consumer_key CHAR(64) NOT NULL,
  consumer_secret CHAR(64) NOT NULL,
  truncated_key CHAR(7) NOT NULL,
  last_access_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY consumer_key (consumer_key),
  KEY user_id (user_id)
) ENGINE=InnoDB {$charset_collate};",

			// 7 — releases
			"CREATE TABLE {$p}releases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT DEFAULT NULL,
  version VARCHAR(40) NOT NULL,
  channel VARCHAR(20) NOT NULL DEFAULT 'stable',
  changelog LONGTEXT DEFAULT NULL,
  file_path TEXT DEFAULT NULL,
  file_hash CHAR(64) DEFAULT NULL,
  min_app_version VARCHAR(40) DEFAULT NULL,
  released_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY product_id (product_id),
  KEY channel (channel)
) ENGINE=InnoDB {$charset_collate};",

			// 8 — webhooks
			"CREATE TABLE {$p}webhooks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(191) NOT NULL,
  target_url TEXT NOT NULL,
  events JSON NOT NULL,
  secret CHAR(64) NOT NULL,
  format VARCHAR(20) NOT NULL DEFAULT 'json',
  status TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) ENGINE=InnoDB {$charset_collate};",

			// 9 — webhook_log
			"CREATE TABLE {$p}webhook_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  webhook_id BIGINT NOT NULL,
  event VARCHAR(40) NOT NULL,
  payload JSON DEFAULT NULL,
  response_code SMALLINT DEFAULT NULL,
  attempts TINYINT NOT NULL DEFAULT 0,
  delivered_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY webhook_id (webhook_id),
  KEY created_at (created_at)
) ENGINE=InnoDB {$charset_collate};",

			// 10 — blacklist
			"CREATE TABLE {$p}blacklist (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type VARCHAR(20) NOT NULL,
  value VARCHAR(191) NOT NULL,
  reason VARCHAR(191) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY type_value (type, value)
) ENGINE=InnoDB {$charset_collate};",

			// 11 — license_meta
			"CREATE TABLE {$p}license_meta (
  meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  license_id BIGINT NOT NULL,
  meta_key VARCHAR(191) NOT NULL,
  meta_value LONGTEXT DEFAULT NULL,
  PRIMARY KEY  (meta_id),
  KEY license_id (license_id),
  KEY meta_key (meta_key)
) ENGINE=InnoDB {$charset_collate};",

			// 12 — subscriptions
			"CREATE TABLE {$p}subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_order_id BIGINT DEFAULT NULL,
  user_id BIGINT NOT NULL,
  license_id BIGINT DEFAULT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  billing_interval SMALLINT NOT NULL DEFAULT 1,
  billing_period VARCHAR(10) NOT NULL DEFAULT 'month',
  recurring_total DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  signup_fee DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  trial_end DATETIME DEFAULT NULL,
  next_payment DATETIME DEFAULT NULL,
  last_payment DATETIME DEFAULT NULL,
  end_date DATETIME DEFAULT NULL,
  payment_method VARCHAR(60) DEFAULT NULL,
  payment_token_id BIGINT DEFAULT NULL,
  failed_attempts TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY status (status),
  KEY next_payment (next_payment),
  KEY license_id (license_id)
) ENGINE=InnoDB {$charset_collate};",

			// 13 — subscription_items
			"CREATE TABLE {$p}subscription_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subscription_id BIGINT NOT NULL,
  product_id BIGINT NOT NULL,
  variation_id BIGINT DEFAULT NULL,
  quantity INT NOT NULL DEFAULT 1,
  line_total DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  meta JSON DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY subscription_id (subscription_id)
) ENGINE=InnoDB {$charset_collate};",

			// 14a — subscription_renewals
			"CREATE TABLE {$p}subscription_renewals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subscription_id BIGINT NOT NULL,
  order_id BIGINT DEFAULT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'renewal',
  amount DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  gateway_txn VARCHAR(191) DEFAULT NULL,
  scheduled_for DATETIME NOT NULL,
  processed_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY subscription_id (subscription_id),
  KEY scheduled_for (scheduled_for)
) ENGINE=InnoDB {$charset_collate};",

			// 14b — subscription_notes
			"CREATE TABLE {$p}subscription_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subscription_id BIGINT NOT NULL,
  note TEXT NOT NULL,
  is_customer TINYINT NOT NULL DEFAULT 0,
  created_by BIGINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY subscription_id (subscription_id)
) ENGINE=InnoDB {$charset_collate};",

			// 15 — plans (reusable subscription offerings assigned to WC products)
			"CREATE TABLE {$p}plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(191) NOT NULL,
  description TEXT DEFAULT NULL,
  status TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) ENGINE=InnoDB {$charset_collate};",

			// 16 — packages (tiers inside a plan: monthly/yearly/lifetime/etc.)
			"CREATE TABLE {$p}packages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  plan_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(191) NOT NULL,
  billing_type VARCHAR(20) NOT NULL DEFAULT 'recurring',
  billing_period VARCHAR(10) NOT NULL DEFAULT 'month',
  billing_interval SMALLINT NOT NULL DEFAULT 1,
  price DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  signup_fee DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  trial_days INT NOT NULL DEFAULT 0,
  length_cycles INT NOT NULL DEFAULT 0,
  generator_id BIGINT DEFAULT NULL,
  max_activations INT DEFAULT NULL,
  overage_strategy VARCHAR(20) NOT NULL DEFAULT 'deny',
  valid_for_days INT DEFAULT NULL,
  benefits TEXT DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  status TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY plan_id (plan_id),
  KEY status (status)
) ENGINE=InnoDB {$charset_collate};",
		);
	}
}
