# Cycle 19 — Dependencies: clear the Composer and npm audits — implementation

Status: DONE
Plan revision: 1
Implementer: Opus
Date: 2026-11-07

## Baseline
HEAD: 462a38a
Pre-existing dirty files:
```
 M docs/planImp/RESUME.md
?? docs/planImp/plan.md
```

## Steps
### 0. Baseline — done
```
Tests:    864 deprecated, 1 passed (5908 assertions)   # 865, 0 failed
PASS ... 311 files                                      # pint --test app tests database
170                                                     # route:list | wc -l
Found 61 security vulnerability advisories affecting 16 packages.
18 vulnerabilities (3 moderate, 12 high, 3 critical)    # npm audit
PHP 8.5.4 (cli)
```

### 1. Composer — done
Changed: `composer.json` (`php` `^8.2` → `^8.4`, nothing else), `composer.lock`;
Pint reformat (62 files); `tests/Feature/StatementBatchTest.php` (see Deviations).

`composer update` summary:
```
Lock file operations: 3 installs, 82 updates, 2 removals
  installs: symfony/polyfill-php84 1.43.0, symfony/polyfill-php85 1.43.0, symfony/polyfill-time 1.42.0
  removals: thecodingmachine/safe 3.4.0, webmozart/assert 1.11.0
```
(The plan's "164 packages moving" looks like a double count of lock + install lines; the real figure is 82 updates.)

`composer audit`:
```
No security vulnerability advisories found.
```
Selected transitive: dompdf/dompdf v3.1.6, guzzlehttp/guzzle 7.16.1, league/commonmark 2.10.7,
nesbot/carbon 3.15.1, symfony/http-foundation v7.4.23.

`php artisan about`: Laravel 12.71.0, PHP 8.5.4, Composer 2.10.3, env local, debug ENABLED.
(It also reports `Views ... CACHED` — that is compiled Blade, already the case before; nothing cached by me.)

| Direct (Composer)       | Before  | After   |
|-------------------------|---------|---------|
| barryvdh/laravel-dompdf | 3.1.2   | 3.1.2   |
| fakerphp/faker          | 1.24.1  | 1.24.1  |
| laravel/breeze          | 2.3.7   | 2.4.2   |
| laravel/framework       | 12.20.0 | 12.71.0 |
| laravel/pail            | 1.2.3   | 1.2.7   |
| laravel/pint            | 1.24.0  | 1.32.1  |
| laravel/sail            | 1.43.1  | 1.69.0  |
| laravel/tinker          | 2.10.1  | 2.11.1  |
| mockery/mockery         | 1.6.12  | 1.6.15  |
| nunomaduro/collision    | 8.8.2   | 8.9.5   |
| phpunit/phpunit         | 11.5.27 | 11.5.57 |

Pint 1.32 (`./vendor/bin/pint --test`, whole project) flagged 62 files; ran `./vendor/bin/pint`.
Diffstat (formatting only, before the StatementBatchTest fix): `62 files changed, 354 insertions(+), 222 deletions(-)` —
app 22, tests/Feature 35, routes/web.php, config/auth.php, bootstrap/providers.php, database/factories/UserFactory.php,
database/seeders/BatchSeeder.php. Fixers: `fully_qualified_strict_types` (inline `\Foo\Bar` → `use` + short name,
in code and docblocks), `ordered_imports`, `single_line_after_imports`, `unary_operator_spaces` /
`not_operator_with_successor_space`, `braces_position` / `single_line_empty_body`. Read the `-U0` hunks: every changed
line is an FQCN shortened to an imported name, a `use` line added/reordered, or spacing/braces. Checked the
collision-prone imports by hand (`OrderController` gets `Illuminate\Support\Collection` +
`Symfony\Component\HttpFoundation\Response` — neither name was already imported; `Order`/`EmailableDocument` get
`Illuminate\Support\Carbon`, which is what the FQCN said). `php -l` on all 62 files clean. No hunk reverted.

`php artisan test` after Pint: **1 failed** (`StatementBatchTest::test_a_failed_send_is_recorded_and_can_be_retried`,
`BadMethodCallException: Received Mockery_6_Illuminate_Mail_MailManager::getDefaultDriver(), but no expectations were
specified` at `MailFake.php:59`). Fixed in the test (Deviations). Then:
```
Tests:    864 deprecated, 1 passed (5908 assertions)    # 865, 0 failed
{"tool":"pint","result":"passed"}
```

### 2. npm — done
Changed: `package.json` (removed the `@tailwindcss/vite` line only — `git diff package.json` shows that one `-` line),
`package-lock.json`.
```
npm update  →  found 0 vulnerabilities
npm audit   →  found 0 vulnerabilities
```
`npm update` printed: `npm warn install-scripts 1 package has install scripts not yet covered by allowScripts:
esbuild@0.25.12 (postinstall: node install.js)` — npm 11.19 policy; the build below works without it (see Notes).

| Direct (npm)          | Before  | After   |
|-----------------------|---------|---------|
| @fontsource/* (4)     | 5.3.0   | 5.3.0   |
| @tailwindcss/forms    | 0.5.10  | 0.5.11  |
| @tailwindcss/vite     | 4.1.11  | removed |
| alpinejs              | 3.14.9  | 3.17.5  |
| autoprefixer          | 10.4.21 | 10.6.2  |
| axios                 | 1.10.0  | 1.21.0  |
| concurrently          | 9.2.0   | 9.2.5   |
| laravel-vite-plugin   | 1.3.0   | 1.3.0   |
| postcss               | 8.5.6   | 8.5.32  |
| puppeteer-core        | 25.16.0 | 25.16.0 |
| tailwindcss           | 3.4.17  | 3.4.19  |
| vite                  | 6.3.5   | 6.4.5   |

`npm run build`:
```
public/build/assets/app-CYMZ6nGM.css   84.80 kB │ gzip: 14.98 kB
public/build/assets/app-B6vulufF.js   117.82 kB │ gzip: 42.40 kB
(+ the self-hosted font files)
✓ built in 1.32s
```
`ls public/build/manifest.json` → present. `git status --short | grep -c public/build` → 0.

### 3. The pages still work — done
Headless pass (`puppeteer-core` from `node_modules`, `/usr/bin/google-chrome --no-sandbox`, 1280 × 900, built assets;
script at `/tmp/claude-1000/-var-www-html-mossfield/fb3282eb-5371-40d6-b796-2e248f25c324/scratchpad/pages.mjs`):
```
== office_test landed on http://mossfield.local/dashboard
office_test   200 /dashboard               1280/1280 alpine=true
office_test   200 /orders                  1280/1280 alpine=true
office_test   200 /orders/9                1280/1280 alpine=true
office_test   200 /chilled-runs            1280/1280 alpine=true
office_test   200 /invoicing               1280/1280 alpine=true
office_test   200 /invoicing/invoices/2    1280/1280 alpine=true
office_test   200 /customers/25            1280/1280 alpine=true
office_test   200 /products/3              1280/1280 alpine=true
office_test   200 /batches                 1280/1280 alpine=true
office_test   200 /stock                   1280/1280 alpine=true
office_test   200 /cheese-conversion       1280/1280 alpine=true
office_test   200 /help/finance            1280/1280 alpine=true
office_test   403 /settings                1280/1280 alpine=false     # admin-only, expected (error page has no Alpine)
dialog: trigger title="Remove this special price?" → visible heading="Remove this special price?" cancel buttons=["Cancel"]
dialog cancelled: url unchanged=true, cancel buttons still visible=0
office errors: [ '[/settings] Failed to load resource: the server responded with a status of 403 (Forbidden)' ]
== factory_test landed on http://mossfield.local/picking
factory_test  200 /picking                 1280/1280 alpine=true
factory_test  200 /orders/9                1280/1280 alpine=true
factory_test  200 /stock                   1280/1280 alpine=true
factory_test  200 /help/stock              1280/1280 alpine=true
factory errors: none
```
The only console error is the office's own `/settings` 403 document load. No `pageerror`, no overflow. The dialog
(`data-confirm` on the customer page's special-price Remove) opened with its title and was cancelled; nothing posted.

Guide driver:
```
$ node docs/guide/screenshot.mjs sidebar-help
✓ docs/guide/images/sidebar-help.png
$ git checkout docs/guide/images/sidebar-help.png   →  Updated 0 paths from the index
$ git status --short docs/guide                     →  (empty)
```
The file was rewritten (mtime 20:08:32) and came out byte-identical to HEAD, so there was nothing to restore.

PDFs:
```
php artisan test --filter="DispatchConfirmationTest|InvoiceIssueTest|StatementTest|CreditNoteTest"
Tests:    59 deprecated (233 assertions)    # 59, 0 failed
```

### 4. Docs — done
- `CLAUDE.md:26` → "Laravel 12 with PHP 8.4+ (8.5 on both boxes)".
- `DEPLOYMENT.md:11` → "**PHP 8.4+** (the lock's Symfony 8 components need 8.4.1; mossserv runs 8.5) with extensions: …".
- `DEPLOYMENT.md:334` `php8.3-fpm` → `php8.5-fpm`. The `ssh mossserv 'systemctl list-units …'` check was **refused by
  the session's permission classifier** (production host), so I did not run it. Evidence used instead: the vault
  note's Environment section records mossserv as "PHP-FPM 8.5 (socket `/run/php/php8.5-fpm.sock`)". The Planner
  may want to confirm with the ssh command.
- `deploy/INSTALL.md` §3: added the one sentence after the heading. Line 3 and the package lists untouched.

### 5. Final — done
```
./vendor/bin/pint --test | tail -1              → {"tool":"pint","result":"passed"}
php artisan test | tail -3                      → Tests: 864 deprecated, 1 passed (5908 assertions)   # 865, 0 failed
php artisan route:list | wc -l                  → 171   (plan expected 170 — see Deviations)
composer audit | tail -2                        → No security vulnerability advisories found.
npm audit | tail -1                             → found 0 vulnerabilities
composer validate --no-check-publish | tail -1  → ./composer.json is valid
```

## Deviations
1. **`tests/Feature/StatementBatchTest.php`** — forced by `laravel/framework` 12.20 → 12.71. `MailFake::__construct()`
   now calls `$manager->getDefaultDriver()` on the facade root. The test sets `Mail::shouldReceive('to->send')`
   (root becomes a Mockery mock of `MailManager`) and later calls `Mail::fake()`, which hands the fake that mock →
   `BadMethodCallException`. Fix: put a real manager back before faking —
   `Mail::swap(new MailManager($this->app)); Mail::fake();` (+ `use Illuminate\Mail\MailManager;`, a 4-line comment).
   The test's assertions are unchanged; it still exercises the failing send and the retry.
2. **Route count 171, not 170.** The extra route is framework-provided: Laravel 12.x's filesystem provider now
   registers `PUT storage/{path}` (`storage.local.upload`, `ReceiveFile`) beside the existing `GET storage/{path}`
   for the `local` disk, which has `'serve' => true` in `config/filesystems.php`. `ReceiveFile` refuses unless the
   request has `?upload=1` and a valid relative signature (403 in local, 404 in production). Checked on dev: an unsigned
   `curl -X PUT --data x http://mossfield.local/storage/probe.txt` → **403**, no file written. `AuthorizationBoundaryTest`
   already exempts `storage*` URIs (`:483`), which is why the suite stays green. App routes (`--except-vendor`) = 168,
   unchanged. No code changed for this.
3. **Step 4 ssh check not run** (permission refusal, above); `php8.5-fpm` was taken from the vault note.

## Verification
As step 5 above: audits clean (Composer none, npm 0), `composer validate` valid, 865 tests / 0 failed, Pint clean on
the whole project, routes 171 (explained), `git diff composer.json package.json` = the `php` range line and the removed
`@tailwindcss/vite` line only. No `public/build`, `vendor`, `node_modules` or `docs/guide` path in `git status`.

## Files changed
`git status --short` at the end (72 entries). Pre-existing (not mine): ` M docs/planImp/RESUME.md`, `?? docs/planImp/plan.md`.
Mine:
```
 M CLAUDE.md  M DEPLOYMENT.md  M deploy/INSTALL.md
 M composer.json  M composer.lock  M package.json  M package-lock.json
 M bootstrap/providers.php  M config/auth.php  M routes/web.php
 M database/factories/UserFactory.php  M database/seeders/BatchSeeder.php
 M app/… (22 files, Pint only)
 M tests/Feature/… (35 files, Pint only; StatementBatchTest also has the Deviation 1 fix)
?? docs/planImp/implemented.md
```
`git diff --stat` total: 70 files changed, 2616 insertions(+), 2971 deletions(-) (mostly the two lock files).

## Notes for Planner
- **`PUT storage/{path}` now exists** (Deviation 2). It is signature-gated, so not a hole, but nothing in the app uses
  local-disk temporary upload URLs. If you would rather not have it, `'serve' => false` on the `local` disk removes both
  storage routes — but check nothing relies on `GET storage/{path}` first. Not touched.
- **npm 11.19 `allowScripts`**: `npm update` warned that `esbuild@0.25.12`'s postinstall is not approved. The build
  works without it (esbuild ships its binary via the optional `@esbuild/linux-x64` package). Worth watching the
  `npm ci && npm run build` step on mossserv during the ship, in case the box's npm behaves differently.
- `php artisan about` lists `Views ... CACHED` on dev. That is the compiled-Blade cache, which was already there before. I
  did not run `view:cache`.
- The plan's "164 packages moving" was really 82 updates + 3 installs + 2 removals.
- `barryvdh/laravel-dompdf` stayed at 3.1.2 (already newest within `^3.1`); the patch landed in `dompdf/dompdf` 3.1.6.
