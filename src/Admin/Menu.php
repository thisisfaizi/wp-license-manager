<?php
/**
 * Admin menu registration for the WPLM plugin.
 *
 * @package WPLM\Admin
 */

namespace WPLM\Admin;

defined( 'ABSPATH' ) || exit;

use WPLM\Container;

/**
 * Registers the top-level "License Manager" admin menu and all sub-menus.
 * Enqueues admin assets only on WPLM screens.
 *
 * Sub-pages ship with stub render methods in this phase; full UI is added in Phase 14.
 */
class Menu {

	/** @var Container */
	private Container $container;

	/**
	 * @param Container $container Plugin service container (available to page renderers).
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Hook into WordPress to register menus and enqueue assets.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'init_settings' ) );
		add_action( 'admin_post_wplm_save_license', array( $this, 'handle_save_license' ) );
		add_action( 'admin_post_' . Screens\EntitlementPanel::ACTION, array( $this, 'handle_licence_action' ) );
		add_action( 'wp_ajax_wplm_search_product', array( $this, 'handle_ajax_search_product' ) );
		add_action( 'wp_ajax_wplm_search_order', array( $this, 'handle_ajax_search_order' ) );
		add_action( 'wp_ajax_wplm_search_user', array( $this, 'handle_ajax_search_user' ) );
		add_action( 'admin_post_wplm_save_generator', array( $this, 'handle_save_generator' ) );
		add_action( 'admin_post_wplm_save_release', array( $this, 'handle_save_release' ) );
		add_action( 'admin_post_wplm_save_webhook', array( $this, 'handle_save_webhook' ) );
		add_action( 'admin_post_wplm_test_webhook', array( $this, 'handle_test_webhook' ) );
		add_action( 'admin_post_wplm_add_blacklist', array( $this, 'handle_add_blacklist' ) );
		add_action( 'admin_post_wplm_update_subscription', array( $this, 'handle_update_subscription' ) );
		add_action( 'admin_post_wplm_save_plan', array( $this, 'handle_save_plan' ) );

		// Tools (Settings → Tools).
		add_action( 'admin_post_wplm_tool_export_licenses', array( $this, 'handle_tool_export_licenses' ) );
		add_action( 'admin_post_wplm_tool_import_licenses', array( $this, 'handle_tool_import_licenses' ) );
		add_action( 'admin_post_wplm_tool_rebuild_crl', array( $this, 'handle_tool_rebuild_crl' ) );
		add_action( 'admin_post_wplm_tool_purge_logs', array( $this, 'handle_tool_purge_logs' ) );
		add_action( 'admin_post_wplm_tool_reroll_keypair', array( $this, 'handle_tool_reroll_keypair' ) );
		add_action( 'admin_post_wplm_tool_resign_licenses', array( $this, 'handle_tool_resign_licenses' ) );
	}

	// -------------------------------------------------------------------------
	// Tools handlers (Settings → Tools)
	// -------------------------------------------------------------------------

	/** Guard: require manage_options and a valid nonce for the given action. */
	private function verify_tool_request( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-license-manager' ) );
		}
		check_admin_referer( $action );
	}

	/** Redirect back to the Settings page with a tool-result flag. */
	private function tool_redirect( string $tool, int $count = 0, bool $failed = false ): void {
		$args = array(
			'page'      => 'wplm-settings',
			'wplm_tool' => $tool,
			'count'     => $count,
		);
		if ( $failed ) {
			$args['failed'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Tools → Export all licenses as a streamed CSV download. */
	public function handle_tool_export_licenses(): void {
		$this->verify_tool_request( 'wplm_tool_export_licenses' );

		$repo  = $this->container->make( \WPLM\Repositories\LicenseRepository::class );
		$page  = 1;
		$limit = 500;

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wplm-licenses-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out     = fopen( 'php://output', 'w' );
		$columns = array(
			'id',
			'license_key',
			'status',
			'product_id',
			'order_id',
			'user_id',
			'max_activations',
			'activation_count',
			'is_floating',
			'overage_strategy',
			'valid_for_days',
			'expires_at',
			'created_at',
		);
		fputcsv( $out, $columns );

		do {
			$result  = $repo->get_list(
				array(
					'per_page' => $limit,
					'page'     => $page,
				)
			);
			$items   = $result['items'] ?? array();
			$fetched = count( $items );

			foreach ( $items as $license ) {
				fputcsv(
					$out,
					array(
						$license->id,
						$license->license_key,
						$license->status,
						$license->product_id,
						$license->order_id,
						$license->user_id,
						$license->max_activations,
						$license->activation_count,
						$license->is_floating,
						$license->overage_strategy,
						$license->valid_for_days,
						$license->expires_at,
						$license->created_at,
					)
				);
			}

			++$page;
		} while ( $fetched === $limit );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/** Tools → Import licenses from an uploaded CSV file. */
	public function handle_tool_import_licenses(): void {
		$this->verify_tool_request( 'wplm_tool_import_licenses' );

		// Nonce + capability verified in verify_tool_request() above.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $_FILES['wplm_csv']['tmp_name'] ) || ! is_uploaded_file( sanitize_text_field( wp_unslash( $_FILES['wplm_csv']['tmp_name'] ) ) ) ) {
			$this->tool_redirect( 'import', 0, true );
		}

		$tmp = sanitize_text_field( wp_unslash( $_FILES['wplm_csv']['tmp_name'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$handle = fopen( $tmp, 'r' );
		if ( false === $handle ) {
			$this->tool_redirect( 'import', 0, true );
		}

		$header = fgetcsv( $handle );
		if ( ! is_array( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->tool_redirect( 'import', 0, true );
		}

		$header  = array_map( 'strtolower', array_map( 'trim', $header ) );
		$service = $this->container->make( \WPLM\Services\LicenseService::class );
		$repo    = $this->container->make( \WPLM\Repositories\LicenseRepository::class );
		$count   = 0;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$data = array();
			foreach ( $header as $i => $col ) {
				$data[ $col ] = $row[ $i ] ?? '';
			}

			$key_string = trim( (string) ( $data['license_key'] ?? '' ) );
			if ( '' === $key_string ) {
				continue;
			}

			// Skip duplicates by hash.
			if ( null !== $repo->find_by_key( $key_string ) ) {
				continue;
			}

			try {
				$service->create(
					array(
						'key_string'       => $key_string,
						'product_id'       => isset( $data['product_id'] ) ? (int) $data['product_id'] : null,
						'order_id'         => isset( $data['order_id'] ) ? (int) $data['order_id'] : null,
						'user_id'          => isset( $data['user_id'] ) ? (int) $data['user_id'] : null,
						'max_activations'  => isset( $data['max_activations'] ) && '' !== $data['max_activations'] ? (int) $data['max_activations'] : null,
						'valid_for_days'   => isset( $data['valid_for_days'] ) && '' !== $data['valid_for_days'] ? (int) $data['valid_for_days'] : null,
						'expires_at'       => ! empty( $data['expires_at'] ) ? sanitize_text_field( $data['expires_at'] ) : null,
						'overage_strategy' => ! empty( $data['overage_strategy'] ) ? sanitize_text_field( $data['overage_strategy'] ) : 'deny',
						'source'           => 0, // import.
					)
				);
				++$count;
			} catch ( \Throwable $e ) {
				unset( $e ); // Skip malformed rows.
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$this->tool_redirect( 'import', $count );
	}

	/** Tools → Rebuild and re-sign the certificate revocation list. */
	public function handle_tool_rebuild_crl(): void {
		$this->verify_tool_request( 'wplm_tool_rebuild_crl' );

		try {
			$this->container->make( \WPLM\Services\RevocationService::class )->build_crl();
			$this->tool_redirect( 'crl' );
		} catch ( \Throwable $e ) {
			unset( $e );
			$this->tool_redirect( 'crl', 0, true );
		}
	}

	/** Tools → Purge activation-log rows older than the retention window. */
	public function handle_tool_purge_logs(): void {
		$this->verify_tool_request( 'wplm_tool_purge_logs' );

		$days   = (int) get_option( 'wplm_telemetry_retention_days', 90 );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		$repo  = $this->container->make( \WPLM\Repositories\ActivationLogRepository::class );
		$count = $repo->purge_before( $cutoff );

		$this->tool_redirect( 'purge', (int) $count );
	}

	/** Tools → Re-roll the Ed25519 signing keypair (danger zone). */
	public function handle_tool_reroll_keypair(): void {
		$this->verify_tool_request( 'wplm_tool_reroll_keypair' );

		require_once WPLM_PLUGIN_DIR . 'src/Install/Seeder.php';
		$ok = ( new \WPLM\Install\Seeder() )->regenerate_signing_keypair();

		if ( $ok ) {
			// Rebuild the CRL so it is re-signed with the new key.
			try {
				$this->container->make( \WPLM\Services\RevocationService::class )->build_crl();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		$this->tool_redirect( 'keypair', 0, ! $ok );
	}

	/** Tools → Re-sign all licenses so each token carries its product-binding pid. */
	public function handle_tool_resign_licenses(): void {
		$this->verify_tool_request( 'wplm_tool_resign_licenses' );

		// Nonce + capability verified in verify_tool_request() above.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$backfill = isset( $_POST['backfill_product_id'] ) ? absint( wp_unslash( $_POST['backfill_product_id'] ) ) : 0;

		try {
			$result = $this->container->make( \WPLM\Services\LicenseService::class )
				->resign_all( $backfill > 0 ? $backfill : null );
			$this->tool_redirect( 'resign', (int) ( $result['resigned'] ?? 0 ) );
		} catch ( \Throwable $e ) {
			unset( $e );
			$this->tool_redirect( 'resign', 0, true );
		}
	}

	/** Register plugin settings via the Settings API. */
	public function init_settings(): void {
		( new Settings\SettingsPage( $this->container->make( \WPLM\Licensing\ProfileRegistry::class ) ) )->register_settings();
	}

	/** Handle the save-license admin-post action. */
	public function handle_save_license(): void {
		check_admin_referer( 'wplm_save_license', 'wplm_license_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-license-manager' ) );
		}

		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- LicenceAdminActions sanitises each field.
		$result = $this->container->make( LicenceAdminActions::class )->save_licence( (array) $post );
		Screens\EntitlementPanel::flash( get_current_user_id(), $result );

		$license_id = (int) $result['license_id'];
		if ( ! $result['ok'] ) {
			$back = $license_id > 0 ? 'admin.php?page=wplm-licenses&action=edit&id=' . $license_id : 'admin.php?page=wplm-licenses&action=add';
		} elseif ( null !== $this->container->make( \WPLM\Repositories\LicenseRepository::class )->find_by_id( $license_id )?->profile ) {
			// An entitlement licence is set up on its own screen: its lines come next.
			$back = 'admin.php?page=wplm-licenses&action=edit&id=' . $license_id . '#wplm-lines';
		} else {
			$back = 'admin.php?page=wplm-licenses';
		}
		wp_safe_redirect( admin_url( $back ) );
		exit;
	}

	/** Handle every form on an entitlement licence's screen (lines, computers, status). */
	public function handle_licence_action(): void {
		$license_id = absint( $_POST['license_id'] ?? 0 );
		check_admin_referer( Screens\EntitlementPanel::ACTION . '_' . $license_id, '_wplm_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-license-manager' ) );
		}

		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- LicenceAdminActions sanitises each field.
		$result = $this->container->make( LicenceAdminActions::class )->dispatch( (array) $post, get_current_user_id() );
		Screens\EntitlementPanel::flash( get_current_user_id(), $result );

		$anchors = array(
			'line_save'    => 'wplm-lines',
			'line_extend'  => 'wplm-lines',
			'line_delete'  => 'wplm-lines',
			'status'       => 'wplm-status',
			'offline_code' => 'wplm-machines',
			'reset_moves'  => 'wplm-machines',
		);
		$anchor  = $anchors[ (string) ( $post['do'] ?? '' ) ] ?? 'wplm-grants';
		wp_safe_redirect( admin_url( 'admin.php?page=wplm-licenses&action=edit&id=' . $license_id . '#' . $anchor ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// AJAX: searchable ID fields (product / order / user)
	// -------------------------------------------------------------------------

	/** AJAX handler: search WooCommerce products (or any post) by title. */
	public function handle_ajax_search_product(): void {
		check_ajax_referer( 'wplm_search_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$q     = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
		$types = array( 'product', 'product_variation' );
		// Include non-WC post types as fallback.
		if ( ! function_exists( 'wc_get_product' ) ) {
			$types = array( 'post', 'page' );
		}

		$posts = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'any',
				's'              => $q,
				'numberposts'    => 10,
				'orderby'        => 'relevance',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( ! is_array( $posts ) ) {
			$posts = array();
		}

		$results = array_map(
			static function ( $pid ) {
				$title = get_the_title( (int) $pid );
				return array(
					'value' => (int) $pid,
					'label' => sprintf( '#%d — %s', (int) $pid, $title ),
				);
			},
			$posts
		);

		wp_send_json_success( $results );
	}

	/** AJAX handler: search WooCommerce orders by number, name, or email. */
	public function handle_ajax_search_order(): void {
		check_ajax_referer( 'wplm_search_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$q = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );

		$results = array();

		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'limit'  => 10,
					'search' => $q,
					'return' => 'objects',
				)
			);

			if ( is_array( $orders ) ) {
				foreach ( $orders as $order ) {
					if ( ! $order instanceof \WC_Order ) {
						continue;
					}
					$results[] = array(
						'value' => $order->get_id(),
						'label' => sprintf(
							'#%d — %s (%s)',
							$order->get_id(),
							$order->get_formatted_billing_full_name(),
							$order->get_billing_email()
						),
					);
				}
			}
		} elseif ( is_numeric( $q ) ) {
			// WooCommerce not active — allow searching by raw post ID.
			$post = get_post( (int) $q );
			if ( $post ) {
				$results[] = array(
					'value' => $post->ID,
					'label' => sprintf( '#%d — %s', $post->ID, $post->post_title ?: $post->post_name ),
				);
			}
		}

		wp_send_json_success( $results );
	}

	/** AJAX handler: search WordPress users by name, username, or email. */
	public function handle_ajax_search_user(): void {
		check_ajax_referer( 'wplm_search_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$q = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );

		$users = get_users(
			array(
				'search'         => '*' . $q . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
				'number'         => 10,
				'orderby'        => 'display_name',
			)
		);

		$results = array_map(
			static function ( \WP_User $u ) {
				return array(
					'value' => $u->ID,
					'label' => sprintf( '#%d — %s (%s)', $u->ID, $u->display_name, $u->user_email ),
				);
			},
			$users
		);

		wp_send_json_success( $results );
	}

	/**
	 * Register the top-level menu page and all sub-menu pages.
	 *
	 * Matches the menu structure defined in Section 12.1 of the spec.
	 *
	 * @return void
	 */
	public function register_menus(): void {
		// Top-level menu.
		add_menu_page(
			__( 'License Manager', 'wp-license-manager' ),
			__( 'License Manager', 'wp-license-manager' ),
			'manage_options',
			'wplm-dashboard',
			array( $this, 'page_dashboard' ),
			'dashicons-lock',
			56
		);

		// Sub-menus. The first entry intentionally re-uses the top-level slug so the
		// "Dashboard" sub-item is highlighted when the parent is active.
		$submenus = array(
			array(
				'parent'   => 'wplm-dashboard',
				'title'    => __( 'Dashboard', 'wp-license-manager' ),
				'slug'     => 'wplm-dashboard',
				'callback' => array( $this, 'page_dashboard' ),
			),
			array(
				'parent'   => 'wplm-dashboard',
				'title'    => __( 'Licenses', 'wp-license-manager' ),
				'slug'     => 'wplm-licenses',
				'callback' => array( $this, 'page_licenses' ),
			),
			array(
				'parent'   => 'wplm-dashboard',
				'title'    => __( 'Subscriptions', 'wp-license-manager' ),
				'slug'     => 'wplm-subscriptions',
				'callback' => array( $this, 'page_subscriptions' ),
			),
			array(
				'parent'   => 'wplm-dashboard',
				'title'    => __( 'Devices', 'wp-license-manager' ),
				'slug'     => 'wplm-devices',
				'callback' => array( $this, 'page_devices' ),
			),
			array(
				'parent'   => 'wplm-dashboard',
				'title'    => __( 'Generators', 'wp-license-manager' ),
				'slug'     => 'wplm-generators',
				'callback' => array( $this, 'page_generators' ),
			),
			array(
				'parent'   => 'wplm-dashboard',
				'title'    => __( 'Plans', 'wp-license-manager' ),
				'slug'     => 'wplm-plans',
				'callback' => array( $this, 'page_plans' ),
			),
			array(
				'parent'   => 'wplm-dashboard',
				'title'    => __( 'Releases', 'wp-license-manager' ),
				'slug'     => 'wplm-releases',
				'callback' => array( $this, 'page_releases' ),
			),
			array(
				'parent'   => 'wplm-dashboard',
				'title'    => __( 'Webhooks', 'wp-license-manager' ),
				'slug'     => 'wplm-webhooks',
				'callback' => array( $this, 'page_webhooks' ),
			),
			array(
				'parent'   => 'wplm-dashboard',
				'title'    => __( 'Blacklist', 'wp-license-manager' ),
				'slug'     => 'wplm-blacklist',
				'callback' => array( $this, 'page_blacklist' ),
			),
			array(
				'parent'   => 'wplm-dashboard',
				'title'    => __( 'Settings', 'wp-license-manager' ),
				'slug'     => 'wplm-settings',
				'callback' => array( $this, 'page_settings' ),
			),
		);

		foreach ( $submenus as $item ) {
			add_submenu_page(
				$item['parent'],
				$item['title'],
				$item['title'],
				'manage_options',
				$item['slug'],
				$item['callback']
			);
		}
	}

	/**
	 * Enqueue admin CSS and JS assets only on WPLM admin pages.
	 *
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! $this->is_wplm_page( $hook ) ) {
			return;
		}

		$base_url = plugin_dir_url( WPLM_PLUGIN_FILE );

		wp_enqueue_style(
			'wplm-admin',
			$base_url . 'assets/css/admin.css',
			array(),
			WPLM_VERSION
		);

		// jQuery UI Autocomplete (bundled with WordPress) + theme for the ID-search fields.
		wp_enqueue_script( 'jquery-ui-autocomplete' );
		wp_enqueue_style(
			'wplm-jquery-ui',
			'https://ajax.googleapis.com/ajax/libs/jqueryui/1.13.2/themes/smoothness/jquery-ui.css',
			array(),
			'1.13.2'
		);

		wp_enqueue_script(
			'wplm-admin',
			$base_url . 'assets/js/admin.js',
			array( 'jquery', 'jquery-ui-autocomplete' ),
			WPLM_VERSION,
			true
		);

		wp_localize_script(
			'wplm-admin',
			'wplmAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'searchNonce' => wp_create_nonce( 'wplm_search_nonce' ),
				'confirmText' => __( 'Are you sure?', 'wp-license-manager' ),
				'copiedText'  => __( 'Copied!', 'wp-license-manager' ),
				'rerollWarning' => __( 'Re-rolling the keypair will invalidate all previously signed license tokens. Are you sure?', 'wp-license-manager' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Page render methods — output real HTML markup (full UI added in Phase 14).
	// -------------------------------------------------------------------------

	/**
	 * Render the Dashboard page.
	 *
	 * @return void
	 */
	public function page_dashboard(): void {
		$screen = new Screens\DashboardScreen(
			$this->container->make( \WPLM\Services\AnalyticsService::class )
		);
		$screen->render();
	}

	/**
	 * Render the Licenses list page.
	 *
	 * @return void
	 */
	public function page_licenses(): void {
		$table = new Screens\LicenseListTable(
			$this->container->make( \WPLM\Repositories\LicenseRepository::class )
		);
		// Process bulk revoke/suspend/export before any output (export streams a file).
		$table->handle_bulk_actions();
		$table->render_page();
	}

	/**
	 * Render the Subscriptions list page (and edit screen for a single subscription).
	 *
	 * @return void
	 */
	public function page_subscriptions(): void {
		$table = new Screens\SubscriptionListTable(
			$this->container->make( \WPLM\Repositories\SubscriptionRepository::class ),
			$this->container->make( \WPLM\Repositories\RenewalRepository::class )
		);
		// Handle inline pause/resume/cancel-from-edit GET actions before rendering.
		$table->handle_inline_actions();
		$table->render_page();
	}

	/**
	 * Render the Devices list page.
	 *
	 * @return void
	 */
	public function page_devices(): void {
		( new Screens\DeviceListTable(
			$this->container->make( \WPLM\Repositories\MachineRepository::class )
		) )->render_page();
	}

	/**
	 * Render the Generators list page.
	 *
	 * @return void
	 */
	public function page_generators(): void {
		( new Screens\GeneratorListTable(
			$this->container->make( \WPLM\Repositories\GeneratorRepository::class )
		) )->render_page();
	}

	/**
	 * Render the Plans list / editor page.
	 *
	 * @return void
	 */
	public function page_plans(): void {
		( new Screens\PlanListTable(
			$this->container->make( \WPLM\Services\PlanService::class ),
			$this->container->make( \WPLM\Repositories\GeneratorRepository::class ),
			$this->container->make( \WPLM\Licensing\ProfileRegistry::class )
		) )->render_page();
	}

	/** Handle the save-plan admin-post action (plan fields + packages). */
	public function handle_save_plan(): void {
		check_admin_referer( 'wplm_save_plan' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-license-manager' ) );
		}

		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PlanAdminActions sanitises each field.
		$result = $this->container->make( PlanAdminActions::class )->save_plan( (array) $post );
		Flash::set( get_current_user_id(), $result );

		$plan_id = (int) $result['plan_id'];
		wp_safe_redirect( admin_url( $plan_id > 0 ? 'admin.php?page=wplm-plans&action=edit&id=' . $plan_id : 'admin.php?page=wplm-plans&action=add' ) );
		exit;
	}

	/**
	 * Render the Releases list page.
	 *
	 * @return void
	 */
	public function page_releases(): void {
		( new Screens\ReleaseListTable(
			$this->container->make( \WPLM\Repositories\ReleaseRepository::class )
		) )->render_page();
	}

	/**
	 * Render the Webhooks list page.
	 *
	 * @return void
	 */
	public function page_webhooks(): void {
		( new Screens\WebhookListTable(
			$this->container->make( \WPLM\Repositories\WebhookRepository::class )
		) )->render_page();
	}

	/**
	 * Render the Blacklist management page.
	 *
	 * @return void
	 */
	public function page_blacklist(): void {
		( new Screens\BlacklistTable(
			$this->container->make( \WPLM\Repositories\BlacklistRepository::class )
		) )->render_page();
	}

	// -------------------------------------------------------------------------
	// Form action handlers — admin-post.php callbacks
	// -------------------------------------------------------------------------

	/** Handle generator add/update form submission. */
	public function handle_save_generator(): void {
		check_admin_referer( 'wplm_save_generator', 'wplm_generator_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-license-manager' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$gen_id = absint( $_POST['generator_id'] ?? 0 );

		$data = array(
			'name'                    => sanitize_text_field( wp_unslash( $_POST['gen_name'] ?? '' ) ),
			'prefix'                  => sanitize_text_field( wp_unslash( $_POST['gen_prefix'] ?? '' ) ),
			'suffix'                  => sanitize_text_field( wp_unslash( $_POST['gen_suffix'] ?? '' ) ),
			'chunks'                  => max( 1, absint( $_POST['gen_chunks'] ?? 4 ) ),
			'chunk_length'            => max( 1, absint( $_POST['gen_chunk_length'] ?? 4 ) ),
			'separator'               => sanitize_text_field( wp_unslash( $_POST['gen_separator'] ?? '-' ) ),
			'charset'                 => sanitize_text_field( wp_unslash( $_POST['gen_charset'] ?? 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789' ) ),
			'default_max_activations' => absint( $_POST['gen_default_max_activations'] ?? 0 ) ?: null,
			'default_valid_days'      => absint( $_POST['gen_default_valid_days'] ?? 0 ) ?: null,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		/** @var \WPLM\Repositories\GeneratorRepository $repo */
		$repo = $this->container->make( \WPLM\Repositories\GeneratorRepository::class );

		if ( $gen_id > 0 ) {
			$repo->update( $gen_id, $data );
		} else {
			$repo->create( $data );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wplm-generators&saved=1' ) );
		exit;
	}

	/** Handle release add/update form submission. */
	public function handle_save_release(): void {
		check_admin_referer( 'wplm_save_release', 'wplm_release_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-license-manager' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$release_id = absint( $_POST['release_id'] ?? 0 );

		$allowed_channels = array( 'stable', 'beta', 'rc' );
		$channel          = sanitize_key( $_POST['rel_channel'] ?? 'stable' );
		if ( ! in_array( $channel, $allowed_channels, true ) ) {
			$channel = 'stable';
		}

		$data = array(
			'product_id'      => absint( $_POST['rel_product_id'] ?? 0 ) ?: null,
			'version'         => sanitize_text_field( wp_unslash( $_POST['rel_version'] ?? '' ) ),
			'channel'         => $channel,
			'file_path'       => esc_url_raw( wp_unslash( $_POST['rel_file_path'] ?? '' ) ) ?: null,
			'file_hash'       => sanitize_text_field( wp_unslash( $_POST['rel_file_hash'] ?? '' ) ) ?: null,
			'min_app_version' => sanitize_text_field( wp_unslash( $_POST['rel_min_app_version'] ?? '' ) ) ?: null,
			'changelog'       => sanitize_textarea_field( wp_unslash( $_POST['rel_changelog'] ?? '' ) ) ?: null,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		/** @var \WPLM\Repositories\ReleaseRepository $repo */
		$repo = $this->container->make( \WPLM\Repositories\ReleaseRepository::class );

		if ( $release_id > 0 ) {
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'wplm_releases',
				$data,
				array( 'id' => $release_id ),
				null,
				array( '%d' )
			);
		} else {
			$repo->create( $data );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wplm-releases&saved=1' ) );
		exit;
	}

	/** Handle webhook add/update form submission. */
	public function handle_save_webhook(): void {
		check_admin_referer( 'wplm_save_webhook', 'wplm_webhook_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-license-manager' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$webhook_id = absint( $_POST['webhook_id'] ?? 0 );

		$allowed_events = array(
			'license.created',
			'license.activated',
			'license.deactivated',
			'license.revoked',
			'license.suspended',
			'license.reinstated',
			'license.terminated',
			'license.expired',
			'license.renewed',
			'machine.revoked',
			'subscription.created',
			'subscription.renewed',
			'subscription.payment_failed',
			'subscription.cancelled',
			'subscription.paused',
			'subscription.resumed',
			'subscription.expired',
		);

		$events = array_intersect(
			array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['wh_events'] ?? array() ) ) ),
			$allowed_events
		);

		$format = sanitize_key( wp_unslash( $_POST['wh_format'] ?? 'json' ) );
		if ( ! in_array( $format, array( 'json', 'slack', 'discord', 'email' ), true ) ) {
			$format = 'json';
		}

		$target = ( 'email' === $format )
			? sanitize_email( wp_unslash( $_POST['wh_target_url'] ?? '' ) )
			: esc_url_raw( wp_unslash( $_POST['wh_target_url'] ?? '' ) );

		$data = array(
			'name'       => sanitize_text_field( wp_unslash( $_POST['wh_name'] ?? '' ) ),
			'target_url' => $target,
			'events'     => wp_json_encode( array_values( $events ) ),
			'format'     => $format,
		);

		if ( $webhook_id > 0 ) {
			$data['status'] = ! empty( $_POST['wh_status'] ) ? 1 : 0;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		/** @var \WPLM\Repositories\WebhookRepository $repo */
		$repo = $this->container->make( \WPLM\Repositories\WebhookRepository::class );

		if ( $webhook_id > 0 ) {
			$repo->update( $webhook_id, $data );
		} else {
			$data['secret'] = bin2hex( random_bytes( 32 ) );
			$data['status'] = 1;
			$repo->create( $data );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wplm-webhooks&saved=1' ) );
		exit;
	}

	/** Send a test delivery to a single webhook and report the result. */
	public function handle_test_webhook(): void {
		$webhook_id = absint( $_GET['webhook_id'] ?? 0 );
		check_admin_referer( 'wplm_test_webhook_' . $webhook_id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-license-manager' ) );
		}

		$repo    = $this->container->make( \WPLM\Repositories\WebhookRepository::class );
		$webhook = $repo->find_by_id( $webhook_id );

		$ok   = false;
		$code = 0;
		if ( $webhook ) {
			$result = $this->container->make( \WPLM\Services\WebhookService::class )->send_test( $webhook );
			$ok     = ! empty( $result['ok'] );
			$code   = (int) ( $result['code'] ?? 0 );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'wplm-webhooks',
					'action'    => 'edit',
					'id'        => $webhook_id,
					'wplm_test' => $ok ? 'ok' : 'fail',
					'code'      => $code,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Handle blacklist add form submission. */
	public function handle_add_blacklist(): void {
		check_admin_referer( 'wplm_add_blacklist', 'wplm_blacklist_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-license-manager' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$allowed_types = array( 'key', 'fingerprint', 'ip', 'email' );
		$type          = sanitize_key( $_POST['bl_type'] ?? 'ip' );
		if ( ! in_array( $type, $allowed_types, true ) ) {
			$type = 'ip';
		}

		$value  = sanitize_text_field( wp_unslash( $_POST['bl_value'] ?? '' ) );
		$reason = sanitize_text_field( wp_unslash( $_POST['bl_reason'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' !== $value ) {
			/** @var \WPLM\Repositories\BlacklistRepository $repo */
			$repo = $this->container->make( \WPLM\Repositories\BlacklistRepository::class );
			$repo->add( $type, $value, $reason );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wplm-blacklist&added=1' ) );
		exit;
	}

	/** Handle subscription update form submission (status + amount + next_payment). */
	public function handle_update_subscription(): void {
		$sub_id = absint( $_POST['subscription_id'] ?? 0 );
		check_admin_referer( 'wplm_update_subscription_' . $sub_id, 'wplm_sub_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-license-manager' ) );
		}

		if ( ! $sub_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wplm-subscriptions' ) );
			exit;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$allowed_statuses = array(
			'pending',
			'trial',
			'active',
			'on-hold',
			'pending-cancel',
			'cancelled',
			'expired',
			'suspended',
		);
		$new_status       = sanitize_text_field( wp_unslash( $_POST['sub_status'] ?? '' ) );
		if ( ! in_array( $new_status, $allowed_statuses, true ) ) {
			$new_status = '';
		}

		$recurring_total = (float) sanitize_text_field( wp_unslash( $_POST['sub_recurring_total'] ?? '' ) );
		$next_payment    = sanitize_text_field( wp_unslash( $_POST['sub_next_payment'] ?? '' ) );
		// Convert datetime-local (Y-m-dTH:i) to MySQL datetime.
		if ( $next_payment ) {
			$next_payment = str_replace( 'T', ' ', $next_payment ) . ':00';
		}
		// phpcs:enable

		/** @var \WPLM\Services\Subscriptions\SubscriptionService $sub_service */
		$sub_service = $this->container->make( \WPLM\Services\Subscriptions\SubscriptionService::class );

		/** @var \WPLM\Repositories\SubscriptionRepository $repo */
		$repo = $this->container->make( \WPLM\Repositories\SubscriptionRepository::class );

		$update = array();
		if ( $recurring_total > 0 ) {
			$update['recurring_total'] = $recurring_total;
		}
		if ( $next_payment ) {
			$update['next_payment'] = $next_payment;
		}
		if ( ! empty( $update ) ) {
			$repo->update( $sub_id, $update );
		}

		if ( $new_status ) {
			try {
				$sub_service->update_status( $sub_id, $new_status );
			} catch ( \Exception $e ) {
				unset( $e ); // Status unchanged; possibly invalid transition.
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wplm-subscriptions&action=edit&id=' . $sub_id . '&saved=1' ) );
		exit;
	}

	/**
	 * Render the Settings page.
	 *
	 * @return void
	 */
	public function page_settings(): void {
		( new Settings\SettingsPage( $this->container->make( \WPLM\Licensing\ProfileRegistry::class ) ) )->render_settings_page();
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Determine whether the given hook corresponds to a WPLM admin screen.
	 *
	 * WordPress uses two hook prefix patterns for plugin pages:
	 *   - toplevel_page_wplm-*   (top-level menu pages)
	 *   - license-manager_page_wplm-*  (sub-menu pages under the top-level slug)
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return bool True when assets should be enqueued.
	 */
	private function is_wplm_page( string $hook ): bool {
		return 0 === strpos( $hook, 'toplevel_page_wplm' )
			|| 0 === strpos( $hook, 'license-manager_page_wplm' );
	}
}
