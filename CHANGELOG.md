# Changelog

All notable changes to WP License Manager are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). This project uses [Semantic Versioning](https://semver.org/).

---

## [1.0.0] — 2026-06-15

### Added

#### Core / Architecture
- PSR-4 autoloader (`WPLM\` → `src/`) with Composer integration and fallback `spl_autoload_register`
- Lightweight DI container (`src/Container.php`) with bind / make / has / instance
- Plugin bootstrap (`src/Plugin.php`) with single-instance `get_instance()`, service container wiring, and conditional WooCommerce boot
- Database installer (`src/Install/Installer.php`) — 15 tables created via `dbDelta()` with schema version tracking
- Database seeder (`src/Install/Seeder.php`) — generates Ed25519 keypair, AES-256-GCM key, HMAC secret, and webhook secret on first activation
- Uninstall handler (`uninstall.php`) — drops all tables and options; respects `wplm_keep_data_on_uninstall` toggle

#### Cryptography
- `src/Crypto/Signer.php` — Ed25519 sign/verify via libsodium; `base64url(json).base64url(sig)` token format
- `src/Crypto/KeyVault.php` — symmetric encryption with AES-256-GCM (hardware) / XChaCha20-Poly1305 (fallback); nonce-prepended ciphertext
- `src/Crypto/Fingerprint.php` — HMAC-SHA256 device fingerprint hashing and SHA-256 static helper

#### Models
- `src/Models/License.php` — `from_row()`, `to_array()`, status helpers, `is_within_expiry()`
- `src/Models/Machine.php`
- `src/Models/Subscription.php`
- `src/Models/Renewal.php`
- `src/Models/ApiKey.php`
- `src/Models/Generator.php`

#### Repositories (15 tables)
- `LicenseRepository` — CRUD + status queries with INET6_ATON/NTOA for IPv4/IPv6 storage
- `MachineRepository` — device seat management, floating lease timestamps
- `ActivationLogRepository`
- `BlacklistRepository`
- `SubscriptionRepository`
- `RenewalRepository`
- `ApiKeyRepository`
- `GeneratorRepository`
- `WebhookRepository`
- `ReleaseRepository`
- `EnvironmentRepository`
- `MetaRepository`
- `ProductMappingRepository`
- `AuditLogRepository`
- `NotificationRepository`

#### Services
- `src/Services/LicenseService.php` — 7-step validation algorithm, encrypted key creation, Ed25519 signing, `wplm_license_created` action
- `src/Services/ActivationService.php` — idempotent activation, overage ceilings (deny / 1.25× / 2×), floating leases
- `src/Services/RevocationService.php` — revoke/suspend/terminate per license or device, signed CRL (1-hour transient cache)
- `src/Services/HeartbeatService.php` — lease renewal, zombie cull via `wplm_zombie_window` filter (default 1200 s)
- `src/Services/GeneratorService.php` — configurable key format, sequential/random generation, batch creation
- `src/Services/AnalyticsService.php` — anomaly detection: fingerprint churn, validation spikes, impossible travel; 10-min cache
- `src/Services/WebhookService.php` — signed webhook dispatch with HMAC-SHA256 signature header
- `src/Services/ReleaseService.php` — software release and update management

#### Subscriptions Engine
- `src/Services/Subscriptions/RenewalProcessor.php` — charges via GatewayBridge, writes renewal record, advances `next_payment`, falls through to dunning on failure
- `src/Services/Subscriptions/DunningManager.php` — configurable retry schedule, sends email notifications, suspends subscription after max failures
- `src/Services/Subscriptions/BillingScheduler.php` — WP-Cron–based next-payment scheduling
- `src/Services/Subscriptions/GatewayBridge.php` — dispatches `wplm_gateway_charge` filter; returns `WP_Error('no_gateway')` when no gateway hooked
- `src/Services/Subscriptions/Proration.php` — upgrade/downgrade prorated credit calculation

#### REST API
- `src/Rest/Auth/BasicAuth.php` — HTTP Basic with SHA-256 consumer key, `hash_equals()` secret check, `determine_current_user` hook
- `src/Rest/Controllers/BaseController.php` — abstract; `require_read/write/public_route()` permission callbacks
- Controllers: `LicensesController`, `ActivationsController`, `ValidateController`, `HeartbeatController`, `RevocationController`, `GeneratorsController`, `ApiKeysController`, `WebhooksController`, `ReleasesController`, `SubscriptionsController`, `AnalyticsController`

#### Admin UI
- `src/Admin/Menu.php` — 5 top-level menu pages, Settings API init, nonce-guarded save handlers
- `src/Admin/Screens/DashboardScreen.php` — summary widgets (active/inactive/expiring/revenue)
- `src/Admin/Screens/LicenseListTable.php` — extends `WP_List_Table`; bulk revoke, suspend, export
- `src/Admin/Settings/SettingsPage.php` — Settings API, 6 sections: General, Security, Activation, Monitoring, Subscriptions, Dunning

#### WooCommerce Integration
- `src/Integrations/WooCommerce/ProductPanel.php` — "License Data" product tab with 7 meta fields
- `src/Integrations/WooCommerce/CheckoutHandler.php` — idempotent key delivery on `woocommerce_order_status_completed`; refund → suspend
- `src/Integrations/WooCommerce/MyAccountSubscriptions.php` — My Account endpoints `wplm-licenses` and `wplm-subscriptions` with pause/resume/cancel POST forms

#### Global Helper Functions
- `src/functions.php` — 30 globally namespaced helper functions (`wplm_get_license()`, `wplm_validate()`, `wplm_activate()`, etc.), all guarded with `function_exists()`

#### Frontend & Templates
- `assets/css/admin.css` — dashboard grid, 7 status badge classes, list-table extras, settings styles
- `assets/js/admin.js` — product panel field toggle, dangerous-action confirm, copy-to-clipboard, keypair re-roll warning
- `templates/myaccount/wplm-licenses.php` — WooCommerce My Account licenses table with truncated key display
- `templates/myaccount/wplm-subscriptions.php` — My Account subscriptions table with action forms

#### Developer Tools
- `composer.json` — PSR-4 autoload, dev deps: PHPUnit ^9, WPCS ^3, phpcs ^3.7, Brain\Monkey ^2.6
- `phpcs.xml` — WordPress coding standards, text-domain enforcement, PHP 7.4+ / WP 5.8+ targets
- Documentation site (Astro Starlight 0.32.6) in `docs-site/`

---

[1.0.0]: https://github.com/wplm/wp-license-manager/releases/tag/v1.0.0
