# WP License Manager (WPLM)

> Industry-grade software licensing for WooCommerce. Open source, GPL v2.

WPLM is a self-hosted licensing server that runs as a WordPress plugin. It issues, validates, activates, monitors, and revokes cryptographically signed license keys — and sells recurring licenses through a native subscription engine, with no paid extensions required.

## Features

| Area | Capability |
|---|---|
| **Cryptographic keys** | Ed25519-signed, AES-256-GCM encrypted. Clients verify offline without calling the server. |
| **Device management** | Hardware fingerprint binding, seat accounting, floating leases, overage strategies (deny / 1.25× / 2×). |
| **Monitoring** | Heartbeat monitor, zombie cull cron, anomaly detection (impossible travel, churn, spikes). |
| **Revocation** | Instant revoke/suspend/terminate, per-device revoke, global blacklists, signed CRL for offline clients. |
| **Native subscriptions** | Recurring billing (daily/weekly/monthly/yearly) with dunning, proration, upgrade/downgrade, and self-service portal — no WooCommerce Subscriptions required. |
| **WooCommerce** | Licensed product panel, auto-delivery on order completion, My Account portal. |
| **REST API** | 40+ endpoints across licenses, devices, generators, releases, subscriptions, and webhooks. |
| **Client SDKs** | PHP/WordPress helper, Dart/Flutter, JavaScript/TypeScript, Python, OpenAPI 3.1 spec. |

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 7.4 (8.1+ recommended) |
| WordPress | 5.8 |
| WooCommerce | 6.0 (optional — core features work standalone) |
| MySQL | 5.7 / MariaDB 10.3 |
| PHP sodium extension | Bundled with PHP 7.2+ |

## Installation

### From a .zip file

1. Download `wp-license-manager.zip` from the [Releases page](https://github.com/wplm/wp-license-manager/releases).
2. In WordPress admin: **Plugins → Add New → Upload Plugin**.
3. Upload the zip, install, and activate.

### Via Composer

```bash
composer require wplm/wp-license-manager
```

### Manual / development

```bash
cd wp-content/plugins
git clone https://github.com/wplm/wp-license-manager.git
cd wp-license-manager
composer install
```

Activate from **Plugins → Installed Plugins**.

## Quick start

```bash
# Issue your first license via the REST API
curl -X POST https://yoursite.com/wp-json/wplm/v1/licenses \
  -u "ck_your_key:cs_your_secret" \
  -H "Content-Type: application/json" \
  -d '{"generator_id": 1}'

# Validate from client software
curl -X POST https://yoursite.com/wp-json/wplm/v1/validate \
  -H "Content-Type: application/json" \
  -d '{"license_key": "ABCD-EFGH-IJKL-MNOP"}'
```

## Documentation

Full documentation lives in `/docs-site/` and is built with [Astro Starlight](https://starlight.astro.build/).

```bash
cd docs-site
npm install
npm run build   # → dist/ ready to upload to your docs subdomain
```

## Development

```bash
composer install
vendor/bin/phpcs --standard=phpcs.xml .   # lint
vendor/bin/phpunit                         # tests
```

## License

GPL v2 or later. See [LICENSE](LICENSE).

## Contributing

Pull requests welcome. Please run phpcs and phpunit before submitting.
