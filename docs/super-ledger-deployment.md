# Running the Super Ledger licence server

This is the runbook for the WordPress site that sells Super Ledger and signs its licences (Super Ledger
M5-27a §8). Every Super Ledger office checks in with this server. If the server is down, lost or leaks
its keys, every paying office is affected. Read this before going live, and again before any key or
hosting change.

## What depends on this server

- **Offices check in** at launch, every 6 hours, and on "Check now". Each check-in returns a fresh signed
  token. An office that cannot check in keeps working until its token's `checkInBy`: **7 days** after its
  last successful check-in (Settings → Super Ledger licences → Check-in window). After that it becomes
  read-only until it checks in again, receives an offline code, or finds a fleet notice.
- **Payments move dates.** Marking a renewal order Completed extends the customer's modules. The office
  unlocks at its next check-in.
- **Emails.** WooCommerce renewal invoices, and the "Your Super Ledger will become read-only on <date>"
  notice (hourly job, sent once per module and date).
- **Nothing locks a customer automatically on the server.** An unpaid module becomes read-only on its own
  after grace, in the app. Suspend is the owner's manual lock.

## 1 · Hosting (decision O-2)

- **A separate WordPress install on its own subdomain** (for example `licence.nowdigiverse.com`). Do not
  share an install with the marketing site or a shop with many plugins.
- **Plugins: WooCommerce and WPLM only**, plus a two-factor plugin for admin logins (for example
  "Two-Factor"). Every extra plugin is code that can read the keys.
- **HTTPS only.** Redirect HTTP to HTTPS at the web server or host panel.
  - Do not rely on WPLM's "Force HTTPS for REST API" setting: it is saved but not enforced.
- **XML-RPC off.** Block `xmlrpc.php` at the web server, or add a must-use plugin with
  `add_filter( 'xmlrpc_enabled', '__return_false' );`.
- **`wp-config.php`:**
  ```php
  define( 'WP_ENVIRONMENT_TYPE', 'production' );
  define( 'DISALLOW_FILE_EDIT', true );
  define( 'DISABLE_WP_CRON', true ); // A real cron runs it instead; see below.
  ```
- **A real cron.** Renewal invoices, dunning, read-only notices and webhook retries run on WP-Cron. On a
  low-traffic site WP-Cron only runs when someone visits, so run it from the system cron every 5 minutes:
  ```
  */5 * * * * cd /path/to/site && wp cron event run --due-now --quiet
  ```
- **PHP 8.0 or newer with the `sodium` extension**, and MySQL 8 / MariaDB 10.5+.
- **Settings → General → Timezone: Karachi.** Paid-through dates are calendar days in the site's time zone,
  and the read-only email names them. Set it before the first sale.
- **Outgoing email that is actually delivered** (an SMTP or transactional-mail service configured for
  `wp_mail`). Invoices and read-only notices depend on it. Send yourself a test order.
- **Backups:** a nightly database backup kept off the server for 30 days. The database holds every
  licence, line, machine and order. The keys are **not** in it once section 2 is done; back them up
  separately (section 3).

## 2 · The three secrets

| Constant | What it does | If it is lost | If it leaks |
|---|---|---|---|
| `WPLM_SIGNING_KEYPAIR` | Signs every licence token, offline code and fleet notice. The public half is built into Super Ledger. | No new token can be signed that offices accept. They go read-only as their `checkInBy` passes, until an app release trusts a new key. | Anyone can sign a token that unlocks any office. Rotate at once (section 6). |
| `WPLM_ENCRYPTION_KEY` | Encrypts licence keys in the database. | Stored licence keys cannot be decrypted: admin can't show them, and re-signing fails. | With a database copy, every licence key can be read. |
| `WPLM_FINGERPRINT_HMAC` | Hashes machine fingerprints before storing them. | Every office's machine stops matching and must activate again. | Low on its own; rotate with the next planned maintenance. |

On a fresh install WPLM generates all three **into the database** (options `wplm_signing_keypair`,
`wplm_encryption_key`, `wplm_fingerprint_hmac`). On the production server, move them into `wp-config.php`:

1. Read the current values with WP-CLI, on the server:
   ```
   wp option get wplm_signing_keypair
   wp option get wplm_encryption_key
   wp option get wplm_fingerprint_hmac
   ```
2. Put them in the offline backup first (section 3), and check the backup opens.
3. Add them to `wp-config.php` above "That's all, stop editing!", exactly as printed (single quotes; the
   keypair is a JSON string):
   ```php
   define( 'WPLM_SIGNING_KEYPAIR', '{"kp":"…","pub":"…","sec":"…"}' );
   define( 'WPLM_ENCRYPTION_KEY', '…' );
   define( 'WPLM_FINGERPRINT_HMAC', '…' );
   ```
4. Check: WPLM → Settings shows no warning about the signing keypair. Also
   `GET /wp-json/wplm/v1/public-key` returns the same `public_key` as before, and an existing licence
   still opens in admin with its key readable.
5. Delete the database copies: `wp option delete wplm_signing_keypair wplm_encryption_key wplm_fingerprint_hmac`.
   - Reactivating WPLM, or a WPLM update that changes the database version, writes fresh, unused keys
     back into these options. Delete them again afterwards.
   - The constants always win.

**Never use Settings → Tools → "Re-roll keypair" on production.** It replaces the key every office trusts.
When the constant is defined, the re-roll only rewrites the unused option, but don't rely on that.

## 3 · The offline key backup

The fleet notice (section 5) and disaster recovery both need the keys when the server is gone.

- **Contents:**
  - a file `keypair.json` holding exactly the `WPLM_SIGNING_KEYPAIR` value;
  - the encryption key and the fingerprint HMAC;
  - the public key as printed by `/public-key`;
  - the date made.
- **Encrypted** with a passphrase only the owner knows: a password manager's secure attachment, or a
  7-Zip archive with AES-256 and encrypted file names.
- **Kept in two places away from the server:** for example the password manager, plus a USB drive at a
  different address. Not in email, not in the site's backup storage.
- **Tested once a year:** open it on another computer and sign a fleet notice for tomorrow (section 5).
  Check that its public key matches the live server's.

## 4 · Uptime monitoring

- Monitor `GET https://<licence host>/wp-json/wplm/v1/public-key` every 5 minutes from an external service
  (for example UptimeRobot or Better Stack).
- Alert on anything but HTTP 200, or a body without the expected `"public_key"` value.
- Send alerts to the owner by **SMS or WhatsApp**, not only email: the mail may run on the same host.
- An outage is not urgent for the first day. Offices keep working for up to 7 days after their last
  check-in. **If it will last more than 2 days, publish a fleet notice** (section 5).

## 5 · The fleet extension notice

When the server is down for more than a couple of days, the owner signs a small notice from the offline
backup and publishes it where offices can fetch it. An office whose check-in fails fetches the notice. A
valid one moves its `checkInBy` to the notice's `until`, which is at most 30 days away. **A notice never
extends what a customer has paid for**, so it cannot give away service.

**Sign it** on any computer with PHP 8+ and the sodium extension. No WordPress is needed; take `bin/` and
`src/` from this repository:

```
php bin/fleet-notice.php --until=2026-10-05 --keypair-file=/secure/keypair.json
```

- A bare date means the end of that day in Asia/Karachi (`--timezone=` to change).
- It refuses an `until` more than 30 days ahead. For a longer outage, publish a new notice before the
  current one runs out.
- With WordPress running, `wp wplm fleet-notice --until=… --keypair-file=…` does the same.
- It prints one line: the notice. Nothing else goes in the published file.

**Publish it** as a plain-text file at two fixed URLs, on hosts that do not depend on nowdigiverse.com or
its DNS:

| Mirror | Proposed URL (confirm the exact names when creating them) |
|---|---|
| Primary | `https://<github-account>.github.io/super-ledger-fleet/notice.txt` (GitHub Pages) |
| Secondary | `https://<project>.pages.dev/notice.txt` (Cloudflare Pages) |

- Set both up **before go-live**, each serving an empty `notice.txt`.
- Tell the Super Ledger team the two exact URLs: M5-27b builds them into the app, so they cannot change
  without an app release.
- **During an outage:** commit the notice line as `notice.txt` to both repositories. Pages redeploy within
  minutes. Check with a browser that both URLs show the line.
- **After recovery:** leave the notice to expire, or replace it with an empty file. Offices that check in
  successfully take their normal window again.

## 6 · Key rotation

Super Ledger trusts a **list** of public keys built into each release. Rotation adds the new key before
switching, and removes the old one only after the whole fleet has moved.

1. **Generate** the next keypair on an offline computer:
   ```
   php -r '$k = sodium_crypto_sign_keypair(); echo json_encode(["pub" => base64_encode(sodium_crypto_sign_publickey($k)), "sec" => base64_encode(sodium_crypto_sign_secretkey($k))]), PHP_EOL;'
   ```
   Save it in the offline backup (section 3) next to the current one.
2. **Give the new public key** (`pub`) to the Super Ledger team. They ship a release that trusts both keys.
3. **Wait** until every office runs that release. Licences → a customer → Computers shows each computer's
   app version from its last check-in.
4. **Switch:** replace `WPLM_SIGNING_KEYPAIR` in `wp-config.php` with the new keypair. From now on,
   check-ins, offline codes and fleet notices are signed with it. An office keeps its old token until it
   checks in, which the release still accepts.
5. **Retire:** after every office has checked in once (7 days at most for an office in use), the team drops
   the old key from the next release.

**If the key leaked:** do the same steps as fast as the release process allows. Suspend any licence you
see misused.

**The contract-fixture key is a test key.** `wp wplm contract-fixtures` signs with a keypair derived from
a public seed. It must never be in a release build's trusted list.

## 7 · Day to day

- **A customer paid by bank transfer, JazzCash or Easypaisa:** WooCommerce → Orders → the renewal order →
  status **Completed**. The order note says the new paid-through date. The office unlocks at its next
  check-in.
- **A customer with no internet:** Licences → the licence → Computers → **Offline renewal code** (days:
  30 unless there is a reason). Copy the code and send it over WhatsApp; it is shown once. It works only on
  that computer and never extends what is paid for.
- **Lock a customer by hand:** Licences → the licence → Status → **Suspend**, with a note. Revoke only for
  fraud or chargebacks. Reinstate unlocks at the next check-in.
- **A customer moved computers twice this month and needs a third:** Computers → **Reset moves**.
- **Selling a new bundle:** Plans → a Super Ledger plan → add a licence type with its modules and limits.
  Assign the plan to a WooCommerce product on its Licensing tab.
- **Hand-fixing a customer:** Licences → the licence → Entitlement lines. Add, change the date, extend
  by months, or remove. Every change reaches the office at its next check-in.

## 8 · Staging

- Super Ledger development and M5-27b's tests use a **staging copy** at its own URL.
- It has `WP_ENVIRONMENT_TYPE` set to `staging`, the same plugins and settings, and **its own keypair**,
  generated there. Never copy the production keys to staging.
- The Super Ledger team gets staging's public key for development builds only.
- Restore production data to staging only with the customer email addresses replaced. The read-only
  email job runs there too.

## 9 · Upgrading WPLM

1. Back up the database.
2. Update the plugin files.
3. The schema upgrades itself on the next admin page, REST request or cron run. Check WPLM → Settings
   loads, and that `wp option get wplm_db_version` shows the new version (1.2.0 for this release). If the
   keys live in `wp-config.php`, delete any key options the upgrade wrote back (section 2, step 5).
4. Check one licence: Licences → a Super Ledger licence shows its lines and computers, and
   `/public-key` is unchanged.
5. Read `CHANGELOG.md` for the release's upgrade notes. 1.1.0 changed how dunning and cancellation
   affect every product.
