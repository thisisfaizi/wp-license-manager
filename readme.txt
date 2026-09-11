=== WP License Manager ===
Contributors: wplm
Tags: licensing, license key, woocommerce, software licensing, subscriptions
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 1.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted software licensing for WooCommerce: issue, validate, activate, monitor, and revoke cryptographically signed license keys with a native subscription engine.

== Description ==

WP License Manager (WPLM) is a self-hosted licensing server that runs as a WordPress plugin. It issues, validates, activates, monitors, and revokes cryptographically signed license keys, and can sell recurring licenses through a built-in subscription engine — no paid extensions required.

Client software (desktop apps, plugins, SaaS) calls the REST API to enforce licensing, and can verify Ed25519-signed license payloads offline without contacting the server.

= Core licensing =

* License key CRUD via admin UI, REST API, and PHP functions.
* Cryptographically signed keys (Ed25519) for tamper-proof offline validation.
* Encrypted key storage (XChaCha20-Poly1305 AEAD) with a SHA-256 hash column for fast lookup.
* Per-license absolute expiry and "valid for N days from activation" relative expiry, with grace periods.
* Configurable key generators (charset, chunks, length, separator, prefix, suffix) and bulk generation.

= Device & machine management =

* Hardware fingerprint binding (HMAC-SHA256 anonymized) with per-license seat limits.
* Floating / concurrent licensing with automatic lease reclaim.
* Per-device records, component sub-fingerprints, and overage strategies (deny / 1.25x / 2x).

= Monitoring & revocation =

* Heartbeat monitor with automatic zombie-seat culling.
* Full activation log and a usage analytics dashboard.
* Instant revoke / suspend / terminate, per-device revocation, fingerprint/IP/email blacklists.
* Signed certificate revocation list (CRL) for offline enforcement.

= Plans, packages & native subscriptions =

* Build reusable **Plans** containing one or more **Packages** (Monthly, Yearly, Lifetime, or benefit-based tiers), each with its own price, billing schedule, trial, sign-up fee, generator and seat rules.
* Assign a plan to any **native** WooCommerce product — no special product types. Customers pick a package on the product page.
* Recurring packages create a subscription billed daily/weekly/monthly/yearly; lifetime/one-time packages issue a perpetual or fixed-term license.
* Automatic rebilling via WooCommerce payment tokens, with dunning (failed-payment retries).
* Customer self-service: pause, resume, cancel, change payment method.
* Subscription lifecycle is bound to the license status.

= WooCommerce integration =

* Licensing tab on any product: assign a subscription plan, or enable simple one-time licensing.
* Auto-issue and deliver keys on order completion; resend on demand.
* Customer "My Account" portal for keys, devices, and subscriptions.

= REST API & developer tools =

* Full `wplm/v1` REST API for licenses, devices, generators, releases, subscriptions, and webhooks.
* Internal PHP API (`wplm_*()` functions) plus action and filter hooks for deep integration.
* Signed webhooks (HMAC-SHA256) with automatic retry for license, device, and subscription events.

== External services ==

This plugin does not connect to any external service on its own. It is a licensing *server*: your own client software connects to *your* site's REST API. Outgoing HTTP requests occur only when you explicitly configure webhooks (delivered to URLs you specify) or when a payment is processed through your existing WooCommerce payment gateway.

== Installation ==

1. Upload the `wp-license-manager` folder to `/wp-content/plugins/`, or install the ZIP via Plugins → Add New → Upload Plugin.
2. Activate the plugin through the Plugins menu in WordPress.
3. On activation, the plugin creates its database tables and generates its cryptographic secrets automatically.
4. Visit License Manager → Settings to configure defaults, then Generators to create your first key generator.
5. (Optional) Activate WooCommerce to sell licenses and subscriptions.

= Requirements =

* PHP 7.4 or higher (8.1+ recommended) with the sodium extension (bundled with PHP 7.2+).
* WordPress 5.8 or higher.
* WooCommerce 6.0+ is optional — core licensing and subscriptions work standalone.
* MySQL 5.7 / MariaDB 10.3 or higher.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

No. Core licensing, device management, monitoring, revocation, and the REST API all work without WooCommerce. WooCommerce is only required if you want to sell licenses or subscriptions through a store.

= Do I need the paid WooCommerce Subscriptions extension? =

No. WPLM ships its own subscription engine and manages billing schedules, automatic rebilling, trials, dunning, and switching on your site.

= How do clients validate a license offline? =

Each license payload is signed with Ed25519. The public key is exposed at `GET /wp-json/wplm/v1/public-key`, and clients verify the signature locally without a network call. A signed revocation list is available at `GET /wp-json/wplm/v1/crl`.

= Where are the secret keys stored? =

The signing private key, encryption key, and HMAC secrets are stored as non-autoloaded options (or as `wp-config.php` constants if you prefer). Only the Ed25519 public key is ever exposed.

== Changelog ==

= 1.0.8 =
* Fixed: device deactivation failed with a database error ("Unknown column 'deactivated_at'") because the `wplm_machines` table was missing that column — devices could not be deactivated and clients received a corrupted response. The column is now part of the schema and is added automatically to existing installs on upgrade.
* Improved: REST responses are hardened so a database error can never leak into the JSON body (errors are suppressed on `wplm/v1` routes and still logged).

= 1.0.7 =
* New: product binding — each signed license now carries its product id (`pid`). Client SDKs configured with a product id reject any key issued for a different product, enforced online and offline. Includes a "Re-sign licenses" tool under Settings → Tools (with optional product-id backfill) to embed `pid` into existing keys.
* Fixed: `validate` did not hash the device fingerprint before lookup, so an already-activated device was reported as needing activation. Validation now matches activated devices correctly.
* Fixed: re-activating a device left the license inactive; the license is now reconciled to active whenever a device is bound.
* Improved: seat counts are now recomputed from the actual active devices on every activate/deactivate, so the count can no longer drift after an error or retry.

= 1.0.6 =
* New: `[wplm_license_manager]` shortcode — a standalone, themeable front-end license portal where customers check status, see devices, and deactivate seats on any page (no WooCommerce account needed).
* Changed: plan tiers are now labelled "License Types" throughout the admin and storefront (clearer wording; same underlying model).
* Docs: complete documentation site covering Guides, Subscriptions, WooCommerce, REST API, PHP API, Client Integration, and Reference.

= 1.0.5 =
* New: no-code webhook delivery presets — send events straight to Slack, Discord, or Email by pasting a URL/address (no receiver to build). "Custom endpoint" remains for signed JSON to your own backend.
* New: "Send test delivery" button to verify a webhook works before relying on it.

= 1.0.4 =
* New: reusable Subscription Plans with multiple Packages (Monthly/Yearly/Lifetime/benefit-based), assignable to any native WooCommerce product — no custom product types.
* New: storefront package selector, per-package pricing, and automatic subscription/license fulfilment on purchase.
* New: webhooks now fire on events — an event bridge forwards internal license/device/subscription actions to your registered endpoints (HMAC-signed, with hourly retry).
* New: `wplm_webhook_payload` filter to customise webhook bodies.
* Removed: the WPLM Subscription / WPLM Variable Subscription custom product types (superseded by Plans) and their associated WooCommerce-internals workarounds.

= 1.0.3 =
* Fixed: database installer failed to create the generators table because the reserved word `separator` was not quoted.
* Fixed: installer now verifies all tables exist before recording the schema version, so a partial install retries automatically.
* Fixed: schema upgrade check moved to `admin_init` to avoid "unexpected output during activation".
* Improved: KeyVault now always encrypts with XChaCha20-Poly1305 and stores an algorithm marker, so license keys stay decryptable across server/CPU migrations.
* Improved: the WooCommerce product Licensing tab now shows generators in a dropdown.

= 1.0.0 =
* Initial release: cryptographic licensing, device management, monitoring, revocation, native subscriptions, WooCommerce integration, and the full REST API.

== Upgrade Notice ==

= 1.0.8 =
Fixes device deactivation (adds a missing database column, migrated automatically). Recommended for all installs. Visit any admin page once after upgrading to apply the migration.

= 1.0.7 =
Adds product binding so a key for one product cannot be used in another, plus important activation/validation fixes. Run Settings → Tools → Re-sign licenses after upgrading to embed product ids into existing keys.

= 1.0.6 =
Adds a front-end license self-service shortcode and full documentation.

= 1.0.5 =
Adds no-code webhook presets (Slack/Discord/Email) and a test button.

= 1.0.4 =
Adds reusable subscription plans/packages and makes webhooks fire on events. Recommended for all installs.

= 1.0.3 =
Fixes database table creation and improves at-rest encryption portability. Recommended for all installs.
