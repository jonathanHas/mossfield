# Report — cycles 3 and 4 have landed; `NEXT.md` is stale

**Written:** 2026-10-04 · **Against:** `NEXT.md` still holds the completed 2026-09-13 "move the docs" brief. This replaces the earlier 2026-10-04 reports. **Status: cycles 1–4 accepted; browser verification of the new surfaces outstanding.**

Suite **598 passed, 0 failed** — any failure is real. Nothing pushed or deployed (server unreachable).

## What landed since the last report

**Cycle 3 — Money and state** (the S1/S2 findings): forms submit once and payments are idempotent per form; wheel weights accept a comma and refuse anything over 3 × nominal ("check the decimal point"); the order edit form can't change status outside Confirm/Cancel; refused cut and batch forms keep what was typed; the order form previews the customer's real prices and delivery; per-order invoices carry the PO ref; payments auto-apply oldest-first and refuse a minus.

**Cycle 4 — Friction** (the S3 findings): expired stock is visible (dashboard tile, `/stock` notes, `?status=expired`); the picking queue shows **one day** with a collapsed "awaiting dispatch" bucket for July's stale ready orders; Skip cycles; partial mature release; **Pick** / **Ordered** labels and Fulfil asks before recording a pick; duplicate variants merge; every line/stop/panel action lands back on the element you edited; the sheet editor has Notes; run reordering is position boxes + one Save; the dispatch flash names orders and reasons; a disabled Void names what blocks it; statements default to this month; "€X in credit"; 404/403 pages with one link home.

**Cycle 5 — Copy and polish** (the S4 findings): the flash is now sticky with a dismiss × so a fragment landing still shows its message, and the landed element flashes the accent tint; unmatched URLs 404 inside the app (signed-in users get their home link); login shows the Mossfield mark and focuses the password after a failed attempt; batch cards read "7 wheels · 18 packs", "12 loose — less than a case of 16", "in 60 days", and **hide Delete when the server would refuse it**; the cancel-order dialog reads Keep order / Cancel order; multi-order stops show every ref and label each loaded tick; **PDFs dropped from ~1.7 MB to ~40 KB** (font subsetting — check an emailed attachment opens and reads normally); a credit-only invoice reads "Credited".

All three are written up in `TESTING_AND_FIXES.md` (2026-10-04 sections); cycle files in `docs/planImp/archive/`. **One new bug found while testing cycle 5** (queued for cycle 6): allocating a second time from the *same* batch on the order page — e.g. 6 reserved from M041026, then "Allocate 4 more" from M041026 — crashes with a 500. Avoid that exact action on the dev data until cycle 6 lands; the picking screen's one-tap pick is unaffected.

## What to verify in the browser

The Implementer drove every change with real input at 1408 px and 500 × 659, but one pair of eyes, a real phone, and the things it deliberately didn't press:

1. **Dispatch run** (`/chilled-runs?date=…` with a loaded ready order and an unloaded one): the flash should read *"1 order dispatched · … · skipped: ORD-… Shop (not picked, not loaded)"*. Not pressed by the Implementer because it issues real invoices; `DispatchRunTest` pins the text. Pick a date you don't mind billing.
2. **Real phone (≤ 390 px) on `/picking`**: the day header with ◀ ▶, the stale bucket, a per-piece wheel line (type `5,210` → `5.210`; `52.10` → amber **Check**), a **bulk-weighed vacuum-pack line** (still never exercised on a phone), Skip cycling, `+` greyed at the cap, the short button clear of the footer.
3. **Fragment landings**: change a line qty on `/orders/{id}` → the page reloads scrolled to that line **with the green flash pinned at the top of the viewport** and the line tinted briefly; × dismisses it. Save a sheet row → `#stop-N` lands just under the flash (the column headers scroll off — known, cycle 6 makes the header sticky); add a special price → the panel.
4. **Run reorder**: set a stop's Position box to the last number and Save order → it's last and in view; ↑ once → back on that row. Try a number above the stop count (clamped to last).
5. **Partial release** on `/cheese-conversion`: with 2 held, release 1; move a wheel without saving → Release disabled with "Save maturing first".
6. **Expired stock**: dashboard tile count equals `/batches?status=expired`; `/stock` milk card note names 4 batches / 4,437 units.
7. **Error pages**: `/orders/999999` as factory → "Back to picking"; as office → dashboard; `/no-such-page` while signed in → the same home link (fixed in cycle 5).
7a. **Cycle 5 copy**: `/login` shows the "M · Mossfield Dairy" mark; a wrong password puts the cursor in the password box; `/batches` — G030426 shows "Can't delete — N records depend on it" (hover for why) and only a fresh batch offers Delete; the Mature cards' raw milk reads "—"; `/stock` M041026-1 reads "12 loose — less than a case of 16"; the cancel-order dialog says Keep order / Cancel order; E2E Corner Shop's row on Tue 06/10 shows both PO refs and numbered ticks; open an invoice PDF from an email in the log — ~40 KB and the € signs render.
8. Still open from before: overdue reminders send; CSV export contents; monthly consolidated path; role change mid-session.

**Harness note from the Implementer:** on `orders/show`, clicking a native `<select>` opens a popup that doesn't commit keyboard choices under the Chrome extension; Shift+Tab to focus it closed, then Down, works. Not an app bug.

## Decisions taken

- E2E data kept; cycle 4's browser checks released one more G010426 wheel to Mature (item 46: remaining 7, held 1) — reversible from the Mature batch page if the lineage numbers matter to a checklist.
- S4 copy/polish items are a planned short cycle 5; the policy questions are `OPEN_QUESTIONS.md` #5 for Jon.

## Disagreements

None with a brief. The cycle-4 plan's reorder semantics and landing fragment were wrong as written; the Implementer's versions (re-insert at the wanted position; land on the stop you moved) are what shipped.

## Test results

612 passed, 0 failed (536 → 557 → 598 → 612 across cycles 3–5). New suites: `DashboardTest`, `ErrorPagesTest`.

## Dev database state

See `RESUME.md` → *Dev database*. Orders 23 (19–24 are cancelled cycle probes), payments 4, invoices 7, run #1 positions 1…17 with the E2E customers at 1–2. **Mail is the `log` driver — do not point dev at SMTP.**
