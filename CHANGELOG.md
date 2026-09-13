# Changelog

All notable changes to WP License Manager are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). This project uses [Semantic Versioning](https://semver.org/).

---

## [1.2.3] — 2026-09-13

### Changed
- **The licence keys are now printed in WooCommerce's completed-order email**, under the order table, instead
  of arriving as a separate plain-text message. That separate email is no longer sent. Customers open the
  order email; a bare text message beside it was easy to miss, easy to lose and easy to mistake for spam.
  Admin copies and the processing email never carry keys.
- **Issuance moved to priority 5 on `woocommerce_order_status_completed`.** WooCommerce sends its
  completed-order email on the same hook at priority 10, so the licences have to exist before it renders.
- New filter `wplm_email_order_licence_lines` lets an add-on print extra lines in that block — used by the
  Super Ledger add-on for the office address.

### Removed
- `CheckoutHandler::deliver_license_email()` and `::send_license_email()`, which existed only to send the
  separate message. The admin's Resend action on an order is unchanged and still sends a plain message.

## [1.2.2] — 2026-09-13

### Changed
- **Licences are issued when an order is Completed, and no longer when it reaches Processing.** Both the
  standard and the plan/package path used to fulfil on either status, so a customer paying through a gateway
  that lands orders in Processing received their key before the shop had confirmed the order. The shop now
  decides when a key goes out, by marking the order Completed. **A store that leaves paid orders in Processing
  and never completes them will stop delivering keys** — complete those orders and the keys are issued and
  emailed as usual.

### Fixed
- **A standard order that could not issue every key was still marked delivered**, so re-completing it never
  retried and the customer was left without a key. Issuance now records the failure as an order note, leaves
  the order retryable (as the plan path already did), and skips line items that already have their keys so a
  retry never issues a second one.

## [1.2.1] — 2026-09-12

### Fixed
- **The zombie cull deactivated machines that were never floating.** `MachineRepository::create()` bound a null
  `lease_expires_at` as `''`, which MySQL outside strict mode stores as `0000-00-00 00:00:00`. Every machine
  therefore looked leased, and every licence with a heartbeat — floating or not — lost its machine 20 minutes
  after the last one. Found on the live Super Ledger licence server: an office that checks in every 6 hours was
  refused its next check-in with `machine_inactive`.
  - `create()` now writes SQL `NULL` for every null value.
  - The database upgrade to 1.2.1 clears the zero-date leases already stored.
  - `Machine` reads a zero date as null, and the cull ignores one.
- **An entitlement licence is never floating, and never culled.** It checks in with its own deadline
  (`checkInBy`). Creating or updating a profile licence forces `is_floating` off, activation gives its machine
  no lease even if an older row says floating, the cull skips profile licences, and the admin form hides the
  Floating checkbox for them.

## [1.2.0] — Unreleased

Entitlement licences: per-module and per-limit lines with their own paid-through dates, and a signed, machine-bound v2 token. Classic licences, their v1 tokens and every other product are unchanged.

### Added
- **Licence profiles.** A plan can sell a licence profile. A licence bought from such a plan is an *entitlement licence*.
  - WPLM builds no profile in. A product's add-on plugin registers its own (`Licensing\Profile`: code, label, module codes, limit codes) through the `wplm_license_profiles` filter.
  - A profile code is lower-case letters, digits and hyphens (at most 32); a malformed registration is ignored.
  - A licence whose profile nobody registers gets no v2 token, so the add-on must stay active on the licence server.
- **Entitlement lines** (`wplm_entitlements`).
  - A `module` line grants one of the profile's modules; a `limit` line adds to one of its limits (for example `users`).
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
- **Offline renewal codes** (`Licensing\OfflineCodeService`, `wp wplm offline-code <machine-id> --days=30`).
  - A code is an ordinary v2 token for that machine, with the check-in deadline the owner picks (1–365 days).
  - It is logged as `offline_code`, with who issued it and for how many days.
  - It is refused for a machine that has not checked in since the upgrade, because its signed `fp` is not yet known.
- **Fleet extension notice** (`Licensing\FleetNotice`): `{"v":1,"pid":"<profile code>","kind":"extend-check-in","until","iat"}`, signed from a keypair **file**.
  - `wp wplm fleet-notice --pid=<profile code> --until=YYYY-MM-DD --keypair-file=…` signs it. `--pid` is required: a notice for the wrong product is rejected by every client.
  - `bin/fleet-notice.php` does the same without WordPress: plain PHP with sodium.
  - It refuses more than 30 days. A bare date means the end of that day in Asia/Karachi (`--timezone`).
- **"Will become read-only" email** (`Licensing\LapseNoticeService`, cron `wplm_lapse_notices`, hourly; template `templates/emails/read-only-soon.php`).
  - Sent on a module's paid-through day, naming the last working day (`paid_through + grace`) and the first read-only day. A lapsing base module (`Profile::$base_module`) is worded as the whole product; other modules are named.
  - Sent once per module and date: each notice is logged as `lapse_notice`. A renewal that moves the date starts a new cycle.
  - A missed run catches up while the module is still in grace, never after read-only has started. A failed send is retried on the next run.
  - It goes to the purchase order's billing email, else the licence owner's account email (filter `wplm_lapse_notice_recipient`). A licence with neither is logged as `no_recipient`.
  - Suspended, revoked and terminated licences are not emailed. Action: `wplm_lapse_notice_sent`.
- Profiles carry human names for their codes (`Profile::code_label()`) and may name a **base module** (`Profile::$base_module`): the module without which the whole product is read-only. A profile without one treats every module alone.
- **"Licences are not being renewed" notice** (`Admin\UnregisteredProfileNotice`): every admin screen names any licence type that licences or plans use but no active plugin registers, since their computers get no new tokens and go read-only when the check-in window runs out.
- **Settings → "<Profile> licences"**: the check-in window and grace for each licence profile (blank = default 7; 1–60 and 0–60 days). A change reaches each computer at its next check-in.
- **Settings → Subscriptions → Default grace (days)**, the 1.1.0 `wplm_default_grace_days` setting, which had no field.
- **The entitlement licence screen** (Licences → Edit, for a licence with a profile):
  - **What this licence grants today:** each module's paid-through date and state (active, due soon, grace, read-only, lifetime), each limit against the usage last reported, and a warning when the profile's base module has no line.
  - **Entitlement lines:** add, change the date or quantity, extend by months (the renewal rule), remove. A line of another licence cannot be reached through a tampered id.
  - **Computers:** last check-in, app version, usage, and **Offline renewal code** with a days field (default 30). The code is shown once, with a copy button, and as a QR when a QR renderer is installed. Also the moves left, and **Reset moves**.
  - **Status:** suspend and revoke need a note; reinstate takes an optional one. Each change is logged as `status_note` with the note and the admin.
  - **Licence log:** status notes, offline codes, read-only notices, moves and activations.
  - Logic in `Admin\LicenceAdminActions`; every panel form posts to `admin-post.php?action=wplm_licence_action`.
- **The plan editor sells modules and limits.** A plan chooses its licence type (classic, or a registered profile).
  - Each licence type (package) of a profile plan gets a checkbox per module and a quantity per limit of the profile: the template a purchase writes onto the licence.
  - Every template is checked before the plan or its packages change, so a bad one leaves the plan as it was.
  - Saving warns when no licence type grants the profile's base module: right for an add-on plan, wrong for a main one.
  - Grace and Valid-for fields are hidden for a profile plan: its dates come from the lines and the profile's grace.
  - Logic in `Admin\PlanAdminActions`; save results and warnings are shown through `Admin\Flash`.
- **My Account → My Licenses → View Devices** now opens (the link went nowhere):
  - the licence's active computers;
  - for an entitlement licence, each module's paid-through date and state, and the moves left;
  - a **Move to another computer** button per computer. This is a self-service move that counts against the limit like one made from the app. A third move within 30 days is refused, naming the day it frees up.
  - A customer sees and moves only their own licences. The list shows an entitlement licence's modules instead of "Expires: Never". (`Integrations\WooCommerce\MyAccountLicences`.)
- The Add Licence form chooses the licence type (classic, or a registered profile). An entitlement licence opens on its lines after it is created. Its screen has no expiry, grace or valid-for fields.
- Bulk Suspend and Revoke leave entitlement licences unchanged and say so: those are locked from their own screen, with a note. Their list rows link there instead of offering Revoke or Delete.
- `EntitlementService::extend_line()`, `renewed_paid_through()` (the renewal rule, now shared with renewals) and `module_state()`.
- A warning on the Settings page when licence tokens are signed with a keypair from the database on a `production` site. Define `WPLM_SIGNING_KEYPAIR` in `wp-config.php` instead.
- `TokenV2Service::compose()` and `wire()` are public and static, so a product's add-on can build contract fixtures with the same composition code as real tokens.
- `Crypto\CompactToken`: the token format in one place, usable without WordPress. `Signer` delegates to it, and its output is byte-identical to before.

### Changed
- **An entitlement licence never stores `expires_at`.** Its lines carry the dates, so no path can make classic validation mark it expired or refuse its activation.
  - Covered paths: checkout, renewal payment, subscription cancellation, first activation with `valid_for_days`, admin, REST.
  - Enforced once, in `LicenseRepository`. Turning a licence into an entitlement licence clears its expiry.
- `LicenseService::extend_term()` returns `null` for an entitlement licence.
- `ActivationLogRepository::count_events_since()` compared a UTC cutoff with site-time `created_at` values; it now compares in site time.

### Fixed
- **Saving the Settings page failed with a fatal error.** `options.php` passes `null` for the group option the form never posts, and `validate_settings()` accepted only an array.
- **The dunning retry schedule entered in Settings was ignored.** The page saves a comma list ("2,4,9") but dunning read only a JSON array, so it always used 1, 3, 5. Both forms are read now. The field also showed "3,7,14" as the default, which was never the default.
- **My Account → My Licenses was always empty.** Listing licences without an explicit sort built `ORDER BY  DESC`, so the query failed and customers were told they had no licences.
- **Add New Licence always failed.** The form had no key field and ignored the chosen generator, so creation threw "key_string is required". A blank key is now generated with the chosen generator, else the default one. Errors come back to the form as a notice instead of a fatal.

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
- A licence that ran out was marked `expired` without firing `wplm_license_status_changed`, so the `license.expired` webhook and add-ons listening for it never heard. It matters more now that expiry, not suspension, is how an unpaid licence ends. It fires once.
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
