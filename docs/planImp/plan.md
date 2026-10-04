# Friction: the S3 findings from the end-to-end run

Status: ACCEPTED
Revision: 1
Planner: Fable 5.1
Date: 2026-10-04

## Goal

The end-to-end run (`archive/2026-10-04-e2e-browser-run/implemented.md`) found
the arithmetic sound and the surfaces tiring. This cycle takes the S3 findings
— places where the screen misleads or costs the operator real effort — plus
the four follow-ons from cycle 3. After it: expired stock is visible instead of
vanishing; the picker's "Today" is today; a partial mature release is one
action; the order page stops offering two unlabelled number boxes per line;
every save lands back where the operator was working; reordering a run is one
form; the dispatch flash names orders and reasons; Finance defaults to the
period the office actually wants; error pages lead home. Pure copy and
cosmetic items (S4) wait for a short cycle 5.

## Context

HEAD `7c86326` + the staged archive of cycle 3. Suite **557 passed, 0
failed**; any failure is real. `pint --test app tests database routes` clean.
Nothing pushed or deployed. Dev DB holds the E2E data (`RESUME.md`): expired
milk batches 16/19/21/22 and yoghurt 18, July `ready` orders that were never
dispatched, order 14 `preparing` — all useful for the browser checks here.

Each step cites its finding(s); read them in the archived `implemented.md`
first. Laravel 12: `redirect()->route(...)->withFragment('id')` exists and is
how "land back on the thing you edited" is done below.

**Facts the steps rely on**

- Dashboard (`routes/web.php:43–50`): `readyNowCount` = `status='active'`
  with `ready_date` null-or-past — no expiry, no stock check. `/stock`
  (`StockOverviewService::build()`) returns per-type cards with
  `active_batches`/`variants`; the card view prints "No active milk batches."
  (`stock/overview.blade.php:39,74,106`). `/batches` already tags expired
  batches (`batch-card.blade.php:45`). Strict expiry everywhere =
  `expiry_date > today` (`BatchItem::allocatable()`).
- Batch create: the expiry hint is `#expiry-note` (`batches/create.blade.php:53`),
  products carry `data-shelf-life-days`; the batch page only renders an Expiry
  row when set (`batches/show.blade.php:107`).
- Picking queue (`PickingController::index()`): all `confirmed|preparing|ready`
  orders, no date filter; the view groups by delivery date and the strip is
  `pickedUnits/totalUnits` over everything. `PickingController::item()` sets
  `$skipTo` = **first** unsettled line other than the current, so Skip from
  line 2 goes back to line 1 (F-31). Stepper `+` is `qty = Math.min(maxQty, qty+1)`
  with no disabled state (`picking/item.blade.php:263`). `.mob-shell` has
  `padding-bottom: 110px` for the fixed `.mob-footer`; the "Can't finish"
  button sits in a `.mob-section` above the footer at `:348`. The OK/Check
  tags compare to `{{ 2 * $expWeight }}` where `$expWeight = (float)
  ($variant->weight_kg ?? 1)` (`:12`) — a stored `0` gets through.
- Mature conversion (`cheese-conversion/index.blade.php`): the header button
  submits a hidden sibling `release-form-{id}` whose `quantity` is fixed to
  the **saved** hold (`:259–261`); the hold form's `quantity` is Alpine
  `mature.length` (`:56`). `CheeseConversionController::release()` already
  validates `quantity` as `integer|min:1|max:{held}` — a partial release is
  legal server-side.
- Order page (`orders/partials/allocation-items.blade.php`): per allocation a
  **Fulfil** form with a `quantity` number input (max = remaining) and no
  `data-confirm` (`~:120–160`), and per line a **Qty / Update** form
  (`:286–290`) — two unlabelled boxes showing the same number (F-21). Add
  item's quantity is `value="{{ old('quantity', 1) }}"` (`orders/show.blade.php:427`).
  `OrderController::store()` creates one line per posted item (`:249+`);
  `storeItem()` merges by variant. Sibling list age uses `order_date`
  (`orders/show.blade.php:68`) and the summary is
  `quantity_ordered.'× '.$i->productVariant->product->name` (`:58`). The
  create form's option label is `picker_label (price_label)` — standard
  price even when the line uses the customer's special (cycle 3 note).
  `update()`'s success flash is "Order updated successfully." even for
  Confirm (`:394`). 32 `redirect()->route('orders.show', $order)` calls in
  `OrderController` + `OrderAllocationController` land at the top of the page.
- Customers: `CustomerController::store()` redirects to `customers.index`
  (`:92`); `destroy()` to `customers.index` without the search (`:216,222`);
  `CustomerSpecialPriceController` → `customers.show` with no fragment; the
  Special prices panel is at `customers/show.blade.php:338` (no `id`).
- Delivery runs (`DeliveryRunController`): every action redirects to
  `delivery-runs.index` top; `reorder()` takes the whole `positions[]` array
  (`:115–134`); the view posts one such form per ↑/↓ (`delivery-runs/index.blade.php:85–105`).
  The charge reveal prints `€ charge: on/off` from `apply_delivery_charge`
  only (`:80`); a `delivery_charge_percent` customer shows "off" (F-18).
- Chilled runs: `saveStop()` validates `qty.*` + `customer_reference` only —
  no `notes` (F-28); redirects to `chilled-runs.index` with run/date, no
  fragment; rows are `id="stop-{customer_id}"`. `dispatchFlash()`
  (`ChilledRunController:~225–283`) says "N stops dispatched" (it counts
  orders), names skipped **customers** (`$names()`), and gives one reason
  per bucket. `toggleLoaded()` flash names the customer, not the order.
- Orders show: `Edit` link always rendered (`orders/show.blade.php:130`);
  `update()` refuses invoiced orders only on submit (`blockedByInvoice`).
- Finance: Void button is `@can('void', $invoice)` → absent when
  `Invoice::isVoidable()` is false (payments allocated or an issued credit
  note). Consolidated shortcuts: Last month / This month / Last week
  (`consolidated/create.blade.php:27–29`, `ConsolidatedInvoiceController:~105–114`);
  statement preview defaults to **last** month (`StatementController::133`).
  Customer page "Net owed" prints a negative euro when in credit
  (`customers/show.blade.php:157`). `OrderAllocationController::allocate()`
  has one generic refusal (`:79`). The payments component starts
  `allocations: {}` even after a validation redirect. No
  `resources/views/errors/` — Laravel's bare pages.
- Tests to extend: `StockOverviewTest`, `PickingTest`, `CheeseConversionTest`,
  `OrderAddItemTest`, `OrderItemEditTest`, `OrderStoreOptionalFieldsTest`,
  `CustomerSpecialPriceTest`, `ChilledRunTest` (also holds run CRUD/assign/
  reorder), `DispatchRunTest`, `InvoiceVoidTest`, `StatementTest`,
  `ConsolidatedInvoiceTest`, `PaymentAllocationTest`, `OrderTransitionsTest`.
  New: `DashboardTest`, `ErrorPagesTest`.

## Constraints

- No change to stock/money arithmetic, `DispatchService`, allocation,
  `isAllowedStatusChange()`, or the free-stock expression
  (`TESTING_AND_FIXES.md` → *Invariants worth keeping*, all sections).
- Every existing flash/message text stays unless a step changes it.
- `data-confirm` for anything destructive; forms already submit once.
- `npm run build` after JS/CSS changes; `./vendor/bin/pint` on touched PHP.
- **Do not commit.** Planner commits on acceptance. Nothing pushed/deployed.

## Out of scope

- `OPEN_QUESTIONS.md` #1 and #5 (write-offs; F-06, F-09, F-10, F-24, F-25,
  F-33, F-34's per-order-without-loaded, F-38's two policy items).
- **Cycle 5 (copy & polish, S4):** F-03 Laravel logo, F-04 login focus, F-05
  topbar divider, F-11 batch copy nits + naming the batch in its delete
  dialog + hiding Delete when `deletionBlocker()` would refuse, F-14 "29
  units", F-17 Mossorders id field, F-26's dialog button labels and
  tab-order, F-27 second ref / loaded tick labels on a multi-order stop,
  F-31's `/orders` at phone width, F-34's PDF size, F-38's "Partial" label
  for a credit-only invoice and the weekly-terms warning.
- Drag-and-drop for run stops (step 8 uses position inputs).
- Any redesign of the picking screens beyond the three fixes in step 3.

## Steps

### 1. Expired stock is visible (F-01, F-02, F-07)

Files: `routes/web.php`, `app/Services/StockOverviewService.php`,
`resources/views/stock/overview.blade.php`, `resources/views/dashboard.blade.php`,
`resources/views/batches/create.blade.php`, `resources/views/batches/show.blade.php`,
`tests/Feature/DashboardTest.php` (new), `tests/Feature/StockOverviewTest.php`,
`tests/Feature/BatchCreateTest.php`

What:
- **Dashboard** `readyNowCount`: active, ready-date null-or-past, **not
  expired** (`whereNull('expiry_date')->orWhere('expiry_date', '>', today())`,
  the same strict rule) **and with stock** (`whereHas('batchItems', qty_remaining > 0)`).
  Add `expiredCount` / `expiredUnits`: active batches with `expiry_date <=
  today` and `SUM(quantity_remaining) > 0`. Render a tile **"Expired stock"**
  (value = batches, sub = "N units · write off or close") linking to
  `/batches?status=expired`, only when `expiredCount > 0`, for admin/office/
  factory. Note in a comment that the write-off route is OPEN_QUESTIONS #1.
- **`/stock`**: `StockOverviewService::build()` also returns, per type,
  `expired` = `['batches' => n, 'units' => u]` from the same query (one
  grouped query for all three types, not per card). The card's empty state
  becomes: *"No sellable milk."* + when `expired.batches > 0`: *"N expired
  batches still hold U units — see Batches."* (link with `?status=expired`).
  When the card has stock **and** expired batches exist, show the same line
  as a small muted note under the card header.
- **Batch create**: when the chosen product has no `shelf_life_days`, the
  `#expiry-note` reads *"This product has no shelf life set — the batch will
  have **no expiry** and stays sellable until sold. Enter one if it should
  expire."* (JS on product change; keep the current text when a shelf life
  exists).
- **Batch page**: always render the Expiry row; when null print *"None — stays
  sellable until sold"*.
- Tests: `DashboardTest` (new): with one sellable, one expired-with-stock and
  one expired-empty batch, office sees "ready now" = 1 and the Expired tile
  says 1 batch; factory sees the tile too; the tile is absent when nothing is
  expired. `StockOverviewTest`: an expired milk batch with stock → card shows
  "1 expired batch" and the sellable count excludes it. `BatchCreateTest`: a
  yoghurt batch page shows "None — stays sellable".

Check: `php artisan test --filter="DashboardTest|StockOverviewTest|BatchCreateTest"`
pass. Browser as office: dashboard shows the Expired tile (dev DB has 5),
`/stock` milk card names the expired batches instead of "No active milk
batches".

### 2. The picking queue shows one day (F-30)

Files: `app/Http/Controllers/PickingController.php`,
`resources/views/picking/index.blade.php`, `tests/Feature/PickingTest.php`

What:
- `index()`: collect the queue as now, then derive `$days` = the distinct
  delivery dates present (nulls as a trailing "No date" bucket). `$selected`
  = `?date=` if it is one of them; else **today** if present; else the
  **nearest future** date; else the **latest past** date. The page shows only
  the selected day's orders; the strip (`pickedUnits/totalUnits`) counts only
  them. Header: `◀ Tue 6 Oct ▶` switching between `$days` (links with
  `?date=`), with "Today"/"Tomorrow" labels as the view already derives.
- **Stale bucket**: `ready` orders whose delivery date is **before today**
  are *not* in `$days`; they go in a collapsed `<details>` at the bottom:
  *"Ready, awaiting dispatch — N orders from earlier days"* listing
  `order_number · customer · date`, each linking to the order overview. They
  are the office's to dispatch, not the floor's to pick. (`confirmed`/
  `preparing` orders with past dates **do** stay in `$days` — they still need
  picking.)
- `show()`'s `$nextOrder` prefers the same day, then the queue order as now.
- Tests: with a July `ready` order and two orders for Tuesday, GET `/picking`
  as factory defaults to Tuesday, strip counts Tuesday only, the July order is
  under "awaiting dispatch"; `?date=` switches; a past `preparing` order is a
  selectable day.

Check: `php artisan test --filter=PickingTest` pass. Browser as factory at
500 px: `/picking` opens on the nearest day with work, not July; the stale
bucket lists the July orders.

### 3. Picking ergonomics (F-31 + cycle-3 follow-on)

Files: `app/Http/Controllers/PickingController.php`,
`resources/views/picking/item.blade.php`, `resources/css/app.css`,
`tests/Feature/PickingTest.php`

What:
- `item()`: `$skipTo` = the first unsettled line **after** the current one in
  order-item order, wrapping to the start — so Skip walks the lines in a
  cycle rather than bouncing to line 1.
- Stepper: `+` gets `:disabled="qty >= maxQty"` and `−` gets
  `:disabled="qty <= 1"`, with a disabled style on `.mob-step-btn[disabled]`
  (opacity .4, default cursor).
- The "Can't finish this line — mark short" section must clear the fixed
  footer on a 659 px-tall viewport: raise `.mob-shell`'s `padding-bottom` to
  the footer's real height on the pick screen (measure it; the footer has two
  rows there) or give that section its own bottom margin. Verify by scrolling
  to the bottom at 500 × 659 — nothing hidden.
- OK/Check thresholds: compute `$expWeight` as the server does — `weight_kg`
  when `> 0`, else **no thresholds** (both tags fall back to the old
  behaviour: OK when > 0). Same guard on the desktop row's `data-expected`.
- Test: Skip from line 2 of a three-line order goes to line 3; from line 3 to
  line 1.

Check: `php artisan test --filter=PickingTest` pass. Browser at 500 × 659:
Skip cycles; `+` greys out at the cap; the short button is reachable above
the footer.

### 4. Release part of a hold (F-13)

Files: `resources/views/cheese-conversion/index.blade.php`,
`tests/Feature/CheeseConversionTest.php`

What:
- The release control becomes a small `<input type="number" min="1"
  max="{{ $maturing }}" value="{{ $maturing }}">` (the hidden field's value)
  beside the button, label "Release" + input + "of {{ $maturing }} to mature".
  The `data-confirm` text reads the input's value at click time — give the
  button `x-data`-free JS: `onclick` is gone (cycle 1); instead set
  `data-confirm` dynamically on the input's `input` event
  (`btn.dataset.confirm = \`Release ${n} held wheel(s) into mature cheese?\``),
  or render the message as "Release the entered number of held wheel(s)…".
- While the Maturing zone is **dirty** (Alpine `mature.length !== held`),
  disable the Release button and show *"Save maturing first"* — the
  released count must be the saved hold the server validates against.
- Test: POST release with `quantity=1` on a hold of 2 → one wheel released,
  hold 1 (already legal server-side; the test pins the page renders the input
  with `max="2"`).

Check: `php artisan test --filter=CheeseConversionTest` pass. Browser: hold 2
on G010426 (it has 1 held now — hold one more, Save), set the input to 1,
Release → 1 released, 1 still held; move a wheel without saving → Release
disabled with the hint.

### 5. Order line controls (F-21, F-22, F-23)

Files: `resources/views/orders/partials/allocation-items.blade.php`,
`resources/views/orders/show.blade.php`, `app/Http/Controllers/OrderController.php`,
`tests/Feature/OrderAddItemTest.php`, `tests/Feature/OrderStoreOptionalFieldsTest.php`

What:
- **F-21**: label the two boxes. The allocation row's input gets a visible
  `Pick` label (`<label>` text "Pick" before the box, `aria-label="Units to
  pick now"`), the line's Qty form keeps "Qty:" but its label becomes
  **"Ordered:"**. Add `data-confirm="Record a pick of N × {variant} from
  batch {code}?"` on the Fulfil button (N read live from the input via a
  tiny `oninput` that updates `dataset.confirm`, or word it without N),
  `data-confirm-label="Record pick"`. This makes Enter-in-the-wrong-box
  harmless.
- **F-22**: Add item quantity → `value="{{ old('quantity') }}"
  placeholder="1"`; server default: if blank, treat as 1 (`storeItem()`
  validates `quantity` as `required` — make it `nullable` and default 1).
- **F-23**: `store()` merges posted items by `product_variant_id`, summing
  quantities, before creating lines (same rule as `storeItem()`).
- Tests: Add item page has no `value="1"` on `#add_quantity` and a blank
  quantity creates a line of 1; `store()` with the same variant twice creates
  **one** line with the summed quantity.

Check: `php artisan test --filter="OrderAddItemTest|OrderStoreOptionalFieldsTest|OrderItemEditTest"`
pass. Browser: Fulfil now asks; Add item with an empty box → 1.

### 6. The order page and form behave like one tool (F-26 functional bits, F-20 follow-on)

Files: `app/Http/Controllers/OrderController.php`, `app/Http/Controllers/OrderAllocationController.php`,
`resources/views/orders/create.blade.php`, `resources/views/orders/show.blade.php`,
`resources/views/orders/partials/allocation-items.blade.php`,
`tests/Feature/OrderItemEditTest.php`, `tests/Feature/OrderTransitionsTest.php`

What:
- **Land on the line you touched.** Every line/allocation action redirect
  (`storeItem`, `updateItem`, `destroyItem`, `shortCloseItem`, `reopenItem`,
  `allocate`, `deallocate`, `fulfill`, `unfulfill`) appends
  `->withFragment('line-'.$orderItem->id)`; the partial gives each line
  block `id="line-{{ $orderItem->id }}"` (and the read-only table rows the
  same). `autoAllocate` and status changes keep landing at the top.
- **Delivery date default** on `/orders/create`: pass
  `customerRunDates` = `[customer_id => next run date]` where the customer is
  on a run (`DeliveryRun::dateFor(now())` if `>= today`, else
  `dateFor(now()->addWeek())`; whole-week runs → week start). JS fills
  `#delivery_date` on customer change **only if the field is empty or still
  holds the previous auto-fill**.
- `Line total` input: `tabindex="-1"` (it's read-only).
- Flash: `update()` says **"Order confirmed."** when the status moved
  `pending → confirmed`; "Order updated successfully." otherwise.
- Sibling list: age from `created_at` (`diffForHumans`), and the summary uses
  `productVariant->picker_label` (e.g. `24× Organic Milk · 1L Bottle`).
- Option labels on the create form show the **customer's** price when a
  special exists: on customer change, JS rewrites each option's text to
  `picker_label (€x.xx)` from `customerPrices`, falling back to the server
  label; mark special ones with `· special`.
- Tests: `updateItem` redirect is `route('orders.show', $order).'#line-'.$item->id`;
  the create page embeds `customerRunDates` for a customer on a Tuesday run
  with next Tuesday's date; Confirm flash text.

Check: `php artisan test --filter="OrderItemEditTest|OrderTransitionsTest|OrderStoreOptionalFieldsTest"`
pass. Browser: change a line qty → the page reloads scrolled to that line;
pick E2E Corner Shop on the create form → delivery date = next Tuesday; the
1L option reads "(€1.25 · special)".

### 7. Office forms land where the work is (F-16, F-28)

Files: `app/Http/Controllers/CustomerController.php`,
`app/Http/Controllers/CustomerSpecialPriceController.php`,
`app/Http/Controllers/DeliveryRunController.php`, `app/Http/Controllers/ChilledRunController.php`,
`resources/views/customers/show.blade.php`, `resources/views/chilled-runs/partials/_run-table.blade.php`,
`tests/Feature/CustomerSpecialPriceTest.php`, `tests/Feature/ChilledRunTest.php`

What:
- `CustomerController::store()` → `customers.show` for the new customer.
  `destroy()` → `customers.index` **with the incoming `search`/`is_active`
  query** (pass them through from the request).
- Special prices: all three redirects `->withFragment('special-prices')`;
  the panel gets `id="special-prices"`; the add `<select>` **omits variants
  that already have a special price** for this customer.
- Delivery runs: `assign`, `unassign`, `toggleCharge`, `reorder` redirect
  `->withFragment('stop-'.$customer->id)` (the moved/toggled stop; for
  `reorder` the moved id is posted — see step 8); the stop rows get
  `id="stop-{{ $stop->id }}"`.
- Chilled runs: `saveStop()` redirects `->withFragment('stop-'.$customer->id)`;
  the row editor gains a **Notes** textarea (`name="notes"`, 2 rows, in the
  Notes cell under the added-lines UI, `form="stop-form-{id}"`), validated
  `nullable|string|max:2000`, saved to the order (created or existing; the
  dirty-tracking `$watch` includes it). Display mode already shows notes.
- Tests: `store` redirects to `customers.show`; special-price redirect has the
  fragment and the select omits a priced variant; `saveStop` with `notes`
  persists and redirects with `#stop-{id}`.

Check: `php artisan test --filter="CustomerSpecialPriceTest|ChilledRunTest"`
pass. Browser: create a customer → its page; add a special price → page
reloads at the panel; save a sheet row → reloads at that row.

### 8. Reordering a run is one form (F-15, F-18)

Files: `app/Http/Controllers/DeliveryRunController.php`,
`resources/views/delivery-runs/index.blade.php`, `tests/Feature/ChilledRunTest.php`

What:
- Each stop row gets a `Position` number input (`positions[{customer_id}]`,
  value = current position, `min=1`) and the run panel gets one **Save
  order** button posting them all to `reorder` — the array now maps
  customer id ⇒ wanted position; `reorder()` sorts by wanted position (ties
  by current), renumbers 1…n, validates every id belongs to the run, and
  redirects with the fragment of the first stop whose position changed.
  Keep the ↑/↓ buttons but have them post `move: {customer_id, direction}`
  to the same endpoint (swap with the neighbour) and redirect to
  `#stop-{id}` — no more losing your place. Both shapes go through one
  method: `positions` xor `move`.
- Charge reveal: a stop with `delivery_charge_percent` shows
  **`{{ pct }} % of order value`** (tag, no toggle) instead of "€ charge:
  off"; the toggle stays for the others.
- Tests: posting `positions` as id ⇒ position renumbers correctly; `move`
  down swaps two stops; a customer not on the run is refused; the percent
  customer's row shows "5 % of order value".

Check: `php artisan test --filter=ChilledRunTest` pass. Browser: set E2E
Weekly Deli's position to 17 and Save → it's last, page scrolled to it; ↑
once → page lands on the row.

### 9. Dispatch and loaded flashes name orders and reasons (F-32, F-34 Edit)

Files: `app/Http/Controllers/ChilledRunController.php`,
`resources/views/orders/show.blade.php`, `tests/Feature/DispatchRunTest.php`,
`tests/Feature/OrderTransitionsTest.php`

What:
- `dispatchFlash()`: "N **orders** dispatched"; skipped entries per **order**:
  `ORD-… {customer} ({reasons})` where reasons are any of *not picked*
  (status ≠ ready), *not loaded*; e.g. `skipped: ORD-20261004-003 E2E Corner
  Shop (not picked, not loaded); ORD-20261004-005 An Tairseach (not picked,
  not loaded)`. Keep the invoice-failed / no-email parts.
- `toggleLoaded()` flash: `"{customer} · {order_number} marked loaded."`.
- `orders/show`: when `$order->invoice_id` is set, don't render **Edit**;
  render a muted line *"On invoice {number} — void it to edit."* (the
  billing line already links to Finance).
- Tests: `DispatchRunTest` flash contains the order number and both reasons;
  loaded flash contains the order number; an invoiced order's page has no
  `orders.edit` link.

Check: `php artisan test --filter="DispatchRunTest|OrderTransitionsTest|DispatchConfirmationTest"` pass.

### 10. Finance defaults and messages (F-37, F-38 defaults, F-41 message, cycle-3 follow-on)

Files: `resources/views/invoicing/invoices/show.blade.php`,
`app/Http/Controllers/Invoicing/ConsolidatedInvoiceController.php`,
`resources/views/invoicing/consolidated/create.blade.php`,
`app/Http/Controllers/Invoicing/StatementController.php`,
`resources/views/customers/show.blade.php`, `app/Http/Controllers/OrderAllocationController.php`,
`resources/views/invoicing/payments/create.blade.php`,
`tests/Feature/InvoiceVoidTest.php`, `tests/Feature/StatementTest.php`,
`tests/Feature/ConsolidatedInvoiceTest.php`, `tests/Feature/PaymentAllocationTest.php`,
`tests/Feature/OrderAllocationConcurrencyTest.php`

What:
- Invoice page: when issued but not voidable, render a disabled **Void**
  button with `title` and a muted line: *"Can't void — remove its payments
  (N) / credit note (CN-…) first."* (name what blocks it).
- Consolidated: add a **This week** shortcut (`period=this_week`, Mon–Sun of
  today); default stays last month.
- Statement preview default: **this month** (`today()->startOfMonth()`,
  `today()`), not last month.
- Customer page: when `outstanding_balance < 0` print **"€X in credit"** (and
  the green tone the page uses for credit), never a minus sign.
- `allocate()` refusal: distinguish *"This line is already fully allocated
  (N of N)."* from *"Only N units are available in this batch right now."*
  from the existing generic text (keep it as the final fallback).
- Payments form: seed `allocations` from `old('allocations', [])` so a
  refused submit keeps a manual split; set `allocationsTouched = true` when
  any were restored.
- Tests: void hint text on an invoice with a payment; `this_week` period
  bounds; statement default `from` = first of this month; "in credit" text;
  the two allocate messages; old allocations rendered.

Check: `php artisan test --filter="InvoiceVoidTest|StatementTest|ConsolidatedInvoiceTest|PaymentAllocationTest|OrderAllocationConcurrencyTest"` pass.

### 11. Error pages lead home (F-41)

Files: `resources/views/errors/404.blade.php` (new), `resources/views/errors/403.blade.php` (new),
`resources/views/errors/layout.blade.php` (new), `tests/Feature/ErrorPagesTest.php` (new)

What:
- A minimal standalone layout (own `<!DOCTYPE>`, `@vite` CSS, the app's
  background/ink vars, centred card) — not `x-app-layout` (errors happen for
  guests too). 404: *"That page doesn't exist."* 403: *"You don't have access
  to that page."* Both offer **one** link: signed-in factory → `/picking`
  ("Back to picking"); any other signed-in user → dashboard; guest → login.
  Include the request path in muted mono so the office can report it.
- Tests: guest 404 links to login; factory 403 on `/customers` links to
  `/picking`; office 404 links to the dashboard; no stack trace text.

Check: `php artisan test --filter=ErrorPagesTest` pass. Browser:
`/orders/999999` as factory shows the page with "Back to picking".

### 12. Docs

Files: `CLAUDE.md`, `docs/planImp/WORK_IN_PROGRESS.md`

What:
- `CLAUDE.md`: Stock Overview → the `expired` per-type summary and the
  dashboard tile; Mobile Picking → one-day queue + stale bucket + cyclic
  Skip; Chilled Run Sheet → row editor has Notes; Delivery runs → `reorder()`
  accepts `positions` (id ⇒ position) or `move`; a one-line **Redirect
  convention**: line/stop/panel actions redirect `->withFragment(...)` to the
  element edited, status-level actions to the top; `errors/` views exist.
- `WORK_IN_PROGRESS.md`: *Recently Completed: Friction fixes (2026-10-04)*
  entry listing the findings closed.

Check: `grep -n "withFragment\|stale bucket\|positions\b.*move\|errors/" CLAUDE.md` → hits.

## Verification

1. `php artisan test` → **0 failed**; record the passing count.
2. `./vendor/bin/pint --test app tests database routes` → clean.
3. `npm run build` → ok.
4. `git status --short` → only files this plan names (+ the staged archive).
5. Browser checks from steps 1–9 and 11 with real input (`php artisan serve`
   on 127.0.0.1 is fine; 500 × 659 for the picking steps). Console clean.
6. Dev-DB probe: nothing in this cycle moves stock or money; confirm
   `orders`, `payments`, `invoices` counts are unchanged except any order the
   browser checks create (tag them `E2E-20261004 cycle4` and cancel them).

## Risks

- **Fragments and flashes.** `withFragment` keeps the session flash; the
  layout's feedback region renders at the top, so a user scrolled to a line
  may not see the flash. Acceptable here (the line itself changes), but say
  so in the report; cycle 5 can anchor the flash near the fragment.
- **Picking default day** must never hide a `confirmed`/`preparing` order:
  only `ready` orders go to the stale bucket.
- **Reorder semantics**: validate that every posted id belongs to the run and
  that `positions` and `move` are mutually exclusive; the old per-row forms
  posted the whole array and must not remain anywhere (grep the view).
- **Merging duplicate variants in `store()`** changes the line count a
  customer sees; the summed quantity is what they ordered, so it's right —
  but check `OrderStoreOptionalFieldsTest` and `DeliveryChargeTest` still
  pass (they create orders via `store()`).
- **Error views**: Laravel renders `errors/404.blade.php` automatically when
  present; make sure the layout has no dependency on a signed-in user
  (`auth()->user()` may be null) and that the view doesn't itself 500 for an
  API-shaped request (JSON requests still get JSON — don't change that).
- **Session length.** Twelve steps; update `implemented.md` after each; a
  `BLOCKED — resume from N+1` is a fine outcome.

## Review

Reviewed 2026-10-04 against `implemented.md` (Status: DONE, 712 lines, read to
the end) and the full diff of the ten controllers/services, the new views and
the two view families with the most logic (release control, line controls).
Planner reran: `php artisan test` → **598 passed, 0 failed** (+41);
`pint --test app tests database routes` → PASS 264 files; `npm run build` ok;
`grep 'name="positions[]"' resources/views` → none; dev DB: orders 21 (19 +
two cancelled cycle-4 probes), payments 4, invoices 7, run #1 positions 1…17,
G010426 item 46 `held=1 remaining=7` (the step-4 release the plan asked for).

### Criteria — all twelve pass

1 Expired stock: dashboard "ready now" strict + has-stock; `expiredCount/Units`
from one join; `/stock` cards get `expired` from one grouped query; hints on
batch create/show; **plus** `?status=expired` widened so the tile's link isn't
empty (deviation 2 — right call, pinned). 2 One-day queue: `partition` stale
`ready`-before-today; `selectDay()` ?date → today → nearest future → latest
past → undated; strip counts the day; `$nextOrder` prefers the same day.
3 Skip cycles (`slice($position)->concat(take($position−1))`), stepper
disabled at limits, `$expWeight` null when nominal ≤ 0 (both surfaces).
4 Partial release: number input bound by `form=`, dynamic `data-confirm`,
`:disabled` while the zone is dirty with a hint. 5 Pick/Ordered labels, Fulfil
asks ("Record a pick of N × … from batch …" rewritten live), Add item blank →
1, `store()` merges variants. 6 Fragments on every line action (`#order-items`
after a removal — right, the line is gone), run-date default, `tabindex=-1`,
"Order confirmed.", `created_at` age, `picker_label` summaries, special-price
option labels. 7 Customer create → show; delete keeps filters via the form
action; special prices → `#special-prices` and the select omits priced
variants; run actions → `#stop-{id}` (`#run-{id}` after unassign); sheet
Notes textarea with dirty tracking. 8 `positions` (id ⇒ position) xor `move`,
unknown id refused, re-insert semantics, lands on the moved stop; percent tag.
9 Per-order skipped list with both reasons; loaded flash names the order;
invoiced orders lose Edit. 10 Void blocker named; This week link (the period
already existed); statement defaults this month; "in credit" on both figures;
two specific allocate refusals; old allocations restored. 11 Standalone error
layout with one home link; JSON 404 untouched. 12 Docs present.

### Deviations — all accepted

1 baseline; 2 expired filter widened; 3 extra partial; 4 the fifteen redirect
assertions and the other adapted tests follow behaviour the plan changed —
the new assertions are stricter, not looser; 5 container fragments when the
element is gone; **6 and 7 corrected the plan** (see above); 8 filter carried
in the delete form's action; 9 Dispatch run not pressed — right, it would
issue real invoices in dev and `DispatchRunTest` pins the text; 10–12 fine.

### Notes for Planner — decisions

- Flash out of view after a fragment redirect → **cycle 5**: a transient
  inline "✓ saved" mark on the target element (or render the flash region
  sticky). Known from this plan's Risks.
- Sheet `<tr>`s lack `scroll-mt` → **cycle 5**, one line.
- Unmatched-URL 404 shows "Sign in" to a signed-in user → **cycle 5**:
  `Route::fallback()` inside the `web` group so the session loads; keep the
  JSON behaviour.
- Step-4 release moved dev stock → **keep** (recorded in `RESUME.md`).
- Footer height → nothing to do.
- Harness `<select>` keyboard quirk → `handoff/REPORT.md` for the browser
  session.
- `allocate()` third message ("Only N more needed on this line") → **cycle 5**.
- `reorder()` unknown-id flash vs validation error → fine as is.

Nothing to redo. Archive: `mkdir -p docs/planImp/archive/2026-10-04-friction`
and move `plan.md` + `implemented.md` there.
