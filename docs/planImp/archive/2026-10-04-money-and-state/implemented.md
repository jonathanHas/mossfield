# Money and state: the S1/S2 findings from the end-to-end run — implementation

Status: DONE
Plan revision: 1
Implementer: Opus
Date: 2026-10-04

## Baseline
HEAD: 44fde40 (the plan says `5f62755` + staged archive; the Planner has since
committed that as `44fde40`, so the tree is clean)
Pre-existing dirty files: clean

## Steps
### 1. Forms submit once (F-39) — done (browser check: see "Browser checks")
Changed: `resources/js/submit-once.js` (new), `resources/js/app.js`,
`database/migrations/2026_10_04_120000_add_form_token_to_payments_table.php` (new),
`app/Models/Payment.php` (fillable), `app/Services/PaymentService.php` (stores
`form_token`), `app/Http/Controllers/Invoicing/PaymentController.php` (token on
create; `form_token` nullable|uuid; `repeatSubmission()` before `record()` and
in the `UniqueConstraintViolationException` catch),
`resources/views/invoicing/payments/create.blade.php` (hidden `form_token`,
`old()`-preserved), `tests/Feature/PaymentAllocationTest.php` (+4 cases).

`submit-once.js`: bubble-phase `submit` listener; ignores `defaultPrevented`
(so confirm.js's parked submit never counts) and `GET` forms; marks the form
`data-submitting` + `aria-busy`; disables its submit buttons, including
`form="id"` ones, in a `setTimeout(0)`, so the submitter's name/value is
already in the form data; drops a second submit of a form already on its way;
re-enables on `pageshow` and after 10 s (see Deviations). It only re-enables
buttons it disabled itself, never Alpine's.

Check output:
```
$ php artisan migrate
  2026_10_04_120000_add_form_token_to_payments_table ........... 135.17ms DONE
$ php artisan test --filter=PaymentAllocationTest
  ✓ a repeated form submission records one payment
  ✓ different form tokens record separate payments
  ✓ a post without a form token still records
  ✓ the create form carries a form token
  Tests: 16 passed (43 assertions)
red check: storing form_token as null → "a repeated form submission…" FAILS
  ("Failed asserting that two strings are equal"); restored.
$ npm run build → ✓ built; grep -l "data-submitting" public/build/assets/*.js → app-DcUF9rXl.js
```

### 2. A weight that can't be a wheel is refused (F-29) — done (browser check below)
Changed: `app/Http/Controllers/Concerns/ValidatesFulfilmentWeights.php`
(`normaliseWeightInput()` turns `,` into `.` in `weights.*` and
`actual_weight_kg`; `WEIGHT_BOUND_FACTOR = 3`; per-unit `weights.*`
`max:3×nominal`, bulk `actual_weight_kg` `max:3×nominal×quantity` when the
variant's `weight_kg` > 0; messages quote the nominal),
`app/Http/Controllers/PickingController.php` and
`app/Http/Controllers/OrderAllocationController.php` (call
`normaliseWeightInput()` before `validate()`; pass `$orderItem` to the
messages), `resources/views/picking/item.blade.php` (comma → dot on keydown
for per-piece and bulk; amber **Check** tag instead of **OK** above 2 ×
expected), `resources/views/orders/partials/allocation-items.blade.php`
(desktop per-unit: the same keydown, `data-expected`, the row turns warn
colour above 2 × expected in `updateTotal()`),
`tests/Feature/FulfilmentUnitWeightsTest.php` (+4 cases; `allocated()` now
gives the batch `max(10, qty)` stock so 18 packs can be allocated).

The comma is inserted with `document.execCommand('insertText', false, '.')`
rather than by writing to the model, because a `type=number` input sanitises a
half-typed `"5."` to empty (see Deviations).

Check output:
```
$ php artisan test --filter="FulfilmentUnitWeightsTest|PickingTest|OrderFulfillmentWeightTest"
  ✓ a wheel weight far above nominal is refused
  ✓ a decimal comma is read as a decimal point
  ✓ a bulk total far above nominal is refused
  ✓ a variant without a nominal weight is not bounded
  Tests: 35 passed (164 assertions)
red check: WEIGHT_BOUND_FACTOR = 3000 → the two "far above nominal" cases FAIL; restored.
```

### 3. The order edit form can't skip the workflow (F-19) — done (browser check below)
Changed: `app/Http/Controllers/OrderController.php` (`status` →
`sometimes|in:…`, defaults to the current status; new `isAllowedStatusChange()`
permits only unchanged / `pending → confirmed` / `→ cancelled` when
`canBeCancelled()`; anything else redirects to `orders.show` with the plan's
error before anything is written; `$isCancelling` logic untouched),
`resources/views/orders/edit.blade.php` (the `<select name="status">` is
replaced by a read-only status tag, using the same tone `match` as the show
page's sibling list, plus "Status changes from the order page."),
`tests/Feature/OrderTransitionsTest.php` (+6 cases).

Check output:
```
$ php artisan test --filter="OrderTransitionsTest|OrderCustomerReferenceTest|DeliveryChargeTest"
  ✓ the edit route cannot jump an order to dispatched
  ✓ the edit route cannot mark an unpicked order ready
  ✓ the edit route cannot revive a cancelled order
  ✓ the show page confirm and cancel moves still work
  ✓ the edit form saves without posting a status
  ✓ the edit page has no status input
  Tests: 48 passed (182 assertions)
$ php artisan test   (whole suite, per the Risks note)
  Tests: 550 passed (3585 assertions)
```

### 4. A refused cut keeps the weighed total (F-12) — done (browser check below)
Changed: `resources/views/cheese-cutting/create.blade.php` (script: a
`totalTouched` flag set by typing in the total and cleared by a pack-count
change; a pack-count change always recomputes; a variant change recomputes
only if untouched; **on load a restored total is kept** and only
`updateAverageWeight()`/`updateWaste()` run), `tests/Feature/CheeseCutWeightTest.php`
(+1 case).

Check output:
```
$ php artisan test --filter=CheeseCutWeightTest
  ✓ a refused cut hands back the weighed total
  Tests: 12 passed (70 assertions)
```
Note: the `value="4.680"` half of the test would also have passed before
(`old()` was always rendered). The script half (the page carries the "keep a
restored total" branch) and the browser check are what cover the change, as
the plan said.

### 5. A refused batch form keeps the quantities and the bad date (F-08) — done (browser check below)
Changed: `resources/views/batches/create.blade.php` (script: `oldQuantities`
built from `@json(old('batch_items', []))`, keyed by `variant_id` and coerced
to integers because they're interpolated into an HTML string; an
`initialising` flag wraps the on-load product `change` dispatch, so the rebuilt
rows are seeded from it and `updateExpiryDate()` returns early when the field
already holds a value; product / production-date **changes** still
auto-fill), `tests/Feature/BatchCreateTest.php` (+1 case).

Check output:
```
$ php artisan test --filter=BatchCreateTest
  ✓ a refused batch hands back the quantities and the bad expiry
  Tests: 7 passed (29 assertions)
```

### 6. The order form previews what the customer will pay (F-20) — done (browser check below)
Changed: `app/Http/Controllers/OrderController.php` (`create()` eager-loads
`specialPrices.productVariant` and `deliveryRun`; passes `customerPrices`
= customer id ⇒ {variant id ⇒ round(special × (weight_kg if €/kg else 1), 2)},
only for customers with active-variant overrides; and `customerCharges` =
customer id ⇒ {charge: `currentDeliveryCharge()`, percent:
`deliveryChargePercent()`}), `resources/views/orders/create.blade.php`
(`unitPrice(option)` uses the customer's special else `data-price`; the
summary panel now reads **Items / Delivery (incl. VAT) / Total**, with delivery
= fixed charge, or round(items × % / 100, 2), and €0 while there are no
items; changing the customer re-prices every line),
`tests/Feature/CustomerSpecialPriceTest.php` (+1 case).

Check output:
```
$ php artisan test --filter="CustomerSpecialPriceTest|OrderStoreOptionalFieldsTest"
  ✓ the order form previews the customers price and delivery
  Tests: 11 passed (47 assertions)
```

### 7. The invoice names the order and the customer's reference (F-35) — done
Changed: `resources/views/invoicing/invoices/pdf.blade.php` (`$orderLookup` is
now built for **every** invoice, not only consolidated ones — the Risks case;
the "Invoice details" block prints `Order ORD-… · Your ref: …` per order when
`! $grouped`; consolidated headings unchanged),
`resources/views/invoicing/invoices/show.blade.php` (the HTML page has its own
header, not the PDF partial, so the same line goes under the subtitle for
non-consolidated invoices, with the order linked),
`tests/Feature/InvoiceIssueTest.php` (+1), `tests/Feature/ConsolidatedInvoiceTest.php` (+1).

Check output:
```
$ php artisan test --filter="InvoiceIssueTest|ConsolidatedInvoiceTest"
  ✓ a per order invoice names the order and the customers reference
  ✓ a consolidated pdf shows each reference once per order
  Tests: 27 passed (92 assertions)
```

### 8. A payment is applied unless the office says otherwise; a minus sign is refused (F-36, F-40) — done (browser check below)
Changed: `resources/views/invoicing/payments/create.blade.php` (amount bound
with `x-model="amount"`, a string, seeded `@js((string) old('amount', ''))`;
the stale `value=` dropped; `init()` watches `amount` and calls `autoFill()`
unless `allocationsTouched`, which each Apply box's `x-on:input` sets and
`autoFill()` clears; Record was already disabled while `parseFloat(amount) <= 0`
via `valid()`), `app/Http/Controllers/Invoicing/PaymentController.php` (the
`amount.min` message "A payment must be more than zero. Record refunds as
credit notes." — written during step 1, same `validate()` call),
`tests/Feature/PaymentAllocationTest.php` (+2 cases).

Check output:
```
$ php artisan test --filter=PaymentAllocationTest
  ✓ a negative payment is refused with a clear message
  ✓ the create form auto applies unless an apply box is edited
  Tests: 18 passed (50 assertions)
```

### 9. Docs — done
Changed: `CLAUDE.md` (Order Structure → **Status transitions** bullet;
Variable Weight → *Server-side weights* sentence on comma normalisation, the
3 × nominal bound and the client Check state; Frontend Development → new
**Forms** subsection on `submit-once.js` and `form_token`; Mature Conversion →
*Undo release* now says the Mature batch/item stay at 0/0 and are reused),
`docs/planImp/WORK_IN_PROGRESS.md` (*Recently Completed: Money and state fixes
(2026-10-04)* at the top).

Check output:
```
$ grep -n "Status transitions\|submit-once\|3 × nominal\|form_token" CLAUDE.md
146:`resources/js/submit-once.js` … form_token …           (new Forms section: submit-once + form_token)
435:- **Status transitions**: the only statuses OrderController::update() accepts …   (new)
542:- **Server-side weights** … 3 × nominal …                  (new sentence)
585:- **Status transitions**: first pick flips confirmed → preparing …   (pre-existing, Mobile Picking)
```
Four lines, as the plan expects. One of them (585) is a pre-existing bullet with
the same name, and submit-once and form_token share line 146. All four new items
are present.

## Browser checks
`php artisan serve --host=127.0.0.1:8000`, fresh `npm run build`, real mouse and
keyboard (the tab came up hidden once; I asked Jon to bring it forward, then
`visibilityState: visible`, 1408 px). Console clean on every page below.

- **Step 8 + step 1, payments** (office, Record payment for E2E Weekly Deli,
  INV-000006 balance €76.35; payments = 3 before):
  - Amount and Apply boxes start blank.
  - Typed **-5** → field keeps `-5`, `validity.valid=false` ("Value must be
    greater than or equal to 0.01."), **Record payment disabled** ✓.
  - Typed **1.00** → the Apply box **auto-fills 1.00**, "Left on account €0.00" ✓.
  - Edited the Apply box to 0.50, changed the amount to 2.00 → the Apply box
    **kept 0.50**, "Left on account €1.50" ✓ (my edit kept).
  - Amount 1.00 → Auto-fill oldest first → 1.00 / €0.00 (override cleared) ✓.
  - **Double-clicked Record payment** → the server log shows a **single**
    `POST /invoicing/payments` → "Payment of €1.00 recorded for E2E Weekly
    Deli."; payments 3 → **4**, INV-000006 balance 76.35 → 75.35 ✓.
- **Step 6, order form**: E2E Corner Shop, 1L × 24 → line **€30.00** (special
  €1.25), **Delivery (incl. VAT) €6.15**, **Total €36.15**. Switching customer
  re-priced every line: Organic Store €36.00 (its €1.50 special, no run,
  delivery €0); Mortons of Ranelagh €60.00 (standard). Created
  **ORD-20261004-008** (id 19) → saved "Subtotal €30.00 · Delivery charge €5.00
  · VAT €1.15 · Total €36.15", the **same as the preview, to the cent** ✓.
  (The option labels still say "(€2.50)", the standard price; the line totals
  use the customer's. Not in scope.)
- **Step 3, edit form** on order 19: "ORDER STATUS · Pending · Status changes
  from the order page."; no `name="status"` input; appended to notes →
  Update order → "Order updated successfully." ✓.
- **Step 1, Confirm order** on order 19: a MutationObserver on the button
  recorded `{disabled: true, aria-busy: "true", data-submitting: "1"}` after
  the dialog's OK; double-clicking the dialog's Confirm → **one** PATCH (log
  17:14:19 PATCH + redirect GET) → confirmed ✓. The confirm.js resubmit wasn't
  blocked by submit-once. (A double-click on the page's **Confirm order**
  button itself opens the dialog, and the second click lands on its backdrop
  and closes it, so nothing is posted: also safe.) Show page **Cancel order**
  (dialog) → "Order cancelled." ✓; order 19 cancelled.
- **Step 5, batch form**: Milk, raw 10, expiry **01/10/2026** (before
  production 04/10), 1L 10, Enter → "The expiry date field must be a date
  after production date." → after the redirect the expiry still reads
  **2026-10-01** and the 1L row still holds **10** (raw milk 10 too) ✓.
- **Step 4, cut form** (G010426): pack type, wheel 5.120, packs 18, total typed
  **4.680**, cut date 01/03/2026 → "The cut date cannot be before the batch
  was produced (2026-04-01)." → total still **4.680**, trim 0.440, avg 0.260 ✓.
  Packs → 19 → total recomputed **4.750** ✓. Typed 4.700, then packs → 18 →
  recomputed **4.500** (the touched flag clears on a pack change) ✓. No cut was
  submitted.
- **Step 2, weights.** Throwaway order **ORD-20261004-009** (id 20, Corner
  Shop, Farmhouse Whole Wheel × 2, delivery 13/10), confirmed and
  auto-allocated as office.
  - **Desktop panel**: typed "5,210" in wheel #1 → field **5.210**, total
    5.210 ✓ (`execCommand('insertText')` works in a number input); typed 12 →
    the row turns `var(--warn-ink)` ✓.
  - **Factory** at `/picking/20/items/54` (desktop width): wheel #1 "5,210" →
    **5.210 · OK** ✓; wheel #2 "52.10" → amber **Check**, submit still enabled ✓;
    wheel #2 "5210" → Picked · Next → refused "Each unit must weigh no more than
    15 kg — a wheel is usually about 5.000 kg. Check the decimal point." DB:
    nothing picked (ful 0), G010426 still 8 ✓.
  - Order 20 then cancelled as office ("Order cancelled. Reserved and picked
    stock has been returned to its batch.").

## Deviations
1. **`submit-once.js` skips `GET` forms and re-enables after 10 s.** The plan
   said "every form" and "re-enable on `pageshow`". Two forms download a file
   and never leave the page: the CSV export (GET) and the backup download
   (POST). With the plan's version their buttons would stay disabled until
   reload. GET is idempotent, so skipping it costs nothing. The 10 s timer only
   matters for a POST that doesn't navigate, and a double-click (milliseconds
   apart) is still blocked. It also drops a second `submit` event on a form
   already marked `data-submitting` (e.g. Enter pressed twice), which the plan
   didn't mention.
2. **Comma handling uses `document.execCommand('insertText', false, '.')`**
   instead of "set the model string" / `value += '.'`. A `type=number` input
   sanitises a half-typed value like `"5."` to empty, so writing it would blank
   the field. `insertText` inserts into the edit buffer like a keystroke and
   fires `input`, so `x-model` / `oninput` stay in sync. Verified in the
   browser on both forms. (`execCommand` is deprecated but still supported
   in every current browser.)
3. **Step 8's server message was written during step 1**, because it is the
   same `validate()` call in `PaymentController::store()`.
4. **Two throwaway orders for the browser checks** (E2E Corner Shop, notes
   tagged "E2E-20261004 cycle3…"): ORD-20261004-008 (id 19) and
   ORD-20261004-009 (id 20), both cancelled. Plus one real €1.00 payment
   (id 8) on E2E Weekly Deli's INV-000006, from the double-click check.
5. **Batch old-quantity seeding coerces to integers**, because the values are
   interpolated into an HTML template string (a crafted POST could otherwise
   inject markup into the rebuilt rows).
6. **The edit page's status tag** reuses the show page's sibling-list tone
   `match` inline. The show page has no shared status-pill component to reuse.

## Verification
1. `php artisan test` → **557 passed (3623 assertions), 0 failed** (536 + 21
   new cases: payments +6, weights +4, transitions +6, cut +1, batch +1,
   order preview +1, invoice +2).
2. `./vendor/bin/pint --test app tests database routes` → `PASS … 262 files`.
3. `npm run build` → ok; `grep -l "data-submitting" public/build/assets/*.js`
   → `public/build/assets/app-DcUF9rXl.js`.
4. `php artisan migrate:status | tail -3` →
   `2026_10_04_120000_add_form_token_to_payments_table … [5] Ran`.
5. `git status --short` → only files this plan names (list below); no staged
   archive (it was already committed as `44fde40`).
6. Browser checks for steps 1, 2, 3, 4, 5, 6, 8 done with real input (above),
   console clean.

## Files changed
```
 M CLAUDE.md
 M app/Http/Controllers/Concerns/ValidatesFulfilmentWeights.php
 M app/Http/Controllers/Invoicing/PaymentController.php
 M app/Http/Controllers/OrderAllocationController.php
 M app/Http/Controllers/OrderController.php
 M app/Http/Controllers/PickingController.php
 M app/Models/Payment.php
 M app/Services/PaymentService.php
 M docs/planImp/WORK_IN_PROGRESS.md
 M resources/js/app.js
 M resources/views/batches/create.blade.php
 M resources/views/cheese-cutting/create.blade.php
 M resources/views/invoicing/invoices/pdf.blade.php
 M resources/views/invoicing/invoices/show.blade.php
 M resources/views/invoicing/payments/create.blade.php
 M resources/views/orders/create.blade.php
 M resources/views/orders/edit.blade.php
 M resources/views/orders/partials/allocation-items.blade.php
 M resources/views/picking/item.blade.php
 M tests/Feature/BatchCreateTest.php
 M tests/Feature/CheeseCutWeightTest.php
 M tests/Feature/ConsolidatedInvoiceTest.php
 M tests/Feature/CustomerSpecialPriceTest.php
 M tests/Feature/FulfilmentUnitWeightsTest.php
 M tests/Feature/InvoiceIssueTest.php
 M tests/Feature/OrderTransitionsTest.php
 M tests/Feature/PaymentAllocationTest.php
?? database/migrations/2026_10_04_120000_add_form_token_to_payments_table.php
?? docs/planImp/implemented.md
?? resources/js/submit-once.js
```
`app/Services/PaymentService.php` isn't in step 1's file list, but step 1's
text says `PaymentService::record()` stores the token, so the plan names it.
`OrderAllocationController.php` and `PickingController.php` are the two
callers step 2 names. Nothing committed.

## Notes for Planner
- **Order-form option labels** still show the standard price ("1L Bottle
  (€2.50)") while the line uses the customer's €1.25. The totals are right;
  the label could follow the customer too. Left alone (labels weren't in
  step 6).
- **Old allocations on the payment form** aren't restored after a validation
  refusal: the component starts with `allocations: {}`. With auto-apply this
  mostly self-heals (the amount re-applies on the next change), but the
  operator's manual split is lost on a refused submit. Not in scope.
- **3 × nominal bound and Mature wheels**: Mature Whole Wheel has
  `weight_kg = 5.000` like Farmhouse, so the same 15 kg ceiling applies. I
  found no variant whose nominal is far below reality. Vacuum packs (0.250 kg)
  allow 0.75 kg each, × quantity for bulk.
- **`submit-once` and the chilled-run sheet**: the stop forms use `form="…"`
  external buttons; `submitButtonsOf()` covers them (`button[form=id]`), but I
  didn't click-test the sheet's Save. The browser-testing session could add it.
- **Cleanup**: the cycle-3 browser checks added orders 19 and 20 (both
  cancelled) and payment 8 (€1.00, Weekly Deli) to the dev DB.
