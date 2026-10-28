# UX review, part 1: honest dashboard, layout at 1280 px, quieter screens — implementation

Status: DONE
Plan revision: 2 (steps 1–14 under revision 1; 15–19 below)
Implementer: Opus
Date: 2026-10-13

## Baseline
HEAD: c27f101
Pre-existing dirty files:
```
 M bootstrap/cache/.gitignore          (mode 100755→100644 only)
 M docs/planImp/README.md
 M storage/app/.gitignore              (mode only)
 M storage/app/private/.gitignore      (mode only)
 M storage/app/public/.gitignore       (mode only)
 M storage/framework/.gitignore        (mode only)
 M storage/framework/cache/.gitignore  (mode only)
 M storage/framework/cache/data/.gitignore (mode only)
 M storage/framework/sessions/.gitignore   (mode only)
 M storage/framework/testing/.gitignore    (mode only)
 M storage/framework/views/.gitignore      (mode only)
 M storage/logs/.gitignore             (mode only)
?? docs/planImp/UX_REVIEW_2026-10-13.md
?? docs/planImp/plan.md
```

## Steps
### 1. Mossorders surface behind one flag (A5) — done
Changed: `config/services.php` (`mossorders.ui_enabled`), `.env.example`,
`layouts/navigation.blade.php`, `dashboard.blade.php` (`$showSyncAlert` + button),
`customers/{index,show,create,edit}.blade.php`,
`tests/Feature/OnlineOrdersVisibilityTest.php` (new — 4 cases; the fourth, beyond the
plan, pins that saving the customer form with the field hidden keeps
`mossorders_user_id`: absent keys aren't in `validated()`, so the link survives).
Check output:
```
php artisan test --filter=OnlineOrdersVisibilityTest
  Tests:    4 deprecated (28 assertions)
php artisan test --filter=AuthorizationBoundaryTest
  Tests:    21 deprecated (1444 assertions)
```
("deprecated" = the pre-existing PDO::MYSQL_ATTR_SSL_CA notice; 0 failures.)

### 2. Dashboard says what to do (A2, A3, B9, C11, C16) — done
Changed: `routes/web.php` (`toConfirmCount`, `toAllocateCount`, `overdueDeliveryCount`
cloned off `$unallocatedQuery`; comment updated), `app/Models/Order.php`
(`OPEN_STATUSES`, `isDeliveryOverdue()` — and `statusLabel()` for step 9, added in the
same edit), `dashboard.blade.php` (To-do summary, "Orders to action" / "Ready · to
dispatch" KPIs, Low stock KPI removed, grid 6→5, queue subtitle, DELIVERY column with
overdue tag, Pending/Confirm → per row, `whitespace-nowrap`, item detail moved to the
customer cell's `title`, Low stock + Recent activity panels removed),
`tests/Feature/DashboardTest.php` (+4 cases a–d).
Check output:
```
php artisan test --filter=DashboardTest
  Tests:    7 deprecated (38 assertions)
```

### 3. Overdue delivery visible on the order surfaces (A3) — done
Changed: `orders/index.blade.php` (Delivery cell), `orders/show.blade.php` (subtitle),
`order-allocations/index.blade.php` (new Delivery column, empty-row colspan 7→8),
`tests/Feature/DeliveryOverdueTest.php` (new — predicate cases + present/absent per surface).
Check output:
```
php artisan test --filter=DeliveryOverdueTest
  Tests:    6 deprecated (22 assertions)
```

### 4. Order header fits (B7) — done (browser check: see Verification)
Changed: `orders/show.blade.php` (title row + `data-order-actions` row inside one
`mb-7` wrapper; h1 `whitespace-nowrap`; disabled "Mark dispatched" button with
`title="Tick loaded onto van first"` + `data-dispatch-needs-loaded`; Edit hidden when
invoiced), `resources/css/app.css` (`.mf-btn-primary[disabled]` — no disabled rule existed).
See Deviations for where the invoice sentence went.
Check output:
```
php artisan test --filter=OrderTransitionsTest
  Tests:    26 deprecated (110 assertions)
```

### 5. Product page fits; Recent batches tells the truth (B6, C15-part) — done (browser check: see Verification)
Changed: `lg:grid-cols-[320px_1fr]` → `lg:grid-cols-[320px_minmax(0,1fr)]` in
`products/{show,edit}`, `products/variants/{create,edit}`, `orders/show` (5 hits);
`products/show.blade.php` Recent batches: "Total qty" → "Produced" (Σ `quantity_produced`,
no "kg"), Ready date prints "Expired" first, Status tag "Expired" for an active expired batch.
`tests/Feature/UxCopyTest.php` (new). The fresh-batch case sets remaining ≠ produced so the
`48` match can't come from the Remaining column.
Check output:
```
php artisan test --filter=UxCopyTest
  Tests:    2 deprecated (8 assertions)
```

### 6. Weigh-in gets its own row (B8) — done (browser check: see Verification)
Changed: `orders/partials/allocation-items.blade.php` — per-unit variable-weight branch
only: the ACTION cell keeps `<form id="fulfil-{allocation}">` with Pick select, Fulfil and
hidden `actual_weight_kg`; a new `<tr class="weight-entry">` after the allocation row
(same `@can('update')` + `quantity_remaining > 0` + per-unit condition) holds
`#weights_{id}` (flex-wrap row of `.weight-row`s, inputs `form="fulfil-{id}"`) and the
Total. colspan computed in `@php` (= 6 in this branch). JS, bulk and fixed branches untouched.
Check output:
```
php artisan test --filter=OrderFulfillmentWeightTest
  Tests:    7 deprecated (28 assertions)
php artisan test --filter="FulfilmentUnitWeightsTest|ConfirmDialogTest"
  Tests:    17 deprecated (80 assertions)
```

### 7. The auto-allocate banner knows when it's done (C19) — done
Changed: `orders/show.blade.php` — `$canAutoAllocate` / `$noStockLabels` in the head
`@php` (via a shared `$needsStock` closure); the green banner renders only when
`$canAutoAllocate`; otherwise (not fully allocated) a muted `mf-flash`
(`data-nothing-to-allocate`, panel/line/ink-2 inline colours — there is no neutral flash
class) "Nothing left to allocate. {labels joined ", … and …"} has/have no free stock —
close it/them short, or wait for a new batch."
`tests/Feature/OrderShowAllocationInlineTest.php` +1 case (no-stock line → no button +
message; add a stocked line → button back, message gone).
Check output:
```
php artisan test --filter=OrderShowAllocationInlineTest
  Tests:    6 deprecated (23 assertions)
```

### 8. Short-close copy and the stepper count (C17, SMALL_FIXES ×2) — done
Changed: dialog bodies in `orders/partials/allocation-items.blade.php` (now also
"The N unpicked unit(s) …" with `Str::plural`) and `picking/item.blade.php` (both);
`OrderController::shortCloseItem()` captures `$released` before `closeShort()` and appends
the reserved-units sentence only when > 0; `status-stepper.blade.php` denominator nets
`quantity_short` for short-closed lines; `SMALL_FIXES.md` both items `[x]` with a
"Done 2026-10-13 (cycle 13)" line; `OrderShortSupplyTest` +3 cases.
Check output:
```
php artisan test --filter="OrderShortSupplyTest|ConfirmDialogTest|PickingTest"
  Tests:    57 deprecated (204 assertions)
```

### 9. One word per status (C12) — done
Changed: `Order::statusLabel()` (added in step 2's edit); `ucfirst($order->status)` →
`->statusLabel()` in `orders/index`, `customers/show`, `order-allocations/index`;
`ucfirst($sibling->status)` in `orders/show`; filter option label "Preparing" → "Picking"
(value unchanged); prose in `orders/show` ("returns to <b>Picking</b>") and
`picking/item` ("goes back to Picking."). `UxCopyTest` +1 case.
Check output:
```
grep -rn "Preparing" resources/views      → (no output)
php artisan test --filter=UxCopyTest
  Tests:    3 deprecated (13 assertions)
```
`ucfirst($order->status)` remains in views the plan didn't list — see Notes for Planner.

### 10. Credit limit and the duplicate figure (C13, C14) — done
Changed: `customers/show.blade.php` (Business terms Credit limit + Account tile print
"No limit" when 0; Business-terms "Outstanding" row removed), `customers/index.blade.php`
(Credit limit column), `customers/{create,edit}.blade.php` (hint under the input, same
`text-[12px] mt-1` muted `<p>` as the billing-email hint). No test asserted `€0.00` for a
credit limit (the one `€0.00` hit is a dispatch-confirmation line in OrderShortSupplyTest),
so no existing test changed. `UxCopyTest` +1 case.
Check output:
```
php artisan test --filter="UxCopyTest|CreditLimitWarningTest|CustomerSpecialPriceTest|StatementTest"
  Tests:    34 deprecated (141 assertions)
```
(StatementTest added to the filter: it asserts "€25.00 in credit" on the customer page,
which the removed Outstanding row used to also render — the Net owed tile still does.)

### 11. Batch cards stop contradicting themselves (C15, C16) — done
Changed: `batches/partials/batch-card.blade.php` — status tag skipped when expired and
`active` (`sold_out`/`closed` still print); "Ready:" prints red "Expired" first;
delete-blocked sentence → a faint `mf-link`-styled "Delete" `<span aria-disabled
data-delete-blocked>` whose `title` is "Can't delete — N record(s) depend(s) on it.
{blocker}" (the literal "Can't" sits outside `{{ }}` so it isn't escaped to `&#039;` and
BatchCreateTest's raw-HTML assertion still matches). `batches/show.blade.php`: the
`@else` "More actions coming soon." arm removed. `UxCopyTest` +2 cases.
Check output:
```
php artisan test --filter="BatchCreateTest|UxCopyTest"
  Tests:    23 deprecated (130 assertions)
```

### 12. Dead controls (E27, E28) — done
Changed: `layouts/partials/topbar.blade.php` (Notifications button removed; divider + date
chip kept), `profile/edit.blade.php` (delete panel include removed, subtitle "Your account
details and password."), `profile/partials/delete-user-form.blade.php` deleted (unstaged
deletion — I briefly staged it with `git rm --cached` and reset it; the index is clean).
`profile.destroy`, `ProfileController::destroy()` and ProfileTest untouched. `UxCopyTest` +1.
Check output:
```
php artisan test --filter="ProfileTest|UxCopyTest|LayoutFeedbackTest"
  Tests:    18 deprecated (96 assertions)
```

### 13. Run sheet: honest strip, real zeros, calm capacity, no wraps (F31–F33, B10) — done (browser check: see Verification)
Changed: `_summary-strip.blade.php` (`$noOrderStops = rows − loadableCount`; each 100 %
branch says "All ordered stops covered/loaded/dispatched — N without an order" when
N > 0, else the existing sentence), `_run-table.blade.php` (stock footer prints a real `0`
when the column has ordered units, `—` otherwise; Capacity tag in the run header after
the driver eyebrow; `whitespace-nowrap` on "+ Ref"), `chilled-runs/index.blade.php`
(capacity `mf-flash-warn` removed), `invoicing/uninvoiced/index.blade.php`
(`whitespace-nowrap` on "Issue all N"). `ChilledRunTest` +3 cases (the strip copy is
asserted in its raw `&mdash;` form, as the view writes it).
Check output:
```
php artisan test --filter="ChilledRunTest|DispatchRunTest|InvoiceIssueTest"
  Tests:    107 deprecated (428 assertions)
```

### 14. Docs — done
Changed: `CLAUDE.md` (one line under *Online Orders Integration*), `DEPLOYMENT.md`
(`MOSSORDERS_UI_ENABLED=false` in the mossfield env block — that block is where the
`MOSSORDERS_*` vars live; the table above it is only the shared-secret table),
`docs/planImp/UX_REVIEW_2026-10-13.md` (Decisions → the Cycle 13 bullet now says
implemented / cycle 13, awaiting review, and that A3's picking default day wasn't in it).
Check output:
```
grep -n MOSSORDERS_UI_ENABLED CLAUDE.md DEPLOYMENT.md .env.example
.env.example:98:MOSSORDERS_UI_ENABLED=false
DEPLOYMENT.md:135:MOSSORDERS_UI_ENABLED=false   # default false; …
CLAUDE.md:849:The Online Orders nav item, dashboard sync banner/button and the customer-page …
```

## Deviations

1. **Step 4 — the invoiced-order sentence.** The plan moved it into the subtitle as
   "· on invoice INV-… — void it to edit" (lowercase, no full stop). `OrderTransitionsTest`
   line 407 (not listed in the plan's Risks) asserts the exact string
   `On invoice INV-… — void it to edit.`, and the plan says not to change tests beyond what a step
   says. Smallest fix: it is its own muted line directly under the subtitle (same
   `@can('update')` gate as before), keeping the exact wording. It is still out of the action
   bar, and Edit is still hidden when the order is invoiced.
2. **Step 9 — `statusLabel()` added during step 2.** Same model edit as `isDeliveryOverdue()`.
   The order changed, the content didn't.
3. **Step 1 — one extra test case** (the customer form saved with the field hidden keeps the
   `mossorders_user_id` link). It adds coverage and changes no behaviour.
4. **Browser checks done in headless Chrome over CDP**, not an interactive Chrome window. No
   Chrome MCP tool was attached to this session. Window 1280×900, `office_test`, against
   `http://mossfield.local` after `npm run build`. Probe and script in the session scratchpad.

## Verification

1. `php artisan test`:
   ```
   Tests:    726 deprecated, 1 passed (4590 assertions)
   Duration: 31.03s
   ```
   727 tests, 0 failed. The baseline was 699; this cycle adds 28. "deprecated" is the
   pre-existing PDO::MYSQL_ATTR_SSL_CA notice.
2. `./vendor/bin/pint --test app tests database routes config` → `PASS … 300 files`.
3. `grep -rn "released back" app resources/views` → no hits (exit 1).
4. `grep -rn "coming soon\|Activity log\|Low-stock" resources/views` → no hits (exit 1).
5. Browser (headless Chrome, 1280 px, office_test). Console errors on every page: `[]`.
   - **/dashboard:** no sync strip, no "Online orders" button, no "Online Orders" nav item.
     Summary: "To do: 1 order to confirm · 1 order to allocate · 2 past their delivery date."
     I created a pending probe order (#8, ORD-20261013-003, 2 × Farmhouse Whole Wheel, Avoca
     Rathcoole) with tinker; it shows **Confirm →**. ORD-20260710-001 shows **Allocate →** and
     the overdue tag. **But the queue table scrolls sideways inside its panel at 1280 px**: the
     STATUS column is the last one visible and the Confirm/Allocate column needs a scroll. See
     Notes.
   - **/products/1:** `scrollWidth 1265 === clientWidth 1265`, so no horizontal scroll. The 15 px
     gap to `innerWidth` (1280) is the headless vertical scrollbar, so the plan's literal
     `scrollWidth === innerWidth` can't hold with a classic scrollbar. `clientWidth` is the
     honest comparison. Same 1265/1265 on /orders/4, /orders/8 and /chilled-runs.
   - **/orders/4** (ready, unloaded): h1 height 36 px (one line). `[data-dispatch-needs-loaded]`
     is a disabled `<button>` titled "Tick loaded onto van first". After ticking Loaded: h1
     still 36 px, the disabled button is gone and the dispatch form is there. **Unticked
     afterwards**; `loaded_at` is null again (checked with tinker).
   - **Weigh-in (probe order 8):** Confirm → Auto allocate through the page's forms. The
     `tr.weight-entry` row renders, colspan 6 = 6 `<th>`s, and both inputs sit on one row
     (same `top`) with `form="fulfil-20"`. The form's `elements` include both `weights[]`.
     Typing 5.120 and 4.880 → Total `10.000`, hidden `actual_weight_kg` `10.000`. Fulfil
     recorded allocation 20 as 2/2 with `unit_weights_kg [5.12, 4.88]`. Undo restored the stock
     ("Undid fulfillment of 2 units."). **The allocation table still overflows its wrapper by
     8 px (637 vs 629)**, with or without the weigh-in row (measured by hiding it), so the cause
     is the base six columns at this width, not the weigh-in. See Notes.
   - **/chilled-runs** (Tuesday · Dublin, 15 stops, 2 with orders): Capacity is a tag in the run
     header ("Capacity: 1600 kg"), and there is no `mf-flash-warn`. The 1L milk column (24
     ordered, 0 free) now shows `0`; unordered columns with no stock show `—`. The strip isn't at
     100 % on today's data ("1/2", "2 of 4 units reserved"), so the "… — N without an order"
     copy can't show here. `ChilledRunTest` pins it instead.
   - **Probe order cancelled** through its Cancel form: "Order cancelled. Reserved and picked
     stock has been returned to its batch." Free Farmhouse wheels are back to 29, the same as
     before the probe. Order 8 stays in the dev DB as a cancelled order, since orders are never
     deleted; its note says it is a probe.

## Files changed
`git status --short` at the end. (pre) = dirty before this cycle.
```
 M .env.example
 M CLAUDE.md
 M DEPLOYMENT.md
 M app/Http/Controllers/OrderController.php
 M app/Models/Order.php
 M bootstrap/cache/.gitignore                         (pre, mode only)
 M config/services.php
 M docs/planImp/README.md                             (pre)
 M docs/planImp/SMALL_FIXES.md
 M resources/css/app.css
 M resources/views/batches/partials/batch-card.blade.php
 M resources/views/batches/show.blade.php
 M resources/views/chilled-runs/index.blade.php
 M resources/views/chilled-runs/partials/_run-table.blade.php
 M resources/views/chilled-runs/partials/_summary-strip.blade.php
 M resources/views/customers/{create,edit,index,show}.blade.php
 M resources/views/dashboard.blade.php
 M resources/views/invoicing/uninvoiced/index.blade.php
 M resources/views/layouts/navigation.blade.php
 M resources/views/layouts/partials/topbar.blade.php
 M resources/views/order-allocations/index.blade.php
 M resources/views/orders/index.blade.php
 M resources/views/orders/partials/allocation-items.blade.php
 M resources/views/orders/partials/status-stepper.blade.php
 M resources/views/orders/show.blade.php
 M resources/views/picking/item.blade.php
 M resources/views/products/{edit,show}.blade.php
 M resources/views/products/variants/{create,edit}.blade.php
 M resources/views/profile/edit.blade.php
 D resources/views/profile/partials/delete-user-form.blade.php
 M routes/web.php
 M storage/**/.gitignore (10 files)                   (pre, mode only)
 M tests/Feature/ChilledRunTest.php
 M tests/Feature/DashboardTest.php
 M tests/Feature/OrderShortSupplyTest.php
 M tests/Feature/OrderShowAllocationInlineTest.php
?? docs/planImp/UX_REVIEW_2026-10-13.md               (pre)
?? docs/planImp/implemented.md
?? docs/planImp/plan.md                               (pre)
?? tests/Feature/DeliveryOverdueTest.php
?? tests/Feature/OnlineOrdersVisibilityTest.php
?? tests/Feature/UxCopyTest.php
```
`public/build/` was rebuilt (`npm run build`) for the browser pass. It is untracked output.
Nothing is staged, committed or pushed.

## Notes for Planner

1. **The dashboard order queue still scrolls sideways at 1280 px** (screenshot
   `dashboard.png` in the session scratchpad). In the 1fr track beside the 360 px rail, the
   nowrap ORDER cell, the VALUE, the DELIVERY date + "overdue" tag, the STATUS tag and the
   nowrap button add up to more than the panel. The customer name wraps to four lines and the
   **Confirm → / Allocate → column needs a sideways scroll**. Possible fixes: drop the VALUE or
   ITEMS column, put the overdue tag under the date, or stack the side rail below the queue
   under `xl`. Part 2 or a follow-up.
2. **The order-page allocation table overflows by 8 px at 1280 px** (637 in 629). Six
   `mf-td` columns at 16 px padding each side. It's independent of the weigh-in row (the step-6
   check "no longer scrolls sideways" holds for the weigh-in, not for the table as a whole).
   Tighter padding on this table, or merging Allocated/Fulfilled into one "2 / 0" column,
   would clear it.
3. **`ucfirst($order->status)` remains in views the plan didn't list**, so "Preparing" can
   still show there: `orders/edit.blade.php:54` (the status tag on the edit page, which the
   office does see), `orders/docket.blade.php:113` (printed), `online-orders/index.blade.php:92`
   (hidden now), and `invoicing/uninvoiced/index.blade.php:59` (dispatched/delivered only, so
   harmless). The first two probably want `statusLabel()`.
4. **The dashboard queue footer still says "Showing N of M unallocated"**, but the list now
   includes pending orders to confirm. "Showing N of M to action" would match the new KPI.
5. **The plan's product-page check `scrollWidth === innerWidth`** can't pass with a classic
   (non-overlay) vertical scrollbar. `scrollWidth === documentElement.clientWidth` is the
   reliable form for future plans.
6. **`customers/show.blade.php` ~line 319** still shows an "Online" tag on orders that came
   from Mossorders (`mossorders_order_id`). The plan didn't list it under A5. It is order
   provenance rather than a link control, so I left it.
7. **`Fulfil` via `form.requestSubmit()`** (as my CDP script did) skips the confirm dialog,
   because `confirm.js` hooks the button click. A real click gets the dialog as before. This is
   pre-existing, not a regression; I'm noting it only because it is how the probe pick went
   through without the dialog.
8. With the bell gone, the topbar's divider sits alone before the date chip. The plan said to
   keep it, so I did.

---

# Revision 2 — steps 15–19

Baseline for revision 2: HEAD `c27f101` (unchanged), working tree = the revision-1 changes
listed under *Files changed* above (accepted, uncommitted). Only the files named below are
touched in revision 2.

## Steps (revision 2)

### 15. The dashboard queue must not scroll sideways — done
Changed: `dashboard.blade.php` — main grid `lg:grid-cols-[minmax(0,1fr)_360px]` →
`2xl:grid-cols-[minmax(0,1fr)_360px]` (comment updated); ITEMS `<th>` and its `<td>` removed
(`$totalOrdered` stays, it feeds `$isPartial`). `DashboardTest::test_the_queue_offers_confirm_for_pending_and_allocate_for_confirmed`
now also asserts `assertDontSee('>Items</th>', false)` (and the step-18 footer).
Check output (headless Chrome over CDP, 1280×900, office_test; queue holds ORD-20260710-001):
```
DASH {"rows":1,"scroll":[998,998],"ok":true,
      "ths":["Order","Customer","Value","Delivery","Status",""],
      "btnsVisible":["Allocate →:true"],"doc":[1280,1280],"railBelow":true}
```
`w.scrollWidth <= w.clientWidth` true; the button sits inside the wrapper's visible box; the
Maturing rail renders below the queue. (Only one queued order in the dev DB now; the revision-1
probe is cancelled. The panel is 998 px wide against the 738 px table the Planner measured.)

### 16. The allocation table's 8 px overflow — done
Changed: `resources/css/app.css` (`.mf-table-tight .mf-th, .mf-table-tight .mf-td { padding-left: 10px; padding-right: 10px; }`
right after `.mf-td`), `orders/partials/allocation-items.blade.php` (`mf-table-tight` on the
"Current allocations" `<table>`).
Check output (same session):
```
ORDER4 {"tight":4,"wraps":[[629,629,true],[629,629,true],[629,629,true],[629,629,true]],"pad":"10px"}
ORDER5 {"tight":5,"wraps":[[629,629,true],[629,629,true],[629,629,true],[629,629,true],[629,629,true]],"pad":"10px"}
```
Every allocations wrapper has `scrollWidth <= clientWidth`. Orders 4 and 5 are `ready`, so their
action cells hold the Undo form, not the Pick/Fulfil/Remove form whose 637-in-629 overflow
revision 1 measured on probe order 8. I didn't create another probe order, because it would
stay in the dev DB as a second cancelled order. By arithmetic, 10 px instead of 16 px on both
sides of 6 columns frees 72 px, against the 8 px that was missing.

### 17. `statusLabel()` on the four remaining views — done
Changed: `orders/edit.blade.php:54`, `orders/docket.blade.php:113`,
`online-orders/index.blade.php:92`, `invoicing/uninvoiced/index.blade.php:59` →
`{{ $order->statusLabel() }}` (all four loop or route variables are `App\Models\Order`).
Check output:
```
grep -rnE 'ucfirst\(\$[a-zA-Z]*->status\)' resources/views   → (no output, rc=1)
```
See Deviations (rev 2) for the grep form.

### 18. Queue footer wording — done
Changed: `dashboard.blade.php` "Showing N of M unallocated" → "Showing N of M to action".
Check output:
```
grep -n "unallocated" resources/views/dashboard.blade.php
21:        $unallocated = $unallocatedOrders ?? collect();     (PHP variable names only —
22:        $unallocatedN = $unallocatedCount ?? 0;              no visible copy)
197/219/284: $unallocated->isEmpty() / foreach / ->count()
php artisan test --filter=DashboardTest → Tests: 7 deprecated (40 assertions)
```

### 19. The lone divider — done
Changed: `layouts/partials/topbar.blade.php` — the 1 px divider `<div>` and its F-05 comment
removed; the date chip stays.
Check output:
```
php artisan test --filter="LayoutFeedbackTest|UxCopyTest|DashboardTest|OrderTransitionsTest"
  Tests:    47 deprecated (230 assertions)
```

## Deviations (revision 2)
1. **Step 17's grep.** The plan's `grep -rn "ucfirst(\$[a-zA-Z]*->status)" resources/views`
   already returned nothing *before* the change: in GNU basic regex the `$` it ends up with
   doesn't match a literal `$`. So it can't prove anything. I used
   `grep -rnE 'ucfirst\(\$[a-zA-Z]*->status\)'`, which found exactly the four views before the
   edit and nothing after. The literal plan grep also returns nothing now.
2. **Step 16's browser check** was made on the two `ready` orders that have allocations, not on
   a confirmed order with a per-unit Fulfil form (see the step for why and the arithmetic).

## Verification (revision 2)
1. `php artisan test` → `Tests: 726 deprecated, 1 passed (4592 assertions)`, so 727 tests,
   0 failed.
2. `./vendor/bin/pint --test app tests database routes config` → `PASS … 300 files`.
3. Chrome checks for steps 15 and 16: see above. Assets rebuilt with `npm run build` first.
   Console errors: `[]`.

## Files changed (revision 2 only)
```
 M resources/css/app.css
 M resources/views/dashboard.blade.php
 M resources/views/invoicing/uninvoiced/index.blade.php
 M resources/views/layouts/partials/topbar.blade.php
 M resources/views/online-orders/index.blade.php
 M resources/views/orders/docket.blade.php
 M resources/views/orders/edit.blade.php
 M resources/views/orders/partials/allocation-items.blade.php
 M tests/Feature/DashboardTest.php
```
(Several of these were already modified by revision 1.) Nothing staged, committed or pushed.
The dev DB is untouched in revision 2: page loads only, no form posts.

## Notes for Planner (revision 2)
1. Future plans could pin the step-17 grep in its `-E` form. The basic-regex `\$` spelling
   silently matches nothing.
2. `.mf-table-tight` is general-purpose. Other dense nested tables (for example the batch
   page's item table) could take it if they ever overflow. I didn't apply it anywhere else.
