<div align="center">

<img src="https://capsule-render.vercel.app/api?type=waving&color=gradient&customColorList=6,11,20&height=200&section=header&text=WP+License+Manager&fontSize=60&fontAlignY=40&animation=fadeIn&fontColor=ffffff" />

[![Typing SVG](https://readme-typing-svg.demolab.com?font=Fira+Code&weight=700&size=22&pause=1200&color=6366F1&center=true&vCenter=true&width=740&lines=Ed25519+cryptographic+licensing+for+WordPress;Native+subscriptions+%E2%80%94+no+paid+add-ons+required;Offline+verification+%E2%80%94+no+server+roundtrip+needed;40%2B+REST+endpoints+ready+out+of+the+box;Self-service+renewal+from+My+Account)](https://git.io/typing-svg)

</div>

<div align="center">

[![CI](https://img.shields.io/github/actions/workflow/status/wplm/wp-license-manager/ci.yml?style=for-the-badge&label=CI&logo=github-actions&logoColor=white)](https://github.com/wplm/wp-license-manager/actions)
[![Coverage](https://img.shields.io/codecov/c/github/wplm/wp-license-manager?style=for-the-badge&logo=codecov&logoColor=white)](https://codecov.io/gh/wplm/wp-license-manager)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759B?style=for-the-badge&logo=wordpress&logoColor=white)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0%2B-96588A?style=for-the-badge&logo=woocommerce&logoColor=white)](https://woocommerce.com)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green?style=for-the-badge)](LICENSE)
[![Version](https://img.shields.io/github/v/release/wplm/wp-license-manager?style=for-the-badge&color=blue)](https://github.com/wplm/wp-license-manager/releases)

</div>

<br />

<div align="center">
<strong>Self-hosted, cryptographically-signed software licensing as a WordPress plugin.</strong><br />
Sell licenses through WooCommerce, verify them offline in milliseconds, and manage every seat from one admin dashboard.
</div>

---

## ✨ Features

<table>
<tr>
<td width="50%">

### 🔐 Cryptographic Security
- **Ed25519 signatures** — clients verify offline without a server call
- **AES-256-GCM** key encryption at rest
- **Signed CRL** — revoke offline clients instantly
- **Replay protection** — signed `iat` timestamp + monotonic time floor
- **HMAC-SHA256** device fingerprints

</td>
<td width="50%">

### 🖥️ Device & Seat Management
- Hardware fingerprint binding per machine
- Configurable seat limits with overage strategies (deny / 1.25× / 2×)
- Per-device revocation and admin deactivation
- Floating leases + heartbeat expiry
- Anomaly detection (impossible travel, churn spikes)

</td>
</tr>
<tr>
<td width="50%">

### 💳 Native Subscriptions
- Recurring billing: daily / weekly / monthly / yearly
- Dunning manager with configurable retry schedule
- Pro-rated upgrades and downgrades
- Self-service pause, resume, cancel, and **renew** from My Account
- **No WooCommerce Subscriptions add-on required**

</td>
<td width="50%">

### 🛒 WooCommerce Integration
- Product panel — assign a license plan to any WC product
- Auto-delivery on order completion
- My Account → Subscriptions portal (view, pause, cancel, renew)
- Self-service renewal: one click → WooCommerce checkout → expiry extended
- Subscription emails (created / renewed / payment failed / cancelled)

</td>
</tr>
<tr>
<td width="50%">

### 🌐 REST API
- 40+ endpoints: licenses, devices, generators, subscriptions, releases, webhooks
- WP Application Passwords auth (no custom keys needed)
- OpenAPI 3.1 spec bundled
- Webhook delivery with retries and HMAC signing

</td>
<td width="50%">

### 📦 Client SDKs
- **[wplm-dart](https://github.com/wplm/wplm-dart)** — Dart / Flutter (pub.dev)
- **[wplm-python](https://github.com/wplm/wplm-python)** — Python (PyPI)
- **[wplm-php](https://github.com/wplm/wplm-php)** — PHP + WordPress helper (Packagist)
- **[wplm-js](https://github.com/wplm/wplm-js)** — JavaScript / TypeScript (npm)
- All SDKs verify signatures offline and fall back to cache on network loss

</td>
</tr>
</table>

---

## 🏗️ Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                         WordPress + WooCommerce                      │
│                                                                     │
│  ┌──────────────┐   ┌────────────────────┐   ┌──────────────────┐  │
│  │  Admin Panel │   │  WooCommerce Store  │   │   My Account     │  │
│  │  (WPLM menu) │   │  (Product / Cart /  │   │  (Subscriptions/ │  │
│  │              │   │   Checkout)         │   │   Licenses)      │  │
│  └──────┬───────┘   └────────┬───────────┘   └───────┬──────────┘  │
│         │                    │                        │             │
│         └────────────────────▼────────────────────────▼             │
│                       ┌─────────────────┐                           │
│                       │  WPLM Core      │                           │
│                       │  LicenseService │                           │
│                       │  SubscriptionSvc│                           │
│                       │  ActivationSvc  │                           │
│                       │  RevocationSvc  │                           │
│                       └────────┬────────┘                           │
│                                │                                    │
│                       ┌────────▼────────┐                           │
│                       │   REST API      │  wp-json/wplm/v1          │
│                       │   40+ endpoints │                           │
│                       └────────┬────────┘                           │
└────────────────────────────────│────────────────────────────────────┘
                                 │  HTTPS
        ┌────────────────────────┼────────────────────────┐
        │                        │                        │
   ┌────▼────┐             ┌─────▼─────┐           ┌─────▼─────┐
   │ Flutter │             │  Python   │           │  Node.js  │
   │  (Dart) │             │  Desktop  │           │ Electron  │
   │  wplm-  │             │  wplm-    │           │  wplm-js  │
   │  dart   │             │  python   │           │           │
   └─────────┘             └───────────┘           └───────────┘
        │                        │                        │
        └────────────────────────┼────────────────────────┘
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
      Next SDK validate() returns extended expiry automatically
```

---

## ⚡ Quick Start

### Install

```bash
# Via Composer
composer require wplm/wp-license-manager

# Or clone into wp-content/plugins/
git clone https://github.com/wplm/wp-license-manager.git
cd wp-license-manager && composer install
```

Activate from **WordPress Admin → Plugins → Installed Plugins**.

### Issue your first license

```bash
# Create a license via the REST API
curl -X POST https://yoursite.com/wp-json/wplm/v1/licenses \
  -u "ck_your_key:cs_your_secret" \
  -H "Content-Type: application/json" \
  -d '{"generator_id": 1, "expires_at": "2026-12-31", "max_activations": 3}'
```

### Validate from client software

```bash
# Online validation (returns signed_payload for offline caching)
curl -X POST https://yoursite.com/wp-json/wplm/v1/validate \
  -H "Content-Type: application/json" \
  -d '{"license_key": "XXXX-XXXX-XXXX-XXXX", "fingerprint": "device-id-here"}'

# Activate a seat
curl -X POST https://yoursite.com/wp-json/wplm/v1/activate \
  -H "Content-Type: application/json" \
  -d '{"license_key": "XXXX-XXXX-XXXX-XXXX", "fingerprint": "device-id-here", "name": "My Laptop"}'
```

Response includes a `signed_payload` — store it and call `GET /public-key` once to verify signatures offline without any server connection.

---

## 📋 Requirements

| Requirement | Minimum | Recommended |
|---|---|---|
| PHP | 7.4 | 8.2+ |
| WordPress | 5.8 | 6.5+ |
| WooCommerce | 6.0 _(optional)_ | 8.0+ |
| MySQL / MariaDB | 5.7 / 10.3 | 8.0 / 10.6 |
| PHP `sodium` extension | Bundled since PHP 7.2 | — |

---

## 🔧 Development

```bash
composer install
vendor/bin/phpcs --standard=phpcs.xml .    # PSR-12 lint
vendor/bin/phpunit                          # unit + integration tests

# Docs site (Astro Starlight)
cd docs-site && npm install && npm run dev  # localhost:4321
npm run build                               # → dist/ for deployment
```

---

## 📚 Documentation

Full documentation lives in `/docs-site/` — built with [Astro Starlight](https://starlight.astro.build/), covering API reference, SDK integration guides, webhook configuration, and subscription lifecycle diagrams.

---

## 🤝 Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/my-feature`
3. Run `phpcs` and `phpunit` — both must pass
4. Submit a pull request with a clear description

Please follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards and include tests for new features.

---

## 📄 License

GPL v2 or later. See [LICENSE](LICENSE).

---

<div align="center">

**Built for WordPress plugin vendors who need real licensing infrastructure — not SaaS fees.**

[![Dart SDK](https://img.shields.io/badge/SDK-Dart%2FFlutter-0175C2?style=for-the-badge&logo=dart&logoColor=white)](https://github.com/wplm/wplm-dart)
[![Python SDK](https://img.shields.io/badge/SDK-Python-3776AB?style=for-the-badge&logo=python&logoColor=white)](https://github.com/wplm/wplm-python)
[![PHP SDK](https://img.shields.io/badge/SDK-PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://github.com/wplm/wplm-php)
[![JS SDK](https://img.shields.io/badge/SDK-JavaScript%2FTypeScript-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black)](https://github.com/wplm/wplm-js)

<img src="https://capsule-render.vercel.app/api?type=waving&color=gradient&customColorList=6,11,20&height=100&section=footer" />

</div>
