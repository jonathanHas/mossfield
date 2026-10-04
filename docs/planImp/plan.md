# Money and state: the S1/S2 findings from the end-to-end run

Status: READY
Revision: 1
Planner: Fable 5.1
Date: 2026-10-04

## Goal

Close the eight findings from the 2026-10-04 end-to-end browser run that can
write wrong money or wrong state with no warning
(`archive/2026-10-04-e2e-browser-run/implemented.md`, F-39, F-29, F-19,
F-12, F-08, F-20, F-35, F-36/F-40). After this cycle: a double-clicked form
records once; a wheel weight typed with a comma can't bill €83 360; the order
edit form can't teleport an order to *dispatched*; a refused cut or batch form
hands back what was typed; the order form previews the price the customer will
actually pay; the invoice carries the customer's PO reference; and a recorded
payment is applied to the open invoice unless the office says otherwise.

## Context

HEAD `5f62755` + the staged archive of cycle 2. Suite **536 passed, 0 failed**;
any failure is real. `pint --test app tests database routes` clean. Nothing
pushed or deployed. Dev DB holds the E2E data (see `RESUME.md`); nothing here
needs it.

Each step below cites the finding; read it in the archived `implemented.md`
first — it has the exact steps, evidence and the Implementer's suggested fix.
This plan decides which fix.

**Patterns to copy.**
- JS lives in `resources/js/*.js`, imported from `app.js` (cycle 1 added
  `confirm.js`: capture-phase `document` listeners, `requestSubmit(submitter)`,
  a per-form flag cleared by `setTimeout(0)`). Read it before writing
  `submit-once.js` — the two must cooperate (see step 1).
- Server-side form rules live in FormRequests or inline `$request->validate`;
  weight rules are centralised in
  `app/Http/Controllers/Concerns/ValidatesFulfilmentWeights.php` (shared by
  `OrderAllocationController::fulfill()` and `PickingController::pick()`).
- Tests extend `Tests\TestCase` with `RefreshDatabase`; invoicing tests extend
  `tests/Feature/InvoicingTestCase.php` (shop, bottle, wheel, `deliveredOrder()`,
  `issue()`). `User::factory()` → office; `->admin()`, `->factoryWorker()`.
- Blade forms keep typed values with `old()`; two of them then let a
  `DOMContentLoaded` script overwrite the field (steps 4–5).
- Confirm dialogs are `data-confirm` attributes (cycle 1).

**Facts the steps rely on.**
- `OrderController::update()` (`app/Http/Controllers/OrderController.php:302`)
  validates `status` as `required|in:` all seven values and writes it. The
  show page's **Confirm order** and **Cancel order** quick-actions POST to the
  same route with `status=confirmed` / `status=cancelled` plus the
  `$statusFields` replay (`orders/show.blade.php:19`). `orders/edit.blade.php:44`
  renders a free `<select name="status">`. Legitimate transitions already have
  their own routes: `orders.dispatch` (→ `DispatchService`), `orders.deliver`,
  picking/allocation (`markPickingStarted()`, `reconcilePickingStatus()`).
- `ValidatesFulfilmentWeights::fulfilmentWeightRules()` — per-unit
  `weights.*` → `required|numeric|min:0.001`; bulk `actual_weight_kg` →
  `required|numeric|min:0.001`. Nominal weight is
  `$orderItem->productVariant->weight_kg` (nullable; cheese variants have it).
  Inputs: `picking/item.blade.php:308` (`type="number" step="0.001"
  inputmode="decimal"`, `x-model="weights[i-1]"`) and `:324` (bulk);
  `orders/partials/allocation-items.blade.php:144` (desktop per-unit,
  `oninput="updateTotal(id)"`). Chrome drops a typed comma in a `number`
  input, so "5,210" becomes 5210.
- `PaymentController::store()` validates and calls
  `PaymentService::record($customer, $data, $allocations, $by)` inside one
  transaction; `payments` has no uniqueness beyond the id. The form is the
  Alpine component `paymentForm(invoices, initialAmount)` in
  `invoicing/payments/create.blade.php` (`x-model.number="amount"`,
  `allocations` blank until **Auto-fill oldest first**).
- `cheese-cutting/create.blade.php`: `total_weight_kg` input has
  `:value="old('total_weight_kg')"`, but the script's `updateTotalWeight()`
  (packs × nominal) runs on load and on pack/variant change and overwrites it.
- `batches/create.blade.php`: variant quantity rows are built by JS
  (`batch_items[${index}][quantity_produced]`) from the product's variants;
  nothing seeds them from `old('batch_items')`. `updateExpiryDate()` sets
  `expiry_date` from shelf life on product/production-date change **and on
  load**, over `old('expiry_date')`.
- `orders/create.blade.php`: every variant `<option>` carries
  `data-price="{{ $variant->estimated_unit_price }}"` (base price × nominal
  weight for €/kg variants); the JS sums `price × qty` into `#orderTotal`.
  `OrderController::create()` (`:197`) passes `$customers` (active) and
  `$productVariants` (grouped). The saved price is
  `Customer::unitPriceFor($variant)` (special price if any, else base — both in
  €/unit or €/kg), and the saved delivery charge is
  `Customer::currentDeliveryCharge()` (gross) or `deliveryChargePercent()`.
- `invoicing/invoices/pdf.blade.php`: `$grouped = $invoice->isConsolidated()`;
  the "Invoice details" block (`:59–68`) prints date / period / terms / due;
  per-order headings with `Order … · ref …` render only inside the lines loop
  when `$grouped` (`:85–95`). `$orderLookup` (id → order) is already passed to
  the view — find where it's built (grep `orderLookup` in `app/`) and reuse it.
  `Invoice::orders()` is the orders whose `invoice_id` is this invoice.

## Constraints

- Every message a customer or operator already sees stays unless a step
  changes it. No route renames. No change to `DispatchService`, allocation
  arithmetic or the free-stock expression (`TESTING_AND_FIXES.md` →
  *Invariants worth keeping*).
- Weight validation: **recompute from per-unit weights** server-side, as now;
  the new bound is an extra rule, not a replacement.
- The show-page Confirm / Cancel quick-actions must keep working unchanged.
- `npm run build` after JS changes; `./vendor/bin/pint` on touched PHP.
- **Do not commit.** The Planner commits on acceptance. Nothing is pushed or
  deployed.

## Out of scope

- Every S3/S4 finding (cycle 4) and every decision item (`OPEN_QUESTIONS.md`
  #5) — including F-21 (two number boxes per line), F-22 (caret after the
  pre-filled 1 on Add item), F-24 (second delivery charge), F-25 (credit
  limit), F-33 (notes on customer documents).
- Changing how allocations are applied in `PaymentService` beyond what step 3
  says.
- A general idempotency framework. Step 1's token is for payments only; the
  JS guard covers the rest.
- Restyling anything.

## Steps

### 1. Forms submit once (F-39)

Files: `resources/js/submit-once.js` (new), `resources/js/app.js`,
`database/migrations/2026_10_04_120000_add_form_token_to_payments_table.php` (new),
`app/Models/Payment.php`, `app/Http/Controllers/Invoicing/PaymentController.php`,
`resources/views/invoicing/payments/create.blade.php`,
`tests/Feature/PaymentAllocationTest.php`

What:
- **Client, every form.** `submit-once.js`: one bubble-phase `submit`
  listener on `document`. When a form's submit is not default-prevented
  (`!e.defaultPrevented` — i.e. `confirm.js` has already let it through, or
  the form has no confirm), mark it `data-submitting` and, in a
  `setTimeout(…, 0)`, disable every `button[type=submit]` **owned by** the
  form (`form.elements` plus `document.querySelectorAll('button[form="'+id+'"]')`)
  and add `aria-busy="true"`. Disabling must happen after the current tick so
  the submitter's `name`/`value` is still in the form data. On `pageshow`
  (bfcache back) re-enable everything and clear the flag. Alpine forms that
  bind `:disabled` keep working — we only add `disabled`, never remove theirs
  while the page lives. Import it from `app.js` after `./confirm`.
- **Server, payments.** Migration: nullable `string('form_token', 36)` with a
  **unique** index on `payments`. `Payment::$fillable` += `form_token`.
  `PaymentController::create()` passes `'formToken' => (string) Str::uuid()`
  and the form posts it as a hidden `form_token` (keep it in `old()` on a
  validation redirect — `old('form_token', $formToken)`). `store()` validates
  `form_token` as `nullable|uuid`; before `record()`, if a payment with that
  token exists, redirect to it with the flash
  `Payment already recorded — this was a repeat submission.`; pass the token
  into `record()`'s `$data` so it's inserted; catch
  `Illuminate\Database\UniqueConstraintViolationException` around `record()`
  and do the same redirect (two near-simultaneous posts: the second insert
  fails on the index). `PaymentService::record()` stores
  `'form_token' => $data['form_token'] ?? null`.
- Tests (`PaymentAllocationTest`): (a) posting the same valid payload with the
  same `form_token` twice creates **one** payment and the second response
  redirects to it with the "repeat submission" flash; (b) two posts with
  different tokens create two payments (the token is not a per-customer
  lock); (c) a post without a token still works.

Check: `php artisan migrate` clean; `php artisan test --filter=PaymentAllocationTest`
pass; `npm run build` ok; in the browser (`php artisan serve`, as office,
`/invoicing/payments/create`) double-click **Record payment** → one payment;
on the order page double-click **Confirm order** → confirmed once, and the
button is visibly disabled for the instant before navigation.

### 2. A weight that can't be a wheel is refused (F-29)

Files: `app/Http/Controllers/Concerns/ValidatesFulfilmentWeights.php`,
`resources/views/picking/item.blade.php`,
`resources/views/orders/partials/allocation-items.blade.php`,
`tests/Feature/FulfilmentUnitWeightsTest.php` (or `PickingTest.php` — put the
cases where that suite's fixtures already give a weighed wheel line)

What:
- **Normalise commas server-side** before validation: in the two callers (or
  a small `normaliseWeightInput(Request)` helper on the trait, called first),
  replace `,` with `.` in `weights.*` and `actual_weight_kg` via
  `$request->merge([...])`. So "5,210" posted from any client validates as
  5.210.
- **Plausibility bound.** In `fulfilmentWeightRules()`, when
  `$orderItem->productVariant->weight_kg` is set (> 0): per-unit `weights.*`
  gets `max:` **3 × nominal** (rounded to 3 dp); bulk `actual_weight_kg` gets
  `max:` 3 × nominal × `request('quantity')`. Messages
  (`fulfilmentWeightMessages()`): `weights.*.max` → `Each unit must weigh no
  more than :max kg — a wheel is usually about {nominal} kg. Check the decimal
  point.`; `actual_weight_kg.max` similarly for the line total. When nominal
  is null, no bound (as now).
- **Client.** Picking (`item.blade.php:308`, `:324`) and desktop (`:144`):
  keep `type="number"` (step/min still useful) but add a `keydown` handler
  that turns a typed `,` into `.` — in Alpine: `x-on:keydown.prevent="…"` only
  for the comma key (`$event.key === ','`), then insert `.` into the model
  (the picking inputs are `x-model`-bound, so set the model string; the
  desktop input is plain JS — set `value += '.'` and call `updateTotal`).
  Also an amber "check this" state: on the picking per-piece rows the
  existing `● OK` indicator (next to "expected ~ N kg") should read
  **`Check`** in the warn colour when a weight exceeds 2 × nominal, and the
  submit stays enabled (the server bound is the hard stop). Desktop: the row
  gets `style="color: var(--warn-ink)"` under the same rule.
- Tests: per-unit post `weights=[5210, 4.980]` for a 5 kg nominal wheel →
  422/redirect with the `max` message and **nothing picked**; `weights=['5,210',
  '4.980']` → accepted, stored as `[5.210, 4.980]`, total 10.190; bulk
  `actual_weight_kg=60` for 18 × 0.250 kg packs → refused; a variant with no
  nominal weight accepts 50 kg (no bound).

Check: `php artisan test --filter="FulfilmentUnitWeightsTest|PickingTest|OrderFulfillmentWeightTest"`
pass. Browser as factory at `/picking/{order}/items/{wheel line}`: type
`5,210` → field shows `5.210`; type `52.10` → amber **Check**; submitting
`5210` (paste it) → server refusal with the message, nothing picked.

### 3. The order edit form can't skip the workflow (F-19)

Files: `app/Http/Controllers/OrderController.php`,
`resources/views/orders/edit.blade.php`, `tests/Feature/OrderTransitionsTest.php`

What:
- `update()`: `status` becomes `sometimes|in:…`; when absent, use
  `$order->status`. Then **validate the transition** before the transaction:
  allowed are (a) unchanged, (b) `pending → confirmed`, (c) `→ cancelled`
  when `$order->canBeCancelled()`. Anything else → `redirect()->route('orders.show',
  $order)->with('error', 'Order status moves through the workflow — use
  Confirm, picking, Mark dispatched or Mark delivered.')` with nothing written.
  The existing `$isCancelling` logic is unchanged.
- `orders/edit.blade.php:41–50`: replace the `<select name="status">` with a
  read-only status badge (reuse whatever the show page uses for the status
  pill) and the one-line hint `Status changes from the order page.` No
  `status` input is posted from this form any more.
- Keep the show page's Confirm (`status=confirmed`) and Cancel
  (`status=cancelled`) forms exactly as they are — they are the allowed
  transitions.
- Tests (`OrderTransitionsTest`): office PATCH `status=dispatched` on a
  pending order → redirect with the error, status unchanged, `dispatched_at`
  null, no invoice; `status=ready` on a confirmed order with nothing picked →
  refused; `status=pending` on a cancelled order → refused; `status=confirmed`
  on pending → allowed (existing behaviour); `status=cancelled` on a ready
  order → allowed and stock returned (existing test covers the stock);
  PATCH without `status` (the edit form) → other fields saved, status
  unchanged; the edit page no longer contains `name="status"`.

Check: `php artisan test --filter="OrderTransitionsTest|OrderCustomerReferenceTest|DeliveryChargeTest"`
pass (the latter two use the edit form/PATCH). Browser: `/orders/{id}/edit`
shows the badge, saving still works; show page Confirm and Cancel still work.

### 4. A refused cut keeps the weighed total (F-12)

Files: `resources/views/cheese-cutting/create.blade.php`,
`tests/Feature/CheeseCutWeightTest.php`

What:
- In the script: do **not** call `updateTotalWeight()` on load when the input
  already has a value (`old('total_weight_kg')` rendered it). Only recompute
  on `change`/`input` of the pack count or the pack variant, and even then
  only if the operator hasn't typed a total themselves — track a
  `totalTouched` flag set by the total input's own `input` event and cleared
  when the pack count changes. On load, call `updateAverageWeight()` and
  `updateWaste()` so the calculator reflects the restored values.
- Test: POST a cut with `total_weight_kg=4.680` and a `cut_date` before
  production → redirect back; follow it and assert the page contains
  `value="4.680"` on `#total_weight_kg` (the `old()` is rendered). The JS
  change is checked in the browser (below) and by the browser session.

Check: `php artisan test --filter=CheeseCutWeightTest` pass. Browser: on a
cut form type wheel 5.120, packs 18, total **4.680**, cut date before
production → refusal; the total still reads **4.680**; now change packs to 19
→ total recomputes (4.750); type 4.700 then change packs → recomputes again
(touched flag cleared on pack change).

### 5. A refused batch form keeps the quantities and the bad date (F-08)

Files: `resources/views/batches/create.blade.php`, `tests/Feature/BatchCreateTest.php`

What:
- Pass `@json(old('batch_items', []))` into the script as `oldItems`. When
  rows are built for the selected product (on load with `old('product_id')`
  present), seed each `quantity_produced` from the matching `variant_id` in
  `oldItems`.
- `updateExpiryDate()`: on load, skip when `expiry_date` already has a value
  (it's `old()`); keep auto-filling on product / production-date **change**.
- Test (`BatchCreateTest`): POST milk with `expiry_date` before
  `production_date` and `batch_items[0][quantity_produced]=10` → redirect;
  follow and assert the page embeds the old items JSON containing `"10"` and
  the expiry input's `value` is the refused date, not blank.

Check: `php artisan test --filter=BatchCreateTest` pass. Browser: refuse a
batch with a bad expiry → quantities and the bad expiry are back on screen
beside the error.

### 6. The order form previews what the customer will pay (F-20)

Files: `app/Http/Controllers/OrderController.php` (`create()`),
`resources/views/orders/create.blade.php`, `tests/Feature/CustomerSpecialPriceTest.php`

What:
- `create()` builds two maps and passes them: `customerPrices` =
  `[customer_id => [variant_id => estimated unit price for that customer]]`
  for every active customer × active variant where the customer has a special
  price (`Customer::specialPrices` eager-loaded — only overrides, the JS falls
  back to `data-price`); the estimate is `special_price × (weight_kg if
  is_priced_by_weight else 1)`, rounded to 2 dp. `customerCharges` =
  `[customer_id => ['charge' => currentDeliveryCharge(), 'percent' =>
  deliveryChargePercent()]]`.
- View: embed both with `@json`. `updateLineTotal()` uses
  `customerPrices[customerId]?.[variantId] ?? data-price`. Below "Order
  total" add two lines: `Delivery (incl. VAT) €X` — `charge`, or
  `round(subtotal × percent / 100, 2)` when `percent` is set — and
  `Total €Y`; recompute on customer change and on every line change. Label
  the first figure **Items** so the three read Items / Delivery / Total.
- Test: as office, GET `/orders/create` with a customer who has a special
  price → the page embeds that price under the customer id and variant id;
  and the delivery map carries the run charge for a flagged customer.

Check: `php artisan test --filter="CustomerSpecialPriceTest|OrderStoreOptionalFieldsTest"`
pass. Browser: choose E2E Corner Shop, add 1L × 24 → line €30.00, Delivery
€6.15, Total matches what the saved order then shows (to the cent).

### 7. The invoice names the order and the customer's reference (F-35)

Files: `resources/views/invoicing/invoices/pdf.blade.php`,
`tests/Feature/InvoiceIssueTest.php`

What:
- In the "Invoice details" block, when `! $grouped`, after the invoice date
  print one line per order in `$orderLookup` (there is exactly one on a
  per-order invoice): `Order ORD-… ` and, when filled, `· Your ref: PO-…`.
  Consolidated invoices keep their per-order group headings as now (they
  already show the ref) — don't duplicate.
- Also show the same line on the HTML invoice page if it has its own header
  (check `invoicing/invoices/show.blade.php`; if it renders the PDF partial,
  nothing to do).
- Test: issue an invoice for a delivered order with `customer_reference =
  'PO-123'`; the invoice show page and the PDF HTML contain `ORD-` and
  `PO-123`; a consolidated invoice still shows them once per order.

Check: `php artisan test --filter="InvoiceIssueTest|ConsolidatedInvoiceTest"` pass.

### 8. A payment is applied unless the office says otherwise; a minus sign is refused (F-36, F-40)

Files: `resources/views/invoicing/payments/create.blade.php`,
`tests/Feature/PaymentAllocationTest.php`

What:
- Component: `amount` starts as `''` (not 0) — `old('amount', '')`; bind with
  `x-model="amount"` (string) and parse where it's used (`parseFloat`). The
  `min="0.01"` then refuses a negative at submit; also disable **Record
  payment** while `parseFloat(amount) <= 0`.
- Auto-apply by default: when `amount` changes and the operator has **not**
  edited any Apply box (`allocationsTouched` flag set by `input` on those
  boxes, cleared by **Auto-fill oldest first**), call `autoFill()`. The
  "Left on account" figure then reads 0 for a payment that covers open
  invoices, and the operator can still override per invoice.
- Server (defence): `amount` already `min:0.01`; add a clear message
  `A payment must be more than zero. Record refunds as credit notes.`
- Test: server refuses `amount=-5` with that message (the JS part is a
  browser check).

Check: `php artisan test --filter=PaymentAllocationTest` pass. Browser: open
Record payment for a customer with one open invoice, type the amount → the
Apply box fills and "Left on account €0.00"; type `-5` → browser refuses on
submit; edit an Apply box, change the amount → your edit is kept.

### 9. Docs

Files: `CLAUDE.md`, `docs/planImp/WORK_IN_PROGRESS.md`

What:
- `CLAUDE.md` → **Order Structure**: add a bullet *Status transitions*: the
  only statuses `OrderController::update()` accepts are unchanged, `pending →
  confirmed`, and `→ cancelled` via `canBeCancelled()`; everything else goes
  through `orders.dispatch`/`orders.deliver`/picking. The edit form no longer
  posts `status`.
- **Variable Weight Fulfillment** → *Server-side weights*: commas are
  normalised to dots; per-unit weights are bounded at 3 × nominal
  `weight_kg` (bulk: × quantity) when a nominal exists.
- **Frontend Development**: a *Forms* bullet — `resources/js/submit-once.js`
  disables a form's submit buttons after submission (re-enabled on `pageshow`);
  payments carry a `form_token` (unique) so a repeat POST is a redirect, not a
  second payment.
- **Mature Conversion** → *Undo release*: correct "drops the mature item" to
  "leaves the Mature batch and item in place at 0 produced / 0 remaining and
  reuses them on the next release" (observed in the E2E run).
- `WORK_IN_PROGRESS.md`: a *Recently Completed: Money and state fixes
  (2026-10-04)* entry at the top listing the eight findings closed.

Check: `grep -n "Status transitions\|submit-once\|3 × nominal\|form_token" CLAUDE.md`
→ four hits.

## Verification

1. `php artisan test` → **0 failed**; record the passing count (536 + the new
   cases).
2. `./vendor/bin/pint --test app tests database routes` → clean.
3. `npm run build` → ok; `grep -l "data-submitting" public/build/assets/*.js`
   finds the bundle.
4. `php artisan migrate:status | tail -3` shows the payments migration ran.
5. `git status --short` → only files this plan names (+ the staged archive).
6. The browser checks in steps 1, 2, 3, 4, 5, 6, 8 (real input; `php artisan
   serve` on 127.0.0.1 is fine). Console clean on each page.

## Risks

- **`submit-once` vs `confirm.js`.** `confirm.js` prevents the first submit
  and re-submits via `requestSubmit`; `submit-once` must act only on submits
  that go through (`!e.defaultPrevented`), or it will disable the button
  while the dialog is open and the confirmed re-submit finds a disabled
  submitter. Test both orders of listener registration by double-clicking a
  dialog-gated button and a plain one.
- **Disabling the submitter too early** drops its `name`/`value` (the
  statements preview button). Hence `setTimeout(0)`.
- **Alpine `:disabled`** on the picking FAB and the payment button: adding
  `disabled` is fine; never *remove* a disabled attribute Alpine owns.
- **3 × nominal** could refuse a genuine outlier (a 15 kg wheel on a 5 kg
  nominal). Farmhouse wheels run 4.5–5.5 kg; the bound is generous. If the
  Implementer finds a variant whose nominal is far below reality, note it
  rather than loosening the rule.
- **Removing the status select** may break any test that posts `status` from
  the edit form expecting it to be required — run the whole suite, not just
  the named filters.
- **`$orderLookup`** may be built only for consolidated invoices; if so,
  build it for all and keep the per-order heading logic unchanged.

## Review

(Planner fills this in after reading implemented.md and the diff.)
