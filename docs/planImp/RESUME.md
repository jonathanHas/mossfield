# Resume point — 2026-10-28

Where things stand, so a fresh session (or a fresh machine after a reboot) can
pick up exactly here. Update or replace this file whenever you stop at a point
worth returning to.

## What to say to Claude Code

> Read `docs/planImp/RESUME.md`, then do the next handoff.

"Do the next handoff" is defined in `CLAUDE.md` → *Handoff Convention*: read
`docs/planImp/handoff/NEXT.md`, do it test-first, write
`docs/planImp/handoff/REPORT.md`. The browser-testing session writes the next
`NEXT.md` after reading the report.

## Where the loop is

- **Last brief done:** "move the planning and testing docs into `docs/planImp/`"
  (2026-09-13). Its report is the current `handoff/REPORT.md`.
- **`handoff/NEXT.md` is that same brief** — the browser session has not written
  a new one yet. If it still reads "move the planning and testing docs…" when you
  resume, there is nothing to do until a new brief arrives; say so rather than
  inventing work.
- **2026-10-04, outside the loop (Jon said "you choose"):** the authorisation
  boundary pass was done test-first from the router instead of waiting for the
  browser session's brief — `tests/Feature/AuthorizationBoundaryTest.php` plus
  four findings fixed (remember-me cookie survived deactivation; dangling
  `orders.destroy` 500; `ProductRequest` validated before the policy; four
  read actions with no `authorize()`). Recorded in `TESTING_AND_FIXES.md` →
  2026-10-04. If the browser session's next brief is authorisation findings,
  check them against that section first — most should already be closed.
- **2026-10-04, first Plan/Implement cycle (`planimp.md`) — ACCEPTED:** the
  housekeeping batch (strict expiry on `/stock`, the 7 Breeze tests retired,
  all 41 native `confirm()`s replaced by one `data-confirm` dialog,
  `WORK_IN_PROGRESS.md` refreshed). Planned by Fable, implemented by an Opus
  session, reviewed against the diff. Write-up in `TESTING_AND_FIXES.md` →
  2026-10-04 Housekeeping batch; cycle files in
  `archive/2026-10-04-housekeeping-batch/` once Jon archives them.
  `handoff/REPORT.md` now carries the browser checklist for both October
  passes (it also says `NEXT.md` is stale).
- **2026-10-04, cycle 2 — ACCEPTED:** the end-to-end browser run (an Opus
  Implementer drove Chrome with real input through batches → cut → mature →
  customers → orders → picking → dispatch → finance → unhappy paths). No code
  changed; **41 findings** (3 S1, 3 S2, 19 S3, 16 S4), every probe
  reconciled. Index in `TESTING_AND_FIXES.md` → 2026-10-04 End-to-end run;
  full report `archive/2026-10-04-e2e-browser-run/implemented.md`; decisions
  for Jon in `OPEN_QUESTIONS.md` #5.
- **Cycle 3 "Money and state" — ACCEPTED 2026-10-04:** the S1/S2 fixes
  landed (submit-once + payment `form_token`; weight comma + 3× nominal
  bound; order-edit status bypass closed; refused cut/batch forms keep input;
  order-form price preview; PO ref on invoices; payment auto-apply and
  negative amount). Suite **557 passed, 0 failed**. Write-up in
  `TESTING_AND_FIXES.md` → 2026-10-04 Money and state. Jon archives
  `plan.md` + `implemented.md` to `archive/2026-10-04-money-and-state/`.
- **Cycle 4 "Friction" — ACCEPTED 2026-10-04:** the S3 findings landed
  (expired stock visible; one-day picking queue with a stale bucket; cyclic
  Skip; partial mature release; Pick/Ordered labels + Fulfil confirm; merge
  duplicate variants; fragment redirects everywhere; sheet Notes; run reorder
  as one form; per-order dispatch flash; Void blocker named; statement
  defaults; "in credit"; error pages). Suite **598 passed, 0 failed**.
  Write-up in `TESTING_AND_FIXES.md` → 2026-10-04 Friction. Jon archives
  `plan.md` + `implemented.md` to `archive/2026-10-04-friction/`.
- **Cycle 5 "Copy and polish" — ACCEPTED 2026-10-04:** sticky dismissible
  flash + `:target` tint; `Route::fallback`; third allocate message; login
  brand mark + password focus; batch copy (per-variant stock, full-case
  label, "in N days", raw-milk dash, Delete hidden when refused via
  `Batch::deletionBlocker()`); Keep order / Cancel order; multi-order stop
  labels; **PDFs 1.7 MB → 40 KB** (font subsetting); "Credited" label; terms
  hint. Suite **612 passed, 0 failed**. Write-up in `TESTING_AND_FIXES.md` →
  2026-10-04 Copy and polish. Jon archives `plan.md` + `implemented.md` to
  `archive/2026-10-04-copy-and-polish/`.
- **Cycle 6 "Loose ends" — ACCEPTED 2026-10-04:** same-batch allocation
  top-up fixed (was a 500); the conversion allocator's reservation sum
  ungated (the last of the eight sites); `/batches` N+1 fixed (`??` on a
  loaded null → `array_key_exists`; 12→18 queries became 6→6); sticky
  run-sheet header at `xl+`; `/orders` nowrap; Breeze logo deleted. Suite
  **617 passed, 0 failed**. Write-up in `TESTING_AND_FIXES.md` → 2026-10-04
  Loose ends. Jon archives `plan.md` + `implemented.md` to
  `archive/2026-10-04-loose-ends/`.
- **Cycle 7 "Stock write-offs" — ACCEPTED 2026-10-05:** OPEN_QUESTIONS #1
  built — reasons list (seeded by migration, manageable), record/reverse
  through `StockWriteOffService`, the expired one-click, the fifth state in
  the weight model and on every stock surface, `BackupService::ADDED_LATER`.
  Suite **647 passed, 0 failed**. Write-up in `TESTING_AND_FIXES.md` →
  2026-10-05. Jon archives `plan.md` + `implemented.md` to
  `archive/2026-10-05-write-offs/`.
- **Cycle 8 "The policy batch" — ACCEPTED 2026-10-05:** all eight
  OPEN_QUESTIONS #5 rules built (one delivery charge per drop; credit limit
  warns; Mature conversion-only; sanity ceiling; milk yield computed and
  bottled ≤ raw; notes internal; dispatch needs the loaded tick; issue only
  dispatched/delivered; Net 14 default). Suite **674 passed, 0 failed**.
  Write-up in `TESTING_AND_FIXES.md` → 2026-10-05 Policy batch. Jon archives
  `plan.md` + `implemented.md` to `archive/2026-10-05-policy-batch/`.
- **Open for Jon:** the batch-ceiling numbers (`config/mossfield.php`
  `batch_ceilings`: milk 5 000, yoghurt 3 000, cheese 200 — guesses).
- **Cycle 9 "Polish" — ACCEPTED 2026-10-06:** card Sold split from cut/
  converted; "Written off" tag; three billing-line states; refusals to the
  queue page; sticky header by column count (≤ 11 xl, ≤ 15 2xl);
  `mf-landing`. Suite **684 passed, 0 failed**. Archived to
  `archive/2026-10-06-polish/` by the Planner.
- **Cycle 10 "Settings" — ACCEPTED 2026-10-06:** `/settings` (admin-only)
  holds the batch ceilings; `Setting::batchCeiling()` is the read site;
  config stays the default; backups/reset classify the table. Suite **695
  passed, 0 failed**. Archived to `archive/2026-10-06-settings/` by the
  Planner. **Jon enters his ceiling numbers at `/settings`** (dev table is
  empty = defaults).
- **Planner's Chrome walk-through of order-taking, 2026-10-06:** both entry
  surfaces work and read well; five copy/UX items found (generic "Are you
  sure?" dialog titles — 46 of 47 dialogs; "FIFO" in the auto-allocate
  dialog; the stepper says "Picking" with nothing picked; "Capacity." on the
  run sheet; the create form's trailing blank line). Probes ids 27–28
  cancelled.
- **Cycle 11 "Order-flow polish" — ACCEPTED 2026-10-06 (revision 2):**
  every confirm dialog titled and its body the consequence (scan in
  `ConfirmDialogTest`), "N of M picked" under Picking, "Capacity:", the
  optional trailing line, "FIFO" gone. Suite **699 passed, 0 failed**. Probe
  id 29 cancelled. Reviewed in the Implementer's session at Jon's request;
  the Planner re-verified on 2026-10-07 (suite 699 green, 47/47 titles,
  bodies traced) and archived it to `archive/2026-10-06-order-flow-polish/`.
- **Cycle 12 "Delete guards" — PARKED 2026-10-10** (Jon: work on the deploy
  script first). Plan + the Implementer's IN PROGRESS report are in
  `parked/2026-10-10-delete-guards/`; its half-done tree is commit `a8f75d6`
  on branch **`wip/cycle-12-delete-guards`** (off `095c250`). `main` is clean
  — deliberately, because `deploy/bin/ship` auto-commits *everything* in the
  working tree. Resume instructions are at the top of the parked plan.
- **2026-10-13, UX review (Fable, Chrome as `office_test`):** 34 findings in
  `UX_REVIEW_2026-10-13.md` (its Decisions block has Jon's calls: finding 1
  expected, 4 deferred, 5 hidden behind a flag).
- **Cycle 13 "UX review, part 1" — ACCEPTED 2026-10-13 (revision 2):**
  Mossorders surface behind `MOSSORDERS_UI_ENABLED`; dashboard says confirm
  / allocate / overdue; `Order::isDeliveryOverdue()` + `statusLabel()`;
  order header and product page fit 1280 px; weigh-in row; honest
  auto-allocate banner and short-close copy; "No limit"; batch-card tags;
  bell and Profile delete gone; run-sheet strip/stock footer/capacity. Suite
  **727 tests, 0 failed**. Write-up in `TESTING_AND_FIXES.md` → 2026-10-13.
  Jon archives `plan.md` + `implemented.md` to
  `archive/2026-10-13-ux-review-part-1/`.
- **Cycle 14 "UX review, part 2" — ACCEPTED 2026-10-28:** the Planner's
  second walk-through (every role, phone widths, sad paths) → 25 findings in
  `UX_REVIEW_2026-10-28.md`, all landed: backup covers the nine finance
  tables; an admin can't demote/deactivate themselves; `form_token` on
  write-off and cut forms (one migration); a 419 page; undo-release deletes
  the empty mature batch; honest Active/Ready filters, void-invoice export,
  "run has no charge", reversed write-offs named; factory/driver copy;
  Release hidden when Mature isn't set up; pick form keeps typed weights and
  names real batch stock; next-up never a dead order; credit-note form
  prefills VAT; cut-from-batch returns to the batch; statement landing.
  Suite **780 tests, 0 failed**. Write-up in `TESTING_AND_FIXES.md` →
  2026-10-28. Jon archives `plan.md` + `implemented.md` +
  `UX_REVIEW_2026-10-28.md` to `archive/2026-10-28-ux-review-part-2/` and
  commits (explicit `git add` paths; the tree also holds the untracked
  migration, `errors/419.blade.php` and `UserManagementTest`).
- **Cycle 15 "The 2026-10-13 leftovers" — ACCEPTED 2026-10-28 (revision 2):**
  D20–D26, E29, F34 and the last "fulfil" copy, the two cycle-14 carry-overs
  and the dev-DB data fixes: customer email/city/postal code optional
  (migration `2026_10_28_130000`, email required only while invoice
  delivery is Email, trailing commas stripped); order-form run hint,
  "if different" address, `~` estimates; `/orders` search + date range,
  filters apply as they change; customers search as you type;
  `App\View\FinanceNav` behind the sidebar and the finance tab bar; town on
  the run sheet with the address on hover; "Pick" wording; backup
  numbering sentence; `PickingTest::batchItem()` sets `ready_date`.
  Revision 2 fixed the Implementer's enum-vs-string fallback in
  `CustomerController::rules()`. Suite **808 tests, 0 failed**. Write-up in
  `TESTING_AND_FIXES.md` → 2026-10-28 (cycle 15). Jon archives `plan.md` +
  `implemented.md` to `archive/2026-10-28-leftovers/`; committed as
  `4f0591a` (39 files) and **deployed to `mossserv` on 2026-10-28** with
  `deploy/bin/ship-jon` — the migration shows `[10] Ran` there. The four data fixes (Avoca rename; 16L bucket
  €72 flat; Garlic & Basil wheel €16/kg; Mature wheel €20/kg by weight)
  were applied in production by Jon through the app on 2026-10-28.
- **Cycle 16 "Copy and tidy before the user guide" — ACCEPTED 2026-10-29
  (revision 1):** the order page says "N of M picked" in all four places
  (`OrderShowAllocationInlineTest` pins it page-wide on confirmed, pending,
  ready and dispatched orders) and the completeness grep now matches the
  copy token (`(?<![\w$.-]|->)[Ff]ulfil…`) instead of discarding lines —
  the only user-visible "fulfil" left is the `Fulfillment` sidebar group
  (kept on purpose, listed in `SMALL_FIXES.md`); the factory dashboard
  button reads "Picking queue →" (`UxCopyTest`); every
  `BackupService::TABLES` entry must have a label on `/backup`
  (`BackupRestoreTest`); the four data items are ticked in
  `SMALL_FIXES.md`; dev user 9 `review_test` is deleted. Suite **810 tests,
  0 failed**. Write-up in `TESTING_AND_FIXES.md` → 2026-10-29. Archived to
  `archive/2026-10-29-copy-and-tidy/` (plan, report and the finding),
  browser checks passed (no finding written), committed as `862e293`
  (12 files) and **deployed to `mossserv` on 2026-10-29** with `ship-jon`
  (no migration). Still
  deferred: finding 4 of 2026-10-13 (Email invoice from the order page),
  the sync "never completed" message, the Mossorders flag decision, and
  the orders search box's autofocus on a phone.
- **Cycle 16b "User guide, part 1" — ACCEPTED 2026-10-31 (revision 1):**
  `/help` (`HelpController`, shared role group) renders `docs/guide/*.md`
  through `Str::markdown()` with regex + `is_file` path safety; **Help** is
  the last sidebar group; `HelpPagesTest` (6) reads the real guide folder;
  `docs/guide/screenshot.mjs` + `screenshots.json` (puppeteer-core over the
  installed Chrome, 15 pictures, 2.7 MB) and the dev-only
  `docs/guide/demo-data.php` (orders `GUIDE-A…D`, ids 11–14); four pages
  (getting started, orders, chilled runs, picking). Suite **816 tests, 0
  failed**. Write-up in `TESTING_AND_FIXES.md` → 2026-10-31. Jon archives
  `plan.md` + `implemented.md` to `archive/2026-10-31-user-guide-part-1/`
  and commits with `ship-jon` (since 2026-10-31 it adds untracked files
  like `ship` does; `.gitignore` is the exclusion list).
- **Next: cycle 16c = user guide, part 2** (finance, stock/production,
  admin pages), opening with the small deferred items listed at the end of
  the 2026-10-31 section of `TESTING_AND_FIXES.md` (Help link in the phone
  shell, demo-data `--fresh`, two wording fixes, the empty new-order form
  picture, the dev box's expired stock before the stock pictures).
- **Then: cycle 17 = parked cycle 12 (delete guards)** — plan and the
  Implementer's IN PROGRESS report are in `parked/2026-10-10-delete-guards/`;
  resume instructions at the top of the parked plan. **The branch
  `wip/cycle-12-delete-guards` (commit `a8f75d6`) is not on this clone**:
  this working copy was cloned fresh from GitHub (`git reflog` begins at
  `c27f101 clone:`), the branch was never pushed, and `git fsck --lost-found`
  finds no dangling commits. Unless the old machine still has it (vault open
  item: "19 files of uncommitted work on the old machine"), cycle 17
  restarts from the parked plan, using the report as the record of what
  steps 1–4 had already done.

## What has been done this run (all in `TESTING_AND_FIXES.md`, newest first)

Browser pass 2026-09-12, findings 1–11 — **all closed**:
free-stock invariant extended to eight sites (`BatchItem::freeStockByVariant()`
behind `/products` and the mossorders export); layout-level flash/error region;
cutting index/form/batch page read free stock; Mature seeded + `release()`
bounded; inline `items.*` errors; **pass 2a** (wheel weighed at cutting,
per-unit fulfilment weights persisted and recomputed server-side, `cut_date`
bounds); **pass 2b** (finished weight derived per wheel from measured states,
coverage shown, reconciliation asserted); consolidation (variant pickers
prefixed with the product, batch page/cards split cut/converted out of "sold",
`/` redirects, fonts self-hosted, small-fixes batch); docs moved into
`docs/planImp/`. Also `php artisan mossfield:reset-trading-data` exists.

## Committed locally, NOT pushed or deployed

Everything above (plus the invoicing work that preceded it) was committed on
2026-10-04 as **`26934f4`** on `main`, followed by the authorisation pass
(`24ce7cb`), the planImp protocol + plan (`218f492`) and the housekeeping
cycle — all local, ahead of `origin/main` (`989574f`). The production server
was unreachable, so nothing has been pushed to GitHub or deployed. When the server is back: push, then
follow `DEPLOYMENT.md` §7 with maintenance mode on — this commit carries the
invoicing migrations (`invoices`, `document_sequences`, …) and new
`INVOICING_*` env vars, so don't `ship --no-maintenance` it.

`public/build` and `node_modules` are gitignored; both are rebuilt by
`npm install && npm run build`.

## Baseline to expect

- `php artisan test` → **816 tests, 0 failing** as of 2026-10-31 (after cycle 16b) (PHPUnit flags most as "deprecated" — the pre-existing `PDO::MYSQL_ATTR_SSL_CA` notice, not failures). Any failure is real.
- `./vendor/bin/pint --test app tests database` → clean.

## Dev database — cycle 16b changes, 2026-10-29

Four demo orders for the guide pictures, made by `php artisan tinker
docs/guide/demo-data.php` (idempotent; re-running creates nothing):
`GUIDE-A` id 11 Organic Store, confirmed and fully allocated, delivery
2026-10-29; `GUIDE-B` id 12 Organic Store pending, 2026-11-05; `GUIDE-C` id
13 Cavistons preparing (12 × 1L picked from `M281026`, other lines
reserved), 2026-11-03; `GUIDE-D` id 14 Roundwood Stores pending, 2026-11-03.
They hold reservations on `M281026`, `Y230726` and `G030426`. To clear them,
cancel them in the app (stock returns); 16c adds a `--fresh` flag.

## Dev database — cycle 16 change, 2026-10-29

User id 9 `review_test` (factory, deactivated, left by the 2026-10-28
review) deleted by tinker; seven users remain. The cancelled probe orders
ORD-20261013-002 / -003 stay as history.

## Dev database — cycle 15 changes, 2026-10-28

Migration `2026_10_28_130000` (customer email/city/postal_code nullable) is
applied on dev. By tinker: customer 20 renamed "Avoca Ballsbridge"; variant
14 (16L Bucket) flat €72; variant 15 (Mature Whole Wheel) €20/kg by weight;
nine one-letter placeholder postcodes NULL; customer 10's trailing comma
gone. Mature on dev has no Vacuum Pack variant (the seeder makes one) —
drift, left alone.

## Dev database — state on 2026-10-13

The section below describes the database **before** it was reset; on
2026-10-13 it held 16 customers and 5 orders before the UX walk-through. The
walk-through and cycle 13 added: ORD-20261013-001 (Barnhill, id 6)
**dispatched → INV-000001** (€161.60, unpaid, not emailed — void from Finance
if it should go), ORD-20261013-002 (id 7) and the Implementer's probe
ORD-20261013-003 (id 8), both cancelled. G030426 wheels 31 → 29 on hand. The
`office_test` password is back to the seeder default. `public/build` was
rebuilt.

## Dev database (`mossfield_cl`) — repaired, not reset; E2E data added 2026-10-04

**After the E2E run:** 13 batches, 17 orders, 7 invoices (INV-000004 paid,
005 void, 006 consolidated, 007 unpaid), 3 payments, 1 credit note, 1
statement, 18 customers (27 **E2E Corner Shop**, 28 **E2E Weekly Deli** at
run #1 positions 1–2; run #1 charge is now €6.15). Order 14 is `preparing`
with 12 units reserved — pick it, short it or cancel it when it gets in the
way. Cycle 3's browser checks added orders 19–20 (cancelled) and payment 8
(€1.00 on INV-000006, the double-click check — the one row with a
`form_token`). Cycle 4's added orders 21–22 (cancelled) and **released one
more G010426 wheel to Mature** (item 46 now `remaining 7 · held 1`; G010426-1
has 2) — reversible from the Mature batch page. Cycles 5–6 added orders
23–25 (cancelled). Counts now 24 orders, 33 allocations, 4 payments, 7
invoices, 13 batches, 18 customers; no customer is linked to Mossorders.
**Cycle 7 wrote off M230726's expired stock** (951 units, reason Expired —
correct, kept) and left 4 `stock_write_offs` rows (2 active, 2 reversed
probes); the dashboard Expired tile now reads 4 batches / 4,956 units.
**Cycle 8's probe** ORD-20261005-001 (id 26, E2E Corner Shop, 2 × 1L, €0
delivery — the once-per-drop rule) was confirmed, picked, loaded and
**dispatched → INV-000008** (€2.50, not emailed); it can't be cancelled now
— void INV-000008 from Finance if it should go. Counts: 25 orders, 8
invoices. The two stale July `ready` orders (ORD-20260710-001,
ORD-20260723-001) were **cancelled on 2026-10-06** (Jon: "you decide");
their 112 picked units went back to their expired July batches, so expired
stock reads 5,024 units. **The Planner's 2026-10-06 order-taking walk-through** (Chrome, as `office_test`) added two more cancelled probes — ORD-20261006-001 (id 27, Corner Shop, auto-allocated then cancelled, stock released) and ORD-20261006-002 (id 28, Weekly Deli, entered from the run sheet for 13/10) — so **27 orders**. Everything E2E is tagged `E2E` / `E2E-20261004` / `@e2e.test`. Details:
`TESTING_AND_FIXES.md` → *End-to-end browser run* → *Dev-database state*.
**Mail is the `log` driver — never point dev `.env` at SMTP** while a
confirmation addressed to a real customer sits in `document_emails`.

Before the run: 8 batches, 10 orders, 3 invoices, 16 real customers, 7 products (Mature seeded
2026-09-12). Batch **G030426** (`/batches/17`, wheel item 26): 38 produced —
26 free · 4 cut · 4 converted · 4 sold; the browser session's 2a/2b
verification cuts and releases are in there on purpose. Mature batch
G030426-1 (`/batches/23`) exists from those releases. On 2026-10-04 the
housekeeping cycle re-ran `AdminUserSeeder` (the `admin` login had been
missing; `office_test`/`factory_test`/`driver_test` passwords reset to
`admin123`) and round-tripped order 3 item 13 (reopen → undo → re-pick 1 ×
0.250 kg → short) — it ended where it started except for fresh timestamps.
If you want a clean seeded state instead:

```bash
php artisan mossfield:reset-trading-data --seed=1            # dry run
php artisan mossfield:reset-trading-data --seed=1 --confirm  # wipes the G030426 lineage
```

## Bringing the box back up

```bash
cd /var/www/html/mossfield
composer install && npm install && npm run build   # only if vendor/ or node_modules/ are gone
php artisan migrate                                # both 2026_09_13 migrations are already applied on dev
composer run dev                                   # server + queue + logs + Vite
```

Site: `http://mossfield.local` (DB `mossfield_cl` on `127.0.0.1:3306`, user
`mossfield`; test DB `mossfield_test` is hardcoded in `phpunit.xml`). Seeded
logins in `CLAUDE.md` → *Dev / Staging Seeded Users* (`admin` / `admin123`).

## Session

Claude Code session that did all of the above:
https://claude.ai/code/session_01KGdGAeX25gMiFKUECUqoDA — resumable with
`claude --resume` on this machine if the transcript is still there; this file is
the fallback if it isn't.
