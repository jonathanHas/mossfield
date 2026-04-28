# Work In Progress

This file tracks the current development state and what needs attention when resuming work.

**Last Updated**: 2026-04-27

> **Production install runbook lives in [`DEPLOYMENT.md`](./DEPLOYMENT.md).** When anything in this file references "operator action required in prod", the detailed step-by-step is there.

---

## Current Issue: Variable Weight Input Fields Not Rendering

### Problem
The individual weight input fields for variable-weight cheese items are not displaying in the fulfillment UI at `/order-allocations/{order}`.

### What Should Happen
When fulfilling cheese wheels (variable weight items), the UI should show:
- A quantity selector (dropdown)
- Individual weight input fields for each unit (#1, #2, #3, etc.)
- A running total of all entered weights
- The total weight is submitted as `actual_weight_kg`

### What's Currently Happening
- The "Variable Weight" badge shows correctly
- The "Weight" column appears in the allocations table
- The quantity selector and "Fulfill" button appear
- But the individual weight input rows (#1, #2, #3) are NOT rendering
- Only "Total: kg" shows with no input fields above it

### Files Involved
- `resources/views/order-allocations/show.blade.php` (lines 159-199)
  - Contains the form with Blade `@for` loops to render weight inputs
  - JavaScript functions `updateWeightInputs()` and `updateTotal()` at bottom
- `app/Http/Controllers/OrderAllocationController.php`
  - `fulfill()` method handles the form submission
  - Expects `quantity` and `actual_weight_kg` parameters

### Test Order
- Order 12 (`ORD-20260110-004`)
- Contains 3 Mossfield Farmhouse Cheese Whole Wheels (variable weight)
- Allocation #18 has 3 units allocated, 0 fulfilled

### Debugging Steps Tried
1. Verified `isVariableWeight()` returns `true` for the order item
2. Cleared view cache (`php artisan view:clear`)
3. Tried Alpine.js approach - didn't render
4. Switched to plain Blade `@for` loops with vanilla JavaScript - still not rendering

### Possible Causes to Investigate
1. **Blade Compilation Issue**: The `@for` loops might not be compiling correctly
2. **View Caching**: Old cached view might still be served (try hard refresh in browser)
3. **Conditional Logic**: Check if `$allocation->quantity_remaining > 0` evaluates correctly
4. **HTML Structure**: Verify the form HTML is actually in the page source (View Source in browser)

### Quick Test
```bash
# Check if weight inputs are in the compiled view
php artisan view:clear
# Then check raw HTML output:
curl -s "http://mossfield.local/order-allocations/12" | grep -A5 "weight-row"
```

---

## Recently Completed Features

### Product Show + Edit — Master-Detail Layout (2026-04-27)

Same pattern as the orders master-detail (shipped earlier today), now applied to `/products/{product}` (show) and `/products/{product}/edit`. The `/products` index is unchanged — drilling in via **View** or **Edit** is what flips you into the new layout.

- **`ProductController::show()` and `edit()`** both now accept `Request` and call a new private helper `buildProductList()` that fetches up to 50 sibling products (eager-loading variants with `withSum('batchItems as total_stock', 'quantity_remaining')` to keep the no-N+1 invariant), force-includes the selected product, and sorts by `$typeOrder = ['milk','yoghurt','cheese']` then name. Views receive `$productList`, `$listFilters`, `$listTotal`, `$listLimit`. Filter passthrough is wired but a no-op today (index has no filters); helper accepts `type`, `is_active`, `search` for future filter UI without a refactor.
- **`update()` now redirects to `products.show($product)`** instead of `products.index`. Behavior change, intentional — matches `OrderController::update()` so the user lands on the master-detail with their just-edited product highlighted on the right. `destroy()` redirect stays at index (the product no longer exists).
- **New partial `resources/views/products/_sibling_list.blade.php`** — single source of truth for the sidebar, used by both show and edit. Renders products grouped by type with passive subheads (no `<details>` collapsing — there are ~10 products total, collapse is overkill). Each row shows name, variant count, total stock, and an Inactive tag when applicable. Active row gets the same accent-bar treatment as the orders sidebar.
- **Mode-aware sibling links** — sidebar rows on `show` link to `products.show`, rows on `edit` link to `products.edit`. So a user editing many products in a row stays in edit mode; toggling modes goes through the existing **Edit** / **View** button in the detail header. Resolves via `route('products.'.$mode, ...)`.
- **Variant pages (`products.variants.create/edit`) also wrapped** in the same master-detail layout (added in the same change). Sidebar product rows link to `products.show` from variant pages (not `variants.create/edit`, which would require a target-product context that doesn't make sense across products). When on a variant page, the active product's row expands inline to show **its variants** with edit links — quick variant-to-variant navigation within the same product. Active variant gets its own accent bar; sidebar takes `$showActiveVariants` (bool) and `$activeVariant` (model or null) options.
- **Shared list-builder**: `app/Http/Controllers/Concerns/BuildsProductList.php` trait holds `buildProductList(Request, Product): array`. Used by both `ProductController` (show + edit) and `ProductVariantController` (create + edit). When filter passthrough lands on `/products` later, update the trait once.
- **Known limitation**: clicking a sibling on edit / variant edit pages discards unsaved form changes silently. Not guarded — revisit if it bites in practice.

### Order Show — Master-Detail Layout (2026-04-27)

`/orders/{order}` is now a two-pane layout: a sticky list of sibling orders on the left/middle (320px column on `lg+`) and the existing detail content on the right. The index table at `/orders` is unchanged — drilling in is what flips you into the new view.

- **`OrderController::show()`** also fetches up to 50 sibling orders, mirroring whatever `status` / `payment_status` / `customer_id` filters were active on the index. Selected order is force-included in the list even if it falls outside the cap or filters (so deep links from elsewhere still highlight correctly).
- **Filter passthrough** — `orders/index.blade.php` row links forward active filters into the show URL via `array_merge($rowFilters, ['order' => $order->id])`, so the master pane on the show screen matches what the user was looking at on the index.
- **Sibling list rows** show order number, customer name, top-3 item summary (`2× Cheese · 1× Yoghurt …`), status tag, total (gated on `see-financials`), and a relative timestamp. Active row gets a left accent bar + soft background.
- **Mobile** — list pane is `hidden lg:flex`; below `lg` the detail renders alone with a "← All orders" button (which itself preserves filters back to the index).
- **Allocation panel from the mockup is intentionally not pulled in** — `Manage allocation →` still routes to `/order-allocations/{order}`. Folding allocation into this screen is a bigger merge worth a separate decision.

### Batches & Cheese-Cutting UI Redesign (2026-04-22)

Restructured `/batches` and `/cheese-cutting` to mirror the `/products` grouped-card layout, with a visual wheel/vac-pack status indicator in every cheese batch header:

- **`/batches`** — batches now group by product type (Milk → Yoghurt → Cheese) and, within Cheese, sub-group by variety (Farmhouse, Garlic & Basil, etc.). Three collapse levels (type → variety → individual batch) via native `<details>`/`<summary>` — no Alpine dependency; all collapsed by default so users expand only what they need. Filters and pagination preserved at the outer level.
- **Wheel circles in card header** — for each cheese batch, one small circle per wheel produced: **yellow = remaining**, **grey = cut to vac packs**, **black = sold as whole wheel**. Sourced from `quantity_produced`, `quantity_remaining`, and `cheese_cutting_logs.source_batch_item_id` count. Always visible in the header (not hidden behind the collapse) so users can scan wheel status at a glance.
- **Vac-pack mini-bar in card header** — compact 128px stacked bar (yellow=remaining, black=sold) proportional to produced count, plus a text readout (`N / N packs · N sold`). Chose this over per-pack markers because vac packs can run into the hundreds and individual markers don't scale; the bar reads identically at any count.
- **Cheese-variety header summary** — each sub-group header also shows aggregated wheel totals (remaining/cut/sold across all batches of that variety) so the summary is visible even when all batches below are collapsed.
- **`/cheese-cutting`** — same layout applied (cheese-variety sub-groups, collapsible batch cards with wheel circles + pack bars). Expanded body keeps the original "Available Wheels" grid with per-variant **Cut Wheel** action buttons, and the "Vacuum Packs Created" summary.
- **Controller changes** — `BatchController::index()` and `CheeseCuttingController::index()` both eager-load `batchItems` with `withCount('sourceCuttingLogs')` so the cut-count lookup is one query per page (no N+1).
- **New partials** — `resources/views/batches/partials/batch-card.blade.php` and `resources/views/cheese-cutting/partials/batch-card.blade.php`. The wheel-status helper closure (`$wheelStatsFor`) is inlined at the top of each index view.

### Batch Create Form — De-duplicate Wheel Count (2026-04-22)
The cheese batch create form had operators entering the wheel count twice: once in a top-level "Number of Wheels Produced" input and again in the wheel variant's "Quantity Produced" row under Production Breakdown. The instruction text even said "This should match the wheel count above."

- `resources/views/batches/create.blade.php` — removed the `#cheese-wheels-section` block and its JS (`wheelsInput`, `cheeseWheelsSection` branches in `updateFormForProductType`). Dropped the "match the wheel count above" phrase from the cheese instructions.
- `app/Http/Controllers/BatchController.php` — removed `wheels_produced` from request validation and the manual cheese-specific validation block. `batches.wheels_produced` is now derived inside the transaction as `array_sum(batch_items.quantity_produced)` for cheese, `null` otherwise. Safe because the view only submits wheel variants for cheese (vacuum packs are created later via cutting — see create.blade.php variant filter).
- Display sites (`batches/show`, `batches/partials/batch-card`) and the store success message were untouched — they still read `$batch->wheels_produced`, which is still populated.

### Security Rollout — Dev Exercise (2026-04-22)

Applied the dev-safe subset of the post-Phase-3 operator actions on the shared dev box, end-to-end:

- Dumped both MySQL DBs to `/tmp/{mossfield,mossorders}-pre-sec-20260422-*.sql` before running migrations.
- Ran the three pending migrations (`backfill_email_verified_at_for_existing_users` on both apps + `encrypt_customer_pii` on mossfield). Spot-checked: `customers.phone` / `customers.address` are ciphertext in DB (`eyJpdiI6…`).
- Updated both `.env` files: `SESSION_ENCRYPT=true`, `SESSION_SAME_SITE=strict`, `OFFICE_API_ALLOWED_IPS=127.0.0.1,::1`, `SYNC_SCHEDULE_ENABLED=true`. Left `SESSION_SECURE_COOKIE=false` because dev runs over HTTP.
- Verified `schedule:list` shows the hourly sync commands on both apps.
- Triggered manual syncs; `storage/logs/sync-2026-04-22.log` shows a proper `run summary` line with correlation id + duration on each side.
- Confirmed the API auth chain: 200 from loopback + valid token, 401 on bad/missing token, 403 from a LAN source (`10.42.1.83`) because that IP is off the allowlist.
- Audit log in `laravel.log` logs every outcome (`accepted`, `invalid token`, `ip not allowed`) — verified.

**Gap remaining:** nothing runs `php artisan schedule:run` every minute on this box. The hourly schedule entries are registered but inert until a cron line is added (`DEPLOYMENT.md` §6 has the exact line). Decision pending from user on whether to add the dev cron now or wait for prod.

**New doc:** created [`DEPLOYMENT.md`](./DEPLOYMENT.md) as the single operator runbook for production installs. Mirrored on the mossorders repo.

### Security Review — Phases 1–3 (2026-04-20)

End-to-end hardening of the Mossfield ↔ Mossorders sync surface ahead of turning on the hourly scheduler. Full findings, per-item code locations, and operator runbooks in `SECURITY_NEXT_STEPS.md`. Short version:

- **API transport** — `/api/*` now runs `throttle:sync-api` (60/min per IP) → IP allowlist (`OFFICE_API_ALLOWED_IPS`) → two-token check (`OFFICE_API_TOKEN` + optional `OFFICE_API_TOKEN_PREVIOUS` via `hash_equals`) → audit log. 12 feature tests cover the chain.
- **Outbound sync** — `OnlineOrderImportService` and `OfficeProductSyncService` wrap HTTP with explicit TLS verify, 5s/10s timeouts, and 3× retry with 500ms backoff.
- **Observability** — new `sync` log channel (daily, 14-day retention) at `storage/logs/sync.log`. Each run emits one structured summary line with correlation UUID and duration. `sync:import_orders:last_ok` cached on success, rendered as a freshness strip on `/dashboard`.
- **Scheduler** — hourly `mossfield:import-online-orders` registered in `routes/console.php`, gated on `SYNC_SCHEDULE_ENABLED` (default false). `emailOutputOnFailure` wired when `SYNC_ALERT_EMAIL` is set. Deploys dormant.
- **Auth** — `User` now implements `MustVerifyEmail`; business routes require `['auth', 'verified']`; profile routes stay at `auth` only so users can reach verification. Backfill migration grandfathers existing users.
- **PII encryption** — new `App\Casts\EncryptedNullable` applied to `Customer.phone/address/city/postal_code/notes`. Cast is plaintext-tolerant on read (logs a warning) so deploy order is safe; separate idempotent backfill migration encrypts legacy rows. **Do not run the encrypt migration in prod without a DB backup.**
- **Boot guards** — `AppServiceProvider::boot` refuses to start if `APP_ENV=production` AND (`APP_DEBUG=true` OR `MOSSORDERS_BASE_URL` is not `https://`).
- **Headers** — `SecurityHeaders` middleware appends HSTS (prod only), X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy on every response.
- **Seeder** — `AdminUserSeeder` refuses to run in production; dev can override the default password via `ADMIN_SEED_PASSWORD`.
- **Mirror work on mossorders** — matching middleware, config, logging, and boot guards; `User` also gains `MustVerifyEmail` + backfill migration; `/api/orders` gains `limit` cap (max 500) and `meta.has_more`/`next_since` cursor.

**Operator actions still required** (see `SECURITY_NEXT_STEPS.md` verification checklist):
1. Run both backfill migrations in staging → prod after DB backup.
2. Set production session hardening env vars (`SESSION_SECURE_COOKIE=true`, `SAME_SITE=strict`, `SESSION_ENCRYPT=true`).
3. Populate `OFFICE_API_ALLOWED_IPS` once peer IPs are stable.
4. Flip `SYNC_SCHEDULE_ENABLED=true` in prod once logs look clean in staging.

### Pricing Display Consistency Across Mossfield + Mossorders (2026-04-18)
End-to-end fix for weight-priced cheese display and stock valuation:

- **mossfield**:
  - `ProductVariant::estimated_unit_price` accessor added; `OrderItem::boot()::saving` now computes `line_total` weight-aware (`qty × weight_kg × unit_price`) for `is_priced_by_weight` items
  - `StockController` valuation closures use `calculatePrice($qty)` — fixes prior overstatement on cheese (was naively `qty × base_price`)
  - Display sites switched to `$variant->price_label`: `products/index`, `products/show`, `orders/create` (3 dropdowns), `orders/show`, `orders/edit`, `stock/index` (price column + 3 valuation cells + tooltip)
  - `Api/ProductExportController` now emits `is_priced_by_weight` so mossorders can render the same way
- **mossorders**:
  - Migration adds `office_product_variants.is_priced_by_weight`; `OfficeProductSyncService` reads it from the API; `OfficeProductVariant` exposes `price_label` and `estimated_unit_price` accessors
  - `OrderController::prepareLineItems` produces weight-aware estimated totals; customer review/confirmation and admin order views show "estimated" labels and a "Final price set at fulfillment" amber banner
  - `orders/create` browse page now has a live per-row Subtotal column + running Order total (vanilla JS, recalculates on every qty change). Weight-priced rows display an "Approx Xg per pack — actual weight may vary" caption under the product name and an "estimated" subtotal label so the customer sees how the total is derived
  - **Heads-up after deploying the migration**: existing rows default to `is_priced_by_weight=false`. Run `php artisan office:sync-products` (or click sync at `/admin/office-sync`) to repopulate from the office API
- **Heads-up**: legacy `Mossfield Mature - Whole Wheel` variant has `weight_kg=null`, so it currently estimates as €0. Editing the variant via the form will force a weight (per the new validation) — fixes itself on next edit.

### Products Section Polish (2026-04-18)
Smaller wins on the products area:

- New `ProductRequest` and `ProductVariantRequest` FormRequests centralise validation: unique product names, scoped-unique variant names per product, `maturation_days` required when `type=cheese`, `weight_kg` required unless variant is variable-weight & not priced by weight, `base_price` minimum 0.01, checkbox booleans coerced via `prepareForValidation()`
- `ProductController::index/show` use `withSum('batchItems as total_stock', ...)` to kill N+1; `total_stock` accessor on `ProductVariant` prefers the eager-loaded value
- `show()` limits batches to last 10 in the controller load
- "Create Batch" button on product show is now wired to `batches.create?product_id={id}` and the batches form preselects the product from the query string
- Variant rows show `Var. wt` (amber) and `€/kg` (indigo) badges next to the name; new "Duplicate" link on each variant row opens the create form prefilled via `?from={variantId}`

### Order Notes Sync from Mossorders (2026-04-18)
Customer-typed order notes now flow through end-to-end:

- mossorders `Api/OrderExportController::transformOrder` now includes `'notes' => $order->notes` in the response
- mossfield `ImportOnlineOrders` and `OnlineOrdersController` both read `$payload['notes']` and fall back to the `"Imported from Mossorders order …"` placeholder when absent

---

### Variable Weight Fulfillment System (2026-01-10)
Database migrations and model support for variable-weight cheese:
- `product_variants.is_variable_weight` - Boolean flag
- `product_variants.is_priced_by_weight` - Boolean flag for €/kg pricing
- `order_allocations.actual_weight_kg` - Stores fulfilled weight
- `order_items.weight_fulfilled_kg` - Total fulfilled weight
- `order_items.fulfilled_total` - Calculated total based on weight

### Undo Fulfillment (2026-01-10)
Ability to reverse fulfillments:
- `BatchItem::restoreStock()` method
- `OrderItem::unfulfillAllocation()` method
- `OrderAllocationController::unfulfill()` action
- Route: `POST /order-allocations/{allocation}/unfulfill`
- UI: "Undo" button in allocations table

### Online Orders UI (2026-01-10)
Web interface for Mossorders integration:
- `OnlineOrdersController` with index, preview, import methods
- Views at `resources/views/online-orders/`
- Navigation link added to main nav
- Routes at `/online-orders`, `/online-orders/preview`, `/online-orders/import`

---

## Database State

### Migrations Run
All migrations are up to date including:
- `2026_01_10_144238_add_variable_weight_to_product_variants_table`
- `2026_01_10_144304_add_actual_weight_to_order_allocations_table`
- `2026_01_10_144316_add_fulfilled_weight_to_order_items_table`

### Cheese Variants Configuration
All cheese wheel variants are marked as variable weight:
```
Mossfield Farmhouse Cheese - Whole Wheel | variable: yes | by_weight: yes
Mossfield Garlic & Basil Cheese - Whole Wheel | variable: yes | by_weight: yes
Mossfield Tomato & Herb - Wheel | variable: yes | by_weight: yes
Mossfield Mature - Whole Wheel | variable: yes | by_weight: yes
```

Vacuum packs remain fixed weight (variable: no, by_weight: no).

---

## Next Steps

1. **Fix weight input rendering** - Debug why Blade `@for` loops aren't generating HTML
2. **Test full fulfillment flow** - Enter weights, submit, verify database
3. **Test undo fulfillment** - Verify stock restoration works
4. **Online Orders testing** - Test preview and import with live Mossorders instance

---

## Useful Commands

```bash
# Clear all caches
php artisan cache:clear && php artisan view:clear && php artisan config:clear

# Check order 12 data
php artisan tinker --execute="
\$o = App\Models\Order::with(['orderItems.productVariant', 'orderItems.orderAllocations'])->find(12);
foreach (\$o->orderItems as \$i) {
    echo \$i->productVariant->name . ' - isVariableWeight: ' . (\$i->isVariableWeight() ? 'yes' : 'no') . PHP_EOL;
}
"

# Check migration status
php artisan migrate:status | tail -10

# Run development server
composer run dev
```
