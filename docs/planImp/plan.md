# Settings: batch ceilings editable by admin

Status: ACCEPTED
Revision: 1
Planner: Fable 5.1
Date: 2026-10-06

## Goal

The batch sanity ceilings (cycle 8) live in `config/mossfield.php` as the
Planner's guesses — milk 5 000, yoghurt 3 000, cheese 200 units per variant
per batch. Jon wants them changeable from the app, by an admin, without a
deploy. This cycle adds a small **Settings** page in the Admin group with the
three ceilings; the config values become the defaults a fresh install starts
from. The settings store is generic (key → value) so the next admin-tunable
number has a home, but this cycle exposes only the ceilings.

## Context

HEAD `e3184a8` + the staged archive of cycle 9. Suite **684 passed, 0
failed**; any failure is real. `pint --test app tests database routes config`
clean. Nothing pushed or deployed.

**Facts the steps rely on**

- The ceilings are read in exactly one place:
  `BatchController::store()` (`:148–151`),
  `$ceiling = (int) config("mossfield.batch_ceilings.{$product->type}", 0)`;
  `batches/create.blade.php:88` mentions the config path in a comment. The
  config block (`config/mossfield.php`) carries the comment that Jon sets the
  numbers.
- Admin-only routes live in the `role:admin` group (`routes/web.php`, the
  `users` resource and `/backup`); the **Admin** nav group
  (`layouts/navigation.blade.php:78–80`) holds Users and Backup & Restore,
  admin-only. `UserPolicy` is admin-only; `BasePolicy` is office-write.
- Page pattern to copy: `resources/views/users/index.blade.php` (title block,
  `mf-panel`, `mf-label`/`mf-input`, `mf-btn-primary`).
- Housekeeping that fails tests if missed: `ResetTradingData::KEEP` (a new
  reference table must be classified), `BackupService::TABLES` +
  `ADDED_LATER` (a new table must be listed, and marked so older backups still
  restore — `false` = keep current rows, which is right for settings),
  `AuthorizationBoundaryTest` (every web route needs `auth` + a role group;
  no route parameters here), `ConfirmDialogTest`, `LayoutFeedbackTest`.
- Tests to extend: `BatchCreateTest` (the ceiling cases from cycle 8),
  `BackupRestoreTest`, `ResetTradingDataTest` (auto), `AuthorizationBoundaryTest`
  (auto). New: `SettingsTest`.

## Constraints

- **Config stays the default.** A fresh install (no `settings` rows) behaves
  exactly as today; a stored value wins. Never write the config file.
- Admin-only (Jon's words: "a page for admin"). Office doesn't see it.
- Ceilings are integers ≥ 1 (0/blank is not "no ceiling" — a batch form
  with no ceiling would lose the guard; if Jon ever wants one off, that's a
  separate decision).
- One read per request: the batch form must not query settings per variant
  row.
- `./vendor/bin/pint` on PHP; `npm run build` only if CSS/JS change (none
  expected).
- **Do not commit.** Planner commits on acceptance. Nothing pushed/deployed.

## Out of scope

- Exposing any other setting (mature-conversion months, invoicing config,
  weight bound factor) — the store can hold them later; this page shows the
  three ceilings only.
- A per-variant ceiling (per type is what cycle 8 built).
- Audit history of changes (the flash names old → new; that's enough for
  three numbers).

## Steps

### 1. A settings store with config defaults

Files: `database/migrations/2026_10_06_120000_create_settings_table.php` (new),
`app/Models/Setting.php` (new), `app/Support/Settings.php` (new — or a static
API on the model; say which), `tests/Feature/SettingsTest.php` (new)

What:
- `settings`: `key` (string 80, **primary**), `value` (text, JSON-encoded),
  `updated_by` FK users nullable `nullOnDelete`, timestamps.
- `Setting` model (`$incrementing = false`, `$keyType = 'string'`, casts
  `value` json). Static API:
  `Setting::get(string $key, mixed $default = null): mixed` — stored value
  else `$default`; `Setting::set(string $key, mixed $value, ?User $by): void`;
  `Setting::batchCeiling(string $type): int` — `(int) self::get("batch_ceilings.$type",
  config("mossfield.batch_ceilings.$type", 0))`. Cache reads **per request**
  (a static array or `once()`), cleared by `set()`.
- Tests: `get` falls back to config; `set` then `get` returns the stored
  value and survives a fresh query; `batchCeiling('cheese')` is 200 with no
  row and the stored value with one.

Check: `php artisan migrate` clean; `php artisan test --filter=SettingsTest` pass.

### 2. The batch form reads the setting

Files: `app/Http/Controllers/BatchController.php`, `resources/views/batches/create.blade.php`,
`tests/Feature/BatchCreateTest.php`

What: `store()` uses `Setting::batchCeiling($product->type)` (one call);
the per-item error text is unchanged. The create view's comment points at
Settings as well as the config. Test: with `batch_ceilings.cheese` stored as
500, 300 wheels save without a prompt and 501 ask; the existing cycle-8
cases (no row) still pass unchanged.

Check: `php artisan test --filter=BatchCreateTest` pass.

### 3. The Settings page

Files: `app/Http/Controllers/SettingsController.php` (new),
`app/Policies/SettingPolicy.php` (new — admin-only like `UserPolicy`),
`app/Providers/AuthServiceProvider.php`, `app/Http/Requests/SettingsRequest.php` (new),
`resources/views/settings/index.blade.php` (new), `routes/web.php`,
`resources/views/layouts/navigation.blade.php`, `tests/Feature/SettingsTest.php`

What:
- Routes in the **`role:admin` group**: `GET /settings` → `index`
  (`settings.index`), `PUT /settings` → `update` (`settings.update`).
- `SettingPolicy`: `viewAny`/`update` for active admins only (mirror
  `UserPolicy`). `SettingsRequest::authorize()` = `can('update', Setting::class)`;
  rules: `batch_ceilings.milk|yoghurt|cheese` each `required|integer|min:1|max:1000000`.
- `index()`: the three current values (stored or default) and, per type,
  whether it is the default or who changed it when (`updated_by`,
  `updated_at`). `update()`: `Setting::set()` for each changed value only;
  flash "Batch ceilings saved — cheese 200 → 500." naming each change (or
  "No changes." when none). Redirect to `settings.index`.
- View: title "Settings", one panel **Batch quantity ceilings** — "Above
  these a new batch asks for confirmation before saving. Units per variant
  per batch." — three labelled number inputs (Milk / Yoghurt / Cheese) with
  the default shown as help text ("default 5 000") and "changed by {name} on
  {date}" when stored; one **Save** button; a "Reset to defaults" button
  (`data-confirm`) that deletes the three rows.
- Nav: **Admin** group, "Settings" (a cog icon, following the existing icon
  map), admin-only.
- Tests: admin sees the page with the defaults; `PUT` stores and the page
  shows the new value and "changed by"; `min:1` refused; office and factory
  get 403 on both routes; guest → login; Reset restores the defaults; the
  flash names the change.

Check: `php artisan test --filter="SettingsTest|AuthorizationBoundaryTest"` pass
(the matrix picks the two routes up automatically — admin-only, no params).
Browser as admin: `/settings` → set cheese to 500 → Save → flash; `/batches/create`
with 300 wheels saves without the prompt; Reset → 200 again.

### 4. Housekeeping

Files: `app/Console/Commands/ResetTradingData.php`, `app/Services/BackupService.php`,
`tests/Feature/BackupRestoreTest.php`

What: `settings` → `ResetTradingData::KEEP`; `BackupService::TABLES` gains
`settings` (after `users`, since it references them) and `ADDED_LATER['settings'] = false`
(an older backup leaves current settings alone). Test: a backup with a
stored ceiling round-trips it; a payload without the table keeps it.

Check: `php artisan test --filter="ResetTradingDataTest|BackupRestoreTest"` pass.

### 5. Docs

Files: `CLAUDE.md`, `config/mossfield.php`, `docs/planImp/WORK_IN_PROGRESS.md`,
`docs/planImp/OPEN_QUESTIONS.md`

What: `CLAUDE.md` → a short **Settings** subsection under Security Posture →
Roles (admin-only `/settings`, `Setting::get/set`, config = defaults,
`batchCeiling()` is the one read site, housekeeping lists); the Batch create
rules paragraph points at Settings. `config/mossfield.php`'s ceilings comment:
"defaults — admins change the live values at /settings". `WORK_IN_PROGRESS.md`:
*Recently Completed: Settings (2026-10-06)*. `OPEN_QUESTIONS.md` #5 status
line: the ceiling numbers are now set at `/settings`, not in config.

Check: `grep -n "batchCeiling\|/settings" CLAUDE.md` → hits.

## Verification

1. `php artisan test` → **0 failed**; record the count.
2. `./vendor/bin/pint --test app tests database routes config` → clean.
3. `php artisan migrate:status | tail -1` shows the settings migration ran.
4. `php artisan route:list | wc -l` → previous + 2.
5. `git status --short` → only files this plan names (+ the staged archive).
6. Browser check from step 3 as admin, then as `office_test` `/settings` → 403
   page with "Back to the dashboard". Console clean.
7. Dev-DB probe: `settings` rows after the browser check — reset them so dev
   keeps the defaults (or leave Jon's real numbers if he has given them).

## Risks

- **Per-request cache** must be cleared by `set()` and must not leak across
  tests (`RefreshDatabase` resets the DB but not a static array —
  clear it in `set()` and, if needed, in a `tearDown`; prefer `once()` or an
  instance held on the container so each request/test starts clean).
- **JSON value** for an int: store `500`, read `(int)` — don't let `"500"`
  (a string) through validation into a comparison.
- **`AuthorizationBoundaryTest`**: the new routes sit in the admin group, so
  office is 403 — the matrix asserts that automatically; nothing to add
  unless a parameter appears.

## Review

Reviewed 2026-10-06 against `implemented.md` (Status: DONE, read to the end)
and the diff: model, controller, request, policy, migration, view, and the
integration points. Planner reran: `php artisan test` → **695 passed, 0
failed** (+11); `pint --test … config` → PASS 297 files; migration `[8] Ran`;
`route:list` → 166 (+2); dev `settings` empty (defaults), probe batch G061026
gone, 13 batches, 25 orders.

### Criteria — all five pass

1 `settings` (string PK, JSON value, `updated_by`); `Setting::get/set/
forget/flushCache/batchCeiling`; one query per request, cache keyed on the
`app()` instance so tests and long-lived workers start clean; three tests
incl. "five reads → 1 query". 2 `BatchController::store()` is the one read
site; stored 500 → 300 wheels save, 501 asks; cycle-8 cases unchanged.
3 Admin-only routes in the admin group; `SettingPolicy` denies all to
non-admins in `before()`; `SettingsRequest` carries the policy and the
`min:1|max:1000000` rules (reset mode validates only `reset`); page with
defaults, "changed by … on …", Save naming each change, Reset (PUT
`reset=1`, `data-confirm-danger`) shown only when something is stored; nav
in Admin; eight tests incl. office/factory 403, guest, inactive admin.
4 `KEEP`, `TABLES` + `ADDED_LATER['settings'] = false`, **and**
`import()` flushes the cache (red-checked). 5 Docs, config comment,
OPEN_QUESTIONS #5 status.

### Deviations — all accepted

1 API on the model; 2 app-keyed cache (better than `once()`, which can't
be cleared selectively); 3 Reset via `PUT reset=1` (keeps "+2 routes");
4 `forget()`; 5 `flushCache()` on restore — necessary; 6 a pre-settings
restore may leave `updated_by` dangling → the page says "a removed user";
values unaffected; fine.

### Notes for Planner — decisions

- Reset placement → fine as is.
- Jon's numbers go in at `/settings` → told him.
- A second setting would justify a small registry (key → label, rules,
  default) → noted in `TESTING_AND_FIXES.md` for whoever adds the next one.

Nothing to redo. Archive: `mkdir -p docs/planImp/archive/2026-10-06-settings`
and move `plan.md` + `implemented.md` there.
