# Friction: the S3 findings from the end-to-end run — implementation

Status: DONE
Plan revision: 1
Implementer: Opus
Date: 2026-10-04

## Baseline
HEAD: f22df0c (the plan's Context says `7c86326` + a staged archive; that
archive has since been committed as `f22df0c`)
Pre-existing dirty files: clean

## Steps

### 1. Expired stock is visible — done
Changed: `routes/web.php` (`readyNowCount` now also requires not expired —
strict `>` today — and a batch item with stock; new `expiredCount` /
`expiredUnits` from one join query), `resources/views/dashboard.blade.php`
(the **Expired stock** tile, danger tone, own row above the KPI row, rendered
only when `expiredCount > 0`, for every role that gets batch stats, i.e.
admin/office/factory), `app/Services/StockOverviewService.php`
(`expiredByType()`: one grouped query, merged into each card as `expired`),
`resources/views/stock/overview.blade.php` + **new**
`resources/views/stock/partials/_expired-note.blade.php` (see Deviations),
`resources/views/batches/create.blade.php` (`#expiry-note` swaps to the
no-shelf-life text on product change, restoring `data-default-text`
otherwise), `resources/views/batches/show.blade.php` (Expiry row always;
"None — stays sellable until sold"), `app/Http/Controllers/BatchController.php`
(`?status=expired` also matches active batches past expiry — see Deviations),
`tests/Feature/DashboardTest.php` (new, 3), `StockOverviewTest` (+2, one
assertion changed — see Deviations), `BatchCreateTest` (+2).
Check output:
```
$ php artisan test --filter="DashboardTest|StockOverviewTest|BatchCreateTest"
  Tests:    17 passed (71 assertions)
```
Browser: see *Browser checks*.

### 2. The picking queue shows one day — done
Changed: `app/Http/Controllers/PickingController.php` (`index(Request)`:
partitions **stale** = `ready` with a delivery date before today; the rest is
grouped by `dayKey()` (`Y-m-d` or `none`); `selectDay()` = `?date=` if it is a
day with work → today → nearest future → latest past → first (undated); the
strip counts the selected day only; passes `selected`/`prevDay`/`nextDay`/
`stale`. `show()`'s `$nextOrder` prefers the same `dayKey`, then the old queue
order), `resources/views/picking/index.blade.php` (heading is the day label —
Today/Tomorrow/`D j M`/No delivery date — with ◀ ▶ links to the neighbouring
days; the per-date group headers are gone since the page is one day; collapsed
`<details data-stale-bucket>` "Ready, awaiting dispatch — N orders from
earlier days", each row `order_number · customer · j M` → `picking.show`),
`resources/css/app.css` (`.mob-day-nav`), `tests/Feature/PickingTest.php` (+3:
one-day + stale bucket + `?date=` switch; past `preparing` order stays a
selectable day; today wins when present).
Check output:
```
$ php artisan test --filter=PickingTest
  Tests:    20 passed (98 assertions)
```
Browser: see *Browser checks*.

### 3. Picking ergonomics — done
Changed: `app/Http/Controllers/PickingController.php` (`item()`: `$skipTo` =
first unsettled line in `lines after $position` ++ `lines before it`, so Skip
cycles forward), `resources/views/picking/item.blade.php` (stepper `−`
`:disabled="qty <= 1"`, `+` `:disabled="qty >= maxQty"`; `$expWeight` is
`weight_kg` only when `> 0`, else `null` → no "expected ~" line, no Check tag,
OK when > 0; the short section gets `margin-bottom: 24px` — see Browser
checks for the measurement), `resources/views/orders/partials/allocation-items.blade.php`
(desktop `data-expected` only rendered for a positive nominal `weight_kg`, the
same figure the server bound uses — it previously used the batch item's
`unit_weight_kg`), `resources/css/app.css` (`.mob-step-btn[disabled]`:
opacity .4, default cursor), `tests/Feature/PickingTest.php` (+3: Skip 2→3 and
3→1; stepper `:disabled` bindings render; a 0 kg nominal gives no Check/expected).
Check output:
```
$ php artisan test --filter=PickingTest
  Tests:    23 passed (109 assertions)
```
Browser (500 × 659): see *Browser checks*.

### 4. Release part of a hold — done
Changed: `resources/views/cheese-conversion/index.blade.php`: the header shows
"Release [n] of N to mature [Release]". The number input (`name="quantity"`,
`form="release-form-{id}"`, `min=1 max=N value=N required`) and the button
belong to the sibling release form through `form=`; the form's hidden
`quantity` is gone. `x-on:input` on the input rewrites the button's
`data-confirm` to "Release {n} held wheel(s) into mature cheese?" (it sits
inside the card's existing Alpine scope, reached via `x-ref`, with no
`onclick`). The button is `:disabled="mature.length !== N"`, and an
`x-show`/`x-cloak` "Save maturing first" hint shows while the Maturing zone
differs from the saved hold. `tests/Feature/CheeseConversionTest.php`: +1
(page renders `min="1" max="2" value="2"`; POST `quantity=1` on a hold of 2
leaves 1 held, 1 converted). One existing test was adapted; see Deviations.
Check output:
```
$ php artisan test --filter=CheeseConversionTest
  Tests:    20 passed (92 assertions)
```
Browser: see *Browser checks*.

### 5. Order line controls — done
Changed: `resources/views/orders/partials/allocation-items.blade.php`
- **F-21:** all three Fulfil forms (fixed-qty, bulk-weighed, per-unit) label
  their count box **Pick** (`<label for>` + `aria-label="Units to pick
  now"`). Each Fulfil button carries
  `data-confirm="Record a pick of N × {picker_label} from batch {code}?"`,
  `data-confirm-title`/`-label` "Record pick", and a `data-confirm-template`
  with `{n}`; the count box's `oninput` (`onchange` on the per-unit select,
  after `updateWeightInputs`) rewrites `data-confirm` from the template. The
  line's quantity box is labelled **Ordered:** (`aria-label="Quantity
  ordered"`).

`resources/views/orders/show.blade.php`
- The pending-status read-only table's line box gets the same **Ordered:**
  label, for consistency.
- **F-22:** Add item quantity is `value="{{ old('quantity') }}"
  placeholder="1"`, with no `required`.

`app/Http/Controllers/OrderController.php`
- `storeItem()`: `quantity` is `nullable|integer|min:1`, and blank means 1.
- **F-23:** `store()` groups the validated items by `product_variant_id` and
  sums the quantities before creating lines.

Tests:
- `OrderAddItemTest` +2: a blank box creates a line of 1, and there is no
  `value="1"`; the Pick/Ordered labels and the confirm text render.
- `OrderStoreOptionalFieldsTest` +1: the same variant twice gives one line of
  7 and a total of €13.00.

Check output (`DeliveryChargeTest` added, because the plan's Risks call it out):
```
$ php artisan test --filter="OrderAddItemTest|OrderStoreOptionalFieldsTest|OrderItemEditTest|DeliveryChargeTest"
  Tests:    45 passed (201 assertions)
```
Browser: see *Browser checks*.

### 6. The order page and form behave like one tool — done
Changed:

`app/Http/Controllers/OrderController.php`
- `storeItem` / `updateItem` / `shortCloseItem` / `reopenItem` success (and
  their line-specific refusals: "No change to quantity.", "already fully
  fulfilled") redirect `->withFragment('line-'.$id)`. `storeItem` looks up
  the merged-or-new line by variant.
- `destroyItem`'s "Item removed" goes to `#order-items`, because the line no
  longer exists (see Deviations); the last-line cancel stays at the top.
- `update()` says **"Order confirmed."** on `pending → confirmed`.
- `create()` adds `customerRunDates` (`dateFor(now())`, else next week's when
  it's already past) and `customerPriceLabels` (special price as `€x.xx` or
  `€x.xx/kg`).

`app/Http/Controllers/OrderAllocationController.php`
- Every redirect in `allocate` / `deallocate` / `fulfill` / `unfulfill` gets
  `#line-{order_item_id}`. `autoAllocate` and status actions are unchanged.

`resources/views/orders/partials/allocation-items.blade.php` +
`orders/show.blade.php`
- Each `<x-order.item-row>` gets `id="line-{id}" class="scroll-mt-20"`; the
  attributes merge onto the component root, so the component is untouched.
  The Items heading gets `id="order-items"`.
- The sibling list shows age from `created_at` and a summary of `N×
  picker_label`.

`resources/views/orders/create.blade.php`
- Options carry `data-picker`. `relabelOptions()` rewrites each option's text
  to `picker_label (€x.xx · special)` on customer change and on rows added
  later, falling back to the server label kept in `data-default-label`.
- `fillDeliveryDate()` fills `#delivery_date` only when it is empty or still
  holds the previous auto-fill, and clears that auto-fill if the new
  customer has no run.
- The Line total inputs get `tabindex="-1"`.

Tests
- `OrderItemEditTest` +3: the update redirect carries `#line-{id}` and the
  page carries the anchors; the create form exposes the next-Tuesday run date
  and the `€1.25` special label, plus `data-picker` and `tabindex`; the
  sibling summary reads `24× Test Milk · 2L Bottle`.
- `OrderTransitionsTest` +1: "Order confirmed." vs "Order updated
  successfully.".
- 15 existing redirect assertions now expect the fragment (see Deviations).
Check output:
```
$ php artisan test --filter="OrderItemEditTest|OrderTransitionsTest|OrderStoreOptionalFieldsTest"
  Tests:    34 passed (145 assertions)
$ php artisan test --filter="FulfilmentUnitWeightsTest|OrderAddItemTest|OrderFulfillmentWeightTest|OrderItemEditTest|OrderShortSupplyTest"
  Tests:    61 passed (246 assertions)
```
Browser: see *Browser checks*.

### 7. Office forms land where the work is — done
Changed:

`app/Http/Controllers/CustomerController.php`
- `store()` → `customers.show` for the new customer.
- `destroy(Request, …)` → `customers.index` carrying the posted
  `search`/`is_active` (non-empty only).
- `resources/views/customers/index.blade.php`: the Delete form's action
  carries the current `search`/`is_active` query (see Deviations — not a
  named file).

Special prices (`app/Http/Controllers/CustomerSpecialPriceController.php`,
`resources/views/customers/show.blade.php`)
- All three redirects → `#special-prices`.
- The panel gets `id="special-prices"`.
- The Add select drops variants already priced for this customer, and an
  optgroup left empty is skipped.

`app/Http/Controllers/DeliveryRunController.php`
- `assign` and `toggleCharge` → `#stop-{customer}`; `reorder` → see step 8.
- `unassign` → `#run-{run}`, because the stop row is gone (see Deviations).

`resources/views/delivery-runs/index.blade.php`
- Rows get `id="stop-{id}"`; run panels get `id="run-{id}"`.

`app/Http/Controllers/ChilledRunController.php` +
`resources/views/chilled-runs/partials/_run-table.blade.php`
- `saveStop()` validates `notes` as `nullable|string|max:2000`. It is written
  to the order only when the key was posted; blank becomes null.
- `$backToSheet` redirects add `#stop-{customer}`.
- `buildSheet()` adds `editNotes`.
- The editor gets a 2-row **Notes** textarea (`form="stop-form-{id}"`,
  `x-model="notes"`) in the Notes cell, under the add-line select. It
  replaces the read-only notes line edit mode used to show.
- `stopEditor` adds `notes` to its state, its `$watch` list and
  `serialize()`, so it counts towards the unsaved-changes guard.

Tests
- `CustomerSpecialPriceTest` +2: the Add select omits a priced variant; a new
  customer lands on `customers.show`; delete returns to the filtered index.
- `ChilledRunTest` +2: `saveStop` notes persist, prefill, clear on blank and
  survive an absent key, with the redirect to `#stop-{id}`; assign lands on
  `#stop-{id}`, unassign on `#run-{id}`.
- Two existing redirect assertions updated: special-price CRUD and the
  `DeliveryChargeTest` toggle. See Deviations.
Check output:
```
$ php artisan test --filter="CustomerSpecialPriceTest|ChilledRunTest|DeliveryChargeTest"
  Tests:    100 passed (409 assertions)
```

### 8. Reordering a run is one form — done
Changed:

`app/Http/Controllers/DeliveryRunController.php`
- `reorder()` validates `positions` (`required_without:move|prohibits:move|array`,
  `positions.*` integer ≥ 1) **xor** `move` (`customer_id`, `direction` up|down).
- Every posted id must be on the run; otherwise it redirects with an error
  flash and nothing changes.
- `positions` is `id ⇒ wanted position` and goes through
  `orderByWantedPosition()`. Each stop whose wanted position differs from its
  current one is taken out and re-inserted at that position (ascending wanted,
  ties by current); the rest keep their order. See Deviations for why this
  isn't a plain sort.
- `move` goes through `swapWithNeighbour()`.
- Renumbers 1…n, writing only rows that changed.
- Redirects to `#stop-{first stop whose position changed}` (positions) or
  `#stop-{moved id}` (move).

`resources/views/delivery-runs/index.blade.php`
- Each row has a `positions[{id}]` number box (`min 1`, `max n`) tied by
  `form=` to one hidden `reorder-{run}` form, plus a **Save order** button
  when there are 2+ stops.
- ↑/↓ are small forms posting `move[customer_id]` / `move[direction]`. No
  `positions[]` form remains (grepped).
- A `delivery_charge_percent` stop shows a tag "5 % of order value" (trailing
  zeros trimmed) instead of the on/off toggle.

Tests (`ChilledRunTest`)
- +4: id ⇒ position renumbers (1→4 makes it last; 4→1) with the fragment;
  `move` down swaps; positions + move together is refused; a customer on
  another run is refused with no change; the page renders the position
  inputs, one Save order, the `move` forms, the percent tag with no toggle,
  and no `positions[]`.
- The existing `test_assign_reorder_and_unassign_stops` now posts the new
  shape. See Deviations.
Check output:
```
$ php artisan test --filter=ChilledRunTest   (run together with step 7, above)
  Tests:    100 passed (409 assertions)
```
Browser: see *Browser checks*.

### 9. Dispatch and loaded flashes name orders and reasons — done
Changed:

`app/Http/Controllers/ChilledRunController.php`
- `dispatchAll()` passes one sorted collection of skipped orders (not ready +
  ready-but-unloaded).
- `dispatchFlash(array $results, Collection $skippedOrders)` builds a list per
  **order**: `ORD-… {customer} ({reasons})`, where the reasons are *not picked*
  (status ≠ ready) and *not loaded* (`loaded_at` null), both when both apply.
- Success reads "N order(s) dispatched … · skipped: …". The
  nothing-to-dispatch error carries the same "(skipped: …)" list.
- The invoice-failed and no-email parts are unchanged.
- `toggleLoaded()` reads "{customer} · {order_number} marked loaded." (and
  "… unmarked — back on the to-load list.").

`resources/views/orders/show.blade.php`
- When `invoice_id` is set, Edit is replaced by the muted line "On invoice
  INV-… — void it to edit.".

Tests
- `DispatchRunTest` +1: a preparing, unloaded order's flash reads `ORD-… An
  Tairseach (not picked, not loaded)`; the loaded flash names the order.
- `DispatchRunTest`: three existing assertions moved to the new text ("1
  order dispatched", per-order skipped entries; "not ready" → "not picked").
  The plan changes this text.
- `OrderTransitionsTest` +1: before dispatch the page links `orders.edit`;
  after dispatch (invoice issued) it doesn't, and shows the "On invoice"
  line.
Check output:
```
$ php artisan test --filter="DispatchRunTest|OrderTransitionsTest|DispatchConfirmationTest|ChilledRunTest"
  Tests:    112 passed (462 assertions)
```

### 10. Finance defaults and messages — done
Changed:

`resources/views/invoicing/invoices/show.blade.php`
- A `$voidBlocker` is computed for an issued invoice that isn't voidable,
  from the distinct allocated payments and the issued credit-note numbers:
  "Can't void — remove its payments (1) and credit note (CN-…) first."
- The page renders a disabled **Void** button with that text as its `title`,
  plus a muted line (`data-void-blocker`) under the header.

`resources/views/invoicing/consolidated/create.blade.php`
- Adds a **This week** shortcut. `period=this_week` (Mon–Sun) already existed
  in `ConsolidatedInvoiceController::resolvePeriod()`; only the link was
  missing, so the controller is unchanged. The default stays last month.

`app/Http/Controllers/Invoicing/StatementController.php`
- `resolvePeriod()` defaults to `[startOfMonth, today]`.
- It is used only by `preview()`; the monthly batch command picks its own
  period.

`resources/views/customers/show.blade.php`
- Both balance figures — "Outstanding" (`outstanding_balance`) and the
  Account panel's "Net owed" (`$aged['total']`) — print "€X in credit" in
  `--accent-ink` when negative, never a minus.

`app/Http/Controllers/OrderAllocationController.php`
- `allocationRefusal()` is checked in this order:
  1. "This line is already fully allocated (N of N)."
  2. "Only N units are available in this batch right now."
  3. Otherwise the old generic text.

`resources/views/invoicing/payments/create.blade.php`
- `paymentForm(…, initialAllocations)` is seeded from the non-empty
  `old('allocations')`. When any are restored, `allocationsTouched = true`
  so the amount watcher doesn't overwrite them.

Tests
- `InvoiceVoidTest` +1: no blocker on a clean invoice; "its payments (1)";
  then "… and credit note (CN-…)"; a disabled button.
- `ConsolidatedInvoiceTest` +1: `this_week` bounds are Monday–Sunday, the
  link renders, and the default is still `last_month`.
- `StatementTest` +2: the preview's `from` is the first of this month and
  `to` is today; the customer page shows "€25.00 in credit" and no
  "€-25.00".
- `PaymentAllocationTest` +1: a refused submit (blank amount) brings back the
  7.25 Apply figure.
- `OrderAllocationConcurrencyTest` +1: both refusal messages.
Check output:
```
$ php artisan test --filter="InvoiceVoidTest|StatementTest|ConsolidatedInvoiceTest|PaymentAllocationTest|OrderAllocationConcurrencyTest"
  Tests:    53 passed (174 assertions)
```

### 11. Error pages lead home — done
Changed: **new** `resources/views/errors/layout.blade.php`: a standalone page
with its own `<!DOCTYPE>`, `@vite(['resources/css/app.css'])`, the app's
`--bg`/`--ink` vars and a centred `mf-panel` card. It shows the code, the
message, the request path in muted mono (`data-error-path`), and **one**
link: factory → `picking.index` "Back to picking"; any other signed-in user
→ dashboard "Back to the dashboard"; guest → login "Sign in". `auth()->user()`
may be null and is handled. **New** `errors/404.blade.php` ("That page
doesn't exist.") and `errors/403.blade.php` ("You don't have access to that
page.") extend it. **New** `tests/Feature/ErrorPagesTest.php` (4): a guest
404 links to login (path shown, no stack trace / vendor text); an office 404
on `/orders/999999` links to the dashboard; a factory 403 on `/customers`
links to `/picking`; a JSON 404 is still JSON.
Check output:
```
$ php artisan test --filter=ErrorPagesTest
  Tests:    4 passed (18 assertions)
```
Note: an *unmatched* URL 404s before the `web` middleware group runs, so no
session is loaded and even a signed-in user is shown the guest link (Sign
in). The login route's `guest` middleware then sends them on to the
dashboard, so it still leads home, one hop longer. A bound-model 404
(`/orders/999999`) and a 403 see the real user.

### 12. Docs — done
Changed: `CLAUDE.md`
- Stock Overview gains an *Expired stock stays visible* bullet: the `expired`
  summary, the empty-card text, the dashboard tile, the `?status=expired`
  filter and "ready now".
- Mobile Picking gains *One day at a time* (the queue rules and the stale
  bucket) and *Skip is cyclic* (plus the stepper and weight thresholds).
- Chilled Run Sheet gains a *Notes* bullet in Inline order entry, and the
  Routes bullet now documents `reorder()`'s `positions` xor `move` and the
  percent tag.
- New *Redirect convention* and *Error pages* subsections under Frontend
  Development.

`docs/planImp/WORK_IN_PROGRESS.md`: a new "Recently Completed: Friction fixes
(2026-10-04)" entry listing the findings closed.
Check output:
```
$ grep -n "withFragment\|stale bucket\|positions\b.*move\|errors/" CLAUDE.md
149:Line / stop / panel actions redirect `->withFragment(...)` to the element they edited …
152:`resources/views/errors/{layout,404,403}.blade.php` — a standalone page …
597:- **One day at a time**: … they sit in the collapsed stale bucket, …
611:- **Routes**: … **`DeliveryRunController::reorder()`** accepts exactly one of `positions` … or `move` …
```

## Browser checks
`php artisan serve --host=127.0.0.1:8000`, a fresh `npm run build`, real mouse
and keyboard. The tab came up hidden once and Jon brought the window forward;
after that `visibilityState` was `visible`. Office at 1408 px; factory at
**500 × 659** (window 500×844 gives `innerWidth 500, innerHeight 659`). No
console errors on any page.

### Step 1 — expired stock (office)
- Dashboard: an **Expired stock** tile reading "5 · 5,907 units · write off or
  close". 5,907 matches the DB (2610+1470+951+246+630). Clicking it opens
  `/batches?status=expired` listing exactly M070926, M230726, M230726-1,
  M220626 and Y220626 ✓.
- `/stock`: the milk card reads "4 expired batches still hold 4,437 units —
  see Batches."; yoghurt reads "1 expired batch still holds 1,470 units". Dev
  now has fresh M041026/Y041026 stock, so this is the *with-stock* variant;
  the empty variant is covered by `StockOverviewTest` ✓.
- `/batches/create`: Yoghurt (no shelf life) → "This product has no shelf
  life set — the batch will have **no expiry** …"; Milk → back to
  "Auto-filled from the product's shelf life, when set." with expiry
  2026-10-14 ✓.
- `/batches/20` (yoghurt, no expiry): "Expiry date · None — stays sellable
  until sold" ✓.

### Step 4 — partial release (office)
- G010426 had 1 held. I clicked one farmhouse wheel and pressed **Mature →**.
  The Release button went **disabled** and "Save maturing first" appeared ✓.
- **Save maturing · 2** gave "Maturing hold updated — 2 wheel(s) set aside."
  and the input read `value 2, max 2`.
- I typed 1 and pressed Release. The dialog said "Release 1 held wheel(s)
  into mature cheese?"; confirming gave "Released 1 wheel(s) to mature
  cheese."
- DB: batch item 46 `held=1 remaining=7`, newest log `wheels_converted=1` ✓.
  This is a real stock movement, as the plan's check asked (see Notes).

### Step 6 — order form and page (office)
- Order form, E2E Corner Shop:
  - Delivery date auto-filled **2026-10-06** (Tue) ✓.
  - The 1L option reads "Organic Milk · 1L Bottle (**€1.25 · special**)"; 2L
    keeps "(€4.50)". The rows added later carry the special label too ✓.
  - Tab from the quantity skips Line total ✓.
- **Step 5 F-23:** 1L × 24 plus a second 1L × 6 row previewed a total of
  €43.65. Create gave **ORD-20261004-010** (id 21) with **one** line of 30
  and Total €43.65 ✓.
- The sibling list shows "4h" and "20× Organic Milk · 1L Bottle · …" ✓.
- **Step 5 F-22:** Add item with 2L and the box blank gave "Item added to
  order." — a line of **1** — and the URL `/orders/21#line-56` with the line
  in view ✓.
- Confirm order → dialog → **"Order confirmed."** ✓.
- Auto allocate → "Auto-allocated 31 units across 2 lines."
- **Step 5 F-21:** the boxes are labelled **Pick** / **Ordered:**. Typing 5
  in Pick and pressing **Enter** opened the dialog "Record pick — Record a
  pick of 5 × Organic Milk · 1L Bottle from batch M041026?", with focus on
  Cancel. I cancelled, so nothing was picked ✓.
- **Ordered:** 30 → 29, then Enter, gave "Item quantity updated." and the
  URL `#line-55`, with the line at top 80 px (the `scroll-mt-20` offset) ✓.
  The flash sits at the page top, out of view, as the plan's Risks predicted.
- Order 21 was cancelled at the end ("Order cancelled. Reserved and picked
  stock has been returned to its batch.").

### Step 7 — office forms
- Created customer "E2E-20261004 cycle4 Test Shop": it lands on
  `/customers/30` ✓.
- Added a 1L special at €1.10: "Special price added.", the URL
  `#special-prices`, the panel in view, and 1L **gone** from the Add select ✓.
- Deleted the customer from `/customers?search=cycle4` (dialog): "Customer
  deleted successfully." and back on `/customers?search=cycle4` with the
  search box still "cycle4" ✓. The customer and its special price are gone.
- Chilled sheet `?run=1&date=2026-10-06&edit=20` (Avoa Ballsbridge, no order
  that day):
  - Typed 10L Churn 1 and the note "E2E-20261004 cycle4 (to cancel)";
    `__stopDirty` went `true`.
  - **Save** gave "Order for Avoa Ballsbridge saved (ORD-20261004-011)." and
    `#stop-20`; the row shows the note ✓.
  - Re-editing pre-filled the note. Zeroing and saving gave "Order for Avoa
    Ballsbridge cancelled — all quantities cleared …" and `#stop-20`.
- E2E Corner Shop couldn't be used: it has 3 orders for that date, so its
  row is the "Multiple orders" link, not editable.

### Step 8 — run reorder
- E2E Weekly Deli position 2 → **17**, then **Save order**: "Stop order
  updated for Dublin Tue." and Deli is 17th of 17 ✓.
- The *first* run landed on `#stop-10` (An Tairseach, the first stop whose
  number changed) with Deli off-screen. I changed `reorder()` to land on the
  stop whose Position box was changed (see Deviations) and re-ran: 17 → back
  to 2 by **Enter** in the box (implicit submit through the `form=` Save
  button) gave `#stop-28`, row at top 80 ✓; 2 → 17 with Save order gave
  `#stop-28`, Deli last and in view ✓.
- **↑** once: Deli 16th, `#stop-28`, row in view ✓.
- Restored Deli to 2; positions are 1…17 again.
- Show charge settings: Deli shows "**5 % of order value**" (no toggle);
  Corner Shop keeps "€ charge: on" ✓.

### Step 9 — flashes
- Loaded tick on order 21 in the sheet: "E2E Corner Shop · ORD-20261004-010
  marked loaded."; ticked again: "… unmarked — back on the to-load list." ✓.
- `/orders/5` (invoiced): no Edit link, and "On invoice INV-000001 — void it
  to edit." ✓.
- `/invoicing/invoices/7`: a disabled Void with title and line "Can't void —
  remove its payments (2) first." ✓ (that's step 10).
- **Dispatch run was not pressed.** It would issue real invoices in the dev
  DB, and the plan's step 9 check is tests-only; the per-order flash text is
  pinned by `DispatchRunTest`.

### Steps 2 and 3 — picking (factory, 500 × 659)
- `/picking` opens on **"Tue 6 Oct"** (the nearest day with work), not July.
  - The strip reads 0 / 42: the two Tuesday Corner Shop orders, 12 + 30 ✓.
  - The collapsed bucket reads "Ready, awaiting dispatch — 2 orders from
    earlier days" (ORD-20260710-001 · An Tairseach · 14 Jul; ORD-20260723-001
    · Barnhill Stores · 28 Jul) ✓.
  - ▶ goes to `?date=none` "No delivery date" (37 / 37), with ◀ back to Tue
    6 Oct ✓.
- `/picking/21/items/55` (29 needed):
  - The stepper starts at 29 with **+ disabled** (opacity .4); − gives 28
    with + enabled, + gives 29 with + disabled again ✓.
  - Line 56 (1 needed): both − and + disabled ✓.
- Skip on 55 → 56, Skip on 56 → 55 (a two-line order cycles) ✓.
- Short button, scrolled to the bottom at 659 px tall: its bottom edge is at
  525 and the fixed footer starts at 575 (Picked · Next at 589), so it is
  fully visible ✓.
  - On this screen the footer is **one** row, 85 px tall, not two as the plan
    expected. The 110 px shell padding already cleared it; the new 24 px
    margin is extra headroom.
- 0 kg nominal thresholds: no such variant in dev; covered by `PickingTest`.

### Step 11 — error page
- `/orders/999999` as factory: title "Not found · Mossfield", "404 / That
  page doesn't exist. / /orders/999999 / [Back to picking]". Clicking it goes
  to `/picking` ✓.

## Deviations
1. **Baseline HEAD is `f22df0c`, not `7c86326` + staged archive** — the archive
   was committed before this cycle started. Tree clean.
2. **`/batches?status=expired` widened** (`BatchController::index`, not a
   plan-named file). The filter matched only `status='expired'`, but every
   expired batch in dev is still `status='active'` with a past expiry, so the
   tile's link would have opened an empty list. It now also matches active
   batches with `expiry_date <= today` (strict, as `Batch::isExpired()`).
   Pinned by `DashboardTest::test_the_expired_filter_…`.
3. **New partial `stock/partials/_expired-note.blade.php`** — the note appears
   in six places (empty / non-empty × three cards). Not a plan-named file.
4. **Existing tests adapted, where a step deliberately changes the
   behaviour they pin:**
   - `StockOverviewTest` empty-state text ("No active milk batches" → "No
     sellable milk.").
   - `CheeseConversionTest::test_the_release_button_posts_exactly_the_saved_hold`:
     the hidden `quantity` became the editable header input tied by `form=`.
     The test still pins that the default is the saved hold and adds the
     dirty-disable binding.
   - **15** `assertRedirect(route('orders.show', $order))` assertions in
     `FulfilmentUnitWeightsTest`, `OrderAddItemTest`,
     `OrderFulfillmentWeightTest`, `OrderItemEditTest` and
     `OrderShortSupplyTest` now expect `#line-{id}` (or `#order-items` for a
     removal). The new URLs are more specific, not looser; one store case
     uses `assertRedirectContains(…'#line-')` because the new line's id isn't
     known before the request.
   - The special-price CRUD redirects (`#special-prices`), the
     `DeliveryChargeTest` toggle redirect (`#stop-{id}`), and
     `ChilledRunTest::test_assign_reorder_and_unassign_stops`, which now posts
     `positions` as `id ⇒ position` (the old list shape is gone by design).
   - `DispatchRunTest`: three flash assertions moved to "N order(s)
     dispatched", per-order skip entries, and "not picked" instead of "not
     ready".
5. **`destroyItem` → `#order-items`, `unassign` → `#run-{id}`.** The plan
   asked for the line / stop fragment, but those elements no longer exist
   after the action; the closest container is used instead.
6. **Reorder semantics are not a plain "sort by wanted, ties by current".**
   Taken literally, moving the 2nd of 17 stops to "17" ties with the
   current 17th; the moved stop's lower current position then wins, so it
   lands 16th and the plan's own check ("→ it's last") fails. Instead a stop
   whose wanted position differs from its current one is taken out and
   re-inserted at that position (moved stops in wanted order, ties by
   current), and the rest close up.
7. **Reorder landing fragment:** the plan says "the first stop whose position
   changed". In the browser that was An Tairseach (3 → 2), not the stop the
   operator moved, which was off-screen, contradicting the check's
   "scrolled to it". It now lands on the first stop whose Position box was
   changed, falling back to the first renumbered stop. The `ChilledRunTest`
   assertion was updated to match (a test I wrote this cycle).
8. **Customer delete keeps its filter through the form's action URL.** The
   Delete form in `customers/index.blade.php` (not a plan-named file) now
   carries `?search=…&is_active=…`, since "pass them through from the
   request" needs the index to send them.
9. **Step 9 browser: Dispatch run not pressed** (see Browser checks). The
   loaded flash and the invoiced Edit line were checked.
10. **Step 10, consolidated "This week":** only the link was added;
    `this_week` already existed in `resolvePeriod()`, so the controller is
    unchanged.
11. **The "€X in credit" rule is applied to both balance figures on the
    customer page** — "Outstanding" (`outstanding_balance`, as the plan
    names) and "Net owed" (`$aged['total']`, the one at the plan's cited
    line 157).
12. **The pending-status items table's quantity box** also got the
    "Ordered:" label, for consistency with F-21.

## Verification
1. `php artisan test` → **598 passed (3828 assertions), 0 failed** (557 → 598:
   +41 new cases).
2. `./vendor/bin/pint --test app tests database routes` → `PASS … 264 files`.
3. `npm run build` → `✓ built`.
4. `git status --short`: only files the plan names, plus the non-plan files
   explained in Deviations 2, 3 and 8 (`BatchController.php`,
   `stock/partials/`, `customers/index.blade.php`). The cycle-3 archive was
   already committed, so nothing is staged. `grep -rn 'name="positions\[\]"'
   resources/views` returns nothing.
5. Browser checks for steps 1–9 and 11 with real input; console clean
   (above).
6. Dev-DB probe. Before the browser checks: orders 19, payments 4, invoices
   7. After: **orders 21** (+2, both tagged "E2E-20261004 cycle4" and both
   `cancelled`: id 21 ORD-20261004-010 and id 22 ORD-20261004-011),
   **payments 4, invoices 7** (unchanged). The test customer was created and
   deleted. Run 1 positions are 1…17 with Deli back at 2. **One stock
   movement:** the step 4 check released 1 G010426 wheel into Mature (batch
   item 46: held 1, remaining 7, plus a new `CheeseConversionLog`). The plan's
   step 4 check asked for it; it is reversible from the mature batch's page
   ("Return to farmhouse hold").

## Files changed
```
 M CLAUDE.md
 M app/Http/Controllers/BatchController.php            (Deviation 2)
 M app/Http/Controllers/ChilledRunController.php
 M app/Http/Controllers/CustomerController.php
 M app/Http/Controllers/CustomerSpecialPriceController.php
 M app/Http/Controllers/DeliveryRunController.php
 M app/Http/Controllers/Invoicing/StatementController.php
 M app/Http/Controllers/OrderAllocationController.php
 M app/Http/Controllers/OrderController.php
 M app/Http/Controllers/PickingController.php
 M app/Services/StockOverviewService.php
 M docs/planImp/WORK_IN_PROGRESS.md
 M resources/css/app.css
 M resources/views/batches/create.blade.php
 M resources/views/batches/show.blade.php
 M resources/views/cheese-conversion/index.blade.php
 M resources/views/chilled-runs/partials/_run-table.blade.php
 M resources/views/customers/index.blade.php           (Deviation 8)
 M resources/views/customers/show.blade.php
 M resources/views/dashboard.blade.php
 M resources/views/delivery-runs/index.blade.php
 M resources/views/invoicing/consolidated/create.blade.php
 M resources/views/invoicing/invoices/show.blade.php
 M resources/views/invoicing/payments/create.blade.php
 M resources/views/orders/create.blade.php
 M resources/views/orders/partials/allocation-items.blade.php
 M resources/views/orders/show.blade.php
 M resources/views/picking/index.blade.php
 M resources/views/picking/item.blade.php
 M resources/views/stock/overview.blade.php
 M routes/web.php
 M tests/Feature/{BatchCreate,CheeseConversion,ChilledRun,ConsolidatedInvoice,
   CustomerSpecialPrice,DeliveryCharge,DispatchRun,FulfilmentUnitWeights,
   InvoiceVoid,OrderAddItem,OrderAllocationConcurrency,OrderFulfillmentWeight,
   OrderItemEdit,OrderShortSupply,OrderStoreOptionalFields,OrderTransitions,
   PaymentAllocation,Picking,Statement,StockOverview}Test.php
?? docs/planImp/implemented.md
?? resources/views/errors/{layout,404,403}.blade.php
?? resources/views/stock/partials/_expired-note.blade.php (Deviation 3)
?? tests/Feature/DashboardTest.php
?? tests/Feature/ErrorPagesTest.php
```
No pre-existing dirty files. Nothing committed.

## Notes for Planner
- **Flash out of view after a fragment redirect** (as the plan's Risks said).
  It shows on every line, stop and special-price landing. For cycle 5:
  anchor the flash near the fragment, or render a small inline "✓ saved" on
  the target element.
- **Chilled run sheet rows have no `scroll-margin`.** `#stop-{id}` lands with
  the row flush at the top and the column headers scrolled off. The order
  page, run panels and stop rows got `scroll-mt-20`; the sheet's `<tr>`s
  didn't, because `_run-table` styling wasn't in scope. A one-line follow-up.
- **Unmatched-URL 404s show the guest link.** No session is loaded before
  route matching, so a signed-in user on `/no-such-page` sees "Sign in". The
  login route's `guest` middleware then sends them to the dashboard, so it
  still leads home. Fixing it would mean a fallback route inside the `web`
  group.
- **The mature release in step 4 moved stock in dev** (1 G010426 wheel →
  Mature). That is what the check asked for; reverse it from the mature
  batch's page if the dev data matters.
- **Picking footer height:** on the fixed-qty screen it is one row (85 px),
  not two. A per-piece weight screen uses the same footer. The added 24 px
  margin is harmless headroom.
- **Native `<select>` + keyboard in the browser harness:** on `orders/show`,
  clicking the Add item select opened a popup that didn't commit Home/Down/
  Return. Focusing it by Shift+Tab and pressing Down on the closed select
  worked. This is a harness quirk, not an app bug; worth recording for the
  browser-testing session.
- **`allocate()` refusal precedence:** "already fully allocated" is checked
  before batch availability. A request for more than the line still needs
  (but not fully allocated) where the batch has enough falls to the old
  generic text. A third message ("Only N more needed on this line") would
  close that.
- **`reorder()` rejects an unknown id with a flash, not a validation error**
  (nothing changes). Mixing `positions` and `move` is a validation error
  (`prohibits`).
