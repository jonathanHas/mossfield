# Mossfield — Production Deployment Runbook

This document is the single source of truth for what an operator has to do to stand up or upgrade **mossfield** (the office system) in production. Dev-setup lives in `README.md`; security context and phase-by-phase rationale lives in `SECURITY_NEXT_STEPS.md`. When either of those files says "see DEPLOYMENT.md", that's this file.

The runbook assumes mossfield and mossorders are being deployed as a pair, since they share a secret and talk to each other — but each app has its own copy of this doc. Run the procedure on both hosts.

---

## 1. Host prerequisites

- **PHP 8.2+** with extensions: `mbstring`, `xml`, `curl`, `zip`, `bcmath`, `gd`, `mysql`/`pdo_mysql`, `intl`, `fileinfo`, `tokenizer`
- **MySQL 8+** (or MariaDB 10.6+) — SQLite is fine for dev but **not** production
- **Composer 2.x**
- **Node.js 20+** and **npm** (only needed at deploy time for `npm run build`; not at runtime)
- **Webserver** — nginx or Apache — with TLS termination in front (Let's Encrypt / corporate cert)
- **SMTP mailer reachable** — used for order confirmations, scheduler failure alerts, password-reset mail
- **Cron** — used to kick Laravel's scheduler every minute (see §6)

---

## 2. First install

```bash
# as the deploy user, in the chosen target dir (e.g. /var/www/mossfield)
git clone <repo-url> .
composer install --no-dev --optimize-autoloader
npm ci
npm run build

cp .env.example .env
php artisan key:generate
# edit .env per §3 and §4 before running migrations
```

### Database bootstrap

```bash
# as root or via your MySQL provisioning tool:
CREATE DATABASE mossfield CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mossfield'@'localhost' IDENTIFIED BY '<generated-strong-password>';
GRANT ALL PRIVILEGES ON mossfield.* TO 'mossfield'@'localhost';
FLUSH PRIVILEGES;
```

### Migrations + seed

```bash
php artisan migrate --force
ADMIN_SEED_PASSWORD='<generated-strong-password>' php artisan db:seed --class=AdminUserSeeder
```

`AdminUserSeeder` refuses to run when `APP_ENV=production` unless `ADMIN_SEED_PASSWORD` is set. Do not ship with the default `admin123`.

### Filesystem perms

```bash
chown -R www-data:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

### Public storage symlink

```bash
php artisan storage:link
```

Creates `public/storage` → `storage/app/public`. Without it, uploaded product images write to disk fine but render as broken `<img>` on the product index/show pages (the URL `/storage/products/...` resolves through this symlink). Idempotent — safe to re-run.

### Framework caches

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Webserver vhost

Point `DocumentRoot` / nginx `root` at `<install-dir>/public`. Force HTTPS and forward `X-Forwarded-Proto` so Laravel recognises the TLS upstream. The app's `AppServiceProvider::boot()` refuses to start in production if `APP_DEBUG=true` or if `MOSSORDERS_BASE_URL` is not `https://` — a broken config fails loud at boot.

---

## 3. Required environment variables

### Framework

| Var | Value | Notes |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | Boot guard enforces this |
| `APP_URL` | `https://mossfield.example.com` | Must match the public URL |
| `APP_KEY` | auto (via `key:generate`) | |

### Database

| Var | Notes |
|---|---|
| `DB_CONNECTION` | `mysql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | per §2 bootstrap |

### Session hardening (production only)

```env
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=strict
SESSION_ENCRYPT=true
```

`SESSION_SECURE_COOKIE=true` requires the site to be served over HTTPS. The TLS terminator must also send `X-Forwarded-Proto: https` (see Laravel trusted-proxy docs if behind a proxy).

### Mail

`MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`. Smoke-test with `php artisan mail:test <address>` after deploy — failure alerts depend on this.

### Office ↔ Mossorders integration (the important one)

**Critical detail about the shared secret.** There are three `.env` values spread across the two hosts. They must hold the **same** hex-encoded token. Generate once with `openssl rand -hex 32` and copy into:

| Host | Env var | Role |
|---|---|---|
| **mossfield** | `OFFICE_API_TOKEN` | Validates **inbound** calls (mossorders → `/api/products`) |
| **mossfield** | `MOSSORDERS_API_TOKEN` | Authenticates **outbound** calls (mossfield → mossorders) |
| **mossorders** | `OFFICE_API_TOKEN` | Used for **both** directions in mossorders (see mossorders/DEPLOYMENT.md) |

Additional vars on mossfield:

```env
MOSSORDERS_BASE_URL=https://mossorders.example.com       # must be https:// in prod
MOSSORDERS_API_TOKEN=<shared-secret-from-openssl-rand>
OFFICE_API_TOKEN=<same-shared-secret>
OFFICE_API_TOKEN_PREVIOUS=                               # leave blank outside rotation windows
OFFICE_API_ALLOWED_IPS=<mossorders-public-ip>            # see §5
```

Rotation runbook: `SECURITY_NEXT_STEPS.md` §H3. Zero-downtime flow uses the `_PREVIOUS` var as an overlap window.

### Scheduler + alerting

```env
SYNC_SCHEDULE_ENABLED=false     # flip to true after §6 verification
SYNC_ALERT_EMAIL=ops@example.com
SYNC_LOG_DAYS=14                # optional — sync.log retention, defaults to 14
```

---

## 4. Post-install operator actions

Run these **in order** on a new production install. Each is idempotent and safe to re-run.

### 4.1 Apply backfill migrations

`php artisan migrate --force` in §2 already runs everything pending, including:
- `2026_04_20_120000_backfill_email_verified_at_for_existing_users` — marks any `NULL email_verified_at` rows as verified so existing accounts aren't locked out after `MustVerifyEmail` was added.
- `2026_04_20_120500_encrypt_customer_pii` — encrypts `phone`, `address`, `city`, `postal_code`, `notes` on every `customers` row. **Idempotent** — the cast tolerates pre-migration plaintext and this backfill is safe to re-run.
- `2026_05_25_120100_enable_variable_weight_on_cheese_variants` — flags every `type='cheese'` variant `is_variable_weight` (per-unit for wheels, `is_bulk_weighed` for packs) so weights can be captured at fulfilment. Does **not** change prices. **Pricing is a manual follow-up:** for each cheese variant that should sell by weight, edit it (`/products/{product}/variants/{variant}/edit`) → tick **Priced by weight** → set `base_price` as **€/kg**. Until then weight is recorded but invoicing stays per-unit. Note `unit_price` is locked per order line at creation, so any orders placed before this change keep their old per-unit price and would need a manual reprice.

**If you are migrating an existing production DB for the first time:** back it up first. `mysqldump -u mossfield -p mossfield > mossfield-preencrypt-$(date +%Y%m%d).sql`. The migration has a best-effort `down()` but rolling encryption back should assume "restore the dump".

Verify after running: `SELECT phone, address FROM customers LIMIT 1;` directly in MySQL should return base64-ish ciphertext beginning with `eyJpdiI6...`. Loading a customer via the app should return plaintext.

The same `php artisan migrate --force` also adds `batch_items.quantity_maturing` (`2026_06_20_130000…`) and the conversion-traceability columns (`cheese_conversion_logs`, `batches.source_batch_id`) used by **Mature Conversion** — no operator action beyond the migrate. See §4.1a for the one seeder step it needs.

It also adds the optional **Customer Reference / PO number** columns — `orders.customer_reference` (`2026_06_27_120000…`) and `customers.requires_reference` (`2026_06_27_120100…`). Additive, no backfill, no new env vars. Tick "Always require a customer reference on orders" on a customer to auto-show the field on their orders.

### 4.1a Seed the Mature Cheese product (Mature Conversion)

The Mature Conversion flow (`/cheese-conversion`) releases Farmhouse wheels into a separate **Mossfield Mature Cheese** product. It's created by `ProductSeeder` (idempotent `firstOrCreate` — also creates its Whole Wheel + Vacuum Pack variants). On an existing DB, run it once:

```bash
sudo -u www-data php artisan db:seed --class=ProductSeeder --force
```

Verify: the product exists — `SELECT name FROM products WHERE name = 'Mossfield Mature Cheese';`. Optionally tune the age threshold (default 5 months) via `MATURE_CONVERSION_MONTHS` in `.env`, then `php artisan config:clear`. Prices/weights for the Mature variants are placeholders (€55 wheel, €7 pack) — confirm with the farm and edit at `/products`.

### 4.2 Populate the IP allowlist

Once the mossorders public IP is stable (e.g. after DNS/ingress settles), set on mossfield:

```env
OFFICE_API_ALLOWED_IPS=<mossorders-public-ip>
```

Comma-separated, CIDR notation supported (e.g. `203.0.113.0/29`). Blank = allow all, which is fine for an initial bring-up but should be tightened before go-live.

After editing, `php artisan config:clear`.

Verify: `curl -I -H "Authorization: Bearer <token>" https://mossfield.example.com/api/products` **from mossorders** returns 200; **from any other host** returns `403 Forbidden`.

### 4.3 Sanity-check the scheduler (with `SYNC_SCHEDULE_ENABLED=false`)

```bash
php artisan schedule:list
# → 0 * * * *  php artisan mossfield:import-online-orders  Next Due: …
```

Run the job manually once:

```bash
php artisan mossfield:import-online-orders
tail -n 5 storage/logs/sync-$(date +%Y-%m-%d).log
# → expect a "import_orders: run summary" line with correlation_id, duration_ms, counts
```

Verify the dashboard freshness strip: log in as the admin, open `/dashboard`, look for "Online order sync: last ran Xm ago" — it should show a fresh timestamp.

### 4.4 Flip the scheduler on

Only after §4.3 looks clean:

```env
SYNC_SCHEDULE_ENABLED=true
```

Then `php artisan config:clear`.

---

## 5. Production vhost quick-check

```bash
curl -sI https://mossfield.example.com/ | grep -iE 'strict-transport|x-frame|x-content|referrer|permissions'
```

Expected:
- `Strict-Transport-Security` — production only (the `SecurityHeaders` middleware gates HSTS on `APP_ENV=production`)
- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: geolocation=(), microphone=(), camera=()`

---

## 6. Cron setup (required for scheduled sync)

Laravel's scheduler fires only if something runs `schedule:run` every minute. Add a cron entry as the deploy user:

```cron
* * * * * /usr/bin/php /var/www/mossfield/artisan schedule:run >> /var/www/mossfield/storage/logs/cron.log 2>&1
```

Verify after deploying:

```bash
tail -f storage/logs/cron.log
# and, after the top of the next hour:
tail -f storage/logs/sync-$(date +%Y-%m-%d).log
```

The hourly run appends a `run summary` line. Dashboard freshness strip updates within a minute of the run completing.

---

## 7. Upgrade / redeploy

**Everyday path (recommended): `deploy/bin/ship`.** From the dev box, after making and testing changes locally:

```bash
deploy/bin/ship              # prompt for a commit message (blank = timestamp)
deploy/bin/ship -m "Fix totals"   # use the given message, no prompt
deploy/bin/ship --no-maintenance  # deploy live (skip artisan down/up)
```

`ship` is the one-shot wrapper for the mossserv box: it auto-commits **all** local changes (tracked *and* untracked), pushes to GitHub, and deploys with maintenance mode on by default — so you don't have to remember the underlying flag combo. It shows the deploy summary and asks to confirm before pushing. Any extra flags (`--skip-npm`, `--dry-run`, `-y`, `--branch=<name>`) pass straight through.

The deploy-script family (all in `deploy/bin/`):

| Script | Use it for |
|---|---|
| **`ship`** | **Everyday deploys to mossserv** — auto-commit-all + push + deploy w/ maintenance. The default. |
| `deploy-mossserv` | mossserv target without the auto-commit defaults (pins host/path, hands off to `deploy2`). |
| `deploy2` | Original prod box (`mossfield-prod`); auto-loads the passphrase-protected SSH key (prompts once per session). |
| `deploy` | Same as `deploy2` but does **not** auto-load the key — run `ssh-add` first. |
| `deploy-on-prod` | Runs **on the server** (pull/build/migrate/cache/reload); invoked over SSH by the above, or directly for rollback. |

See `deploy/INSTALL.md` "Future redeploys" for the SSH alias setup these depend on.

Manual fallback (also what to run if `deploy-on-prod` is broken):

```bash
cd /var/www/mossfield
sudo -u www-data git fetch --tags && sudo -u www-data git checkout <new-tag>
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data npm ci && sudo -u www-data npm run build
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan storage:link
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
# restart php-fpm or equivalent so opcache picks up new code
sudo systemctl reload php8.3-fpm
```

If the upgrade introduces new env vars, diff `.env.example` against `.env` and add anything missing before restarting.

---

## 8. Rollback

If a deploy goes wrong:

1. **Migrations** — most are forward-only. If the new deploy added a destructive migration, restore the DB dump (made in §4.1) before rolling code back.
2. **Code** — `git checkout <previous-tag>`, re-run composer/npm/artisan steps from §7.
3. **Env** — revert `.env` changes from the deploy (version-controlled separately from the repo).

---

## 9. Known quirks

- **Shared secret spans three env vars across two hosts.** Missing one during rotation breaks half the sync. Full rotation runbook is `SECURITY_NEXT_STEPS.md` §H3.
- **`SESSION_ENCRYPT=true` rotates the session key format.** First time you set it, all existing browser sessions are invalidated once. Users log back in; no further impact.
- **Cron is mandatory for automated sync.** Code + env flags do not, by themselves, run the job. Without a cron entry `SYNC_SCHEDULE_ENABLED=true` does nothing.
- **PII encryption is one-way at rest.** Encrypted columns cannot be queried by value. Anything that needs a lookup (email) stays plaintext.

---

## 10. Smoke tests for a fresh install

After completing §1 – §6, these should all pass:

- [ ] `https://mossfield.example.com/` loads and serves security headers (§5)
- [ ] Admin login works with the seeded credentials (§2)
- [ ] `php artisan schedule:list` shows the hourly sync with `Next Due` in the future
- [ ] `php artisan mossfield:import-online-orders` emits a `run summary` line in `sync-YYYY-MM-DD.log`
- [ ] `/dashboard` shows a recent "Online order sync: last ran …" timestamp
- [ ] `curl -H "Authorization: Bearer <token>" https://mossfield.example.com/api/products` from the mossorders host → 200
- [ ] Same curl from any other IP → 403 (once allowlist is populated)
- [ ] `SELECT phone FROM customers LIMIT 1;` → ciphertext starting `eyJpdiI6`
- [ ] Setting `APP_ENV=production` + `APP_DEBUG=true` in a test env causes boot to fail
- [ ] A factory-role login redirects to `/picking` and the queue renders (no € amounts visible); a driver-role login gets 403 on `/picking`
- [ ] As office: create a run + assign a customer at `/delivery-runs`, then on `/chilled-runs` "Enter order" saves a **pending** order and "Confirm all" moves it onto the picking queue. As factory: the sheet renders read-only (no qty inputs, no €) but the loaded tick works. *(No new env vars or operator actions — the `delivery_runs`/`loaded_at` migrations run with the standard §7 `php artisan migrate`.)*
- [ ] As office on `/cheese-conversion`: a Farmhouse batch's wheels can be set aside ("Save maturing") and the held wheels drop out of `/stock` available + aren't auto-allocated to orders; a ≥5-month batch shows "Release to mature" → a Mature batch appears in `/batches` & `/stock`; "Return to farmhouse hold" on the mature batch undoes it. As factory: the page renders read-only. *(Requires the §4.1a `ProductSeeder` run; `quantity_maturing` migration runs with the standard `php artisan migrate`.)*
- [ ] As office: create an order with a **Customer ref** (click "+ Customer ref" on `/orders/create`) → it shows on the order page and survives a status change; tick "Always require a customer reference on orders" on a customer → the field auto-expands on their order form and Chilled Runs row. *(Additive `customer_reference`/`requires_reference` migrations run with the standard `php artisan migrate`; no new env vars.)*

---

## Change log

| Date | Change |
|---|---|
| 2026-04-22 | Initial deployment runbook |
| 2026-06-03 | Add mobile picking smoke test (factory `/picking` redirect + driver 403) |
| 2026-06-04 | Add chilled run sheet smoke test (office entry + confirm-all, factory read-only + loaded tick) |
| 2026-06-20 | Add Mature Conversion: §4.1a seed Mossfield Mature Cheese product + `MATURE_CONVERSION_MONTHS`; `quantity_maturing` migration; conversion smoke test |
| 2026-06-27 | Add optional Customer Reference / PO number: `orders.customer_reference` + `customers.requires_reference` migrations (additive, no env); customer-ref smoke test |
