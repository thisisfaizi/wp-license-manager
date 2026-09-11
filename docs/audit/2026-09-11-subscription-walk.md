# Audit walk — purchase, activation and renewal (2026-09-11)

Before changing WPLM for Super Ledger (Super Ledger task `phases/m5-compliance/m5-27a-wplm-entitlements.md`,
step 1), the existing purchase → activate → renew paths were run on a real install and their results were
recorded. **Nothing below is inferred from reading the code; each line is an observed result.**

- Install: Local site, WordPress 7.1, WooCommerce 10.9.4, WPLM 1.0.8 (`24281aa`), PHP 8.4.10, MySQL 8.0.35.
  WPLM and WooCommerce were the only active plugins.
- Method: [`scripts/walk.php`](scripts/walk.php) and [`scripts/walk_h.php`](scripts/walk_h.php) (run with
  `wp eval-file`). They drive the real services through the container and real WooCommerce orders. Time
  was moved by editing `next_payment` / `expires_at`.
- Every row they created was tagged `M527-AUDIT` and removed afterwards by
  [`scripts/cleanup.php`](scripts/cleanup.php). Table counts were back to their starting values
  (licences 4, plans 1, packages 2, subscriptions 2, machines 0).
- Raw output: [`walk-output.txt`](walk-output.txt).

## Findings

| # | Severity | What happens | Where |
|---|---|---|---|
| **F1** | **Critical** | **A monthly plan bought at checkout issues a licence that never expires.** `expires_at` is `NULL` and `grace_days` is `0`; the signed token says `"expires": null`. The licence's only link to billing is the subscription status. | `PlanCheckout::fulfill_item` passes `expires_at => null` for recurring packages |
| **F2** | **Critical** | **A customer with no stored payment token (bank transfer, JazzCash, Easypaisa, cash) who never pays again keeps a valid licence forever.** 3 days after `next_payment`, cron leaves the subscription `active`, `next_payment` never advances, and `validate` returns `valid: true`. `wplm_subscription_manual_renewal_due` fires on every hourly run, but **nothing listens to it**: no invoice, no email, no state change. The existing subscription #2 on this install is a live example: due 2026-07-16, still `active`, licence #2 `expires_at NULL`, status active. | `RenewalProcessor::process_single`, no listener anywhere in `src/` |
| **F3** | **High** | **A failed card charge suspends the licence at the first failure, and the retries never run.** After one decline the subscription is `on-hold` and the licence `4` (suspended), although it was paid through a month later. On the retry day `get_due_for_renewal()` selects only `active`/`trial`, so the `on-hold` subscription is never picked up. The 1/3/5-day retries and the final cancel never happen; the licence stays suspended until someone intervenes by hand. | `DunningManager::handle_failure` → `SubscriptionService::pause`; `SubscriptionRepository::get_due_for_renewal` |
| **F4** | **High** | **A successful card renewal does not revive an expired licence.** Once `validate` has marked a licence `3` (expired), a successful charge extends `expires_at` but leaves status `3`, so `validate` still answers `expired` to a customer who has paid. | `RenewalProcessor::extend_license_expiry` uses `LicenseService::update` (no status change); `LicenseService::validate` step 4 writes status `3` |
| **F5** | Medium | **Late card renewals are counted from the old expiry**, so a customer who pays 5 days late loses those 5 days. Observed: expired 5 days ago → new expiry is old + 1 month, not today + 1 month. The self-service path already does `max(expires_at, now)`; the cron path does not. | `RenewalProcessor::extend_license_expiry` |
| **F6** | Medium | **Self-service renewal does not re-sign the token.** After a paid renewal, `expires_at` moved to 2026-10-11 but the stored `signature` was unchanged, still carrying the old `expires`. An offline client keeps seeing the old date. The code comment says `renew()` re-signs; it does not. | `LicenseService::renew` writes through the repository, bypassing `update()`'s re-sign |
| F7 | Low (code reading; production impact depends on timezone) | Purchase dates use `current_time('mysql')`, which is **site-local** time, but `add_period` parses it as UTC. On a site set to Asia/Karachi (+5), every period is 5 hours off. The walk ran at offset 0, so this was not observed. | `PlanCheckout::fulfill_item`, `RenewalProcessor` |
| F8 | Low (code reading) | The "subscription engine enabled" setting (`wplm_sub_engine_enabled`) is saved but never read, so switching it off changes nothing. | `SettingsPage` only |

**Checked and not a defect:**
- Deactivating the same machine twice from admin leaves `activation_count` at 0, not −1.
- The seat limit refuses a second PC (`machine_limit_exceeded`).
- A self-service renewal paid by bank transfer, then marked Completed, brings an expired licence back to
  active and extends from today.

## What this means for Super Ledger

- **F1 and F2 mean WPLM cannot stop a non-paying customer today**, which is the whole point of M5-27.
  Super Ledger's own design (per-module `paid_through`, the app computing grace and read-only) replaces
  the "perpetual licence + subscription status" model rather than patching it.
- **F3 and F4 are the failure mode Super Ledger decision D2 forbids:** a paying or briefly-late customer
  locked out with no automatic way back. For product `super-ledger`, non-payment lapses; it never
  suspends or revokes.
- **F5, F6 and F7 are fixed in the shared code**, because they affect every product WPLM sells.

## Found while building the test harness (same day)

| # | Severity | What happens | Where |
|---|---|---|---|
| **F9** | **High** | **A missing secret crashes every request, including wp-admin.** The error message says to use Settings → Tools, but that page can't load. `Plugin::boot()` constructs the cron scheduler and its whole service graph on `plugins_loaded`, and `KeyVault`/`Signer`/`Fingerprint` read their secrets in their constructors. | `Crypto\*::__construct` |
| **F10** | **High** | **A fresh install cannot sell.** No key generator is seeded, so `PlanCheckout` falls back to generator `1`, which does not exist. The exception is logged, no licence is issued, and the order is **still marked fulfilled**, so it never retries. The customer has paid and gets nothing. | `Install\Seeder`, `PlanCheckout::fulfill_order` |
| **F11** | **Critical (security)** | **Anyone can deactivate any customer's device.** The public, unauthenticated `POST /wplm/v1/deactivate` accepts a bare `machine_id`, so counting upward deactivates every device on the site. Proven by a REST test: HTTP 200, device deactivated. | `ValidationController::deactivate` |
| F12 | Low | The package editor has no "Valid for (days)" input, so saving a plan erases a one-time package's validity. | `PlanListTable`, `Menu::handle_plan_save` |

Also confirmed for **F7**: on this machine MySQL `NOW()` returns Pakistan Standard Time, 5 hours ahead of the UTC values the plugin stores. `get_due_for_renewal()` therefore treats a renewal as due 5 hours early. `MachineRepository` writes `last_heartbeat_at = NOW()` and compares it against a UTC cutoff, so on this server floating-licence zombies are never culled; on a server behind UTC, live devices would be culled.

## Status

**All of F1–F12 are fixed in 1.1.0.** Each has an integration test that failed first (`tests/integration/`). The behaviour changes are listed under *Changed* and *Upgrade notes* in `CHANGELOG.md`.

Deliberately **not** changed:
- **F8:** the unused engine toggle. Honouring a default-off setting would silently stop renewals on sites that never saved Settings.
- **Existing perpetual recurring licences:** they get an expiry at their next renewal or cancellation. A backfill would lock overdue customers the moment it runs.
