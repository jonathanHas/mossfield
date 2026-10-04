# Resume point — 2026-10-04

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
- **The Planner's own backlog is now empty.** What's left needs Jon:
  `OPEN_QUESTIONS.md` #1 (write-offs — the biggest functional gap) and #5
  (eight policy calls from the E2E run); each answer becomes a one-step
  plan. Also `FULFILMENT_PLAN.md` Phase 4 (driver route + POD) when wanted,
  and two tiny follow-ups logged under the cycle-6 section.

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

- `php artisan test` → **all passing, 0 failing** (the 7 Breeze failures were retired 2026-10-04). Any failure is real.
- `./vendor/bin/pint --test app tests database` → clean.

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
invoices, 13 batches, 18 customers; no customer is linked to Mossorders. Everything E2E is tagged `E2E` / `E2E-20261004` / `@e2e.test`. Details:
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
