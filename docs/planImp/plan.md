# The policy batch: eight rules the app had no opinion on

Status: READY
Revision: 1
Planner: Fable 5.1
Date: 2026-10-05

## Goal

Build Jon's decisions on `OPEN_QUESTIONS.md` #5 (all eight items). After this
cycle: a flagged customer pays the run's delivery charge **once per drop**,
not once per order; confirming an order that takes a customer over their
credit limit **warns** (and the order page shows it); Mature is **made by
conversion only**; a batch quantity far above the usual needs an explicit
"yes, really"; milk yield is **computed** and bottling more than the raw milk
entered is refused; order notes stay **internal** (off the dispatch
confirmation and docket); **Mark dispatched** requires the loaded tick like
the run button does; the To-Invoice queue only lets the office **issue
invoices for dispatched or delivered orders**; and choosing weekly/monthly
invoicing defaults a customer's **payment terms to Net 14**.

## Context

HEAD `b7a2d59` + the staged archive of cycle 7. Suite **647 passed, 0 failed**;
any failure is real. `pint --test app tests database routes config` clean.
Nothing pushed or deployed.

**Facts the steps rely on**

- **Delivery charge** (`CLAUDE.md` → Delivery Charge): `Customer::currentDeliveryCharge()`
  (`app/Models/Customer.php:96`) returns the run's gross charge iff
  `apply_delivery_charge` and the customer is on a run (0 when a percentage
  is set — `deliveryChargePercent()` takes precedence). Snapshotted at **four**
  creation sites: `OrderController::store()` (`:327`), `ChilledRunController::saveStop()`
  (`:499`), `ImportOnlineOrders::processOrder()` (`:211`),
  `OnlineOrdersController::import()` (`:192`); also fed to the order form's
  preview map (`OrderController::create()` `:225`). VAT is backed out in
  `Order::calculateTotals()`. E2E F-24: two same-day orders both carried €6.15.
- **Credit limit**: `Customer::canPlaceOrder(float)` (`:201`) =
  `outstanding_balance + amount <= credit_limit`, **called nowhere**;
  `outstanding_balance` = open invoice balances − unallocated credit; the
  customer form requires `credit_limit` (default €0). Confirm happens in
  `OrderController::update()` (`$isConfirming`, `:409`) and
  `ChilledRunController::confirmAll()`.
- **Mature**: `ProductSeeder` creates "Mossfield Mature Cheese";
  `CheeseConversionController::matureProduct()` (`:227`) finds it **by name**
  (`MATURE_PRODUCT_NAME`); `BatchController::create()` passes every active
  product to the form (`batches/create.blade.php:22`); `products` has no
  flag for it. `ProductRequest` validates the product form.
- **Quantities**: `BatchController::store()` validates
  `batch_items.*.quantity_produced` as `integer|min:1`, nothing above
  (E2E F-09: 3 000 wheels accepted). `config/mossfield.php` exists (one key,
  `mature_conversion_months`).
- **Milk yield**: `Batch::getProductionYieldAttribute()` returns a
  hard-coded `1.0` for milk; cheese/yoghurt use
  `finished_product_weight / raw_milk_litres`. Milk variants' `weight_kg` is
  their volume in litres (the batch form labels it "Unit volume" and prints
  "L"); `BatchItem.unit_weight_kg` is copied from it at creation.
  `BatchFinishedWeightTest:363` asserts `1.0` for milk and `:378` the page's
  "Yield ~5.0%" for cheese. The store form helper says raw milk "equals the
  finished volume" for milk.
- **Notes on documents**: `orders/dispatch-confirmation.blade.php:129–133`
  and `orders/docket.blade.php:171+` print a NOTES block from `order.notes`.
  The run sheet's Notes column and the order page are internal and stay.
- **Dispatch**: `DispatchService::dispatch()` (`app/Services/DispatchService.php:47`)
  refuses unless `status === 'ready'`; the **run** button
  (`ChilledRunController::dispatchAll()`) additionally requires `loaded_at`
  and names skipped orders; the **per-order** `OrderController::markDispatched()`
  (`:~380`) has no loaded check. `loaded_at` is set only by
  `ChilledRunController::toggleLoaded()` (ability `load`, factory may), which
  redirects to the sheet. The order page (`orders/show.blade.php:151`) shows
  Mark dispatched when `ready`. Tests that dispatch through the route
  without `loaded_at`: `DispatchConfirmationTest` (18 posts),
  `OrderTransitionsTest` (8); `DispatchRunTest` sets `loaded_at`.
- **To Invoice**: `UninvoicedOrderController` base query `whereIn('status',
  ['ready','dispatched','delivered'])` (`:33`); `issue()`/`issueAll()` call
  `InvoiceService::issueForOrder()`, whose guard is `hasReachedReady()`
  (`InvoiceService::issueForOrder()`); the view offers **Issue invoice** per
  row and **Issue all N**. `InvoiceController::headlineStats()` feeds the
  dashboard's "Awaiting invoice" tile. `DispatchService` calls
  `issueForOrder()` **after** flipping the status to `dispatched`.
- **Customer form** (`customers/create.blade.php:62–110`, same in `edit`):
  Alpine `x-data="{ terms, mode }"` seeded from `x-ref`'d selects; the
  cycle-5 hint shows when `terms === 'immediate'` and mode is weekly/monthly.
- Tests to extend: `DeliveryChargeTest`, `OrderTransitionsTest`,
  `ChilledRunTest`, `BatchCreateTest`, `BatchFinishedWeightTest`,
  `MatureProductSeedTest`, `CheeseConversionTest`, `DispatchConfirmationTest`,
  `OrderDocketTest`, `DispatchRunTest`, `InvoiceIssueTest`, `DashboardTest`,
  `CustomerSpecialPriceTest` (customer form), `AuthorizationBoundaryTest`
  (no new routes expected unless step 7 adds one). New:
  `CreditLimitWarningTest`.

## Constraints

- Snapshot semantics stay: a decision made at order creation is stored on
  the order; later edits are manual (`CLAUDE.md` → Delivery Charge).
- The credit limit **warns, never blocks** (Jon). A `credit_limit` of 0 means
  "no limit" (today every customer has 0 by default — a block would have
  stopped everything).
- Stock arithmetic, the free-stock sites and the write-off model are
  untouched.
- Every flash text that isn't changed by a step stays.
- `npm run build` after JS/CSS; `./vendor/bin/pint` on PHP.
- **Do not commit.** Planner commits on acceptance. Nothing pushed/deployed.

## Out of scope

- Charging delivery per *run* across several days, or re-charging when the
  charged order is cancelled (the office edits the charge on the surviving
  order — say so in the docs).
- Blocking on credit; a credit-hold status; changing `outstanding_balance`.
- Automatic batch `status` changes; the follow-ups under the cycle-6/7
  records.
- `FULFILMENT_PLAN.md` Phase 4.

## Steps

### 1. One delivery charge per drop (F-24)

Files: `app/Models/Customer.php`, `app/Http/Controllers/OrderController.php`,
`app/Http/Controllers/ChilledRunController.php`, `app/Console/Commands/ImportOnlineOrders.php`,
`app/Http/Controllers/OnlineOrdersController.php`, `resources/views/orders/create.blade.php`,
`tests/Feature/DeliveryChargeTest.php`

What:
- `Customer::currentDeliveryCharge(?CarbonInterface $deliveryDate = null,
  ?int $exceptOrderId = null): float` — unchanged when no date is given;
  with a date, returns **0** when the customer already has a non-cancelled
  order on that `delivery_date` with `delivery_charge > 0` (the drop is
  already charged). Add `Customer::deliveryChargeCarrierFor($date)` (or
  return the order) so the flash can name it.
- Pass the delivery date at all four creation sites (`store()` →
  `delivery_date` when set, else no dedupe; `saveStop()` → the run date;
  the two imports → the payload's delivery date when set). When the charge
  was waived, `store()`'s and `saveStop()`'s success flash appends
  `"Delivery charge already on ORD-… for that day."`
- The create form's preview: `customerCharges` can't know the date until
  typed — on delivery-date change, JS asks nothing new; instead pass
  `customerChargedDates` = `[customer_id => [dates already charged]]` for
  the next 14 days (one query) and show Delivery €0 with "(already charged
  on ORD-… that day)" when the chosen date matches.
- Docs line (step 10): cancelling the charged order leaves the other at €0 —
  the office edits the charge on the surviving order.
- Tests (`DeliveryChargeTest`): second same-day order via `store()` → €0,
  flash names the first; a different day → charged; the first order
  cancelled before the second is created → second charged; `saveStop()`
  same rule; a percentage customer's second order still computes its %;
  import path (whichever is easiest to drive) obeys it.

Check: `php artisan test --filter="DeliveryChargeTest|ChilledRunTest"` pass.
Browser: create a second order for E2E Corner Shop on a day it already has
one → Delivery €0 in the preview and on the saved order, flash names the
first order.

### 2. The credit limit warns (F-25)

Files: `app/Models/Customer.php`, `app/Http/Controllers/OrderController.php`,
`app/Http/Controllers/ChilledRunController.php`, `resources/views/orders/show.blade.php`,
`tests/Feature/CreditLimitWarningTest.php` (new)

What:
- `Customer::creditWarning(float $orderTotal): ?string` — `null` when
  `credit_limit <= 0` or within limit; else
  `"{name} is at €{outstanding} outstanding against a €{limit} credit limit —
  this order takes it to €{outstanding + total}."` Make `canPlaceOrder()` use
  the same arithmetic (or remove it if nothing else will call it — say which).
- `OrderController::update()` on `$isConfirming`, `OrderController::store()`,
  and `ChilledRunController::confirmAll()` (one line per customer over) add
  the warning as a **`warning` flash** alongside the success flash — never a
  refusal.
- `orders/show.blade.php`: inside `@can('see-financials')`, when the order is
  `pending|confirmed|preparing|ready` and `creditWarning($order->total_amount)`
  is non-null, a warn-tone banner with the same text.
- Tests (new `CreditLimitWarningTest`): limit 100, outstanding 80 (one open
  invoice), confirm a €30 order → warning flash text; €15 → none; limit 0 →
  none at any size; the order page banner for over/within; `confirmAll`
  names the customer once.

Check: `php artisan test --filter="CreditLimitWarningTest|OrderTransitionsTest"` pass.

### 3. Mature is made by conversion only (F-10)

Files: `database/migrations/2026_10_05_120000_add_conversion_only_to_products_table.php` (new),
`app/Models/Product.php`, `database/seeders/ProductSeeder.php`,
`app/Http/Requests/ProductRequest.php`, `resources/views/products/{create,edit}.blade.php`,
`app/Http/Controllers/BatchController.php`, `resources/views/batches/create.blade.php`,
`app/Http/Controllers/CheeseConversionController.php`,
`tests/Feature/BatchCreateTest.php`, `tests/Feature/MatureProductSeedTest.php`, `tests/Feature/CheeseConversionTest.php`

What:
- Migration: `products.conversion_only` boolean default false; **set true
  where `name = 'Mossfield Mature Cheese'`** in the same migration (so prod
  needs no seeder step). `Product::$fillable`/casts; `ProductSeeder` sets it.
- `ProductRequest`: `conversion_only` boolean (coerced like `is_active`);
  product create/edit forms get the checkbox "Made only by conversion — no
  production batches (e.g. Mature)".
- `BatchController::create()` excludes `conversion_only` products from the
  select; `store()` refuses one with the validation message
  `"{name} is only made by releasing matured wheels — use Mature Conversion."`
- `CheeseConversionController::matureProduct()` prefers
  `where('conversion_only', true)->where('type','cheese')->first()`, falling
  back to the name (keep the constant for the fallback).
- Tests: the batch form omits Mature; `store()` with Mature → 422/redirect
  with the message; `MatureProductSeedTest` asserts the flag; a conversion
  still finds the Mature product with the flag set and the name changed.

Check: `php artisan test --filter="BatchCreateTest|MatureProductSeedTest|CheeseConversionTest"` pass.

### 4. A sanity ceiling on batch quantities (F-09)

Files: `config/mossfield.php`, `app/Http/Controllers/BatchController.php`,
`resources/views/batches/create.blade.php`, `tests/Feature/BatchCreateTest.php`

What:
- `config('mossfield.batch_ceilings')` = `['milk' => 5000, 'yoghurt' => 3000,
  'cheese' => 200]` — **units per variant per batch** (dev's largest: milk
  240 + 96, yoghurt 120 + 60, cheese 38 wheels). Comment that Jon sets the
  numbers.
- `store()`: when any item exceeds its type's ceiling and `confirm_large`
  isn't posted, redirect back `withInput()` and a `warning` flash plus a
  per-item error: `"{N} × {variant} — the usual ceiling is {ceiling}. Tick
  'Yes, this batch really is that big' to save it."` The form renders a
  checkbox `confirm_large` (only shown when `old('confirm_large')` or that
  error exists). With the box ticked, the batch saves and the success flash
  notes "(large batch confirmed)". `update()` doesn't change quantities —
  untouched.
- Tests: 3 000 wheels → refused with the message and the box rendered;
  ticked → saved; 200 wheels → saved without asking; a milk batch of 4 999 →
  no prompt.

Check: `php artisan test --filter=BatchCreateTest` pass.

### 5. Milk yield is computed and can't exceed 100 % (F-06)

Files: `app/Models/Batch.php`, `app/Http/Controllers/BatchController.php`,
`resources/views/batches/create.blade.php`, `tests/Feature/BatchFinishedWeightTest.php`,
`tests/Feature/BatchCreateTest.php`

What:
- `Batch::getProductionYieldAttribute()`: drop the milk special case — every
  type is `finished_product_weight / raw_milk_litres` (for milk the unit
  weight is litres, so this is bottled ÷ raw).
- `store()` and `update()` (if it edits `raw_milk_litres`): for a **milk**
  product, `Σ(quantity × variant weight_kg)` must be `<= raw_milk_litres`,
  else refuse with `"Bottled {N} L is more than the {M} L of raw milk
  entered."` (an error on `raw_milk_litres`, input kept). The form's helper
  text for milk becomes "Raw milk that was bottled — must be at least the
  total bottled volume."
- Tests: `BatchFinishedWeightTest:363` → a milk batch of 500 L raw with 432 L
  bottled yields `0.864`; `BatchCreateTest`: 100 L raw with 120 × 1L refused,
  100 × 1L accepted.

Check: `php artisan test --filter="BatchFinishedWeightTest|BatchCreateTest"` pass.

### 6. Order notes stay internal (F-33)

Files: `resources/views/orders/dispatch-confirmation.blade.php`,
`resources/views/orders/docket.blade.php`, `resources/views/orders/create.blade.php`,
`resources/views/orders/edit.blade.php`, `tests/Feature/DispatchConfirmationTest.php`,
`tests/Feature/OrderDocketTest.php`

What:
- Remove the NOTES blocks from both documents (and the docket's `.notes`
  CSS). The order form's Notes label gets a hint "Internal — not printed on
  customer documents."
- Tests: an order with notes "SECRET INTERNAL" → the confirmation (HTML and
  PDF text) and the docket don't contain it; the order page and the run
  sheet still do.

Check: `php artisan test --filter="DispatchConfirmationTest|OrderDocketTest|ChilledRunTest"` pass.

### 7. Mark dispatched requires the loaded tick (F-34)

Files: `app/Services/DispatchService.php`, `app/Http/Controllers/ChilledRunController.php`,
`resources/views/orders/show.blade.php`, `tests/Feature/OrderTransitionsTest.php`,
`tests/Feature/DispatchConfirmationTest.php`, `tests/Feature/DispatchRunTest.php`

What:
- `DispatchService::dispatch()`: after the `ready` check, refuse when
  `loaded_at` is null — `DispatchException('Tick the order as loaded onto the
  van first.')`. Both buttons now share the rule at the service.
- `ChilledRunController::toggleLoaded()` accepts an optional `return=order`
  input and then redirects to `orders.show` (fragment `dispatch`) instead of
  the sheet. No new route.
- `orders/show.blade.php`: for `ready` orders, a **"Loaded onto van"** tick
  (`@can('load', $order)` — factory can tick here too) posting
  `chilled-runs.toggle-loaded` with `return=order`; **Mark dispatched** is
  rendered only when `loaded_at` is set, otherwise a muted "Tick loaded onto
  van first". Show the loaded timestamp when set.
- Tests: `OrderTransitionsTest` — dispatch on a ready, **unloaded** order is
  refused with the message; the existing dispatch cases set `loaded_at` in
  their fixture (one helper change); the page shows the tick and hides Mark
  dispatched until loaded; factory can POST the toggle with `return=order`
  and lands on the order page. `DispatchConfirmationTest`: give its ready
  fixture `loaded_at` (one place) — **don't** weaken any assertion.
  `DispatchRunTest` is already loaded-aware.

Check: `php artisan test --filter="OrderTransitionsTest|DispatchConfirmationTest|DispatchRunTest|PickingTest"`
pass. Browser: a ready order's page → tick Loaded → Mark dispatched appears →
dispatch works; an unloaded one has no Mark dispatched.

### 8. Issue only for dispatched or delivered (F-38a)

Files: `app/Services/InvoiceService.php`, `app/Http/Controllers/Invoicing/UninvoicedOrderController.php`,
`app/Http/Controllers/Invoicing/InvoiceController.php` (`headlineStats()`),
`resources/views/invoicing/uninvoiced/index.blade.php`, `tests/Feature/InvoiceIssueTest.php`,
`tests/Feature/DashboardTest.php` (or wherever the tile is tested)

What:
- `InvoiceService::issueForOrder()`: the guard becomes `status ∈ {dispatched,
  delivered}` with the message `"An invoice is issued when the order is
  dispatched — this one hasn't left yet."` (`DispatchService` flips to
  `dispatched` first, so the automatic path is unaffected).
- `UninvoicedOrderController`: keep `ready` orders in the list but in their
  own section **"Picked, awaiting dispatch"** with no Issue button and no
  place in "Issue all"; `issue()`/`issueAll()` only consider
  dispatched/delivered. `headlineStats()['uninvoiced']` counts
  dispatched/delivered only (the tile is "act now" money).
- Tests: `issueForOrder()` on a `ready` order throws with the new message; a
  dispatched one issues; the queue page shows a ready order under the new
  heading with no Issue button and the count excludes it; `issueAll` skips
  it.

Check: `php artisan test --filter="InvoiceIssueTest|DashboardTest|InvoicingAccessTest|ConsolidatedInvoiceTest|DispatchRunTest"` pass.

### 9. Consolidated customers default to Net 14 (F-38b)

Files: `resources/views/customers/create.blade.php`, `resources/views/customers/edit.blade.php`,
`tests/Feature/CustomerSpecialPriceTest.php` (the customer-form tests live there)

What: in the Alpine block, on `invoicing_mode` change to `weekly|monthly`
while `terms === 'immediate'`, set the terms select (and `terms`) to
`net_14`; the cycle-5 hint still shows if the office sets Immediate back.
No server rule. Test: the form carries the handler (assert the `net_14`
default expression is present) — the behaviour is a browser check.

Check: browser — pick Weekly on a new customer → terms flip to Net 14; set
Immediate again → the hint shows.

### 10. Docs

Files: `CLAUDE.md`, `docs/planImp/WORK_IN_PROGRESS.md`

What: `CLAUDE.md` → Delivery Charge (once per drop, date-aware
`currentDeliveryCharge()`, the cancel caveat); Customer Structure (credit
limit warns, 0 = no limit, `creditWarning()`); Products (`conversion_only`);
Batch create (ceilings config + `confirm_large`; milk bottled ≤ raw; yield
computed for every type); Dispatch (loaded required at the service; the
order-page tick; `return=order`); Invoicing (issue only
dispatched/delivered; the queue's "awaiting dispatch" section); Customer
form (terms default). `WORK_IN_PROGRESS.md`: *Recently Completed: Policy
batch (2026-10-05)*.

Check: `grep -n "once per drop\|creditWarning\|conversion_only\|batch_ceilings\|loaded onto the van first\|hasn't left yet" CLAUDE.md` → six hits.

## Verification

1. `php artisan test` → **0 failed**; record the count.
2. `./vendor/bin/pint --test app tests database routes config` → clean.
3. `npm run build` → ok.
4. `php artisan migrate:status | tail -2` shows the products migration ran;
   `php artisan tinker --execute='echo App\Models\Product::where("conversion_only",1)->value("name");'`
   → Mossfield Mature Cheese.
5. `git status --short` → only files this plan names (+ the staged archive).
6. Browser checks from steps 1, 2, 4, 7, 9 with real input; console clean.
7. Dev-DB probe: tag any probe orders `E2E-20261005 cycle8` and cancel them;
   nothing else moves. Dispatching in the browser check (step 7) issues a
   real invoice — use an E2E customer's order and say which.

## Risks

- **Step 7 touches 26 existing dispatch tests.** Fix the fixture, not the
  assertions; if a test genuinely dispatches without a loaded tick as its
  *subject*, it becomes the refusal case.
- **Step 8 vs the automatic path**: `DispatchService` must still issue — it
  calls `issueForOrder()` after the status flip inside its own flow; run
  `DispatchRunTest` and `DispatchConfirmationTest` to prove it.
- **Step 5 and existing milk fixtures**: any test fixture that bottles more
  than its raw milk now fails validation — fix the fixture (raw ≥ bottled),
  not the rule.
- **Step 1's dedupe reads other orders at creation time** — keep it inside
  the creating transaction; two simultaneous creations for the same drop
  could both charge, which is acceptable (the office can fix one).
- **Ceiling numbers** are guesses from dev data; Jon can change them in
  config, and the plan says so in the comment.

## Review

(Planner fills this in after reading implemented.md and the diff.)
