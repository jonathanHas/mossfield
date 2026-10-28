# Cycle 15 — the 2026-10-13 leftovers, two cycle-14 carry-overs, and the dev-DB data fixes

Status: ACCEPTED
Revision: 2
Planner: Fable 5.1
Date: 2026-10-28

**Revision 2 (2026-10-28, after the Planner's review):** steps 1–16 are done and accepted
as implemented — do not redo them. Only **step 17** is new: the absent-select fallback in
`CustomerController::rules()` compares a string to an enum, so on an update it never fires.
Update `implemented.md` in place (a step-17 section, fresh Verification output) and set
`Plan revision: 2`.

## Goal

Close the last findings of the 2026-10-13 office walk-through (`UX_REVIEW_2026-10-13.md`,
Jon's decisions of 2026-10-28 in its *Decisions for the leftovers* block): the customer
form stops forcing junk into fields the office doesn't have (D20, D21); the order form says
why the delivery date was filled in, what the delivery-address box is for, and that a
weight-priced line total is an estimate (D22–D24); the Orders and Customers lists get a
search box, a date range and filters that apply as you change them (D25, D26); Finance has
one navigation instead of two that disagree (E29); the run sheet prints the town rather than
the whole address (F34); and the order page says "pick" where it still said "fulfil" (the
C12 remainder). Plus two carry-overs from the cycle 14 review — a "restoring resets
document numbering" sentence on `/backup` and in `DEPLOYMENT.md`, and `PickingTest::batchItem()`
giving its cheese batch a real `ready_date` — and the four dev-DB data fixes Jon decided
(Avoca rename; 16L bucket flat; Garlic & Basil and Mature wheels €/kg).

Blade, copy, one additive migration, one small nav helper, controller validation and
filters. **No stock, allocation or weight arithmetic changes.**

## Context

- HEAD `559c1b2`. Working tree: `docs/planImp/SMALL_FIXES.md` and
  `docs/planImp/UX_REVIEW_2026-10-13.md` are modified (Jon's 2026-10-28 decisions) — leave
  them. Baseline (2026-10-28): `php artisan test` → **780 tests, 0 failed** (PHPUnit prints
  "779 deprecated, 1 passed" — the `PDO::MYSQL_ATTR_SSL_CA` notice, not failures; 5016
  assertions); `./vendor/bin/pint --test app tests database` → PASS. Any failure is real.
- **Customer form.** `CustomerController::store()` and `update()` carry the same 20-line
  rule set twice; `email`, `address`, `city`, `postal_code`, `credit_limit` are `required`.
  The columns are **NOT NULL** (`email` varchar, `city` varchar, `postal_code` varchar,
  `address` text, `credit_limit` decimal default 0) — the original
  `2025_07_28_124807_create_customers_table` — so making the fields optional needs a
  migration, not just a rule change. `city`/`postal_code`/`address` are encrypted at rest
  (`App\Casts\EncryptedNullable`, which already passes `null` through). `email` is
  plaintext with a plain index (unique dropped in `2026_06_29_153344`), so NULLs are fine.
  `CustomerController::withDuplicateEmailWarning()` queries `where('email', $customer->email)`
  — with a null email that would match every other email-less customer; guard it.
  `Customer::billingEmail()` already returns null for a blank email, and `DispatchService`
  already records "no address" rather than failing. `Customer::getFullAddressAttribute()`
  joins all four parts blindly ("Kilmantin Hill,, , , Ireland"); `InvoiceService::billToAddress()`
  and the statement PDF already filter blanks — make `full_address` do the same (the docket
  and dispatch confirmation read it). Views that print email/city already fall back to "—"
  on `customers/show`; `customers/index` line 72 prints a bare `{{ $customer->email }}`.
  `UxCopyTest::test_a_zero_credit_limit_reads_no_limit` pins the form hint text
  `0 = no limit — the app warns, never blocks, when an order would exceed it.` on both forms.
  The create form's `invoice_delivery` select always posts (`required`), default `email`;
  the controller treats an absent/blank value as "leave the DB default" (`email`).
  Dev data today (`tinker`): postal codes `x`, `r`, `c`, `X` on ids 11, 14–18, 20–22;
  address of id 10 ends `Hill,,`.
- **Order create form** (`resources/views/orders/create.blade.php`, `OrderController::create()`).
  `customerRunDates` (`[customer_id => Y-m-d]`) feeds `fillDeliveryDate()`, which fills an
  empty field (or one still holding the previous auto-fill) with the customer's next run
  date. **D22's decision: keep that**; add only a hint naming the run. `DeliveryRun` has
  `day_of_week` (1–7 or null for whole-week runs), `dateFor()` and a `day_label` accessor
  ("Tuesday" / "Week of"). Each product `<option>` carries `data-price` (=
  `ProductVariant::estimated_unit_price`, the per-unit estimate: `base_price × weight_kg`
  for weight-priced variants) and `data-picker`; the option is rendered **three times** —
  the `old('items')` branch, the fresh first row, and the JS `createOrderItem()` template.
  `customerPrices[customer][variant]` is the same per-unit estimate at the special price.
  The totals panel has `#orderItemsTotal`, `#orderDelivery`, `#orderTotal`.
  `OrderItemEditTest::test_the_create_form_knows_each_customers_next_run_date_and_special_labels`
  asserts `customerRunDates` and the `data-picker` attribute — keep both.
  The delivery-address textarea (`rows="3"`, bare label) is on both `create` and `edit`.
- **Orders list.** `OrderController::index()` filters `status`, `payment_status`,
  `customer_id` and paginates by `order_date desc`; `show()` replays the **same three keys**
  into the sibling pane (`$listFilters`, printed as `k=v` chips and re-threaded into every
  sibling link). `orders/index.blade.php` builds `$rowFilters` from the same three keys so a
  row click keeps the filter. The Docket link sits inside the Status cell; the Payment cell
  prints `ucfirst(payment_status)` even on cancelled orders. The only auto-submit pattern in
  the app is `onchange="this.form.submit()"` on `invoicing/payments/create.blade.php`.
  `resources/js/submit-once.js` ignores GET forms, so a filter form may submit repeatedly.
  Alpine is global (`resources/js/app.js`), so `x-on:input.debounce.500ms` is available.
  Tests touching `orders.index`: `DeliveryOverdueTest`, `LayoutFeedbackTest`,
  `OnlineOrdersVisibilityTest`, `UxCopyTest`.
- **Customers list** (`customers/index.blade.php`, `CustomerController::index()`): search
  (name/email LIKE) + `is_active` (+ online filter behind the Mossorders flag), a Filter
  button. **The per-row Delete form (lines 94–102) belongs to parked cycle 12 — do not
  touch it.**
- **Finance navigation.** Sidebar (`resources/views/layouts/navigation.blade.php` lines
  30–39) lists 5 items: Invoices, To Invoice, Payments, Statements, Overdue. The finance tab
  bar (`resources/views/invoicing/partials/_nav.blade.php`) lists 8 in a different order:
  Invoices, To invoice, Consolidated, Payments, Overdue, Statements, Sent, Export. Route
  names (`routes/web.php` 240–270): `invoicing.invoices.index`, `invoicing.uninvoiced.index`,
  `invoicing.consolidated.create`, `invoicing.payments.index`, `invoicing.overdue.index`,
  `invoicing.statements.index`, `invoicing.emails.index`, `invoicing.export.index`. The
  sidebar's `$icons` map is a name → list of SVG paths; the `<nav>` is `overflow-auto`, so
  three more items scroll rather than clip. `app/View/` holds only `Components/` — a small
  helper class fits there. `InvoicingAccessTest` asserts only the word "Finance".
- **Run sheet.** `chilled-runs/partials/_run-table.blade.php` lines 258–260 print
  `address, city, postal_code` under the customer name in `.cust .ad` (CSS at
  `resources/css/app.css:559`). `ChilledRunTest` fixtures use `address 'Main St'`,
  `city 'Wicklow'`, `postal_code 'A67'`. The sheet is €-free (a test pins it) — no change there.
- **"Fulfil" copy left after cycle 13** (user-facing; PHP identifiers, route names and
  blade comments stay): `orders/partials/allocation-items.blade.php` — "Needs fulfilment"
  (line 67), "N allocated · N fulfilled" (70), the `Fulfilled` table header (93), three
  `Fulfil` buttons (152, 181, 197; their dialogs already say "Record pick");
  `orders/show.blade.php:515` "until the new line is fulfilled";
  `products/variants/create.blade.php:82,96`, `products/variants/edit.blade.php:83,97`
  ("Requires weighing at fulfillment…", "Enter one total weight at fulfilment…");
  `products/index.blade.php:127` and `batches/partials/batch-card.blade.php:228`
  (`title="Weighed at fulfillment"`). `OrderShowAllocationInlineTest:128` asserts
  'Needs fulfilment'. The sidebar group heading "Fulfillment" is a section name, not a
  state — leave it.
- **Backup page** `resources/views/backup/index.blade.php` — the Restore panel's description
  (lines 98–103). `BackupRestoreTest:100` GETs the page and asserts only `assertOk()`.
  `DEPLOYMENT.md` §9 "Known quirks" is a bullet list; the change log table is at the end.
- **`PickingTest::batchItem()`** (lines 53–70) passes `ready_date` to `Batch::create()`, but
  `Batch::creating` (app/Models/Batch.php:42–44) overwrites it for cheese with
  `production_date + maturation_days` (90 days in that fixture), so every helper batch is
  "not ready" and `PickingController::batchOptionsFor()` (which filters on
  `isAvailableForAllocation()` → `isReadyToSell()`) lists nothing; picks still succeed
  because `pickFromBatchItem()` guards stock, not readiness. One test
  (`test_the_pick_screen_names_the_real_batch_stock`, line 300) patches `ready_date` after
  create by hand — that is the fix, moved into the helper.
- **Dev DB rows for the data fixes** (ids as of today): customer 20 "Avoa Ballsbridge";
  variant 14 "16L Bucket" €72.00 with `is_priced_by_weight = 1`; variant 7 Garlic & Basil
  "Whole Wheel" is **already** €16.00/kg on dev (the €40 flat lives on production — Jon's
  edit); variant 15 Mature "Whole Wheel" €55.00 flat, `weight_kg` 5.000. Match by name in
  the tinker step, not by id, and print before/after.

## Constraints

- **Stock arithmetic is untouched.** Nothing here reads or writes `quantity_remaining`,
  reservations, `quantity_maturing` or weights. The invariants in
  `TESTING_AND_FIXES.md` → *Invariants worth keeping* (free stock = `remaining − (allocated −
  fulfilled) − maturing` at all eight sites; `on hand + dispatched + cut + converted +
  written off = produced`) must still hold — the full suite proves it.
- **Prices on existing orders are snapshots** (`order_items.unit_price`): the data fixes
  change variants and a customer name only; no order is repriced.
- The migration is additive (NOT NULL → nullable on three columns) and reversible; no
  backfill. `BackupService` is unaffected (same columns, same encryption list).
- No route is added, renamed or removed. New query-string keys only (`q`, `from`, `to`).
- Mossorders import paths (`ImportOnlineOrders`, `OnlineOrdersController`) are untouched.
- Keep the chilled-run sheet €-free and the picking blades factory-safe (`see-financials`).
- Alpine: never an `@` shorthand that is also a Blade directive (`x-on:…`); nullable values
  behind `x-show` need `?.`.
- **Do not commit, push or deploy.** Jon commits after review (explicit `git add` paths;
  the two dirty docs are his). Run `php artisan migrate` on the dev DB (step 1) — that is
  expected and should be recorded.

## Out of scope

- Finding 1 (stock hint on the order form — expected behaviour), 4 (send the invoice from
  the order page / Invoices-list row actions — deferred), 5 and the dashboard's "online
  sync has never completed" (behind `MOSSORDERS_UI_ENABLED`).
- The per-row Delete on `customers/index`, product and variant Delete links, and any
  delete guard — cycle 16 resumes parked cycle 12.
- Changing the delivery-date default (D22 keeps today / the run date) or the default
  payment terms (D21 keeps Immediate).
- Making Street address optional. Only email, city and postal code become optional.
- A delivery-date hint on the **edit** order form (it loads no run data; the date is set).
- Production data edits — Jon makes them through Products and Customers.
- Renaming the "Fulfillment" sidebar group, route names (`order-allocations.fulfill`),
  PHP identifiers or blade comments containing "fulfil".
- Any change to `DocumentMailer`, `DispatchService` or the not-emailed queue for
  email-less customers — they already treat a null `billingEmail()` as "no address".

## Steps

### 1. Migration: email, city and postal code become nullable
Files: `database/migrations/2026_10_28_130000_make_customer_contact_fields_nullable.php` (new)
What: `Schema::table('customers', …)` with `$table->string('email')->nullable()->change();`
and the same for `city` and `postal_code` (keep `string`, i.e. varchar(255) — they hold
ciphertext today and fit). `down()`: set `''` on NULL rows for the three columns first, then
`->nullable(false)->change()`. Laravel 12 needs no doctrine/dbal for `change()`.
Check: `php artisan migrate` runs the one migration;
`php artisan tinker --execute='foreach (["email","city","postal_code"] as $c) echo $c, ": ", DB::selectOne("SELECT IS_NULLABLE n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \"customers\" AND COLUMN_NAME = ?", [$c])->n, PHP_EOL;'`
prints `YES` three times. `php artisan test --filter='BackupRestoreTest|ResetTradingDataTest'` green.

### 2. Customer validation: one rule set, optional fields, trimmed input
Files: `app/Http/Controllers/CustomerController.php`
What:
- Extract the duplicated rules into `private function rules(Request $request, ?Customer $customer = null): array`
  and the post-validation coercions (`is_active`, `requires_reference`, `send_statements`,
  the blank-select `unset`, `normalizeChargePercent`) into
  `private function normalise(array $validated, Request $request): array`. `store()` and
  `update()` both call them; the only difference is the `mossorders_user_id` unique rule's
  ignore id.
- Rule changes: `'email' => ['nullable', 'email', 'max:255', Rule::requiredIf(fn () => ($request->input('invoice_delivery') ?: 'email') === 'email')]`
  (an absent select means the DB default, `email`, so a blank email is still refused then);
  custom message `'email.required' => 'An email address is needed while Invoice delivery is Email — or choose Print / post or No invoice sent.'`;
  `'city' => 'nullable|string|max:255'`, `'postal_code' => 'nullable|string|max:255'`,
  `'credit_limit' => 'nullable|numeric|min:0'`. `address` stays `required|string`.
- Before validating (`$request->merge([...])`), tidy `name`, `email`, `address`, `city`,
  `postal_code`, `country`: `trim`, then strip trailing commas and whitespace
  (`preg_replace('/[\s,]+$/u', '', $value)`), then `''` → `null`. So "Kilmantin Hill,," is
  stored as "Kilmantin Hill" and a blank postal code as NULL, never `''`.
- After validating: `credit_limit` null/blank → `0` (column is NOT NULL, 0 = no limit).
- `withDuplicateEmailWarning()`: return early when `blank($customer->email)`.
Check: `php artisan test --filter='CustomerSpecialPriceTest|DeliveryChargeTest|OnlineOrdersVisibilityTest|UxCopyTest'`
green (they post the full form). `grep -c "'required|email" app/Http/Controllers/CustomerController.php` → 0.

### 3. Customer forms: optional fields read as optional
Files: `resources/views/customers/create.blade.php`, `resources/views/customers/edit.blade.php`, `tests/Feature/UxCopyTest.php`
What (both forms, identically):
- Email: drop `required` and the red asterisk; hint under the input:
  `Needed while Invoice delivery is Email. Print and no-invoice customers can leave it blank.`
- City and Postal code: drop `required` and the asterisks. Street address and Country keep theirs.
- Credit limit: drop `required` and the asterisk; create form value `old('credit_limit', '')`;
  edit form value `old('credit_limit', (float) $customer->credit_limit > 0 ? $customer->credit_limit : '')`
  (a stored 0 shows as blank — it means no limit); hint becomes
  `Leave blank for no limit — the app warns, never blocks, when an order would exceed it.`
- `UxCopyTest::test_a_zero_credit_limit_reads_no_limit`: change `$hint` to the new sentence.
Check: `php artisan test --filter=UxCopyTest` green;
`grep -nE 'id="(email|city|postal_code|credit_limit)"[^>]*required' resources/views/customers/*.blade.php` → nothing.

### 4. `full_address` without empty commas; index shows "—"; `CustomerFormTest`
Files: `app/Models/Customer.php`, `resources/views/customers/index.blade.php`, `tests/Feature/CustomerFormTest.php` (new)
What: `getFullAddressAttribute()` → `collect([address, city, postal_code, country])->filter(fn ($p) => filled($p))->implode(', ')`.
`customers/index` line 72: `{{ $customer->email ?: '—' }}`.
New `CustomerFormTest` (office user, `RefreshDatabase`), cases:
1. store with `invoice_delivery = print`, blank email/city/postal_code → 302 to `customers.show`;
   the row has NULL `email`, `city`, `postal_code` (read via the model — encrypted columns).
2. store with `invoice_delivery = email` and blank email → session error on `email` containing
   "Invoice delivery is Email"; nothing created.
3. store with `invoice_delivery` **absent** from the post and blank email → refused the same way (DB default is email).
4. store with `address = 'Kilmantin Hill,,'`, `city = ' Wicklow , '`, `postal_code = ''` →
   stored `Kilmantin Hill`, `Wicklow`, NULL.
5. store with blank `credit_limit` → stored `0.00`; `customers.show` reads "No limit".
6. update path: blanking city/postal_code on an existing customer stores NULL.
7. two email-less customers → no "Heads up: this email is also used by" warning in the session.
8. `full_address` of a customer with NULL city/postal_code contains no `, ,` and no leading/trailing comma;
   GET `orders.docket` (the HTML page, not `?download=1` — the PDF path 500s on this box's PCRE JIT) for an order of that customer is OK and `assertDontSee(', ,')`.
9. `customers.edit` and `customers.show` for a NULL-email customer render OK; the index shows "—" in Contact.
Check: `php artisan test --filter=CustomerFormTest` → 9 passed.

### 5. Dev-DB data fixes (tinker)
Files: none (data only). Record the before/after output in `implemented.md`.
What: one tinker run, matching by name, printing each row before and after:
- customer "Avoa Ballsbridge" → `name = 'Avoca Ballsbridge'`;
- variant "16L Bucket" (yoghurt) → `is_priced_by_weight = false`, `base_price = 72.00`;
- Whole Wheel of the product whose name contains "Garlic" → `is_priced_by_weight = true`, `base_price = 16.00`
  (a no-op on dev — say so if the before/after match);
- Whole Wheel of the `conversion_only` product (Mature) → `is_priced_by_weight = true`, `base_price = 20.00`;
- every customer whose trimmed `postal_code` is one character long → `postal_code = null`
  (the `x`/`r`/`c`/`X` placeholders D20 found); every customer whose `address` ends in
  commas/whitespace → stripped with the same regex as step 2. Encrypted columns: do this
  through the model (`$customer->update([...])`), never raw SQL.
Check: the tinker output shows the four rows at their new values; `GET /products` (as office)
lists `Mature Cheese · Whole Wheel` at `€20.00/kg` and the bucket at `€72.00` with no `/kg`;
`Customer::whereNotNull('postal_code')->get()->filter(fn ($c) => strlen(trim($c->postal_code)) <= 1)->count()` → 0.
Existing orders keep their old `unit_price` (snapshot) — note it, don't "fix" it.

### 6. Order form: say which run filled the delivery date (D22)
Files: `app/Http/Controllers/OrderController.php` (`create()`), `resources/views/orders/create.blade.php`
What: beside `customerRunDates`, build `$customerRuns` = `[customer_id => label]` for
customers on a run: `"{$run->name} · every {$run->day_label}"` for day runs
(e.g. `Dublin Tue · every Tuesday`), `"{$run->name} · any day that week"` for whole-week
runs. Pass it to the view. Under the Delivery date input add
`<p id="deliveryDateHint" class="text-[12px] mt-1" style="color: var(--muted);"></p>`.
In the script: `const customerRuns = @json($customerRuns);` and have `fillDeliveryDate()`
(and the customer `change` handler path that already calls it) set the hint: the run label
when the customer has one — append ` — next run date filled in` while the field holds the
auto-filled date — `No delivery run — pick a date.` for a customer without one, empty when
no customer is selected. The date default itself does not change.
Check: new `tests/Feature/OrderCreateFormTest.php` (office user; fixture: a `DeliveryRun`
`day_of_week 2` named "Tuesday run", one customer on it, one not):
`assertViewHas('customerRuns', fn ($r) => $r[$onRun->id] === 'Tuesday run · every Tuesday' && ! isset($r[$offRun->id]))`
and `assertSee('id="deliveryDateHint"', false)`. `OrderItemEditTest` still green.

### 7. Delivery address reads as the override it is (D23)
Files: `resources/views/orders/create.blade.php`, `resources/views/orders/edit.blade.php`
What: label `Delivery address (if different)`, `rows="2"`, and under the textarea
`<p class="text-[12px] mt-1" style="color: var(--muted);">Leave blank to deliver to the customer's address.</p>`.
Check: `OrderCreateFormTest` asserts the sentence on `orders.create` and on `orders.edit`
for a pending order.

### 8. Weight-priced line totals say they are estimates (D24)
Files: `resources/views/orders/create.blade.php`
What: in all three option renderings add `data-by-weight="{{ $variant->is_priced_by_weight ? 1 : 0 }}"`
and `data-weight="{{ $variant->is_priced_by_weight ? (float) $variant->weight_kg : '' }}"`.
`updateLineTotal()`: when the selected option is by-weight, show
`~€160.00 · est. 10 kg` (kg = `data-weight × quantity`, trimmed to at most 3 decimals);
otherwise unchanged. `updateOrderTotal()`: if any priced line is by-weight, prefix the Items
and Total figures with `~` and show `<p id="orderEstimateNote">` under the totals:
`~ Estimated from nominal weights — the invoice uses the picked weight.` (hidden otherwise).
Special prices need no change (`customerPrices` is already the per-unit estimate).
Check: `OrderCreateFormTest`: a priced-by-weight cheese variant (`weight_kg 5`) →
`assertSee('data-by-weight="1"', false)`, `assertSee('data-weight="5"', false)`,
`assertSee('id="orderEstimateNote"', false)`; a milk variant → its option has `data-by-weight="0"`.
`CustomerSpecialPriceTest` and `OrderItemEditTest` still green (`data-picker`, embedded JSON).

### 9. Orders list: search, date range, auto-apply, tidy columns (D25)
Files: `app/Http/Controllers/OrderController.php`, `resources/views/orders/index.blade.php`, `tests/Feature/OrdersIndexFilterTest.php` (new)
What:
- `private function applyOrderFilters(Request $request, Builder $query): array` — reads
  `status`, `payment_status`, `customer_id`, `q`, `from`, `to`; applies them; returns the
  filters it actually applied (keys with non-blank values; a `from`/`to` that
  `Carbon::parse` cannot read is dropped silently, never a validation redirect on a GET).
  `q`: `order_number LIKE %q%` OR `customer_reference LIKE %q%` OR
  `whereHas('customer', name LIKE %q%)`, with `%`/`_` in the input escaped. `from`/`to`:
  `whereDate('delivery_date', '>=' / '<=')`. `index()` and `show()` both use it —
  `show()`'s `foreach ($listFilters …) where(...)` goes; `$listFilters` is the returned
  array, so the sibling pane shows the same set as the list (the chips print `q=…` as they
  print `status=…`).
- View, filter form: a search box first (`name="q"`, label `Search`, placeholder
  `Order no., customer or their PO ref`, `x-data` with
  `x-on:input.debounce.500ms="$el.form.requestSubmit()"` and an `x-init` that, when the box
  has a value, focuses it with the caret at the end — the reload otherwise drops the
  cursor); the three selects get `onchange="this.form.requestSubmit()"`; two `type="date"`
  inputs `from` / `to` labelled `Delivery from` / `to`, also `onchange` submit; the button
  reads `Search` (Enter still works, no-JS still works); Clear shows when any of the six
  keys is set. `$rowFilters` uses the controller's `$filters` (pass it to the view) so the
  six keys thread into the sibling pane.
- Payment cell: on a `cancelled` order print `—` (faint) instead of the payment tag; the
  `inv` link logic stays as is.
- Docket: move the link out of the Status cell into the actions cell after View/Edit
  (`View · Edit · Docket`).
Check: `OrdersIndexFilterTest` (office user; two customers "Barnhill Stores" and "Avoca
Rathcoole", order A `customer_reference 'PO-77'` delivery 2026-11-03 `ready`, order B
delivery 2026-11-10 `cancelled`):
1. `?q=PO-77` lists A only; `?q=Avoca` lists B only; `?q=<A's order_number>` lists A only;
2. `?from=2026-11-05` lists B only; `?to=2026-11-05` lists A only; `?from=nonsense` lists both (ignored);
3. `?status=cancelled` → `assertDontSee('mf-tag-warn">Pending', false)` (no payment tag on a cancelled row);
4. the ready order's row: `assertSeeInOrder(['>View</a>', '>Docket</a>'], false)` (Docket after View; a pending order has no Docket link);
5. `orders.show` for A with `?q=Avoca` → `assertViewHas('listFilters', ['q' => 'Avoca'])` and `orderList` contains B (and A, prepended);
6. the page contains `requestSubmit()` and `x-on:input.debounce`.
Then `php artisan test --filter='DeliveryOverdueTest|LayoutFeedbackTest|OnlineOrdersVisibilityTest|UxCopyTest|OrdersIndexFilterTest'` green.

### 10. Customers list: search applies as you type (D26, the half that isn't cycle 12)
Files: `resources/views/customers/index.blade.php`
What: the same treatment as step 9 on the search input (debounced `requestSubmit`, caret
restored) and `onchange` submit on the Status (and, when shown, Online account) select;
button text `Search`. **Leave the per-row Delete form exactly as it is** (cycle 12/16).
Check: `CustomerFormTest` case 10: `GET customers.index?search=Avo` → OK, `assertSee('x-on:input.debounce', false)`,
`assertSee('requestSubmit()', false)`; `git diff resources/views/customers/index.blade.php`
shows no change inside the `customers.destroy` form.

### 11. One Finance navigation (E29)
Files: `app/View/FinanceNav.php` (new), `resources/views/layouts/navigation.blade.php`, `resources/views/invoicing/partials/_nav.blade.php`, `tests/Feature/FinanceNavTest.php` (new)
What: `final class FinanceNav { public static function items(): array }` returning eight
`['id','label','route','match','icon']` rows in the tab bar's order and with its labels:
Invoices (`invoicing.invoices.index`, `invoicing.invoices.*`, `invoice`) · To invoice
(`invoicing.uninvoiced.index`, `invoicing.uninvoiced.*`, `euro`) · Consolidated
(`invoicing.consolidated.create`, `invoicing.consolidated.*`, `layers`) · Payments
(`invoicing.payments.index`, `invoicing.payments.*`, `euro`) · Overdue
(`invoicing.overdue.index`, `invoicing.overdue.*`, `alert`) · Statements
(`invoicing.statements.index`, `invoicing.statements.*`, `statement`) · Sent
(`invoicing.emails.index`, `invoicing.emails.*`, `send`) · Export (`invoicing.export.index`,
`invoicing.export.*`, `download`). The sidebar's Finance group becomes
`['label' => 'Finance', 'items' => \App\View\FinanceNav::items()]` and `$icons` gains
`layers`, `send` and `download` (simple 24×24 stroke paths — a three-layer stack, a paper
plane, a tray with a down arrow; the Implementer owns the paths). `_nav.blade.php` sets
`$tabs = \App\View\FinanceNav::items()` and renders as before (label/route/match).
Check: `FinanceNavTest`: (1) `FinanceNav::items()` has 8 rows, unique ids, and
`Route::has($row['route'])` for each; (2) office `GET dashboard` → `assertSee(route(...))`
for `invoicing.consolidated.create`, `invoicing.emails.index`, `invoicing.export.index` and
`assertSee('To invoice')`; (3) office `GET invoicing.invoices.index` → the page contains all
eight `route()` URLs; (4) factory `GET dashboard` → `assertDontSee(route('invoicing.emails.index'))`.
`InvoicingAccessTest` green. `grep -c FinanceNav resources/views/layouts/navigation.blade.php resources/views/invoicing/partials/_nav.blade.php` → 1 and 1.

### 12. Run sheet: the town, with the address on hover (F34)
Files: `resources/views/chilled-runs/partials/_run-table.blade.php`, `tests/Feature/ChilledRunTest.php`
What: build `$fullAddress = collect([$customer->address, $customer->city, $customer->postal_code])->filter()->implode(', ')`;
put `title="{{ $fullAddress }}"` on the `<td class="cust">` (omit when blank); the `.ad`
line prints `{{ $customer->city }}` only, and only when the customer has a city. No CSS change.
Check: new `ChilledRunTest` case (office, a stop with `Main St / Wicklow / A67` and an order):
`assertSee('<div class="ad">Wicklow</div>', false)`, `assertSee('title="Main St, Wicklow, A67"', false)`,
`assertDontSee('>Main St', false)`; the existing €-free test still green.

### 13. "Pick", not "fulfil", on the order page and variant forms (C12 remainder)
Files: `resources/views/orders/partials/allocation-items.blade.php`, `resources/views/orders/show.blade.php`, `resources/views/products/variants/create.blade.php`, `resources/views/products/variants/edit.blade.php`, `resources/views/products/index.blade.php`, `resources/views/batches/partials/batch-card.blade.php`, `tests/Feature/OrderShowAllocationInlineTest.php`
What: "Needs fulfilment" → **To pick**; "{allocated} allocated · {fulfilled} fulfilled" →
"{allocated} allocated · {fulfilled} picked"; the `Fulfilled` column header → **Picked**; the
three `Fulfil` buttons → **Pick** (their `data-confirm-title`/`-label` "Record pick" stay);
`orders/show.blade.php:515` "…until the new line is picked."; variant forms: "Weighed when
picked (e.g. cheese wheels)." and "Enter one total weight when picking instead of per-unit
(e.g. vacuum packs). Only used when Variable weight is on."; the two `title="Weighed at
fulfillment"` → `title="Weighed when picked"`. `OrderShowAllocationInlineTest:128`
→ `assertSee('To pick')`. Do not rename form ids (`fulfil-{id}`), routes, JS or PHP.
Check: `php artisan test --filter='OrderShowAllocationInlineTest|ConfirmDialogTest|OrderFulfillmentWeightTest|FulfilmentUnitWeightsTest'` green;
`grep -rniE 'fulfil' resources/views --include='*.blade.php' | grep -viE 'quantity_fulfilled|fulfilled_at|weight_fulfilled|fulfilled_total|isFullyFulfilled|fulfil-\{|form="fulfil|id="fulfil|\$fulfil|fulfillPct|order-allocations\.(un)?fulfill|\{\{--|^\S+:\s*//|Fulfillment|\$fulfillItems'`
prints **nothing** (every survivor is an identifier, a route, a comment or the group heading).

### 14. Restoring a backup resets document numbering — say so (cycle 14 carry-over)
Files: `resources/views/backup/index.blade.php`, `DEPLOYMENT.md`, `tests/Feature/BackupRestoreTest.php`
What: in the Restore panel's description add a second sentence:
`Document numbering (invoices, credit notes, statements) returns to where it stood when the backup was taken, so anything issued since would reuse a number — restore before issuing, not after.`
`DEPLOYMENT.md` §9 Known quirks, new bullet: **Restoring a backup rewinds document
numbering.** `/backup` → Restore replaces `document_sequences` with the backup's, so the next
invoice number is the backup's next, not today's; an invoice issued between the backup and
the restore would be re-numbered on re-issue. Treat a restore as a whole-system rewind and
check `/invoicing` afterwards. Change-log row `2026-10-28 | Note that a restore rewinds document numbering (§9)`.
`BackupRestoreTest:100` → `->assertOk()->assertSee('returns to where it stood')`.
Check: `php artisan test --filter=BackupRestoreTest` green.

### 15. `PickingTest::batchItem()` makes a ready batch (cycle 14 carry-over)
Files: `tests/Feature/PickingTest.php`
What: in `batchItem()`, after `Batch::create([...])`, `$batch->forceFill(['ready_date' => now()->subDay()->toDateString()])->save();`
with a one-line comment (`Batch::creating` overwrites a cheese `ready_date`; set it after).
Drop the now-redundant `->batch->update(['ready_date' => …])` and its comment in
`test_the_pick_screen_names_the_real_batch_stock` (the helper's item is the same batch).
Check: `php artisan test --filter=PickingTest` → all green, same count;
`grep -n ready_date tests/Feature/PickingTest.php` → only the helper.

### 16. Docs
Files: `CLAUDE.md`
What (short, in place): Customers *Key Fields* — `email` is optional unless `invoice_delivery`
is Email (not unique); `city`/`postal_code` optional (NULL, never `''`); `credit_limit`
blank = 0 = no limit; trailing commas/whitespace are stripped on save; `full_address` skips
blank parts. Orders Master-Detail — `show()` accepts the same six filters as `index()`
(`status`, `payment_status`, `customer_id`, `q`, `from`, `to`) via `applyOrderFilters()`.
Invoicing *Views* — the Finance group and the finance tab bar both render
`App\View\FinanceNav::items()`. Chilled Run Sheet — rows show the town, full address on
hover. Order create form — run hint and `~` estimates for weight-priced lines.
Check: `grep -n "FinanceNav\|applyOrderFilters" CLAUDE.md` → both present.

### 17. The absent-select fallback must compare enum to enum (revision 2)
Files: `app/Http/Controllers/CustomerController.php`, `tests/Feature/CustomerFormTest.php`
Why: `Customer::$casts` maps `invoice_delivery` to `App\Enums\InvoiceDelivery` (a
string-backed enum: `Email = 'email'`, `Print`, `None`). Revision 1's `rules()` builds
`$delivery = $request->input('invoice_delivery') ?: ($customer?->invoice_delivery ?? 'email')`
and tests `$delivery === 'email'`. On create the fallback is the string `'email'` and the
rule fires (case 3 proves it). On **update with the select absent**, the fallback is the
stored value — an enum object — so `=== 'email'` is `false` and an Email-delivery customer
can be saved with a blank email. The edit form always posts the select, so the browser path
is fine today; the hole is on any other PUT, and `CLAUDE.md` now documents the fallback as
working. Proof from tinker on dev: `var_dump(("" ?: ($c?->invoice_delivery ?? "email")) === "email")`
→ `bool(false)` for an Email customer.
What: resolve to the enum on both sides, the way `Customer::wantsEmailedInvoices()` does:
```php
$delivery = InvoiceDelivery::tryFrom((string) $request->input('invoice_delivery'))
    ?? $customer?->invoice_delivery
    ?? InvoiceDelivery::Email;
// …
Rule::requiredIf(fn () => $delivery === InvoiceDelivery::Email),
```
(`tryFrom('')` is null, so a blank select still falls through to the stored value; an
invalid value is still refused by the `in:` rule.) Add `use App\Enums\InvoiceDelivery;`.
Two new `CustomerFormTest` cases, both `PUT customers.update` with `invoice_delivery`
**unset** from the posted form and `email => ''`:
- an existing customer stored with `invoice_delivery => 'email'` (and an email) →
  `assertSessionHasErrors('email')`, the message contains `Invoice delivery is Email`,
  and `$customer->refresh()->email` is unchanged;
- an existing customer stored with `invoice_delivery => 'print'` → `assertSessionHasNoErrors()`
  and `$customer->refresh()->email` is `null`.
Check: `php artisan test --filter=CustomerFormTest` → 12 passed; `php artisan test` →
**808 tests, 0 failed**; `./vendor/bin/pint --test app tests database` → PASS.

## Verification

Run in order at the end; paste real output.

1. `php artisan migrate:status | grep 2026_10_28_130000` → `Ran`.
2. `php artisan test` → **0 failed**; expect about 780 + 25 tests (new: `CustomerFormTest` ×10,
   `OrderCreateFormTest` ×4–5, `OrdersIndexFilterTest` ×6, `FinanceNavTest` ×4, one
   `ChilledRunTest` case). Record the exact count.
3. `./vendor/bin/pint --test app tests database` → PASS.
4. The step-13 grep → nothing; the step-3 grep → nothing.
5. `php artisan tinker --execute='echo App\Models\Customer::where("name","Avoca Ballsbridge")->count(), " ", App\Models\ProductVariant::where("name","16L Bucket")->value("is_priced_by_weight"), " ", App\Models\ProductVariant::whereHas("product", fn ($q) => $q->where("conversion_only", true))->where("name","Whole Wheel")->value("base_price"), PHP_EOL;'`
   → `1 0 20.00`.
6. `git status --short` — list it; the two pre-existing doc modifications are Jon's.

Browser checks for Jon after review (exercise the action, not just the render):
- `/customers/create`: save a Print customer with no email, city or postal code → lands on
  the customer page; switch Invoice delivery to Email and save blank → the email error names
  the setting.
- `/orders/create`: pick a customer on a run → the hint under Delivery date names the run;
  add a Mature wheel → the line reads `~€100.00 · est. 5 kg` and the totals carry `~`.
- `/orders`: type part of a customer name → the list narrows without pressing anything;
  pick a status → same; a cancelled row shows no payment tag; Docket is in the right-hand cell.
- Sidebar → Finance shows eight items in the same order as the finance tab bar.
- `/chilled-runs`: a stop shows its town; hovering the name shows the full address.
- `/backup`: the Restore panel mentions document numbering.

## Risks

- **`Rule::requiredIf` with `nullable`** — `required*` rules are implicit and run before
  `nullable` short-circuits, so a null email is still refused when delivery is Email.
  `CustomerFormTest` case 2/3 prove it; if the Implementer sees the rule not firing, the
  cause is the rule order, not the migration.
- **Null email downstream.** The duplicate-email warning is the one site that queried by
  email (guarded in step 2). `billingEmail()`, `OrderDocket` and the dispatch confirmation
  already tolerate a blank. A per-order customer on *Email* delivery can't be saved without
  one, so the not-emailed queue doesn't gain a row it can never send.
- **Debounced search reloads the page.** The caret-restore `x-init` keeps typing fluid; if
  it feels jumpy in the browser, the debounce is the knob (500 ms), not the design.
- **`show()`'s sibling query now honours `q`/`from`/`to`** — a row clicked from a searched
  list keeps the search, as it keeps a status filter today. The chips print `q=…` raw.
- **`data-weight` formatting**: `(float) '5.000'` renders `5`; the test asserts `data-weight="5"`.
- **Eight Finance items** lengthen the sidebar; the `<nav>` already scrolls (`overflow-auto`).
- **Step 5 touches dev data only.** Production copies are Jon's; the Garlic & Basil update is
  a no-op on dev and the report should say so rather than invent a change.
- **Down migration**: `nullable(false)` would fail on NULL rows, hence the `''` backfill in
  `down()`; nobody is expected to run it.

## Review

### Revision 1 — reviewed 2026-10-28 (Planner, Fable 5.1): one fix to redo, see step 17

Read `implemented.md` to the end, then the whole `git diff` plus the four new test files,
the migration and `FinanceNav`. Re-ran everything myself: `php artisan test` → **806 tests,
0 failed** (5133 assertions; "805 deprecated, 1 passed" is the SSL_CA notice); pint PASS on
304 files; `migrate:status` shows `2026_10_28_130000` as `[3] Ran`; `information_schema`
reports `email`/`city`/`postal_code` as `YES` nullable with `customers_email_index` intact;
the step-3 grep prints nothing; the step-13 grep prints only the four blade-comment
continuation lines; the dev-data probe prints `1 false 20.00` and zero one-char postcodes.

**Criteria, step by step.**
1. Migration — pass. `change()` kept the index; `down()` backfills `''` first.
2. Validation — pass with one defect: `rules()`, `messages()`, `tidyInput()`, `normalise()`
   are as specified; the duplicate-email warning returns early on a blank email;
   `credit_limit` blank → 0. **Fail:** the absent-select fallback (Deviation 1) compares the
   stored enum to the string `'email'` — see step 17.
3. Forms — pass. No `required` on the four fields, hints as written, edit form shows a
   stored 0 as blank, `UxCopyTest` updated.
4. `full_address` / index "—" / `CustomerFormTest` — pass (10 cases, incl. the docket
   via the HTML page).
5. Dev data — pass. Before/after recorded; Avoca renamed; bucket flat at €72; Garlic & Basil
   a no-op as predicted; Mature wheel €20/kg; nine junk postcodes NULL; "Kilmantin Hill,"
   stripped. Through the models, no raw SQL.
6. Run hint — pass. `customerRuns` built over the already-eager-loaded `deliveryRun`;
   `fillDeliveryDate()` is the same decision inverted, with `updateDeliveryHint()` after it
   and on the date's `change`. Date default untouched.
7. Delivery address copy — pass on create and edit.
8. `~` estimates — pass. `data-by-weight`/`data-weight` on all three renderings;
   `isByWeight()` guards the empty option; the note toggles via `hidden`.
9. Orders list — pass. `applyOrderFilters()` escapes `\ % _`, drops an unreadable date,
   returns only applied keys; `show()` uses it; `$rowFilters = $filters`; cancelled rows
   print "—" and keep the `inv` link; Docket is after View/Edit; six-case test.
10. Customers list — pass; the Delete form is untouched (hunks at 18–45 and 72 only).
11. Finance nav — pass. Eight rows in tab-bar order, three new icon paths, both renderers
    read `FinanceNav::items()`, four-case test, `InvoicingAccessTest` green.
12. Run sheet — pass. Town only in `.ad`, full address in `title`, no CSS change, still €-free.
13. Pick wording — pass. No ids, routes, JS or PHP renamed; `grep 'at fulfil'` finds nothing.
14. Backup sentence + DEPLOYMENT §9 + change-log row — pass; the test pins the sentence.
15. `PickingTest::batchItem()` — pass; `forceFill()->save()` after create, same 27 tests.
16. CLAUDE.md — pass, except that the Customers sentence "an absent select meaning the
    stored/default Email" becomes true only once step 17 lands (no doc change needed then).

**Deviations.**
1. Absent-select fallback reads the stored preference — **accepted in intent, rejected as
   written**: the intent (keep what is stored on update) is better than the plan's rule, but
   the comparison is string-vs-enum and never fires on update. Fixed in step 17.
2. Step-13 grep survivors are multi-line blade comments — **accepted**; blade comments are
   out of scope and the grep was the plan's blind spot, not the work's.
3. Four `ready_date` hand patches removed, not one — **accepted**; the step's own check
   required it and the suite count is unchanged.
4. `User::factory()->factoryWorker()` — **accepted**; the plan's spelling was wrong.
5. `Customer::sole()` / a pending order / the docket asserting `Main St, Ireland` —
   **accepted**.

**Notes for Planner.**
- JS behaviour is only attribute-asserted — **accepted**; that is what PHPUnit can do here.
  The browser checks under *Verification* are for Jon after acceptance.
- Dev data: bucket already €72, G&B no-op, one trailing comma not two, Mature has no Vacuum
  Pack on dev — **noted, no action**; dev drift from the seeder is not this cycle's concern.
- `country` stays required and is tidied; `phone` not tidied — **accepted** as specified.
- `tidyInput()` nulls a blank `name`/`country` before `required` — **accepted**; same outcome
  as the `ConvertEmptyStringsToNull` middleware.
- Orders search trims and escapes; whitespace-only `q` is ignored — **accepted**.
- A comment-aware grep for a future cycle — **deferred**; noted for the next copy sweep.
- The search box autofocuses on every filtered load (phone keyboard) — **deferred to Jon's
  browser check**; if it bites, the fix is to skip the `x-init` focus below `md`, a one-line
  follow-up, not a revision.

**Verdict:** not accepted yet. Revision 2 adds step 17 only. Implementer: run it, update
`implemented.md`, paste the fresh suite count; the Planner re-reviews step 17 and the
suite, then accepts.

### Revision 2 — reviewed 2026-10-28 (Planner, Fable 5.1): ACCEPTED

Read the step-17 section of `implemented.md`, the refreshed Verification and Notes, and the
diff of the two files. `rules()` now resolves `$delivery` as `InvoiceDelivery::tryFrom((string)
input) ?? $customer?->invoice_delivery ?? InvoiceDelivery::Email` and compares enum to enum;
the two test cases are as specified (stored Email → `email` error naming the setting, email
untouched; stored Print → no error, email null). The Implementer ran them test-first and the
Email case failed before the fix, as predicted.

Re-ran everything myself: `CustomerFormTest` → 12 passed; `php artisan test` → **808 tests,
0 failed** (5139 assertions); pint PASS on 304 files; `git status` lists the same 36 paths
as revision 1. A direct probe of `rules()` with a stored-Email customer and no
`invoice_delivery` input now yields a `RequiredIf` that stringifies to `required`, which is
the case revision 1 got wrong. An invalid posted value (`tryFrom` → null) falls back to the
stored preference but is refused by the `in:` rule anyway, so nothing slips through there.

Steps 1–16: unchanged since revision 1, accepted there. Step 17: **pass**. CLAUDE.md's
"an absent select meaning the stored/default Email" sentence is now true as written.

**Verdict: ACCEPTED.** Written up in `TESTING_AND_FIXES.md` → 2026-10-28 (cycle 15);
`RESUME.md` updated. Jon: archive both files to
`docs/planImp/archive/2026-10-28-leftovers/`, run the browser checks under *Verification*,
commit with explicit `git add` paths and deploy with `deploy/bin/ship-jon` (the
`2026_10_28_130000` migration must run on `mossserv`).
