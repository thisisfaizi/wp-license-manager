# WP License Manager (WPLM) — Build Specification

> **Industry-Grade Software Licensing for WooCommerce · Open Source (GPL v2)**

> **AGENT BRIEF** — This is an implementation specification written for an autonomous coding agent (Claude Code). Build the plugin exactly as specified below. Each section defines concrete files, database schemas, classes, REST endpoints, and acceptance criteria. Work through the build order in Section 15. Do not ask for clarification on items that are fully specified here — implement them.

> **⚠️ READ FIRST — AMENDMENTS BELOW.** The "Implementation Amendments" section
> immediately following records where the shipped plugin **intentionally
> supersedes** the original spec. Where the two conflict, the amendments win.
> **Keep this section updated whenever features or architecture change.**

---

## Implementation Amendments (current architecture)

These changes supersede the matching original sections. Last updated: 1.0.6.

### A1 — Commerce model: Plans & Packages (supersedes §9.1 product types, §12.3)
The custom WooCommerce product types `wplm-subscription` /
`wplm-variable-subscription` were **removed** — they fought WooCommerce internals
(wrong data store → null-variation crashes) and duplicated billing fields.

Instead, commerce is driven by reusable **Plans** containing one or more
**Packages**:
- `wplm_plans` (name, description, status) and `wplm_packages` tables.
- A Package has `billing_type` (`recurring` | `lifetime` | `onetime`),
  period/interval, price, signup_fee, trial_days, length_cycles, generator_id,
  max_activations, overage_strategy, valid_for_days, benefits[], sort_order, status.
- A plan is **assigned to any native WooCommerce product** via the Licensing tab
  (`_wplm_plan_id`). The storefront shows a "Choose a package" selector; price
  displays as "From {min package price}" and the product is purchasable without a
  WC price (`PlanCheckout` filters `woocommerce_is_purchasable` / `…_get_price_html`).
- `PlanCheckout` fulfils on order completion: recurring → subscription + bound
  license; lifetime/one-time → a license. `CheckoutHandler` only handles simple
  one-time `_wplm_is_licensed` products and skips items carrying `_wplm_package_id`.
- Admin: **License Manager → Plans** (`PlanService`, plan editor, `handle_save_plan`).
- **UI terminology:** Packages are presented to users as **"License Types"**
  everywhere in the admin, storefront, and docs (clearer for store owners). The
  internal model, `wplm_packages` table, `Package` class, `_wplm_package_id` meta
  and form field names are **unchanged** — only the user-facing labels differ.

### A2 — Webhooks: event bridge + no-code presets (extends §1.6, §10)
- `WebhookEventBridge` maps internal `wplm_*` actions → dotted event slugs and
  calls `WebhookService::dispatch()` (the original spec defined dispatch but never
  wired the trigger).
- `wplm_webhooks.format` column adds **no-code delivery presets**: `json` (signed
  HMAC envelope), `slack`, `discord`, `email`. A "Send test delivery" admin button
  posts a `test.ping`. New filter `wplm_webhook_payload`.

### A3 — Front-end license portal (extends §1.6 self-service)
`[wplm_license_manager]` shortcode (`LicenseShortcode`) renders a standalone,
themeable license lookup/management form on any page — status, seats, devices,
and self-deactivate — independent of WooCommerce My Account.

### A4 — Crypto: at-rest cipher (supersedes §1.1, §4, §17 "AES-256-GCM" default)
`KeyVault` **always encrypts with XChaCha20-Poly1305-IETF** and prefixes each blob
with a 1-byte algorithm marker (`0x01`=AES-256-GCM, `0x02`=XChaCha20). Decryption
is marker-driven, never runtime CPU detection — so stored keys survive
server/CPU/library migrations. AES-GCM blobs remain decryptable for compat.

### A5 — Subscription emails
`SubscriptionMailer` sends the templates/emails/ templates on subscription
lifecycle hooks (created/renewed/payment-failed/cancelled) and applies the
`wplm_subscription_email_content` filter.

### A6 — Schema additions vs §3 (now 17 tables)
Added `wplm_plans`, `wplm_packages`; `wplm_webhooks` gained a `format` column.
`wplm_subscription_notes` is present (the spec listed 14; shipped = 17).

### A7 — Product binding in signed payload (extends §4 crypto, client SDKs)
The Ed25519 signed license payload gained a `pid` (product id) field alongside
`key`/`expires`/`max`/`iat`. `LicenseService::build_signing_payload()` is the
single source of truth, used by `create()`, the `update()` re-sign path
(`product_id` now also triggers a re-sign), and `resign_all()`. A new admin tool
**Settings → Tools → Re-sign licenses (product binding)** re-signs every token and
optionally backfills a product id onto keys that have none (generator/API/CSV
keys sign `pid: null`). All four client SDKs (dart/python/php/js) enforce product
binding: when configured with a product id, they reject any token whose signed
`pid` differs (or is absent) with a `product_mismatch` / `WplmProductMismatch`
error, **online and offline**, derived from the signed payload (not the unsigned
`license` JSON). Configuring no product id opts out (backward compatible). The
`/validate` endpoint is unchanged — no server-side product param was added.

---

## Project Identity

| Field | Value |
|---|---|
| Plugin Name | WP License Manager (WPLM) |
| Text Domain | `wp-license-manager` |
| Function Prefix | `wplm_` |
| Class Namespace | `WPLM\` |
| DB Table Prefix | `{$wpdb->prefix}wplm_` |
| Constant Prefix | `WPLM_` |
| License | GPL v2 or later (open source) |
| Min PHP | 7.4 (target 8.1+ compatible) |
| Min WordPress | 5.8 |
| Min WooCommerce | 6.0 (optional — core + subscriptions work standalone) |
| Min MySQL | 5.7 / MariaDB 10.3 |

---

## Contents

1. Product Scope & Feature Inventory
2. Architecture & File Structure
3. Database Schema (14 tables)
4. Cryptography & Key Signing
5. License Lifecycle & Status Model
6. Device / Machine Activation Engine
7. Monitoring, Heartbeats & Telemetry
8. Revocation, Blacklisting & Fraud Controls
9. Native Subscription Engine (no WC Subscriptions needed)
10. REST API (complete endpoint map)
11. Internal PHP API — Functions, Actions, Filters
12. Admin UI & WooCommerce Integration
13. Documentation Website & Per-Phase Docs
14. Client SDK Suite (WordPress, Flutter, JS, Python, …)
15. Build Order for Claude Code
16. Acceptance Criteria & Test Matrix
17. Coding Standards & Security Rules

---

## 1. Product Scope & Feature Inventory

*WPLM is a self-hosted licensing server that issues, validates, activates, monitors, and revokes software license keys. It runs as a WordPress plugin, integrates with WooCommerce for selling licenses, and exposes a REST API that client software (desktop apps, plugins, SaaS) calls to enforce licensing. All features below ship in one open-source package — there is no paid tier.*

### 1.1 Core Licensing
- License key CRUD — create, read, update, delete via admin UI, REST API, and PHP functions.
- Cryptographically signed keys (Ed25519) for tamper-proof offline validation.
- AES-256-GCM encryption of stored key strings; SHA-256 hash column for O(1) lookup.
- Per-license expiry (absolute date) and "valid for N days from activation" relative expiry.
- Grace period support after expiry before hard lockout.
- License key generators with configurable charset, chunks, length, separator, prefix, suffix.
- Bulk generation of N keys from a generator into an available pool.
- Import/export licenses as CSV.

### 1.2 Device & Machine Management (industry-grade)
- Device fingerprint binding — node-lock a license to specific hardware fingerprints (HMAC-SHA256 anonymized).
- Allowed device limit (max activations / seats) per license, overridable per product.
- Per-device records: fingerprint, friendly name, hostname, IP, OS, app version, first/last seen.
- Floating / concurrent licensing — lease a seat from a pool, auto-return on lease expiry.
- Activation & deactivation with automatic seat accounting.
- Component tracking — sub-fingerprints for hardware components (CPU, mobo, disk) under one machine.
- Overage strategy per license (deny / allow 1.25x / allow 2x) for cloud rolling deployments.

### 1.3 Monitoring & Telemetry
- Machine heartbeat monitor — devices ping on an interval; missed pings auto-deactivate "zombie" seats.
- Configurable heartbeat interval and dead-machine cull window.
- Full activation log — every activate/deactivate/validate/heartbeat event with timestamp, IP, fingerprint.
- Usage analytics dashboard — active seats, validations/day, geographic spread, version distribution.
- Anomaly flags — impossible travel, fingerprint churn, validation rate spikes.

### 1.4 Revocation & Security Controls
- Instant license revocation — flips status to revoked; all future validations fail.
- Per-device revocation — kick a single device without killing the whole license.
- Fingerprint blacklist and IP blacklist — global deny lists.
- Suspend / reinstate licenses (temporary hold distinct from permanent revoke).
- Terminated status — permanent, non-reversible kill switch.
- Signed revocation list (CRL-style) clients can cache for offline enforcement.

### 1.5 Native Recurring Billing & Subscriptions
*WPLM ships its own subscription engine so a store can sell recurring licenses without the paid WooCommerce Subscriptions extension. Full detail in Section 9.*
- Subscription products — sell licenses billed weekly, monthly, quarterly, or yearly (any interval/period).
- Free trials and sign-up fees per subscription product.
- Automatic recurring payments via gateway tokenization (Stripe/PayPal/etc. through WooCommerce tokens).
- Manual renewal fallback — emailed renewal invoice payable through any WooCommerce gateway.
- Failed-payment retry (dunning) with configurable retry schedule before suspension.
- Customer self-service — pause, resume, cancel, change payment method, upgrade/downgrade (switching with proration).
- Renewal synchronization — align all renewals to a fixed day with first-payment proration.
- Subscription-aware coupons — recurring discounts and sign-up-fee discounts.
- Variable subscriptions — customer chooses billing schedule at purchase.
- Multiple subscriptions per order, grouped for fewer gateway charges.
- Subscription lifecycle is bound to the license: active→license active, on-hold→suspended, cancelled/expired→revoked.
- Recurring-revenue reports — MRR, active subscribers, churn, renewals, upcoming revenue.
- Subscription emails — renewal processed, payment failed, cancelled, expired, trial ending.

### 1.6 Distribution & Commerce
- WooCommerce product binding (simple + variable) with per-variation license rules.
- Auto-issue & deliver keys on order completion; resend on demand.
- Software release / application management — versions, changelog, secured file downloads via token.
- Update API — licensed clients check for and pull updates.
- QR code generation encoding the license key for delivery.
- Webhooks — fire signed HTTP callbacks on license/device/subscription events with retry.
- Customer "My Account" portal — view keys, manage devices, deactivate seats, manage subscriptions.

---

## 2. Architecture & File Structure

Object-oriented, PSR-4 autoloaded under the `WPLM\` namespace. A lightweight service container wires modules. No global state beyond the documented PHP API functions in Section 11.

### 2.1 Directory Layout

```
wp-license-manager/
├── wp-license-manager.php          # Bootstrap: headers, constants, autoloader, activation hooks
├── uninstall.php                   # Clean removal (guarded by a 'keep data' option)
├── composer.json                   # PSR-4 map, dev deps (phpunit, phpcs)
├── README.md  CHANGELOG.md  LICENSE
│
├── src/
│   ├── Plugin.php                  # Main singleton, boots all modules
│   ├── Container.php               # Tiny DI container
│   ├── Install/
│   │   ├── Installer.php           # dbDelta schema creation, versioned migrations
│   │   ├── Migrator.php            # Runs upgrade routines by schema version
│   │   └── Seeder.php              # Default settings + Ed25519 keypair generation
│   ├── Crypto/
│   │   ├── KeyVault.php            # AES-256-GCM encrypt/decrypt of key strings
│   │   ├── Signer.php              # Ed25519 sign / verify (sodium)
│   │   └── Fingerprint.php         # HMAC-SHA256 fingerprint hashing helpers
│   ├── Models/
│   │   ├── License.php  Generator.php  ApiKey.php
│   │   ├── Machine.php  Component.php  ActivationLog.php
│   │   ├── Release.php  Webhook.php  Blacklist.php
│   │   ├── Subscription.php  SubscriptionItem.php  Renewal.php
│   ├── Repositories/               # One repo per table — all $wpdb->prepare()
│   │   ├── LicenseRepository.php  MachineRepository.php  ...
│   │   ├── SubscriptionRepository.php  RenewalRepository.php
│   ├── Services/
│   │   ├── LicenseService.php      # Issue, validate, expire, renew logic
│   │   ├── ActivationService.php   # Seat accounting, overage, floating leases
│   │   ├── HeartbeatService.php    # Ping ingest + zombie culling (cron)
│   │   ├── RevocationService.php   # Revoke, suspend, blacklist, CRL builder
│   │   ├── GeneratorService.php    # Key string generation
│   │   ├── AnalyticsService.php    # Dashboard aggregates (cached transients)
│   │   ├── WebhookService.php      # Dispatch + retry queue
│   │   └── Subscriptions/          # NATIVE recurring-billing engine
│   │       ├── SubscriptionService.php   # Create/pause/resume/cancel lifecycle
│   │       ├── BillingScheduler.php      # Next-payment dates, sync, proration
│   │       ├── RenewalProcessor.php      # Cron: charge due renewals via tokens
│   │       ├── DunningManager.php        # Failed-payment retry schedule
│   │       ├── SwitchManager.php         # Upgrade/downgrade with proration
│   │       └── GatewayBridge.php         # WC payment-token charge abstraction
│   ├── Rest/
│   │   ├── RestServer.php          # Registers all routes + auth middleware
│   │   ├── Auth/BasicAuth.php      # consumer_key:secret verification
│   │   └── Controllers/            # One controller per resource group
│   │       ├── LicensesController.php  MachinesController.php
│   │       ├── GeneratorsController.php  ReleasesController.php
│   │       ├── SubscriptionsController.php
│   │       └── ValidationController.php
│   ├── Admin/
│   │   ├── Menu.php                # Admin menu tree
│   │   ├── ListTables/             # WP_List_Table subclasses
│   │   ├── Screens/                # Add/Edit forms, dashboard, settings tabs
│   │   └── Settings/SettingsPage.php
│   ├── Integrations/
│   │   ├── WooCommerce/            # Product panel, order hooks, My Account
│   │   │   ├── SubscriptionProductType.php  # 'subscription' + 'variable-subscription'
│   │   │   ├── CheckoutHandler.php          # Creates subscription on purchase
│   │   │   └── MyAccountSubscriptions.php   # Customer manage/pause/cancel UI
│   │   ├── Compat/WcsCompat.php    # OPTIONAL bridge if WC Subscriptions IS present
│   │   └── Qr/QrGenerator.php
│   ├── Cron/Scheduler.php          # Registers WP-Cron events (renewals, dunning, culls)
│   └── Support/                    # Logger, Validator, ResponseFactory, helpers
│
├── assets/  (admin css/js, icons, vendor chart lib)
├── templates/  (emails, my-account, qr)
├── languages/  wp-license-manager.pot
├── tests/  (phpunit: unit + integration + rest)
│
└── docs-site/                       # Astro Starlight documentation website
    ├── package.json  astro.config.mjs  tsconfig.json
    ├── src/
    │   ├── content/docs/            # All .md / .mdx documentation pages
    │   │   ├── index.mdx            # Landing / overview
    │   │   ├── getting-started/     # Install, setup, first license
    │   │   ├── guides/              # Licenses, devices, subscriptions, revocation…
    │   │   ├── rest-api/            # Endpoint reference (mirrors Section 10)
    │   │   ├── php-api/             # Functions, actions, filters (mirrors Section 11)
    │   │   ├── build-log/           # ONE page per build phase (written as built)
    │   │   └── reference/           # DB schema, status codes, error codes
    │   └── content.config.ts        # Starlight content collection schema
    ├── public/                      # Logo, favicon, static images
    └── dist/                        # Built static site → upload to docs subdomain
```

---

## 3. Database Schema

Fourteen tables, all prefixed `{$wpdb->prefix}wplm_`. Created via `dbDelta()` in `Installer.php`. Store the schema version in the `wplm_db_version` option and run Migrator on version bump. All FKs enforced in application logic (MySQL engine = InnoDB, utf8mb4). The final four tables power the native subscription engine (Section 9).

### wplm_licenses
| Column | Type | Description |
|---|---|---|
| id | BIGINT UNSIGNED PK | Auto-increment primary key |
| license_key | LONGTEXT | AES-256-GCM encrypted key string |
| hash | CHAR(64) UNIQUE | SHA-256 of plaintext key for fast lookup |
| signature | TEXT | Ed25519 signature of the key payload |
| product_id | BIGINT | WooCommerce product (nullable) |
| order_id | BIGINT | WooCommerce order (nullable) |
| user_id | BIGINT | Owning WP user / customer (nullable) |
| status | TINYINT | 0 pending,1 active,2 inactive,3 expired,4 suspended,5 revoked,6 terminated |
| max_activations | INT | Allowed device seats (NULL = unlimited) |
| activation_count | INT DEFAULT 0 | Current active seats |
| is_floating | TINYINT DEFAULT 0 | 1 = concurrent/floating license |
| overage_strategy | VARCHAR(20) | deny / allow_1_25x / allow_2x |
| valid_for_days | INT | Relative expiry days from first activation (nullable) |
| activated_at | DATETIME | First activation timestamp (nullable) |
| expires_at | DATETIME | Absolute expiry (NULL = perpetual) |
| grace_days | INT DEFAULT 0 | Days of grace after expiry |
| source | TINYINT | 0 import,1 generator,2 api,3 woocommerce |
| created_at | DATETIME | Creation timestamp |
| created_by | BIGINT | Creator user id |
| updated_at | DATETIME | Last modified timestamp |

### wplm_machines (devices)
| Column | Type | Description |
|---|---|---|
| id | BIGINT UNSIGNED PK | Primary key |
| license_id | BIGINT | FK to wplm_licenses.id |
| fingerprint | CHAR(64) | HMAC-SHA256 device fingerprint |
| name | VARCHAR(191) | Friendly device name (optional) |
| hostname | VARCHAR(191) | Reported hostname (optional) |
| ip_address | VARBINARY(16) | Last seen IP (packed, v4/v6) |
| platform | VARCHAR(60) | OS / platform string |
| app_version | VARCHAR(40) | Client app version at activation |
| lease_expires_at | DATETIME | Floating lease expiry (nullable) |
| last_heartbeat_at | DATETIME | Last successful ping |
| status | TINYINT | 1 active, 2 deactivated, 3 revoked |
| activated_at | DATETIME | Activation timestamp |
| created_at | DATETIME | Row creation |
| UNIQUE KEY | (license_id, fingerprint) | One row per device per license |

### wplm_machine_components
| Column | Type | Description |
|---|---|---|
| id | BIGINT UNSIGNED PK | Primary key |
| machine_id | BIGINT | FK to wplm_machines.id |
| fingerprint | CHAR(64) | Component sub-fingerprint |
| kind | VARCHAR(40) | cpu / motherboard / disk / mac / gpu / custom |
| name | VARCHAR(191) | Human label |
| created_at | DATETIME | Row creation |

### wplm_activation_log
| Column | Type | Description |
|---|---|---|
| id | BIGINT UNSIGNED PK | Primary key |
| license_id | BIGINT | FK license (indexed) |
| machine_id | BIGINT | FK machine (nullable) |
| event | VARCHAR(30) | validate/activate/deactivate/heartbeat/revoke/deny |
| result | VARCHAR(20) | success / fail / limit_exceeded / expired / revoked |
| ip_address | VARBINARY(16) | Request IP |
| country | CHAR(2) | Geo country code (optional) |
| meta | JSON | Arbitrary event detail |
| created_at | DATETIME | Event time (indexed) |

### wplm_generators
| Column | Type | Description |
|---|---|---|
| id | BIGINT UNSIGNED PK | Primary key |
| name | VARCHAR(191) | Generator label |
| charset | VARCHAR(191) | Character pool |
| chunks | TINYINT | Number of segments |
| chunk_length | TINYINT | Characters per segment |
| separator | VARCHAR(8) | Segment separator |
| prefix | VARCHAR(40) | Optional prefix |
| suffix | VARCHAR(40) | Optional suffix |
| default_max_activations | INT | Seat default for issued keys |
| default_valid_days | INT | Expiry default for issued keys |
| created_at | DATETIME | Row creation |

### wplm_api_keys
| Column | Type | Description |
|---|---|---|
| id | BIGINT UNSIGNED PK | Primary key |
| user_id | BIGINT | Owner WP user |
| description | VARCHAR(191) | Human label |
| permissions | VARCHAR(12) | read / write / read_write |
| consumer_key | CHAR(64) | Hashed consumer key |
| consumer_secret | CHAR(64) | Hashed secret |
| truncated_key | CHAR(7) | Display tail |
| last_access_at | DATETIME | Last use |
| created_at | DATETIME | Row creation |

### wplm_releases (software versions)
| Column | Type | Description |
|---|---|---|
| id | BIGINT UNSIGNED PK | Primary key |
| product_id | BIGINT | Linked WooCommerce product |
| version | VARCHAR(40) | Semver version string |
| channel | VARCHAR(20) | stable / beta / rc |
| changelog | LONGTEXT | Release notes (markdown) |
| file_path | TEXT | Protected file location |
| file_hash | CHAR(64) | SHA-256 of artifact |
| min_app_version | VARCHAR(40) | Minimum upgradable-from version |
| released_at | DATETIME | Publish timestamp |

### wplm_webhooks
| Column | Type | Description |
|---|---|---|
| id | BIGINT UNSIGNED PK | Primary key |
| name | VARCHAR(191) | Label |
| target_url | TEXT | Delivery URL (HTTPS) |
| events | JSON | Subscribed event names |
| secret | CHAR(64) | HMAC signing secret |
| status | TINYINT | 1 active, 0 paused |
| created_at | DATETIME | Row creation |

### wplm_webhook_log
| Column | Type | Description |
|---|---|---|
| id | BIGINT UNSIGNED PK | Primary key |
| webhook_id | BIGINT | FK webhook |
| event | VARCHAR(40) | Event delivered |
| payload | JSON | Body sent |
| response_code | SMALLINT | HTTP status received |
| attempts | TINYINT | Delivery attempts |
| delivered_at | DATETIME | Success time (nullable) |
| created_at | DATETIME | Queued time |

### wplm_blacklist
| Column | Type | Description |
|---|---|---|
| id | BIGINT UNSIGNED PK | Primary key |
| type | VARCHAR(20) | fingerprint / ip / email |
| value | VARCHAR(191) | Blacklisted value (indexed) |
| reason | VARCHAR(191) | Why blacklisted |
| created_at | DATETIME | Row creation |

### wplm_license_meta
| Column | Type | Description |
|---|---|---|
| meta_id | BIGINT UNSIGNED PK | Primary key |
| license_id | BIGINT | FK license |
| meta_key | VARCHAR(191) | Key (indexed) |
| meta_value | LONGTEXT | Value |

### wplm_subscriptions (native recurring billing)
| Column | Type | Description |
|---|---|---|
| id | BIGINT UNSIGNED PK | Primary key |
| parent_order_id | BIGINT | WooCommerce order that started the subscription |
| user_id | BIGINT | Subscriber WP user |
| license_id | BIGINT | Bound license (nullable until issued) |
| status | VARCHAR(20) | active/on-hold/pending-cancel/cancelled/expired/trial/pending |
| billing_interval | SMALLINT | Every N periods (e.g. 1, 3) |
| billing_period | VARCHAR(10) | day / week / month / year |
| recurring_total | DECIMAL(18,4) | Amount charged each cycle |
| currency | CHAR(3) | ISO currency code |
| signup_fee | DECIMAL(18,4) | One-time fee on first order |
| trial_end | DATETIME | Trial end (nullable) |
| next_payment | DATETIME | Next scheduled charge (nullable) |
| last_payment | DATETIME | Last successful charge |
| end_date | DATETIME | Fixed end / expiry (nullable = until cancelled) |
| payment_method | VARCHAR(60) | WC gateway id used for renewals |
| payment_token_id | BIGINT | WC payment token for automatic rebilling (nullable) |
| failed_attempts | TINYINT DEFAULT 0 | Current dunning retry count |
| created_at | DATETIME | Row creation |
| updated_at | DATETIME | Last modified |

### wplm_subscription_items
| Column | Type | Description |
|---|---|---|
| id | BIGINT UNSIGNED PK | Primary key |
| subscription_id | BIGINT | FK wplm_subscriptions.id |
| product_id | BIGINT | WooCommerce product |
| variation_id | BIGINT | Variation (nullable) |
| quantity | INT DEFAULT 1 | Item quantity |
| line_total | DECIMAL(18,4) | Recurring line total |
| meta | JSON | Captured product/billing meta |

### wplm_subscription_renewals
| Column | Type | Description |
|---|---|---|
| id | BIGINT UNSIGNED PK | Primary key |
| subscription_id | BIGINT | FK subscription |
| order_id | BIGINT | Generated WooCommerce renewal order |
| type | VARCHAR(20) | renewal / switch / resubscribe |
| amount | DECIMAL(18,4) | Charged amount |
| status | VARCHAR(20) | success / failed / pending |
| gateway_txn | VARCHAR(191) | Gateway transaction reference |
| scheduled_for | DATETIME | When the charge was due |
| processed_at | DATETIME | When processed (nullable) |
| created_at | DATETIME | Row creation |

### wplm_subscription_notes
| Column | Type | Description |
|---|---|---|
| id | BIGINT UNSIGNED PK | Primary key |
| subscription_id | BIGINT | FK subscription |
| note | TEXT | Audit/status note (system or admin) |
| is_customer | TINYINT DEFAULT 0 | 1 = shown to customer |
| created_by | BIGINT | Author user id (0 = system) |
| created_at | DATETIME | Row creation |

---

## 4. Cryptography & Key Signing

Use PHP sodium (libsodium) — it ships with PHP 7.2+. Generate a keypair on install and store it securely. Keys are signed so clients can verify authenticity offline.

### 4.1 Secrets generated on install (Seeder.php)
| Secret | Purpose |
|---|---|
| `WPLM_SIGNING_KEYPAIR` | Ed25519 keypair. Private key stored in an autoloaded-off option (or wp-config constant if present). Public key exposed via REST for client verification. |
| `WPLM_ENCRYPTION_KEY` | 256-bit key for AES-256-GCM encryption of stored license_key strings. |
| `WPLM_FINGERPRINT_HMAC` | Secret used to HMAC-SHA256 device fingerprints before storage (anonymization). |
| `WPLM_WEBHOOK_SECRET` | Default HMAC secret for signing webhook payloads. |

### 4.2 Signed license payload
A signed key encodes a JSON payload, base64url-encoded, with an appended Ed25519 signature — clients verify with the public key without contacting the server:

```php
// Signer::sign( array $payload ) : string
$payload = [
  'key'     => 'A1B2-C3D4-E5F6-G7H8',
  'expires' => '2027-01-01T00:00:00Z',
  'max'     => 3,                 // max devices
  'features'=> ['pro','api'],
  'iat'     => 1735689600
];
$body = base64url( json_encode($payload) );
$sig  = base64url( sodium_crypto_sign_detached($body, $privateKey) );
$signedKey = $body . '.' . $sig;   // client splits on '.' and verifies

// Client-side verify (any language):
// sodium_crypto_sign_verify_detached(sig, body, WPLM_PUBLIC_KEY)
```

> **WHY Ed25519** — Detached Ed25519 signatures give tamper-proof offline validation, signed revocation lists, and license files for air-gapped installs. RSA-2048 is acceptable as a fallback if sodium is unavailable, but sodium is the default.

---

## 5. License Lifecycle & Status Model

### 5.1 Status enum
| Status | Meaning |
|---|---|
| 0 — pending | Created/sold but not yet delivered or activated. |
| 1 — active | Valid and usable. Validations pass. |
| 2 — inactive | Delivered, zero active devices, still valid to activate. |
| 3 — expired | Past expires_at (+ grace). Validations fail unless renewed. |
| 4 — suspended | Temporary admin hold. Reversible. Validations fail. |
| 5 — revoked | Permanently killed by admin. Validations fail. Reversible only by explicit reinstate. |
| 6 — terminated | Hard, non-reversible kill switch. Cannot be reactivated. |

### 5.2 State transitions (enforce in LicenseService)
```
pending ─deliver→ inactive ─activate(device)→ active
active ─last device deactivated→ inactive
active ─past expiry(+grace)→ expired
expired ─renew(extend expires_at)→ active
any(except terminated) ─suspend→ suspended ─reinstate→ (previous)
any(except terminated) ─revoke→ revoked ─reinstate→ inactive
any ─terminate→ terminated   (TERMINAL — no exit)
```

### 5.3 Validation algorithm (every /validate call)
1. Look up license by hash(key). Not found → fail `license_not_found`.
2. Check blacklist (key/fingerprint/ip). Hit → fail `blacklisted`, log deny.
3. Check status. Not active/inactive → fail with status reason.
4. Check expiry: if expires_at + grace_days < now → set expired, fail.
5. If fingerprint supplied: confirm an active machine row exists for it; if not and seats available the client may proceed to /activate.
6. Confirm activation_count ≤ max_activations (respect overage_strategy).
7. Log validate success; return signed license payload + device list.

---

## 6. Device / Machine Activation Engine

Implemented in `ActivationService`. This is the heart of the node-lock / floating model. Fingerprints arrive from clients already hashed, or are hashed server-side with `WPLM_FINGERPRINT_HMAC`.

### 6.1 Activation flow
1. Client computes device fingerprint (e.g. SHA-256 of machine GUID) and calls /activate with license key + fingerprint (+ optional name, hostname, components).
2. Service validates license (Section 5.3 steps 1–4).
3. If machine row already exists & active → return it (idempotent), do not consume a new seat.
4. Else compute seat ceiling = max_activations × overage multiplier; if activation_count ≥ ceiling → fail `machine_limit_exceeded`, log limit.
5. Insert machine row (status active), persist components, increment activation_count, set license.activated_at + relative expiry on first activation.
6. Fire `wplm_machine_activated` action + matching webhook. Return signed machine certificate.

### 6.2 Deactivation
- By fingerprint or machine id. Sets machine status = deactivated, decrements activation_count, frees the seat.
- Client SHOULD deactivate on app exit; heartbeat monitor (Section 7) catches crashes.

### 6.3 Floating / concurrent leases
- When license.is_floating = 1, activation grants a lease: machine.lease_expires_at = now + lease_duration.
- Client renews the lease via heartbeat. Expired leases are reclaimed by cron, freeing seats for others.
- Enables "borrow a seat from the pool" — concurrent-user licensing without permanent node-lock.

### 6.4 Overage strategy
| Strategy | Effect |
|---|---|
| deny | Hard limit. activation_count may never exceed max_activations. |
| allow_1_25x | Permit up to 1.25× seats temporarily (license flagged over-limit but functional). |
| allow_2x | Permit up to 2× seats — for cloud rolling deployments where old + new nodes overlap. |

---

## 7. Monitoring, Heartbeats & Telemetry

### 7.1 Heartbeat monitor (HeartbeatService + cron)
- Devices ping GET/POST /machines/{fingerprint}/heartbeat (or /ping) on a configurable interval (default 10 min).
- Each ping updates machine.last_heartbeat_at and renews any floating lease.
- A WP-Cron job (`wplm_cull_zombies`, every 5 min) deactivates machines whose last_heartbeat_at is older than the dead-machine window (default 2× interval), freeing zombie seats.
- Heartbeat misses and culls are written to the activation log.

### 7.2 Telemetry captured per event
- Timestamp, event type, result, license id, machine id, IP (packed), geo country, app version, raw meta JSON.
- IP geolocation optional via a pluggable resolver (filter `wplm_resolve_geo`); never blocks the request path.

### 7.3 Analytics dashboard (AnalyticsService, cached transients)
| Widget | Content |
|---|---|
| Seat utilization | Active devices vs total seats sold across all licenses. |
| Validations / day | Time-series of validate calls (7/30/90 day windows). |
| Status breakdown | Counts by license status (active, expired, revoked, …). |
| Version distribution | Histogram of app_version reported by devices. |
| Geographic spread | Validations grouped by country. |
| Top licenses by activity | Most-validated keys (spot abuse / heavy use). |
| Expiring soon | Licenses expiring in next 7/30 days. |

### 7.4 Anomaly detection flags
- Impossible travel — same license validating from distant geos within an implausible window.
- Fingerprint churn — abnormal rate of new device fingerprints on one license (key sharing).
- Validation spike — sudden surge in validation rate vs the license's baseline.
- Flags surface on the license detail screen and fire `wplm_anomaly_detected` for webhook/automation.

---

## 8. Revocation, Blacklisting & Fraud Controls

### 8.1 Revocation operations (RevocationService)
| Operation | Effect |
|---|---|
| Revoke license | status → revoked. All validations fail immediately. Reversible via reinstate. |
| Reinstate license | revoked/suspended → inactive. Seats preserved. |
| Suspend license | Temporary hold → suspended. Distinct from revoke for billing disputes. |
| Terminate license | Permanent, irreversible kill (status 6). Guarded by a confirm step in UI + API. |
| Revoke device | Single machine → revoked, seat freed, device can't re-activate while license blacklists it. |
| Blacklist fingerprint | Global deny — any license activation from this fingerprint fails. |
| Blacklist IP / email | Global deny lists for fraud sources. |

### 8.2 Signed Certificate Revocation List (CRL)
- Endpoint GET /crl returns an Ed25519-signed JSON list of revoked key hashes + revoked fingerprints.
- Offline clients cache the CRL and refuse cached-valid licenses that appear on it.
- CRL is regenerated on any revoke/terminate and cached as a transient.

> **FRAUD POSTURE** — Revocation, blacklists, and the signed CRL together let a vendor kill a leaked key everywhere — online instantly via /validate, and offline within the client's CRL refresh window.

---

## 9. Native Subscription Engine

*WPLM includes a complete recurring-billing engine so a store can sell subscription-based licenses WITHOUT the paid WooCommerce Subscriptions extension ($279/yr). It manages billing schedules, automatic rebilling via gateway tokens, trials, sign-up fees, dunning, switching, and customer self-service entirely on-site. WooCommerce is used only for the cart, checkout, and payment-token storage.*

> **DESIGN RULE** — Subscriptions are managed on this site, not in the payment gateway. The gateway only sees individual charges; WPLM owns the schedule, status, and lifecycle. If the official WC Subscriptions plugin happens to be active, WcsCompat defers to it instead of double-managing — but it is NEVER required.

### 9.1 Subscription product types
- **Register two WooCommerce product types: subscription (simple) and variable-subscription.**
- Product fields: billing interval + period (every N day/week/month/year), recurring price, optional sign-up fee, optional free-trial length, optional fixed expiry/length, one-per-customer toggle.
- Variable subscriptions let the customer choose a billing schedule (e.g. monthly vs yearly) at purchase; each variation carries its own interval/price.
- A subscription product binds to a license generator + seat rules exactly like any licensed product (Section 12).

### 9.2 Status model
| Subscription Status | License effect |
|---|---|
| pending | Awaiting first payment. License pending. |
| trial | In free-trial window. License active, no charge yet. |
| active | Paid and current. License active. |
| on-hold | Payment failed or manually paused. License suspended. |
| pending-cancel | Cancelled by user but paid through period end. License active until end_date, then revoked. |
| cancelled | Ended. License revoked. |
| expired | Reached fixed end_date. License revoked. |

### 9.3 Billing schedule & synchronization (BillingScheduler)
- Compute next_payment from billing_interval + billing_period anchored to start or trial_end.
- Renewal synchronization — align all renewals to a fixed day (e.g. 1st of month); prorate the first payment for the partial period.
- Sign-up fee charged once on the parent order; trials defer the first recurring charge until trial_end.
- All schedule math runs in site timezone and is stored as UTC DATETIME.

### 9.4 Automatic rebilling (RenewalProcessor — WP-Cron)
1. Cron event `wplm_process_renewals` (hourly) selects subscriptions where next_payment ≤ now and status ∈ {active, trial-ending}.
2. For each, GatewayBridge charges the stored WC payment token (Stripe, etc.) for recurring_total.
3. On success: create a WooCommerce renewal order (paid), write a wplm_subscription_renewals row, advance next_payment, extend the bound license expiry, fire `wplm_subscription_renewed`.
4. On failure: increment failed_attempts and hand off to DunningManager.
5. Manual-renewal subscriptions (no token) instead email a payable renewal invoice through any WooCommerce gateway.

### 9.5 Dunning — failed-payment retry (DunningManager)
- Configurable retry schedule (default: retry on day 1, 3, 5 after failure).
- While retrying, subscription goes on-hold and the license is suspended (grace configurable).
- Successful retry → reactivate subscription + license, clear failed_attempts.
- Exhausting all retries → cancel subscription, revoke license, fire `wplm_subscription_payment_failed_final`.

### 9.6 Upgrades, downgrades & switching (SwitchManager)
- Customer or admin switches a subscription to a different product/variation.
- Proration of the recurring amount, sign-up fee, and remaining length per configurable policy.
- Seat count / license entitlements update to match the new plan immediately.
- Switch is recorded as a wplm_subscription_renewals row of type switch.

### 9.7 Coupons, multiple subscriptions & gifting
- Subscription-aware coupons: recurring-discount (applies every cycle) and sign-up-fee discount (first order only).
- Multiple subscription products in one cart create grouped subscriptions sharing a parent order to reduce gateway fees.
- Optional gifting: purchaser pays, recipient receives the license + subscription (recipient email captured at checkout).

### 9.8 Customer self-service (My Account → Subscriptions)
| Action | Detail |
|---|---|
| View | All subscriptions with status, next payment, amount, bound license + devices. |
| Pause / Resume | Self-pause (on-hold) and resume if store allows. |
| Cancel | Immediate or at-period-end (pending-cancel). |
| Change payment method | Swap the saved WC payment token used for renewals. |
| Upgrade / Downgrade | Switch plans with proration (if enabled). |
| Renew early / Resubscribe | Pay now to extend, or restart an expired subscription. |

### 9.9 Recurring-revenue reporting
- MRR (monthly recurring revenue) and ARR, active-subscriber count, trial count.
- Churn rate, renewals processed, failed/recovered payments, upcoming-revenue forecast.
- Rendered on the Dashboard alongside license analytics; cached as transients.

### 9.10 Subscription emails
- Subscription created, trial ending soon, renewal payment processed (with invoice), payment failed (with retry/update-method link), subscription cancelled, subscription expired.
- All templates live in templates/emails/ and are filterable via `wplm_subscription_email_content`.

> **WHY THIS MATTERS** — This single engine replaces a recurring $279/yr dependency and ships open-source under GPL v2. Recurring licensing — the core monetization model for software vendors — works out of the box.

---

## 10. REST API

Base namespace: `wplm/v1`. Auth: HTTP Basic (consumer_key:consumer_secret), keys hashed in wplm_api_keys with per-key read/write/read_write scope. All write routes require write scope and a permission callback. Responses use a consistent envelope; errors return WP_Error → proper HTTP status.

### Licenses
| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/wplm/v1/licenses` | List licenses (paginate, filter by status/product/order/user) |
| POST | `/wplm/v1/licenses` | Create a license (optionally from a generator) |
| GET | `/wplm/v1/licenses/{key}` | Retrieve one license + its devices |
| PUT | `/wplm/v1/licenses/{key}` | Update writable fields (seats, expiry, status, meta) |
| DELETE | `/wplm/v1/licenses/{key}` | Delete a license record |
| POST | `/wplm/v1/licenses/{key}/renew` | Extend expiry / reset relative validity |
| POST | `/wplm/v1/licenses/{key}/suspend` | Suspend (temporary hold) |
| POST | `/wplm/v1/licenses/{key}/revoke` | Revoke (permanent, reversible) |
| POST | `/wplm/v1/licenses/{key}/reinstate` | Reinstate a suspended/revoked license |
| POST | `/wplm/v1/licenses/{key}/terminate` | Terminate (irreversible — requires confirm flag) |

### Validation & Activation (client-facing)
| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/wplm/v1/validate` | Validate key (+ optional fingerprint). Returns signed payload |
| POST | `/wplm/v1/activate` | Activate a device (key + fingerprint + meta) |
| POST | `/wplm/v1/deactivate` | Deactivate a device (key + fingerprint/machine_id) |
| POST | `/wplm/v1/heartbeat` | Device ping — keep-alive / renew floating lease |
| GET | `/wplm/v1/crl` | Signed certificate revocation list for offline clients |
| GET | `/wplm/v1/public-key` | Ed25519 public key for client-side signature verification |

### Machines / Devices
| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/wplm/v1/licenses/{key}/machines` | List devices bound to a license |
| GET | `/wplm/v1/machines/{id}` | Retrieve a device record + components |
| DELETE | `/wplm/v1/machines/{id}` | Remove/deactivate a device (frees seat) |
| POST | `/wplm/v1/machines/{id}/revoke` | Revoke a single device |

### Generators
| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/wplm/v1/generators` | List generators |
| POST | `/wplm/v1/generators` | Create a generator |
| GET | `/wplm/v1/generators/{id}` | Retrieve a generator |
| PUT | `/wplm/v1/generators/{id}` | Update a generator |
| DELETE | `/wplm/v1/generators/{id}` | Delete a generator |
| POST | `/wplm/v1/generators/{id}/generate` | Bulk-generate N keys into the pool |

### Releases / Updates / Webhooks
| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/wplm/v1/releases` | List software releases |
| POST | `/wplm/v1/releases` | Publish a new version (metadata + file ref) |
| GET | `/wplm/v1/updates/check` | Licensed client checks for a newer version |
| GET | `/wplm/v1/updates/download` | Token-gated download of a release artifact |
| GET | `/wplm/v1/webhooks` | List webhooks |
| POST | `/wplm/v1/webhooks` | Register a webhook (events + target + secret) |
| DELETE | `/wplm/v1/webhooks/{id}` | Delete a webhook |

### Subscriptions (native engine)
| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/wplm/v1/subscriptions` | List subscriptions (filter by status/user/product) |
| POST | `/wplm/v1/subscriptions` | Create a subscription (admin/integration) |
| GET | `/wplm/v1/subscriptions/{id}` | Retrieve a subscription + items + bound license |
| PUT | `/wplm/v1/subscriptions/{id}` | Update schedule / amount / payment method |
| POST | `/wplm/v1/subscriptions/{id}/pause` | Put subscription on-hold (suspends license) |
| POST | `/wplm/v1/subscriptions/{id}/resume` | Reactivate a paused subscription |
| POST | `/wplm/v1/subscriptions/{id}/cancel` | Cancel now or at period end |
| POST | `/wplm/v1/subscriptions/{id}/switch` | Upgrade/downgrade with proration |
| POST | `/wplm/v1/subscriptions/{id}/renew` | Charge a renewal now (early renew) |
| GET | `/wplm/v1/subscriptions/{id}/renewals` | List renewal/switch history |

### 10.1 Response envelope
```json
// Success
{ "success": true, "data": { /* resource */ }, "meta": { "total": 42 } }

// Error (HTTP status mirrors code)
{ "success": false, "code": "machine_limit_exceeded",
  "message": "Activation limit reached for this license.",
  "data": { "status": 422 } }
```

---

## 11. Internal PHP API

Global functions wrap the services for theme/plugin developers. All return typed objects or WP_Error.

### License Functions
| Function | Purpose |
|---|---|
| `wplm_create_license( $args )` | Create & return a License model (signs key, encrypts, stores). |
| `wplm_get_license( $key )` | Fetch a License by key string (or id). |
| `wplm_get_licenses( $args )` | Query licenses with filters; returns array of License. |
| `wplm_update_license( $key, $data )` | Update writable fields. |
| `wplm_delete_license( $key )` | Delete a license. |
| `wplm_validate_license( $key, $fingerprint = null )` | Run full validation; returns result object. |
| `wplm_renew_license( $key, $until )` | Extend expiry. |
| `wplm_revoke_license( $key )` | Revoke a license. |
| `wplm_suspend_license( $key )` | Suspend a license. |
| `wplm_reinstate_license( $key )` | Reinstate a license. |
| `wplm_terminate_license( $key )` | Irreversibly terminate. |

### Device Functions
| Function | Purpose |
|---|---|
| `wplm_activate_device( $key, $fingerprint, $meta = [] )` | Activate a device; returns Machine or WP_Error. |
| `wplm_deactivate_device( $key, $fingerprint )` | Deactivate a device; frees a seat. |
| `wplm_get_devices( $key )` | List Machine rows for a license. |
| `wplm_revoke_device( $machine_id )` | Revoke a single device. |
| `wplm_record_heartbeat( $fingerprint )` | Register a device ping. |

### Subscription Functions
| Function | Purpose |
|---|---|
| `wplm_create_subscription( $args )` | Create a subscription + items; returns Subscription. |
| `wplm_get_subscription( $id )` | Fetch a Subscription with items + bound license. |
| `wplm_get_subscriptions( $args )` | Query subscriptions by status/user/product. |
| `wplm_pause_subscription( $id )` | On-hold the subscription (suspends license). |
| `wplm_resume_subscription( $id )` | Reactivate a paused subscription. |
| `wplm_cancel_subscription( $id, $atPeriodEnd = false )` | Cancel now or at period end. |
| `wplm_switch_subscription( $id, $newProduct )` | Upgrade/downgrade with proration. |
| `wplm_process_renewal( $id )` | Charge a renewal immediately via gateway token. |

### Meta & Utility Functions
| Function | Purpose |
|---|---|
| `wplm_add_license_meta( $id, $key, $val )` | Add meta row. |
| `wplm_get_license_meta( $id, $key )` | Get meta value. |
| `wplm_update_license_meta( $id, $key, $val )` | Update meta. |
| `wplm_delete_license_meta( $id, $key )` | Delete meta. |
| `wplm_blacklist_add( $type, $value, $reason )` | Add to deny list. |
| `wplm_generate_keys( $generator_id, $count )` | Bulk-generate keys. |

### 11.1 Action hooks
| Action | When / Args |
|---|---|
| `wplm_license_created` | After a license is created. Args: License $license. |
| `wplm_license_status_changed` | After any status change. Args: License, $old, $new. |
| `wplm_license_revoked` | After revoke. Args: License. |
| `wplm_license_terminated` | After terminate. Args: License. |
| `wplm_machine_activated` | After device activation. Args: Machine, License. |
| `wplm_machine_deactivated` | After deactivation. Args: Machine, License. |
| `wplm_machine_revoked` | After a device is revoked. Args: Machine. |
| `wplm_heartbeat_received` | On each device ping. Args: Machine. |
| `wplm_zombie_culled` | When cron deactivates a dead machine. Args: Machine. |
| `wplm_anomaly_detected` | When an anomaly flag fires. Args: License, $type, $detail. |
| `wplm_order_licenses_issued` | After WooCommerce order delivers keys. Args: $order_id, License[]. |
| `wplm_subscription_created` | After a subscription is created. Args: Subscription. |
| `wplm_subscription_status_changed` | After status change. Args: Subscription, $old, $new. |
| `wplm_subscription_renewed` | After a successful renewal charge. Args: Subscription, Renewal. |
| `wplm_subscription_payment_failed` | On a failed renewal attempt. Args: Subscription, $attempt. |
| `wplm_subscription_payment_failed_final` | After dunning exhausts retries. Args: Subscription. |
| `wplm_subscription_switched` | After an upgrade/downgrade. Args: Subscription, $from, $to. |
| `wplm_subscription_cancelled` | After cancellation. Args: Subscription. |

### 11.2 Filter hooks
| Filter | Purpose |
|---|---|
| `wplm_generated_key_string` | Modify a freshly generated key string before save. |
| `wplm_validation_response` | Modify the /validate response payload. |
| `wplm_rest_request_valid` | Inject custom REST request validation. |
| `wplm_default_max_activations` | Override default seat count. |
| `wplm_heartbeat_interval` | Override heartbeat interval (seconds). |
| `wplm_zombie_window` | Override dead-machine cull window (seconds). |
| `wplm_resolve_geo` | Provide IP→country resolution. |
| `wplm_list_table_columns` | Add/modify license list-table columns. |
| `wplm_email_license_keys` | Customize the delivery email content. |
| `wplm_subscription_next_payment` | Override the computed next-payment date. |
| `wplm_dunning_retry_schedule` | Customize failed-payment retry offsets (days). |
| `wplm_switch_proration` | Customize proration math on plan switch. |
| `wplm_subscription_email_content` | Customize any subscription email body. |

---

## 12. Admin UI & WooCommerce Integration

### 12.1 Admin menu tree
```
License Manager (top-level menu)
├── Dashboard          # license + recurring-revenue analytics, anomaly flags
├── Licenses           # WP_List_Table: search, filter, bulk revoke/suspend/export
│     └── Add / Edit   # all fields + device list + per-license activation log
├── Subscriptions      # WP_List_Table: status, next payment, MRR; edit screen
│     └── Edit         # change schedule/amount/method, pause/cancel, renewal log
├── Devices            # global device browser, filter by license/status, revoke
├── Generators         # CRUD + bulk-generate UI
├── Releases           # software versions, changelog, file upload
├── Webhooks           # endpoints + delivery log
├── Blacklist          # fingerprint / IP / email deny lists
└── Settings           # tabbed (below)
```

### 12.2 Settings tabs
| Tab | Contents |
|---|---|
| General | My-account endpoint slug, hide-key toggle, auto-complete orders, default seat count. |
| Security | Force SSL, restrict REST to admins, fingerprint anonymization toggle. |
| Activation | Default overage strategy, floating lease duration, allow client-side activation. |
| Monitoring | Heartbeat interval, zombie cull window, telemetry retention days, geo resolver. |
| WooCommerce | Order status that triggers delivery, stock behavior, per-product defaults. |
| Subscriptions | Enable engine, allowed customer actions (pause/cancel), sync day, proration policy, manual-renewal toggle. |
| Dunning | Failed-payment retry schedule, on-hold grace, final-action (cancel/revoke). |
| Releases | Update channel defaults, download token TTL. |
| QR Codes | Enable, size, error-correction level. |
| Webhooks | Global signing secret, retry attempts, backoff. |
| REST API | Generate/revoke consumer keys with scope. |
| Tools | CSV import/export, CRL rebuild, log purge, keypair re-roll (danger zone). |

### 12.3 WooCommerce integration
- Product data panel (simple + variable + subscription + variable-subscription): enable licensing, source (pool vs generator), generator select, seats override, valid-for override, per-variation config, and — for subscription types — billing interval/period, sign-up fee, trial, length.
- Checkout handler: a subscription product creates a wplm_subscriptions row, stores the customer's payment token for rebilling, and issues the bound license on first payment.
- Order completion hook (configurable trigger status) auto-issues keys, links order/product/user, marks delivered, emails keys + QR.
- Order screen meta box lists issued keys with quick revoke + resend; subscription orders link to the subscription record.
- My Account → Licenses: customer views keys, sees devices, self-deactivates seats, downloads updates.
- My Account → Subscriptions: customer pauses/resumes/cancels, changes payment method, upgrades/downgrades, renews early.
- Optional WcsCompat: if the paid WC Subscriptions plugin is active, defer to it rather than double-manage — WPLM's engine is otherwise fully standalone.

### 12.4 WooCommerce software-product rules
These rules govern exactly how a licensed/software product behaves through the cart, checkout, and post-purchase lifecycle. Claude Code must enforce every rule below.

**Product Configuration Rules**
| Rule | Detail |
|---|---|
| Licensing toggle | Each product (and each variation) carries an 'is licensed' flag. Only flagged items issue keys. |
| Key source | Per product: draw from an existing key pool OR generate on-the-fly from a chosen generator. If pool is empty and no generator is set, block purchase with a clear stock error. |
| Seats per product | max_activations override at product/variation level; falls back to generator default, then global default. |
| Validity | valid_for_days and/or absolute expiry override per product; subscription products derive expiry from the billing period instead. |
| Delivery type | Virtual + downloadable software handled natively — license key and (optional) signed download link both delivered. |
| Quantity → keys | Buying quantity N of a licensed product issues N distinct keys unless the product is marked 'single key, N seats'. |
| One-per-customer | Optional flag prevents a customer from holding more than one active license/subscription for the product. |

**Subscription-Product Rules**
| Rule | Detail |
|---|---|
| Product types | subscription (fixed schedule) and variable-subscription (customer picks schedule at purchase). |
| Billing fields | interval + period (every N day/week/month/year), recurring price, optional sign-up fee, optional trial length, optional fixed length/expiry. |
| License binding | A subscription product binds to a generator + seat rules; the issued license's lifecycle follows the subscription status (Section 9.2). |
| First order | Sign-up fee (if any) charged on the parent order; license issued on first successful payment; trial defers first recurring charge. |
| Mixed cart | A cart may mix one-time licensed products and subscription products; each is processed by its own path in one checkout. |
| Switching | Upgrading/downgrading the subscription product re-issues or re-scopes the bound license entitlements with proration. |
| Renewal orders | Each successful renewal creates a WooCommerce renewal order linked to the subscription for accounting + receipts. |

> **ORDER-STATUS MAPPING** — Order paid/completed → issue + deliver license. Order refunded → suspend or revoke bound license (configurable). Subscription renewal paid → extend license expiry. Subscription on-hold/cancelled/expired → suspend/revoke per Section 9.2. All transitions fire the matching action hook + webhook.

---

## 13. Documentation Website & Per-Phase Docs

*Documentation is a first-class deliverable, not an afterthought. WPLM ships a complete documentation website inside the plugin folder at /docs-site/, built with Astro Starlight. Every build phase (Section 15) produces its own documentation as it is completed, so the docs grow in lockstep with the code and are finished the moment the plugin is.*

### 13.1 Boilerplate & stack
| Item | Detail |
|---|---|
| Generator | Astro Starlight — the 2026 standard for OSS docs; fastest builds, near-zero JS shipped. |
| Why Starlight | Outputs a plain static site (HTML/CSS) → drop the dist/ folder on any subdomain. No server/runtime needed. |
| Content format | Markdown / MDX in docs-site/src/content/docs/. |
| Built-in features | Sidebar nav, full-text search (Pagefind), dark mode, mobile layout, i18n, code highlighting, edit-on-GitHub links. |
| Scaffold command | `npm create astro@latest -- --template starlight` (run once inside the plugin folder, naming the dir docs-site). |
| Build command | `cd docs-site && npm install && npm run build` → static output in docs-site/dist/. |
| Deploy | Upload docs-site/dist/ to the docs subdomain (e.g. docs.yourdomain.com) — static hosting, no PHP. |

### 13.2 Required site structure
Configure the Starlight sidebar in astro.config.mjs to match these top-level groups. Each group maps to real plugin capabilities so the published site is a usable product manual + developer reference.

| Section | Content |
|---|---|
| Overview | What WPLM is, feature inventory, architecture at a glance, screenshots. |
| Getting Started | Requirements, install, activation, generating the first license, REST API key setup. |
| User Guides | Licenses, generators, devices/seats, monitoring, revocation/blacklist, releases/updates, QR, webhooks. |
| Subscriptions | Creating subscription products, billing schedules, trials/fees, dunning, switching, customer self-service. |
| WooCommerce | Licensed-product setup, software-product rules, order delivery, My Account portal. |
| REST API | Auth, every endpoint with request/response examples (mirrors Section 10), error codes. |
| PHP / Developer API | Functions, action hooks, filter hooks (mirrors Section 11), code recipes. |
| Client Integration | How vendor software calls validate/activate/heartbeat; offline verification with the public key + CRL; sample snippets. |
| Reference | Database schema, license/subscription status codes, error codes, settings glossary. |
| Build Log | One page per build phase (Section 15) — what was built, key files, decisions, how to test. |

> **PER-PHASE RULE** — At the END of every build phase in Section 15, before moving to the next phase, write that phase's documentation: (1) add/extend the relevant user/developer guide pages, and (2) add a Build Log page summarizing what was implemented, the files touched, public APIs added, and how to verify it. A phase is not 'done' until its docs are written and the docs site still builds (npm run build passes).

### 13.3 Build Log page template
Each docs-site/src/content/docs/build-log/phase-NN.md follows this template so the log is consistent:

```markdown
---
title: "Phase 03 — Cryptography Layer"
description: "Ed25519 signing, AES-256-GCM vault, fingerprint hashing."
---

## Goal
What this phase delivers in one or two sentences.

## What was built
- Classes/files added (e.g. src/Crypto/Signer.php)
- Public APIs / functions / hooks introduced
- DB tables or settings touched

## How it works
Short explanation + a code example where useful.

## How to test
Commands or steps to verify (phpunit filter, REST call, admin action).

## Acceptance criteria covered
Which acceptance-matrix test rows this phase satisfies.
```

---

## 14. Client SDK Suite

*The WordPress plugin is the licensing SERVER. Vendor software needs CLIENT SDKs to talk to it — embeddable libraries that wrap the REST API, compute device fingerprints, verify Ed25519 signatures offline, cache the CRL, and expose a clean idiomatic API in each language. Without SDKs, every customer re-implements HTTP + crypto by hand. WPLM ships a suite of official open-source SDKs, all built against one shared contract.*

> **WHERE THEY LIVE** — SDKs are separate open-source repositories (one per language), NOT inside the plugin folder — client code does not belong in a server plugin. The plugin repo links to them; each SDK has its own package-manager release (Packagist, pub.dev, npm, PyPI, etc.). The docs-site Client Integration section documents all of them.

### 14.1 Shared SDK contract
Every SDK — regardless of language — implements the same capability set so behavior is identical everywhere. This contract is the single source of truth; language SDKs are thin idiomatic wrappers over it.

| Capability | Behavior |
|---|---|
| configure | Set server base URL + product/license key (and optional API key for management calls). |
| validate | POST /validate — returns status, expiry, seats, signed payload. |
| activate | POST /activate — binds the current device (auto-computes fingerprint). |
| deactivate | POST /deactivate — releases the current device's seat. |
| heartbeat | POST /heartbeat — keep-alive ping; renews floating lease. |
| fingerprint | Compute a stable per-device fingerprint (platform-appropriate sources). |
| verifySignature | Verify the Ed25519 signature on a license payload using the bundled public key — works fully offline. |
| checkCRL | Fetch/cache the signed revocation list; refuse revoked keys offline. |
| checkUpdate / download | Query /updates/check and token-download a release artifact (where applicable). |
| error model | Typed errors: NotFound, Expired, Suspended, Revoked, LimitExceeded, Blacklisted, NetworkError, SignatureInvalid. |

### 14.2 Cross-cutting requirements
- **Offline-first** — validate must work from a cached signed payload + CRL when the network is down, within a configurable max-clock-drift / cache-TTL window.
- Replay/clock-tamper protection — reject responses whose signed timestamp drifts beyond the allowed window (default 5 min), mirroring Keygen's MaxClockDrift.
- No secrets in clients — SDKs embed only the PUBLIC key + product id + server URL. The signing private key and API consumer secrets never ship in client software.
- Pluggable HTTP + retries — allow a custom HTTP client for retries, proxies, and tests.
- Pluggable fingerprint source — sensible per-platform default, overridable (e.g. random UUID for cloud nodes that share hardware).
- Semantic-versioned, package-manager published, MIT/GPL-compatible licensed, with a README quickstart + example app each.

### 14.3 SDK roadmap (priority order)
Build in this order — the first three cover the stated targets (WordPress plugins, Flutter, and general software). The universal REST reference + OpenAPI spec means any other language can be generated or hand-rolled quickly.

| SDK | Notes |
|---|---|
| 1. PHP SDK | For WordPress plugins/themes & PHP apps. Composer package. Includes a drop-in WP helper: license-settings admin field, transient-cached validation, plugin-update integration via the Update API. THE priority for the ecosystem. |
| 2. Dart / Flutter SDK | pub.dev package. Works on Android, iOS, Windows, macOS, Linux, web. Platform fingerprint via device_info_plus; secure cache via flutter_secure_storage. |
| 3. JavaScript / TypeScript SDK | npm package for Node.js (Electron desktop apps, CLIs, servers) and browser. |
| 4. Python SDK | PyPI package for desktop (PyQt apps), scripts, and servers. Fingerprint via a py-machineid-style helper. |
| 5. Universal REST + OpenAPI | A published OpenAPI 3.1 spec for the whole API so SDKs for Go, Rust, C#, Java, Swift, Kotlin, C++ can be auto-generated with openapi-generator. Ships a curl/bash recipe set too. |

### 14.4 Idiomatic usage examples
Each SDK exposes the same flow. Illustrative target ergonomics:

```php
// ---- PHP (WordPress plugin) ----
$wplm = new WPLM\Client([
  'url'        => 'https://license.vendor.com',
  'product_id' => 42,
  'license_key'=> $user_entered_key,
]);
$result = $wplm->activate();      // binds this site as a device
if ($result->valid) { /* unlock pro features */ }
```

```dart
// ---- Dart / Flutter ----
final wplm = WplmClient(url: '...', productId: 42, licenseKey: key);
final res = await wplm.validate();
if (res.status == LicenseStatus.active) { unlock(); }
await wplm.heartbeat();           // keep floating lease alive
```

```javascript
// ---- JS / TypeScript (Electron) ----
const wplm = new WplmClient({ url, productId: 42, licenseKey });
const res = await wplm.activate();
wplm.on('revoked', () => app.lockdown());
```

```python
# ---- Python (PyQt desktop) ----
wplm = WplmClient(url='...', product_id=42, license_key=key)
res = wplm.validate(offline_ok=True)  # uses cached signed payload + CRL
if res.active: unlock()
```

### 14.5 WordPress plugin-licensing helper (PHP SDK)
Because licensing other WordPress plugins is a core use case, the PHP SDK ships a turnkey WP module so a plugin author adds licensing in a few lines:
- Drop-in Settings field + 'Activate / Deactivate license' button for the author's plugin admin page.
- Transient-cached periodic re-validation (configurable interval) so every page load isn't a network call.
- Hooks into the WordPress plugin update flow so licensed plugins receive updates through WPLM's Update API (pre_set_site_transient_update_plugins / plugins_api), gated by license status.
- Graceful degraded mode: network down → fall back to last cached signed payload until TTL expires; revoked via CRL → lock immediately.
- Copy-paste bootstrap snippet in the docs so an author wires it without reading the whole SDK.

> **WHY THIS ANSWERS THE NEED** — WordPress plugins use the PHP SDK + WP helper; Flutter apps use the Dart SDK; Electron/Node, Python desktop, and everything else are covered by their SDKs or the OpenAPI-generated clients. All speak the one shared contract (14.1) against the same server.

---

## 15. Build Order for Claude Code

Implement strictly in this sequence. Each step compiles and is testable before the next. Run phpcs (WordPress standard) and the relevant tests after every step. **CRITICAL: every step ends by writing that phase's documentation in /docs-site/ (guide pages + a Build Log page per Section 13) and confirming the docs site still builds before continuing.**

1. **Bootstrap & scaffolding** — wp-license-manager.php headers, constants, Composer PSR-4 autoload, Plugin singleton, Container, activation/deactivation hooks. Plugin activates cleanly on a WP install. ALSO: scaffold /docs-site/ with Astro Starlight, configure the sidebar groups (Section 13.2), write the Overview + Getting-Started skeleton, add Build Log phase-01. Confirm npm run build passes.
2. **Installer & schema** — Installer.php creates all 14 tables via dbDelta; store wplm_db_version; Migrator stub. Verify tables exist with correct columns. DOCS: Reference → Database Schema page (all 14 tables) + Build Log phase-02.
3. **Crypto layer** — Seeder generates Ed25519 keypair + AES + HMAC secrets on activation; implement KeyVault, Signer, Fingerprint with unit tests (encrypt/decrypt round-trip, sign/verify). DOCS: Client Integration → offline verification + public key; Build Log phase-03.
4. **Models + Repositories** — all model classes and repository CRUD using $wpdb->prepare(); unit-test License & Machine repos. DOCS: Reference → data models; Build Log phase-04.
5. **GeneratorService** — key string generation honoring charset/chunks/sep/prefix/suffix + wplm_generate_keys(); test uniqueness and format. DOCS: User Guides → Generators; Build Log phase-05.
6. **LicenseService** — create (sign+encrypt+store), validate algorithm (Section 5.3), renew, status transitions; full unit coverage of the status machine. DOCS: User Guides → Licenses + Reference → status codes; Build Log phase-06.
7. **ActivationService** — activate/deactivate, seat accounting, overage multipliers, floating leases (Section 6). Test limit enforcement and idempotent re-activation. DOCS: User Guides → Devices/Seats; Build Log phase-07.
8. **RevocationService** — revoke/suspend/reinstate/terminate, device revoke, blacklist, signed CRL builder. Test that revoked keys fail validation and appear on CRL. DOCS: User Guides → Revocation/Blacklist + Client Integration → CRL; Build Log phase-08.
9. **HeartbeatService + Cron** — heartbeat ingest, lease renewal, zombie cull job. Test that stale machines are culled and seats freed. DOCS: User Guides → Monitoring/Heartbeats; Build Log phase-09.
10. **Subscription engine** — Subscription/Renewal models + repos, SubscriptionService lifecycle, BillingScheduler dates/sync/proration, RenewalProcessor cron + GatewayBridge token charge, DunningManager retries, SwitchManager proration (Section 9). Test full cycle: create → renew → fail → dunning → cancel, and that license status tracks subscription status. DOCS: full Subscriptions section; Build Log phase-10.
11. **REST layer** — RestServer, BasicAuth middleware, all controllers/endpoints incl. subscriptions (Section 10), response envelope, error mapping. Test each route with auth + scope. DOCS: complete REST API section with request/response examples; Build Log phase-11.
12. **Internal PHP API** — expose all wplm_*() functions incl. subscription fns; wire every action/filter at the correct points (Section 11). Test hooks fire with correct args. DOCS: PHP/Developer API section + recipes; Build Log phase-12.
13. **AnalyticsService + Dashboard** — license aggregates + recurring-revenue metrics (MRR/churn/forecast) with transient caching, anomaly flags, dashboard widgets. DOCS: User Guides → Dashboard/Analytics; Build Log phase-13.
14. **Admin UI** — menu tree, list tables (Licenses, Subscriptions, Devices, Generators, Releases, Webhooks, Blacklist), add/edit screens, settings tabs. DOCS: User Guides screenshots + Reference → settings glossary; Build Log phase-14.
15. **WooCommerce integration** — licensed product panel incl. subscription product types, checkout handler that creates subscriptions + stores payment tokens, order delivery hook, order meta box, My Account licenses + subscriptions portal, email + QR templates. DOCS: WooCommerce section; Build Log phase-15.
16. **Releases/Updates + Webhooks dispatch + optional WcsCompat** — release publishing, update check/download, webhook delivery + retry log, defer to WC Subscriptions only if present. DOCS: User Guides → Releases/Updates + Webhooks; Build Log phase-16.
17. **i18n, uninstall, README/CHANGELOG** — wrap all strings in __(), generate .pot, implement guarded uninstall.php, write docs and inline DocBlocks. DOCS: finalize Getting-Started + Overview; Build Log phase-17.
18. **Hardening + docs finalization (SERVER COMPLETE)** — run full test matrix (Section 16), phpcs zero errors, security review against Section 17, performance (indexes, caching). Final docs pass: proofread all pages, verify every REST/PHP example runs, build the docs site (npm run build) and confirm dist/ is ready to upload to the docs subdomain. Build Log phase-18.
19. **OpenAPI spec + SDK contract** — publish the OpenAPI 3.1 description of the whole REST API and write the shared SDK contract (Section 14.1) as a conformance checklist every SDK is tested against. DOCS: Client Integration → API spec; Build Log phase-19.
20. **PHP SDK + WordPress helper** — Composer package implementing the full contract, plus the turnkey WP licensing helper (settings field, cached re-validation, plugin-update integration). Ship with an example plugin. DOCS: Client Integration → PHP/WordPress; Build Log phase-20.
21. **Dart / Flutter SDK** — pub.dev package implementing the contract; per-platform fingerprint + secure cache; offline verification. Ship with an example Flutter app. DOCS: Client Integration → Flutter; Build Log phase-21.
22. **JS/TS + Python SDKs** — npm and PyPI packages implementing the contract, each with an example app (Electron + PyQt). DOCS: Client Integration → JS & Python; Build Log phase-22.
23. **SDK conformance + release** — run the shared-contract conformance suite against every SDK (online + offline + revoked paths), tag semver releases, publish to each package manager, finalize Client Integration docs. Build Log phase-23.

---

## 16. Acceptance Criteria & Test Matrix

The build is complete when every row passes. Write automated tests (PHPUnit + WP test suite) wherever marked.

| Test | Pass condition |
|---|---|
| Activation clean | Plugin activates with no PHP notices; all 14 tables created; secrets generated. |
| Key issue + sign | wplm_create_license() returns a key whose signature verifies with the public key. |
| Encrypt at rest | Stored license_key column is ciphertext; plaintext never persisted. |
| Validate states | Validation returns correct result for active/expired/suspended/revoked/terminated. |
| Seat limit | Activating beyond max_activations fails machine_limit_exceeded (deny strategy). |
| Overage | allow_2x permits up to 2× seats; deny does not. |
| Idempotent activate | Re-activating an existing device does not consume a new seat. |
| Deactivate frees seat | Deactivation decrements activation_count and frees capacity. |
| Floating lease reclaim | Expired lease is reclaimed by cron; seat becomes available. |
| Zombie cull | Machine with stale heartbeat is auto-deactivated within the window. |
| Revoke kills key | Revoked license fails all validations and appears in /crl (signed). |
| Device revoke | Revoking one device frees its seat; other devices unaffected. |
| Blacklist | Blacklisted fingerprint/IP is denied activation and logged. |
| REST auth | Endpoints reject missing/invalid keys; write scope enforced on writes. |
| Woo delivery | Completing an order issues + emails keys and links order/product/user. |
| Sub create | Buying a subscription product creates a subscription, stores a payment token, issues the license on first payment. |
| Sub renewal | Cron charges a due renewal via the token, creates a renewal order, and extends the bound license expiry. |
| Sub trial + fee | Free trial defers first charge to trial_end; sign-up fee is charged once on the parent order. |
| Dunning | Failed renewal triggers retries on schedule; subscription on-hold + license suspended; exhausting retries cancels + revokes. |
| Sub status → license | on-hold suspends, cancelled/expired revokes, resume reactivates the bound license. |
| Sub switch | Upgrade/downgrade prorates correctly and updates seat entitlements immediately. |
| Sub self-service | Customer can pause, resume, cancel, change payment method, and renew early from My Account. |
| MRR report | Dashboard shows MRR, active subscribers, churn, and upcoming-revenue forecast. |
| Update gating | Only valid licenses can check/download updates via token. |
| Webhook delivery | Subscribed license/device/subscription events POST a signed payload; failures retry and log. |
| Analytics | Dashboard shows seat utilization, validations/day, version + geo spread. |
| i18n | All user-facing strings translatable; .pot generated. |
| Per-phase docs | Every build phase has a Build Log page + updated guides written when the phase completed. |
| Docs site builds | cd docs-site && npm run build succeeds; dist/ is a complete static site ready for the docs subdomain. |
| Docs coverage | Every REST endpoint and PHP function/hook is documented with a working example. |
| SDK contract | Every SDK passes the shared-contract conformance suite (validate/activate/deactivate/heartbeat). |
| SDK offline | Each SDK validates offline from a cached signed payload + CRL and rejects a revoked key offline. |
| SDK no secrets | No SDK ships the signing private key or any API consumer secret — public key only. |
| WP helper | The PHP SDK's WordPress helper activates a license and gates plugin updates by license status in an example plugin. |
| Flutter SDK | The Dart SDK activates + heartbeats from an example Flutter app across at least two platforms. |
| phpcs | Zero WordPress-Coding-Standards errors. |

---

## 17. Coding Standards & Security Rules

### 17.1 Standards
- WordPress Coding Standards (WPCS) — enforce via phpcs.xml; CI fails on errors.
- PSR-4 autoloading; one class per file; namespaced under WPLM\.
- Type-hint everything; declare(strict_types=1) where practical; PHPDoc on all public methods.
- No direct file access — every PHP file starts with `defined('ABSPATH') || exit;`.

### 17.2 Security (non-negotiable)
| Area | Rule |
|---|---|
| SQL | 100% $wpdb->prepare() — zero string interpolation into queries. |
| Output | esc_html / esc_attr / esc_url on all output; wp_kses for rich text. |
| Input | sanitize_* + wp_unslash on all input; validate types before use. |
| CSRF | wp_nonce_field / check_admin_referer on every admin form + AJAX. |
| AuthZ (admin) | current_user_can('manage_options' or a custom wplm_manage cap) on all admin actions. |
| AuthZ (REST) | Permission callback on every route; read vs write scope from the API key. |
| Secrets | Signing private key + encryption key stored unautoloaded; never returned by any endpoint (only the public key is exposed). |
| Crypto | sodium (Ed25519, AES-256-GCM, HMAC-SHA256) — never roll custom crypto. |
| Fingerprints | HMAC-anonymized before storage; treat as pseudonymous personal data. |
| Downloads | Release artifacts served via short-lived signed tokens; never a public path. |
| Webhooks | HTTPS only; HMAC-sign payloads; verify TLS on delivery. |
| Rate limiting | Throttle validate/activate per IP+key; log and flag spikes. |

> **DEFINITION OF DONE** — All Section 16 acceptance tests pass, phpcs reports zero errors, every Section 17 security rule is satisfied, the documentation site in /docs-site/ builds and covers every feature/endpoint/hook, the client SDKs (PHP+WP helper, Dart/Flutter, JS/TS, Python) pass the shared-contract conformance suite and are published, and the plugin installs/uninstalls cleanly on a fresh WordPress + WooCommerce install. Ship as GPL v2 with README, CHANGELOG, a generated .pot, built docs ready for the docs subdomain, and the OpenAPI spec.
