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
- **Candidates for what's next:** the first question in `OPEN_QUESTIONS.md` (no
  way to record stock being lost) if Jon decides it; the housekeeping batch
  (expiry `>` vs `>=` divergence between `StockOverviewService` and
  `BatchItem::allocatable()`, delete/rewrite the 7 Breeze tests, replace the
  41 native `confirm()`s with a modal, refresh the stale `WORK_IN_PROGRESS.md`).

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
2026-10-04 as **`26934f4`** on `main`, one commit ahead of `origin/main`
(`989574f`). The production server was unreachable at the time, so it has not
been pushed to GitHub and not deployed. When the server is back: push, then
follow `DEPLOYMENT.md` §7 with maintenance mode on — this commit carries the
invoicing migrations (`invoices`, `document_sequences`, …) and new
`INVOICING_*` env vars, so don't `ship --no-maintenance` it.

`public/build` and `node_modules` are gitignored; both are rebuilt by
`npm install && npm run build`.

## Baseline to expect

- `php artisan test` → **529 passing, 7 failing** — the 7 are the documented
  Breeze baseline (`TESTING_AND_FIXES.md` → *Known-failing baseline*). An 8th
  failure is real.
- `./vendor/bin/pint --test app tests database` → clean.

## Dev database (`mossfield_cl`) — repaired, not reset

8 batches, 10 orders, 3 invoices, 16 real customers, 7 products (Mature seeded
2026-09-12). Batch **G030426** (`/batches/17`, wheel item 26): 38 produced —
26 free · 4 cut · 4 converted · 4 sold; the browser session's 2a/2b
verification cuts and releases are in there on purpose. Mature batch
G030426-1 (`/batches/23`) exists from those releases. If you want a clean
seeded state instead:

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
