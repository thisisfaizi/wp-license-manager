<?php
/**
 * Main plugin singleton — boots all modules.
 *
 * @package WPLM
 */

namespace WPLM;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin singleton. Instantiated once on `plugins_loaded`. Owns the Container
 * and is responsible for registering all hooks.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private Container $container;

	private function __construct() {
		$this->container = new Container();
		$this->register_services();
		$this->boot();
	}

	/** Returns (and creates) the single Plugin instance. */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Access the service container. */
	public function container(): Container {
		return $this->container;
	}

	// -------------------------------------------------------------------------
	// Activation / deactivation hooks (called by register_activation_hook)
	// -------------------------------------------------------------------------

	/** Runs on plugin activation. */
	public static function activate(): void {
		require_once WPLM_PLUGIN_DIR . 'src/Install/Installer.php';
		require_once WPLM_PLUGIN_DIR . 'src/Install/Seeder.php';

		$installer = new Install\Installer();
		$installer->run();

		$seeder = new Install\Seeder();
		$seeder->run();

		// Flush rewrite rules so REST routes are available immediately.
		flush_rewrite_rules();

		do_action( 'wplm_activated' );
	}

	/** Runs on plugin deactivation. */
	public static function deactivate(): void {
		// Remove scheduled cron events.
		$hooks = array(
			'wplm_process_renewals',
			'wplm_cull_zombies',
			'wplm_retry_webhooks',
			'wplm_lapse_notices',
		);
		foreach ( $hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}

		flush_rewrite_rules();

		do_action( 'wplm_deactivated' );
	}

	// -------------------------------------------------------------------------
	// Private boot helpers
	// -------------------------------------------------------------------------

	/** Register all service bindings in the container. */
	private function register_services(): void {
		// Crypto.
		$this->container->bind(
			Crypto\KeyVault::class,
			fn( $c ) => new Crypto\KeyVault()
		);
		$this->container->bind(
			Crypto\Signer::class,
			fn( $c ) => new Crypto\Signer()
		);
		$this->container->bind(
			Crypto\Fingerprint::class,
			fn( $c ) => new Crypto\Fingerprint()
		);

		// Repositories.
		$this->container->bind(
			Repositories\LicenseRepository::class,
			fn( $c ) => new Repositories\LicenseRepository( $c->make( Crypto\KeyVault::class ) )
		);
		$this->container->bind(
			Repositories\MachineRepository::class,
			fn( $c ) => new Repositories\MachineRepository()
		);
		$this->container->bind(
			Repositories\GeneratorRepository::class,
			fn( $c ) => new Repositories\GeneratorRepository()
		);
		$this->container->bind(
			Repositories\ActivationLogRepository::class,
			fn( $c ) => new Repositories\ActivationLogRepository()
		);
		$this->container->bind(
			Repositories\BlacklistRepository::class,
			fn( $c ) => new Repositories\BlacklistRepository()
		);
		$this->container->bind(
			Repositories\ReleaseRepository::class,
			fn( $c ) => new Repositories\ReleaseRepository()
		);
		$this->container->bind(
			Repositories\WebhookRepository::class,
			fn( $c ) => new Repositories\WebhookRepository()
		);
		$this->container->bind(
			Repositories\ApiKeyRepository::class,
			fn( $c ) => new Repositories\ApiKeyRepository()
		);
		$this->container->bind(
			Repositories\SubscriptionRepository::class,
			fn( $c ) => new Repositories\SubscriptionRepository()
		);
		$this->container->bind(
			Repositories\RenewalRepository::class,
			fn( $c ) => new Repositories\RenewalRepository()
		);
		$this->container->bind(
			Repositories\PackageRepository::class,
			fn( $c ) => new Repositories\PackageRepository()
		);
		$this->container->bind(
			Repositories\PlanRepository::class,
			fn( $c ) => new Repositories\PlanRepository(
				$c->make( Repositories\PackageRepository::class )
			)
		);

		$this->container->bind(
			Repositories\EntitlementRepository::class,
			fn( $c ) => new Repositories\EntitlementRepository()
		);

		// Licence profiles and entitlements.
		$this->container->bind(
			Licensing\ProfileRegistry::class,
			fn( $c ) => new Licensing\ProfileRegistry()
		);
		$this->container->bind(
			Services\EntitlementService::class,
			fn( $c ) => new Services\EntitlementService(
				$c->make( Repositories\EntitlementRepository::class ),
				$c->make( Repositories\LicenseRepository::class ),
				$c->make( Licensing\ProfileRegistry::class )
			)
		);
		$this->container->bind(
			Licensing\TokenV2Service::class,
			fn( $c ) => new Licensing\TokenV2Service(
				$c->make( Services\EntitlementService::class ),
				$c->make( Licensing\ProfileRegistry::class ),
				$c->make( Crypto\Signer::class ),
				$c->make( Crypto\Fingerprint::class )
			)
		);

		// Services.
		$this->container->bind(
			Services\GeneratorService::class,
			fn( $c ) => new Services\GeneratorService(
				$c->make( Repositories\GeneratorRepository::class ),
				$c->make( Repositories\LicenseRepository::class )
			)
		);
		$this->container->bind(
			Services\PlanService::class,
			fn( $c ) => new Services\PlanService(
				$c->make( Repositories\PlanRepository::class ),
				$c->make( Repositories\PackageRepository::class ),
				$c->make( Licensing\ProfileRegistry::class ),
				$c->make( Services\EntitlementService::class )
			)
		);
		$this->container->bind(
			Services\LicenseService::class,
			fn( $c ) => new Services\LicenseService(
				$c->make( Repositories\LicenseRepository::class ),
				$c->make( Crypto\Signer::class ),
				$c->make( Crypto\KeyVault::class ),
				$c->make( Repositories\ActivationLogRepository::class ),
				$c->make( Repositories\BlacklistRepository::class ),
				$c->make( Repositories\MachineRepository::class ),
				$c->make( Crypto\Fingerprint::class ),
				$c->make( Licensing\ProfileRegistry::class )
			)
		);
		$this->container->bind(
			Services\ActivationService::class,
			fn( $c ) => new Services\ActivationService(
				$c->make( Repositories\LicenseRepository::class ),
				$c->make( Repositories\MachineRepository::class ),
				$c->make( Repositories\ActivationLogRepository::class ),
				$c->make( Repositories\BlacklistRepository::class ),
				$c->make( Crypto\Fingerprint::class ),
				$c->make( Crypto\Signer::class )
			)
		);
		$this->container->bind(
			Licensing\CheckInService::class,
			fn( $c ) => new Licensing\CheckInService(
				$c->make( Repositories\LicenseRepository::class ),
				$c->make( Repositories\MachineRepository::class ),
				$c->make( Repositories\ActivationLogRepository::class ),
				$c->make( Services\ActivationService::class ),
				$c->make( Licensing\TokenV2Service::class ),
				$c->make( Licensing\ProfileRegistry::class ),
				$c->make( Crypto\Fingerprint::class )
			)
		);
		$this->container->bind(
			Licensing\OfflineCodeService::class,
			fn( $c ) => new Licensing\OfflineCodeService(
				$c->make( Repositories\LicenseRepository::class ),
				$c->make( Repositories\MachineRepository::class ),
				$c->make( Repositories\ActivationLogRepository::class ),
				$c->make( Licensing\TokenV2Service::class )
			)
		);
		$this->container->bind(
			Admin\LicenceAdminActions::class,
			fn( $c ) => new Admin\LicenceAdminActions(
				$c->make( Services\EntitlementService::class ),
				$c->make( Repositories\EntitlementRepository::class ),
				$c->make( Services\LicenseService::class ),
				$c->make( Repositories\LicenseRepository::class ),
				$c->make( Repositories\MachineRepository::class ),
				$c->make( Services\RevocationService::class ),
				$c->make( Repositories\ActivationLogRepository::class ),
				$c->make( Licensing\OfflineCodeService::class ),
				$c->make( Licensing\CheckInService::class ),
				$c->make( Services\GeneratorService::class )
			)
		);
		$this->container->bind(
			Integrations\WooCommerce\MyAccountLicences::class,
			fn( $c ) => new Integrations\WooCommerce\MyAccountLicences(
				$c->make( Repositories\LicenseRepository::class ),
				$c->make( Repositories\MachineRepository::class ),
				$c->make( Licensing\CheckInService::class ),
				$c->make( Services\EntitlementService::class ),
				$c->make( Licensing\ProfileRegistry::class )
			)
		);
		$this->container->bind(
			Admin\PlanAdminActions::class,
			fn( $c ) => new Admin\PlanAdminActions(
				$c->make( Services\PlanService::class ),
				$c->make( Licensing\ProfileRegistry::class ),
				$c->make( Services\EntitlementService::class )
			)
		);
		$this->container->bind(
			Admin\Screens\EntitlementPanel::class,
			fn( $c ) => new Admin\Screens\EntitlementPanel(
				$c->make( Services\EntitlementService::class ),
				$c->make( Licensing\ProfileRegistry::class ),
				$c->make( Repositories\MachineRepository::class ),
				$c->make( Licensing\CheckInService::class ),
				$c->make( Repositories\ActivationLogRepository::class )
			)
		);
		$this->container->bind(
			Licensing\LapseNoticeService::class,
			fn( $c ) => new Licensing\LapseNoticeService(
				$c->make( Repositories\EntitlementRepository::class ),
				$c->make( Repositories\LicenseRepository::class ),
				$c->make( Repositories\ActivationLogRepository::class ),
				$c->make( Licensing\ProfileRegistry::class )
			)
		);
		$this->container->bind(
			Services\RevocationService::class,
			fn( $c ) => new Services\RevocationService(
				$c->make( Repositories\LicenseRepository::class ),
				$c->make( Repositories\MachineRepository::class ),
				$c->make( Repositories\BlacklistRepository::class ),
				$c->make( Repositories\ActivationLogRepository::class ),
				$c->make( Crypto\Signer::class )
			)
		);
		$this->container->bind(
			Services\HeartbeatService::class,
			fn( $c ) => new Services\HeartbeatService(
				$c->make( Repositories\MachineRepository::class ),
				$c->make( Repositories\LicenseRepository::class ),
				$c->make( Repositories\ActivationLogRepository::class ),
				$c->make( Crypto\Fingerprint::class )
			)
		);
		$this->container->bind(
			Services\AnalyticsService::class,
			fn( $c ) => new Services\AnalyticsService(
				$c->make( Repositories\LicenseRepository::class ),
				$c->make( Repositories\ActivationLogRepository::class ),
				$c->make( Repositories\SubscriptionRepository::class )
			)
		);
		$this->container->bind(
			Services\WebhookService::class,
			fn( $c ) => new Services\WebhookService(
				$c->make( Repositories\WebhookRepository::class )
			)
		);
		$this->container->bind(
			Services\Subscriptions\SubscriptionService::class,
			fn( $c ) => new Services\Subscriptions\SubscriptionService(
				$c->make( Repositories\SubscriptionRepository::class ),
				$c->make( Repositories\RenewalRepository::class ),
				$c->make( Services\LicenseService::class )
			)
		);
		$this->container->bind(
			Services\Subscriptions\BillingScheduler::class,
			fn( $c ) => new Services\Subscriptions\BillingScheduler()
		);
		$this->container->bind(
			Services\Subscriptions\GatewayBridge::class,
			fn( $c ) => new Services\Subscriptions\GatewayBridge()
		);
		$this->container->bind(
			Services\Subscriptions\DunningManager::class,
			fn( $c ) => new Services\Subscriptions\DunningManager(
				$c->make( Repositories\SubscriptionRepository::class ),
				$c->make( Services\Subscriptions\SubscriptionService::class )
			)
		);
		$this->container->bind(
			Services\Subscriptions\RenewalProcessor::class,
			fn( $c ) => new Services\Subscriptions\RenewalProcessor(
				$c->make( Repositories\SubscriptionRepository::class ),
				$c->make( Repositories\RenewalRepository::class ),
				$c->make( Services\Subscriptions\GatewayBridge::class ),
				$c->make( Services\Subscriptions\DunningManager::class ),
				$c->make( Services\LicenseService::class ),
				$c->make( Services\Subscriptions\BillingScheduler::class ),
				$c->make( Services\Subscriptions\SubscriptionService::class ),
				$c->make( Services\EntitlementService::class )
			)
		);
		$this->container->bind(
			Services\Subscriptions\SwitchManager::class,
			fn( $c ) => new Services\Subscriptions\SwitchManager(
				$c->make( Repositories\SubscriptionRepository::class ),
				$c->make( Repositories\RenewalRepository::class ),
				$c->make( Services\Subscriptions\BillingScheduler::class ),
				$c->make( Services\LicenseService::class )
			)
		);

		// REST.
		$this->container->bind(
			Rest\RestServer::class,
			fn( $c ) => new Rest\RestServer( $c )
		);

		// Cron.
		$this->container->bind(
			Cron\Scheduler::class,
			fn( $c ) => new Cron\Scheduler(
				$c->make( Services\Subscriptions\RenewalProcessor::class ),
				$c->make( Services\HeartbeatService::class ),
				$c->make( Services\WebhookService::class ),
				$c->make( Licensing\LapseNoticeService::class )
			)
		);

		// Self-service renewal (WooCommerce order → license extension).
		$this->container->bind(
			Integrations\WooCommerce\SelfServiceRenewal::class,
			fn( $c ) => new Integrations\WooCommerce\SelfServiceRenewal(
				$c->make( Services\LicenseService::class ),
				$c->make( Repositories\SubscriptionRepository::class ),
				$c->make( Services\Subscriptions\RenewalProcessor::class ),
				$c->make( Repositories\RenewalRepository::class )
			)
		);

		// Admin.
		$this->container->bind(
			Admin\Menu::class,
			fn( $c ) => new Admin\Menu( $c )
		);
	}

	/** Wire WordPress hooks and boot each module. */
	private function boot(): void {
		// Global PHP API functions (Section 11) — load before REST routes.
		require_once WPLM_PLUGIN_DIR . 'src/functions.php';

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wplm', Cli\LicensingCommand::class );
		}

		// Safety net: run the installer whenever the stored DB version is behind.
		// Deferred to admin_init so headers are already sent — prevents the
		// "unexpected output during activation" warning (plugins_loaded fires
		// inside the activation request before the activation hook runs).
		add_action( 'admin_init', array( $this, 'maybe_upgrade_db' ) );
		// A licence server is mostly REST and cron traffic; an update must not wait for someone
		// to open wp-admin before its schema exists.
		add_action( 'rest_api_init', array( $this, 'maybe_upgrade_db' ), 1 );
		if ( wp_doing_cron() ) {
			add_action( 'init', array( $this, 'maybe_upgrade_db' ), 1 );
		}

		// i18n.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// A missing secret used to fatal every request, admin included (audit F9). Crypto now
		// loads secrets lazily, so the site stays up and the owner is told what to fix.
		add_action( 'admin_notices', array( $this, 'notice_missing_secrets' ) );

		// REST API.
		add_action(
			'rest_api_init',
			function () {
				$this->container->make( Rest\RestServer::class )->register();
			}
		);

		// Cron.
		$this->container->make( Cron\Scheduler::class )->register();

		// Webhook event bridge — forwards internal wplm_* actions to registered
		// webhook endpoints. Registered on every request so events fire from the
		// admin, the front end, the REST API, and cron alike.
		( new Integrations\WebhookEventBridge(
			$this->container->make( Services\WebhookService::class )
		) )->register();

		// Front-end license self-service shortcode: [wplm_license_manager].
		( new Integrations\LicenseShortcode() )->register();

		// Admin UI.
		if ( is_admin() ) {
			$this->container->make( Admin\Menu::class )->register();
		}

		// WooCommerce integration — deferred to 'init' so WooCommerce classes are
		// guaranteed loaded regardless of plugin activation order.
		add_action( 'init', array( $this, 'maybe_boot_woocommerce' ) );

		do_action( 'wplm_loaded', $this );
	}

	/** Boot WooCommerce integrations only when WooCommerce is active. */
	public function maybe_boot_woocommerce(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$this->boot_woocommerce();
	}

	/** Boot WooCommerce-dependent integrations. */
	public function boot_woocommerce(): void {
		// Product data panel (licensing fields on product edit screen).
		( new Integrations\WooCommerce\ProductPanel() )->register();

		// Order completion → license delivery.
		( new Integrations\WooCommerce\CheckoutHandler(
			$this->container->make( Services\LicenseService::class ),
			$this->container->make( Services\Subscriptions\SubscriptionService::class )
		) )->register();

		// My Account portal (Licenses + Subscriptions tabs).
		( new Integrations\WooCommerce\MyAccountSubscriptions(
			$this->container->make( Services\Subscriptions\SubscriptionService::class )
		) )->register();
		$this->container->make( Integrations\WooCommerce\MyAccountLicences::class )->register();

		// Order admin meta box — shows issued keys + subscription link per order.
		( new Integrations\WooCommerce\OrderMetaBox(
			$this->container->make( Repositories\LicenseRepository::class )
		) )->register();

		// Subscription lifecycle emails (created / renewed / payment-failed / cancelled).
		( new Integrations\WooCommerce\SubscriptionMailer(
			$this->container->make( Repositories\LicenseRepository::class )
		) )->register();

		// Plan/package storefront + checkout (native WC products with an assigned plan).
		( new Integrations\WooCommerce\PlanCheckout(
			$this->container->make( Services\PlanService::class ),
			$this->container->make( Services\GeneratorService::class ),
			$this->container->make( Services\LicenseService::class ),
			$this->container->make( Services\Subscriptions\SubscriptionService::class ),
			$this->container->make( Services\EntitlementService::class ),
			$this->container->make( Licensing\ProfileRegistry::class )
		) )->register();

		// Customer self-service renewal: create order → pay → license extended on completed.
		$this->container->make( Integrations\WooCommerce\SelfServiceRenewal::class )->register();
	}

	/**
	 * Create / upgrade DB tables when the stored schema version is behind.
	 * Hooked on admin_init so it never runs inside the activation window.
	 */
	public function maybe_upgrade_db(): void {
		if ( get_option( 'wplm_db_version', '' ) === WPLM_DB_VERSION ) {
			return;
		}
		require_once WPLM_PLUGIN_DIR . 'src/Install/Installer.php';
		require_once WPLM_PLUGIN_DIR . 'src/Install/Seeder.php';
		( new Install\Installer() )->run();
		( new Install\Seeder() )->run();
	}

	/**
	 * The secrets that are missing or malformed, by option name.
	 *
	 * @return string[]
	 */
	public static function missing_secrets(): array {
		$missing = array();

		$enc = defined( 'WPLM_ENCRYPTION_KEY' ) && WPLM_ENCRYPTION_KEY ? WPLM_ENCRYPTION_KEY : get_option( 'wplm_encryption_key', '' );
		$raw = $enc ? base64_decode( (string) $enc, true ) : false;
		if ( ! $raw || 32 !== strlen( $raw ) ) {
			$missing[] = 'wplm_encryption_key';
		}

		$kp      = defined( 'WPLM_SIGNING_KEYPAIR' ) && WPLM_SIGNING_KEYPAIR ? WPLM_SIGNING_KEYPAIR : get_option( 'wplm_signing_keypair', '' );
		$decoded = $kp ? json_decode( (string) $kp, true ) : null;
		if ( ! is_array( $decoded ) || empty( $decoded['sec'] ) || empty( $decoded['pub'] ) ) {
			$missing[] = 'wplm_signing_keypair';
		}

		$hmac = defined( 'WPLM_FINGERPRINT_HMAC' ) && WPLM_FINGERPRINT_HMAC ? WPLM_FINGERPRINT_HMAC : get_option( 'wplm_fingerprint_hmac', '' );
		if ( ! $hmac || ! base64_decode( (string) $hmac, true ) ) {
			$missing[] = 'wplm_fingerprint_hmac';
		}

		return $missing;
	}

	/** Admin notice naming any missing secret. */
	public function notice_missing_secrets(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$missing = self::missing_secrets();
		if ( empty( $missing ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated option names */
					__( 'WP License Manager cannot sign, encrypt or validate licences: missing or invalid secret(s) %s. Restore them from your backup (or define them in wp-config.php). Regenerating the signing keypair invalidates every issued token.', 'wp-license-manager' ),
					implode( ', ', $missing )
				)
			)
		);
	}

	/** Load plugin text domain. */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'wp-license-manager',
			false,
			dirname( WPLM_PLUGIN_BASENAME ) . '/languages'
		);
	}
}
