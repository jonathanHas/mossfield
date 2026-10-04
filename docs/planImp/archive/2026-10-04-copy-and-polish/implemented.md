# Copy and polish: the S4 findings and the cycle-4 follow-ons — implementation

Status: DONE
Plan revision: 1
Implementer: Opus
Date: 2026-10-04

## Baseline
HEAD: 390cb7c (the plan's Context says `f6e9de4` + a staged archive; that archive has since been committed as `390cb7c`)
Pre-existing dirty files: clean
`php artisan route:list | wc -l` before: 157

## Steps

### 1. A redirect that lands on an element still shows its message — done
Changed: `resources/views/layouts/partials/feedback.blade.php` (wrapper gets
`mf-feedback` + `pb-2`; each flash and the error block get Alpine
`x-data="{ show: true }" x-show="show"` and a `type="button"` ×
`.mf-feedback-close` with `aria-label="Dismiss message"`), `resources/css/app.css`
(`.mf-feedback`: `position: sticky; top: 0; z-index: 30; background: var(--bg)`
— below the drawer's z-40 and the modal's z-50; `.mf-feedback-close`;
`.scroll-mt-20:target { animation: mf-target 1.8s ease-out }` with an
accent-soft → transparent keyframe), `resources/views/chilled-runs/partials/_run-table.blade.php`
(both `<tr id="stop-…">` gain `scroll-mt-20`), `tests/Feature/LayoutFeedbackTest.php`
(+1: exactly once, `class="mf-feedback `, the dismiss button).
Check output:
```
$ php artisan test --filter=LayoutFeedbackTest
  Tests:    6 passed (35 assertions)
```
Browser: see *Browser checks*.

### 2. An unmatched URL still knows who you are — done
Changed: `routes/web.php` — `Route::fallback(fn () => abort(404));` is the
last line, after `require __DIR__.'/auth.php'`.

`tests/Feature/ErrorPagesTest.php` +2:
- On `/no-such-page`, a signed-in office user gets "Back to the dashboard"
  and factory gets "Back to picking".
- A JSON request to `/no-such-page` gets JSON.
- The existing guest test still sees the login link.

`tests/Feature/AuthorizationBoundaryTest.php`
- `UNGUARDED_URIS` gains `{fallbackPlaceholder}`. The fallback carries
  neither `auth` nor `guest`; it only aborts 404. See Deviations.
Check output:
```
$ php artisan test --filter="ErrorPagesTest|AuthorizationBoundaryTest"
  Tests:    27 passed (1401 assertions)
$ php artisan route:list | wc -l
158        (before: 157)
```

### 3. The third allocate refusal — done
Changed: `app/Http/Controllers/OrderAllocationController.php` —
`allocationRefusal()` checks `$needed = ordered − allocated`; if `$quantity >
$needed` it returns "This line only needs {$needed} more — allocate at most
that many.". That check sits between "already fully allocated" and the
batch-availability check.

`tests/Feature/OrderAllocationConcurrencyTest.php` +1:
- Line of 10 with 6 allocated, batch at 50: requesting 5 gives that message
  and nothing changes.
- Requesting 4 allocates (10 allocated). The 4 comes from a **second** batch
  item; see Notes for why.
Check output:
```
$ php artisan test --filter=OrderAllocationConcurrencyTest
  Tests:    6 passed (22 assertions)
```

### 4. Login looks like Mossfield and puts the cursor where it's needed — done
Changed:
- **new** `resources/views/components/brand-mark.blade.php`: the "M" square
  plus "Mossfield Dairy" in a link (`href` prop, default `dashboard`).
- `resources/views/layouts/navigation.blade.php`: the sidebar's inline brand
  markup is replaced by `<x-brand-mark />`.
- `resources/views/layouts/guest.blade.php`:
  - `<x-brand-mark href="/" />` replaces `<x-application-logo>`.
  - The body uses `var(--bg)` / `var(--ink)` instead of `bg-gray-100`.
  - The card is `mf-panel` instead of `bg-white shadow-md`.
  - `<title>` falls back to "Mossfield".
- `resources/views/auth/login.blade.php`: `#login` gets
  `:autofocus="blank(old('login'))"` and `#password` gets
  `:autofocus="filled(old('login'))"`.
- `tests/Feature/Auth/AuthenticationTest.php` +2:
  - The page has "Mossfield", not the Laravel logo path; autofocus is on
    `#login`.
  - After a failed POST, the followed page has autofocus on `#password`,
    none on `#login`, and keeps the username.
- `components/application-logo.blade.php` is now unused but left in place.
Check output:
```
$ php artisan test --filter=AuthenticationTest
  Tests:    8 passed (23 assertions)
```

### 5. Topbar divider — done
Changed: `resources/views/layouts/partials/topbar.blade.php` — the 1 px divider
gets `class="hidden sm:block"`.
Check: browser (see *Browser checks*).

### 6. Batch copy and the delete button — done
Changed:

`app/Models/Batch.php`
- `getRemainingLabelAttribute()` → "10 wheels · 18 packs" from the loaded
  `batchItems` (produced > 0), "N units" for a single variant, and "214 × 1L
  Bottle · 80 × 2L Bottle" when two variants share a unit (see Deviations).
- `readyIn()` → "today" / "in N days" / "N days ago" within 90 days,
  otherwise `diffForHumans()`.
- `hasNoRawMilkOfItsOwn()`: `source_batch_id` set and raw milk 0.
- `deletionBlocker()` was moved from the controller; same rule and message.
- `dependentRecordCount()` and a private `dependentCounts()` read the
  `*_count` attributes from loaded `batchItems` when every item carries all
  five, else one `withCount` query.

`app/Http/Controllers/BatchController.php`
- `index()` adds `orderAllocations`, `targetCuttingLogs`,
  `sourceConversionLogs` and `targetConversionLogs` to the existing
  `withCount` — still one batch_items query.
- `destroy()` calls `$batch->deletionBlocker()`; the private method is gone.

`resources/views/batches/partials/batch-card.blade.php`
- Ready countdown uses `readyIn()`.
- Raw milk shows "—" via `hasNoRawMilkOfItsOwn()`.
- Stock shows `remaining_label`, which no longer runs a per-card `SUM`.
- Delete renders only when `deletionBlocker()` is null. The dialog reads
  "Delete batch {code}? This can't be undone.", titled "Delete batch?".
- A blocked batch instead shows a muted `data-delete-blocked` note, "Can't
  delete — N record(s) depend(s) on it", with the blocker as `title`.

`resources/views/batches/show.blade.php`
- Same raw-milk dash, `readyIn()` and `remaining_label`.
- The Size column prints the unit only when it differs from the size.

`resources/views/batches/create.blade.php`
- The JS template prints the unit only when it differs from the size.

`components/stock/case-blocks.blade.php` + `case-pictograph.blade.php`
- `$totalCases` *is* a `ceil` and still drives the drawing, so the part case
  stays drawn.
- The label now uses `intdiv`/`%`: "12 loose — less than a case of 16", "2
  cases × 16 + 8 loose", "1 case × 16".

Tests
- `BatchCreateTest` +2:
  - The show page reads "10 wheels · 18 packs" and "in 60 days", with no
    "wheel wheel" and one bare "wheel".
  - A converted batch shows Raw milk "—".
  - On the index, a fresh batch has the new Delete dialog and an allocated
    batch shows "Can't delete — 1 record depends on it" (once).
  - With 2 → 4 batches, still exactly **one** query carries the deletion
    counts and **zero** per-card `sum(quantity_remaining)` (see Deviations
    for why this isn't a total-count assertion).
- `StockOverviewTest` +1: "12 loose — less than a case of 16" and "2 cases ×
  16 + 8 loose".
Check output:
```
$ php artisan test --filter="BatchCreateTest|StockOverviewTest|BatchFinishedWeightTest"
  Tests:    27 passed (159 assertions)
```
Browser: see *Browser checks*.

### 7. The Mossorders id is tucked away — done
Changed: `resources/views/customers/create.blade.php` and `edit.blade.php`.
- The "Online integration" block is now a `<details>`, summary "Online
  integration (Mossorders link)".
- It is open when the field has a value (`old()` or the customer's) **or**
  the field was refused (`$errors->has('mossorders_user_id')` — so a
  validation error isn't hidden).
- The hint is "The customer's user id in the Mossorders portal — found on
  their account there. Only needed for shops that order online."

No test (copy only, per the plan). The existing customer suites still pass:
```
$ php artisan test --filter=Customer
  Tests:    54 passed (191 assertions)
```

### 8. Dialogs name the thing they ask about — done
Changed: `resources/js/confirm.js` (header comment documents
`data-confirm-cancel-label`; `open()` sets `#confirm-action-cancel`'s text
from it, default "Cancel", on every open — so a previous dialog's label never
leaks), `resources/views/orders/show.blade.php` (Cancel order button:
`data-confirm-title="Cancel this order?"`, `data-confirm-cancel-label="Keep
order"`, OK stays "Cancel order"), `tests/Feature/ConfirmDialogTest.php` (+1:
a pending order page carries all three attributes). The batch delete dialog
from F-11 was named in step 6.
Check output:
```
$ php artisan test --filter=ConfirmDialogTest
  Tests:    4 passed (12 assertions)
$ npm run build
✓ built in 2.35s
```
Browser: see *Browser checks*.

### 9. A multi-order stop tells the driver what's what — done
Changed:

`app/Http/Controllers/ChilledRunController.php`
- `buildSheet()`: `references[customer]` is now every non-empty ref, in
  order-number order, joined by ` · `.
- A separate `$firstReferences` feeds `editCustomerReference`; the editor
  only opens on a single-order stop anyway.

`resources/views/chilled-runs/partials/_run-table.blade.php`
- On a stop with more than one order, each tick (and the static "dispatched"
  span) sits in a small flex row with a mono `data-tick-order` order number.
- Its `aria-label` is "Mark ORD-… loaded" / "ORD-… loaded — tap to unmark".
- A single-order stop keeps the old labels and no number.
- The edit-mode text now reads "Multiple orders — open each to edit".

`tests/Feature/ChilledRunTest.php` +2:
- Two orders show "Ref: PO-E2E-1 · PO-E2E-2", both labelled ticks, two order
  numbers and the new edit-mode text.
- A single-order stop keeps "Mark loaded" with no number.
Check output:
```
$ php artisan test --filter=ChilledRunTest
  Tests:    65 passed (270 assertions)
```

### 10. Order numbers don't wrap — done
Changed: `resources/views/orders/index.blade.php` — `whitespace-nowrap` on the
order-number `<td>` and the status `<td>`. The table's existing
`overflow-x-auto` scrolls sideways instead.
Check: browser (see *Browser checks*).

### 11. One-page PDFs weigh what one page should — done (subsetting; no font fallback needed)
Changed:
- **new** `config/dompdf.php`, from `php artisan vendor:publish
  --provider="Barryvdh\DomPDF\ServiceProvider" --tag=config`; the only edit
  is `'enable_font_subsetting' => true`, with a comment.
- `tests/Feature/DispatchConfirmationTest.php`: the PDF download test gains
  `assertLessThan(300 * 1024, strlen(...))`.

Sizes, rendered from dev order ORD-20260723-002 / INV-000001 through
`DispatchConfirmation::documentPdfData()` and `InvoiceService::pdf()->output()`:

| Document | Before | After |
|---|---|---|
| Dispatch confirmation | 1,368,871 B (1.37 MB) | **36,586 B** (36 KB) |
| Invoice | 1,761,109 B (1.76 MB) | **40,724 B** (40 KB) |
| Docket (after only) | — | 30,426 B |

Glyph check (`pdftotext`):
- Before and after, the extracted text of the dispatch confirmation and the
  invoice is **identical** (`diff` clean).
- `€` appears 13 times in each, `—` in the confirmation ("Dispatched: —"),
  and the batch codes M230726, M230726-1 and Y230726 are intact.
- `·` doesn't occur in those two documents (it didn't before either); the
  docket's footer "Generated … · Mossfield Organic Farm" shows it survives.

Red check: with `enable_font_subsetting => false` the new assertion fails
("Failed asserting that 1368426 is less than 307200."); restored to `true`.
Check output:
```
$ php artisan test --filter="DispatchConfirmationTest|InvoiceIssueTest"
  Tests:    33 passed (135 assertions)
```

### 12. Finance labels — done
Changed:
- `app/Models/Invoice.php`: `getPaymentStateLabelAttribute()` gives
  `'partial'` the label **"Credited"** when `amount_paid == 0` (credit notes
  only), else "Part paid". The `payment_state` **value** is unchanged.
- `resources/views/customers/create.blade.php` and `edit.blade.php`:
  - The Business-terms grid gets `x-data="{ terms, mode }"`, seeded in
    `x-init` from `x-ref`'d selects that update on `x-on:change`.
  - A warn-ink hint (`data-terms-hint`, `x-show` + `x-cloak`) sits under
    Payment terms: "Consolidated invoices are due the day they're issued with
    Immediate terms — most weekly customers use Net 7 or Net 14." It shows
    when terms are Immediate and invoicing is weekly or monthly. No
    validation change.
- `tests/Feature/PaymentStateTest.php` +2:
  - A credit-note-only invoice has state `partial` and label "Credited"; with
    a €5 payment it goes back to "Part paid".
  - The create form carries the hint.
Check output:
```
$ php artisan test --filter="PaymentStateTest|InvoicingAccessTest"
  Tests:    19 passed (90 assertions)
```

### 13. Docs and the small-fixes list — done
Changed:
- `CLAUDE.md`: a new *Feedback, fallbacks, dialogs and PDFs* subsection under
  Frontend Development covering
  - the sticky, dismissible flash region and the `:target` highlight;
  - `Route::fallback`, which must stay last, plus its boundary-test entry;
  - `data-confirm-cancel-label`;
  - PDF font subsetting through `config/dompdf.php`;
  - `Batch::deletionBlocker()` as the single delete rule.
- `docs/planImp/SMALL_FIXES.md`: **Destructive actions use native
  `confirm()`** is ticked, with a note that it was done in cycle 1
  (`confirm.js`, `ConfirmDialogTest`). The weight-input item and the
  Judgement section are untouched.
- `docs/planImp/WORK_IN_PROGRESS.md`: a new "Recently Completed: Copy & polish
  (2026-10-04)" entry.
Check output:
```
$ grep -n "fallback\|cancel-label\|font_subsetting\|deletionBlocker" CLAUDE.md
154:### Feedback, fallbacks, dialogs and PDFs
156:- **`Route::fallback(fn () => abort(404))`** is the **last** line of `routes/web.php` …
157:- **`confirm.js`** also reads `data-confirm-cancel-label` …
158:- **PDFs subset their fonts**: `config/dompdf.php` (published) sets `'enable_font_subsetting' => true` …
159:- **`Batch::deletionBlocker()`** is the one place the batch-delete rule lives …
```

## Browser checks
`php artisan serve --host=127.0.0.1:8000`, a fresh `npm run build`, real mouse
and keyboard. The tab came up hidden once and Jon brought it forward; after
that `visibilityState` was `visible`, 1408 px wide, or 500 × 659 for the
phone-width checks. No console errors on any page.

### Step 1
- **Order page.** I used a throwaway pending order with 6 lines (fixture made
  in tinker, ORD-20261004-012, id 23, notes "E2E-20261004 cycle5 …").
  - Changed the last line's **Ordered** 2 → 3 and pressed Enter: the URL
    became `#line-63`, the page scrolled 785 px, and the green "Item quantity
    updated." sat at the **top of the viewport** (`.mf-feedback`
    `position: sticky`, top 0 → bottom 70) ✓. The line showed the `mf-target`
    tint (visible in the screenshot) ✓.
  - **×** hid the flash ✓. The *first* run left the empty sticky wrapper
    28 px tall; I fixed that (the wrapper hides once every message is
    dismissed — see Deviations). Re-checked by setting the line back to 2:
    after ×, the wrapper is `display: none` ✓.
- **Sheet row.** Avoa Ballsbridge on Tue 06/10, `?edit=20`:
  - Typed 10L Churn 1 and the note "E2E-20261004 cycle5 (to cancel)", then
    Save. The page landed at `#stop-20` with the row top at **80 px**, just
    below the sticky flash (bottom 70) ✓, and the row tinted ✓.
  - The table's **column headers were off-screen** (thead bottom −429): the
    sheet's `<thead>` isn't sticky, so `scroll-mt-20` alone can't put the row
    under the headers. See Notes.
  - Re-edited and zeroed: "Order for Avoa Ballsbridge cancelled …"
    (ORD-20261004-013, id 24).

### Step 8 — dialogs
- Order 23, **Cancel order**: the dialog reads title "Cancel this order?",
  buttons **Keep order** / **Cancel order** ✓. Keep order closed it with no
  request sent; the order stayed pending ✓.
- The next dialog (a line's **Remove**) reset to "Are you sure?" / Cancel /
  Remove ✓.
- Order 23 was then cancelled via the dialog ("Order cancelled.").

### Step 9 — multi-order stop
- E2E Corner Shop, Tue 06/10: "Ref: PO-E2E-1 · PO-E2E-2" ✓.
- Ticks: ORD-20261004-001 is a static "Dispatched" span; ORD-20261004-003 has
  `aria-label="Mark ORD-20261004-003 loaded"`. Both order numbers show in
  mono beside the ticks ✓.
- "Multiple orders — open each to edit" ✓.

### Step 6 — batches
- `/batches`:
  - **G030426** shows "Can't delete — 15 records depend on it", no Delete ✓.
    Every batch with records shows the note; only Y041026 keeps Delete.
  - Mature cards G030426-1 and G010426-1 read Raw milk **"—"** ✓.
  - **G010426** reads Stock "7 wheels · 18 packs" ✓.
  - Milk reads "214 × 1L Bottle · 80 × 2L Bottle".
  - A single-variant Mature batch read "**1 units**". Fixed to pluralise
    (`Str::plural('unit', n)`); see Deviations.
- Y041026 **Delete**: the dialog reads "Delete batch?" / "Delete batch
  Y041026? This can't be undone." / Cancel / Delete. Cancelled, nothing
  deleted.
- `/batches/26` (G010426): Stock remaining "7 wheels · 18 packs"; the Size
  column shows "wheel" and "pack" with no "wheel wheel" ✓.
- `/stock`: "12 loose — less than a case of 16" (M041026-1, the F-14 case),
  "15 cases × 16", "12 cases × 8" ✓.
- No dev cheese batch is still maturing, so "in N days" is covered by
  `BatchCreateTest` only.

### Step 7 — Mossorders link
- `/customers/create`: the details element is **collapsed**, with summary
  "Online integration (Mossorders link)" and the new hint ✓.
- No dev customer is linked, so I set Mortons of Ranelagh (id 11) to 99999 in
  tinker for the check: `/customers/11/edit` opens it, value 99999 ✓.
  `/customers/12/edit` (unlinked) is collapsed, and clicking the summary
  expands it ✓.
- Reverted the link to null; no customer has a `mossorders_user_id` now.

### Steps 5 and 10 — 500 px
- Topbar: the bell `flex`, the divider `display: none`, the date
  `display: none` — no stray bar ✓.
- `/orders`: order numbers are one line (17 px tall), status tags are one
  line, and the table scrolls sideways (scrollWidth 888 in a 435 px box; a
  wheel scroll moved it 453 px) ✓.
  - The **Payment** cell ("Paid / inv") and the **View · Edit** cell still
    wrap; they're out of scope (the plan limits `/orders` to the number and
    status). See Notes.

### Step 4 — login
- Logged out: `/login` shows the "M · Mossfield Dairy" mark, the body is
  `rgb(250, 249, 246)` (`--bg`), there is no Laravel SVG, and focus is on
  `#login` ✓.
- Chrome autofilled the fields. I replaced the password with a wrong one and
  pressed Enter: "These credentials do not match our records.", username kept
  as `office_test`, password empty, **focus on `#password`** ✓.

## Deviations
1. **Baseline HEAD is `390cb7c`, not `f6e9de4` + staged archive** — the archive
   was committed before this cycle. Tree clean.
2. **`AuthorizationBoundaryTest::UNGUARDED_URIS` gains
   `{fallbackPlaceholder}`** (test file not named by the plan). The matrix
   test requires every web route to carry `auth` or `guest`; the fallback
   carries neither by design (it only aborts 404, for guests too). Without
   this entry step 2 can't pass.
3. **The feedback wrapper hides once every message is dismissed**
   (`x-data="{ open: N }"`, each × does `open--`, wrapper `x-show="open >
   0"`). The plan's per-flash `x-show` alone left a 28 px sticky padding strip
   covering the page top. Found in the browser.
4. **Step 3's success case allocates from a second batch item.** Allocating
   again from the *same* batch through `order-allocations.allocate` returns
   **500**: `allocateFromBatchItem()` always inserts, and
   `order_allocations` is unique per (order_item, batch_item). This is
   pre-existing and out of scope; see Notes.
5. **Step 6 query-count assertion pins this step's queries, not the page
   total.** Adding batches does add queries to `/batches`, but every one is a
   pre-existing per-item SUM from `BatchItem::wheelStates()` (allocations and
   `wheels_converted`). So the test asserts what the plan's risk names:
   exactly one query carries the deletion counts at 2 and at 4 batches, and
   zero per-card `sum(quantity_remaining)` (the card no longer runs one). See
   Notes for the N+1.
6. **`remaining_label` names variants when two share a unit** ("214 × 1L
   Bottle · 80 × 2L Bottle"). The plan's "{qty} {unit}" would print "214
   bottles · 80 bottles" for milk. Single-variant batches pluralise ("1
   unit", "4 units") — the plan's literal "N units" printed "1 units".
7. **The Mossorders `<details>` also opens on a validation error** for that
   field, not only when it holds a value, so a refusal isn't hidden.
8. **`dependentRecordCount()` added to `Batch`** alongside
   `deletionBlocker()`, for the card's "N records" wording. Both read one
   private `dependentCounts()`.
9. **`<title>` fallback on the guest layout** changed from 'Laravel' to
   'Mossfield' (same line as the logo change; `APP_NAME` normally wins).
10. **Two fixture orders and a temporary link in the dev DB** for the browser
    checks — see Verification 6.

## Verification
1. `php artisan test` → **612 passed (3900 assertions), 0 failed** (598 → 612:
   +14 new cases).
2. `./vendor/bin/pint --test app tests database routes config` → `PASS … 277 files`
   (I added `config` because `config/dompdf.php` is new).
3. `npm run build` → `✓ built`.
4. `php artisan route:list | wc -l` → **158** (before: 157; +1 = the fallback).
5. `git status --short` → only files this plan names, plus
   `tests/Feature/AuthorizationBoundaryTest.php` (Deviation 2) and
   `resources/views/layouts/navigation.blade.php` (step 4 says to use the
   brand mark there). The cycle-4 archive was already committed.
6. Browser checks for steps 1, 4, 5, 6, 7, 8, 9 and 10 with real input,
   console clean (above). PDF sizes are recorded in step 11.

Dev-DB probe:
- Before: orders 21, payments 4, invoices 7, batches 13, customers 18.
- After: **orders 23** (+2: id 23 ORD-20261004-012, created in tinker as the
  step 1/8 fixture; id 24 ORD-20261004-013 from the sheet save — **both
  cancelled**, both tagged "E2E-20261004 cycle5").
- **Payments 4, invoices 7, batches 13, customers 18** are unchanged. No
  stock moved: both orders were pending and never allocated.
- Mortons of Ranelagh's `mossorders_user_id` was set to 99999 for the step 7
  check and reverted to null.

## Files changed
```
 M CLAUDE.md
 M app/Http/Controllers/BatchController.php
 M app/Http/Controllers/ChilledRunController.php
 M app/Http/Controllers/OrderAllocationController.php
 M app/Models/Batch.php
 M app/Models/Invoice.php
 M docs/planImp/SMALL_FIXES.md
 M docs/planImp/WORK_IN_PROGRESS.md
 M resources/css/app.css
 M resources/js/confirm.js
 M resources/views/auth/login.blade.php
 M resources/views/batches/{create,show}.blade.php
 M resources/views/batches/partials/batch-card.blade.php
 M resources/views/chilled-runs/partials/_run-table.blade.php
 M resources/views/components/stock/{case-blocks,case-pictograph}.blade.php
 M resources/views/customers/{create,edit}.blade.php
 M resources/views/layouts/{guest,navigation}.blade.php
 M resources/views/layouts/partials/{feedback,topbar}.blade.php
 M resources/views/orders/{index,show}.blade.php
 M routes/web.php
 M tests/Feature/Auth/AuthenticationTest.php
 M tests/Feature/AuthorizationBoundaryTest.php        (Deviation 2)
 M tests/Feature/{BatchCreate,ChilledRun,ConfirmDialog,DispatchConfirmation,
   ErrorPages,LayoutFeedback,OrderAllocationConcurrency,PaymentState,
   StockOverview}Test.php
?? config/dompdf.php
?? docs/planImp/implemented.md
?? resources/views/components/brand-mark.blade.php
```
No pre-existing dirty files. Nothing committed.

## Notes for Planner
- **`allocate()` 500s when topping up an existing reservation from the same
  batch** (Deviation 4). Reproduce: line of 10, allocate 6 from batch X, then
  allocate 4 more from batch X → `UniqueConstraintViolation` on
  `order_allocations (order_item_id, batch_item_id)`.
  `OrderItem::pickFromBatchItem()` already solves this with
  `extendAllocation()`; `allocateFromBatchItem()` should widen the existing
  row the same way. Worth a test-first fix; it's an S2-ish crash on a normal
  office action.
- **`/batches` N+1 in `BatchItem::wheelStates()`**: one `SUM` of
  allocations and one of `wheels_converted` per item, even though
  `BatchController::index()` already eager-loads `quantity_currently_allocated`
  and `converted_wheels`. `wheelStates()` presumably calls the relation
  instead of the loaded attribute. About 3 queries per cheese card.
- **Run-sheet headers:** a saved row now lands below the sticky flash, but
  the sheet's `<thead>` isn't sticky, so the column names are off-screen.
  Fix: `position: sticky; top: <flash height>` on `.mf-run thead th`.
  Coordinating with a variable-height sticky flash is the fiddly part
  (`top: 0` with the flash collapsed after dismissal is simplest).
- **`/orders` at 500 px:** the Payment cell ("Paid / inv") and the actions
  cell ("View · Edit") still wrap; only the number and status were in scope.
- **`components/application-logo.blade.php`** is now unused. Safe to delete
  in a later housekeeping pass.
- **The PDF `·` glyph** isn't in the invoice or the dispatch confirmation for
  that dev order (no customer reference). The docket footer proves it
  survives subsetting; an invoice for an order with a PO ref would show it on
  the "Order … · Your ref" line.
