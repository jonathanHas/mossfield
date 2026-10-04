# Copy and polish: the S4 findings and the cycle-4 follow-ons

Status: ACCEPTED
Revision: 1
Planner: Fable 5.1
Date: 2026-10-04

## Goal

Clear the small things the end-to-end run listed as S4 — copy, labels, a
logo, a divider, a dialog that doesn't name its batch — together with the
four follow-ons the cycle-4 review deferred: a flash that's out of view after a
fragment redirect, sheet rows landing flush at the top, a 404 for an unmatched
URL telling a signed-in user to sign in, and a third allocate message. Also
the 1.76 MB one-page PDFs. Nothing here changes stock or money; everything
here is what an operator reads or taps every day.

## Context

HEAD `f6e9de4` + the staged archive of cycle 4. Suite **598 passed, 0 failed**;
any failure is real. `pint --test app tests database routes` clean. Nothing
pushed or deployed.

Findings referenced are in `archive/2026-10-04-e2e-browser-run/implemented.md`;
the four follow-ons are in `archive/2026-10-04-friction/plan.md` → Review.

**Facts the steps rely on**

- Flash region: `resources/views/layouts/partials/feedback.blade.php`,
  included once in `layouts/app.blade.php` at the top of `<main>` (a `div
  class="px-6 pt-5 …" role="status"`). `LayoutFeedbackTest` pins
  exactly-once rendering. The picking layout has its own region — untouched.
- Fragment targets: order rows `id="line-{id}" class="scroll-mt-20"`; run
  stop rows `id="stop-{id}"` with `scroll-mt-20`; the two chilled-run sheet
  rows `<tr id="stop-{{ $customer->id }}"` at `_run-table.blade.php:103` (edit)
  and `:194` (display) have **no** `scroll-mt`.
- Error pages: `resources/views/errors/{layout,404,403}.blade.php` (cycle 4).
  An unmatched URL 404s before the `web` group runs, so `auth()->user()` is
  null and the page says "Sign in". `routes/web.php` has no `Route::fallback`.
- `OrderAllocationController::allocationRefusal()` (`:94–107`): "already
  fully allocated (N of N)" → "only N available in this batch" → generic.
  Missing: the request asks for more than the line still needs while the
  batch has enough (`quantity > ordered − allocated`).
- Login: `layouts/guest.blade.php:18` renders `<x-application-logo>` (the
  Laravel cube) above a `bg-gray-100` page; the app's brand mark is the
  "M" square + "Mossfield Dairy" at `layouts/navigation.blade.php:~115–123`.
  `auth/login.blade.php:11` gives `#login` `autofocus` unconditionally (F-04).
- Topbar: `layouts/partials/topbar.blade.php:48` is a 1 px divider that is
  always rendered; the date chip after it is `hidden sm:inline` (F-05).
- Batch copy (F-11): `batches/create.blade.php:159` prints `${variant.size}
  ${variant.unit}` and `batches/show.blade.php:159` prints `size unit` — both
  "wheel wheel" when they're equal. `components/stock/case-blocks.blade.php:47`
  and `case-pictograph.blade.php:40` print `{{ n }} cases × {{ caseSize }}`.
  `batches/show.blade.php:101` and `batch-card.blade.php:64` print
  `ready_date->diffForHumans()` ("1 month from now" for 60 days).
  `batch-card.blade.php:72` prints `number_format(raw_milk_litres, 1)L` —
  "0.0L" on a converted Mature batch. `batch-card.blade.php:160` has
  `data-confirm="Are you sure?"` on Delete, rendered for anyone who `@can('delete')`
  even when `BatchController::deletionBlocker()` (`:223–260`, private: counts
  allocations, source+target cutting logs, source+target conversion logs)
  would refuse. The index's `batchItems` eager-load already carries
  `withCount('sourceCuttingLogs')`, `withSum(... converted_wheels)` and the
  allocations sum (`BatchController::index():4–12`).
- F-14: `batches/show.blade.php:121` prints `{{ $batch->remaining_stock }} units`
  and `batch-card.blade.php:75` `Stock: {{ remaining_stock }}` — wheels and
  packs summed.
- F-17: `customers/create.blade.php:126–134` "Online integration · Mossorders
  user ID" bare number input (same block in `edit.blade.php`).
- F-26 dialog labels: `orders/show.blade.php:500` Cancel order button; the
  shared dialog's cancel button is `#confirm-action-cancel` "Cancel"
  (`layouts/partials/confirm-dialog.blade.php:8`); `resources/js/confirm.js`
  sets title/message/OK label from `data-confirm-*` and has no cancel-label
  option.
- F-27: `ChilledRunController::buildSheet():686` keeps only the **first**
  order's `customer_reference` per stop; `_run-table.blade.php:268` prints it;
  the per-order loaded ticks (`:208–220`) have `aria-label` "Mark loaded" with
  no order number and no visible label; `:278` says "Multiple orders — edit
  above".
- F-31: `orders/index.blade.php:64` wraps the table in `overflow-x-auto`;
  order numbers wrap mid-number at 500 px.
- F-34: PDFs come from `Pdf::loadView(...)` (barryvdh/laravel-dompdf) with
  `font-family: 'DejaVu Sans'` / `'DejaVu Sans Mono'` in
  `documents/_pdf-styles.blade.php`; the whole fonts are embedded (1.4–1.8 MB
  per one-page document, attached to every email). `config/dompdf.php` is
  **not published**; the package default is `'enable_font_subsetting' => false`
  (`vendor/barryvdh/laravel-dompdf/config/dompdf.php:113`). `pdftotext` is
  installed at `/usr/bin/pdftotext`. `DispatchConfirmationTest:58–60` already
  asserts the content type and `%PDF`.
- F-38: `Invoice::getPaymentStateAttribute()` (`app/Models/Invoice.php:173`)
  returns `partial` when `amount_paid > 0 || amount_credited > 0`; the label
  is "Part paid" (`:198`). A credit-only invoice therefore reads "Part paid".
  Customer form: `payment_terms` select (`customers/create.blade.php:69–75`,
  default Immediate) and `invoicing_mode` select in the same form.

## Constraints

- No stock or money arithmetic changes; `payment_state` **values** stay
  (filters and tests use them) — only the label may change.
- Every message a customer or operator sees stays unless a step changes it.
- `LayoutFeedbackTest` (flash rendered exactly once) must keep passing.
- `npm run build` after JS/CSS changes; `./vendor/bin/pint` on touched PHP.
- **Do not commit.** Planner commits on acceptance. Nothing pushed/deployed.

## Out of scope

- Everything in `OPEN_QUESTIONS.md` #1 and #5.
- Redesigning the picking or run-sheet screens; drag-and-drop anywhere.
- The `/orders` table beyond `whitespace-nowrap` (factory stays on `/picking`).
- Changing PDF layout or content — only how fonts are embedded.

## Steps

### 1. A redirect that lands on an element still shows its message (cycle-4 note)

Files: `resources/views/layouts/partials/feedback.blade.php`,
`resources/css/app.css`, `resources/views/chilled-runs/partials/_run-table.blade.php`,
`tests/Feature/LayoutFeedbackTest.php`

What:
- Make the feedback region **sticky**: the wrapper gets `position: sticky;
  top: 0; z-index: 30` (a `.mf-feedback` class in `app.css`, with the page
  background behind it so content doesn't show through) and a small **×**
  dismiss button per flash (`type="button"`, removes its flash with a two-line
  inline script or Alpine `x-data="{show:true}" x-show="show"`). Sticky
  inside `<main>` keeps the message at the top of the viewport however far
  the fragment scrolled the page.
- Highlight the landed element: one CSS rule `:target { animation:
  mf-target 1.8s ease-out }` with a keyframe from the accent tint to
  transparent background (`.scroll-mt-20:target` is enough to scope it to
  the elements we give fragments).
- Both sheet `<tr>`s (`:103`, `:194`) get `scroll-mt-20` in their class list.
- Test: `LayoutFeedbackTest` — the flash is still rendered exactly once and
  the wrapper carries `mf-feedback`.

Check: `php artisan test --filter=LayoutFeedbackTest` pass. Browser: change a
line qty on a long order page → the page lands on the line **and** the green
flash is visible at the top of the viewport; × hides it; the line flashes
briefly. Save a sheet row → the row lands below the column headers.

### 2. An unmatched URL still knows who you are (cycle-4 note)

Files: `routes/web.php`, `tests/Feature/ErrorPagesTest.php`

What:
- At the end of `routes/web.php` add `Route::fallback(fn () => abort(404));`
  — routes in `web.php` carry the `web` group, so the session loads and the
  error layout sees the user. JSON requests still get Laravel's JSON 404
  (`abort()` honours `expectsJson()`).
- Test: a signed-in office user on `/no-such-page` → 404 page with "Back to
  the dashboard"; factory → "Back to picking"; guest → "Sign in"; a JSON
  request to `/no-such-page` → JSON.

Check: `php artisan test --filter=ErrorPagesTest` pass;
`php artisan route:list | wc -l` is the previous count + 1.

### 3. The third allocate refusal (cycle-4 note)

Files: `app/Http/Controllers/OrderAllocationController.php`,
`tests/Feature/OrderAllocationConcurrencyTest.php`

What: in `allocationRefusal()`, after the "already fully allocated" check and
before the batch-availability check: `$needed = ordered − allocated`; if
`$quantity > $needed` → `"This line only needs {$needed} more — allocate at
most that many."`. Test: an order of 10 with 6 allocated, batch holding 50,
request 5 → that message; request 4 → allocates.

Check: `php artisan test --filter=OrderAllocationConcurrencyTest` pass.

### 4. Login looks like Mossfield and puts the cursor where it's needed (F-03, F-04)

Files: `resources/views/layouts/guest.blade.php`, `resources/views/auth/login.blade.php`,
`tests/Feature/Auth/AuthenticationTest.php`

What:
- Replace `<x-application-logo>` with the app's mark: the same "M" square +
  "Mossfield Dairy" markup as `layouts/navigation.blade.php:~115–123`
  (extract it into `resources/views/components/brand-mark.blade.php` and use
  it in both places so they can't drift). Page background `var(--bg)`, card
  uses `mf-panel`; keep the form itself.
- `login.blade.php`: `#login` gets `autofocus` only when `old('login')` is
  empty; otherwise `#password` gets it (the username is kept after a failed
  attempt, the password is what needs retyping).
- Tests: the login page contains "Mossfield" and not the Laravel logo's SVG
  path; after a failed POST, following the redirect gives a page where the
  `autofocus` attribute is on the password input.

Check: `php artisan test --filter=AuthenticationTest` pass. Browser: `/login`
shows the mark; wrong password → cursor in the password box.

### 5. Topbar divider (F-05)

Files: `resources/views/layouts/partials/topbar.blade.php`

What: the 1 px divider at `:48` gets `class="hidden sm:block"` so it hides
with the date chip it separates.

Check: browser at 500 px — bell, no stray bar.

### 6. Batch copy and the delete button (F-11, F-14)

Files: `resources/views/batches/create.blade.php`, `resources/views/batches/show.blade.php`,
`resources/views/batches/partials/batch-card.blade.php`,
`resources/views/components/stock/case-blocks.blade.php`,
`resources/views/components/stock/case-pictograph.blade.php`,
`app/Models/Batch.php`, `app/Http/Controllers/BatchController.php`,
`tests/Feature/BatchCreateTest.php`, `tests/Feature/StockOverviewTest.php`

What:
- **"wheel wheel"**: print `size` only when it differs from `unit` — in the
  create form's JS template and the show page's Size column.
- **Cases**: `{{ n }} cases × {{ caseSize }}` → when `n === 0`:
  `"{{ units }} loose — less than a case of {{ caseSize }}"`; otherwise
  `"{{ n }} {{ case|cases }} × {{ caseSize }}"` plus `" + {{ rem }} loose"`
  when there's a remainder. Check how `$totalCases` is computed (the run saw
  "1 cases × 16" for 12 bottles — if it's a ceil, make it the full-case
  count).
- **Ready countdown**: a tiny helper (Blade `@php` or a `Batch::readyIn()`
  accessor) — under 90 days away print `"in N days"` / `"N days ago"`,
  otherwise `diffForHumans()`. Both the card and the show page.
- **Raw milk on a converted batch**: when `source_batch_id` is set and
  `raw_milk_litres == 0`, print `—` (the lineage line already says where it
  came from).
- **Delete**: move `deletionBlocker()` from the controller to
  `Batch::deletionBlocker(): ?string` reading the item counts from the
  **loaded** relation when present (`$this->batchItems` with
  `order_allocations_count`, `source_cutting_logs_count`,
  `target_cutting_logs_count`, `source_conversion_logs_count`,
  `target_conversion_logs_count`) and falling back to one `withCount` query
  when not. `BatchController::destroy()` calls it. `BatchController::index()`
  adds the missing `withCount([...])` to its `batchItems` eager-load (still
  one query). The card renders Delete **only when `deletionBlocker()` is
  null**; otherwise a muted "Can't delete — N records depend on it" with the
  blocker as `title`. The dialog reads `"Delete batch {{ batch_code }}? This
  can't be undone."`.
- **F-14 "29 units"**: `Batch::remaining_by_variant` (or inline) → `"10
  wheels · 18 packs"` built from `batchItems` as `{{ qty }} {{ variant unit,
  pluralised }}` joined by ` · `; a single-variant batch keeps `"N units"`.
  Both the show page and the card.
- Tests: `BatchCreateTest` — the show page prints "wheel" once per wheel
  item (not "wheel wheel"), the per-variant stock line, "—" for raw milk on a
  converted batch, and "in 60 days"; the index renders Delete for a fresh
  batch and the "Can't delete" text for one with an allocation, with the
  query count for the index unchanged by the new counts (assert with
  `DB::enableQueryLog()` before/after: same number of queries).
  `StockOverviewTest` — 12 bottles of a 16-case → "12 loose — less than a
  case of 16".

Check: `php artisan test --filter="BatchCreateTest|StockOverviewTest|BatchFinishedWeightTest"`
pass. Browser `/batches`: G030426's Delete is gone (it has cuts and sales);
the Mature card's raw milk reads "—"; `/batches/26` reads "7 wheels · 18
packs".

### 7. The Mossorders id is tucked away (F-17)

Files: `resources/views/customers/create.blade.php`, `resources/views/customers/edit.blade.php`

What: wrap the "Online integration" block in a `<details>` that is **open**
only when the field has a value (`old()` or the customer's); summary "Online
integration (Mossorders link)"; hint → "The customer's user id in the
Mossorders portal — found on their account there. Only needed for shops that
order online."

Check: browser — collapsed on create; open on an edit page for a linked
customer. (No test; copy only.)

### 8. Dialogs name the thing they ask about (F-26, F-11 dialog)

Files: `resources/js/confirm.js`, `resources/views/orders/show.blade.php`,
`tests/Feature/ConfirmDialogTest.php`

What:
- `confirm.js`: support `data-confirm-cancel-label` (default "Cancel") and
  reset it on every open.
- Cancel order: `data-confirm-title="Cancel this order?"`,
  `data-confirm-cancel-label="Keep order"`, OK stays "Cancel order".
- Test: `/orders/{pending}` contains `data-confirm-cancel-label="Keep order"`.

Check: `php artisan test --filter=ConfirmDialogTest` pass; `npm run build`.
Browser: the cancel-order dialog reads Keep order / Cancel order.

### 9. A multi-order stop tells the driver what's what (F-27)

Files: `app/Http/Controllers/ChilledRunController.php`,
`resources/views/chilled-runs/partials/_run-table.blade.php`, `tests/Feature/ChilledRunTest.php`

What:
- `buildSheet()`: `references[customer_id]` becomes **all** non-empty refs,
  in order-number order, joined by ` · ` (the editor's prefill
  `editCustomerReference` keeps using the first — it edits one order).
- Loaded ticks on a multi-order stop: each gets `aria-label="Mark
  ORD-… loaded"` / `"ORD-… loaded — tap to unmark"` and a visible mono
  `order_number` beside the tick (only when the stop has > 1 order).
- `"Multiple orders — edit above"` → `"Multiple orders — open each to edit"`.
- Test: a stop with two orders shows both refs and both order numbers next
  to the ticks.

Check: `php artisan test --filter=ChilledRunTest` pass. Browser: E2E Corner
Shop's row on Tue 06/10 shows "Ref: PO-E2E-1 · PO-E2E-2" and labelled ticks.

### 10. Order numbers don't wrap (F-31)

Files: `resources/views/orders/index.blade.php`

What: `whitespace-nowrap` on the order-number and status cells.

Check: browser at 500 px — numbers on one line, table scrolls sideways.

### 11. One-page PDFs weigh what one page should (F-34)

Files: `config/dompdf.php` (new — published), `tests/Feature/DispatchConfirmationTest.php`

What:
- `php artisan vendor:publish --provider="Barryvdh\DomPDF\ServiceProvider"`
  (config only), then set `'enable_font_subsetting' => true` in `options`.
  Rebuild one dispatch confirmation and one invoice PDF (tinker →
  `app(App\Documents\DispatchConfirmation::class …)` or hit the routes) and
  record the sizes before/after. Expect well under 200 KB.
- Verify with `pdftotext file.pdf - | head` that `€`, `·`, `—` and a batch
  code survive subsetting.
- If subsetting doesn't bring it down, fall back to core fonts
  (`Helvetica`/`Courier` in `_pdf-styles`) and re-verify `€` — say which you
  did.
- Test: the existing PDF assertion in `DispatchConfirmationTest` gains
  `assertLessThan(300 * 1024, strlen($response->getContent()))`.

Check: `php artisan test --filter="DispatchConfirmationTest|InvoiceIssueTest"`
pass; sizes recorded in the report.

### 12. Finance labels (F-38)

Files: `app/Models/Invoice.php`, `resources/views/customers/create.blade.php`,
`resources/views/customers/edit.blade.php`, `tests/Feature/PaymentStateTest.php`

What:
- `getPaymentStateLabelAttribute()`: when state is `partial` and
  `amount_paid == 0` (credit only) → **"Credited"**; the state value stays
  `partial`.
- Customer form: an inline hint under Payment terms, shown (Alpine) when
  `invoicing_mode` is weekly/monthly **and** `payment_terms` is immediate:
  *"Consolidated invoices are due the day they're issued with Immediate terms
  — most weekly customers use Net 7 or Net 14."* No validation change.
- Test: an invoice with a credit note and no payment has label "Credited"
  and state `partial`.

Check: `php artisan test --filter="PaymentStateTest|InvoicingAccessTest"` pass.

### 13. Docs and the small-fixes list

Files: `CLAUDE.md`, `docs/planImp/SMALL_FIXES.md`, `docs/planImp/WORK_IN_PROGRESS.md`

What:
- `CLAUDE.md`: Frontend Development → the flash region is sticky with a
  dismiss and `:target` highlight; `Route::fallback` exists; `confirm.js`
  supports `data-confirm-cancel-label`; PDFs use font subsetting
  (`config/dompdf.php`); `Batch::deletionBlocker()` is the one place the
  delete rule lives (views hide the button, the controller refuses).
- `SMALL_FIXES.md`: tick **Destructive actions use native `confirm()`**
  (done 2026-10-04, cycle 1 — the list predates it) with a one-line note;
  leave the weight-input item and the Judgement section as they are.
- `WORK_IN_PROGRESS.md`: *Recently Completed: Copy & polish (2026-10-04)*.

Check: `grep -n "fallback\|cancel-label\|font_subsetting\|deletionBlocker" CLAUDE.md` → hits.

## Verification

1. `php artisan test` → **0 failed**; record the count.
2. `./vendor/bin/pint --test app tests database routes` → clean.
3. `npm run build` → ok.
4. `php artisan route:list | wc -l` → previous + 1 (the fallback).
5. `git status --short` → only files this plan names (+ the staged archive).
6. Browser checks from steps 1, 4, 5, 6, 7, 8, 9, 10 with real input; console
   clean. PDF sizes before/after in the report.

## Risks

- **Sticky flash over fixed headers**: `z-index: 30` must sit below the modal
  (`z-50`) and above the content; check the mobile drawer (`z-40`) still
  covers it.
- **`:target` animation** fires on every fragment landing, including the
  browser's own back/forward — harmless.
- **Font subsetting** can drop a glyph the layout uses (bullets, en/em
  dashes, €). `pdftotext` is the check; the core-font fallback is the exit.
- **`Route::fallback`** must stay **last** in `web.php`; any route file loaded
  after it would be shadowed. `api.php` is a separate group and unaffected.
- **Hiding Delete** must not add a query per card: the counts ride the
  existing eager-load. The query-count assertion is the guard.

## Review

Reviewed 2026-10-04 against `implemented.md` (Status: DONE, 522 lines, read to
the end) and the diff of every PHP file plus the JS, CSS, feedback partial,
guest layout, login, brand mark and the three view families with logic.
Planner reran: `php artisan test` → **612 passed, 0 failed** (+14);
`pint --test app tests database routes config` → PASS 277 files; `npm run
build` ok; `route:list | wc -l` → 158; dev DB: orders 23 (two cancelled
cycle-5 probes), payments 4, invoices 7, batches 13, customers 18, no
customer linked to Mossorders; a real invoice PDF rendered at **40,549 B**.

### Criteria — all thirteen pass

1 Sticky `.mf-feedback` (z-30, below drawer/modal), per-message × that also
hides the wrapper when the last one goes (deviation 3 — found and fixed in
the browser), `:target` tint, `scroll-mt-20` on both sheet rows.
2 `Route::fallback(fn () => abort(404))` last in `web.php`; office/factory/
guest/JSON cases tested; the boundary test's allowlist gains the fallback
(deviation 2 — necessary and correct). 3 "This line only needs N more" sits
between the two existing refusals. 4 `<x-brand-mark>` shared by sidebar and
guest layout; `:autofocus` flips to the password after a failed attempt;
title fallback "Mossfield". 5 Divider `hidden sm:block`. 6 `Batch::
deletionBlocker()` + `dependentRecordCount()` read the five `*_count`s from
the index's eager-load (one query) else one `withCount`; card hides Delete
and names the batch in the dialog; `remaining_label` ("7 wheels · 18 packs",
or variant names when units collide — deviation 6, better than the plan);
`readyIn()`; raw-milk dash; full-case label with loose remainder. 7 Details
collapsed unless valued or refused. 8 `data-confirm-cancel-label` reset on
every open; Keep order / Cancel order. 9 All refs joined; per-order tick
labels and numbers on multi-order stops. 10 Nowrap on number and status.
11 Font subsetting: 1.37 MB → 36 KB, 1.76 MB → 40 KB, `pdftotext` output
identical before/after, €/—/· verified; red-checked. 12 "Credited" label,
state value unchanged; terms hint on the customer form. 13 Docs and
`SMALL_FIXES.md` ticked.

### Deviations — all accepted

1 baseline; 2 boundary-test allowlist; 3 wrapper hides when empty; 4 the
success case in step 3's test uses a second batch item because of the bug
below; 5 the query-count assertion pins this step's queries, not the page
total (the page already had an N+1 — below); 6 variant names when units
collide and proper pluralisation; 7 details open on a validation error; 8
`dependentRecordCount()`; 9 title fallback; 10 probes (recorded).

### Notes for Planner — decisions

- **`allocate()` 500s when topping up a reservation from the same batch**
  (`allocateFromBatchItem()` always inserts; `order_allocations` is unique
  per order_item + batch_item; `pickFromBatchItem()` already widens via
  `extendAllocation()`). A real S2 found by the Implementer → **cycle 6**,
  test-first.
- **`/batches` N+1 in `BatchItem::wheelStates()`** (per-item SUMs although
  the index eager-loads `quantity_currently_allocated` and `converted_wheels`)
  → **cycle 6**.
- **Run-sheet `<thead>` not sticky** so a saved row lands with the column
  names off-screen → **cycle 6** (`position: sticky; top: 0` on `.mf-run thead
  th`; the flash collapses after dismissal so `top: 0` is fine).
- `/orders` Payment and actions cells still wrap at 500 px → **cycle 6**,
  two `whitespace-nowrap`s.
- `components/application-logo.blade.php` unused → **cycle 6**, delete.
- PDF `·` glyph proven on the docket → nothing to do.

Nothing to redo. Archive: `mkdir -p docs/planImp/archive/2026-10-04-copy-and-polish`
and move `plan.md` + `implemented.md` there.
