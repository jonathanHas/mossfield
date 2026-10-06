# Settings: batch ceilings editable by admin — implementation

Status: DONE
Plan revision: 1
Implementer: Opus
Date: 2026-10-06

## Baseline
HEAD: 512811b (the plan's Context says `e3184a8` + a staged archive; that archive has since been committed as `512811b`)
Pre-existing dirty files: clean
```
$ php artisan test
  Tests:    684 passed (4333 assertions)
$ ./vendor/bin/pint --test app tests database routes config
(clean — no output)
$ php artisan route:list | wc -l
164
```

## Steps

### 1. A settings store with config defaults — done
**The static API lives on the model** (`App\Models\Setting`); there is no
separate `app/Support/Settings.php`.

Changed:

**new** `database/migrations/2026_10_06_120000_create_settings_table.php`
- `key` string(80) primary, `value` text (JSON), `updated_by` FK users
  nullable `nullOnDelete`, timestamps.

**new** `app/Models/Setting.php`
- string primary key, non-incrementing; `value` cast `json`;
  `updatedBy()` relation.
- `get($key, $default)`, `set($key, $value, ?User $by)`, `forget($key)`
  (for Reset), `flushCache()`, and
  `batchCeiling($type) = (int) get("batch_ceilings.$type", config(...))`.
- **Per-request cache:** all rows are read once (`rows()`, keyed by `key`)
  and held in a static that is **tied to the `app()` instance**. A new
  application — each test, or each request under a long-lived worker —
  re-reads, so nothing leaks between tests (the plan's risk) without a
  `tearDown`. `set()` and `forget()` clear it.

**new** `tests/Feature/SettingsTest.php` (3):
- `get` falls back (null, or the given default; cheese 200, milk 5000 from
  config);
- a stored value wins:
  - `set()` clears a warmed cache;
  - it survives `flushCache()` (from the DB);
  - `updated_by` is recorded;
  - the raw column holds JSON `500`;
  - `forget()` reverts to the default;
- five `batchCeiling()` reads → **1 query**.
Check output:
```
$ php artisan migrate
  2026_10_06_120000_create_settings_table ...................... 110.80ms DONE
$ php artisan test --filter=SettingsTest
  Tests:    3 passed (11 assertions)
```

### 2. The batch form reads the setting — done
Changed:
- `app/Http/Controllers/BatchController.php::store()`: `$ceiling =
  Setting::batchCeiling($product->type)` — one call, which reads every
  setting in one query. The comment points at `/settings`. The
  per-item error text is unchanged.
- `resources/views/batches/create.blade.php`: the comment names
  `Setting::batchCeiling()` / `/settings` plus the config defaults.
- `tests/Feature/BatchCreateTest.php` +1: with `batch_ceilings.cheese` stored
  as 500, 300 wheels save with no prompt; 501 is refused with "501 × Whole
  Wheel — the usual ceiling is 500. …". The cycle-8 cases (no row → 200 /
  5000) pass unchanged.
Red check (HEAD's controller): `⨯ an admin set ceiling replaces the default — 1 failed`.
Check output:
```
$ php artisan test --filter=BatchCreateTest
  Tests:    17 passed (95 assertions)
```

### 3. The Settings page — done
Changed:

Routes, policy, request
- `routes/web.php`, in the **`role:admin` group** after Backup:
  `GET /settings` (`settings.index`) and `PUT /settings` (`settings.update`).
- **new** `app/Policies/SettingPolicy.php`: mirrors `UserPolicy`.
  `before()` returns true for an active admin and false for everyone else;
  `viewAny`/`update` return false.
- `app/Providers/AuthServiceProvider.php` registers it.
- **new** `app/Http/Requests/SettingsRequest.php`:
  - `authorize()` = `can('update', Setting::class)`;
  - rules: `batch_ceilings.{milk,yoghurt,cheese}` each
    `required|integer|min:1|max:1000000` (attributes "Milk ceiling" etc.);
  - with `reset=1` the only rule is `reset: boolean`;
  - `CEILING_TYPES` lists the three types once.

**new** `app/Http/Controllers/SettingsController.php`
- `index()`: per type, the current value (`Setting::batchCeiling`), the
  config default, and the stored row with `updatedBy` (one eager-loaded
  query).
- `update()`:
  - **reset** forgets the three keys ("Batch ceilings reset to the
    defaults.", or "…already the defaults." if none were stored);
  - otherwise it `set()`s **only the values that changed** and flashes
    "Batch ceilings saved — cheese 200 → 500." (changes joined with ", "),
    or "No changes.";
  - it always redirects to `settings.index`.

**new** `resources/views/settings/index.blade.php`
- Title "Settings" and one panel **Batch quantity ceilings** with the plan's
  sentence.
- Three number inputs (Milk / Yoghurt / Cheese); help text "default 5 000",
  plus "· changed by {name} on {d/m/Y}" when stored.
- A **Save** button.
- **Reset to defaults**, shown only when something is stored: a second form
  `PUT /settings` with `reset=1`, `data-confirm` listing the defaults,
  `data-confirm-danger`.

`resources/views/layouts/navigation.blade.php`
- An Admin-group item "Settings" with a new `settings` (cog) icon in the
  icon map. The Admin group is already admin-only.

**Reset uses the PUT route** (`reset=1`) rather than a third route, so the
plan's "routes + 2" holds. See Deviations.

`tests/Feature/SettingsTest.php` +5:
- admin sees the defaults (values, "default 5 000", no "changed by", no
  Reset, the nav link);
- save stores only the changed value, names it, and the page shows "500",
  "changed by Jon Admin on 06/10/2026" and Reset; a resave → "No
  changes."; two changes are named in order;
- 0, -5, '', 'abc', 2.5 and 1000001 are each refused, nothing stored;
- Reset drops all three rows → 200;
- office and factory get 403 on GET and PUT and have no nav link; guest →
  login; an inactive admin → login.
Check output:
```
$ php artisan test --filter="SettingsTest|AuthorizationBoundaryTest|ConfirmDialogTest|LayoutFeedbackTest"
  Tests:    40 passed (1569 assertions)
```
(Two failures on the way, both mine: a "changed by … on …" line split across
two Blade lines, and a test payload built with `+` that kept the left-hand
`batch_ceilings`, so the bad value was never sent.)
Browser: see *Browser checks*.

### 4. Housekeeping — done
Changed:

`app/Console/Commands/ResetTradingData.php`
- `settings` is in `KEEP` (configuration, not trading data).

`app/Services/BackupService.php`
- `TABLES` gains `settings` right after `users` (it references them).
- `ADDED_LATER['settings'] = false`: a backup from before this cycle leaves
  the current settings alone. The docblock names it.
- **`import()` now calls `Setting::flushCache()`** after the transaction.
  Not in the plan — see Deviations.

`tests/Feature/BackupRestoreTest.php` +2:
- **Round trip**: back up with cheese 450; then set cheese 999 and milk 10
  and warm the cache (999). After restoring, cheese is **450** (with its
  `updated_by`) and milk is back to the default 5000, because a row added
  since the backup goes.
- **Pre-settings backup**: `settings` is stripped from the payload, cheese
  450 is set afterwards, and the restore keeps 450.

Red check (the `flushCache()` line removed): `⨯ a stored setting round trips
— 1 failed`. In the same request the restore left the stale 999 in the
cache.
Check output:
```
$ php artisan test --filter="ResetTradingDataTest|BackupRestoreTest"
  Tests:    25 passed (136 assertions)
```

### 5. Docs — done
Changed:
- `CLAUDE.md`:
  - a **Settings** bullet under Security Posture → Roles & authorization:
    - admin-only `/settings`; `SettingPolicy` mirrors `UserPolicy`;
    - config = defaults, never written;
    - `Setting::get/set/forget`, one query per request with the cache cleared
      by set/forget/import;
    - `batchCeiling()` is the one read site;
    - the page's behaviour, including Reset as `PUT reset=1`;
    - the housekeeping lists;
    - how to add the next value;
  - the *Batch create rules* paragraph now says the ceiling is read via
    `Setting::batchCeiling()`, set at `/settings`, with the config values as
    defaults.
- `config/mossfield.php`: the ceilings comment says these are the
  **defaults** and that admins change the live values at `/settings`.
- `docs/planImp/WORK_IN_PROGRESS.md`: *Recently Completed: Settings
  (2026-10-06)*.
- `docs/planImp/OPEN_QUESTIONS.md` #5 status: the ceiling numbers are now
  set at `/settings`; config holds only the defaults.
Check:
```
$ grep -n "batchCeiling\|/settings" CLAUDE.md
270:- **Settings** (`/settings`, admin-only — …
355:**Batch create rules** (`BatchController::store()`, OPEN_QUESTIONS #5): (1) … `Setting::batchCeiling($type)` … `/settings` …
```

## Browser checks
**As office (`office_test`)** — you signed the browser in on
`mossfield.local`:
- `/settings` → the 403 page "You don't have access to that page.", with
  **Back to the dashboard**;
- the dashboard nav has no Settings link and no Admin group;
- console clean.

**As admin** — I ran `php artisan serve` on `127.0.0.1:8010` (same `.env`,
same dev DB) and signed in there as the seeded `admin` test account. That
left your office session on `mossfield.local` alone, and `127.0.0.1` is a
local host where I may sign in with seed credentials.
1. `/settings`: one panel, Milk 5000 / Yoghurt 3000 / Cheese 200, each with
   "default 5 000" / "default 3 000" / "default 200"; no Reset button;
   **Settings** is in the Admin nav.
2. Typed 500 into Cheese → **Save** → flash "Batch ceilings saved — cheese
   200 → 500.". Cheese reads 500 with "default 200 · changed by Admin on
   06/10/2026", and **Reset to defaults** appears.
3. `/batches/create`, Farmhouse, **300 wheels**, 3000 L, notes
   "E2E-20261006 cycle10 settings ceiling probe" → saved straight away as
   **G061026** (batch 30): "Batch G061026 created successfully! 300 wheels
   produced.". No prompt, no confirm box, no "(large batch confirmed)".
4. `/settings` → **Reset to defaults** → the confirm dialog read "Reset the
   batch ceilings to the defaults (milk 5 000, yoghurt 3 000, cheese 200)?"
   → **Reset** → "Batch ceilings reset to the defaults."; Cheese 200, meta
   "default 200", the Reset button gone.
5. Console clean.

My first click on Log in didn't submit (the server log showed no POST);
pressing Return in the form worked.

## Dev-DB state after the probe
- `settings`: **0 rows** — reset in the browser, so dev keeps the defaults.
  Jon hasn't given his numbers.
- The probe batch **G061026 (id 30)** was removed with tinker after the
  check:
  - its notes matched the tag;
  - `deletionBlocker()` was null (no orders, cuts or write-offs);
  - its item and the batch were deleted in one transaction.
  300 phantom wheels on dev would have skewed every stock figure.
- Migration `2026_10_06_120000_create_settings_table` has run on
  `mossfield_cl`.
- The temporary server on `127.0.0.1:8010` is stopped (curl → 000).

## Deviations
1. **The static API is on the model** (`App\Models\Setting`), not a separate
   `app/Support/Settings.php`. The plan offered either and asked me to say
   which.
2. **Per-request cache tied to the `app()` instance** rather than `once()`.
   - `once()` can't be cleared selectively by `set()`.
   - A plain static would leak between tests.
   - Keyed on the application object, every new app (each test; each request
     under a long-lived worker) re-reads, and `set()` / `forget()` clear it
     within a request.
3. **Reset is `PUT /settings` with `reset=1`**, not a separate route. The
   plan has two routes and "route:list → previous + 2" (164 → 166 holds).
   `SettingsRequest` skips the ceiling rules when `reset` is set.
4. **`Setting::forget()`** was added for Reset. It deletes a row and clears
   the cache.
5. **`BackupService::import()` calls `Setting::flushCache()`.** Not in the
   plan: without it a restore left the pre-restore values cached for the
   rest of that request. The red check shows the round-trip test failing
   without it.
6. **Restoring a pre-settings backup can leave `updated_by` dangling.**
   Restore runs with FK checks off, so `nullOnDelete` doesn't fire. The
   kept `settings` rows can then point at a user id the restore removed.
   The page shows "changed by a removed user" in that case; values are
   unaffected. Not worth more for a three-number page.

## Verification
1. `php artisan test` → **695 passed (4439 assertions), 0 failed** (67.1 s).
   Baseline 684 → +11:
   - SettingsTest +8;
   - BatchCreate +1;
   - BackupRestore +2.
2. `./vendor/bin/pint --test app tests database routes config` → clean.
3. `php artisan migrate:status | tail -1` →
   `2026_10_06_120000_create_settings_table … [8] Ran`.
4. `php artisan route:list | wc -l` → **166** (baseline 164, +2).
5. `git status --short`: only the files below, plus `implemented.md`. The
   staged archive is already committed as 512811b.
6. Browser: admin flow and office 403 as above; the console was clean.
7. Dev-DB probe: `settings` is empty (defaults); the probe batch was
   removed.

No `npm run build` was needed (no CSS/JS changed). **Nothing committed,
pushed or deployed.**

## Files changed
New:
- `database/migrations/2026_10_06_120000_create_settings_table.php`
- `app/Models/Setting.php`
- `app/Policies/SettingPolicy.php`
- `app/Http/Requests/SettingsRequest.php`
- `app/Http/Controllers/SettingsController.php`
- `resources/views/settings/index.blade.php`
- `tests/Feature/SettingsTest.php`

Modified:
- `app/Http/Controllers/BatchController.php`
- `app/Providers/AuthServiceProvider.php`
- `app/Services/BackupService.php`
- `app/Console/Commands/ResetTradingData.php`
- `routes/web.php`
- `config/mossfield.php`
- `resources/views/batches/create.blade.php`
- `resources/views/layouts/navigation.blade.php`
- `tests/Feature/BatchCreateTest.php`, `tests/Feature/BackupRestoreTest.php`
- `CLAUDE.md`, `docs/planImp/WORK_IN_PROGRESS.md`,
  `docs/planImp/OPEN_QUESTIONS.md`, this file.

## Notes for Planner
- **Reset sits under Save** in its own small form, because the forms can't
  nest. It reads fine but is slightly detached; it could move into the
  panel header if you prefer.
- **Jon's real numbers** go in at `/settings` now. Nothing in config needs
  touching.
- **Adding the next setting** = a config default + a key + a field. The
  controller and request are ceiling-specific for now. A second setting
  would justify a small "registry" (key → label, rules, default) rather
  than another hand-written block.
