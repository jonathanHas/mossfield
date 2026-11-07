# Cycle 19 — Dependencies: clear the Composer and npm audits before real use

Status: ACCEPTED
Revision: 1
Planner: Fable 5.1
Date: 2026-11-07

Read `docs/planImp/planimp.md` first. The Implementer writes
`docs/planImp/implemented.md`; it does not edit this file.

## Goal

Bring every dependency to the newest version its **existing** constraint
allows, so that `composer audit` and `npm audit` both report nothing, the
suite stays green, the pages render as before, and production — which is
on the LAN and about to be used — is not running a framework with five
open advisories. No major-version upgrades: Laravel stays 12.x, Vite 6.x,
Tailwind 3.x, PHPUnit 11.x. Those are a separate job (vault `Todo.md`).

Three things:

1. **Composer**: `composer update` within the current `composer.json`
   constraints. I resolved the lock in a scratch copy: `laravel/framework`
   12.20 → **12.71**, `guzzlehttp/guzzle` 7.9 → 7.16, `league/commonmark`
   2.7 → 2.10, `dompdf/dompdf` 3.1.5 → 3.1.6, `symfony/*` 7.3 → 7.4 (a few
   transitive ones to 8.1), `nesbot/carbon` 3.10 → 3.15, `phpunit/phpunit`
   11.5.27 → 11.5.57, `laravel/pint` 1.24 → 1.32, plus ~70 others —
   164 packages moving, 2 removed, 3 added. **`composer audit --locked` on
   that lock: "No security vulnerability advisories found"** (today: 61
   advisories across 16 packages).
2. **npm**: drop the unused `@tailwindcss/vite` (Tailwind 3 runs through
   PostCSS here — `postcss.config.js`; nothing imports it) and
   `npm update` within ranges: `vite` 6.3 → 6.4, `axios` 1.10 → 1.21,
   `alpinejs` 3.14 → 3.17, `tailwindcss` 3.4.17 → 3.4.19, `postcss`,
   `autoprefixer`, `concurrently`, `puppeteer-core`. Same scratch probe:
   **`npm audit` → 0** (today: 18 — 3 critical, 12 high, 3 moderate, all
   with fixes inside the current ranges).
3. **Say what PHP the app now needs.** The resolved lock carries
   `symfony/string`, `symfony/translation`, `symfony/event-dispatcher`,
   `symfony/clock` at 8.1, which require **PHP ≥ 8.4.1**. Both boxes run
   **8.5.4** (dev and mossserv — checked), so nothing changes in practice,
   but `composer.json` says `^8.2` and the docs say "8.2+" / "8.3". Make
   them honest: `^8.4`.

## Context

**Baseline.** HEAD `462a38a` (cycle 18 shipped; mossserv on the same
commit, migration ran, box up). **865 tests, 0 failed** (5,908
assertions); `pint --test app tests database` clean (311 files);
`php artisan route:list | wc -l` → 170. Working tree: `M
docs/planImp/RESUME.md` (Planner's — leave it) and this file.

**Toolchain.** PHP 8.5.4 and Composer 2.10 on both boxes; Node 22 on
mossserv. The deploy (`deploy/bin/deploy-on-prod:119-125`) runs
`composer install --no-dev --optimize-autoloader` and `npm ci && npm run
build` on the box from the committed `composer.lock` / `package-lock.json`,
so shipping the two lock files is the whole deployment. `public/build` is
gitignored — never commit it.

**What is actually vulnerable.** Advisories by package today:
`league/commonmark` 14 (renders the user guide at `/help` — an office user
reading Markdown we wrote, so low exposure, but it is the biggest count),
`guzzlehttp/guzzle` 9 + `psr7` 4 (the Mossorders sync client — unused
until a portal exists), `symfony/http-foundation` 6, `dompdf/dompdf` 6
(every PDF), `symfony/yaml` 5, `laravel/framework` 5, `symfony/routing`
3, `symfony/mime` 2, one each in `symfony/process`, `polyfill-intl-idn`,
`mailer`, `http-kernel`, `psy/psysh`, `phpunit/phpunit`,
`league/flysystem`. The `composer.json` constraints already permit the
fixed versions — nobody has run `composer update` since the project was
scaffolded (framework 12.20 is from mid-2025).

**No config to republish.** All moves are minor/patch within the
constraints; Laravel 12.x minors add no required config changes.
`config/dompdf.php` is published (font subsetting) — a 3.1.6 patch does
not touch it. Breeze 2.4 changes only its scaffolding command, not any
file in this repo.

**Pint 1.32 may reformat.** Nine minor releases of Pint add or tighten
rules under the default `laravel` preset (there is no `pint.json`). Expect
`./vendor/bin/pint` to touch files that have not changed in meaning. That
is fine and expected — commit the result; it is a formatter.

**PHPUnit output.** Every test already prints as "deprecated" because of
the pre-existing `PDO::MYSQL_ATTR_SSL_CA` notice; the count and wording
may shift with 11.5.57. Only **failed** matters.

**Carbon 3.10 → 3.15.** `Batch::maturation_status` (`Batch.php:199`) uses
`diffInDays(..., false)` and `diffForHumans()`; `Batch::isReadyToSell()`
uses `lte(now())`. These are stable in Carbon 3; the suite covers them
(`StockOverviewTest`, `BatchCreateTest`, `CheeseConversionTest`).

**The guide's driver.** `docs/guide/screenshot.mjs` uses `puppeteer-core`
(`^25.16`) against `/usr/bin/google-chrome`. `npm update` may move it
within 25.x; step 3 proves it still drives Chrome by shooting one picture
and then restoring the file (a re-shoot is not this cycle's job, and dev
data has drifted since the 18 shoot).

## Constraints

- **Within existing constraints only.** Do not edit any version range in
  `composer.json` or `package.json` except: the `php` field (`^8.2` →
  `^8.4`) and the removal of `@tailwindcss/vite`. No `--with`, no
  `composer require`, no `npm install <pkg>@latest`. If an audit advisory
  survives a within-range update, report it; don't widen a range to chase
  it.
- **No application code changes** beyond what Pint reformats and what a
  genuine breakage from a dependency forces (none expected — if one
  appears, fix the smallest thing and say so under Deviations).
- **Nothing on production.** Dev only. Jon ships.
- **Dev DB untouched** — no migrations, no seeding, no data.
- `public/build` stays uncommitted; `docs/guide/images/*` stays as at
  HEAD (step 3's test picture is restored with `git checkout`).
- Do not run `php artisan optimize` or cache config/routes/views on dev.

## Out of scope

- Laravel 13, PHPUnit 12/13, Vite 7/8, Tailwind 4, `laravel-vite-plugin`
  3, `concurrently` 10, `laravel/tinker` 3 — all majors, vault `Todo.md`.
- Upgrading PHP itself (both boxes are already on 8.5).
- The two sibling apps (`osmanagercl`, `timekeepercl`) — same Todo item,
  separate repos, separate cycles.
- Re-shooting the guide.

## Steps

### 0. Baseline

```
php artisan test | tail -3        # 865, 0 failed
./vendor/bin/pint --test app tests database | tail -1
php artisan route:list | wc -l    # 170
composer audit | tail -2          # 61 advisories, 16 packages
npm audit | tail -1               # 18 vulnerabilities
git log --oneline -1              # 462a38a
git status --short                # M docs/planImp/RESUME.md, ?? docs/planImp/plan.md
php -v | head -1                  # 8.5.4
```

### 1. Composer

1. In `composer.json`, change `"php": "^8.2"` to `"php": "^8.4"`. Nothing
   else in the file.
2. `composer update` (no arguments; it resolves within the constraints and
   installs). Record the summary line ("Upgrading N, …") and the direct
   dependencies' new versions from `composer show --direct`.
3. `composer audit` → expect "No security vulnerability advisories found".
   If anything remains, list it with the package, the advisory id and the
   constraint that pins it — and stop there for that package.
4. `php artisan about | head -20` — Laravel version 12.71.x, PHP 8.5.4,
   environment local, debug on. Sanity only.
5. `./vendor/bin/pint --test` (whole project this time — the new version
   may want changes in `routes`, `config`, `bootstrap` too). If it reports
   files, run `./vendor/bin/pint` and record `git diff --stat` for the
   formatting changes separately from the rest. **Read the diff.** It must
   be whitespace, ordering, trailing commas and the like — if Pint changed
   anything that alters behaviour (it should not), revert that hunk and
   note it.
6. `php artisan test` → **865 tests, 0 failed**. Any failure is real;
   diagnose it against the package that moved (the usual suspects are a
   Carbon comparison, a changed exception class, a stricter validation rule
   in Laravel). Fix the smallest thing in `app/` or `tests/` and record it
   under Deviations with the package and version that forced it.

### 2. npm

1. In `package.json`, remove the `"@tailwindcss/vite"` line from
   `devDependencies`. Nothing else in the file.
2. `npm update` (within ranges). Then `npm audit` → expect
   "found 0 vulnerabilities". Record `npm ls --depth=0` for the direct
   packages.
3. `npm run build` → completes; record the output's asset list (two or
   three files under `public/build/assets/`). Then `git status --short`
   must **not** list `public/build` (it is ignored) — if it does, stop and
   say so.
4. `npm run dev` is **not** needed; the built assets are what the headless
   check below serves. Confirm Vite's manifest exists:
   `ls public/build/manifest.json`.

### 3. The pages still work

A read-only headless pass against the **built** assets (nothing pressed,
nothing posted) — reuse the cycle-18 shape: `puppeteer-core` from the
project's `node_modules`, `/usr/bin/google-chrome`, `--no-sandbox`,
1280 × 900, log in as `office_test` then `factory_test` (password
`admin123`), collect `console` errors and `pageerror`s, and for each page
record status, `scrollWidth/clientWidth` and whether Alpine booted
(`typeof window.Alpine === 'object'`):

- office: `/dashboard`, `/orders`, `/orders/9`, `/chilled-runs`,
  `/invoicing`, `/invoicing/invoices/2`, `/customers/25`, `/products/3`,
  `/batches`, `/stock`, `/cheese-conversion`, `/help/finance`,
  `/settings` (admin — skip for office, it 403s: note it).
- factory: `/picking`, `/orders/9`, `/stock`, `/help/stock`.

Expect every page 200 (403 only where the role says so), **no console
errors except the factory's own 403 resource loads**, no `pageerror`,
`Alpine` present, no horizontal overflow. Then open one dialog and cancel
it — the sidebar's Help link or any `data-confirm` button — to prove
`confirm.js` + Alpine still run under the rebuilt bundle. Do not press
OK on anything.

Then the guide driver: `node docs/guide/screenshot.mjs sidebar-help` →
"1 ✓" (or the script's equivalent). It will have rewritten
`docs/guide/images/sidebar-help.png`; `git checkout
docs/guide/images/sidebar-help.png` afterwards and confirm
`git status --short docs/guide` is empty. This proves `puppeteer-core`
still drives Chrome after the update; the picture itself is not shipped.

PDFs: `php artisan test --filter="DispatchConfirmationTest|InvoiceIssueTest|StatementTest|CreditNoteTest"`
— the dompdf patch and the font-subsetting size assertion (< 300 KB).
(Already inside step 1.6's full run; repeat the filter here only if step
1.6 was run before `npm` — it is independent of npm either way.)

### 4. Docs

- `CLAUDE.md:26` "Laravel 12 with PHP 8.2+" → "Laravel 12 with PHP 8.4+
  (8.5 on both boxes)".
- `DEPLOYMENT.md:11` "**PHP 8.2+**" → "**PHP 8.4+** (the lock's Symfony 8
  components need 8.4.1; mossserv runs 8.5)". Leave the `php8.3-fpm`
  reload line at `:334` alone if that is still the service name on the
  box — check with `ssh mossserv 'systemctl list-units --type=service |
  grep php'` and update the version in that one line to match (it is
  almost certainly `php8.5-fpm` now).
- `deploy/INSTALL.md:3` and §3 heading: add one sentence after the §3
  heading — "The app now needs PHP ≥ 8.4; substitute the version you
  install for `8.3` in the commands below (mossserv runs 8.5)." Do not
  rewrite the package lists.
- `docs/planImp/SMALL_FIXES.md`: nothing. `OPEN_QUESTIONS.md`: nothing.
- Vault `Todo.md` is Jon's; the Planner ticks the mossfield part of the
  Composer item after review. Don't touch it.

### 5. Final

```
./vendor/bin/pint --test | tail -1             # clean, whole project
php artisan test | tail -3                     # 865, 0 failed
php artisan route:list | wc -l                 # 170
composer audit | tail -2                       # none
npm audit | tail -1                            # 0
composer validate --no-check-publish | tail -1 # valid (lock in sync)
git status --short
```

`git status` should list: `composer.json`, `composer.lock`, `package.json`,
`package-lock.json`, `CLAUDE.md`, `DEPLOYMENT.md`, `deploy/INSTALL.md`,
whatever Pint reformatted, `docs/planImp/RESUME.md` (pre-existing),
`docs/planImp/implemented.md`, and this file — and **not** `public/build`,
`vendor`, `node_modules`, or any `docs/guide/images` file.

In `implemented.md`, give the before/after of every **direct** dependency
(Composer and npm, one table each), the Pint diffstat, and the audit lines
verbatim.

## Verification (Planner reruns after implementation)

1. `composer audit` → none; `npm audit` → 0; `composer validate` → valid.
2. `php artisan test` → 865, 0 failed; `pint --test` clean; routes 170.
3. `git diff composer.json package.json` shows exactly the `php` range and
   the removed `@tailwindcss/vite` line, nothing else.
4. `composer show --direct` / `npm ls --depth=0` match the tables in
   `implemented.md`; no direct package crossed a major.
5. Own read-only headless pass (office + factory, the step-3 list), fresh
   `npm run build` first.
6. `git status` lists no build output, no vendor, no picture.

## Risks

- **Pint touches many files.** Expected; a reformat is not a regression.
  The reviewer reads the diffstat, not every hunk — the suite is the
  behaviour check.
- **A transitive Symfony 8 component on PHP 8.5** — both already true on
  dev (the probe lock resolved fine); the `php` range bump just makes
  `composer install` say so on an older box instead of failing at runtime.
- **The production `composer install`** swaps `vendor/` under the app; the
  ship's maintenance mode covers it (ship **with** maintenance, the
  default — no migration, but a vendor swap is not a no-op either). The box
  has reached Packagist before (it installed the current lock), ~165
  downloads this time — expect the remote step to take a few minutes.
- **`npm ci` on the box** rebuilds from the new lock; Node 22 is fine for
  Vite 6.4. If the remote build failed, the previous `public/build` would
  still be in place — but the deploy script's behaviour on a failed step
  is Jon's to watch, so note in the ship message that the remote step is
  longer than usual.
- **dompdf 3.1.6** could change glyph metrics by a hair; the size test is
  a ceiling, not an exact figure.
- **Alpine 3.17** — `x-on:input.debounce`, `$watch`, `x-data` components
  and `$el.form.requestSubmit()` are all core; nothing here uses a plugin.
  The headless pass is the check.
- Anything that cannot be cleared within its range (none in the probe) is
  reported, not chased.

## Review

**ACCEPTED** — Planner (Fable 5.1), 2026-11-07, against `implemented.md` (DONE, revision 1).

Reran independently: `composer audit` → none; `npm audit` → 0;
`composer validate` → valid; `pint --test` (whole project) → passed;
`php artisan test` → **865 tests, 0 failed (5,908 assertions)**; routes
171 (168 `--except-vendor`, unchanged — the extra one is Laravel 12.x's
signature-gated `PUT storage/{path}`, see below). `git diff composer.json
package.json` is exactly the `php` range line and the removed
`@tailwindcss/vite` line. Direct versions match the two tables; no direct
package crossed a major. Checked the Pint diff for anything beyond
import/FQCN/spacing (nothing) and for ambiguous `Collection` / `Carbon` /
`Response` imports (none — `OrderController` imports one of each). Fresh
`npm run build`, then an own read-only headless pass as `office_test` (12
pages) and `factory_test` (4): all 200, Alpine booted on every page, no
console errors, no `pageerror`, no horizontal overflow; `public/build`
not in `git status`.

Deviations accepted: (1) the `StatementBatchTest` fix is the right one —
`MailFake` now asks the facade root for its default driver, and the test
had left a Mockery mock there; swapping a real `MailManager` back before
`Mail::fake()` keeps the assertions intact. (2) The 171st route is the
framework's `storage.local.upload` for the `local` disk with `'serve' =>
true`; unsigned PUT is 403 on dev and 404 in production, nothing in the
app issues temporary upload URLs, and `AuthorizationBoundaryTest`
already exempts `storage*`. Left as is; recorded in
`TESTING_AND_FIXES.md`. (3) The ssh check the Implementer was refused I
ran myself: mossserv's service is `php8.5-fpm.service`, so
`DEPLOYMENT.md:334` is right.

Ship **with** maintenance mode: no migration, but the remote step swaps
`vendor/` and rebuilds assets (`composer install` + `npm ci && npm run
build`), so it runs longer than usual. Watch the npm step for the
`esbuild` install-scripts warning — the build on dev worked without it.
