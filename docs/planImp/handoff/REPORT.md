# Report — cycles 3 and 4 have landed; `NEXT.md` is stale

**Written:** 2026-10-04 · **Against:** `NEXT.md` still holds the completed 2026-09-13 "move the docs" brief. This replaces the earlier 2026-10-04 reports. **Status: cycles 1–4 accepted; browser verification of the new surfaces outstanding.**

Suite **598 passed, 0 failed** — any failure is real. Nothing pushed or deployed (server unreachable).

## What landed since the last report

**Cycle 3 — Money and state** (the S1/S2 findings): forms submit once and payments are idempotent per form; wheel weights accept a comma and refuse anything over 3 × nominal ("check the decimal point"); the order edit form can't change status outside Confirm/Cancel; refused cut and batch forms keep what was typed; the order form previews the customer's real prices and delivery; per-order invoices carry the PO ref; payments auto-apply oldest-first and refuse a minus.

**Cycle 4 — Friction** (the S3 findings): expired stock is visible (dashboard tile, `/stock` notes, `?status=expired`); the picking queue shows **one day** with a collapsed "awaiting dispatch" bucket for July's stale ready orders; Skip cycles; partial mature release; **Pick** / **Ordered** labels and Fulfil asks before recording a pick; duplicate variants merge; every line/stop/panel action lands back on the element you edited; the sheet editor has Notes; run reordering is position boxes + one Save; the dispatch flash names orders and reasons; a disabled Void names what blocks it; statements default to this month; "€X in credit"; 404/403 pages with one link home.

**Cycle 5 — Copy and polish** (the S4 findings): the flash is now sticky with a dismiss × so a fragment landing still shows its message, and the landed element flashes the accent tint; unmatched URLs 404 inside the app (signed-in users get their home link); login shows the Mossfield mark and focuses the password after a failed attempt; batch cards read "7 wheels · 18 packs", "12 loose — less than a case of 16", "in 60 days", and **hide Delete when the server would refuse it**; the cancel-order dialog reads Keep order / Cancel order; multi-order stops show every ref and label each loaded tick; **PDFs dropped from ~1.7 MB to ~40 KB** (font subsetting — check an emailed attachment opens and reads normally); a credit-only invoice reads "Credited".

**Cycle 6 — Loose ends**: allocating a second time from the *same* batch on the order page (6 from M041026, then 4 more from M041026) now **tops up the existing reservation** instead of crashing; the mature-conversion allocator counts reserved wheels the same way every other screen does; `/batches` and `/cheese-cutting` cost a flat number of queries; the run sheet's column headers **stay visible while you scroll** at desktop width (≥ 1280 px) and a saved row lands just under them.

**Cycle 7 — Stock write-offs** (new feature, Jon's decisions on OPEN_QUESTIONS #1): the batch page has a **Write-offs** panel — units, reason (extendable list under Miscellaneous → Write-off reasons), optional note, and for weighed stock the measured weight; reserved stock can't be written off; a write-off is reversible and stays on the record; an expired batch offers **"Write off expired stock (N units)"** in its header. Written-off units leave sellable stock everywhere, show as a red state on wheel strips and a `written off` segment on `/stock`, and still count in finished weight.

**Cycle 8 — The policy batch** (Jon's decisions on OPEN_QUESTIONS #5): a flagged customer pays the run's delivery charge **once per drop** (a second same-day order shows €0 and the flash names the first); confirming an order that takes a customer over their credit limit **warns** (banner on the order page, flash on confirm) but never blocks — a limit of 0 means none; **Mature can't be made as a fresh batch** (it's gone from the batch form); a batch far above the usual size asks for a **"Yes, this batch really is that big"** tick; **milk yield is computed** and bottling more than the raw milk is refused; order notes are **off the dispatch confirmation and docket**; **Mark dispatched needs the loaded tick** — the order page has its own "Loaded onto van" tick now (factory can use it); the To-Invoice queue shows `ready` orders under **"Picked, awaiting dispatch"** with no Issue button; choosing weekly/monthly on a customer defaults terms to **Net 14**.

**Cycle 9 — Polish**: the batch card's items table shows a wheel row's sold / cut / converted separately; a fully written-off expired card says "Written off"; a `ready` order's page says "Awaiting dispatch — invoiced when dispatched"; the run sheet's header sticks whenever the sheet fits its panel (by column count); every fragment landing sits just under the flash by its real height.

**Cycle 10 — Settings**: `/settings` (Admin group, admin-only) holds the batch sanity ceilings; Save names each change, Reset drops back to the built-in defaults; office/factory get the 403 page.

All eight are written up in `TESTING_AND_FIXES.md` (2026-10-04/05/06 sections); cycle files in `docs/planImp/archive/`.

## What to verify in the browser

The Implementer drove every change with real input at 1408 px and 500 × 659, but one pair of eyes, a real phone, and the things it deliberately didn't press:

1. **Dispatch run** (`/chilled-runs?date=…` with a loaded ready order and an unloaded one): the flash should read *"1 order dispatched · … · skipped: ORD-… Shop (not picked, not loaded)"*. Not pressed by the Implementer because it issues real invoices; `DispatchRunTest` pins the text. Pick a date you don't mind billing.
2. **Real phone (≤ 390 px) on `/picking`**: the day header with ◀ ▶, the stale bucket, a per-piece wheel line (type `5,210` → `5.210`; `52.10` → amber **Check**), a **bulk-weighed vacuum-pack line** (still never exercised on a phone), Skip cycling, `+` greyed at the cap, the short button clear of the footer.
3. **Fragment landings**: change a line qty on `/orders/{id}` → the page reloads scrolled to that line **with the green flash pinned at the top of the viewport** and the line tinted briefly; × dismisses it. Save a sheet row at desktop width → `#stop-N` lands under the flash **and** under the column headers, which stay put while you scroll; dismiss the flash and the headers sit flush at the top. Add a special price → the panel.
3a. **Same-batch top-up** (cycle 6): on a confirmed order, allocate part of a line from a batch, then the rest from the **same** batch → "Allocated N units", one row in the allocations table, no error.
3c. **Policy batch** (cycle 8): on `/orders/create` pick E2E Corner Shop and a day it already has an order → Delivery €0 with the note; set a customer's credit limit low in the UI, confirm an order over it → the amber banner and the warning flash, and the order still confirms; `/batches/create` has no Mature and refuses 3 000 wheels until the box is ticked (don't tick it on dev); a milk batch bottling more than its raw milk is refused; a ready order's page shows "Loaded onto van" and no Mark dispatched until ticked (try it as `factory_test` too); `/invoicing/uninvoiced` shows the July ready orders under "Picked, awaiting dispatch" with no Issue button; on `/customers/create` choosing Weekly flips terms to Net 14.
3b. **Write-offs** (cycle 7): on `/batches/26` (G010426) write off 1 wheel, reason Spoiled, weight `4,900` (comma → `4.900`) → the strip gets a red circle, `/cheese-cutting` shows one fewer free wheel, the batch's "N of 12 wheels weighed" goes up by one; try to write off more than the free count on a batch with a reservation → refused naming the reserved units; **Reverse** it → everything back, the row struck through. On an expired batch (M220626 / Y220626 still have stock) press **Write off expired stock** → one row per item, the dashboard tile and `/stock` note drop by one batch. Deactivate a reason under Miscellaneous → Write-off reasons → it disappears from the panel's select but stays on old rows. Factory sees the history, no forms.
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

695 passed, 0 failed (536 → 557 → 598 → 612 → 617 → 647 → 674 → 684 → 695 across cycles 3–10). New suites: `DashboardTest`, `ErrorPagesTest`, `StockWriteOffTest`, `CreditLimitWarningTest`, `SettingsTest`.

## Dev database state

See `RESUME.md` → *Dev database*. Orders 24 (19–25 are cancelled cycle probes), payments 4, invoices 7, run #1 positions 1…17 with the E2E customers at 1–2. **M230726's expired stock is written off** (951 units); the dashboard Expired tile reads 4 batches / 4,956 units. Cycle 8's probe ORD-20261005-001 (E2E Corner Shop) is dispatched on INV-000008 (€2.50). The two stale July `ready` orders were cancelled on 2026-10-06. The Planner's 2026-10-06 order-taking walk-through left two more cancelled probes, ORD-20261006-001 and -002 (ids 27–28), with no allocations. **Mail is the `log` driver — do not point dev at SMTP.**
