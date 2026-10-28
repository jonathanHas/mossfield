# Cycle 15 — the 2026-10-13 leftovers, two cycle-14 carry-overs, and the dev-DB data fixes — implementation

Status: DONE
Plan revision: 2
Implementer: Opus
Date: 2026-10-28

## Baseline
HEAD: 559c1b2
Pre-existing dirty files:
```
 M docs/planImp/SMALL_FIXES.md
 M docs/planImp/UX_REVIEW_2026-10-13.md
?? docs/planImp/plan.md
```

## Steps
### 1. Migration: email, city and postal code become nullable — done
Changed: `database/migrations/2026_10_28_130000_make_customer_contact_fields_nullable.php` (new; checked first — did not exist)
Check output:
```
  2026_10_28_130000_make_customer_contact_fields_nullable ....... 69.95ms DONE
email: YES
city: YES
postal_code: YES
(customers_email_index survives the change())
BackupRestoreTest|ResetTradingDataTest → Tests: 27 deprecated (165 assertions), 0 failed
```

### 2. Customer validation: one rule set, optional fields, trimmed input — done
Changed: `app/Http/Controllers/CustomerController.php` — `rules()`, `messages()`, `tidyInput()` (the pre-validate `merge`), `normalise()`; `withDuplicateEmailWarning()` returns early on a blank email.
Check output:
```
CustomerSpecialPriceTest|DeliveryChargeTest|OnlineOrdersVisibilityTest|UxCopyTest → Tests: 58 deprecated (249 assertions), 0 failed
grep -c "'required|email" app/Http/Controllers/CustomerController.php → 0
```
(See Deviations: the absent-select fallback on update reads the stored `invoice_delivery`.)

### 3. Customer forms: optional fields read as optional — done
Changed: `resources/views/customers/create.blade.php`, `resources/views/customers/edit.blade.php`, `tests/Feature/UxCopyTest.php` (hint string only)
Check output:
```
UxCopyTest → Tests: 8 deprecated (46 assertions), 0 failed
grep -nE 'id="(email|city|postal_code|credit_limit)"[^>]*required' resources/views/customers/*.blade.php → (nothing, exit 1)
```

### 4. `full_address` without empty commas; index shows "—"; `CustomerFormTest` — done
Changed: `app/Models/Customer.php` (`getFullAddressAttribute()` filters blanks), `resources/views/customers/index.blade.php` (Contact `email ?: '—'`), `tests/Feature/CustomerFormTest.php` (new; checked first — did not exist)
Check output:
```
php artisan test --filter=CustomerFormTest
  Tests:    9 deprecated (35 assertions)   → 9 passed, 0 failed
```

### 5. Dev-DB data fixes (tinker) — done
Changed: dev DB `mossfield_cl` only (through the models; encrypted columns via `$customer->update()`).
Check output (one tinker run, matched by name):
```
["before",20,"Avoa Ballsbridge"]
["after",20,"Avoca Ballsbridge"]
["before",{"id":14,"product":"Mossfield Organic Yoghurt","name":"16L Bucket","by_weight":true,"base_price":"72.00","weight_kg":"16.000"}]
["after", {"id":14,...,"by_weight":false,"base_price":"72.00",...}]         ← price was already 72.00; only the flag changed
["before",{"id":7,"product":"Mossfield Garlic & Basil Cheese","name":"Whole Wheel","by_weight":true,"base_price":"16.00",...}]
["after", {"id":7,...,"by_weight":true,"base_price":"16.00",...}]           ← NO-OP on dev, as the plan predicted
["before",{"id":15,"product":"Mossfield Mature Cheese","name":"Whole Wheel","by_weight":false,"base_price":"55.00","weight_kg":"5.000"}]
["after", {"id":15,...,"by_weight":true,"base_price":"20.00","weight_kg":"5.000"}]
["customer",10,"An Tairseach Organic Farm Shop","before",{"postal_code":"A67 YX 26","address":"Kilmantin Hill,"},"after",{"address":"Kilmantin Hill"}]
["customer",11,"Mortons of Ranelagh","before",{"postal_code":"r",...},"after",{"postal_code":null}]
["customer",14,"Brooklodge Hotel","before",{"postal_code":"X",...},"after",{"postal_code":null}]
["customer",15,"Nourish - Sandymount","before",{"postal_code":"r",...},"after",{"postal_code":null}]
["customer",16,"The Delgany Grocer","before",{"postal_code":"x",...},"after",{"postal_code":null}]
["customer",17,"Lotts & Co Terenure","before",{"postal_code":"r",...},"after",{"postal_code":null}]
["customer",18,"Avoca Mount Usher","before",{"postal_code":"c",...},"after",{"postal_code":null}]
["customer",20,"Avoca Ballsbridge","before",{"postal_code":"x",...},"after",{"postal_code":null}]
["customer",21,"Avoca Kilmacanogue","before",{"postal_code":"x",...},"after",{"postal_code":null}]
["customer",22,"Cavistons","before",{"postal_code":"x",...},"after",{"postal_code":null}]
one-char postcodes left: 0
```
(Customer 10's address read `Kilmantin Hill,` at the model level, one comma — the plan's `,,` was probably the `full_address` join.)
`GET /products` as `office_test` (kernel request from tinker) → 200;
```
16L Bucket 16L 16.000 kg €72.00 3 Active …                          ← no /kg
Mossfield Mature Cheese … Whole Wheel Var. wt €/kg wheel 5.000 kg €20.00/kg 0 Active …
```
(The products index prints the variant under its product heading, so the text is "Whole Wheel … €20.00/kg" under "Mossfield Mature Cheese", not the picker label `Mature Cheese · Whole Wheel`.)
Existing orders keep their old `unit_price` (snapshot) — not touched.

### 6. Order form: say which run filled the delivery date (D22) — done
Changed: `app/Http/Controllers/OrderController.php` (`create()` builds `$customerRuns`), `resources/views/orders/create.blade.php` (`#deliveryDateHint`; `fillDeliveryDate()` now always ends in a new `updateDeliveryHint()`, which the date `change` listener also calls so the "— next run date filled in" tail drops once the operator types a date). Date default unchanged.
`tests/Feature/OrderCreateFormTest.php` (new; checked first — did not exist).

### 7. Delivery address reads as the override it is (D23) — done
Changed: `resources/views/orders/create.blade.php`, `resources/views/orders/edit.blade.php` (label, `rows="2"`, hint).

### 8. Weight-priced line totals say they are estimates (D24) — done
Changed: `resources/views/orders/create.blade.php` — `data-by-weight` / `data-weight` on all three option renderings; `updateLineTotal()` prints `~€100.00 · est. 5 kg` for by-weight lines; `updateOrderTotal()` prefixes Items and Total with `~` and un-hides `#orderEstimateNote` when any priced line is by-weight.

Check output (steps 6–8):
```
php artisan test --filter='OrderCreateFormTest|OrderItemEditTest|CustomerSpecialPriceTest'
  Tests:    31 deprecated (136 assertions)   → 0 failed
OrderCreateFormTest: 5 cases — run labels (day run + whole-week run), #deliveryDateHint, delivery-address sentence on create + edit,
  wheel option `data-price="100" … data-by-weight="1" data-weight="5"` + #orderEstimateNote, bottle option `data-by-weight="0" data-weight=""`.
```

### 9. Orders list: search, date range, auto-apply, tidy columns (D25) — done
Changed: `app/Http/Controllers/OrderController.php` (`applyOrderFilters()`; `index()` passes `$filters`; `show()`'s hand-rolled `foreach … where` replaced by it, `$listFilters` = its return), `resources/views/orders/index.blade.php` (search box first with debounced `requestSubmit` + caret-restore `x-init`; selects + `from`/`to` date inputs submit on change; button "Search"; Clear shows when `$filters` non-empty; `$rowFilters = $filters`; cancelled rows print a faint `—` instead of the payment tag (the `inv` link unchanged); Docket moved to the actions cell after View/Edit), `tests/Feature/OrdersIndexFilterTest.php` (new; checked first — did not exist).
Check output:
```
php artisan test --filter='DeliveryOverdueTest|LayoutFeedbackTest|OnlineOrdersVisibilityTest|UxCopyTest|OrdersIndexFilterTest'
  Tests:    31 deprecated (167 assertions)   → 0 failed (OrdersIndexFilterTest: 6 cases)
```

### 10. Customers list: search applies as you type (D26) — done
Changed: `resources/views/customers/index.blade.php` (debounced `requestSubmit` + caret restore on search; `onchange` submit on Status and Online account; button "Search"), `tests/Feature/CustomerFormTest.php` (case 10).
Check output:
```
php artisan test --filter=CustomerFormTest → Tests: 10 deprecated (38 assertions), 0 failed
git diff resources/views/customers/index.blade.php → hunks only at lines 18–45 (filter form) and 72 (step 4's email "—"); no change inside the customers.destroy form.
```

### 11. One Finance navigation (E29) — done
Changed: `app/View/FinanceNav.php` (new; checked first — `app/View` held only `Components/`), `resources/views/layouts/navigation.blade.php` (Finance group = `FinanceNav::items()`; `$icons` gains `layers`, `send`, `download`), `resources/views/invoicing/partials/_nav.blade.php` (`$tabs = FinanceNav::items()`), `tests/Feature/FinanceNavTest.php` (new).
Check output:
```
php artisan test --filter='FinanceNavTest|InvoicingAccessTest' → Tests: 13 deprecated (88 assertions), 0 failed
grep -c FinanceNav … → navigation.blade.php:1, _nav.blade.php:1
```
(The plan's `User::factory()->factory()` spelling doesn't exist; the state is `factoryWorker()`.)

### 12. Run sheet: the town, with the address on hover (F34) — done
Changed: `resources/views/chilled-runs/partials/_run-table.blade.php` (`$fullAddress` → `title` on `<td class="cust">` when non-blank; `.ad` prints `city` only, only when filled), `tests/Feature/ChilledRunTest.php` (new case `test_a_stop_shows_its_town_with_the_full_address_on_hover`).
Check output:
```
php artisan test --filter=ChilledRunTest → Tests: 75 deprecated (294 assertions), 0 failed (incl. both €-free tests)
```

### 13. "Pick", not "fulfil", on the order page and variant forms (C12 remainder) — done
Changed: `resources/views/orders/partials/allocation-items.blade.php` ("To pick"; "N allocated · N picked"; header "Picked"; three buttons "Pick" — dialogs unchanged), `resources/views/orders/show.blade.php:515`, `resources/views/products/variants/{create,edit}.blade.php` (both now "Weighed when picked (e.g. cheese wheels)." + "Enter one total weight when picking instead of per-unit …"), `resources/views/products/index.blade.php` + `resources/views/batches/partials/batch-card.blade.php` (`title="Weighed when picked"`), `tests/Feature/OrderShowAllocationInlineTest.php` (`assertSee('To pick')`). No ids, routes, JS or PHP renamed.
Check output:
```
php artisan test --filter='OrderShowAllocationInlineTest|ConfirmDialogTest|OrderFulfillmentWeightTest|FulfilmentUnitWeightsTest' → 30 deprecated (131 assertions), 0 failed
  (ConfirmDialogTest alone: 6 deprecated, 0 failed)
step-13 grep →
resources/views/components/order/item-row.blade.php:6:    slot (the fulfilment panel) spanning the full row beneath.
resources/views/orders/partials/allocation-items.blade.php:8:    update the order). Fully-fulfilled lines collapse to a one-line summary; the
resources/views/orders/partials/allocation-items.blade.php:9:    fulfilment panel (the only "card" on the page) is reserved for lines still
resources/views/orders/partials/allocation-items.blade.php:76:             fully fulfilled, or closed short). --}}
```
All four survivors are continuation lines of multi-line `{{-- … --}}` comments (opened on lines 2, 2 and 75 respectively), which the grep's `\{\{--` filter can't see. Blade comments are out of scope, so they stay — see Deviations. Note also the grep's `-i` + `Fulfillment` filter hides every "fulfillment" (double-l) line, so it could not have caught the two variant-form "at fulfillment" strings or the two `title="Weighed at fulfillment"`; they were changed anyway and `grep -rn 'at fulfil' resources/views` now finds nothing.

### 14. Restoring a backup resets document numbering — say so — done
Changed: `resources/views/backup/index.blade.php` (second sentence in the Restore description), `DEPLOYMENT.md` (§9 bullet + change-log row `2026-10-28`), `tests/Feature/BackupRestoreTest.php` (`->assertSee('returns to where it stood')`).
Check output:
```
php artisan test --filter=BackupRestoreTest → Tests: 19 deprecated (99 assertions), 0 failed
```

### 15. `PickingTest::batchItem()` makes a ready batch — done
Changed: `tests/Feature/PickingTest.php` — helper drops the ignored `ready_date` from `Batch::create()` and `forceFill`s it after create; the hand patches are removed from **four** tests, not one (see Deviations).
Check output:
```
php artisan test --filter=PickingTest → Tests: 27 deprecated (135 assertions), 0 failed — same 27 as before
grep -n ready_date tests/Feature/PickingTest.php
61:        // Batch::creating overwrites a cheese ready_date; set it after, so the stock is pickable.
62:        $batch->forceFill(['ready_date' => now()->subDay()->toDateString()])->save();
```

### 16. Docs — done
Changed: `CLAUDE.md` — Customers Key Fields (optional email unless Email delivery, nullable city/postal code, blank credit limit) + a "Form tidy" paragraph; Orders: a create-form bullet (run hint, `~` estimates, auto-apply list filters); Orders Master-Detail: six filters via `applyOrderFilters()`; Chilled Run Sheet: stop-cell town/hover bullet; Invoicing Views: `App\View\FinanceNav::items()`.
Check output:
```
grep -n "FinanceNav\|applyOrderFilters" CLAUDE.md → 488 (applyOrderFilters), 842 (FinanceNav)
```

### 17. The absent-select fallback must compare enum to enum (revision 2) — done
Changed: `app/Http/Controllers/CustomerController.php` (`use App\Enums\InvoiceDelivery;`; `rules()` resolves `$delivery` via `InvoiceDelivery::tryFrom((string) $request->input('invoice_delivery')) ?? $customer?->invoice_delivery ?? InvoiceDelivery::Email` and `Rule::requiredIf(fn () => $delivery === InvoiceDelivery::Email)`), `tests/Feature/CustomerFormTest.php` (two cases: `test_an_absent_delivery_select_on_edit_keeps_a_stored_email_preference`, `…_keeps_a_stored_print_preference`).
Test-first: with only the tests added, the email case failed exactly as the plan predicted:
```
FAILED  Tests\Feature\CustomerFormTest > an absent delivery select on edi…
Session is missing expected key [errors].
  at tests/Feature/CustomerFormTest.php:94  ->assertSessionHasErrors('email');
```
After the fix:
```
php artisan test --filter=CustomerFormTest
  Tests:    12 deprecated (44 assertions)        ← 12 passed, 0 failed
php artisan test
  Tests:    807 deprecated, 1 passed (5139 assertions)   ← 808 tests, 0 failed
./vendor/bin/pint --test app tests database
  PASS   ......................................................... 304 files
```
CLAUDE.md's "an absent select meaning the stored/default Email" sentence is now true as written; no doc change.

## Deviations

1. **Step 2 — the absent-select fallback on update.** *(Revision 2: the string-vs-enum defect the Planner found here is fixed in step 17.)* The plan's rule reads `($request->input('invoice_delivery') ?: 'email') === 'email'`. `rules()` uses `?: ($customer?->invoice_delivery ?? 'email')` instead: on create that is exactly the plan's rule (DB default email); on update an absent select already means "keep what is stored" (the existing `normalise()` unset), so the email requirement follows the stored preference rather than assuming Email. Both edit and create forms always post the select, so the browser path is unchanged; `CustomerFormTest` case 3 pins the create behaviour.
2. **Step 13 — the grep check can't print nothing.** Four survivors remain, all continuation lines of multi-line `{{-- … --}}` blade comments (`components/order/item-row.blade.php:6`, `orders/partials/allocation-items.blade.php:8,9,76`). Blade comments are explicitly out of scope, so they were left; the grep's `\{\{--` filter only sees a comment's first line. The same grep's case-insensitive `Fulfillment` exclusion also hides every double-l "fulfillment", so it would not have flagged the two variant-form strings or the two `title="Weighed at fulfillment"` — those were changed per the step text; `grep -rn 'at fulfil' resources/views` → nothing.
3. **Step 15 — four hand patches removed, not one.** Besides `test_the_pick_screen_names_the_real_batch_stock`, three more tests patched `ready_date` after the helper (`…weights refill…` at old line 273, `test_stepper_buttons_disable_at_their_limits`, `test_a_zero_nominal_weight_gives_no_check_threshold`). The step's own check ("`grep -n ready_date` → only the helper") required removing them all; they were redundant once the helper sets it. Same 27 tests, all green.
4. **Step 11 test** — the plan's factory user spelling `User::factory()->factory()` doesn't exist; the state is `factoryWorker()`.
5. **Step 4 test case 1/8** use `Customer::sole()` / a pending order respectively; case 8 also asserts the docket shows `Main St, Ireland`.

## Verification

Re-run in full after step 17 (revision 2).

1. `php artisan migrate:status | grep 2026_10_28_130000`
```
  2026_10_28_130000_make_customer_contact_fields_nullable ............ [3] Ran
```
2. `php artisan test`
```
  Tests:    807 deprecated, 1 passed (5139 assertions)
  Duration: 45.65s
```
→ **808 tests, 0 failed** (revision 1's 806 + the two step-17 cases). "deprecated" is the `PDO::MYSQL_ATTR_SSL_CA` notice; no FAIL lines.
3. `./vendor/bin/pint --test app tests database`
```
    PASS   ......................................................... 304 files
```
4. Step-3 grep → nothing (exit 1). Step-13 grep → the same four multi-line blade-comment continuation lines as revision 1 (`components/order/item-row.blade.php:6`, `orders/partials/allocation-items.blade.php:8,9,76`) — accepted in the review as Deviation 2.
5. tinker check (with `var_export` on the bool) → `1 false 20.00`.
6. `git status --short` — unchanged file list from revision 1 (step 17 touched two files already in it); see Files changed.

## Files changed

```
 M CLAUDE.md
 M DEPLOYMENT.md
 M app/Http/Controllers/CustomerController.php
 M app/Http/Controllers/OrderController.php
 M app/Models/Customer.php
 M docs/planImp/SMALL_FIXES.md                 ← pre-existing (Jon's)
 M docs/planImp/UX_REVIEW_2026-10-13.md        ← pre-existing (Jon's)
 M resources/views/backup/index.blade.php
 M resources/views/batches/partials/batch-card.blade.php
 M resources/views/chilled-runs/partials/_run-table.blade.php
 M resources/views/customers/create.blade.php
 M resources/views/customers/edit.blade.php
 M resources/views/customers/index.blade.php
 M resources/views/invoicing/partials/_nav.blade.php
 M resources/views/layouts/navigation.blade.php
 M resources/views/orders/create.blade.php
 M resources/views/orders/edit.blade.php
 M resources/views/orders/index.blade.php
 M resources/views/orders/partials/allocation-items.blade.php
 M resources/views/orders/show.blade.php
 M resources/views/products/index.blade.php
 M resources/views/products/variants/create.blade.php
 M resources/views/products/variants/edit.blade.php
 M tests/Feature/BackupRestoreTest.php
 M tests/Feature/ChilledRunTest.php
 M tests/Feature/OrderShowAllocationInlineTest.php
 M tests/Feature/PickingTest.php
 M tests/Feature/UxCopyTest.php
?? app/View/FinanceNav.php
?? database/migrations/2026_10_28_130000_make_customer_contact_fields_nullable.php
?? docs/planImp/implemented.md
?? docs/planImp/plan.md                        ← pre-existing (Planner's)
?? tests/Feature/CustomerFormTest.php
?? tests/Feature/FinanceNavTest.php
?? tests/Feature/OrderCreateFormTest.php
?? tests/Feature/OrdersIndexFilterTest.php
```
Dev DB: migration `2026_10_28_130000` ran; step 5 data edits (above). Nothing committed.

## Notes for Planner

- **The JS behaviour is not exercised by PHPUnit** — the run hint text, the `~€… · est. N kg` line totals, the `~` totals + note toggle, the debounced search and the caret restore are covered only by "the markup/attributes are there" assertions. The browser checks in the plan are the real test; I did not open a browser.
- **Data notes from step 5:** the 16L bucket was already €72.00 on dev (only `is_priced_by_weight` changed); Garlic & Basil wheel was a no-op; customer 10's stored address had one trailing comma, not two. Mature on dev has only a Whole Wheel variant (no Vacuum Pack) — the seeder creates both, so dev has drifted; not touched.
- **Customer `phone`/`country`** — `country` is still `required` and the tidy step strips trailing commas from it too; `phone` is not tidied (not in the plan's list).
- `tidyInput()` also converts `''` → `null` for `name`/`country`; the `ConvertEmptyStringsToNull` middleware already did that, so `required` still fires as before.
- **Orders search** trims the query and escapes `%`, `_` and `\`; a whitespace-only `q` is ignored (not added to `$filters`, so no `q=` chip).
- **Possible follow-up:** the plan's step-13 grep would be more useful with a multi-line-comment-aware check (e.g. strip `{{-- … --}}` with `perl -0pe` first) if it is reused in a later cycle.
- The orders search input autofocuses whenever `q` is set (by design, for the caret restore) — on a phone that would pop the keyboard on every filtered load. Worth a look in the browser check.
