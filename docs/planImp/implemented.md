# The policy batch: eight rules the app had no opinion on — implementation

Status: DONE
Plan revision: 1
Implementer: Opus
Date: 2026-10-05

## Baseline
HEAD: 9a77e3c (the plan's Context says `b7a2d59` + a staged archive; that archive has since been committed as `9a77e3c`)
Pre-existing dirty files: clean

## Steps

### 1. One delivery charge per drop — done
Changed:

`app/Models/Customer.php`
- `currentDeliveryCharge(?CarbonInterface $deliveryDate = null, ?int
  $exceptOrderId = null)`: unchanged without a date. With a date it returns
  0 when `deliveryChargeCarrierFor($date)` finds an order.
- New `deliveryChargeCarrierFor($date, $except)`: the first non-cancelled
  order on that `delivery_date` with `delivery_charge > 0`.

`app/Http/Controllers/OrderController.php`
- `store()` passes the parsed `delivery_date` (no date → no dedupe), inside
  the creating transaction. A waived charge appends " Delivery charge
  already on ORD-… for that day." to the flash.
- `create()` adds `customerChargedDates` (one query: non-cancelled charged
  orders in the next 14 days → `[customer => [Y-m-d => order_number]]`).

`app/Http/Controllers/ChilledRunController.php`
- `saveStop()` passes the run date and has the same flash. See Deviations:
  the sheet only creates an order when the stop has none for the date, so a
  carrier can't exist there — the guard is defensive.

`app/Console/Commands/ImportOnlineOrders.php` +
`app/Http/Controllers/OnlineOrdersController.php`
- Pass `null`, with a comment: imports always create orders with
  `delivery_date = null` and the payload has no delivery date, so there is
  no drop to share. See Deviations.

`resources/views/orders/create.blade.php`
- An `#orderDeliveryNote` beside "Delivery (incl. VAT)", and a
  `customerChargedDates` map.
- `updateOrderTotal()` shows €0 and "(already charged on ORD-… that day)"
  when the chosen date is charged. A non-percentage charge only — a
  percentage customer is charged on every order.
- The delivery-date `change` event re-runs the preview.

`tests/Feature/DeliveryChargeTest.php` +7:
- the same-day second order is €0, its total is lines only, and the flash
  names the first;
- a different day is charged twice;
- an undated order is charged as before;
- a first order cancelled before the second → the second is charged;
- the sheet path: editing the existing order keeps one charge; with it
  cancelled, the sheet's new order is charged;
- a percentage customer gets its % on every order;
- the create view carries the charged-dates map.
- (Import: the date is always null, so it behaves exactly as before; the
  existing import tests still pass.)
Check output:
```
$ php artisan test --filter="DeliveryChargeTest|ChilledRunTest"
  Tests:    97 passed (389 assertions)
```
Red check (HEAD `Customer.php` + `OrderController.php`): the same-drop test
fails; restored, it passes.
Browser: see *Browser checks*.

### 2. The credit limit warns — done
Changed:

`app/Models/Customer.php`
- New `creditWarning(float $orderTotal): ?string`. It returns null when
  `credit_limit <= 0` (0 = no limit) or when `outstanding + total ≤ limit`.
  Otherwise: "{name} is at €X outstanding against a €L credit limit — this
  order takes it to €Y.".
- `canPlaceOrder()` is **kept**, rewritten on the same arithmetic (`is_active
  && creditWarning() === null`). It's still documented in `CLAUDE.md`'s
  helper list and nothing in the app calls it.

`app/Http/Controllers/OrderController.php`
- `store()` (always) and `update()` (when confirming; reads the fresh total)
  add a `warning` flash alongside the success flash. No refusal anywhere.

`app/Http/Controllers/ChilledRunController.php`
- `confirmAll()` groups the confirmed orders by customer and sums their
  totals; one warning line per customer who goes over, joined into one
  `warning` flash.

`resources/views/orders/show.blade.php`
- Inside `@can('see-financials')`, for `pending|confirmed|preparing|ready`: a
  warn banner `data-credit-warning` "**Over the credit limit.** {sentence}".

**new** `tests/Feature/CreditLimitWarningTest.php` (6, on the invoicing
fixture; an €80 open invoice against a €100 limit):
- confirming a €30 order → the exact warning, and it's confirmed;
- €15 → no warning;
- limit 0 → none at any size (`canPlaceOrder` true);
- `store()` warns;
- the page shows the banner over the limit, not within, and never to
  factory;
- `confirmAll` with two orders (€24) names the customer **once** ("…takes it
  to €104.00").
Check output:
```
$ php artisan test --filter="CreditLimitWarningTest|OrderTransitionsTest"
  Tests:    26 passed (98 assertions)
```
Browser: see *Browser checks*.

### 3. Mature is made by conversion only — done
Changed:

**new** `database/migrations/2026_10_05_120000_add_conversion_only_to_products_table.php`
- `products.conversion_only` (bool, default false, after `is_active`).
- The same migration sets it true where `name = 'Mossfield Mature Cheese'`.

Model, seeder, request, forms
- `app/Models/Product.php`: fillable and cast.
- `database/seeders/ProductSeeder.php`: Mature gets `'conversion_only' =>
  true`.
- `app/Http/Requests/ProductRequest.php`: `boolean` rule, coerced like
  `is_active`.
- `resources/views/products/{create,edit}.blade.php`: a checkbox "Made only
  by conversion — no production batches (e.g. Mature)".

`app/Http/Controllers/BatchController.php`
- `create()` excludes `conversion_only` products.
- `store()`: a closure rule on `product_id`, "{name} is only made by
  releasing matured wheels — use Mature Conversion.".

`app/Http/Controllers/CheeseConversionController.php`
- `matureProduct()` prefers `conversion_only` + cheese and falls back to the
  name constant.
- `guardSourceWheel()` compares product **id** to `matureProduct()` rather
  than the name, so a renamed Mature is still refused as a source. See
  Deviations.

Tests
- `BatchCreateTest`: the form omits Mature and lists Farmhouse; `store()`
  is refused with the exact message.
- `MatureProductSeedTest`: Mature has the flag, Farmhouse doesn't.
- `CheeseConversionTest` +1: with Mature renamed "Mossfield Aged Cheese"
  and flagged, a release still lands on it.
Check output:
```
$ php artisan migrate
  2026_10_05_120000_add_conversion_only_to_products_table ....... 60.86ms DONE
$ php artisan tinker --execute='echo App\Models\Product::where("conversion_only",1)->value("name");'
Mossfield Mature Cheese
$ php artisan test --filter="BatchCreateTest|BatchFinishedWeightTest|MatureProductSeedTest|CheeseConversionTest"
  Tests:    53 passed (308 assertions)    (steps 3–5 together)
```

### 4. A sanity ceiling on batch quantities — done
Changed:

`config/mossfield.php`
- `batch_ceilings` = milk 5000, yoghurt 3000, cheese 200 — units per
  variant per batch. The comment says the starting numbers are guesses from
  dev data and Jon sets them.

`app/Http/Controllers/BatchController.php::store()`
- After validation, any item over its type's ceiling without
  `confirm_large` redirects back `withInput()` with:
  - a `warning` flash ("That batch is much bigger than usual — check the
    quantities, or confirm it.");
  - a per-item error "{N} × {variant} — the usual ceiling is {C}. Tick 'Yes,
    this batch really is that big' to save it.".
- With the box ticked it saves and the success flash ends "(large batch
  confirmed)". `update()` doesn't touch quantities.

`resources/views/batches/create.blade.php`
- A warn box `data-confirm-large` with the `confirm_large` checkbox, rendered
  only when a ceiling error is in the bag or `old('confirm_large')`.

`tests/Feature/BatchCreateTest.php` +2:
- 3 000 wheels is refused with the exact message and warning, nothing is
  saved, and the re-rendered form shows the box; ticked, it saves with the
  note.
- 200 wheels and 4 999 × 1L save without a prompt.
Check: as step 3 (53 passed).

### 5. Milk yield is computed and can't exceed 100 % — done
Changed:

`app/Models/Batch.php`
- `getProductionYieldAttribute()`: the milk `1.0` special case is gone. Every
  type is `finished_product_weight / raw_milk_litres` (for milk, bottled ÷
  raw).

`app/Http/Controllers/BatchController.php::store()`
- For milk, Σ(quantity × variant `weight_kg`) > raw is refused on
  `raw_milk_litres`, input kept: "Bottled {N} L is more than the {M} L of
  raw milk entered.". Litres print without trailing zeros.
- `update()` doesn't edit raw milk, so it's untouched.

`resources/views/batches/create.blade.php`
- The milk helper text reads "Raw milk that was bottled — must be at least
  the total bottled volume.".

Tests
- `BatchFinishedWeightTest`: the milk case (renamed
  `…_its_yield_is_bottled_over_raw`) is now 500 L raw / 432 bottled →
  finished 432, yield **0.864**.
- `BatchCreateTest` +1: 120 × 1L on 100 L is refused with the exact message;
  100 × 1L is accepted.
- No existing fixture bottled more than its raw milk. Full suite at this
  point: **665 passed, 0 failed**.
Check: as step 3.

### 6. Order notes stay internal — done
Changed:
- `resources/views/orders/dispatch-confirmation.blade.php` and `docket.blade.php`:
  the NOTES blocks are removed, with a comment saying why. The docket's
  own `.notes` CSS is removed. The shared `documents/_pdf-styles` `.notes`
  rule stays — `invoicing/invoices/pdf.blade.php` still uses that class.
- `resources/views/orders/create.blade.php` and `edit.blade.php`: a hint
  under Notes, "Internal — not printed on customer documents.".

Tests
- `DispatchConfirmationTest` +1: with notes "SECRET INTERNAL …", the HTML
  confirmation doesn't contain them. Nor does the **PDF**: its text is
  extracted with `/usr/bin/pdftotext`, and the test checks it read "Dispatch
  confirmation" first (it skips with a message if pdftotext is missing).
- `OrderDocketTest` +1: the docket doesn't show them; the order page does.
- `ChilledRunTest` +1: the run sheet still shows them.
Check output:
```
$ php artisan test --filter="DispatchConfirmationTest|OrderDocketTest|ChilledRunTest"
  Tests:    89 passed (368 assertions)
```

### 7. Mark dispatched requires the loaded tick — done
Changed:

`app/Services/DispatchService.php`
- After the `ready` check, under the same lock: `loaded_at` null → throws
  `DispatchException('Tick the order as loaded onto the van first.')`.
- Both buttons share the rule at the service; `markDispatched()` already
  flashes a `DispatchException` as an error.

`app/Http/Controllers/ChilledRunController.php`
- `toggleLoaded()`: with `return=order`, it redirects to
  `orders.show#dispatch` with the same flash. No new route.

`resources/views/orders/show.blade.php`
- For a `ready` order, `@can('load')` — outside `@can('update')`, so factory
  gets it — a **"Loaded onto van"** tick button (`data-loaded-tick`,
  `id="dispatch"`). It posts `chilled-runs.toggle-loaded` with
  `return=order` and shows the loaded time (`d/m H:i`) once set.
- Inside the office block: Mark dispatched only when `loaded_at` is set;
  otherwise a muted "Tick loaded onto van first".

Tests
- Fixtures, assertions untouched: `DispatchConfirmationTest::readyOrder()`
  sets `loaded_at` (one place). `OrderTransitionsTest::makeOrder()` sets it
  for `ready` (one helper). The 11 failures this caused all came from these
  two fixtures.
- `OrderTransitionsTest` +3:
  - an unloaded ready order is refused with the exact message, staying
    ready;
  - the page shows the tick and "Tick loaded onto van first" with no
    dispatch form; the toggle with `return=order` lands on `#dispatch`; then
    Mark dispatched and the timestamp show;
  - factory sees the tick, can toggle it (back to `#dispatch`), and still
    gets 403 dispatching.
- `DispatchRunTest` was already loaded-aware and is unchanged.
Check output:
```
$ php artisan test --filter="OrderTransitionsTest|DispatchConfirmationTest|DispatchRunTest|PickingTest"
  Tests:    76 passed (338 assertions)
```
Browser: see *Browser checks*.

### 8. Issue only for dispatched or delivered — done
Changed:

`app/Services/InvoiceService.php::issueForOrder()`
- The `hasReachedReady()` guard is now `status ∈ {dispatched, delivered}`,
  with the message "An invoice is issued when the order is dispatched — this
  one hasn't left yet.".
- `DispatchService` flips the status before issuing, so the automatic path
  is unchanged.

`app/Http/Controllers/Invoicing/UninvoicedOrderController.php`
- `uninvoicedQuery()` is now dispatched/delivered only. It feeds `index()`'s
  two billable sections, `issueAll()` and
  `InvoiceController::headlineStats()['uninvoiced']` (the dashboard and
  Finance tile), so all three exclude ready orders.
- New `awaitingDispatchQuery()` (ready, uninvoiced), passed as
  `$awaitingDispatch`.

`resources/views/invoicing/uninvoiced/index.blade.php`
- A third panel, **"Picked, awaiting dispatch"** (`data-awaiting-dispatch`):
  order, customer, delivery, loaded yes/not-yet, total — and no Issue button.
  Note: "Invoiced automatically when dispatched.".

Tests
- `InvoiceIssueTest` +2:
  - a ready order throws the exact message and stays uninvoiced; once
    dispatched it issues;
  - the queue: the headline count is 1 (excludes ready), the ready order is
    listed under the new heading with no Issue form, "Issue all 1",
    `issueAll` → "1 invoice issued." and leaves the ready order alone, and
    the per-order issue route flashes the message.
- Fixtures: three existing tests needed an *invoiced ready* order, which is
  no longer reachable through `issue()` but exists in pre-change data. Their
  assertions are unchanged.
  - `InvoiceIssueTest` refuses-line-edits / refuses-cancellation: a helper
    `invoicedReadyOrder()` issues while dispatched, then sets the status
    back to ready.
  - `DispatchConfirmationTest` already-invoiced: the same inline.
  See Deviations.
- `DashboardTest` is unchanged: the tile reads `headlineStats()`, whose
  count is asserted in the queue test.
Check output:
```
$ php artisan test --filter="InvoiceIssueTest|DashboardTest|InvoicingAccessTest|ConsolidatedInvoiceTest|DispatchRunTest|DispatchConfirmationTest"
  Tests:    72 passed (323 assertions)
```

### 9. Consolidated customers default to Net 14 — done
Changed:
- `resources/views/customers/{create,edit}.blade.php`: the `invoicing_mode`
  select's Alpine `x-on:change` also runs `if (mode !== 'per_order' && terms
  === 'immediate') { terms = 'net_14'; $refs.terms.value = 'net_14'; }`.
  The cycle-5 hint (which keys off `terms`/`mode`) still shows if the office
  sets Immediate back. There's no server rule.
- `tests/Feature/CustomerSpecialPriceTest.php` +1: both forms carry the
  handler.
Check output:
```
$ php artisan test --filter=CustomerSpecialPriceTest
  Tests:    13 passed (52 assertions)
```
Browser: see *Browser checks*.

### 10. Docs — done
Changed:

`CLAUDE.md`
- Delivery Charge: a once-per-drop bullet (the date-aware
  `currentDeliveryCharge()`, imports undated, percentage unaffected, the
  cancel caveat).
- Customer Structure: `creditWarning()` and "warns, never blocks".
- Products: `conversion_only`, plus a new *Batch create rules* paragraph
  (ceilings / `confirm_large`, milk ≤ raw, yield computed for every type).
- Dispatch Confirmation: notes are internal.
- Dispatch from the sheet: loaded is required at the service, the
  order-page tick, `return=order`.
- Invoicing: issue only dispatched/delivered, "Picked, awaiting dispatch".
- Consolidated invoicing: the Net 14 default.

`docs/planImp/WORK_IN_PROGRESS.md`
- *Recently Completed: Policy batch (2026-10-05)*, Last Updated bumped.
Check output (one count per term — six hits):
```
once per drop                1
creditWarning                2
conversion_only              1
batch_ceilings               1
loaded onto the van first    1
hasn't left yet              1
```

## Browser checks
Run at `http://mossfield.local` as admin, with real input; the console was
clean (no errors) on `orders/create`, `orders/26`, `batches/create` and
`customers/create`.

**Step 1 — once per drop.**
- `/orders/create` with E2E Corner Shop and delivery 06/10/2026 (already
  charged on ORD-20261004-001): the total panel note read "(already charged
  on ORD-20261004-001 that day)" and the delivery line went to €0. Changing
  the date to 13/10 cleared the note; back to 06/10 restored it.
- Submitted (2 × 1L, notes "E2E-20261005 cycle8 step1 same-drop probe"):
  flash "Order ORD-20261005-001 created successfully! Delivery charge
  already on ORD-20261004-001 for that day." The order (id 26) is stored
  with `delivery_charge = 0.00`, total €2.50.

**Step 2 — credit warning.**
- Corner Shop's limit was set to €2 temporarily (tinker) and restored to
  €2000 afterwards.
- `/orders/26` showed the banner "**Over the credit limit.** E2E Corner Shop
  is at €0.00 outstanding against a €2.00 credit limit — this order takes it
  to €2.50."
- Confirm order → both flashes: "Order confirmed." and the same sentence.
  The order was confirmed, not blocked.

**Step 7 — loaded tick.**
- Order 26 was picked to `ready` through the model (`pickFromBatchItem`, 2
  from batch item 42). The page showed the unticked "Loaded onto van" button,
  "Tick loaded onto van first", and no Mark dispatched.
- Clicking the tick landed on `/orders/26#dispatch`: "E2E Corner Shop ·
  ORD-20261005-001 marked loaded.", a ticked box with "05/10 16:31", and
  Mark dispatched now present.
- A stray click after a viewport resize unticked it ("… unmarked — back on
  the to-load list.", same anchor), which exercised the reverse; re-ticked.
- Mark dispatched → Dispatch: "Order ORD-20261005-001 dispatched. Invoice
  **INV-000008** issued — send it from Finance. Confirmation emailed to
  corner@e2e.test." (`MAIL_MAILER=log`).

**Step 8 (extra, before dispatching).**
- `/invoicing/uninvoiced` showed "Picked, awaiting dispatch (3)", with
  ORD-20261005-001 Loaded "Yes" plus two old July ready orders from other
  customers. No Issue buttons in that panel; the header button read "Issue
  all 1".

**Step 3 (extra).**
- `/batches/create`'s product list has no Mature.

**Step 4 — ceiling.**
- Farmhouse, 3000 wheels, 30 000 L → refused:
  - warning flash "That batch is much bigger than usual — check the
    quantities, or confirm it.";
  - error "3,000 × Whole Wheel — the usual ceiling is 200. Tick 'Yes, this
    batch really is that big' to save it.";
  - the `data-confirm-large` box appeared and the 3000 was kept.
- **Not ticked**, to keep a 3 000-wheel batch off the dev DB. The ticked
  path is covered by `BatchCreateTest`.

**Step 9 — Net 14 default.**
- On `/customers/create`, choosing Weekly flipped terms from Immediate to
  `net_14`.
- Setting Immediate back showed the cycle-5 hint ("Consolidated invoices are
  due the day they're issued with Immediate terms — most weekly customers
  use Net 7 or Net 14.").
- Not saved.

**Step 6 (extra).**
- Rendering order 26's docket in tinker: the "cycle8" note text is absent.

## Dev-DB state after the probe
- **ORD-20261005-001** (id 26, E2E Corner Shop):
  - notes "E2E-20261005 cycle8 step1 same-drop probe";
  - dispatched;
  - on **INV-000008** (€2.50, not emailed — the dispatch never emails the
    invoice).
  - It **can't be cancelled** (dispatched and invoiced), as the plan
    foresaw. Void INV-000008 from Finance if it should go.
- Stock: batch item 42 (milk 1L, M041026) is 2 lower, picked and
  dispatched with the order.
- Corner Shop's `credit_limit` is back to 2000.00.
- Nothing else was written: no batch saved, no customer saved.
- Migration `2026_10_05_120000` has run on `mossfield_cl` (Mature
  `conversion_only = 1`).

## Deviations
1. **Step 1 — imports pass no date.** The plan assumed Mossorders imports
   carry a delivery date. Both import paths always set `delivery_date =
   null`, so they call `currentDeliveryCharge(null)`: charged as before,
   with a comment. A dated import would need the payload to carry the
   date.
2. **Step 1 — `saveStop()`'s dedupe is defensive.** The run sheet edits the
   stop's existing order for the date rather than creating a second one, so
   the "already charged" branch can't fire from the sheet today. It is kept
   (it's cheap and matches `store()`), and the tests cover both the edit and
   the create path.
3. **Step 3 — `guardSourceWheel()`** now compares the product id with
   `matureProduct()` instead of the name constant. Otherwise renaming Mature
   would let it be held/released into itself. Not named in the plan; a
   one-line consequence of the flag.
4. **Steps 4/5 — checks after `validate()`, not as rules.** They need the
   loaded variants and product, so they run after validation as explicit
   `back()->withInput()->withErrors()`.
   - The warning flash wording ("That batch is much bigger than usual —
     check the quantities, or confirm it.") is mine; the plan gave only the
     per-item error.
   - The ceiling is **per variant line**, as the plan's message ("{N} ×
     {variant}") implies.
5. **Step 8 — three fixtures build a legacy invoiced-ready order.**
   `InvoiceIssueTest` refuses-line-edits / refuses-cancellation and
   `DispatchConfirmationTest` already-invoiced need an invoiced order that
   is still `ready`. That state is unreachable through `issue()` now but
   exists in pre-change data, so the guards it tests still matter. They
   issue while dispatched, then set the status back to ready. All
   assertions are unchanged.
6. **Step 7 — one more fixture outside the plan's list.**
   `OrderShortSupplyTest::test_a_short_closed_order_can_be_dispatched` now
   sets `loaded_at` before dispatching; its subject is short supply, not the
   tick. The "26 existing dispatch tests" in the Risks came down to 12
   failures, all fixed in fixtures (two helpers plus this one line).
7. **Step 2 — `canPlaceOrder()` kept** (documented, unused), rewritten on
   `creditWarning()` so the two can't disagree.
8. **Step 6 — shared `.notes` CSS kept** in `documents/_pdf-styles`: the
   invoice PDF still uses the class. Only the docket's own copy went.

## Verification
1. `php artisan test` → **674 passed (4288 assertions), 0 failed** (63.8 s).
   Baseline 647 (derived: 28 test methods added, 1 renamed → +27 net; no
   count was recorded at baseline).
2. `./vendor/bin/pint --test app tests database routes config` → clean (and
   `pint` reported PASS on 291 files).
3. `npm run build` → built in 2.14 s (`app-JeqT2BJP.css`,
   `app-BmhPPE0O.js`).
4. Migration and flag:
   ```
   2026_10_05_120000_add_conversion_only_to_products_table ............ [7] Ran
   ```
   The tinker check → `Mossfield Mature Cheese`.
5. `git status --short`: only the files listed below, plus `implemented.md`.
   The staged archive from planning is already committed as 9a77e3c.
6. Browser: see *Browser checks* — steps 1, 2, 4, 7 and 9 (plus 3, 6 and 8);
   the console was clean.
7. Dev-DB probe: see above — ORD-20261005-001 / INV-000008 on **E2E Corner
   Shop**.

**Nothing committed, pushed or deployed.**

## Files changed
App:
- `app/Models/Customer.php`, `Product.php`, `Batch.php`
- `app/Http/Controllers/OrderController.php`, `ChilledRunController.php`,
  `BatchController.php`, `CheeseConversionController.php`,
  `OnlineOrdersController.php`,
  `Invoicing/UninvoicedOrderController.php`
- `app/Http/Requests/ProductRequest.php`
- `app/Console/Commands/ImportOnlineOrders.php`
- `app/Services/DispatchService.php`, `InvoiceService.php`
- `config/mossfield.php`
- `database/seeders/ProductSeeder.php`
- **new** `database/migrations/2026_10_05_120000_add_conversion_only_to_products_table.php`

Views:
- `resources/views/orders/{create,edit,show,dispatch-confirmation,docket}.blade.php`
- `resources/views/batches/create.blade.php`
- `resources/views/products/{create,edit}.blade.php`
- `resources/views/customers/{create,edit}.blade.php`
- `resources/views/invoicing/uninvoiced/index.blade.php`

Tests:
- **new** `tests/Feature/CreditLimitWarningTest.php`
- `tests/Feature/{DeliveryCharge,BatchCreate,BatchFinishedWeight,MatureProductSeed,CheeseConversion,DispatchConfirmation,OrderDocket,ChilledRun,OrderTransitions,InvoiceIssue,CustomerSpecialPrice,OrderShortSupply}Test.php`

Docs:
- `CLAUDE.md`, `docs/planImp/WORK_IN_PROGRESS.md`, this file.

## Notes for Planner
- **The order page's billing line still says "Awaiting invoice · Finance"
  for a ready order.** Since step 8 that order can't be issued until it is
  dispatched, so "Awaiting dispatch" would be truer. Out of scope; one line
  in `orders/show`.
- **Two July ready orders** (ORD-20260710-001 An Tairseach, ORD-20260723-001
  Barnhill) now sit under "Picked, awaiting dispatch", unloaded. Before this
  change they counted toward the dashboard's Awaiting-invoice tile; they no
  longer do, so that number drops on dev. They look like stale test data;
  the office may want to dispatch or cancel them.
- **The ceiling numbers** (5000/3000/200) are still guesses from the dev
  data — they need Jon's figures. The config comment says so.
- **Concurrency (Risks, step 1):** two simultaneous creations for the same
  drop can both charge. The dedupe read is inside the creating transaction
  but takes no lock — accepted per the plan; the office can fix one.
- **Imports and the drop rule:** if Mossorders ever sends a delivery date,
  pass it into `currentDeliveryCharge()` at both import sites and the rule
  covers them.
- `UninvoicedOrderController::issue()` still redirects with `back()` on
  refusal (pre-existing). CLAUDE.md prefers an explicit route for refusals;
  a candidate for SMALL_FIXES.
