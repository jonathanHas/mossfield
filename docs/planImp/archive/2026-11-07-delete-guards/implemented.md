# Cycle 17 — Delete guards: products, variants and customers — implementation

Status: DONE
Plan revision: 1
Implementer: Opus
Date: 2026-11-07

## Baseline
HEAD: 0ddb381
Pre-existing dirty files:
```
 M docs/planImp/RESUME.md      (Planner's, not touched here)
?? docs/planImp/plan.md
```

## Steps
### 0. Baseline — done
```
php artisan test              → Tests: 823 deprecated, 1 passed (5736 assertions)  = 824 tests, 0 failed
pint --test app tests         → PASS … 234 files
route:list | wc -l            → 169
HEAD                          → 0ddb381
git status --short            →  M docs/planImp/RESUME.md / ?? docs/planImp/plan.md
```
(The plan expected only `?? plan.md`; `RESUME.md` was already modified by the
Planner before this session. Not touched here.)

### 1. `ProductVariant::deletionBlocker()` — done
Changed: `app/Models/ProductVariant.php` (+ `orderItems()`, `specialPrices()`,
`deletionBlocker()`, `dependentRecordCount()`, public `dependentCounts()`),
`tests/Feature/DeletionGuardTest.php` (new, 6 variant cases).
Check output:
```
php artisan test --filter=DeletionGuardTest
  ! a bare variant can be deleted … ! variant counts ride a with count load   (6 pass; "!" = the PHP 8.5 PDO deprecation every test carries)
```

### 2. `Product::deletionBlocker()` — done
Changed: `app/Models/Product.php`, `DeletionGuardTest` (+4 product cases).
Check output:
```
Tests:    10 deprecated (22 assertions)   — 10 pass, 0 failed
```

### 3. Products: controllers refuse, pages grey Delete, route scoped — done
Changed: `ProductController` (index/show loaders with `withCount` + `chaperone()`,
destroy refusal), `ProductVariantController::destroy()` (authorize + refusal),
`routes/web.php` (`->scoped()`), `products/index.blade.php` (2 Delete forms),
`products/show.blade.php` (1), `DeletionGuardTest` (+8 cases).
Check output:
```
php artisan test --filter="DeletionGuardTest|ConfirmDialogTest|ProductStockFigureTest|AuthorizationBoundaryTest|FloorPricesTest"
  Tests:    56 deprecated (1649 assertions)   — 56 pass, 0 failed
```

### 4. Customers: blocker, authorize everywhere, page greys Delete — done
Changed: `app/Models/Customer.php` (`deletionBlocker()`, `dependentRecordCount()`,
private `dependentCounts()`), `CustomerController` (`authorize()` in all seven
actions; index `withCount` of five; destroy refuses on the blocker),
`customers/index.blade.php`, `DeletionGuardTest` (+6 cases).
Check output:
```
php artisan test --filter="DeletionGuardTest|CustomerSpecialPriceTest|AuthorizationBoundaryTest"
  Tests:    58 deprecated (1622 assertions)   — 58 pass, 0 failed
```
Red on HEAD (app/resources/routes stashed, the payment-only case run alone):
```
⨯ a customer with a payment and no orders is refused not a 500
Expected response status code [201, 301, 302, 303, 307, 308] but received 500.
SQLSTATE[23000]: Integrity constraint violation: 1451 … `payments_customer_id_foreign`
```
Query figures (temporary STDERR line in the two query tests, removed after):
```
/products   before=5 after=5   (1 blocked product → 5 blocked products)
/customers  before=2 after=2   (1 blocked customer → 5)
```

### 5. Guide: three pictures and two sentences — done
Changed: `docs/guide/stock.md` (one bullet after **View**; 60 lines),
`docs/guide/customers.md:13`, the three PNGs.
Check output:
```
node docs/guide/screenshot.mjs products-index  → ✓ docs/guide/images/products-index.png
node docs/guide/screenshot.mjs product-page    → ✓ docs/guide/images/product-page.png
node docs/guide/screenshot.mjs customers-list  → ✓ docs/guide/images/customers-list.png
php artisan test --filter=HelpPagesTest        → Tests: 9 deprecated (470 assertions) — 9 pass
```
Looked at each picture: € still blurred; `products-index` and `customers-list`
show grey Delete on blocked rows and red on free ones. `product-page` shows no
Delete at all — see Notes. Shot before any probe rows existed.
(The script takes one filter argument; `node … products-index product-page
customers-list` shot only the first, so each was run separately.)

### 6. Docs — done
Changed: `CLAUDE.md` (the `Batch::deletionBlocker()` bullet is now the general
rule; one pointer each under *Product Management* and *Customer CRUD*),
`docs/planImp/OPEN_QUESTIONS.md` (## 7, appended after ## 6 like the others).
Check output:
```
grep -n DeletionGuardTest CLAUDE.md            → 160:- **`deletionBlocker()` is the one place a delete rule lives** …
grep -n "^## 7\." docs/planImp/OPEN_QUESTIONS.md → 276:## 7. The delete-cascade foreign keys are still in the schema
```

### 7. Pint and the suite — done
```
./vendor/bin/pint app tests   → PASS 235 files (no changes needed)
php artisan test              → Tests: 847 deprecated, 1 passed (5824 assertions)  = 848 tests, 0 failed
```

## Deviations
1. **`CustomerController::create()`/`store()` both authorize `create`, and
   `edit()`/`update()` both `update`** — as the plan says; listed only because
   that is seven calls, not five.
2. **Tests compare the title as `"Can't delete — … " . e($blocker)`** — the
   template's literal "Can't" is not escaped but the interpolated sentence's
   apostrophe is (`&#039;`). No code change; noted so the reviewer isn't
   surprised by the assertion shape.
3. **Verification 5's expected hover text** said "Mossfield Organic Milk can't
   be deleted"; step 2 specifies `{short_name}`, so the sentence reads
   "Organic Milk can't be deleted". I followed step 2.
4. **`DeletionGuardTest` has 24 cases** (6 variant, 4 product, 8 product
   routes/pages, 6 customer) — the suite is 848, above the plan's ≥ 845.

No FK changes, no migrations, no CSS/JS, no `public/build`, no `demo-data.json`.

## Verification
1. `php artisan test` → **848 tests, 0 failed, 5824 assertions** (824 + 24).
   ("847 deprecated, 1 passed" — the deprecation is PHP 8.5's
   `PDO::MYSQL_ATTR_SSL_CA` notice, present on the baseline too.)
2. `./vendor/bin/pint --test app tests` → `PASS … 235 files`.
3. `php artisan route:list | wc -l` → **169**.
4. Query counts, from the two `…costs_the_same_queries…` tests (figures via a
   temporary STDERR line, removed): `/products` **5 → 5** (one blocked product
   → five), `/customers` **2 → 2** (one blocked customer → five).
   HEAD-red check: the payment-only customer delete is a 500
   (SQLSTATE 23000/1451, `payments_customer_id_foreign`) with app/resources/routes
   stashed; a redirect with the sentence now.
5. Browser, headless Chrome (puppeteer-core, the guide script's Chrome) as
   `office_test` on `http://mossfield.local`, **console errors: []**:
   ```
   Organic Milk Delete: title "Can't delete — 33 records depend on it. Organic Milk can't be deleted —
     5 batches and 26 order lines and 2 customer special prices depend on it, and deleting it would destroy
     that traceability. Set the product inactive instead."  colour rgb(168,171,162) (--faint), aria-disabled=true
   blocked on /products: 19
   probe product created → red Delete form /products/8
   probe variant created → /products/8/variants/16, red (oklch(0.58 0.17 25))
     dialog: "Delete this variant?" / "The variant is removed. This can't be undone." → OK
     → /products/8  "Product variant deleted successfully."
     dialog: "Delete this product?" / "The product and its variants are removed. This can't be undone." → OK
     → /products   "Product deleted successfully."
   /customers: 6 blocked; first title "Can't delete — 2 records depend on it. An Tairseach Organic Farm Shop
     can't be deleted — 2 orders depend on them. Set the customer inactive instead."
   probe customer created (/customers/27) → filtered list → dialog "Delete this customer?" /
     "The customer and their special prices are removed. This can't be undone." → OK
     → /customers?search=ZZ%20Probe  "Customer deleted successfully."
   ```
   **No probe rows left**: products / variants / customers = 7 / 15 / 16 before
   and after; `ZZ Probe%` rows = 0. (Auto-increment ids moved on: 8, 16, 27.)
6. `git status --short` — see below. Matches the plan's list.

## Files changed
```
 M CLAUDE.md
 M app/Http/Controllers/CustomerController.php
 M app/Http/Controllers/ProductController.php
 M app/Http/Controllers/ProductVariantController.php
 M app/Models/Customer.php
 M app/Models/Product.php
 M app/Models/ProductVariant.php
 M docs/guide/customers.md
 M docs/guide/images/customers-list.png
 M docs/guide/images/product-page.png
 M docs/guide/images/products-index.png
 M docs/guide/stock.md
 M docs/planImp/OPEN_QUESTIONS.md
 M docs/planImp/RESUME.md                  (pre-existing — Planner's, not touched)
 M resources/views/customers/index.blade.php
 M resources/views/products/index.blade.php
 M resources/views/products/show.blade.php
 M routes/web.php
?? docs/planImp/implemented.md
?? docs/planImp/plan.md                    (pre-existing)
?? tests/Feature/DeletionGuardTest.php
```
`git diff --stat`: 18 files, 324 insertions, 53 deletions (incl. RESUME.md's 39).

## Notes for Planner
- **`product-page.png` can't show the greyed Delete.** At 1280 px the variants
  table on `/products/{product}` overflows its panel and the actions cell is
  cut at "Edit · Dupli…" — identical in the HEAD picture, so pre-existing.
  The re-shot picture is therefore visually the same as before; the variant
  Delete (greyed or not) is off the right edge for anyone at that width. A
  wrap/narrower actions cell, or a wider shot, would fix it — not done here.
- **Customer sentence vs title pronoun**: the hover reads "Can't delete — 2
  records depend on **it**. … 2 orders depend on **them**." The title prefix
  is the shared batch-card construct the plan asked for verbatim; the
  sentence uses "them" for a customer as specified. Harmless, but it reads a
  touch odd side by side; "on it" → "on this" in the prefix would suit all four.
- **`dependentCounts()` on `ProductVariant`/`Customer` uses `loadCount()`**
  when the counts aren't loaded, which writes the `*_count` attributes onto
  the model. Harmless (a second call costs nothing), noted in case a later
  caller serialises the model.
- `/customers` paginates at 20, so the browser figure "6 blocked" is page 1 only.
