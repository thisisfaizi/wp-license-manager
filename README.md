<div align="center">

<img src="https://capsule-render.vercel.app/api?type=waving&color=gradient&customColorList=6,11,20&height=200&section=header&text=WP+License+Manager&fontSize=60&fontAlignY=40&animation=fadeIn&fontColor=ffffff" alt="WP License Manager" />

[![Typing SVG](https://readme-typing-svg.demolab.com?font=Fira+Code&weight=700&size=22&pause=1200&color=6366F1&center=true&vCenter=true&width=740&lines=Ed25519-signed+licensing+for+WordPress;Native+subscriptions%2C+no+paid+add-ons+required;Offline+verification%2C+no+server+round+trip;39+REST+routes%2C+54+endpoints;Self-service+renewal+from+My+Account)](https://github.com/DenverCoder1/readme-typing-svg)

</div>

# WP License Manager

**WP License Manager (WPLM)** is a self-hosted software license server and license key manager for WordPress plugins, themes and apps. It issues **Ed25519-signed license keys** that your software checks with **offline verification**, sells them through **WooCommerce**, and handles device seats, native subscriptions and a REST API from one WordPress plugin. It is an open-source alternative to Freemius and EDD Software Licensing.

> Not to be confused with the older WordPress.org plugin of the same name.

<div align="center">

[![Release](https://img.shields.io/github/v/release/thisisfaizi/wp-license-manager?style=for-the-badge&color=6366F1&label=release)](https://github.com/thisisfaizi/wp-license-manager/releases/latest)
[![Status](https://img.shields.io/badge/status-active%20development-F59E0B?style=for-the-badge)](#-status)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net)
[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759B?style=for-the-badge&logo=wordpress&logoColor=white)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0%2B-96588A?style=for-the-badge&logo=woocommerce&logoColor=white)](https://woocommerce.com)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green?style=for-the-badge)](LICENSE)
[![Last commit](https://img.shields.io/github/last-commit/thisisfaizi/wp-license-manager?style=for-the-badge&color=0EA5E9)](https://github.com/thisisfaizi/wp-license-manager/commits/main)

</div>

<br />

<div align="center">
<strong>Self-hosted, cryptographically signed software licensing as a WordPress plugin.</strong><br />
Sell licenses through WooCommerce, verify them offline in milliseconds, and manage every seat from one admin dashboard.
<br /><br />

[Status](#-status) &nbsp;·&nbsp; [Features](#-features) &nbsp;·&nbsp; [Compare](#-how-it-compares) &nbsp;·&nbsp; [Install](#-installation) &nbsp;·&nbsp; [Quick start](#-quick-start) &nbsp;·&nbsp; [SDKs](#-client-sdks) &nbsp;·&nbsp; [Docs](#-documentation) &nbsp;·&nbsp; [Contributing](#-contributing)

</div>

---

## 🚦 Status

WPLM is in **active development**. It is not on WordPress.org yet: install it from the [latest release](https://github.com/thisisfaizi/wp-license-manager/releases/latest) ZIP.

| Area | Where it stands |
|---|---|
| Plugin | **1.3.2**: deleting the plugin keeps your data unless you tick *Delete all data* in Settings. Full history in [CHANGELOG.md](CHANGELOG.md). |
| Compatibility | PHP 8.0+, WordPress 5.8+ (tested up to 6.5), WooCommerce 6.0+ (tested up to 9.0) |
| Releases | [Latest release](https://github.com/thisisfaizi/wp-license-manager/releases/latest) on GitHub, with an installable plugin ZIP (see [Installation](#-installation)). |
| Tests | 28 integration test classes that run against a real WordPress, WooCommerce and MySQL ([tests/README.md](tests/README.md)) |
| CI | Not set up yet. Lint and tests run locally before each merge. |
| Client SDKs | Dart, Python, PHP and JavaScript at **0.2.0** on GitHub. Not published to pub.dev, PyPI, Packagist or npm yet. |

> [!WARNING]
> **Known limitation**
> - An add-on bought while a renewal invoice is unpaid is not on that invoice, yet paying the invoice extends it with the rest (one period not charged). See the 1.3.0 notes in [CHANGELOG.md](CHANGELOG.md).

---

## ✨ Features

<table>
<tr>
<td width="50%" valign="top">

### 🔐 Cryptographic Security
- **Ed25519 signatures** (libsodium): clients verify offline without a server call
- **XChaCha20-Poly1305** encryption of license keys at rest (older AES-256-GCM data still decrypts)
- **Signed revocation list (CRL)**: offline clients drop a revoked key on their next CRL fetch
- **Issued-at timestamp** (`iat`) in every signed payload
- **Product binding**: a signed `pid` stops a key from working in another product
- **HMAC-SHA256** device fingerprints

</td>
<td width="50%" valign="top">

### 🖥️ Device & Seat Management
- Hardware fingerprint binding per machine
- Seat limits with overage strategies (deny / 1.25× / 2×)
- Per-device revocation and admin deactivation
- Floating licenses with heartbeat expiry
- Anomaly detection (impossible travel, fingerprint churn)
- Blacklist by key, email, IP or fingerprint

</td>
</tr>
<tr>
<td width="50%" valign="top">

### 💳 Native Subscriptions
- Recurring billing by day, week, month or year
- Dunning with a configurable retry schedule (default 1, 3, 5 days)
- Prorated upgrades and downgrades
- Self-service pause, resume, cancel and **renew** from My Account
- Add-ons that join an existing license, on one bill
- **No WooCommerce Subscriptions add-on required**

</td>
<td width="50%" valign="top">

### 🛒 WooCommerce Integration
- Product panel: assign a license plan to any WooCommerce product
- Licenses issued when an order is completed, key emailed with the order
- Plans built at purchase, with package extras on the product page
- My Account → Subscriptions and Licenses portals
- Self-service renewal: one click → checkout → expiry extended
- Subscription emails (created / renewed / payment failed / cancelled)

</td>
</tr>
<tr>
<td width="50%" valign="top">

### 🌐 REST API & Webhooks
- 39 routes (54 route and method pairs) under `wp-json/wplm/v1`: licenses, devices, generators, subscriptions, releases, webhooks, analytics, API keys
- WPLM API keys (`ck_…` / `cs_…`) over HTTP Basic auth, created under **License Manager → API Keys**, scoped `read`, `write` or `read_write`, stored as SHA-256 hashes, valid only on `wplm/v1`
- Public client endpoints: validate, activate, deactivate, heartbeat, CRL, public key, update check and download
- Webhooks signed with HMAC-SHA256, with retries, plus Slack, Discord and email presets

</td>
<td width="50%" valign="top">

### 📦 Client SDKs
- **[wplm-dart](https://github.com/thisisfaizi/wplm-dart)**: Dart / Flutter
- **[wplm-python](https://github.com/thisisfaizi/wplm-python)**: Python
- **[wplm-php](https://github.com/thisisfaizi/wplm-php)**: PHP, with a WordPress licensing and auto-update helper
- **[wplm-js](https://github.com/thisisfaizi/wplm-js)**: JavaScript / TypeScript
- All verify signatures offline and fall back to cache on network loss
- Install from GitHub for now (see [Client SDKs](#-client-sdks))

</td>
</tr>
</table>

---

## 🆚 How it compares

| | Type | Hosting | Pricing model | Store |
|---|---|---|---|---|
| **WP License Manager** | Open-source WordPress plugin (GPL-2.0-or-later) | Self-hosted | Free | WooCommerce (optional) |
| **Freemius** | Hosted SaaS platform | Hosted | Revenue share | Freemius platform |
| **EDD Software Licensing** | Paid add-on for Easy Digital Downloads | Self-hosted | Paid add-on | Easy Digital Downloads |
| **License Manager for WooCommerce** | Free WooCommerce plugin for selling license keys | Self-hosted | Free plugin | WooCommerce |

WPLM in one plugin: Ed25519-signed license payloads with offline validation, device seats, native subscriptions, a REST API under `wp-json/wplm/v1`, and client SDKs for Dart, Python, PHP and JavaScript.

<sub>Product names are trademarks of their owners; listed for comparison only.</sub>

---

## 🏗️ Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                       WordPress + WooCommerce                       │
│                                                                     │
│  ┌──────────────┐   ┌────────────────────┐   ┌──────────────────┐   │
│  │  Admin Panel │   │  WooCommerce Store │   │   My Account     │   │
│  │  (WPLM menu) │   │  (Product / Cart / │   │  (Subscriptions /│   │
│  │              │   │   Checkout)        │   │   Licenses)      │   │
│  └──────┬───────┘   └────────┬───────────┘   └───────┬──────────┘   │
│         │                    │                       │              │
│         └────────────────────▼───────────────────────▼              │
│                       ┌─────────────────┐                           │
│                       │  WPLM Core      │                           │
│                       │  LicenseService │                           │
│                       │  Subscriptions  │                           │
│                       │  ActivationSvc  │                           │
│                       │  RevocationSvc  │                           │
│                       └────────┬────────┘                           │
│                                │                                    │
│                       ┌────────▼────────┐                           │
│                       │   REST API      │  wp-json/wplm/v1          │
│                       │   39 routes     │                           │
│                       └────────┬────────┘                           │
└────────────────────────────────│────────────────────────────────────┘
                                 │  HTTPS
     ┌──────────────┬────────────┴───────────┬──────────────┐
     │              │                        │              │
┌────▼─────┐   ┌────▼─────┐             ┌────▼─────┐   ┌────▼─────┐
│ Flutter  │   │  Python  │             │   PHP /  │   │  Node /  │
│  (Dart)  │   │  Desktop │             │ WordPress│   │ Electron │
│wplm-dart │   │wplm-     │             │ wplm-php │   │ wplm-js  │
│          │   │python    │             │          │   │          │
└────┬─────┘   └────┬─────┘             └────┬─────┘   └────┬─────┘
     │              │                        │              │
     └──────────────┴────────────┬───────────┴──────────────┘
                                 │
                    ┌────────────▼────────────┐
                    │   Ed25519 Offline       │
                    │   Verification          │
                    │   (no server needed)    │
                    └─────────────────────────┘
```

**Self-service renewal flow:**
```
Customer → My Account → Subscriptions → [Renew] →
  WooCommerce checkout → Payment → Order: completed →
    WPLM extends expires_at · sets status=active · re-signs payload →
      Next SDK validate() returns the extended expiry automatically
```

---

## 📥 Installation

WPLM is not on WordPress.org or Packagist yet. Install it from a GitHub release.

**Option A: upload the release ZIP** (recommended, no shell needed)

1. Open the [latest release](https://github.com/thisisfaizi/wp-license-manager/releases/latest) and download **wp-license-manager-x.y.z.zip** under *Assets*.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, upload the ZIP and click **Install Now**.
3. To update an existing copy, upload the newer ZIP the same way and choose **Replace current with uploaded**. Licenses, keys and settings stay in the database.

Use the release asset, not GitHub's *Source code* or **Code → Download ZIP**: those unpack to a folder with a different name (so WordPress treats an update as a second plugin) and include the tests and dev files.

> [!CAUTION]
> **Never use Delete on an old copy to make room for a new one.** If the update installs as a second plugin (the old copy sits in a folder with another name, such as `wp-license-manager-main`), deactivate the old copy and remove its folder with your host's file manager, FTP or SSH. Delete on the Plugins screen runs that copy's uninstall, and copies older than 1.3.2 delete every license, device, subscription and setting. From 1.3.2, Delete keeps your data unless **Settings → Uninstall → Delete all data** is ticked, and the plugin warns you when a second copy is installed.

**Option B: clone into your plugins folder** (needs shell access, tracks `main`)

```bash
cd wp-content/plugins
git clone https://github.com/thisisfaizi/wp-license-manager.git
cd wp-license-manager
composer install --no-dev --optimize-autoloader   # optional, see note below
```

Then activate **WP License Manager** under **Plugins → Installed Plugins**. Activation creates the WPLM tables and generates the Ed25519 signing keypair and the key-encryption key, so there is nothing else to configure before your first license.

> [!NOTE]
> `vendor/` is not committed. The plugin has its own autoloader for `src/`, so it runs without `composer install`. Running it with `--no-dev` just swaps in Composer's optimised autoloader.

WooCommerce is optional. Without it you still get licenses, devices, the REST API and webhooks; the store, checkout, My Account and subscription billing features switch on when WooCommerce is active.

---

## ⚡ Quick Start

Responses are wrapped as `{ "success": true, "data": { … }, "meta": { … } }`.

### Issue a license (management API)

Management routes use a WPLM API key over HTTP Basic auth: the consumer key (`ck_…`) is the username and the consumer secret (`cs_…`) is the password. Create one under **License Manager → API Keys** with `write` or `read_write` permission. Copy the secret when it appears: it is shown once and stored only as a hash. A key works only on `wplm/v1` routes, not on the rest of the WordPress REST API.

```bash
curl -X POST https://yoursite.com/wp-json/wplm/v1/licenses \
  -u "ck_your_consumer_key:cs_your_consumer_secret" \
  -H "Content-Type: application/json" \
  -d '{"generator_id": 1, "expires_at": "2027-12-31 23:59:59", "max_activations": 3}'
```

`expires_at` is a UTC datetime (`YYYY-MM-DD HH:MM:SS`). Pass `key_string` to set the key yourself instead of using a generator.

### Activate and validate from client software

These routes are public, so client apps call them without an API key.

```bash
# Activate a seat on this device
curl -X POST https://yoursite.com/wp-json/wplm/v1/activate \
  -H "Content-Type: application/json" \
  -d '{"license_key": "XXXX-XXXX-XXXX-XXXX", "fingerprint": "device-id-here", "name": "My Laptop"}'

# Validate (returns data.signed_payload for offline caching; HTTP 422 when the key is not valid)
curl -X POST https://yoursite.com/wp-json/wplm/v1/validate \
  -H "Content-Type: application/json" \
  -d '{"license_key": "XXXX-XXXX-XXXX-XXXX", "fingerprint": "device-id-here"}'

# Fetch the Ed25519 public key once and ship it with your app
curl https://yoursite.com/wp-json/wplm/v1/public-key
```

Store the `signed_payload` from `/validate` and check it against the public key to verify the license offline, with no server connection. The SDKs do this for you.

---

## 📦 Client SDKs

| SDK | Language | Version | Install |
|---|---|---|---|
| [wplm-dart](https://github.com/thisisfaizi/wplm-dart) | Dart / Flutter | 0.2.0 | [README](https://github.com/thisisfaizi/wplm-dart#readme) |
| [wplm-python](https://github.com/thisisfaizi/wplm-python) | Python | 0.2.0 | [README](https://github.com/thisisfaizi/wplm-python#readme) |
| [wplm-php](https://github.com/thisisfaizi/wplm-php) | PHP + WordPress helper | 0.2.0 | [README](https://github.com/thisisfaizi/wplm-php#readme) |
| [wplm-js](https://github.com/thisisfaizi/wplm-js) | JavaScript / TypeScript | 0.2.0 | [README](https://github.com/thisisfaizi/wplm-js#readme) |

None of the SDKs are on a package registry yet, so install them from GitHub as each README describes.

---

## 📋 Requirements

| Requirement | Minimum | Recommended |
|---|---|---|
| PHP | 8.0 | 8.2+ |
| WordPress | 5.8 | 6.5 |
| WooCommerce | 6.0 _(optional)_ | 9.0 |
| MySQL / MariaDB | 5.7 / 10.3 | 8.0 / 10.6 |
| PHP `sodium` extension | Required | Bundled with PHP 7.2+ |

---

## 🔧 Development

```bash
composer install     # dev dependencies (PHPUnit, WPCS)
composer lint        # WordPress Coding Standards via phpcs.xml
composer lint-fix    # auto-fix what phpcbf can
composer test        # integration tests, needs WordPress + WooCommerce + MySQL
```

The test suite runs against a real WordPress, WooCommerce and an **empty** MySQL test database. Set it up once with [tests/README.md](tests/README.md) before running `composer test`.

---

## 📚 Documentation

| Document | What it covers |
|---|---|
| [CHANGELOG.md](CHANGELOG.md) | Every release in detail, including known limitations |
| [readme.txt](readme.txt) | WordPress-style plugin readme: description, FAQ, short changelog |
| [WPLM_Build_Specification.md](WPLM_Build_Specification.md) | Build spec with implementation amendments: architecture, schema, cryptography, REST API |
| [tests/README.md](tests/README.md) | Setting up and running the integration tests |
| [docs/audit/](docs/audit/) | Observed purchase, activation and renewal walk-through on a real install |
| SDK READMEs | Install, offline verification and product binding for each [client SDK](#-client-sdks) |

---

## 🤝 Contributing

Contributions are welcome:

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/my-feature`
3. Run `composer lint` and `composer test`; both must pass
4. Open a pull request with a clear description

Follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/) (configured in [phpcs.xml](phpcs.xml)) and include tests for new features.

---

## 📄 License

GPL v2 or later. See [LICENSE](LICENSE).

---

<div align="center">

**Built for WordPress plugin vendors who need real licensing infrastructure, not SaaS fees.**

[![Dart SDK](https://img.shields.io/badge/SDK-Dart%2FFlutter-0175C2?style=for-the-badge&logo=dart&logoColor=white)](https://github.com/thisisfaizi/wplm-dart)
[![Python SDK](https://img.shields.io/badge/SDK-Python-3776AB?style=for-the-badge&logo=python&logoColor=white)](https://github.com/thisisfaizi/wplm-python)
[![PHP SDK](https://img.shields.io/badge/SDK-PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://github.com/thisisfaizi/wplm-php)
[![JS SDK](https://img.shields.io/badge/SDK-JavaScript%2FTypeScript-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)](https://github.com/thisisfaizi/wplm-js)

<img src="https://capsule-render.vercel.app/api?type=waving&color=gradient&customColorList=6,11,20&height=100&section=footer" alt="" />

</div>
