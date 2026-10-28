# UX review, part 1: honest dashboard, layout at 1280 px, quieter screens

Status: ACCEPTED
Revision: 2
Planner: Fable 5.1
Date: 2026-10-13

## Goal

The Planner walked the app in Chrome as the office role on 2026-10-13 and
wrote up 34 findings in `docs/planImp/UX_REVIEW_2026-10-13.md`. Jon decided
three of them: finding 1 (the order form takes lines with no stock) is
**expected** — orders are taken before the milk is bottled, so nothing
changes; finding 4 (send the invoice from the order page) is **deferred**;
finding 5 (online-sync noise) becomes **hide the whole Mossorders surface
until that work resumes**, behind one env flag.

This cycle takes the rest of the flow and layout findings (A2, A3, A5, B6–B10,
C11–C17, C19, E27, E28, F31–F33) plus the two open `SMALL_FIXES.md` items the
walk-through re-confirmed (the short-close dialog over-promises; the stepper
counts short-closed units). The forms, lists and remaining copy (D20–D26,
E29, F34, the fulfil/pick wording) are **part 2**, a separate plan.

What changes for the office: the dashboard tells them what to do next
(confirm this, allocate that, this one's delivery date has passed) instead
of offering "Allocate →" on an order that can't be allocated; the order
page and the product page fit a 1280 px laptop; the cheese weigh-in gets a
row of its own; the Mossorders banner, button and nav item are gone until
the integration is switched on; and a dozen sentences say what is true.

## Context

HEAD `c27f101`, `main`, working tree clean apart from the review doc and
the README row that indexes it. Baseline run by the Planner on 2026-10-13:
**699 tests, 0 failed** (PHPUnit flags 698 as "deprecated" — pre-existing
notices, not failures), 4 456 assertions, 29 s;
`pint --test app tests database routes config` clean across 297 files.
Any failure after this cycle is real.

**Facts the steps rely on**

- **Dashboard** is a route closure in `routes/web.php` (lines 32–109), view
  `resources/views/dashboard.blade.php`. The queue query (`$unallocatedQuery`)
  already includes `pending`, but every row renders "Unallocated" +
  "Allocate →"; `OrderAllocationController::index()` and
  `AllocatesOrderStock::ALLOCATABLE_STATUSES` both exclude `pending`, so the
  dashboard count (2) and the worklist (1) disagree. The age column is
  `order_date->diffForHumans(now(), ['short' => true, 'parts' => 1])` →
  "3mos before" / "13h before". The summary sentence is built in the view's
  `@php` block (`$summaryBits`), with the plural bug on line 26
  (`'need allocation'` for 1). `x-dash-kpi` is
  `resources/views/components/dash-kpi.blade.php` (`label`, `value`, `sub`,
  `tone`). `DashboardTest` pins only the expired-stock tile and "1 ready now".
- **Mossorders**: `config/services.php` → `mossorders.base_url` defaults to
  `https://mossorders.example.com` (never empty), `api_token` to null.
  `OnlineOrdersController` treats "configured" as both set. Scheduled sync is
  a *different* flag (`services.sync.enabled` / `SYNC_SCHEDULE_ENABLED`) —
  don't reuse it. Surfaces: nav item (`navigation.blade.php` line 8/26),
  dashboard button (line 71–77) + alert strip (`$showSyncAlert`, line 17 and
  92–105) + the "Online sync is behind" greeting clause (line 29–31),
  customers index filter "Online account" (line 32) and "Online · id" tag
  (65–66), customer show "Online" row (114–118) and header tag (15–16),
  customer create/edit `<details>` "Online integration (Mossorders link)"
  (create 133–143, edit 136–146). Routes stay: `AuthorizationBoundaryTest`
  walks them and `DeliveryChargeTest` posts to `online-orders.import`.
  `phpunit.xml` sets no `MOSSORDERS_*` env, so tests see the config default.
- **Order page header**: `resources/views/orders/show.blade.php` 105–198. The
  bar holds Docket · Loaded onto van · Edit · "Tick loaded onto van first" /
  Mark dispatched. `OrderTransitionsTest` (lines 427–447) asserts the page
  *contains* the string `Tick loaded onto van first` while unloaded and not
  once loaded, and the dispatch route likewise — a `title` attribute
  satisfies both. The page content column is `max-w-[920px]` inside
  `lg:grid-cols-[320px_1fr]` (line 29).
- **Product page overflow**: `products/show.blade.php` line 17 uses
  `lg:grid-cols-[320px_1fr]`. A `1fr` track's minimum is its content's
  min-content width, so the two `w-full` tables push the pane past the
  viewport even inside `overflow-x-auto` (measured: `scrollWidth` 1323 in a
  1280 window). The dashboard already uses the fix
  (`lg:grid-cols-[minmax(0,1fr)_360px]`). Same pattern in `products/edit`,
  `products/variants/create`, `products/variants/edit` and `orders/show`.
  `Batch` has **no** `total_quantity_kg` accessor — the Recent batches
  column prints `" kg"`. `ProductController::show()` eager-loads
  `batches.batchItems`, so `sum('quantity_produced')` is free.
- **Weight entry**: `orders/partials/allocation-items.blade.php` 123–201 —
  the per-unit form (`Pick` select, Fulfil, `#weights_{id}` rows, Total,
  hidden `actual_weight_kg`) lives inside the ACTION `<td>`. JS at 340–380:
  `updateWeightInputs()` toggles `.weight-row` display by index,
  `updateTotal()` sums visible `.weight-input`s. `$pickOnInput` uses
  `this.form.querySelector(...)` (the select stays inside the form). Weight
  arithmetic is server-side (`ValidatesFulfilmentWeights` recomputes the
  total from `weights[]`), so this is layout only. `submit-once.js` already
  handles `form="id"` buttons.
- **Auto-allocate banner**: `orders/show.blade.php` 350–370, condition
  `status ∈ {confirmed, preparing} && ! isFullyAllocated()`. It stays up
  when the only unallocated line has no stock. `$availableBatchItems` is
  `array<orderItemId, Collection<BatchItem>>` from `BuildsAllocationData`,
  built only when the user can update and the status is in the picking set
  — the same guard the banner sits under.
- **Short close**: dialog bodies in `allocation-items.blade.php` line ~328
  ("N units will be released back to stock, and won't be supplied or
  billed.") and `picking/item.blade.php` 159 + 363 ("The N unpicked units
  will be released back to stock and won't …"). Flash in
  `OrderController::shortCloseItem()` line 725 ("… released back to stock,
  not supplied and not billed."). `PickingController::markShort()` (line
  299) already says "won't be supplied or billed" — leave it.
  `OrderItem::closeShort()` releases exactly `quantity_allocated −
  quantity_fulfilled` (the reservation), nothing else. `ConfirmDialogTest`
  scans every `data-confirm`: a title is required, a body may not start
  with "Are you sure" or with its own title.
- **Stepper**: `orders/partials/status-stepper.blade.php` line 37 sums
  `quantity_ordered`; `OrderItem::quantity_short` and `isShortClosed()`
  exist (`app/Models/OrderItem.php` 81–110).
- **Status labels** print `ucfirst($order->status)` in `orders/index`
  (line 109), `orders/show` sidebar, `customers/show` (308),
  `order-allocations/index`; "Preparing" also appears in prose at
  `orders/show` 485 and `picking/item` 82, and as the filter option label
  in `orders/index` 28. No test asserts the word "Preparing".
- **Customer page**: `customers/show.blade.php` Business terms 78–79
  (credit limit), 94–100 (Outstanding row), Account tiles 153–172;
  `customers/index.blade.php` has a Credit limit column; forms
  `customers/create` 64–67 / `edit` 68–71.
- **Batch card**: `batches/partials/batch-card.blade.php` 44–63 (tags),
  73–81 (Ready: field), 176–182 (delete-blocked sentence).
  `BatchCreateTest` line 318 asserts the HTML contains
  `Can't delete — 1 record depends on it` and exactly one
  `data-delete-blocked` — keep both (a `title` attribute keeps the
  sentence). `batches/show.blade.php` 382: "More actions coming soon."
- **Run sheet**: `_summary-strip.blade.php` — `allocStopsTotal`,
  `loadableCount` are stops *with orders*; `$sheet['rows']->count()` is all
  stops. `DispatchRunTest` 280 asserts `All stops dispatched` on a one-stop
  run (unchanged by the rule below). Stock footer `_run-table` 397:
  `{{ $s['free'] ?: '—' }}`. Capacity banner `chilled-runs/index` 78–82
  (`mf-flash mf-flash-warn`); the run header row is `_run-table` 50–54.
- **Topbar bell**: `layouts/partials/topbar.blade.php` 42–47, no handler.
  **Profile delete**: `profile/edit.blade.php` includes
  `profile.partials.delete-user-form`; `ProfileTest` exercises the
  `profile.destroy` route directly (keep the route and those tests).
- Two Alpine rules (from `planimp.md`): never use an `@` shorthand that is
  also a Blade directive; anything nullable behind `x-show` needs `?.`.

## Constraints

- No stock, allocation or weight arithmetic changes. The free-stock
  expression and the five-state reconciliation (`TESTING_AND_FIXES.md` →
  *Invariants worth keeping*) are untouched; every step here is Blade, copy,
  one config flag, one model predicate and tests.
- `OrderItem::closeShort()` semantics stay: reservation only. The copy is
  being brought in line with the code, not the other way round.
- Routes, route names, policies and the authorisation matrix are unchanged
  (`AuthorizationBoundaryTest` must pass untouched). Hidden Mossorders
  screens stay reachable by URL.
- Existing tests are edited only where a step says so, and only to pin the
  new copy; never deleted.
- Do not commit, push or deploy.

## Out of scope

- Finding 1 (stock hints on the order form) — Jon: expected behaviour.
- Finding 4 (Email invoice from the order page / Invoices list row actions)
  — deferred, noted in the review doc.
- Part 2: D20–D26 (customer form required fields and defaults, order-form
  delivery date and address hints, estimate hint, list filters and search),
  E29 (finance nav duplication), F34 (run-sheet address line), C12's
  fulfil/pick wording sweep, the picking queue's day selection.
- Cycle 12 delete guards (parked) — don't touch product/variant/customer
  Delete links.
- Removing the `profile.destroy` route or `ProfileTest`'s delete cases.
- Any change to `OnlineOrdersController`, the sync commands or the API.

## Steps

### 1. Mossorders surface behind one flag (A5)
Files: `config/services.php`, `.env.example`,
`resources/views/layouts/navigation.blade.php`,
`resources/views/dashboard.blade.php`,
`resources/views/customers/{index,show,create,edit}.blade.php`,
`tests/Feature/OnlineOrdersVisibilityTest.php` (new)
What:
- `config/services.php` → `mossorders` gains
  `'ui_enabled' => filter_var(env('MOSSORDERS_UI_ENABLED', false), FILTER_VALIDATE_BOOL)`.
  `.env.example`: `MOSSORDERS_UI_ENABLED=false` beside the other
  `MOSSORDERS_*` lines with a one-line comment ("shows the Online Orders
  screens, dashboard sync banner and customer link fields; off until the
  integration is live").
- `navigation.blade.php` line 8:
  `$canSeeOnlineOrders = $me->hasRole('admin', 'office') && config('services.mossorders.ui_enabled');`
- `dashboard.blade.php`: `$showSyncAlert` additionally requires the flag;
  wrap the "Online orders" button (lines 71–77) in the same `@if`. The
  greeting clause follows `$showSyncAlert` automatically.
- Customers: wrap the index "Online account" filter `<div>`, the index
  "Online · id" tag, the show-page header tag and the "Online" `<dt>/<dd>`
  pair, and the create/edit `<details>` block in
  `@if (config('services.mossorders.ui_enabled'))`. Nothing else in those
  views changes; `mossorders_user_id` stays nullable and fillable.
- New test: with the default (false) an office GET of `dashboard` does not
  contain `route('online-orders.index')` nor "Online order sync"; the
  customers index does not contain "Online account"; with
  `config(['services.mossorders.ui_enabled' => true])` all three appear
  (seed no cache, so the alert strip's "has not completed successfully
  yet" branch renders); `GET /online-orders` is still 200 for office with
  the flag off.
Check: `php artisan test --filter=OnlineOrdersVisibilityTest` passes;
`php artisan test --filter=AuthorizationBoundaryTest` passes unchanged.

### 2. Dashboard says what to do (A2, A3, B9, C11, C16)
Files: `routes/web.php`, `resources/views/dashboard.blade.php`,
`app/Models/Order.php`, `tests/Feature/DashboardTest.php`
What:
- `Order`: add `public const OPEN_STATUSES = ['pending', 'confirmed', 'preparing', 'ready'];`
  and
  ```php
  /** Delivery date has passed and the order hasn't left. */
  public function isDeliveryOverdue(): bool
  {
      return $this->delivery_date !== null
          && $this->delivery_date->lt(today())
          && in_array($this->status, self::OPEN_STATUSES, true);
  }
  ```
- Route closure: beside `unallocatedCount`, add
  `toConfirmCount` (clone of `$unallocatedQuery` where status `pending`),
  `toAllocateCount` (status in `confirmed, preparing`) and
  `overdueDeliveryCount` (`Order::whereIn('status', Order::OPEN_STATUSES)->whereDate('delivery_date', '<', today())->count()`).
  Keep the existing `take(5)` list and its `order_date` sort.
- View `@php` block: replace the "need allocation" sentence with bits:
  `"{$n} ".Str::plural('order', $n).' to confirm'`, `… to allocate`,
  `"{$n} past ".($n === 1 ? 'its' : 'their').' delivery date'` (each only
  when > 0). `$summary = $bits ? 'To do: '.implode(' · ', $bits).'.' : 'Everything is on track.'`.
- KPI row: "Unallocated / need action" becomes label **Orders to action**,
  value `toConfirm + toAllocate`, sub the same bits without the word
  "order" (`1 to confirm · 1 to allocate`) or `clear`. "Ready to ship /
  packed" becomes label **Ready**, sub **to dispatch**. Remove the
  "Low stock" KPI tile; grid class `lg:grid-cols-6` → `lg:grid-cols-5`.
- Queue table: subtitle "Needs allocation · sorted by age" → "To confirm or
  allocate · oldest first". Replace the AGE column with **DELIVERY**:
  `delivery_date?->format('d/m') ?? '—'` followed by
  `<span class="mf-tag mf-tag-danger">overdue</span>` when
  `$order->isDeliveryOverdue()`. Per row: `pending` → tag "Pending"
  (`mf-tag-warn`) and button **Confirm →**; otherwise the existing
  Partial/Unallocated tag and **Allocate →**. Both buttons link to
  `orders.show`. ORDER cell and the button get `whitespace-nowrap`; drop
  the item-detail line under the customer name and put that string on the
  cell as `title` (the ITEMS column already carries the count).
- Remove the "Low stock" and "Recent activity" side panels (lines 343–369)
  and their comments. The side rail keeps "Maturing soon".
- Tests (extend `DashboardTest`): (a) one pending + one confirmed
  under-allocated order → page shows "Confirm →" once and "Allocate →"
  once, "1 order to confirm · 1 order to allocate"; (b) a single confirmed
  order → "1 order to allocate" (singular); (c) a confirmed order with
  `delivery_date = yesterday` → "overdue" tag and "1 past its delivery
  date"; a dispatched order with a past date shows neither; (d) the page
  no longer contains "Low-stock tracking" or "Activity log".
Check: `php artisan test --filter=DashboardTest` passes.

### 3. Overdue delivery visible on the order surfaces (A3)
Files: `resources/views/orders/index.blade.php`,
`resources/views/orders/show.blade.php`,
`resources/views/order-allocations/index.blade.php`,
`tests/Feature/DeliveryOverdueTest.php` (new)
What: wherever the delivery date prints — orders index Delivery cell, the
order page subtitle ("· deliver 14 Jul"), and a new **Delivery** column
after Order date on the allocation worklist — append
`<span class="mf-tag mf-tag-danger" data-overdue>overdue</span>` when
`$order->isDeliveryOverdue()`. Dashboard is done in step 2. Picking is
untouched (its day buckets already keep past days selectable).
Test: unit-ish cases for `isDeliveryOverdue()` (null date; today; yesterday
per open status true; yesterday + dispatched/delivered/cancelled false),
plus one HTTP case per surface asserting `data-overdue` present for a
confirmed order dated yesterday and absent for one dated tomorrow.
Check: `php artisan test --filter=DeliveryOverdueTest` passes.

### 4. Order header fits (B7)
Files: `resources/views/orders/show.blade.php`, `resources/css/app.css`
What: lines 105–198 become two rows inside one `mb-7` wrapper:
```blade
<div class="mb-7">
    <div class="flex items-start justify-between gap-6">
        <div class="min-w-0">
            <h1 class="text-[24px] font-semibold font-mono whitespace-nowrap" …>{{ $order->order_number }}</h1>
            <div class="mt-1.5 text-[13px]" …>customer · ordered … · deliver … [overdue tag from step 3]
                @if($order->invoice_id) · on invoice {{ $order->invoice?->number }} — void it to edit @endif
            </div>
            cancelled tag as now
        </div>
        <a … class="mf-btn-ghost lg:hidden">← All</a>
    </div>
    <div class="flex flex-wrap items-center gap-2 mt-3" data-order-actions>
        … Docket, Loaded onto van, Edit (not when invoiced — the sentence moved up), Confirm / Mark dispatched / Mark delivered / Delivered exactly as now …
    </div>
</div>
```
Replace the muted "Tick loaded onto van first" `<span>` with
`<button type="button" class="mf-btn-primary" disabled aria-disabled="true" title="Tick loaded onto van first" data-dispatch-needs-loaded>Mark dispatched</button>`
(keep the truck icon). Add to `app.css` next to `.mf-btn-primary`:
`.mf-btn-primary[disabled] { opacity: .45; cursor: not-allowed; }` if no
disabled rule exists (check with `grep -n "mf-btn-primary" resources/css/app.css`).
`OrderTransitionsTest` 427–447 must still pass: the title keeps the string
while unloaded; the real form replaces it once loaded.
Check: `php artisan test --filter=OrderTransitionsTest` passes; in Chrome
at a 1280 px window on `/orders/4` (ORD-20260723-001, `ready`) tick
Loaded, then
`document.querySelector('h1').getBoundingClientRect().height < 40` (one line)
and the disabled button shows the tooltip.

### 5. Product page fits; Recent batches tells the truth (B6, C15-part)
Files: `resources/views/products/show.blade.php`,
`resources/views/products/edit.blade.php`,
`resources/views/products/variants/create.blade.php`,
`resources/views/products/variants/edit.blade.php`,
`resources/views/orders/show.blade.php`, `tests/Feature/UxCopyTest.php` (new)
What: `lg:grid-cols-[320px_1fr]` → `lg:grid-cols-[320px_minmax(0,1fr)]`
in all five. Recent batches table: column "Total qty" → **Produced**,
value `{{ $batch->batchItems->sum('quantity_produced') }}` (units, no
"kg"); Ready date cell: `@if($batch->isExpired())` print
`<span style="color: var(--danger);">Expired</span>` before the existing
branches; Status cell: when `isExpired()` and status is `active`, tag
"Expired" (`mf-tag-danger`) instead of "Active".
Test (`UxCopyTest`): product page for a product whose batch expired
yesterday contains "Expired" and not " kg</td>"; a fresh batch shows its
produced units.
Check: in Chrome at 1280 px on `/products/1`:
`document.documentElement.scrollWidth === window.innerWidth` and no
horizontal scrollbar; `php artisan test --filter=UxCopyTest`.

### 6. Weigh-in gets its own row (B8)
Files: `resources/views/orders/partials/allocation-items.blade.php`
What: for the per-unit variable-weight branch only (`isVariableWeight() && ! isBulkWeighed()`, lines 129–176):
- Keep in the ACTION cell: `<form method="POST" action="…fulfill…" id="fulfil-{{ $allocation->id }}">` with `@csrf`, the Pick select, the Fulfil button and the hidden `actual_weight_kg` input. Remove/Undo forms stay as now.
- Render a second row after the allocation's `</tr>`:
  ```blade
  <tr class="weight-entry" style="border-top: 1px dashed var(--line-2);">
      <td colspan="{{ 4 + 1 + 1 }}" class="mf-td">   {{-- Batch, Production, Allocated, Fulfilled, Weight, Action --}}
          <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5" id="weights_{{ $allocation->id }}">
              @for … <div class="weight-row inline-flex items-center gap-1" data-index="{{ $i }}">
                  <span …>#{{ $i }}</span>
                  <input … form="fulfil-{{ $allocation->id }}" …same attributes as now…>
                  <span …>kg</span>
              </div> @endfor
              <span class="text-[11.5px] font-mono ml-auto" style="color: var(--ink-2);">Total: <span id="total_{{ $allocation->id }}" class="font-medium">0.000</span> kg</span>
          </div>
      </td>
  </tr>
  ```
  The colspan is the number of `<th>`s actually rendered (Weight and
  Action are conditional — compute it in `@php` from the same conditions).
  The inputs associate with the form via `form=`; `required` still
  applies; `updateWeightInputs()`'s `row.style.display = 'flex'` is fine
  inside a flex-wrap container. Nothing changes in the JS or the bulk and
  fixed-quantity branches.
Check: `php artisan test --filter=OrderFulfillmentWeightTest` passes
(server-side weights unchanged); in Chrome on a confirmed order with an
allocated cheese-wheel line, the weight boxes sit in one wrapping row
under the allocation, the table no longer scrolls sideways at 1280 px,
typing in #1 and #2 updates Total, and Fulfil records the pick (then Undo
it so the dev DB is as before).

### 7. The auto-allocate banner knows when it's done (C19)
Files: `resources/views/orders/show.blade.php`,
`tests/Feature/OrderShowAllocationInlineTest.php`
What: in the `@php` head of the show view compute
```php
$canAutoAllocate = $order->orderItems->contains(fn ($i) => ! $i->isSettled()
    && $i->quantity_allocated < $i->quantity_ordered
    && ($availableBatchItems[$i->id] ?? collect())->isNotEmpty());
$noStockLabels = $order->orderItems
    ->filter(fn ($i) => ! $i->isSettled() && $i->quantity_allocated < $i->quantity_ordered && ($availableBatchItems[$i->id] ?? collect())->isEmpty())
    ->map(fn ($i) => $i->productVariant->picker_label)->values();
```
The green banner (350–370) renders only when `$canAutoAllocate`. When the
order is not fully allocated and `! $canAutoAllocate`, render instead a
muted `mf-flash` (no button): **Nothing left to allocate.** `{labels}`
`has/have` no free stock — close `it/them` short, or wait for a new batch.
Test: order with one line whose variant has no allocatable stock → page
lacks the auto-allocate route and shows "Nothing left to allocate"; add a
second line with stock → the button is back.
Check: `php artisan test --filter=OrderShowAllocationInlineTest`.

### 8. Short-close copy and the stepper count (C17, SMALL_FIXES ×2)
Files: `resources/views/orders/partials/allocation-items.blade.php`,
`resources/views/picking/item.blade.php`,
`app/Http/Controllers/OrderController.php`,
`resources/views/orders/partials/status-stepper.blade.php`,
`tests/Feature/OrderShortSupplyTest.php`, `docs/planImp/SMALL_FIXES.md`
What:
- All three dialog bodies → `The {n} unpicked {unit(s)} won't be supplied or billed; any stock reserved for them goes back to free stock.`
  (`allocation-items` uses `$orderItem->quantity_remaining`; `picking/item`
  uses `$needed`). Titles unchanged.
- `shortCloseItem()`: before `closeShort()` runs, capture
  `$released = $orderItem->quantity_allocated - $orderItem->quantity_fulfilled`;
  flash `"Line closed short — {$shortQty} units won't be supplied or billed."`
  plus `" {$released} reserved units went back to free stock."` only when
  `$released > 0`.
- Stepper denominator: `$order->orderItems->sum(fn ($i) => $i->quantity_ordered - ($i->isShortClosed() ? $i->quantity_short : 0))`.
- Tick both items in `SMALL_FIXES.md` (`[x]`, one line each: "cycle 13").
- Tests (`OrderShortSupplyTest`): closing a line with no reservation
  flashes the first sentence only; closing one with 4 reserved adds
  "4 reserved units went back to free stock"; a `preparing` order with a
  short-closed 10-unit line and a 2-unit open line renders "0 of 2 picked".
Check: `php artisan test --filter="OrderShortSupplyTest|ConfirmDialogTest|PickingTest"`.

### 9. One word per status (C12)
Files: `app/Models/Order.php`, `resources/views/orders/index.blade.php`,
`resources/views/orders/show.blade.php`,
`resources/views/customers/show.blade.php`,
`resources/views/order-allocations/index.blade.php`,
`resources/views/picking/item.blade.php`, `tests/Feature/UxCopyTest.php`
What: `Order::statusLabel(): string` → `match ($this->status) { 'preparing' => 'Picking', default => ucfirst($this->status) }`.
Replace every `ucfirst($order->status)` / `ucfirst($sibling->status)` in
those views with `->statusLabel()`. Filter option "Preparing" →
"Picking" (value unchanged). Prose: `orders/show` 485 and `picking/item`
82 "Preparing" → "Picking". Leave "fulfil/fulfilled" alone.
Test: orders index with a `preparing` order shows "Picking" and not
"Preparing".
Check: `grep -rn "Preparing" resources/views` finds only `<option value="preparing">`'s *value* (none in label text).

### 10. Credit limit and the duplicate figure (C13, C14)
Files: `resources/views/customers/{show,index,create,edit}.blade.php`,
`tests/Feature/UxCopyTest.php`
What: print `No limit` wherever `credit_limit` is 0 — Business terms row,
Account tile, index column (keep `€…` formatting otherwise). Remove the
"Outstanding" `<dt>/<dd>` from Business terms (the Account panel's "Net
owed" is the same number). Under both forms' credit-limit input add
`<p class="mf-hint">0 = no limit — the app warns, never blocks, when an order would exceed it.</p>`
(use whatever hint class the form already uses for "Where invoices and
statements go…"). Any test asserting `€0.00` for a credit limit (grep
`tests/Feature` for `Credit limit`) is updated to `No limit`.
Check: `php artisan test --filter="UxCopyTest|CreditLimitWarningTest|CustomerSpecialPriceTest"`.

### 11. Batch cards stop contradicting themselves (C15, C16)
Files: `resources/views/batches/partials/batch-card.blade.php`,
`resources/views/batches/show.blade.php`, `tests/Feature/UxCopyTest.php`
What: in the card header, when `$isExpired` skip the status tag if
`$batch->status === 'active'` (the Expired tag replaces it; `sold_out` /
`closed` still print). "Ready:" field: `@if($isExpired)` →
`<span style="color: var(--danger);">Expired</span>` before the existing
branches. Delete-blocked: replace the sentence with
`<span class="mf-link" style="color: var(--faint); cursor: not-allowed;" title="Can't delete — {{ $dependents }} … on it. {{ $deletionBlocker }}" aria-disabled="true" data-delete-blocked>Delete</span>`
— `BatchCreateTest` line 318 keeps passing because the sentence is in the
title and `data-delete-blocked` appears once. `batches/show.blade.php`:
delete the `@else … More actions coming soon. … @endif` arm's paragraph
(keep the `@if`).
Test: `/batches` with an active batch expired yesterday shows one
"Expired" tag and no "Active" tag for it; the batch page lacks "coming soon".
Check: `php artisan test --filter="BatchCreateTest|UxCopyTest"`.

### 12. Dead controls (E27, E28)
Files: `resources/views/layouts/partials/topbar.blade.php`,
`resources/views/profile/edit.blade.php`,
`resources/views/profile/partials/delete-user-form.blade.php` (delete),
`tests/Feature/UxCopyTest.php`
What: remove the Notifications `<button>` (42–47); the divider and date
chip stay. Remove the delete-account panel include and delete the
partial; subtitle → "Your account details and password." The
`profile.destroy` route, `ProfileController::destroy()` and `ProfileTest`
stay — admins remove users at `/users`.
Test: `/profile` lacks "Delete account"; `/dashboard` lacks
`aria-label="Notifications"`.
Check: `php artisan test --filter="ProfileTest|UxCopyTest|LayoutFeedbackTest"`.

### 13. Run sheet: honest strip, real zeros, calm capacity, no wraps (F31–F33, B10)
Files: `resources/views/chilled-runs/partials/_summary-strip.blade.php`,
`resources/views/chilled-runs/partials/_run-table.blade.php`,
`resources/views/chilled-runs/index.blade.php`,
`resources/views/invoicing/uninvoiced/index.blade.php`,
`tests/Feature/ChilledRunTest.php`
What:
- Strip: `@php $noOrderStops = $sheet['rows']->count() - $sheet['loadableCount']; @endphp`.
  In each of the three 100 % branches, when `$noOrderStops > 0` print
  `All ordered stops covered — {n} without an order` / `… loaded — …` /
  `All ordered stops dispatched — {n} without an order`; otherwise the
  existing sentences (so `DispatchRunTest` 280 holds on a one-stop run).
- Stock footer line 397: show `0` (not `—`) when the column has orders:
  `{{ $s['free'] > 0 || ($sheet['totals'][$variant->id]['units'] ?? 0) > 0 ? $s['free'] : '—' }}`.
  The red `short` class already lights when outstanding > free.
- Capacity: delete the `mf-flash-warn` block (index 78–82); in
  `_run-table` header row (50–54) add, after the driver eyebrow,
  `@if ($activeRun->capacity_note)<span class="mf-tag mf-tag-neutral">Capacity: {{ $activeRun->capacity_note }}</span>@endif`.
- `whitespace-nowrap` on the "+ Ref" button (`_run-table` 151) and on
  "Issue all N" (`uninvoiced/index` 18 — `InvoiceIssueTest` 97 asserts the
  text, unchanged).
- Tests (`ChilledRunTest`): run with two stops and one dispatched order →
  "All ordered stops dispatched — 1 without an order"; a run whose only
  milk order is for a variant with zero free stock renders `0` in that
  stock-footer cell (and the `short` class), while an unordered variant
  with zero stock still renders `—`; "Capacity:" renders inside an
  `mf-tag` and not inside `mf-flash-warn`.
Check: `php artisan test --filter="ChilledRunTest|DispatchRunTest|InvoiceIssueTest"`.

### 14. Docs
Files: `CLAUDE.md`, `DEPLOYMENT.md`, `docs/planImp/UX_REVIEW_2026-10-13.md`
What: `CLAUDE.md` → *Online Orders Integration* → one line:
"The Online Orders nav item, dashboard sync banner/button and the
customer-page Mossorders fields render only when `MOSSORDERS_UI_ENABLED=true`
(`services.mossorders.ui_enabled`); routes stay live." `DEPLOYMENT.md`:
add the variable to the env table (default `false`). In the review doc's
**Decisions** block, mark every item this cycle closes as "cycle 13".
Check: `grep -n MOSSORDERS_UI_ENABLED CLAUDE.md DEPLOYMENT.md .env.example` → three hits.

## Verification

Run in order, record real output:

1. `php artisan test` → 0 failing (699 + the new tests). Baseline before
   this cycle is in Context; any new failure is yours.
2. `./vendor/bin/pint --test app tests database routes config` → clean.
3. `grep -rn "released back" app resources/views` → no hits.
4. `grep -rn "coming soon\|Activity log\|Low-stock" resources/views` → no hits.
5. Chrome, window 1280 px wide, as `office_test` (password = `ADMIN_SEED_PASSWORD`):
   - `/dashboard`: no amber sync strip, no "Online orders" button, the
     pending order (create one from the run sheet if none) shows
     "Confirm →"; sidebar has no "Online Orders".
   - `/products/1`: `document.documentElement.scrollWidth === window.innerWidth`.
   - `/orders/4` (ready): tick Loaded; order number stays on one line;
     disabled "Mark dispatched" shows the tooltip; **untick Loaded afterwards**.
   - A confirmed order with an allocated wheel line: weight boxes in a row
     under the allocation; Total updates; table doesn't scroll sideways.
   - `/chilled-runs`: strip reads "… — 14 without an order" style copy;
     milk stock cells show `0` where ordered; Capacity is a tag.
   - Cancel any probe orders you created.

## Risks

- **`minmax(0,1fr)` on `orders/show`** could let the content column
  shrink below the 920 px it expects at `lg`; the content is `max-w`, not
  `min-w`, so it should only ever get narrower. Check the order page at
  1280 px after step 5.
- **`form=` association** (step 6): inputs outside the `<form>` element are
  still submitted and validated, but only if the `id` is unique — one form
  per allocation, so `fulfil-{allocation id}` is. `submit-once.js` already
  handles `form="…"` buttons; the inputs don't need it.
- **`BatchCreateTest` 318** and **`OrderTransitionsTest` 435/445** pin
  strings that steps 11 and 4 move into `title` attributes. Both still
  pass because the assertion is on raw HTML; if either fails, the string
  was changed, not just moved.
- **`DispatchRunTest` 280** ("All stops dispatched") holds only because
  the fixture run has one stop; don't change the sentence for the
  zero-`$noOrderStops` case.
- **`ConfirmDialogTest`**: the new short-close body starts with "The N
  unpicked…", not with its title; keep it that way.
- The dashboard's `OPEN_STATUSES` constant is new; `canBeCancelled()` has
  its own list on purpose (it means "not yet shipped") — don't merge them.

## Steps added in revision 2

The Implementer's work on steps 1–14 is accepted as is (see Review). These
five steps come from its Notes and from the Planner's own 1280 px check.
Only these are to be done now; don't revisit 1–14.

### 15. The dashboard queue must not scroll sideways (Note 1)
Files: `resources/views/dashboard.blade.php`, `tests/Feature/DashboardTest.php`
What: measured in Chrome at 1280 px: the queue table is 738 px inside a
622 px panel, so the Confirm → / Allocate → button is behind a scrollbar —
the one control the panel exists for. Two changes:
- The main grid `lg:grid-cols-[minmax(0,1fr)_360px]` becomes
  `2xl:grid-cols-[minmax(0,1fr)_360px]` (one column up to 1535 px, so at
  1280 the Maturing rail sits under the queue and the queue gets the full
  width).
- Drop the ITEMS column (its count is low value; the item detail is already
  on the customer cell's `title`). Keep ORDER, CUSTOMER, VALUE
  (`@can('see-financials')`), DELIVERY, STATUS, action.
Test: extend `test_the_queue_offers_confirm_for_pending_and_allocate_for_confirmed`
to assert the page no longer contains `>Items</th>`.
Check: in Chrome at 1280 px on `/dashboard` with at least one queued
order, `const w = document.querySelector('.mf-panel .overflow-x-auto'); w.scrollWidth <= w.clientWidth`
is true and the button is visible without scrolling.

### 16. The allocation table's 8 px overflow (Note 2)
Files: `resources/css/app.css`, `resources/views/orders/partials/allocation-items.blade.php`
What: add after `.mf-td` in `app.css`:
`.mf-table-tight .mf-th, .mf-table-tight .mf-td { padding-left: 10px; padding-right: 10px; }`
and put `mf-table-tight` on the allocations `<table>` (the one under
"Current allocations"). Nothing else changes.
Check: in Chrome at 1280 px on an order with an allocation, every
`.overflow-x-auto` under the allocations panel has `scrollWidth <= clientWidth`.

### 17. `statusLabel()` on the four remaining views (Note 3)
Files: `resources/views/orders/edit.blade.php`,
`resources/views/orders/docket.blade.php`,
`resources/views/online-orders/index.blade.php`,
`resources/views/invoicing/uninvoiced/index.blade.php`
What: replace each remaining `ucfirst(<something>->status)` for an order
with `->statusLabel()`. Find them with
`grep -rn "ucfirst(\$[a-zA-Z]*->status)" resources/views` (the step-9 grep
only looked for `$order`).
Check: that grep returns nothing.

### 18. Queue footer wording (Note 4)
Files: `resources/views/dashboard.blade.php`
What: "Showing N of M unallocated" → "Showing N of M to action".
Check: `DashboardTest` passes; `grep -n "unallocated" resources/views/dashboard.blade.php` returns no visible-copy hit.

### 19. The lone divider (Note 8)
Files: `resources/views/layouts/partials/topbar.blade.php`
What: remove the `<div class="hidden sm:block" style="width: 1px; …">`
divider that used to separate the bell from the date chip (and its F-05
comment). The date chip stays.
Check: `LayoutFeedbackTest` and `UxCopyTest` pass.

### Verification for revision 2
1. `php artisan test` → 0 failing.
2. `./vendor/bin/pint --test app tests database routes config` → clean.
3. The two Chrome checks in steps 15 and 16 at a 1280 px window.

## Review

Planner review of revision 1, 2026-10-13. Read `implemented.md` to the
end, read the full `git diff` (50 files), reran `php artisan test` (727
tests, 0 failed, 4 590 assertions), `pint --test` (300 files clean) and the
four grep checks (all clean), then looked at `/dashboard`, `/orders/3`,
`/products/1` and `/chilled-runs` in Chrome at a 1280 px window.

### Criteria

| Step | Result |
| --- | --- |
| 1 Mossorders flag | **Pass.** Flag, nav, dashboard, four customer views, `.env.example`; routes untouched; 4 tests. |
| 2 Dashboard | **Pass** on copy, counts, overdue tag, placeholders, KPI tiles — but the queue table scrolls sideways at 1280 px (measured 738/622); → step 15. |
| 3 Overdue tags | **Pass.** Predicate + three surfaces + 6 tests; seen on `/orders/3`. |
| 4 Order header | **Pass.** `h1` 36 px (one line) on `/orders/3`; disabled button with the tooltip; `OrderTransitionsTest` green. |
| 5 Product page | **Pass.** `scrollWidth 1280 === clientWidth 1280`; Produced column in units; Expired in both cells. |
| 6 Weigh-in row | **Pass** on the diff and the Implementer's CDP measurements (form= association, Total, a real pick + undo). The 8 px table overflow it found is pre-existing → step 16. |
| 7 Banner | **Pass.** `$needsStock` closure; muted panel names the lines; test covers both directions. |
| 8 Short-close copy | **Pass.** Three dialogs, flash with the conditional sentence, stepper nets `quantity_short`; `SMALL_FIXES.md` ticked; 3 tests. |
| 9 Status label | **Pass** for the listed views; four more found → step 17. |
| 10 Credit limit | **Pass.** "No limit" in three places, Outstanding row gone, hint on both forms. |
| 11 Batch cards | **Pass.** `BatchCreateTest` holds (sentence in `title`, one `data-delete-blocked`). |
| 12 Dead controls | **Pass.** Bell and panel gone, partial deleted, route and `ProfileTest` kept. |
| 13 Run sheet | **Pass.** Strip copy seen live ("— 14 without an order"), `0` in the ordered milk column, Capacity tag; `DispatchRunTest` holds. |
| 14 Docs | **Pass.** Three hits for `MOSSORDERS_UI_ENABLED`. |

### Deviations
1. Invoice sentence kept verbatim on its own muted line under the subtitle — **accepted**; `OrderTransitionsTest` 407 was a pin I missed, and the result still clears the action bar.
2. `statusLabel()` added in the step-2 model edit — **accepted**.
3. Extra test that a hidden Mossorders field doesn't blank the link — **accepted**, good catch.
4. Headless Chrome over CDP for the browser checks — **accepted**; the measurements are the evidence that matters, and the Planner repeated the four page checks interactively.

### Notes for Planner
1. Dashboard queue scrolls sideways — **fixed now**, step 15.
2. Allocation table 8 px overflow — **fixed now**, step 16.
3. Remaining `ucfirst(...->status)` views — **fixed now**, step 17.
4. "Showing N of M unallocated" — **fixed now**, step 18.
5. `scrollWidth === innerWidth` can't hold with a classic scrollbar — **accepted**; future plans compare against `documentElement.clientWidth`.
6. "Online" provenance tag on Mossorders-sourced orders on the customer page — **deferred**: it marks where an order came from, not the integration, and no such order exists while the flag is off.
7. `form.requestSubmit()` skips the confirm dialog — **rejected as a finding**: `confirm.js` deliberately hooks the click; users click. Worth a line in `TESTING_AND_FIXES.md` so the next scripted check expects it.
8. Lone divider in the topbar — **fixed now**, step 19.

### Review of revision 2 (Planner, 2026-10-13)

Read the revision-2 report and the diff for the nine files it names; reran
`php artisan test` (727 tests, 0 failed, 4 592 assertions) and `pint --test`
(clean); ran the `-E` grep (nothing left); checked `/dashboard` and
`/orders/4` in Chrome.

- **15 Pass.** Items column gone, rail under the queue below `2xl`,
  footer "to action". The Implementer measured 998/998 at 1280 px with the
  button inside the panel. (The Planner's own Chrome window would not go
  wider than 894 px during this check; at that width the table is 678 px in
  a 612 px panel, which is below the plan's 1280 px target and consistent
  with the Implementer's 1280 px numbers.)
- **16 Pass.** `.mf-table-tight` applied; with the wheel line's panel open
  on `/orders/4` the allocations wrapper measures 563/563. The Implementer
  checked `ready` orders (Undo form) rather than a confirmed order with the
  Fulfil form — accepted: 10 px instead of 16 px on six columns frees 72 px
  against the 8 px that was missing.
- **17 Pass.** Four views; `grep -rnE 'ucfirst\(\$[a-zA-Z]*->status\)' resources/views` is empty.
- **18 Pass.** **19 Pass** (no `width: 1px` divider in the header).

Deviations: (1) the `-E` grep — accepted, the plan's basic-regex spelling
could not match; (2) step 16 checked on ready orders — accepted, see above.
Notes: (1) future plans spell the grep with `-E` — recorded in
`TESTING_AND_FIXES.md`; (2) `.mf-table-tight` kept to the one table —
agreed.

Status: **ACCEPTED**. Written up in `TESTING_AND_FIXES.md` → 2026-10-13;
`RESUME.md`, `WORK_IN_PROGRESS.md` and the review doc updated. Jon archives:
`mkdir -p docs/planImp/archive/2026-10-13-ux-review-part-1 && git mv docs/planImp/plan.md docs/planImp/implemented.md docs/planImp/archive/2026-10-13-ux-review-part-1/`
(both files are untracked, so plain `mv` works too).
