# Report — the end-to-end run is done; `NEXT.md` is stale

**Written:** 2026-10-04 · **Against:** `NEXT.md` still holds the completed 2026-09-13 "move the docs" brief. This report replaces the earlier 2026-10-04 one and tells you what changed since, what was verified for you, and what is left for a browser with real hands.

Suite **536 passed, 0 failed** — any failure is now real. Nothing pushed or deployed (server unreachable).

## What happened since the last report

1. **Authorisation boundary pass** — router-walking matrix test, four fixes (remember-me survived deactivation; `DELETE /orders/{id}` 500; `ProductRequest` validated before policy; four read actions without `authorize()`). `TESTING_AND_FIXES.md` → 2026-10-04 Authorisation.
2. **Housekeeping batch** (Plan/Implement cycle 1) — `/stock` strict expiry; Breeze tests retired; **all 41 native `confirm()`s replaced by one in-app dialog** (`data-confirm`); WIP doc refreshed.
3. **End-to-end browser run** (cycle 2, an Opus session driving Chrome with real input) — batches → cut → mature → customers → orders (form + sheet) → Confirm all / Allocate all → picking at 500 px as factory → load → Dispatch run → invoice / payment / credit note / statement / void → unhappy paths. **41 findings, no code changed.** Full report: `docs/planImp/archive/2026-10-04-e2e-browser-run/implemented.md`; index in `TESTING_AND_FIXES.md`; the design decisions in `OPEN_QUESTIONS.md` #5.
4. **Cycle 3 is READY** (`docs/planImp/plan.md`): the S1/S2 fixes — double-submit guard + payment form token, weight comma/plausibility bound, order-edit status bypass, refused cut/batch forms keeping input, order-form price preview, PO ref on invoices, payment auto-apply / negative amount.

## Items from the previous report — already verified by the run

- Statement **Generate & email** (`name="send"`) generates **and** emails ✓.
- Chilled-run **Confirm all / Allocate all / Dispatch run** dialogs ✓, and the **unsaved-changes** dialog still fires beside them (Keep editing keeps values; Discard navigates) ✓.
- Form-level and button-level confirms, Escape, backdrop ✓ on many pages; the dialog sits above the picking footer and scroll-lock releases ✓.
- Factory: no run-level buttons, no edit links, no € on the sheet or any picking screen; `/invoicing`, `/customers`, the dispatch confirmation → 403 ✓. Driver: placeholder only ✓.
- Deactivate → remember-me cookie dead ✓ (cycle 1, real input).

## What to verify in the browser — what a 500 px minimum and one session couldn't

1. **A real phone (≤ 390 px)** on `/picking`: queue, overview, a fixed line, a per-piece wheel line, a **bulk-weighed vacuum-pack line** (not exercised yet), Undo, Mark short, the celebration screens. Does the fixed footer cover the "Can't finish" button (F-31)? Does anything need horizontal scroll?
2. **Overdue reminders**: `/invoicing/overdue` → *Send reminders* for INV-000001/002 (overdue since September) → one `document_emails` row per invoice, kind `reminder`, and the invoice must **not** be marked "emailed" by it.
3. **Export CSV** (`/invoicing/export`): download for October, open it — do INV-000004…007, CN-000001 and the void appear as the accountant would expect?
4. **Monthly consolidated** path: set E2E Weekly Deli to *monthly*, preview October → it should pick up nothing new (its one order is already on INV-000006); set it back to weekly.
5. **Role change mid-session**: as admin change `office_test` to factory while they're logged in elsewhere; their next page load should be factory access (`/customers` 403).
6. After cycle 3 lands: the eight fixes each have a browser check in `plan.md`; the Implementer runs them, but a second pair of eyes on the **order-form price preview** (E2E Corner Shop: 1L × 24 → €30.00, Delivery €6.15, Total to the cent) and the **weight comma** (`5,210` → `5.210`; `5210` refused) is worth it.

## Decisions taken

- E2E data **kept** in the dev DB (realistic dispatched/invoiced/paid chain). Run #1 charge is €6.15 (reaches E2E Corner Shop only). Order 14 is left `preparing` as pick fodder.
- Findings triaged: S1/S2 → cycle 3 now; S3/S4 → cycle 4; rules the app has no opinion on → `OPEN_QUESTIONS.md` #5 for Jon.

## Disagreements

None with a brief. Two corrections to earlier docs: CLAUDE.md said undo-release "drops the mature item" (it keeps an empty shell and reuses it — doc fix in cycle 3); the E2E plan said all 16 customers were on run #1 (Organic Store is on none).

## Test results

536 passed, 0 failed. No tests changed by the run itself.

## Dev database state

See `TESTING_AND_FIXES.md` → *End-to-end browser run* → *Dev-database state after the run*. Logins `admin`/`admin123` and the three `*_test` users work. **All mail goes to the log**; one dispatch confirmation was addressed to a real-looking customer address — do not point dev at SMTP.
