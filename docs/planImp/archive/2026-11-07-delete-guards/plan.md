# Cycle 17 — Delete guards: products, variants and customers refuse when history depends on them

Status: ACCEPTED
Revision: 1
Planner: Fable 5.1
Date: 2026-11-07 (accepted 2026-11-07)

Read `docs/planImp/planimp.md` first. The Implementer writes
`docs/planImp/implemented.md`; it does not edit this file.

This is the parked cycle 12 (`docs/planImp/parked/2026-10-10-delete-guards/`),
restarted from scratch: its WIP branch was never pushed and is not on this
clone, so **none of that code exists here** (`deletionBlocker()` is in
`Batch` only; there is no `DeletionGuardTest`). The old `implemented.md` is
still worth reading — its six Deviations were right and are now the spec
below. Its step 5 (short-close dialog copy, stepper total) was done in
cycle 13 and is dropped.

## Goal

Deleting a product or a variant today destroys history: the controllers
refuse nothing and the schema cascades — `products → product_variants,
batches → batch_items → order_allocations, cheese_cutting_logs,
cheese_conversion_logs, stock_write_offs`, and `product_variants →
order_items, batch_items, customer_special_prices`. One **Delete** under a
product that has ever been sold removes lines from past orders (their stored
totals then disagree with their lines) and every batch's traceability. The
dialog says so; this cycle makes the server **refuse**, on the pattern
`Batch::deletionBlocker()` set in cycle 5: the model owns the rule, the page
greys Delete and says why on hover, the controller refuses with a flash. The
same for customers, where a customer with no orders but a payment, invoice,
credit note or statement today hits a database RESTRICT error (a 500)
instead of a sentence. Deactivate, don't delete.

## Context

**Baseline.** HEAD `0ddb381`, tree clean, production on the same commit.
**824 tests, 0 failed** (5,736 assertions); `pint --test app tests` clean;
`php artisan route:list | wc -l` → 169.

**The pattern to copy** — `Batch::deletionBlocker(): ?string`,
`dependentRecordCount(): int`, private `dependentCounts()`
(`app/Models/Batch.php:215-285`): reads `*_count` attributes from the loaded
relation when the caller eager-loaded them (`array_key_exists` on
`getAttributes()` — never `??`, which re-queries on NULL), else one
`withCount` query. Sentence: "Batch X can't be deleted — 3 order allocations
and 1 write-off record depend on it, and deleting it would destroy that
traceability. Set its status to … instead." `BatchController::destroy()`:
`authorize`, refuse with the blocker as the `error` flash, else delete. The
card (`batches/partials/batch-card.blade.php:178-193`), inside
`@can('delete')`:

```blade
@if($deletionBlocker = $batch->deletionBlocker())
    @php $dependents = $batch->dependentRecordCount(); @endphp
    <span class="mf-link" style="color: var(--faint); cursor: not-allowed;" aria-disabled="true" data-delete-blocked
          title="Can't delete — {{ $dependents }} {{ Str::plural('record', $dependents) }} {{ $dependents === 1 ? 'depends' : 'depend' }} on it. {{ $deletionBlocker }}">Delete</span>
@else
    <form …> … data-confirm-title="Delete batch?" data-confirm="Batch X and its items are removed. This can't be undone." …
@endif
```

`BatchCreateTest:316-319` pins the title text and
`substr_count($html, 'data-delete-blocked')`; `:324-331` is the query-log
helper for "no per-row query". The guide already describes this behaviour
for batches (`docs/guide/production.md:61`: "greyed out; point at it to see
why") — the three new screens should read the same way.

**Products.** `ProductController::index()` (`:18-36`):
`Product::with(['variants' => orderBy name])`, then
`ProductVariant::attachFreeStock(...)`. `show()` (`:60-75`):
`$product->load(['variants', 'batches' => orderByDesc, limit 10,
'batches.batchItems'])` — **the loaded `batches` is capped at 10, never
count it**. `destroy()` (`:107-118`): authorize, delete image, delete.
`ProductVariantController::destroy()` (`:87-97`) **has no `authorize()`**
(the route group blocks factory; CLAUDE.md → Roles requires the call in
every write action). `ProductPolicy` / `ProductVariantPolicy` are bare
`BasePolicy` (office may delete). Relations: `Product::variants()` (`:45`),
`Product::batches()` (`:50`), `ProductVariant::batchItems()` (`:55`);
**`ProductVariant::orderItems()` and `specialPrices()` do not exist**
(`CustomerSpecialPrice::productVariant()` is the only side). Labels:
`ProductVariant::picker_label` ("Farmhouse Cheese · Whole Wheel", reads
`$variant->product`), `Product::short_name`. `BuildsProductList` (the
sidebar) has no Delete — no counts needed there.

**Delete buttons today.** `products/index.blade.php:80-88` (product, inside
`@can('delete', $product)`, body "This deletes the product, its variants,
and every batch and order line that uses them. It can't be undone.") and
`:151-161` (variant, inside `@can('update', $product)`, body "This deletes
the variant, its stock in every batch, and every order line for it…");
`products/show.blade.php:156-165` (variant; the `data-confirm*` attributes
sit on the **`<form>`**, not the button). `customers/index.blade.php:99-107`
(shown only when `$customer->orders_count === 0`; body "…A customer with
orders can't be deleted — set them inactive instead.";
`CustomerController::index()` `:17` is `Customer::withCount('orders')`).
The customer show page has no Delete. Once the server refuses, these bodies
are false or moot and change to consequence-only; `ConfirmDialogTest` scans
titles and echoes — keep both.

**Customers.** `CustomerController::destroy()` (`:239-255`) checks
`orders()->count()` only and redirects to `customers.index` with the
`search`/`is_active` filters. **No action in `CustomerController` calls
`authorize()`** — `index/create/store/show/edit/update/destroy` rely on the
`role:admin,office` group alone (`CustomerPolicy extends BasePolicy {}`).
FKs: `orders.customer_id` **cascades** (the controller's check is the only
guard); `invoices`, `payments`, `credit_notes`, `statements` are
`restrictOnDelete` (→ 500 today); `customer_special_prices` cascades
(fine — the customer's own config); `document_emails.customer_id`
nullOnDelete. Relations exist: `orders()` (`:60`), `specialPrices()`
(`:70`), `invoices()` (`:155`), `payments()`, `creditNotes()`,
`statements()`.

**Variant route scoping.** `Route::resource('products.variants',
…)->except(['index','show'])` (`routes/web.php:200`) is not `scoped()`:
`DELETE /products/1/variants/9` with variant 9 under product 2 deletes
variant 9 and redirects to product 1. `Product::variants()` exists, so
`->scoped()` makes Laravel resolve the child through it (404 otherwise).
`AuthorizationBoundaryTest::buildFixtures()` binds `{product}` and
`{variant}` — check its variant belongs to its product before relying on
the test staying green.

**Guide.** Three pictures show these rows and change (a greyed Delete
appears on blocked rows): `products-index`, `product-page`,
`customers-list` (`docs/guide/screenshots.json:241,356,368`, all shot as
office with blurs on the € figures). Prose: `docs/guide/customers.md:13`
("**Delete** is offered only for a customer with no orders") must change;
`docs/guide/stock.md` says nothing about deleting a product or variant —
one sentence in the Products section, in the voice of `production.md:61`.
The 44-picture set otherwise stays; `GUIDE_FRESH` is not needed (the demo
milk batch expires 2026-11-17).

**Tests to copy from.** `BatchCreateTest` (blocker render + refusal +
query log), `CustomerSpecialPriceTest:338` (a `customers.destroy` call with
filters — must keep passing), `ProductStockFigureTest` (product/variant/
batch fixtures), `FloorPricesTest` (a small two-product `setUp`),
`InvoicingTestCase` (`deliveredOrder()`, `issue()` for an invoice; or
insert a minimal `Payment` row directly for the RESTRICT case).

## Constraints

- **The model owns the rule.** One `deletionBlocker()` per model; the view
  and the controller both read it. Never duplicate the condition in a blade
  or a controller `if`.
- **Refusal, not cascade.** A product, variant or customer with dependents
  is never deleted — the answer is "set inactive" (`is_active` exists on
  all three). **No FK changes, no migrations** in this cycle — a migration on
  live tables is a deploy decision; record it in `OPEN_QUESTIONS.md` (step 6).
- **No extra queries per row** on `/products`, `/products/{product}` or
  `/customers`: counts ride the loader's `withCount`, read with
  `array_key_exists` (the `??` trap, CLAUDE.md → Mature Conversion).
- Dialog bodies stay titled and echo-free (`ConfirmDialogTest`); the greyed
  Delete follows the batch card exactly (same classes, `aria-disabled`,
  `data-delete-blocked`, title = "Can't delete — N records depend on it. "
  + the sentence).
- `./vendor/bin/pint`; no CSS/JS changes expected (no `npm run build`).
- Dev box only. The browser check may create a probe product, variant and
  customer and must delete them again — leave no rows behind; say so in
  `implemented.md`. No other state changes; nothing on production.
- No commit, no deploy (Jon ships). The Planner does not edit application
  code; the Implementer does not edit this plan. Every departure goes in
  `implemented.md` → Deviations.

## Out of scope

- Changing FK rules (`orders.customer_id` cascade, the product/variant
  cascades) — an open question only.
- A Delete on the customer show page or on the products sidebar.
- Soft deletes / archiving.
- Deleting users, runs, reasons, batches (already guarded or elsewhere).
- Anything else in `SMALL_FIXES.md`.

## Steps

### 0. Baseline
Check: `php artisan test` → 824 tests, 0 failed; `./vendor/bin/pint --test
app tests` clean; `php artisan route:list | wc -l` → 169; `git status
--short` → only `?? docs/planImp/plan.md`; HEAD `0ddb381`.

### 1. `ProductVariant::deletionBlocker()`
Files: `app/Models/ProductVariant.php`, `tests/Feature/DeletionGuardTest.php` (new)
What:
- Relations `orderItems(): HasMany` (`OrderItem`) and `specialPrices():
  HasMany` (`CustomerSpecialPrice`).
- `deletionBlocker(): ?string`, `dependentRecordCount(): int`, and
  **public** `dependentCounts(): array{batch_items:int, order_lines:int,
  special_prices:int}` (public so `Product` can sum it — step 2). Counts
  from `batch_items_count` / `order_items_count` / `special_prices_count`
  when all three are in `getAttributes()`, else one `loadCount([...])`.
- Sentence, Batch voice: "{picker_label} can't be deleted — 3 batch items
  and 12 order lines depend on it, and deleting it would remove them from
  past batches and orders. Set the variant inactive instead." Non-zero
  reasons only ("N batch item(s)", "N order line(s)", "N customer special
  price(s)"), joined by " and "; verb and pronoun follow the total ("1 …
  depends on it … remove it"). When the **only** reason is special prices,
  drop " from past batches and orders" — a special price is config, not
  history.
- Tests: bare variant → null / 0; one batch item → the exact sentence; two
  order lines → "2 order lines depend on it", count 2; one special price →
  "…remove it." with no "past batches"; all three → named together; counts
  from a `withCount` load → **0 queries** (query log).
Check: `php artisan test --filter=DeletionGuardTest` → pass.

### 2. `Product::deletionBlocker()`
Files: `app/Models/Product.php`, `tests/Feature/DeletionGuardTest.php`
What: `deletionBlocker()`, `dependentRecordCount()`, private
`dependentCounts(): array{batches:int, order_lines:int, special_prices:int}`.
- `batches` = `batches_count` when in `getAttributes()`, else
  `batches()->count()` — **never the loaded `batches` relation** (capped at
  10 on the show page).
- `order_lines` / `special_prices` = Σ over the variants'
  `dependentCounts()`: the loaded `variants` when each carries the three
  `*_count`s, else one `variants()->withCount([...])->get()`.
- **`dependentRecordCount()` = batches + order lines + special prices** —
  not the variants' batch items too: they live inside the product's batches
  and would be counted twice, and "N records" must match the sentence.
- A product whose variants are bare → null (variants are config and cascade
  with it).
- Sentence: "{short_name} can't be deleted — 4 batches and 12 order lines
  depend on it, and deleting it would destroy that traceability. Set the
  product inactive instead."
- Tests: bare variants → null / 0; one batch (with an item) → the exact
  sentence, count **1**; a second variant's order line only → "1 order line
  depends on it"; a `withCount` load (product `batches` + the variants'
  three counts) → **0 queries**.
Check: `php artisan test --filter=DeletionGuardTest` → pass.

### 3. Products: controllers refuse, pages grey Delete, route scoped
Files: `app/Http/Controllers/ProductController.php`,
`app/Http/Controllers/ProductVariantController.php`, `routes/web.php`,
`resources/views/products/index.blade.php`,
`resources/views/products/show.blade.php`,
`tests/Feature/DeletionGuardTest.php`
What:
- `ProductController::destroy()`: after `authorize`, `if ($blocker =
  $product->deletionBlocker()) return redirect()->route('products.show',
  $product)->with('error', $blocker);` — before the image delete.
- `ProductVariantController::destroy()`: `$this->authorize('delete',
  $variant)` first, then the same refusal redirecting to `products.show`.
- Loaders: `index()` → `Product::withCount('batches')->with(['variants' =>
  fn ($q) => $q->orderBy('name')->withCount(['batchItems', 'orderItems',
  'specialPrices'])->chaperone()])`; `show()` → the `variants` entry becomes
  the same closure (with `chaperone()`), plus `$product->loadCount('batches')`.
  **`chaperone()`** sets the inverse so `picker_label`'s `$variant->product`
  doesn't lazy-load per blocked variant; keep it in these two loaders only,
  not on `Product::variants()` (the API export and other callers stay as
  they are). `attachFreeStock` unchanged.
- `routes/web.php:200`: `->scoped()` on the `products.variants` resource.
- Views: the three Delete forms (product on index; variant on index and on
  show) become the batch-card construct inside their existing `@can`
  wrappers. Bodies: product → "The product and its variants are removed.
  This can't be undone."; variant (both) → "The variant is removed. This
  can't be undone."
- Tests: office `DELETE products/{blocked}` → redirect to `products.show`,
  `error` = the blocker, product + batch + batch item still exist;
  `DELETE products/{bare}` → gone; variant blocked by an order line →
  refused, line kept; bare variant → **factory 403** (still there), office
  deletes it; `DELETE /products/{A}/variants/{variant of B}` → **404**, the
  variant still there; `/products` renders `data-delete-blocked` exactly
  twice for a blocked product with one blocked variant, the title carries
  the sentence, a bare probe has its form (match on the destroy `action`);
  `/products/{product}` the same for its variants; **query count on
  `/products` does not grow** with four more blocked products (log helper
  as `BatchCreateTest:324`).
Check: `php artisan test --filter="DeletionGuardTest|ConfirmDialogTest|ProductStockFigureTest|AuthorizationBoundaryTest|FloorPricesTest"`
→ pass.

### 4. Customers: blocker, authorize everywhere, page greys Delete
Files: `app/Models/Customer.php`, `app/Http/Controllers/CustomerController.php`,
`resources/views/customers/index.blade.php`, `tests/Feature/DeletionGuardTest.php`
What:
- `deletionBlocker()` / `dependentRecordCount()` / private
  `dependentCounts()` over `orders`, `invoices`, `payments`, `creditNotes`,
  `statements` — the five `*_count`s when all loaded, else one `loadCount`.
  Sentence: "{name} can't be deleted — 3 orders and 1 payment depend on
  them. Set the customer inactive instead." (verb by total; "order(s)",
  "invoice(s)", "payment(s)", "credit note(s)", "statement(s)"). Special
  prices are **not** a blocker.
- `index()`: `withCount(['orders', 'invoices', 'payments', 'creditNotes',
  'statements'])` — the Orders column keeps reading `orders_count`.
- `destroy()`: the `orders()->count()` check becomes the blocker (same
  filtered redirect, `error` = the sentence).
- **`authorize()` in every `CustomerController` action**: `viewAny`
  (index), `create` (create, store), `view` (show), `update` (edit,
  update), `delete` (destroy). Pure additions — the group already denies
  factory, so nothing observable changes except the gap closing.
- View: `@if($customer->orders_count === 0)` becomes the blocker construct
  (greyed Delete with the title, else the form); the "·" separator shows in
  both branches. Body → "The customer and their special prices are removed.
  This can't be undone."
- Tests: a customer with a **payment and no orders** → refused with the
  exact sentence (red on HEAD: a 500, SQLSTATE 23000/1451), still exists;
  with an invoice → refused; with two orders → "2 orders depend on them";
  bare customer with a special price → deleted, the price cascades
  (`CustomerSpecialPriceTest:338` still green); `/customers` shows one
  `data-delete-blocked` with the title and only the bare customer's form;
  no per-row query growth on `/customers`.
Check: `php artisan test --filter="DeletionGuardTest|CustomerSpecialPriceTest|AuthorizationBoundaryTest"` → pass.

### 5. Guide: three pictures and two sentences
Files: `docs/guide/stock.md`, `docs/guide/customers.md`,
`docs/guide/images/{products-index,product-page,customers-list}.png`
What:
- `stock.md`, Products section, after the **View** bullet: "**Delete** on a
  product or a variant works only while nothing depends on it: no batches,
  order lines or customer prices. Otherwise it is greyed out; point at it
  to see why. To stop selling something, untick **Active** instead."
  (match the screen's labels; keep ≤ 110 lines).
- `customers.md:13`: "**Delete** works only for a customer with no orders,
  invoices, payments, credit notes or statements; otherwise it is greyed
  out — point at it to see why — and the customer is set inactive instead."
- Re-shoot: `node docs/guide/screenshot.mjs products-index`,
  `… product-page`, `… customers-list` → 3 ✓; € figures still blurred;
  blocked rows show the greyed Delete. Delete any probe rows **before**
  shooting.
Check: `/help/stock` and `/help/customers` as office show the new text and
pictures; `php artisan test --filter=HelpPagesTest` → 9 passing; the three
PNGs are the only picture changes (`git status`).

### 6. Docs
Files: `CLAUDE.md`, `docs/planImp/OPEN_QUESTIONS.md`
What:
- `CLAUDE.md` → the `Batch::deletionBlocker()` bullet under *Feedback,
  fallbacks, dialogs and PDFs* becomes the general rule:
  "**`deletionBlocker()` is the one place a delete rule lives** — `Batch`,
  `Product`, `ProductVariant`, `Customer`. The page greys Delete with
  'Can't delete — N records depend on it. <sentence>' as its title
  (`data-delete-blocked`; counts from the loader's `withCount`, read by
  `array_key_exists`), the controller refuses with the sentence as the
  `error` flash. Deactivate, don't delete. `products.variants` is
  `scoped()`. `chaperone()` on the two `/products` variant loads keeps
  `picker_label` from lazy-loading. `DeletionGuardTest`." Plus one pointer
  each under *Product Management* and *Customer CRUD*.
- `OPEN_QUESTIONS.md` → **## 7. The delete-cascade foreign keys are still
  in the schema** (Raised 2026-11-07, cycle 17; Status open): the app now
  refuses, but a raw delete would still cascade through `orders.customer_id`,
  `products → batches`, `product_variants → order_items / batch_items`;
  switch to RESTRICT in a migration at a deploy window?
Check: `grep -n DeletionGuardTest CLAUDE.md` → hit; `grep -n "^## 7\."
docs/planImp/OPEN_QUESTIONS.md` → hit.

### 7. Pint and the suite
Check: `./vendor/bin/pint app tests` then `php artisan test` → ≥ 845
tests, 0 failed (say the real number).

## Verification

1. `php artisan test` → **0 failed**; state the count and assertions
   (824 + the new `DeletionGuardTest` cases).
2. `./vendor/bin/pint --test app tests` → clean.
3. `php artisan route:list | wc -l` → 169 (scoping adds no route).
4. Query counts: `/products` and `/customers` as office — the same number
   of queries with one blocked row as with five (from the tests; quote the
   figures).
5. Browser as `office_test`, console clean: `/products` → Organic Milk's
   Delete is greyed, hover shows "Can't delete — N records depend on it.
   Mossfield Organic Milk can't be deleted — …"; a probe product (and a
   probe variant on it) shows a red Delete → delete both → gone, success
   flash. `/customers` → a customer with orders greyed with the sentence; a
   probe customer deletable → delete it. **No probe rows left** — say so.
6. `git status --short` → 3 models, 3 controllers, `routes/web.php`, 3
   views, `DeletionGuardTest` (new), 2 guide pages, 3 PNGs, `CLAUDE.md`,
   `OPEN_QUESTIONS.md`. No migrations, no `public/build`, no
   `demo-data.json`.

## Risks

- **`->scoped()` and existing tests**: `AuthorizationBoundaryTest:457-458`
  binds `{product}` to the bottle's own product, so it survives scoping; any
  other test that posts a variant route under the wrong product is a test
  bug — fix the fixture, not the scoping, and say so in Deviations.
- **`Product::dependentCounts()` must not count the loaded `batches`**
  (capped at 10 on `show`) — `batches_count` or `batches()->count()` only.
- **`order_items` on cancelled orders still count** — they are kept on
  cancel as history. Don't filter them.
- **Customer `authorize('view')` on `show()`**: `BasePolicy` gives office
  `view`; confirm nothing in the Finance flows reaches `customers.show`
  with a role the policy denies (only admin/office can, so no).
- **Three pictures change** — check each before counting it done: € still
  blurred, the greyed Delete visible, nothing else different (the clock is
  hidden by the script).
- **Probe rows on dev**: a product delete cascades its variants — only
  delete the probe you created, by its own Delete button, and confirm the
  product count on `/products` is back where it was.

## Review

### Revision 1 — reviewed 2026-11-07 (Planner: Fable 5.1)

**Verdict: ACCEPTED.**

Reran by the Planner: `php artisan test` → 848 tests, 0 failed (5,824
assertions); `pint --test app tests` → 235 files clean; `route:list` → 169;
HEAD `0ddb381`. Read the whole diff and `DeletionGuardTest` (24 cases). A
read-only two-role headless pass (no probes — the Implementer's were
already gone: products/variants/customers 7/15/16, zero `ZZ Probe%` rows):
office on `/products` sees 19 greyed Deletes, the first titled "Can't
delete — 33 records depend on it. Organic Milk can't be deleted — 5 batches
and 26 order lines and 2 customer special prices depend on it…", colour
`rgb(168,171,162)` (`--faint`), `aria-disabled="true"`, two live forms
(Cumin's Vacuum Pack and Mature Cheese); `/products/1` three greyed;
`/customers` six greyed, ten live on page 1; `/help/stock` and
`/help/customers` carry the new sentences; no console errors. Factory sees
no Delete of any kind on `/products` and 403s on `/customers` and
`/help/customers`. Looked at the three pictures: `products-index` and
`customers-list` show grey on blocked rows and red on free ones, € blurred
as before; `product-page` unchanged to the eye (see note 1).

- Steps 0–7: all done as written. The three models follow the Batch
  pattern exactly (`array_key_exists`, one `loadCount` fallback, public
  `dependentCounts()` on the variant, `batches_count`-or-`count()` on the
  product, the variants' three counts summed); both controllers refuse
  before the image delete; `scoped()`; `authorize()` in all seven customer
  actions; the three views use the batch-card construct verbatim with
  consequence-only bodies; query counts 5 → 5 and 2 → 2.
- Deviations 1–4: accepted. (3) was my slip — step 2 says `{short_name}`,
  Verification 5 wrote the full name; the Implementer followed the step.
- Notes: (1) `product-page.png` can't show the variant Delete because the
  variants table overflows its panel at 1280 px and the actions cell is cut
  at "Edit · Dupli…" — pre-existing, in `SMALL_FIXES.md` now. (2) The hover
  prefix "depend on **it**" beside the customer sentence's "depend on
  **them**" — harmless, in `SMALL_FIXES.md` as a one-word tidy ("on this").
  (3) `loadCount()` writing `*_count` onto the model in the fallback —
  fine; it is what `Batch` does. (4) "6 blocked" was page 1 of `/customers`
  — understood.
