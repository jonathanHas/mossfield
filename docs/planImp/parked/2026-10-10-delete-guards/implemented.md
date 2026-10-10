# Delete guards: products, variants and customers refuse when history depends on them — implementation

Status: IN PROGRESS
Plan revision: 1
Implementer: Opus
Date: 2026-10-07

## Baseline
HEAD: 095c250 (the plan's Context says `1bd17f5`; `095c250` is the commit that added this plan)
Pre-existing dirty files: clean
```
$ php artisan test
  Tests:    699 passed (4456 assertions)
$ ./vendor/bin/pint --test app tests database routes config
(clean — no output)
$ php artisan route:list | wc -l
166
```

## Steps

### 1. `ProductVariant::deletionBlocker()` — done
Changed `app/Models/ProductVariant.php`:
- Relations `orderItems()` and `specialPrices()` (`HasMany` →
  `OrderItem`, `CustomerSpecialPrice`).
- `deletionBlocker(): ?string`, `dependentRecordCount(): int`,
  `dependentCounts(): array{batch_items, order_lines, special_prices}`.
  - Counts come from `batch_items_count` / `order_items_count` /
    `special_prices_count` when all three are in `getAttributes()`, else one
    `loadCount([...])`.
  - **`dependentCounts()` is public, not private as the plan says** —
    `Product` sums its variants' counts with it (step 2). See Deviations.
- Message, Batch voice: "{picker_label} can't be deleted — {reasons joined
  by " and "} depend(s) on it, and deleting it would remove them/it from
  past batches and orders. Set the variant inactive instead."
  - Reasons are "N batch item(s)", "N order line(s)", "N customer special
    price(s)", non-zero only.
  - Verb and pronoun follow the total ("1 … depends on it … remove it").
  - When the **only** reason is special prices, " from past batches and
    orders" is dropped — a special price is config, not history.

**new** `tests/Feature/DeletionGuardTest.php` — step 1 has 6 cases:
- a bare variant → null / 0;
- a batch item → the exact sentence;
- two order lines → "2 order lines depend on it", count 2;
- a special price → "…remove it." with no "past batches";
- all three → named together;
- counts read from a `withCount` load → **0 queries**.
Check output:
```
$ php artisan test --filter=DeletionGuardTest
  Tests:    6 passed (11 assertions)
```

### 2. `Product::deletionBlocker()` — done
Changed `app/Models/Product.php`: `deletionBlocker()`, `dependentRecordCount()`
and private `dependentCounts(): array{batches, order_lines, special_prices}`.
- `batches` = `batches_count` when in `getAttributes()`, else
  `batches()->count()`. It **never counts the loaded `batches` relation**,
  which `show()` caps at 10.
- Order lines / special prices = Σ over the variants' `dependentCounts()`.
  It uses the loaded `variants` when each carries the three `*_count`s, else
  one `variants()->withCount([...])->get()`.
- A product with only bare variants → null (variants are config and cascade
  with it).
- Message: "{short_name} can't be deleted — {N batch(es)} and {N order
  line(s)} and … depend(s) on it, and deleting it would destroy that
  traceability. Set the product inactive instead."
- **`dependentRecordCount()` = batches + order lines + special prices**, not
  "batches + Σ variant counts" as the plan says. A variant's batch items
  live inside the product's batches, so adding them would count the same
  stock twice, and "Can't delete — N records" would disagree with the
  sentence. See Deviations.

`DeletionGuardTest` +4:
- bare variants → null / 0;
- one batch → the exact sentence, count **1** (the batch item isn't counted
  again);
- a second variant's order line only → "1 order line depends on it";
- a `withCount` load (product `batches` + the variants' three counts) → **0
  queries**.
Check output:
```
$ php artisan test --filter=DeletionGuardTest
  Tests:    10 passed (19 assertions)
```

### 3. Controllers refuse; pages hide Delete and say why — done
Changed:

`app/Http/Controllers/ProductController.php`
- `destroy()`: after `authorize`, `if ($blocker = $product->deletionBlocker())`
  → `redirect()->route('products.show', $product)->with('error', $blocker)`.
  The image is untouched on refusal.
- `index()`: `Product::withCount('batches')->with(['variants' => …->orderBy('name')->withCount(['batchItems','orderItems','specialPrices'])->chaperone()])`.
- `show()`: `variants` gets the same `withCount` + `chaperone()`, plus
  `$product->loadCount('batches')` (the loaded `batches` is capped at 10).
- **`chaperone()`** (Laravel 12's inverse-relation helper) is applied in the
  two loaders only, not on `Product::variants()`. The variant blocker's
  message uses `picker_label` → `$variant->product`; without it, each blocked
  variant lazy-loaded its product. The query-count test below shows that
  (red: 11 queries vs 7). Kept off the relation itself so no other caller
  (API export, JSON) changes. Not in the plan — see Deviations.

`app/Http/Controllers/ProductVariantController.php`
- `destroy()` now starts with `$this->authorize('delete', $variant)`, which
  was missing, then the same refusal redirecting to `products.show`.

Views
- `products/index.blade.php` (product row + variant rows) and
  `products/show.blade.php` (variant rows):
  - inside the unchanged `@can` wrappers, `@if($blocker =
    $x->deletionBlocker())` renders `<span … title="{{ $blocker }}"
    data-delete-blocked>Can't delete — N record(s) depend(s) on it</span>`,
    else the Delete form (N = `dependentRecordCount()`);
  - dialog bodies → "The product and its variants are removed. This can't
    be undone." / "The variant is removed. This can't be undone.".

`DeletionGuardTest` +7:
- a blocked product → redirect to `products.show`, `error` = the blocker,
  product + batch + batch item kept;
- a bare product → deleted;
- a blocked variant (order line) → refused, line kept;
- a bare variant → factory **403** (and still there), office deletes it;
- `/products`: exactly 2 `data-delete-blocked` (product + its variant), the
  hover title carries the sentence, the bare probe has its Delete form
  (matched on `action="…"` — the destroy URL equals the View link);
- `/products/{product}`: 1 blocked variant, the spare variant has its form;
- **query count on `/products` doesn't grow** with 4 more blocked products.

Red check (`chaperone()` removed): `Failed asserting that 11 is identical to 7.`

Verification item 4, measured: `/products` on the seeded catalogue
(`ProductSeeder`, via a throwaway test, since deleted) = **3 queries at
HEAD, 3 now**. The counts are subselects in the same query.

Check output:
```
$ php artisan test --filter="DeletionGuardTest|ConfirmDialogTest|ProductStockFigureTest"
  Tests:    30 passed (124 assertions)
```
Browser: see *Browser checks*.

### 4. `Customer::deletionBlocker()` — done
Changed:

`app/Models/Customer.php`
- `deletionBlocker()`, `dependentRecordCount()`, private `dependentCounts()`
  over `orders`, `invoices`, `payments`, `creditNotes`, `statements`.
  Counts come from the `*_count` attributes when all five are loaded, else
  one `loadCount`.
- Message: "{name} can't be deleted — 2 orders and 1 payment depend on them.
  Set the customer inactive instead." (verb by total). Special prices are
  **not** a blocker.

`app/Http/Controllers/CustomerController.php`
- `index()`: `withCount(['orders', 'invoices', 'payments', 'creditNotes',
  'statements'])`; the Orders column keeps reading `orders_count`.
- `destroy()`: the `orders()->count()` check is replaced by the blocker
  (same filtered redirect, `error` = the blocker).
- **Added `$this->authorize('delete', $customer)`.** `destroy()` had none —
  the same gap the plan fixes on the variant route. See Deviations; the
  rest of `CustomerController` has no `authorize` calls either (Notes for
  Planner).

`resources/views/customers/index.blade.php`
- The `orders_count === 0` condition becomes the blocker construct: a
  `data-delete-blocked` span with the hover sentence, else the Delete form.
- The body is now "The customer and their special prices are removed. This
  can't be undone."
- The "·" separator now shows in both branches.

`DeletionGuardTest` +5:
- **a payment and no orders → refused with the exact sentence**, not a 500.
  Red on HEAD: "Expected … 302 but received 500 … SQLSTATE[23000] … 1451
  Cannot delete or update a parent row".
- an invoice → refused;
- two orders → "— 2 orders depend on them";
- a bare customer with a special price → not blocked, deleted, the special
  price cascades;
- the list shows 1 `data-delete-blocked` (with the hover sentence) and only
  the bare customer's form.
- The existing `CustomerSpecialPriceTest` delete-with-filters case passes
  unchanged.

Check output:
```
$ php artisan test --filter="DeletionGuardTest|CustomerSpecialPriceTest"
  Tests:    35 passed (124 assertions)
```

### 5. The two deferred copy items — done
Changed:
- Short-close bodies → "The {n} unpicked unit(s) won't be supplied or
  billed; any stock reserved for them goes back to free stock." Each page
  keeps its own `n`:
  - office "Close short" (`orders/partials/allocation-items`):
    `$orderItem->quantity_remaining`, now with `Str::plural` (it read a bare
    "units" before);
  - picking "Mark short" and "Can't finish this line" (`picking/item`):
    `$needed`.
  Titles unchanged; `ConfirmDialogTest`'s echo check is still green.
- Stepper (`orders/partials/status-stepper`): total = `orderItems->sum(fn ($i)
  => $i->isShortClosed() ? $i->quantity_fulfilled : $i->quantity_ordered)` —
  through the accessor, never the raw column.
- `OrderTransitionsTest` +1: `lineOn()` takes a `shortClosed` flag. A
  preparing order with 10/4 and a short-closed 6/0 reads exactly **"4 of 10
  picked"**.
  Red on HEAD's stepper: `-'4 of 10 picked' +'4 of 16 picked'`.
- `docs/planImp/SMALL_FIXES.md`: both items ticked `[x]`, each with a *Done
  2026-10-07 (cycle 12)* line.

Check output:
```
$ php artisan test --filter="OrderTransitionsTest|OrderShortSupplyTest|ConfirmDialogTest"
  Tests:    58 passed (202 assertions)
```

### 6. Docs — done
Changed:

`CLAUDE.md`
- The `Batch::deletionBlocker()` bullet (*Feedback, fallbacks, dialogs and
  PDFs*) is now the general rule: `deletionBlocker()` is the one place a
  delete rule lives — `Batch`, `Product`, `ProductVariant`, `Customer`. It
  covers:
  - the `data-delete-blocked` span with the sentence as its hover;
  - counts from the loader's `withCount`;
  - the controller's `error` flash;
  - deactivate, don't delete;
  - what stays deletable;
  - the `chaperone()` note;
  - `DeletionGuardTest`.
- A pointer is added to *Product Management* and to *Customer CRUD*.

`docs/planImp/OPEN_QUESTIONS.md`
- New **#6 — "The delete-cascade foreign keys are still in the schema"**:
  switch `orders.customer_id`, `products → batches`, `product_variants →
  order_items` to RESTRICT at the next deploy window?

`docs/planImp/WORK_IN_PROGRESS.md`
- *Recently Completed: Delete guards (2026-10-07)*; Last Updated bumped.

Check:
```
$ grep -n "DeletionGuardTest" CLAUDE.md
159:- **`deletionBlocker()` is the one place a delete rule lives** — … `DeletionGuardTest`.
```

## Verification (so far)
1. `php artisan test` → **722 passed (4530 assertions), 0 failed** (74.6 s).
   Baseline 699 → +23:
   - `DeletionGuardTest` 22 (new);
   - `OrderTransitionsTest` +1.
2. `./vendor/bin/pint --test app tests database routes config` → clean.
3. `php artisan route:list | wc -l` → **166** (unchanged).
4. `/products` query count: **3 at HEAD, 3 now** (seeded catalogue). The
   per-product guard test shows no growth (7 with 1 product, 7 with 5).
5. `git status --short` → 3 models, 3 controllers, 6 views (the plan said
   five; `picking/item` is the sixth, named in step 5),
   `tests/Feature/{DeletionGuardTest (new), OrderTransitionsTest}`, 4 docs,
   and this file. No migrations, no `public/build`.
6. Browser — **pending**, see below.

## Deviations
1. **`ProductVariant::dependentCounts()` is public**, not private as the
   plan says. `Product::dependentCounts()` sums its variants' order lines
   and special prices through it, rather than repeating the attribute-or-
   query logic.
2. **`Product::dependentRecordCount()` = batches + order lines + special
   prices**, not "batches + Σ variant counts". A variant's batch items live
   inside the product's batches; adding them would count the same stock
   twice, and "Can't delete — N records" would disagree with the sentence
   beside it. The sentence names batches, order lines and special prices,
   and N matches it.
3. **`chaperone()` in the two `/products` loaders** (not in the plan). The
   variant message uses `picker_label` → `$variant->product`; without the
   inverse relation every blocked variant lazy-loaded its product. The
   query-count test fails without it (11 vs 7). It's kept off
   `Product::variants()` itself so the API export and other callers are
   unchanged.
4. **`CustomerController::destroy()` gains `$this->authorize('delete',
   $customer)`.** The plan adds the missing call to the variant route only;
   the customer delete had none either, and CLAUDE.md requires it in every
   write action.
5. **Message pronoun and verb follow the count** ("1 batch item depends on
   it … remove it"; "2 order lines depend on it … remove them"). For a
   variant whose only dependency is special prices, "from past batches and
   orders" is dropped, since special prices aren't history.
6. **Six views, not five**: `picking/item.blade.php` carries two of the
   three short-close bodies (named in step 5's files, missed in
   Verification's count).

## Notes for Planner
- **`CustomerController` has no `authorize()` in any action** — `index`,
  `create`, `store`, `show`, `edit`, `update` rely on the `role:admin,office`
  route group alone. That's safe today (factory is 403'd at the middleware),
  but it is the same gap CLAUDE.md → Roles says to close, and the
  authorisation matrix can't see it because the group already denies.
  `destroy()` is fixed here; the rest is a small follow-up.
- **The variant destroy route doesn't check the variant belongs to the
  route's product.** `DELETE /products/1/variants/9` with variant 9 under
  product 2 deletes variant 9 and redirects to product 1. Pre-existing; the
  blocker still applies. A `scopeBindings()` on the nested resource, or an
  `abort_unless`, would close it.
