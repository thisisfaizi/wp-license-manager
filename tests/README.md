# WPLM tests

Integration tests run against a **real** WordPress, WooCommerce and MySQL, using
[`wp-phpunit`](https://github.com/wp-phpunit/wp-phpunit). Each test runs inside a transaction that is
rolled back afterwards.

## One-time setup

1. Create an **empty** database for the tests. Never use a site's database: the WordPress test installer
   drops its tables on every run.
   ```sql
   CREATE DATABASE wplm_tests CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
2. `composer install`
3. Have a WordPress core directory and WooCommerce available. A local site works: the tests load only
   WooCommerce and WPLM, and write to the test database only.

## Environment

| Variable | Meaning | Default |
|---|---|---|
| `WP_CORE_DIR` | WordPress core directory (contains `wp-settings.php`) | — required |
| `WC_PLUGIN_DIR` | WooCommerce plugin directory | `$WP_CORE_DIR/wp-content/plugins/woocommerce` |
| `WPLM_TESTS_DB_NAME` | Test database | `wplm_tests` |
| `WPLM_TESTS_DB_USER` / `WPLM_TESTS_DB_PASSWORD` | Credentials | `root` / empty |
| `WPLM_TESTS_DB_HOST` | Host, optionally `host:port` | `127.0.0.1` |
| `WP_PHP_BINARY` | PHP command for the installer's child process (include `-c php.ini` if needed) | the running PHP |

## Run

```sh
vendor/bin/phpunit                      # everything
vendor/bin/phpunit --filter ManualPayer # one class
```

On LocalWP for Windows, MySQL listens on the site's own port (Site → Database). Use Local's PHP with the
site's generated `php.ini` so `mysqli` and `sodium` are loaded.

## Conventions

- **Time travel by editing stored dates** (`TestCase::set_row()`, `utc()`), never by sleeping.
- **Buy through real WooCommerce orders** (`TestCase::buy()`), so checkout hooks run exactly as in
  production.
- A defect found in an audit gets a failing test **before** its fix. The test names the audit finding
  (see `docs/audit/`).
