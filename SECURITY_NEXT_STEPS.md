# Security Next Steps — Mossfield ↔ Mossorders Sync

This document tracks work from the security review. Phases 1–3 have all shipped in code. A handful of items from Phase 3 still require manual operator action in production (running a backfill migration, flipping `.env` values, attaching the real allowlist IP). Those are called out in-line. Full review lives at `/home/jon/.claude/plans/review-the-current-security-giggly-kay.md`. Production install runbook lives in [`DEPLOYMENT.md`](./DEPLOYMENT.md).

## Dev-box rollout status (2026-04-22)

The dev-safe subset of every operator action below has been applied and verified on the shared dev box running both apps. Active on dev **now**:

- Both backfill migrations run; mossfield PII migration run (customer `phone`/`address` in DB are ciphertext).
- `SESSION_ENCRYPT=true` + `SESSION_SAME_SITE=strict` on both `.env` files. `SESSION_SECURE_COOKIE` stays `false` on dev because the sites run over HTTP; it flips on with the prod cert.
- `OFFICE_API_ALLOWED_IPS=127.0.0.1,::1` on both. API auth chain verified end-to-end: loopback + valid token → 200; wrong token → 401; LAN IP (`10.42.1.83`) → 403 via `api_token_auth: ip not allowed`.
- `SYNC_SCHEDULE_ENABLED=true` on both. Manual command runs append a structured `run summary` line with correlation id + duration to `storage/logs/sync-YYYY-MM-DD.log`.

**Still pending on dev:** cron entry to actually fire `schedule:run` every minute. The hourly schedule is registered and gated on, but nothing triggers it yet. Line to add lives in `DEPLOYMENT.md` §6.

**Still pending everywhere (prod-only):** `SESSION_SECURE_COOKIE=true` (needs HTTPS), production boot-guard exercise (`APP_ENV=production` + `APP_DEBUG=true` refuses to start), HSTS header check over HTTPS, live token rotation drill, and the `SYNC_ALERT_EMAIL` failure-mail path (mailer not configured on dev).

---

## Phase 1 — Shipped

- **H1** Timing-safe token comparison on Mossfield API (`hash_equals`) + feature tests.
- **H2** `throttle:sync-api` (60/min per IP) in front of `api.token` on both apps.
- **H4** Boot guard: app refuses to start if `APP_ENV=production` AND `APP_DEBUG=true`.
- **H5** `AdminUserSeeder` refuses to run in production; password overridable via `ADMIN_SEED_PASSWORD`.
- **M1+M2** `connectTimeout(5) / timeout(10) / retry(3, 500, throw: false)` on both outbound sync services.
- **M3** Audit logging in both `ApiTokenAuth` middlewares (IP, URI, hashed token fingerprint).
- **M10** Hourly schedules registered for `mossfield:import-online-orders` and `office:sync-products` with `withoutOverlapping(10)`, gated on `SYNC_SCHEDULE_ENABLED` (default false).

Flip `SYNC_SCHEDULE_ENABLED=true` when ready to turn the scheduler on.

---

## Phase 2 — Make automation observable & rotatable ✅ SHIPPED

All three Phase 2 items landed. The rotation runbook, sync logging, and failure-alert wiring below are now live.

### H3 — Two-token window for zero-downtime rotation ✅ SHIPPED

**What shipped:**
- `OFFICE_API_TOKEN_PREVIOUS` added to `config/services.php` on both apps and documented in both `.env.example` files.
- Both `ApiTokenAuth` middlewares now accept either `current` or `previous`, both via `hash_equals`. The log line for a successful auth includes `token_version: current|previous` so we can tell when the old token has stopped being used in the wild.
- Feature tests cover: accept current, accept previous, reject both-miss, ignore empty previous. 9/9 tests pass in `tests/Feature/Api/ApiTokenAuthTest.php`.
- `MOSSORDERS_API_TOKEN_PREVIOUS` was intentionally **not** added — that token is outbound-only from mossfield, and senders don't need a previous-token slot.

**Quirk in the existing config (good to know before rotating):** mossorders uses one env var (`OFFICE_API_TOKEN`) for BOTH inbound validation AND outbound calls to mossfield. That means the shared secret must match across three env vars:
- `mossfield/.env: OFFICE_API_TOKEN` (mossfield validates inbound with this)
- `mossfield/.env: MOSSORDERS_API_TOKEN` (mossfield sends this outbound)
- `mossorders/.env: OFFICE_API_TOKEN` (mossorders both validates inbound and sends outbound with this)

All three hold the same shared secret today. Worth untangling in a future pass, but not blocking.

**Rotation runbook:**
1. Generate a new token: `openssl rand -hex 32` → call it `NEW`.
2. **Mossorders first** (its single env var covers both directions, so it's the tighter constraint):
   - Copy current value of `OFFICE_API_TOKEN` into `OFFICE_API_TOKEN_PREVIOUS`.
   - Set `OFFICE_API_TOKEN=NEW`.
   - Deploy. Mossorders now accepts `OLD` or `NEW` inbound, and sends `NEW` outbound.
3. **Mossfield next:**
   - Copy current value of `OFFICE_API_TOKEN` into `OFFICE_API_TOKEN_PREVIOUS`.
   - Set `OFFICE_API_TOKEN=NEW` and `MOSSORDERS_API_TOKEN=NEW` (the outbound var).
   - Deploy. Mossfield now accepts `OLD` or `NEW` inbound, and sends `NEW` outbound.
4. Watch the logs for one full sync cycle in each direction. Every accept should show `token_version: current`.
5. Once confirmed, clear `OFFICE_API_TOKEN_PREVIOUS` on both apps and deploy.

**Files touched:**
- `mossfield/app/Http/Middleware/ApiTokenAuth.php`
- `mossorders/app/Http/Middleware/ApiTokenAuth.php`
- `mossfield/config/services.php`, `mossorders/config/services.php`
- `mossfield/.env.example`, `mossorders/.env.example`
- `mossfield/tests/Feature/Api/ApiTokenAuthTest.php`

### M4 — Structured per-run sync logging ✅ SHIPPED

**What shipped:**
- `sync` log channel added to both apps' `config/logging.php` — daily rotation, 14-day retention (override with `SYNC_LOG_DAYS`), path `storage/logs/sync.log`.
- `ImportOnlineOrders` (mossfield) now emits a single `import_orders: run summary` line per run with: correlation id (UUIDv4), outcome, duration_ms, `since` cursor, counts for fetched/processed/imported/skipped_existing/skipped_unmapped_customer/skipped_invalid_data. Per-order exceptions also flow to the `sync` channel with the same correlation id.
- `SyncOfficeProducts` (mossorders) emits a matching `sync_products: run summary` line with correlation id, mode (full/incremental), duration_ms, total/inserted/updated, outcome, and error message on failure.
- `sync:import_orders:last_ok` (mossfield) and `sync:sync_products:last_ok` (mossorders) are cached as ISO8601 strings on success — stored with `Cache::forever`, overwritten each run.
- Mossfield dashboard (`resources/views/dashboard.blade.php`) now shows a coloured "Online order sync: last ran Xm ago" strip that goes yellow after 2h, red after 6h, grey if no successful run yet. The route handler (`routes/web.php`) reads the cache and passes `$lastOnlineOrderImportAt` to the view.

**Files touched:**
- `mossfield/config/logging.php`
- `mossfield/app/Console/Commands/ImportOnlineOrders.php`
- `mossfield/routes/web.php`
- `mossfield/resources/views/dashboard.blade.php`
- `mossorders/config/logging.php`
- `mossorders/app/Console/Commands/SyncOfficeProducts.php`

### M5 — Failure alerting ✅ SHIPPED (Option A)

**What shipped:**
- Both `routes/console.php` files now call `->emailOutputOnFailure($alertEmail)` on their respective schedule entries when the new `SYNC_ALERT_EMAIL` env var is set. When the var is blank, the hook is skipped and ops falls back to tailing `storage/logs/sync.log`.
- Documented in both `.env.example` files.

**Prerequisite for alerts to actually send:** the app's mailer must be configured (`MAIL_MAILER`, `MAIL_HOST`, etc. in `.env`). In staging we can verify with `php artisan mail:test` after setting the env vars.

**If Option A turns out to be noisy or unreliable** (e.g. mailer flaky, emails filtered), Options B and C from earlier revisions of this doc remain open:
- Option B: custom `SyncFailedException` + a `ReportableHandler` routing to Slack.
- Option C: `/health/sync` endpoint returning 200 iff the `last_ok` cache entry is within 2× schedule interval; wire external monitoring to it. This is the most robust choice for production.

**Files touched:**
- `mossfield/routes/console.php`
- `mossorders/routes/console.php`
- `mossfield/.env.example`, `mossorders/.env.example`

---

## Phase 3 — Harden the edges ✅ SHIPPED (code) / ops action required for some items

### M6 — Enforce email verification on sensitive routes ✅ SHIPPED

**What shipped:**
- `User` on both apps now implements `MustVerifyEmail` (was commented out).
- **Mossfield** `routes/web.php`: the main business-route group (`products`, `batches`, `customers`, `orders`, `order-allocations`, `online-orders`, `cheese-cutting`, `stock`) now carries `['auth', 'verified']`. Profile routes stay at `auth`-only so users can reach profile/verification pages while unverified.
- **Mossorders** `routes/web.php`: the order-placement group and the admin group gained `verified` middleware in front of `must.change.password`. Profile and password-force-change stay reachable without verification.
- Backfill migrations (`2026_04_20_120000_backfill_email_verified_at_for_existing_users`) on both apps set `email_verified_at = now()` for rows where it is NULL — grandfathers existing users past the new requirement. New registrations still must verify.

**Ops step on prod:** run `php artisan migrate` after deploy. Watch for confused users on the next login — they hit the profile page fine but anything business-facing redirects to verification.

**Dev status (2026-04-22):** backfill migration run on both apps. Existing user rows now carry `email_verified_at = now()`. Unverified-user redirect path not yet manually tested on dev (no way to create an unverified user through the UI without also triggering the mail flow).

### M7 — Production session cookie hardening ✅ DOCUMENTED (ops action only)

**What shipped:** both `.env.example` files now carry a commented block recommending the production values. No code change — these are .env settings Laravel already respects.

**Ops step on prod:** set in production `.env`:

```
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=strict
SESSION_ENCRYPT=true
APP_URL=https://...
```

Also verify the reverse proxy sets `X-Forwarded-Proto` so Laravel recognizes HTTPS.

**Dev status (2026-04-22):** `SESSION_SAME_SITE=strict` and `SESSION_ENCRYPT=true` applied on both `.env` files. `SESSION_SECURE_COOKIE` left at `false` — dev serves over HTTP, so enabling secure cookies would log everyone out and block login.

### M8 — Encrypt customer PII at rest ✅ SHIPPED (migration still pending operator run)

**What shipped:**
- `mossfield/app/Casts/EncryptedNullable.php` — custom cast that encrypts on write and decrypts on read, but **tolerates pre-migration plaintext** so deploying the cast does not break customer pages before the backfill runs. When it falls back to plaintext it logs a `legacy plaintext read` warning so any missed rows are visible in logs.
- `mossfield/app/Models/Customer.php`: `phone`, `address`, `city`, `postal_code`, `notes` now use `EncryptedNullable`. `name`, `email`, and `country` stay plaintext because they are queried by equality.
- `mossfield/database/migrations/2026_04_20_120500_encrypt_customer_pii.php` — idempotent backfill migration. Each row attempts `Crypt::decryptString` on each PII field; if that fails (value is plaintext) it encrypts in place. Safe to re-run after partial failure. `down()` is provided to unwind a rollback but should be considered best-effort.
- `mossorders` side not changed — the mossorders `User` model doesn't store address/phone (the `phone` column was removed per `2025_11_15_162637_remove_phone_from_users_table`). If future columns are added, mirror the cast pattern here.

**Ops step on prod — mandatory runbook:**

1. **Back up the DB.** No exceptions.
2. Deploy the code change (cast + migration). Because the cast is plaintext-tolerant, customer pages continue to load even before step 3.
3. Run `php artisan migrate`. The backfill walks the `customers` table, encrypting plaintext values. Re-run if it errors mid-stream — it skips already-encrypted rows.
4. Grep logs for `encrypted_nullable: legacy plaintext read`. Any such entry after migration means a row slipped through — inspect and re-run.
5. Verify: `php artisan tinker` → `Customer::first()->address` → should be plaintext. Raw DB query → should be ciphertext (starts with `eyJpdiI6`).

**Dev status (2026-04-22):** runbook exercised on dev end-to-end — `mysqldump` taken first, migration ran clean, `SELECT phone, address FROM customers LIMIT 3` now returns `eyJpdiI6…` ciphertext, no `legacy plaintext read` log entries since the migration.

**Column width note:** `phone`, `city`, and `postal_code` are `VARCHAR(255)`. A Laravel-encrypted payload is ~200 bytes for a ~50-char input, so normal values fit. If any customer has an unusually long city name (>180 chars plaintext), widen the column to `TEXT` before running the migration.

**Other PII not encrypted:**
- `delivery_address` on `orders` — similar treatment deferred; revisit if legal asks.
- Email — left plaintext because `unique:email` lookups depend on it. If email encryption is required later, add an `email_hash` blind-index column.

### M9 — Explicit TLS verification and HTTPS requirement ✅ SHIPPED

**What shipped:**
- `->withOptions(['verify' => true])` appended to the outbound HTTP pipelines in `OnlineOrderImportService` (mossfield) and `OfficeProductSyncService` (mossorders). A future "let's just turn off SSL verify to debug" change now has to actively rewrite this line.
- `AppServiceProvider::boot()` on both apps gained `guardAgainstInsecureSyncUrlsInProduction()` — when `APP_ENV=production`, it refuses to boot if the configured sync base URL is not `https://`. Dev/staging are unaffected.

### L1 — Security headers ✅ SHIPPED

**What shipped:**
- `app/Http/Middleware/SecurityHeaders.php` on both apps. Registered via `$middleware->append(...)` in each `bootstrap/app.php`, so every response carries the headers.
- HSTS is production-only (won't pin HTTPS on a dev box); the other headers (`X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: geolocation=(), microphone=(), camera=()`) always apply.

**CSP note:** deliberately not adding a Content-Security-Policy header. A strict CSP needs to be tuned to match actual script/style sources, and a wrong one breaks the app silently. Add in a follow-up when there's time to test it per app.

### L2 — Optional IP allowlist ✅ SHIPPED

**What shipped:**
- Both `ApiTokenAuth` middlewares now run an IP check before the token check. Reads `services.office.allowed_ips` (comma-separated IPs/CIDRs via `OFFICE_API_ALLOWED_IPS`). Empty = allow all, matching prior behavior.
- Uses `Symfony\Component\HttpFoundation\IpUtils::checkIp()` so CIDR ranges Just Work.
- `.env.example` on both apps documents the variable.
- 3 new tests in `tests/Feature/Api/ApiTokenAuthTest.php` cover empty-allowlist-allows-all, block-off-list-IP, accept-within-CIDR. Full suite: 12/12 passing.

**Ops step on prod:** once you know the stable IP (or IP range) of the mossorders server as seen by mossfield (and vice versa), set `OFFICE_API_ALLOWED_IPS` on both sides. Until then the check is inert.

**Dev status (2026-04-22):** both `.env` files set to `OFFICE_API_ALLOWED_IPS=127.0.0.1,::1`. End-to-end tested — loopback + valid token returns 200, LAN source IP `10.42.1.83` returns 403 with `api_token_auth: ip not allowed` in the audit log.

### L3 — Pagination cap on `/api/orders` ✅ SHIPPED

**What shipped:**
- Mossorders' `OrderExportController` now accepts a `limit` query param (default 200, max 500 — enforced server-side). Results are ordered `updated_at ASC` so callers can advance a `since` cursor. The response gains a `meta` block with `limit`, `count`, `has_more`, and a `next_since` cursor that is `null` when the window is fully returned.
- Existing mossfield importer reads `response.data`, which is unchanged — the `meta` addition is backward-compatible.

### L4 — Document internal-ID exposure policy ✅ SHIPPED

**What shipped:** class-level docblock added to `mossfield/app/Http/Controllers/Api/ProductExportController.php` explaining that `office_product_id` / `office_variant_id` are deliberately exposed to the trusted Mossorders consumer and that this shape should not be copy-pasted for less-trusted endpoints.

### L5 — Credential hygiene ✅ VERIFIED

**What shipped:** both `.gitignore` files already list `.env`, `.env.backup`, and `.env.production`. No change needed.

**Ops step (recommended, one-time):** run `git log -p --all -- .env*` on each repo to confirm no real tokens were ever committed. If any are found, treat them as compromised and rotate using the H3 runbook above.

---

## Verification Checklist

Legend: `[x]` = verified, `[~]` = partially verified (details inline), `[ ]` = still pending.

Phase 2 (run these in staging before enabling the scheduler in prod):
- [ ] Live-rotate the API token end-to-end: set `_PREVIOUS`, deploy new `OFFICE_API_TOKEN`, run a manual sync, grep `sync.log` for `token_version: current`, then clear `_PREVIOUS`. *Needs a real rotation window; dev uses a single static token.*
- [x] Run `php artisan mossfield:import-online-orders` once manually; confirm a `run summary` line in `sync.log` with correlation id, duration_ms, and counts. *dev 2026-04-22 — UUID `44edc8c6…`, 200 ms, 6 fetched / 5 skipped-existing / 1 skipped-unmapped.*
- [x] Run `php artisan office:sync-products` on mossorders; confirm matching `sync_products: run summary` line. *dev 2026-04-22 — UUID `3ee62346…`, 84 ms, 2 updated.*
- [x] Hit `/dashboard` on mossfield — the sync freshness strip shows a recent timestamp. *dev 2026-04-22. Aging to yellow/red not yet exercised — cache would need to go stale.*
- [ ] Set `SYNC_ALERT_EMAIL` and force a failure (point `MOSSORDERS_BASE_URL` at an invalid host). Run the scheduler once (`php artisan schedule:run`) and confirm the email lands. *Dev mailer not configured; test in staging.*

Phase 3 (before rolling out in prod):
- [x] Both backfill migrations run on dev (`email_verified_at` on both apps, `encrypt_customer_pii` on mossfield) with DB snapshot taken first. *dev 2026-04-22 — dumps at `/tmp/{mossfield,mossorders}-pre-sec-20260422-12070*.sql`.*
- [ ] Log in with an unverified test user (clear `email_verified_at`) on both apps — should redirect to the verification prompt when accessing business routes. Profile should still be reachable. *Not yet exercised on dev.*
- [~] `curl -I https://staging-mossfield/` — confirm the security headers land. *dev 2026-04-22 — over HTTP, confirmed `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: geolocation=(), microphone=(), camera=()`. HSTS is prod-only and therefore absent on dev — expected.*
- [x] Set `OFFICE_API_ALLOWED_IPS` to a non-peer IP, attempt a sync, confirm 403; then to the real peer IP, confirm 200. *dev 2026-04-22 — set to `127.0.0.1,::1`; loopback + valid token → 200; LAN IP `10.42.1.83` → 403 with `ip not allowed` in audit log.*
- [ ] Set `APP_ENV=production` + `APP_DEBUG=true` in a staging env; confirm the app refuses to boot. Also swap `OFFICE_API_BASE_URL` to `http://…` under `APP_ENV=production` and confirm the same. *Not yet exercised — dev stays at `APP_ENV=local`.*
- [x] `SELECT phone, address FROM customers WHERE id = 1;` directly after the encrypt migration returns ciphertext starting `eyJpdiI6`; loading the same customer through the app returns plaintext. *dev 2026-04-22 — first 3 rows verified ciphertext.*
- [ ] Hit `/api/orders?limit=1000` on mossorders — server should clamp to 500 and include `meta.has_more` in the response. *Not yet exercised.*

After Phase 3:
- [ ] Unverified account cannot hit `/orders` on Mossfield — redirected to verification prompt.
- [x] `customers.phone` / `customers.address` in DB are ciphertext, not plaintext. *dev 2026-04-22.*
- [ ] `curl -I https://<app>/` shows HSTS and the other headers. *HTTP-side headers verified on dev; HTTPS/HSTS is prod-only.*
- [ ] Staging `.env` with `https://` enforcement: swap base URL to `http://...` and confirm boot fails.

---

## Out of Scope (tracked separately)

- Payment flow (none integrated yet).
- Host hardening, firewall, TLS termination — these live with infra, not the app.
- 2FA/MFA for office users — worth doing before external users get office logins, but not a sync-automation blocker.
