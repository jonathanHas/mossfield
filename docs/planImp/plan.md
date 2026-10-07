# Delete guards: products, variants and customers refuse when history depends on them

Status: READY
Revision: 1
Planner: Fable 5.1
Date: 2026-10-07

## Goal

Deleting a product or a variant today destroys history: the controllers refuse
nothing and the schema cascades — `products → product_variants, batches →
batch_items → order_allocations, cheese_cutting_logs, cheese_conversion_logs,
stock_write_offs`, and `product_variants → order_items, customer_special_prices`.
One click on "Delete" under a product that has ever been sold removes lines
from past orders (their stored totals then disagree with their lines) and
every batch's traceability. Cycle 11 made the dialog *say* so; this cycle
makes the server **refuse**, on the pattern `Batch::deletionBlocker()` already
set (cycle 5): the model owns the rule, the page hides Delete and says why,
the controller refuses with a flash. The same for customers, where a customer
with no orders but a payment, invoice, credit note or statement currently hits
a database RESTRICT error instead of a sentence. It also clears the two copy
items cycle 11 deferred (short-close dialog promises; stepper total counting
short-closed units).

## Context

HEAD `1bd17f5`. Suite **699 passed, 0 failed**; any failure is real.
`pint --test app tests database routes config` clean. Nothing pushed or
deployed.

**Facts the steps rely on**

- **The pattern to copy**: `Batch::deletionBlocker(): ?string` +
  `dependentRecordCount(): int` + private `dependentCounts()`
  (`app/Models/Batch.php:220–285`) — reads `*_count` attributes from the
  loaded relation when the caller eager-loaded them (checked with
  `array_key_exists` on `getAttributes()`), else one `withCount` query.
  `BatchController::destroy()` (`:275–288`): `authorize`, refuse with the
  blocker as the `error` flash, else delete. The card
  (`batches/partials/batch-card.blade.php:174–190`): inside `@can('delete')`,
  `@if($deletionBlocker = …)` renders a muted `<span title="{{ blocker }}" data-delete-blocked>Can't delete — N records depend on it</span>`,
  else the Delete form. `BatchCreateTest:318` pins the text and
  `substr_count($html, 'data-delete-blocked')`.
- **Products**: `ProductController::destroy()` (`:107`) authorizes then
  deletes; `ProductVariantController::destroy()` (`:87`) **does not call
  `authorize` at all** (the route group blocks factory; add the call — CLAUDE.md
  → Roles requires it in every write action). `ProductPolicy` /
  `ProductVariantPolicy` are bare `BasePolicy` (office may delete).
  Relations: `Product::variants()`, `Product::batches()`;
  `ProductVariant::batchItems()` exists, **`orderItems()` and
  `specialPrices()` do not** (`CustomerSpecialPrice::productVariant()` is the
  only side). Loaders: `ProductController::index()` (`:23`,
  `Product::with(['variants' => orderBy name])` + `attachFreeStock`),
  `ProductController::show()` (`:60`), `BuildsProductList` (sidebar — **no
  Delete there**, no counts needed).
- **Delete buttons**: `products/index.blade.php:78–86` (product, inside
  `@can('delete', $product)`) and `:146–156` (variant, inside
  `@can('update', $product)`); `products/show.blade.php:152–160` (variant;
  the `data-confirm*` attributes sit on the **`<form>`**, not the button).
  `customers/index.blade.php:94–102` (shown only when
  `$customer->orders_count === 0`; `CustomerController::index()` `:15` is
  `Customer::withCount('orders')`). The customer **show** page has no Delete.
- **Customer FKs**: `orders.customer_id` **cascades** (the controller's
  orders check is the only guard); `payments`, `invoices`, `credit_notes`,
  `statements` are `restrictOnDelete` (→ a 500 today);
  `customer_special_prices` cascades (fine — it's the customer's own config);
  `document_emails.customer_id` nullOnDelete. Relations exist:
  `orders()`, `invoices()`, `payments()`, `creditNotes()`, `statements()`,
  `specialPrices()`.
- **Dialog bodies written in cycle 11** describe the cascade ("This deletes
  the product, its variants, and every batch and order line that uses
  them…", `products/index:83`, `:153`, `products/show:157`) and the
  customer rule ("A customer with orders can't be deleted — set them
  inactive instead.", `customers/index:100`). Once the server refuses, those
  sentences are false/moot and must change. `ConfirmDialogTest` scans titles
  and echoes — keep both.
- **Deferred copy (cycle 11 review, `SMALL_FIXES.md`)**: the Close short /
  Mark short / Can't finish bodies say "N units will be released back to
  stock" with N = `quantity_remaining`, but `OrderItem::closeShort()`
  releases only `quantity_allocated − quantity_fulfilled` (CLAUDE.md → Short
  Supply); the stepper's "N of M picked" sums `quantity_ordered` so a
  short-closed line's never-coming units sit in M
  (`orders/partials/status-stepper.blade.php:35–39`; `OrderItem::quantity_short`,
  `isShortClosed()` are the accessors to use — never the raw column).
- Tests to copy from: `BatchCreateTest` (blocker render + refusal),
  `CustomerSpecialPriceTest:338` (a `customers.destroy` call with filters),
  `ProductStockFigureTest` (product/variant/batch fixtures),
  `InvoicingTestCase` (`deliveredOrder()`, `issue()` — an invoice for the
  customer RESTRICT case; or insert a minimal `Payment` row directly).

## Constraints

- **The model owns the rule.** One `deletionBlocker()` per model; the view
  and the controller both read it. Never duplicate the condition in a blade
  or a controller `if`.
- **Refusal, not cascade.** A product/variant/customer with dependents is
  never deleted — the answer is "deactivate" (`is_active` exists on all
  three). Do **not** change any FK to RESTRICT in this cycle (a migration on
  live tables is a deploy decision — note it in `OPEN_QUESTIONS.md` instead,
  step 6).
- **No extra queries per row** on the index pages: counts ride the loader's
  `withCount`, read with `array_key_exists` (CLAUDE.md → the `??` trap).
- Dialog bodies stay titled and echo-free (`ConfirmDialogTest`).
- `./vendor/bin/pint`; no CSS/JS changes expected (no `npm run build`).
- **Do not commit.** Planner commits on acceptance. Nothing pushed/deployed.

## Out of scope

- Changing FK rules (`orders.customer_id` cascade, the product/variant
  cascades) — recorded as an open question only.
- A Delete on the customer **show** page, or on the products sidebar.
- Soft deletes / archiving products.
- Deleting users, runs, reasons, batches (already guarded or out of this
  cycle).

## Steps

### 1. `ProductVariant::deletionBlocker()`

Files: `app/Models/ProductVariant.php`, `tests/Feature/DeletionGuardTest.php` (new)

What:
- Add relations `orderItems(): HasMany` and `specialPrices(): HasMany`
  (`CustomerSpecialPrice`).
- `deletionBlocker(): ?string`, `dependentRecordCount(): int`, private
  `dependentCounts(): array{batch_items:int, order_lines:int, special_prices:int}`
  reading `batch_items_count` / `order_items_count` / `special_prices_count`
  from `getAttributes()` when all three are present, else one
  `loadCount([...])`. Message in the Batch voice:
  "{picker_label} can't be deleted — 3 batch items and 12 order lines depend
  on it, and deleting it would remove them from past batches and orders. Set
  the variant inactive instead." (list only non-zero reasons; singular/plural
  as `Batch` does; special prices read "N customer special prices").
- Tests (`DeletionGuardTest`): variant with a batch item → blocker names "1
  batch item"; with an order line → "1 order line"; with a special price →
  "1 customer special price"; bare variant → null; counts read from loaded
  attributes without a query (use `DB::enableQueryLog()` / `assertCount(0)`
  after `loadCount`).

Check: `php artisan test --filter=DeletionGuardTest` → pass.

### 2. `Product::deletionBlocker()`

Files: `app/Models/Product.php`, `tests/Feature/DeletionGuardTest.php`

What: a product is blocked when it has **any batch** (`batches_count`) or
**any variant that is blocked** (sum of the variants' dependent counts — read
from the loaded `variants` when each carries the three `*_count`s, else
`variants()->withCount([...])->get()`). Message: "{short_name} can't be
deleted — 4 batches and 12 order lines depend on it, and deleting it would
destroy that traceability. Set the product inactive instead." A product with
variants but no batches/lines/prices is deletable (its variants are config
and cascade with it). `dependentRecordCount()` = batches + Σ variant counts.
Tests: product with a batch → blocked naming "1 batch"; product whose only
dependency is a variant's order line → blocked; product with two bare
variants → null.

Check: `php artisan test --filter=DeletionGuardTest` → pass.

### 3. Controllers refuse; pages hide Delete and say why

Files: `app/Http/Controllers/ProductController.php`,
`app/Http/Controllers/ProductVariantController.php`,
`resources/views/products/index.blade.php`, `resources/views/products/show.blade.php`,
`tests/Feature/DeletionGuardTest.php`, `tests/Feature/ConfirmDialogTest.php` (no change expected — run it)

What:
- `ProductController::destroy()`: after `authorize`, `if ($blocker =
  $product->deletionBlocker()) return redirect()->route('products.show', $product)->with('error', $blocker);`.
- `ProductVariantController::destroy()`: add `$this->authorize('delete', $variant)`
  first, then the same refusal redirecting to `products.show`.
- Loaders: `ProductController::index()` → `Product::withCount('batches')->with(['variants' => fn ($q) => $q->orderBy('name')->withCount(['batchItems', 'orderItems', 'specialPrices'])])`;
  `show()` (`:63`) uses `$product->load(['variants', 'batches' => limit 10, …])`
  — change the `variants` entry to `'variants' => fn ($q) => $q->withCount([...])`
  and add `$product->loadCount('batches')` (the loaded `batches` relation is
  **capped at 10**, so never count it — read `batches_count`).
  `attachFreeStock` unchanged. Verify the query count on `/products` does not
  grow per product (one `withCount` is a subselect, not a query per row).
- Views: replace each Delete form with the batch-card construct —
  `@if($blocker = $x->deletionBlocker())` → `<span class="text-[12px]" style="color: var(--muted);" title="{{ $blocker }}" data-delete-blocked>Can't delete — N {{ Str::plural('record', N) }} {{ N === 1 ? 'depends' : 'depend' }} on it</span>`
  else the form. Three places: product (index), variant (index), variant
  (show). Keep the `@can` wrappers as they are.
- **Dialog bodies** (now that a blocked delete never reaches the dialog):
  product → "The product and its variants are removed. This can't be
  undone."; variant (both) → "The variant is removed. This can't be undone."
- Tests: office `DELETE products/{blocked}` → redirect to `products.show`,
  `error` flash equals the blocker, product still exists, its batch still
  exists; `DELETE products/{bare}` → deleted; variant likewise (blocked by an
  order line; bare one deleted; **factory → 403** on the variant route via
  the new `authorize`); `/products` renders `data-delete-blocked` once for a
  blocked product and shows Delete for a bare one; `/products/{product}` the
  same for its variants; `ConfirmDialogTest` still green.

Check: `php artisan test --filter="DeletionGuardTest|ConfirmDialogTest|ProductStockFigureTest"` → pass.
Browser as `office_test`: `/products` → Organic Milk shows "Can't delete — N
records depend on it" (hover shows the sentence); a freshly created
"Probe product" shows Delete → delete it → gone, flash. Console clean.

### 4. `Customer::deletionBlocker()`

Files: `app/Models/Customer.php`, `app/Http/Controllers/CustomerController.php`,
`resources/views/customers/index.blade.php`, `tests/Feature/DeletionGuardTest.php`

What:
- `deletionBlocker()` / `dependentRecordCount()` / `dependentCounts()` over
  `orders`, `invoices`, `payments`, `creditNotes`, `statements`
  (`*_count` attributes when loaded, else one `loadCount`). Message: "{name}
  can't be deleted — 3 orders and 1 payment depend on them. Set the customer
  inactive instead." Special prices are **not** a blocker (they're the
  customer's own config and cascade).
- `CustomerController::index()` → `withCount(['orders', 'invoices', 'payments', 'creditNotes', 'statements'])`
  (the `orders_count` column in the table keeps working).
- `destroy()`: replace the `orders()->count()` check with the blocker (same
  redirect to `customers.index` with filters, `error` = blocker).
- View: replace `@if($customer->orders_count === 0)` with the blocker
  construct (`data-delete-blocked` span vs form). Body → "The customer and
  their special prices are removed. This can't be undone."
- Tests: customer with a payment and no orders → refused with the flash (not
  a 500), still exists; with an invoice → refused; bare customer → deleted
  (existing `CustomerSpecialPriceTest:338` still passes); index shows
  `data-delete-blocked` for the blocked one.

Check: `php artisan test --filter="DeletionGuardTest|CustomerSpecialPriceTest"` → pass.

### 5. The two deferred copy items

Files: `resources/views/orders/partials/allocation-items.blade.php`,
`resources/views/picking/item.blade.php`,
`resources/views/orders/partials/status-stepper.blade.php`,
`tests/Feature/OrderTransitionsTest.php`, `docs/planImp/SMALL_FIXES.md`

What:
- Short-close bodies (office "Close short", picking "Mark short" and "Can't
  finish") → "The {{ n }} unpicked {{ unit(s) }} won't be supplied or billed;
  any stock reserved for them goes back to free stock." (keeps the number,
  stops promising a release that `closeShort()` doesn't make).
- Stepper total: `M = Σ quantity_ordered − Σ quantity_short` over lines
  where `isShortClosed()`, i.e. `orderItems->sum(fn ($i) => $i->isShortClosed() ? $i->quantity_fulfilled : $i->quantity_ordered)`.
  Test: a `preparing` order with lines 10/4 and a short-closed 6/0 renders
  "4 of 10 picked".
- Tick both items in `SMALL_FIXES.md` with a *Done 2026-10-07* line.

Check: `php artisan test --filter="OrderTransitionsTest|OrderShortSupplyTest|ConfirmDialogTest"` → pass.

### 6. Docs

Files: `CLAUDE.md`, `docs/planImp/OPEN_QUESTIONS.md`, `docs/planImp/WORK_IN_PROGRESS.md`

What: `CLAUDE.md` → the `Batch::deletionBlocker()` bullet (Feedback,
fallbacks, dialogs and PDFs) becomes the general rule: "**`deletionBlocker()`
is the one place a delete rule lives** — `Batch`, `Product`, `ProductVariant`,
`Customer`. The page hides Delete and shows "Can't delete — N records depend
on it" (`data-delete-blocked`, counts from the loader's `withCount`), the
controller refuses with the sentence as the `error` flash. Deactivate, don't
delete. `DeletionGuardTest`." Plus one line under Product Management / Customer
CRUD pointing at it. `OPEN_QUESTIONS.md` → new item: "FKs still cascade
(`orders.customer_id`, `products → batches`, `product_variants → order_items`);
the app now refuses, but a raw delete would still cascade — switch to RESTRICT
in a migration at the next deploy window?" `WORK_IN_PROGRESS.md` → *Recently
Completed: Delete guards (2026-10-07)*.

Check: `grep -n "DeletionGuardTest" CLAUDE.md` → hit.

## Verification

1. `php artisan test` → **0 failed**; record the count (699 + new).
2. `./vendor/bin/pint --test app tests database routes config` → clean.
3. `php artisan route:list | wc -l` → unchanged (166).
4. Query count on `/products` as office (e.g. `DB::enableQueryLog()` in
   tinker or a test with `assertQueryCount`-style counting): not more than
   before + 1.
5. `git status --short` → the four models, three controllers, five views,
   the new test + the touched tests, four docs. No migrations, no
   `public/build`.
6. Browser as `office_test`, console clean: `/products` blocked vs bare
   (create and delete a probe product + variant — leave none behind);
   `/customers` → a customer with orders shows "Can't delete — …"; the
   short-close dialog body on a preparing order reads the new sentence.
   Record any dev-DB rows left in `implemented.md` (there should be none).

## Risks

- **`Product::dependentCounts()` must not count the loaded `batches`
  relation** — `show()` limits it to 10. Use `batches_count` when present
  (`array_key_exists`), else `$this->batches()->count()`.
- **`order_items` for cancelled orders** still count as history (the lines
  are kept on cancel) — correct: they're the record. Don't filter them out.
- **Customer blocker wording** must not claim orders are the only blocker
  (cycle 11's body did) — the body is now consequence-only.
- **Short-close body** appears in three places with two different `n`
  expressions (`$orderItem->quantity_remaining` on the office page, `$needed`
  on picking) — keep each page's own variable.

## Review

(Planner fills in after `implemented.md` is DONE.)
