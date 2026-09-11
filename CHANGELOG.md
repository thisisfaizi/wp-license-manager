# Changelog

All notable changes to WP License Manager are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). This project uses [Semantic Versioning](https://semver.org/).

---

## [1.2.0] — Unreleased

Entitlement licences for Super Ledger: per-module and per-limit lines with their own paid-through dates, and a signed, machine-bound v2 token. Classic licences, their v1 tokens and every other product are unchanged.

### Added
- **Licence profiles.** A plan can sell a licence profile (built in: `super-ledger`; others via the `wplm_license_profiles` filter). A licence bought from such a plan is an *entitlement licence*.
- **Entitlement lines** (`wplm_entitlements`).
  - A `module` line grants a module; a `limit` line adds to `users`, `seats` or `phones`.
  - `paid_through` is the inclusive last paid day in the site's time zone; `NULL` means lifetime.
  - Codes are a closed list per profile, and an unknown code is refused on save.
  - Actions: `wplm_entitlement_saved`, `wplm_entitlement_deleted`.
- **Package entitlement templates.** Buying a package writes its template's lines: paid through the day before the next payment, or lifetime.
- **v2 token** (`Licensing\TokenV2Service`):
  - fields `{v, pid, lid, mid, fp, iat, srv, checkInBy, graceDays, status, modules, limits}`, signed with the existing key and format;
  - per-profile settings for the check-in window and grace (default 7 days each);
  - `status` is `suspended` only when the owner suspended the licence; revoked and terminated licences get no token.
- **Renewals extend entitlement lines.** A card charge or a renewal order marked Completed moves the paying subscription's dated lines (`EntitlementService::extend_subscription_lines()`).
  - It uses the same rule as classic licences: inside the profile's grace, the period continues from the old end; after grace, a full period starts today.
  - Month and year steps clamp to month end.
  - Lifetime lines and other subscriptions' lines never move.
  - `next_payment` becomes the start of the day after the new paid-through date, in the site's time zone.
  - The order note tells the owner the new date.
- **Check-in for entitlement licences** (`Licensing\CheckInService`), on the existing public routes:
  - `POST /activate` and `POST /heartbeat` also return `token_v2` (a fresh signed v2 token) and `server_time` (unix seconds).
  - They accept `usage: {users, seats, phones}` and `app_version`, and store them on the machine (`usage_json`), with the signed `fp` (`token_fp`).
  - A malformed fingerprint (anything but lowercase 64-hex) is refused before any machine is created.
  - A suspended licence still checks in, and its token says `suspended`. A revoked or terminated licence gets 403 and no token.
  - **Rate limit:** 30 check-ins per machine per rolling hour (`wplm_check_in_rate_limit`). Beyond that: HTTP 429 `wplm_rate_limited` with `retry_after`.
  - **Moves:** a self-service `POST /deactivate` of an active machine is a move, limited to 2 per rolling 30 days (`wplm_move_limit`). Beyond that: HTTP 429 `wplm_move_limit_reached` with `moves_reset_at`. The response carries `moves_left`.
  - `CheckInService::reset_moves()` lets the owner allow moves again. Re-deactivating an inactive machine is not a move.
  - Classic licences keep their responses, and have no move limit.
- Index `activation_log (machine_id, event, created_at)` for the rate limit.

### Changed
- **An entitlement licence never stores `expires_at`.** Its lines carry the dates, so no path can make classic validation mark it expired or refuse its activation.
  - Covered paths: checkout, renewal payment, subscription cancellation, first activation with `valid_for_days`, admin, REST.
  - Enforced once, in `LicenseRepository`. Turning a licence into an entitlement licence clears its expiry.
- `LicenseService::extend_term()` returns `null` for an entitlement licence.
- `ActivationLogRepository::count_events_since()` compared a UTC cutoff with site-time `created_at` values; it now compares in site time.

### Requirements
- DB version 1.2.0 (adds `wplm_entitlements`, `licenses.profile`, `plans.profile`, `packages.entitlements`, `machines.token_fp`, `machines.usage_json`).

---

## [1.1.0] — Unreleased

This release fixes billing and licensing correctness. The problems were found by running the real purchase → activate → renew paths on a live install ([docs/audit/2026-09-11-subscription-walk.md](docs/audit/2026-09-11-subscription-walk.md)). Every fix below has an integration test (`tests/`) that failed before it.

### Changed — how access ends when a customer does not pay
- **Billing never locks a licence by changing its status.**
  - Access ends because the paid term runs out: a licence stays valid until `expires_at` + `grace_days`, then validates as `expired`.
  - Suspending, revoking and terminating are owner actions only.
- **A recurring plan licence now expires at the end of the paid period** (the trial end or the first `next_payment`), instead of never. Each renewal extends it (F1).
- **Grace:** a new `wplm_default_grace_days` setting (default **7**) and a per-package **Grace (days)** field. New licences carry it.
- **Dunning (F3):** the retry schedule (`wplm_dunning_schedule`, default 1, 3, 5 days = three retries) now actually runs.
  - A declined charge keeps the subscription `active`, with the retry date as `next_payment`.
  - After the last retry, the subscription goes `on-hold` and the customer is sent a renewal invoice.
  - The licence is never suspended or revoked for a failed charge.
- **Subscription → licence sync:**
  - `active`/`trial` brings a pending, inactive or expired licence to active, but never lifts a suspension or revocation.
  - `on-hold` changes nothing.
  - `cancelled`/`expired` no longer revokes. The licence runs to the end of the paid period; a legacy perpetual licence is given that end date.
- **Renewal payments use one rule** (`LicenseService::extend_term()`, `RenewalProcessor::apply_payment()`) for cron card charges and paid renewal orders alike:
  - Paid before the end or inside grace → the new period continues from the old end.
  - Paid after grace → a full period starts from the payment.
  - `next_payment` follows the licence.

### Added
- **Manual renewal invoices (F2).**
  - When a subscription without a stored card falls due, one pending renewal order per cycle is created and emailed to the customer as a WooCommerce customer invoice. Previously `wplm_subscription_manual_renewal_due` had no listener, and nothing happened.
  - Invoices are tracked in `wplm_subscription_renewals` (`type = invoice`), so this works with and without HPOS.
  - New action: `wplm_subscription_renewal_invoiced`.
- New action: `wplm_license_term_extended`.
- An admin notice when a signing, encryption or fingerprint secret is missing.
- **An integration test suite** on real WordPress + WooCommerce (`wp-phpunit`); see `tests/README.md`.

### Fixed
- An expired licence stayed `expired` after a successful payment, so a paying customer was refused (F4).
- Late cron renewals were counted from the old expiry, so the customer lost the late days (F5).
- Self-service renewal did not re-sign the offline token, so offline clients kept the old expiry (F6).
- **Dates (F7).**
  - Due checks for subscriptions, heartbeats, API-key access and "expiring soon" used MySQL `NOW()` (the database server's time zone) against UTC values.
  - Purchase and scheduler dates used site-local time as if it were UTC.
  - On a server whose MySQL is not on UTC (for example Pakistan Standard Time), renewals came due hours early and floating-licence heartbeats were judged against the wrong clock.
- A missing secret fataled every request, including wp-admin, so the Tools page that fixes it was unreachable (F9). Secrets now load on first use.
- A fresh install had no key generator. A plan purchase issued no licence, only logged the failure, and still marked the order fulfilled (F10). Now:
  - activation seeds a default generator;
  - a failed fulfilment adds an order note and stays retryable (set the order to Processing or Completed again);
  - a retry never issues a second licence for an item already issued.
- The package editor had no "Valid for (days)" field, so saving a plan erased a one-time package's validity (F12).
- A licence that ran out was marked `expired` without firing `wplm_license_status_changed`, so the `license.expired` webhook and add-ons listening for it (e.g. Karobar's AI-key switch) never heard. It matters more now that expiry, not suspension, is how an unpaid licence ends. It fires once.
- Admin deactivation by machine id and zombie culling decremented the seat count instead of recomputing it. Deactivating an already inactive device is now a no-op.
- The schema upgrade ran only on `admin_init`. It now also runs on REST and cron requests, so a licence server updated without anyone opening wp-admin still migrates.

### Security
- **Public `POST /wplm/v1/deactivate` accepted a bare `machine_id`**, letting anyone deactivate any customer's device by counting upward (F11). The route now requires `license_key`, and a `machine_id` must belong to that licence.

### Requirements
- **PHP 8.0+.** It was stated as 7.4, but the code already required 8.0.
- DB version 1.1.0 (adds `packages.grace_days`).

### Upgrade notes
- **Subscriptions left `on-hold` by the old dunning, with a suspended licence, are not changed automatically.** Such a suspension can't be told apart from a manual one. After upgrading, review on-hold subscriptions: resume the subscription and reinstate the licence where appropriate.
- **Existing recurring licences with no expiry stay perpetual** until either their next renewal payment (which starts timing them) or cancellation (which gives them the paid-through end date). An admin tool to backfill `expires_at` from `next_payment` can follow. It was left out deliberately: it would lock overdue customers the moment it runs.
- **To lock a customer now, suspend the licence.** Pausing a subscription no longer does it.


## [1.0.8] — 2026-06-28

### Fixed
- Device deactivation failed with `Unknown column 'deactivated_at'` — the `wplm_machines` schema was missing the `deactivated_at` column referenced by `MachineRepository::deactivate()`. The column is now defined in the installer and added to existing tables via the `1.0.6` DB migration (DB version bumped). Devices now deactivate cleanly.

### Security / Hardening
- `RestServer` now suppresses error display **and** `$wpdb` error output for `wplm/v1` routes, so a database error can never corrupt a JSON response (failures are still logged).

## [1.0.7] — 2026-06-28

### Added
- **Product binding.** The Ed25519 signed license payload now carries a `pid` (product id) field. `LicenseService::build_signing_payload()` is the single source of truth used by `create()`, the `update()` re-sign path (now also triggered by a `product_id` change), and a new `resign_all()` migration. A **Settings → Tools → Re-sign licenses** tool re-signs every key and can backfill a product id onto keys that have none. All four client SDKs reject a key whose signed `pid` does not match the configured product id, online and offline (`product_mismatch` / `WplmProductMismatch`).

### Fixed
- `validate()` did not hash the device fingerprint before the machine lookup (activate/deactivate/heartbeat did), so an activated device was always reported `needs_activation: true`. `LicenseService` now receives `Crypto\Fingerprint` and hashes before lookup.
- Re-activating a previously-deactivated device (idempotent path) left the license inactive; the license is now reconciled to active whenever an active device is bound.

### Changed
- Seat counts are now recomputed from the actual active machines (`LicenseRepository::sync_activation_count()`) on activate, re-activate, and deactivate, replacing increment/decrement — the count can no longer drift after an error, retry, or race.

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
