# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Key Docs

- **`README.md`** — feature overview, dev setup, API reference
- **`DEPLOYMENT.md`** — production install/upgrade runbook (env vars, MySQL bootstrap, IP allowlist, scheduler cron, smoke tests). Start here for any "how do I deploy / set up a new prod install" question.
- **`SECURITY_NEXT_STEPS.md`** — security-review tracking doc + token-rotation runbook
- **`WORK_IN_PROGRESS.md`** — current in-flight issues and recently completed work
- **mossorders side:** same split exists at `/var/www/html/mossorders/{README,AGENTS,DEPLOYMENT}.md`

## Project Overview

Laravel 12 app for Mossfield Organic Farm — a dairy producer (bottles milk, makes cheese and yoghurt) — providing batch traceability, order management, and invoicing.

**Tech Stack:**
- **Framework**: Laravel 12 with PHP 8.2+
- **Frontend**: Blade templates with Tailwind CSS and Alpine.js
- **Build System**: Vite for asset compilation
- **Database**: SQLite (default) with Eloquent ORM
- **Authentication**: Laravel Breeze (supports username OR email login)
- **Testing**: PHPUnit with Feature and Unit test suites

## Development Commands

### Starting Development
```bash
composer install
npm install
cp .env.example .env          # if needed
php artisan key:generate
touch database/database.sqlite && php artisan migrate
composer run dev              # server + queue + logs + Vite
```

### Individual Services
```bash
php artisan serve                  # PHP dev server
npm run dev                        # Vite assets
php artisan queue:listen --tries=1 # queue worker
php artisan pail --timeout=0       # log viewer
```

### Building for Production
```bash
npm run build        # build frontend assets
php artisan optimize # optimize Laravel
```

### Testing
```bash
composer run test            # or: php artisan test
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit
php artisan test tests/Feature/ExampleTest.php
```

### Code Quality
```bash
./vendor/bin/pint            # code formatter
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

## Architecture

### Directory Structure
- `app/Http/Controllers/` - HTTP controllers including Auth controllers from Breeze
- `app/Models/` - Eloquent models
- `app/View/Components/` - Blade components (AppLayout, GuestLayout)
- `resources/views/` - Blade templates with auth views and dashboard
- `resources/js/` - JavaScript (Alpine.js setup)
- `resources/css/` - CSS (Tailwind)
- `routes/` - Route definitions (web.php, auth.php)
- `database/migrations/` - Database migrations
- `tests/` - PHPUnit tests (Feature and Unit)

### Key Components
- **Authentication**: Laravel Breeze (login, password reset; registration/verification disabled — see below)
- **User Management**: Profile editing and account deletion
- **Frontend**: Server-side rendered Blade + Tailwind, Vite asset pipeline with HMR

### Configuration
- Database configured for SQLite in `.env`; asset compilation in `vite.config.js`
- Tailwind in `tailwind.config.js` (forms plugin); PHPUnit in `phpunit.xml` (SQLite in-memory test DB)

## Database

SQLite by default (`database/database.sqlite`).

### Common Database Operations
```bash
php artisan make:migration create_example_table
php artisan migrate
php artisan migrate:rollback
php artisan db:seed
php artisan tinker          # access database directly
```

## Frontend Development

Blade templates with Tailwind CSS (forms plugin), Alpine.js for interactivity, and Vite for asset building/HMR. Reusable UI via Blade components.

### Asset Files
- `resources/css/app.css` - Main CSS
- `resources/js/app.js` - Main JS (Alpine.js)
- `resources/js/bootstrap.js` - Bootstrap config with Axios

## Authentication Flow

Based on Laravel Breeze, modified for a closed operator/factory/driver system:
- Login/logout (username OR email — email is optional)
- Password reset (email users only — degrades gracefully for email-less accounts)
- Profile management (edit profile, change password, delete account)
- **Self-registration is disabled** — admin creates users via `/users`
- **Email verification is disabled** — factory and driver users often have no work email

### Username Authentication
- Login form accepts "Username or Email"; `LoginRequest::getLoginField()` switches on `filter_var(..., FILTER_VALIDATE_EMAIL)`
- `email` is nullable on `users`; `username` is the primary identifier
- Inactive users (`is_active = false`) are rejected at login with the standard `auth.failed` message — no info leak about whether the account exists

### Dev / Staging Seeded Users
`AdminUserSeeder` creates one user per role for testing (shared password: `admin123`, override via `ADMIN_SEED_PASSWORD`):

| Username | Role | Email | Purpose |
|---|---|---|---|
| `admin` | admin | admin@osmanager.local | Full access including user management |
| `office_test` | office | office@osmanager.local | Operational access (no user mgmt) |
| `factory_test` | factory | *(none)* | Packing-floor view; no email on purpose |
| `driver_test` | driver | *(none)* | Deny-all baseline until manifest ships |

Seeder refuses to run in production. All auth routes are in `routes/auth.php` (controllers in `app/Http/Controllers/Auth/`) — the `register` and `verification.*` routes are removed but the unused controller classes remain.

## Database Connections

### Primary Database
SQLite by default (`database/database.sqlite`); config in `config/database.php`.

### POS Database (Placeholder)
A commented-out read-only `pos` connection in `config/database.php` for future uniCenta integration — uncomment and set env vars when ready. Usage: `DB::connection('pos')->table('products')->get()`.

## External Integrations

### Mossorders Online Portal Integration

Links office customers to external Mossorders user accounts.

**Customer Model Fields**:
- `mossorders_user_id` (nullable, unique) - Links office customer to a Mossorders user; each Mossorders user maps to at most one office customer. Deleting a Mossorders user does not cascade (preserves office data integrity).

**Helper Method**:
```php
$customer->hasOnlineAccount()  // Returns true if linked to Mossorders
```

**Database**: Migration `2025_11_22_163725_add_mossorders_user_id_to_customers_table.php`; unique index `customers_mossorders_user_unique`.

**Usage**: preparation for future integration — lets API endpoints/admin tools establish customer-user mappings for online ordering access.

## Security Posture

The sync integration has been hardened across three phases. The operator-facing runbook lives in `SECURITY_NEXT_STEPS.md` (DB migrations, `.env` flips, token rotation). Invariants to respect when editing code:

### Auth & middleware
- **Routes are grouped by role, not by `verified` status.** Email verification is disabled (see Authentication Flow). Two tiers of business route groups exist in `routes/web.php`:
  - `['auth', 'role:admin,office,factory']` — shared read-capable routes (products, batches, orders, stock, cheese-cutting index, **mature-conversion index** at `/cheese-conversion`) **plus the mobile picking flow at `/picking`** (see "Mobile Picking Flow" below) **and the chilled run sheet at `/chilled-runs`** (see "Chilled Run Sheet" below). Factory is otherwise view-only; writes are blocked via policies inside the controller (`$this->authorize(...)` per action). The two factory write carve-outs are `OrderPolicy::fulfill` (allocate + fulfil + undo picks) and `OrderPolicy::load` (the chilled-run "loaded onto van" tick) — general order editing stays office/admin.
  - `['auth', 'role:admin,office']` — office-only flows (customers, product variants, order-allocations, online-orders, cheese-cutting write actions, **mature-conversion writes** — hold/release/undo, see "Mature Conversion" below — delivery-run management at `/delivery-runs`).
  - `['auth', 'role:admin']` — user management at `/users`.
  - Profile + password/logout stay on `auth`-only so every authenticated user can manage their own account.
- **`api.token` middleware chain**: on `/api/*` routes, always combine `throttle:sync-api` (60/min per IP, registered in `AppServiceProvider`) with `api.token`. The middleware itself chains IP allowlist → token check → audit log. Don't bypass.
- **Token comparison must be `hash_equals`**. The middleware accepts both `OFFICE_API_TOKEN` and `OFFICE_API_TOKEN_PREVIOUS` (two-token rotation window).
- **`SecurityHeaders` middleware** is appended globally in `bootstrap/app.php` — sets HSTS (prod only), X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy on every response.

### Boot-time guards (all in `AppServiceProvider::boot`)
- Refuses to start if `APP_ENV=production` AND `APP_DEBUG=true`.
- Refuses to start if `APP_ENV=production` AND `services.mossorders.base_url` is not `https://`.
- If you add new outbound sync targets, extend the same guard.

### PII encryption (`Customer` model)
- `phone`, `address`, `city`, `postal_code`, `notes` use the `App\Casts\EncryptedNullable` cast. Written values are encrypted; reads fall back to plaintext for pre-migration rows and log a warning.
- **Never add a `where('phone', ...)` or similar query** on encrypted columns — the ciphertext differs per-write. Query by `name`, `email`, or `mossorders_user_id` instead.
- When adding a new PII column, apply the same cast. If it needs to be searchable, add a separate `_hash` blind-index column.

### Audit + sync logging
- `sync` is a dedicated log channel (daily, 14-day retention) at `storage/logs/sync.log`. Route anything scheduler-related there via `Log::channel('sync')`.
- `ApiTokenAuth` emits `api_token_auth: accepted|invalid|ip not allowed` with IP, URI, and an 8-char SHA256 fingerprint of the token — do not log the raw token.
- `ImportOnlineOrders` and `SyncOfficeProducts` emit one `run summary` line per invocation with a UUID correlation id; cache `sync:*:last_ok` on success. Dashboard reads the cache to render freshness.

### Outbound HTTP
All sync services chain `->withOptions(['verify' => true])->connectTimeout(5)->timeout(10)->retry(3, 500, throw: false)`. Match this pattern for any new external call.

### Schedule
Hourly sync registered in `routes/console.php`, gated on `config('services.sync.enabled')` (env `SYNC_SCHEDULE_ENABLED`, default false). `emailOutputOnFailure` is wired when `SYNC_ALERT_EMAIL` is set.

### Roles & authorization
- `App\Enums\UserRole` — `admin | office | factory | driver`. Required on every user (`users.role`). Also `users.is_active` (default true); inactive users are rejected at login by filtering the `Auth::attempt` credentials array.
- Policies live in `app/Policies/`, registered in `app/Providers/AuthServiceProvider.php`. `BasePolicy` is the CRUD baseline (`admin` via `before()` → all, `office` → full CRUD, `factory` → `viewAny`/`view` only, `driver` → deny-all). `UserPolicy` is admin-only. Add per-model policies by extending `BasePolicy`; override only for narrower rules.
- **Factory picking carve-out**: `OrderPolicy::fulfill` grants admin/office/factory the narrow ability to record picks (allocate stock, fulfil with weights, undo a pick) via the `/picking` routes. It deliberately does NOT widen `update` — factory still cannot edit orders, change lines, cancel, or use the office `order-allocations.*` routes. Every `PickingController` action checks `$this->authorize('fulfill', $order)`.
- **Controllers enforce writes via `$this->authorize('ability', $model)`** at the top of each action — `authorizeResource()` doesn't work in Laravel 12 because the new base `Controller` is a POPO without a `middleware()` method. Add explicit `authorize` calls in any new write action.
- Column-level `see-financials` gate in `AuthServiceProvider::boot()` (admin/office only). Used in order blades (`@can('see-financials') ... @endcan`) to hide unit prices, line totals, subtotals, tax, and totals from factory users who share the `/orders` read screens.
- **Last active admin is protected** — `User::isLastActiveAdmin()` returns true if demoting/deactivating/deleting this user would leave zero active admins. Called from `UserController::update/destroy/deactivate` and `ProfileController::destroy`. Block with a flash error on hit; never allow it through.
- **Session invalidation** on role change + deactivation — `User::logOutEverywhere()` deletes rows from the database `sessions` table where `user_id = $this->id`. Requires `SESSION_DRIVER=database` (default). Don't skip this when changing role or flipping `is_active` to false — elevated cookies would otherwise outlive the change.
- **Role-gated nav** in `resources/views/layouts/navigation.blade.php` — shared `@php` block defines `$canSeeCustomers`/etc., wrapping both desktop and responsive link lists. Nav visibility is separate from policy access (nav gates discovery, policy gates access — they can diverge, e.g. factory CAN view Customer model inline but has no Customers nav link).

### Seeders
`AdminUserSeeder` refuses to run in production. Password overridable via `ADMIN_SEED_PASSWORD`. Besides `admin`, it seeds `office_test`, `factory_test` (no email), and `driver_test` (no email) for local role testing.

## Troubleshooting

### Storage Permission Issues
"Permission denied" errors for `storage/framework/views/` happen when Laravel runs under different users (web server vs CLI). Fix:

```bash
php artisan view:clear
php artisan config:clear
php artisan route:clear
chmod -R 775 storage/ bootstrap/cache/
```

## Business Context

Mossfield Organic Farm produces three product types:
1. **Milk**: 1L and 2L bottles (batch code: Mddmmyy)
2. **Yoghurt**: 250g and 500g tubs (batch code: Yddmmyy)
3. **Cheese**: Farmhouse, Garlic & Basil (seeded); **Mature** (seeded — a premium product produced by aging Farmhouse, not made fresh; see "Mature Conversion" below). Tomato & Herb, Cumin Seed are aspirational varieties not yet seeded. Batch code: Gddmmyy.
   - Produced as wheels, later cut into vacuum packs; requires maturation tracking; each vacuum pack must be traceable to its original wheel.
   - **Mature** is reached by setting Farmhouse wheels aside to age (a reversible "maturing hold"), then releasing them into the Mature product once ~5 months old.

## Implemented Features

### ✅ **Core Production & Batch Management**
- **Batch Traceability**: tracking with automated batch code generation
- **Product Management**: products with full CRUD
- **Product Variant Management**: full CRUD for variants (sizes, packaging) — weight, price, active status per variant; nested routes `/products/{product}/variants/*`
- **Cheese Cutting System**: convert wheels to vacuum packs with full traceability
- **Mature Conversion** (`/cheese-conversion`): set Farmhouse wheels aside to age (reversible maturing hold, excluded from orders), then release aged wheels into the separate Mature product — see "Mature Conversion" below
- **Stock Overview**: real-time stock levels and valuations
- **Grouped Batch Browser** (`/batches`): three-level collapsible hierarchy (type → cheese variety → batch) via native `<details>`/`<summary>`. Each batch card header shows an at-a-glance status strip: one circle per wheel produced (**yellow**=free, **amber**=allocated, **deep-amber**=maturing hold, **grey**=cut, **black**=sold whole) plus a 128px stacked mini-bar for vacuum packs (yellow=remaining, black=sold). Cheese cards also show a compact **lineage line** — "Matured from …" on a Mature batch and "Matured into …" on a farmhouse batch that has fed one (via `source_batch_id`). Same layout on `/cheese-cutting`, with the "Cut Wheel" action inside each expanded body.

### ✅ **Stock Overview (`/stock`)**
- **Per-type cards** (milk, yoghurt, cheese) each rendered by a dedicated row component (`x-stock.case-blocks`, `x-stock.case-pictograph`, `x-stock.cheese-row`).
- **Row-per-(variant, batch)**: `StockOverviewService` groups items by `product_variant_id|batch_id` so each row tags its own `batch_code` and per-batch `expiry`. Rows sort by variant name then production date (FIFO).
- **Sold visibility**: milk/yoghurt mirror cheese — `total` is `quantity_produced`, segments break into `available / allocated / sold` (`sold = produced − remaining`); sold cases render with `--state-sold`.
- **Cheese wheels**: `available / allocated / cut / sold` breakdown; `cut` comes from `source_cutting_logs_count` per batch_item.
- **Active filter**: only batches with `status = 'active'` AND `(expiry_date IS NULL OR expiry_date >= today)` contribute; closed/expired fall off automatically.
- **Total value**: top-of-page euro figure summed from `productVariant->calculatePrice(available_quantity)` so weight-priced cheese is valued correctly.

### ✅ **Customer & Order Management**
- **Customer CRUD**: create/view/edit/delete; search & filter (name, email, status, online account); credit limits, payment terms, outstanding balances; full address; Mossorders link via `mossorders_user_id`; order history.
- **Order Processing**: full workflow from pending to fulfilled
- **Stock Allocation**: FIFO allocation with real-time availability; **Auto-Allocation** for automatic assignment
- **Variable Weight Fulfillment** (cheese): per-unit weight entry with running total, weight-based pricing (€/kg), fulfilled totals from actual vs estimated weight. Pre-fulfillment `line_total` is weight-aware (`qty × weight_kg × unit_price` for weight-priced items, set in `OrderItem::boot()`); `fulfilled_total` overrides once actual weights are known.
- **Undo Fulfillment**: reverse fulfillments and restore stock
- **Online Orders UI** (`/online-orders`): preview and import orders from the Mossorders portal
- **Email Documents** (`POST /orders/{order}/email/{document}`, `orders.email`): one-click **Email invoice** / **Email docket** buttons on `orders/show` send the document to `customer.email` as a PDF attachment over SMTP. Office/admin only (factory can view/print the docket but not email it); invoice adds the `see-financials` + `hasReachedReady()` guards. Reuses the same `Pdf::loadView('orders.{doc}', ['pdf' => true])` render via the `App\Mail\OrderDocumentMail` Mailable (body: `resources/views/emails/order-document.blade.php`). **Sent synchronously** (immediate success/failure flash); failures logged to the `sync` channel. Needs `MAIL_MAILER=smtp` in prod (dev defaults to `log`).

### ✅ **Business Intelligence**
- **Maturation Timeline**: cheese aging visualization
- **Expiry Tracking**: alerts for items nearing expiry
- **Stock Valuation**: real-time stock values
- **Production Planning**: 6-month production overview

### 🔄 **API Integration & Mossorders Sync**
- **Product Export API** — `GET /api/products`, Bearer token (`OFFICE_API_TOKEN`). Returns flattened variants with stock availability; incremental sync via `updated_since`; prices in euros. Each payload includes `is_priced_by_weight` so mossorders can render `€/kg` labels and weight-based estimates.
- **Mossorders Order Import** — commands `php artisan mossfield:preview-online-orders [--since=]` (read-only) and `mossfield:import-online-orders [--since=]`. Import maps to customers via `customers.mossorders_user_id`, is idempotent, auto-generates office order numbers, and tracks source via `orders.mossorders_order_id`. Customer `notes` from the online order are preserved (falls back to `"Imported from Mossorders order …"` when absent — same fallback in `OnlineOrdersController`). Configure `MOSSORDERS_BASE_URL` and `MOSSORDERS_API_TOKEN`.

### 🔄 **Future Enhancements**
- Invoice generation per order; customer portal; advanced reporting; POS integration (uniCenta ready)

## Product & Variant Structure

### Products
Main categories (e.g. "Mossfield Organic Milk", "Mossfield Farmhouse Cheese").

**Key Fields**: `name`; `type` (milk/yoghurt/cheese); `maturation_days` (cheese only); `shelf_life_days` (optional, any type — when set, the batch create form auto-fills `expiry_date = production_date + shelf_life_days`; milk products were backfilled to 10); `is_active`.

### Product Variants
Specific sizes/packaging (e.g. "1L Bottle", "Whole Wheel", "Vacuum Pack").

**Key Fields**:
- `product_id` - Parent product
- `name`, `size`, `unit` - e.g. "Vacuum Pack" / "pack" / "pack"
- `weight_kg` - Weight in kilograms (decimal)
- `base_price` - Price in euros (€)
- `is_variable_weight` - Boolean: weight entered at fulfillment (cheese wheels & packs)
- `is_priced_by_weight` - Boolean: priced per kg (€/kg)
- `is_bulk_weighed` - Boolean: when variable-weight, the entry style — `false` = per-unit weights (wheels), `true` = one total weight for the line (vacuum packs)
- `is_active` - Variant availability

**Variable Weight Products**:
- Cheese is variable weight (weighed at fulfillment); all cheese variants are flagged `is_variable_weight` (data migration `2026_05_25_120100_enable_variable_weight_on_cheese_variants`; seeder sets it on fresh installs). Milk/yoghurt are fixed.
- **Two entry styles** (per variant, via `is_bulk_weighed`): wheels → a weight per unit (#1, #2, #3… with a running total); vacuum packs → a single "Total weight (kg)" box (per-pack entry is impractical at scale). Both submit one `actual_weight_kg`; the fulfil controller/model are identical for both.
- `is_priced_by_weight` is independent and **operator-set per variant** — until ticked (with a €/kg `base_price`), weight is recorded but invoicing stays per-unit. When on, `fulfilled_total = weight_fulfilled_kg × unit_price`.
- Price displayed as €X.XX/kg for weight-priced items.

### Pricing Display Helpers
The `ProductVariant` model centralises all price formatting and estimation. Always use these accessors instead of hand-formatting `base_price`:

- `$variant->price_label` — `"€12.50/kg"` for weight-priced variants, `"€3.50"` otherwise
- `$variant->estimated_unit_price` — per-unit price estimate (uses nominal `weight_kg` when priced by weight); use for cart line totals and `data-price` attributes
- `$variant->calculatePrice($quantity, $weightKg = null)` — weight-aware total; pass an actual weight to override the nominal estimate

`OrderItem::boot()::saving` uses the same logic when computing `line_total`. `StockController` valuations use `calculatePrice()` to avoid the N×base_price overstatement on weight-priced cheese.

**Estimate vs actual on orders** — `OrderItem.line_total` is the pre-fulfilment **estimate** (nominal weight); once a line is **fully fulfilled**, `OrderItem.fulfilled_total` holds the **actual** (recorded weight × `unit_price`). `OrderItem::invoiceable_total` returns the fulfilled total when fully fulfilled (and > 0) else the estimate, and `Order::calculateTotals()` sums `invoiceable_total` — so an order's stored `subtotal`/`total_amount` reflect actual fulfilled weight once picked. `OrderAllocationController::fulfill()`/`unfulfill()` re-run `calculateTotals()`. Order views show `invoiceable_total` with a `kg` hint for weight-priced lines. **Note:** `unit_price` is locked per line at order-creation from the **customer's rate** for the variant (see "Customer Special Prices" below — a per-customer override if set, else the then-current `base_price`); if a variant's pricing mode/rate changes later (e.g. per-unit → €/kg) or a special price is added/changed, pre-existing orders keep the old `unit_price` and need a manual reprice.

### Products Master-Detail View
`/products` is the grouped-card index; `/products/{product}` (show), `…/edit`, `…/variants/create`, and `…/variants/{variant}/edit` are all master-detail — sibling products in a left pane (`lg:grid-cols-[320px_1fr]`, hidden below `lg`), selected content on the right.

- **Shared list-builder**: `app/Http/Controllers/Concerns/BuildsProductList` trait. `ProductController::show/edit` and `ProductVariantController::create/edit` all `use BuildsProductList` and call `$this->buildProductList($request, $product)` to populate `$productList`, `$listFilters`, `$listTotal`, `$listLimit`. **Don't drop these from the views** — the shared sidebar partial requires them.
- **Sidebar partial**: `resources/views/products/_sibling_list.blade.php`. Consumes `$mode` (`'show'`/`'edit'`) — sibling rows link via `route('products.'.$mode, …)`. Variant pages pass `$mode = 'show'`.
- **Variant nesting in sidebar**: pass `$showActiveVariants = true` and (optionally) `$activeVariant = $variant` to expand the active product's row with its variant list inline. Variant pages opt in; product show/edit don't.
- `ProductController::update()` redirects to `products.show`, not `products.index`; `destroy()` redirects to index. `ProductVariantController` store/update/destroy redirect to `products.show`.
- Sidebar groups by `$typeOrder = ['milk','yoghurt','cheese']` (matches `ProductController::index()` line 16 and the trait) — keep the three locations in sync if a new product type is added.

### Controllers
- **ProductController** — CRUD; `index()`/`show()` eager-load variants with `withSum('batchItems as total_stock', ...)` to avoid N+1 on stock display.
- **ProductVariantController** — CRUD for variants (nested under products). Routes `products.variants.{create,store,edit,update,destroy}`; views `resources/views/products/variants/{create,edit}.blade.php`. `create()` accepts `?from={variantId}` to prefill from an existing variant (exposed as `$source`).
- **BatchController** — Batch CRUD + grouped browser at `/batches`. `index()` eager-loads `batchItems` with `withCount('sourceCuttingLogs')` so the "cut" count is one query, not N+1. View composes three nested `<details>` levels, deferring each card to `resources/views/batches/partials/batch-card.blade.php`.
- **CheeseCuttingController** — same eager-load pattern on `index()`; view at `resources/views/cheese-cutting/index.blade.php` uses `partials/batch-card.blade.php` with per-wheel **Cut Wheel** buttons in the expanded body.

### Wheel & Vac-Pack Visualization (shared logic)
Cheese batches render an at-a-glance status strip in the card header:
- **Wheels**: one circle per unit of `quantity_produced`. `cut = source_cutting_logs_count` (requires the `withCount` above), `sold = produced − remaining − cut`, `remaining = produced − cut − sold`, `allocated = min(remaining, unfulfilled allocations)`, `free = remaining − allocated`. Always clamped to `max(0, …)` to survive counter drift.
  - **Maturing hold**: `maturing = min(free, quantity_maturing)` is then carved *out of* `free` (held wheels live inside `quantity_remaining`/`free` — see "Mature Conversion") and rendered as a distinct deep-amber circle using the shared `var(--state-maturing)` token (defined in `resources/css/stock.css`, imported by `app.css`, so it matches the `/stock` maturing segment). The numeric summary is `free · allocated · maturing · cut · sold / produced` (maturing shown only when > 0). The batches-index card's per-item detail table also has a **Maturing** column. This formula is duplicated across `resources/views/batches/{index.blade.php,partials/batch-card.blade.php}` and `resources/views/cheese-cutting/{index.blade.php,partials/batch-card.blade.php}` — keep the four in sync.
- **Vac packs**: a 128px stacked bar (`w-32 h-2`) with yellow=remaining / black=sold proportional to `quantity_produced`. One row per non-wheel cheese batch item with `produced > 0` (per-pack markers impractical — counts reach hundreds). Maturing does not apply to packs (only wheels are held/released).
- Same classification (`str_contains(strtolower($variant->name), 'wheel')`) is used in `CheeseCuttingController::store()` — keep aligned when renaming variants.
- Each cheese sub-group header on `/batches` and `/cheese-cutting` also shows aggregate free/allocated/maturing/cut/sold totals so the summary stays visible when batches are collapsed.
- **Lineage line** (batches index card only): `BatchController::index()` eager-loads `sourceBatch.product` + `matureBatches.product`; the card renders "Matured from `<code>`" / "Matured into `<code>`" links (mirrors `batches/show.blade.php`). Cheese-only, shown only when a conversion link exists; the `/cheese-cutting` card deliberately omits it.

### Validation
Both controllers delegate to FormRequests (`app/Http/Requests/ProductRequest.php`, `ProductVariantRequest.php`):

- Product names unique (`unique:products,name`); variant names unique within their product (`unique:product_variants,name` scoped to `product_id`)
- `maturation_days` required when `type=cheese`
- `weight_kg` required unless `is_variable_weight=true` AND `is_priced_by_weight=false` (weight is needed whenever it's load-bearing for pricing)
- `base_price` minimum 0.01 (no free variants)
- Checkbox booleans coerced via `prepareForValidation()` so unchecked values become `false` instead of being dropped

## Customer Structure

### Customers
Buyers placing orders through the office system or online portal.

**Key Fields**: `name`; `email` (unique); `phone` (optional); `address`/`city`/`postal_code`/`country`; `credit_limit` (€); `payment_terms` (enum: `immediate`, `net_7`, `net_14`, `net_30`); `is_active`; `requires_reference` (boolean, default false — when set, the optional "Customer ref" field auto-expands for this customer on the order forms and Chilled Runs row; plain boolean, safe to query); `notes`; `mossorders_user_id` (nullable, unique).

**Helper Methods**: `hasOnlineAccount()`; `getOutstandingBalanceAttribute()`; `canPlaceOrder(float $orderAmount)`.

**Controller**: `CustomerController` — full CRUD. Routes `customers.{index,create,store,show,edit,update,destroy}`; views in `resources/views/customers/`. Features: search/filter, order history, online account status.

### Customer Special Prices

Per-customer alternative unit price for a specific product variant (negotiated/wholesale rates). Table `customer_special_prices` (migration `2026_07_11_120000_...`): `customer_id` + `product_variant_id` (both `constrained()->cascadeOnDelete()`), `decimal('price', 8, 2)`, `unique(customer_id, product_variant_id)` — one override per (customer, variant). Model `CustomerSpecialPrice` (belongsTo customer + productVariant). `Customer::specialPrices()` (hasMany).

- **The single leverage point**: `Customer::unitPriceFor(ProductVariant $variant): float` returns the special price if one exists, else `$variant->base_price`. The stored price is in the **same units as `base_price`** — €/unit, or €/kg for weight-priced variants — so it drops straight into `order_items.unit_price` and all downstream line/total/invoice math (which reads the `unit_price` snapshot) stays correct with no other changes.
- **Applied at the two internal order-creation sites only**: `OrderController::store()` and `MutatesOrderLines::applyLineQuantity()` (the latter shared by `storeItem`, order edits, and `ChilledRunController::saveStop()`) — both call `$customer->unitPriceFor($variant)` instead of `$variant->base_price`. **Mossorders imports are unchanged** (`ImportOnlineOrders` / `OnlineOrdersController` keep the payload's `unit_price`); the product export API still exposes only `base_price`.
- **Snapshot semantics**: like all pricing, `unit_price` is locked at line creation — adding/changing a special price only affects **new** lines; existing orders keep their old price (manual reprice, same as any base_price change).
- **Management UI**: office/admin only, on the **customer show page** — a "Special prices" panel (add via variant `<select>` + price, per-row edit + remove). Controller `CustomerSpecialPriceController` (`store`/`update`/`destroy`), FormRequest `CustomerSpecialPriceRequest` (scoped-unique per customer, `price >= 0.01`). Routes `Route::resource('customers.special-prices', …)->only(['store','update','destroy'])` in the `role:admin,office` group (factory 403 at middleware, like `customers`/`products.variants`). `CustomerController::show()` eager-loads `specialPrices.productVariant.product` and passes the grouped active-variant list.
- Tests: `tests/Feature/CustomerSpecialPriceTest.php` (order-store uses special vs base, weight-priced flows into `line_total`, manage CRUD + duplicate rejection, panel renders, factory denied).

## Order Structure

### Orders
Track customer purchases and fulfillment status.

**Key Fields**: `order_number` (auto, `ORD-YYYYMMDD-XXX`); `customer_id`; `order_date`/`delivery_date`; `status` (enum: `pending`, `confirmed`, `preparing`, `ready`, `dispatched`, `delivered`, `cancelled`); `payment_status` (enum: `pending`, `paid`, `partial`, `overdue`); `subtotal`/`tax_amount`/`total_amount` (€); `delivery_address` (optional override); `notes`; `customer_reference` (nullable string — the customer's own reference / PO number, distinct from the auto `order_number`; see "Customer Reference" below); `mossorders_order_id` (nullable, unique).

**Helper Methods**: `scopeFromMossorders($query)`; `isFullyAllocated()`; `canBeCancelled()`.

### Currency
All prices throughout the application are displayed in euros (€).

### Customer Reference (optional PO number)
`orders.customer_reference` (nullable string) holds the **customer's own reference / purchase-order number** — distinct from the auto-generated `order_number`. Only a few customers need it, so the input stays tucked behind a small reveal button and doesn't draw the eye; it auto-expands for customers flagged `customers.requires_reference`. Settable on create and **editable anytime** (order edit page + Chilled Runs inline editor). No € / financial implications.

- **Migrations**: `2026_06_27_120000_add_customer_reference_to_orders_table` + `2026_06_27_120100_add_requires_reference_to_customers_table`. `customer_reference` is in `Order::$fillable`; `requires_reference` is in `Customer::$fillable` with a `boolean` cast.
- **Order forms** (`OrderController::store/update`): both validate `nullable|string|max:255`. The create/edit blades wrap the field in a small Alpine reveal (`x-data="{ showRef }"`) — the create form seeds a `customer_id ⇒ requires_reference` map and `@change`s on the customer `<select>` to auto-open; the edit form pre-opens when a value exists or the customer requires one.
- **Show page**: renders a "Customer ref" row in the Order panel only when set. **`customer_reference` is in `$statusFields`** (`orders/show.blade.php`) — the inline Confirm/Cancel PATCH forms replay `$statusFields` as hidden inputs, so omitting it would blank the ref on any status change. Add it to `$statusFields` if you ever add new replayed quick-actions.
- **Customer flag**: `CustomerController::store/update` validate `requires_reference` as boolean and coerce via `$request->has(...)` (mirrors `is_active`); create/edit blades have the checkbox; show page shows a "Ref required" badge.
- **Chilled Runs** (`ChilledRunController::saveStop`): validates + persists/clears the ref (empties normalised to null since the always-in-DOM hidden input posts `''`); `buildSheet()` exposes `references` (per-row, for display) + `editCustomerReference` (edit prefill). The `_run-table` editor adds `reference`/`showExtras` to the `stopEditor` Alpine state (auto-open for ref'd customers, dirty-tracked) behind a "+ Ref" toggle. A ref with **no quantities and no existing order is intentionally not persisted** (no empty orders).
- Tests: `tests/Feature/OrderCustomerReferenceTest.php` (store/update persist, survives status-only PATCH, requires-reference auto-expand) + the three `customer_reference` cases in `ChilledRunTest`.

### Delivery Charge (per-run, per-customer)
A **VAT-inclusive (gross)** € delivery charge defined **per DeliveryRun** (`delivery_runs.delivery_charge`, migration `2026_07_23_120000`), toggled **per customer** (`customers.apply_delivery_charge` boolean, plain column, migration `2026_07_23_120100`), and **snapshotted onto each order** (`orders.delivery_charge`, migration `2026_07_23_120200`). Operators enter the figure the customer actually pays; the charge sits **outside** the subtotal (its own net invoice line) and its contained **23% VAT** is broken out into `orders.tax_amount` — the only non-zero use of tax (products stay 0% VAT).

- **The single leverage point**: `Customer::currentDeliveryCharge(): float` returns the customer's current run charge (gross) iff `apply_delivery_charge` AND the customer is on a run AND that run's charge > 0, else 0. Called at **every order-creation site** — `OrderController::store()`, `ChilledRunController::saveStop()`, and both Mossorders import paths (`ImportOnlineOrders::processOrder()` + `OnlineOrdersController::import()`, which now also call `$order->calculateTotals()` so imported totals reflect actual lines + charge). Snapshot semantics like `unit_price`: changing a run's charge later never touches existing orders.
- **VAT math** lives in `Order::calculateTotals()` — the stored `delivery_charge` is gross, so `net = round(gross / (1 + Order::DELIVERY_CHARGE_VAT_RATE), 2)` (0.23), `tax_amount = round(gross − net, 2)`, `total = subtotal + gross`. VAT is derived by **subtraction** (not `gross × rate`) so `net + tax == gross` exactly — the total matches the entered figure with no penny drift. `Order::delivery_charge_net` accessor exposes the net for display. All 9 `calculateTotals()` call sites (pick/edit/undo/cancel) preserve it automatically.
- **Editable per order** (office/admin): a "Delivery charge (incl VAT)" input on `orders/edit.blade.php`; `OrderController::update()` validates `nullable|numeric|min:0`, coerces blank→0 (column is NOT NULL), then re-runs `calculateTotals()`. **`delivery_charge` is in `$statusFields`** (`orders/show.blade.php`) — the replayed Confirm/Cancel PATCH forms would zero it via that coercion otherwise (same trap as `customer_reference`).
- **Toggle UI**: on the **delivery-runs index**, per-stop, hidden by default behind an Alpine `showCharges` reveal ("Show charge settings" button — off on every fresh load; the toggle route redirects with `?charges=1` to keep it open for consecutive flags). `POST /delivery-runs/stops/{customer}/charge` → `DeliveryRunController::toggleCharge()` (office/admin group; mirrors `unassign()` — resolves the run, `authorize('update', $run)`). The run's charge amount also shows as a tag in the panel header when the reveal is open.
- **Display**: a "Delivery charge" row (the **net** `delivery_charge_net`) + "VAT (23%)" line on `orders/show` (inside `@can('see-financials')`) and `orders/invoice`, rendered only when > 0 — so Subtotal + net + VAT = Total reconciles. **Never** on the docket, picking screens, or the chilled-run sheet (all €-free — the sheet's no-€ test still passes).
- **Percentage variant** (per customer, overrides the fixed run charge): `customers.delivery_charge_percent` (nullable decimal, migration `2026_07_23_130000`) holds a negotiated **% of order value**, snapshotted per order into `orders.delivery_charge_percent` (migration `2026_07_23_130100`). Also VAT-inclusive, but **recomputes live**: when `orders.delivery_charge_percent` is set, `Order::calculateTotals()` recomputes `delivery_charge = round(subtotal × pct / 100, 2)` on **every** recalc (so it tracks line edits/picks), then the same gross→net→VAT back-out runs — all display/`$statusFields`/`delivery_charge_net` code is unchanged because `orders.delivery_charge` stays the single gross € source of truth. **Precedence**: `Customer::currentDeliveryCharge()` returns 0 when `Customer::deliveryChargePercent()` (the rate, or null) is non-null, so a % customer ignores the run's fixed charge; both are snapshotted at the four creation sites (`'delivery_charge' => currentDeliveryCharge()`, `'delivery_charge_percent' => deliveryChargePercent()`). Configured on the **customer create/edit form** ("Delivery charge (%)" in Business terms; `CustomerController` validates `nullable|numeric|min:0|max:100`, blank→null via `normalizeChargePercent()`), shown as an `mf-tag` badge + a Business-terms row on `customers/show`. Per-order editable via a "Delivery charge (%)" input on `orders/edit.blade.php` (a set % overrides the € amount; blank reverts to fixed); **`delivery_charge_percent` is also in `$statusFields`** — same replay trap: dropping it on a status PATCH would null the rate and freeze the order at the last computed €. Order show/invoice label the line "Delivery charge (10%)" when a rate is set (amount still the net).
- Tests: `tests/Feature/DeliveryChargeTest.php` (run-form persist/reject, toggle both-ways + factory-403 + unassigned-error + reveal render, snapshot via store/saveStop/import with exact VAT split + no-drift total, recalc survival, edit/zero + status-replay, show/invoice net render + factory redaction; **plus the percentage cases** — customer-form persist/range-reject, rate snapshot + gross compute, precedence over fixed, live recompute on line change, edit-recompute + blank-reverts, status-replay survival, show/invoice/customer-page display).

### Orders Master-Detail View
`/orders` is a paginated table; `/orders/{order}` is master-detail — sibling orders in a left pane (`lg:grid-cols-[320px_1fr]`, hidden below `lg`), selected order detail on the right.

- `OrderController::show(Request $request, Order $order)` accepts the same filters as `index()` (`status`, `payment_status`, `customer_id`) via query string, fetches up to 50 matching sibling orders, and force-includes the selected order even when outside the cap. View receives `$orderList`, `$listFilters`, `$listTotal`, `$listLimit`. **Don't drop these from `show()`** — the blade depends on them and degrades to an empty list otherwise.
- `orders/index.blade.php` rows link via `route('orders.show', array_merge($rowFilters, ['order' => $order->id]))` so filter state flows into the master pane. Apply the same pattern from any new entry point that should preserve filter context.

## Order Allocation & Fulfillment

### Allocation Flow
1. **Order Created** → pending
2. **Order Confirmed** → available for allocation
3. **Stock Allocated** → links order items to specific batch items (FIFO)
4. **Fulfillment** → reduces actual batch stock, records weights for variable items
5. **Order Completed** → all items fulfilled

### Inline Allocation on the Order Detail Page
The allocation/picking UI is rendered **inline on `/orders/{order}`** — there is no longer a separate full-width allocation page (it dropped the master-detail sidebar + info/customer cards, disorienting mid-workflow). The "Order items" panel is swapped for the rich allocation block when `order.status ∈ {confirmed, preparing, ready}`; other statuses show the plain read-only items table. The financial subtotal/tax/total summary lives in its own panel, rendered in both branches (gated by `@can('see-financials')`).

- **Partial**: `resources/views/orders/partials/allocation-items.blade.php` holds the per-item block (progress bar, current-allocations table with Fulfill/Remove/Undo forms, variable-weight per-unit weight inputs + JS, available-stock picker) and the "Auto allocate" button (header, `confirmed`/`preparing` only). **Every interactive form is gated `@can('update', $order)`** so factory (view-only) sees the same counts/tables read-only with no forms. The weight `<script>` is inside the partial (included once).
- **Data**: `OrderController::show()` eager-loads `orderItems.orderAllocations.batchItem.batch` and builds `$availableBatchItems` **only** when status is in the picking set AND `$request->user()->can('update', $order)`. The builder is the shared `App\Http\Controllers\Concerns\BuildsAllocationData` trait (`buildAvailableBatchItems()`), used by both `OrderController` and `OrderAllocationController` — mirrors the `BuildsProductList` pattern.
- **Action routes unchanged** (`allocate`/`deallocate`/`fulfill`/`unfulfill`/`auto-allocate`); they now `redirect()->route('orders.show', $order)` (was `back()`) so the user stays on the unified page without a referer dependency.
- The old `GET /order-allocations/{order}` route is kept but **redirects to `orders.show`** (bookmarks/worklist/dashboard). The `order-allocations.index` worklist stays; its rows and the dashboard "Allocate →" link point straight at `orders.show`.

### Adding Items to an Existing Order
Items are no longer frozen after creation. `OrderController::storeItem()` (route `POST /orders/{order}/items`, name `orders.items.store`) adds a line to an open order. Office/admin only (`$this->authorize('update', $order)`; factory 403). Allowed while status ∈ `{pending, confirmed, preparing, ready}`; **blocked** on `{dispatched, delivered, cancelled}` (returns to `orders.show` with an error flash).

- **Merge-by-variant**: if the posted `product_variant_id` is already a line, its `quantity_ordered` is incremented (the `OrderItem::boot() saving` hook recomputes `line_total`); otherwise a new line is created with `unit_price = variant->base_price` (mirrors `store()`). `Order::calculateTotals()` re-runs after either path.
- **Ready → Preparing**: adding unpicked work to a `ready` order reverts it to `preparing`. Fulfilling that line flips it back to `ready` via `OrderAllocationController::markPickingComplete()`. Other statuses unchanged.
- **UI**: an "Add item" panel on `orders/show.blade.php` (gated `@can('update', $order)` + `$canAddItems`) — a product `<select>` (grouped via `OrderController::activeVariantsGrouped()`, shared with `create()`) + quantity. `show()` only passes `$productVariants` when the user can update and the status is open. Because allocation is inline, the added line's stock picker appears immediately for `{confirmed, preparing, ready}`.
- `orders/edit.blade.php` still does **not** edit/remove existing lines — all item mutation happens on the show page.

### Editing & Removing Order Items (stock unwind)
`PATCH /orders/{order}/items/{orderItem}` (`orders.items.update` → `updateItem()`) changes a line's quantity; `DELETE` (`orders.items.destroy` → `destroyItem()`) removes a line. Same gating as add (office/admin via `authorize('update')`; blocked on `{dispatched, delivered, cancelled}`; `abort_if` belongs-to guard).

The line-mutation invariants below now live in the shared **`App\Http\Controllers\Concerns\MutatesOrderLines`** trait (`setLineQuantity` / `applyLineQuantity` / `removeLine` / `cancelOrderKeepingLines`), used by `OrderController::storeItem/updateItem/destroyItem` **and** `ChilledRunController::saveStop()` — route any new code that changes `quantity_ordered` or removes lines through it.

- **`OrderItem::releaseUnits(int $units)`** is the shared unwind helper: it releases committed quantity **reserved-first** (just lowers `quantity_allocated` / deletes the row — no stock change) then **fulfilled-last** via `unfulfillAllocation()` (which `increment`s `BatchItem.quantity_remaining` — restoring picked stock). It rolls up the line's counters and is transaction-safe (LIFO by allocation id; `unfulfillAllocation`'s own transaction nests as a savepoint).
- **Increase**: `updateItem` raises `quantity_ordered` (the `OrderItem::boot() saving` hook recomputes `line_total`); the inline picker shows the shortfall. **Decrease**: `releaseUnits(old − new)` then set the new qty. **Remove**: `releaseUnits(quantity_allocated)` (returns all reserved+picked stock) then delete — this is why the FK `onDelete('cascade')` no longer strands `quantity_remaining`.
- **`Order::reconcilePickingStatus()`** runs after every edit/remove/deallocate/unfulfill (the canonical ready⇄preparing rule): `ready` + not-fully-fulfilled → `preparing`; `preparing` + fully-fulfilled → `ready`. One-directional around that boundary — never demotes `preparing → confirmed`. (So increasing a line on a ready order reverts it to preparing; undoing a pick un-sticks a wrongly-"ready" order; removing the last unpicked line from a preparing order advances it to ready.) **`OrderAllocationController::deallocate()` and `unfulfill()` both call it** — without that, undoing a pick left an order claiming "ready for dispatch" with nothing allocated.
- **Last line**: an order can't go empty, so removing its only line **cancels the order** (keeps the line as history, returns any stock) rather than deleting it.
- **UI**: per-line "Qty + Update" and "Remove" controls live in the inline allocation partial (confirmed/preparing/ready) and in the read-only items table (pending), gated `@can('update', $order)` + `$canAddItems`. The Remove confirm/label switches to "Remove (cancels order)" when it's the only line.

### Cancellation Returns All Stock
`OrderController::update()` wraps the status write in a DB transaction; on the `* → cancelled` transition it calls `OrderItem::releaseUnits($item->quantity_allocated)` for every line — returning **both** reserved units (drops the reservation) **and** picked units (restores `BatchItem.quantity_remaining`). `Order::canBeCancelled()` allows cancelling any not-yet-shipped order (`pending|confirmed|preparing|ready`); the Cancel button on `orders/show.blade.php` is driven by it. (Earlier behaviour deliberately *retained* fulfilled allocations on cancel — changed so cancelling never strands picked stock, matching line removal; removing the last line routes through the same cancel.) Keep any new cancel paths consistent — restore stock via `releaseUnits`.

### Order Allocations Table
Links order items to batch items:
- `order_item_id` - Which order item this allocation is for
- `batch_item_id` - Which batch the stock comes from
- `quantity_allocated` / `quantity_fulfilled` - Units reserved / actually picked
- `actual_weight_kg` - Weight recorded at fulfillment (variable-weight items)
- `allocated_at` / `fulfilled_at` - Timestamps

### Variable Weight Fulfillment
For cheese wheels and other variable-weight products:

**Database Fields**: `product_variants.is_variable_weight` (requires weight entry), `product_variants.is_bulk_weighed` (per-unit vs single total), `product_variants.is_priced_by_weight` (€/kg pricing), `order_allocations.actual_weight_kg`, `order_items.weight_fulfilled_kg`, `order_items.fulfilled_total`.

**UI Behavior** (inline on `/orders/{order}` — see "Inline Allocation on the Order Detail Page"):
- Variable weight items show a "Variable Weight" badge.
- **Per-unit** (`!is_bulk_weighed`, e.g. wheels): one weight input per unit (#1, #2, #3…) with a running total summed client-side into the hidden `actual_weight_kg` (JS `updateWeightInputs`/`updateTotal`).
- **Bulk** (`is_bulk_weighed`, e.g. vacuum packs): a single "Total weight (kg)" input posted directly as `actual_weight_kg` — no per-unit rows, no JS. `OrderItem::isBulkWeighed()` selects the branch in `orders/partials/allocation-items.blade.php`.
- `OrderAllocationController::fulfill()` is unchanged — it validates `actual_weight_kg` (required for any `is_variable_weight` item) for both styles.
- Price shown as €X.XX/kg with estimated vs fulfilled totals (only when `is_priced_by_weight`).

**Controller**: `OrderAllocationController`
- `show()` - **redirects to `orders.show`** (allocation UI is now inline)
- `allocate()` - reserve stock from a batch item
- `deallocate()` - remove an allocation (if not fulfilled)
- `fulfill()` - mark allocated items as picked, record weights
- `unfulfill()` - undo fulfillment, restore stock to batch
- `autoAllocate()` - auto-allocate using FIFO
- `index()` - worklist of orders needing allocation (still its own page)

All write actions `redirect()->route('orders.show', $order)`.

### Routes
```php
GET  /order-allocations                    # Worklist of orders needing allocation
GET  /order-allocations/{order}            # Redirects → orders.show (allocation UI is inline)
POST /order-allocations/{orderItem}/allocate    # Allocate stock
DELETE /order-allocations/{allocation}     # Remove allocation
POST /order-allocations/{allocation}/fulfill    # Fulfill allocation
POST /order-allocations/{allocation}/unfulfill  # Undo fulfillment
POST /order-allocations/{order}/auto-allocate   # Auto-allocate order
```

## Mobile Picking Flow (`/picking`)

Phone-first picking surface for the **factory** role (admin/office can use it too), built from a Claude Design handoff. Five screens: Today queue → order overview → pick item (fixed-qty / per-piece weight / bulk weight) → order-ready celebration. Factory users land here after login (`AuthenticatedSessionController::store()` branches on `isFactory()`); the "Picking" nav item sits in the Sales group for admin/office/factory.

### Routes (in the `role:admin,office,factory` group)
```php
GET  /picking                                # Today queue (statuses confirmed/preparing/ready, delivery_date asc)
GET  /picking/{order}                        # Overview — renders the "Order ready" celebration when fully fulfilled
GET  /picking/{order}/items/{orderItem}      # Pick screen (or picked-summary + undo when line is done)
POST /picking/{order}/items/{orderItem}/pick # One-tap allocate+fulfil (transactional)
POST /picking/{order}/items/{orderItem}/undo # Unfulfil the line's latest pick (reservation survives)
```

### Key mechanics
- **`OrderItem::pickFromBatchItem($batchItem, $qty, $weight)`** is the one-tap pick: `order_allocations` is **unique per (order_item, batch_item)**, so a pick always lands on a single row — an existing office reservation is reused, widened for any shortfall via the private `extendAllocation()` (same guards as `allocateFromBatchItem`), then fulfilled once with the full weight. Returns false (nothing changed) on stale stock / over-allocation; the controller flashes an error.
- **Authorization**: every action calls `$this->authorize('fulfill', $order)` (`OrderPolicy::fulfill` = admin/office/factory). Status guard `ensureInQueue()` bounces non-picking statuses back to the queue.
- **Status transitions**: first pick flips `confirmed → preparing`; `Order::reconcilePickingStatus()` then handles the `preparing ⇄ ready` boundary (and undo demotes ready orders). `calculateTotals()` re-runs after pick/undo so weight-priced lines bill actual kg.
- **Batch chooser**: `PickingController::batchOptionsFor()` lists FIFO available batch items **plus** batches holding an unfulfilled reservation for the line (a fully pre-allocated batch has `available_quantity = 0` and would otherwise vanish from the picker). Each option carries `reserved` and `max` (= reserved + available).
- **Financial redaction**: all € amounts on the picking blades are gated `@can('see-financials')` — factory sees quantities/weights only (covered by a feature test).
- **Layout/CSS**: `<x-picking-layout>` (`resources/views/layouts/picking.blade.php`) is a stripped phone shell (no sidebar/topbar); `mob-*` classes live at the bottom of `resources/css/app.css` (`.mob-shell` caps width at 560px; `.mob-footer` is fixed with safe-area padding).
- Views: `resources/views/picking/{index,show,item}.blade.php`; the pick form is Alpine-driven (qty stepper clamped to batch max, per-piece weight rows summed into a hidden `actual_weight_kg`, submit disabled until weights complete). Bulk-weighed variants get a single total-weight input, mirroring the desktop partial.
- Tests: `tests/Feature/PickingTest.php` (access control, one-tap pick, reservation reuse/widening, weight validation, transitions, undo, queue scoping, € redaction).

## Chilled Run Sheet (`/chilled-runs`)

Desktop digital twin of the delivery-run spreadsheet, built from a Claude Design handoff — and (for office/admin) the **order-entry surface** that replaces it. One tab per active **delivery run** (fixed weekly route: name, `day_of_week` 1–7 or null for whole-week "w/c" runs, driver, capacity note); the sheet lists the run's customers in stop order, paired with each customer's order for the run's resolved date. Each row can be put into edit mode to enter/change that stop's order inline.

### Data model
- **`delivery_runs`** table + `DeliveryRun` model. `DeliveryRun::dateFor($weekAnchor)` resolves the concrete date inside the anchored ISO week (`?date=` query param shifts weeks; whole-week runs resolve to week start).
- **`customers.delivery_run_id`** (nullable FK, `nullOnDelete` — deleting a run un-assigns, never deletes customers) + **`customers.run_position`**. Plain columns — safe to query/order, unlike the encrypted PII columns.
- **`orders.loaded_at`** (nullable timestamp) — the per-stop "loaded onto van" tick.

### Key mechanics
- **Routes**: `GET /chilled-runs` + `POST /chilled-runs/orders/{order}/loaded` in the `role:admin,office,factory` group; run management (`Route::resource('delivery-runs', …)` + assign/reorder/unassign POSTs) is office/admin only.
- **`OrderPolicy::load`** is the second factory carve-out (sibling of `fulfill`): factory can tick loaded but still cannot edit orders. `DeliveryRunPolicy` is a bare `BasePolicy` (office CRUD, factory view-only).
- **Columns**: `ChilledRunController::buildSheet()` — milk/yoghurt columns are **all active variants** (stable entry targets, render even when unordered; sorted by size — lexical; revisit if a "10L" churn variant lands); **cheese columns are dynamic** (variants actually present on the run's orders, between the yoghurt group and the last column); the last column is order **Notes** only. Cancelled orders excluded; multiple same-day orders per customer sum per cell; customers with no order that day still render with a "No order this week" tag.
- **Crate math**: footer rows Total units / Blue crates / Extra units outside crate use `ProductVariant::effective_case_size` (`intdiv` / remainder) for milk/yoghurt; cheese columns get units only (`crates`/`extra` null → "—"). The summary strip shows per-type unit+crate totals (+ cheese units when present) and a loaded progress bar (counts only stops that have orders).
- **No € anywhere on the sheet** — quantities only, so the `see-financials` gate is intentionally unused (don't add price columns without re-gating; covered by a feature test).
- **Views/CSS**: `resources/views/chilled-runs/index.blade.php` + `partials/{_day-tabs,_summary-strip,_run-table,_empty}.blade.php`; run management at `resources/views/delivery-runs/`. Run-sheet classes (`mf-daytab*`, `mf-run*`, `mf-run-check`, `mf-crate-*`, `mf-run-qin`, `col-cheese`) live at the bottom of `resources/css/app.css`. Nav: "Chilled Runs" (snow icon, admin/office/factory) and "Delivery Runs" (truck icon, office/admin) in the Sales group.
- Tests: `tests/Feature/ChilledRunTest.php` (role access, stop ordering, no-order/cancelled/inactive rows, column model, crate math, loaded-tick policy, run CRUD/assign/reorder, `dateFor()` week resolution, € redaction, and the full inline-entry suite).

### Inline order entry (office/admin)
- **Per-row edit via `?edit={customer_id}`** (server-driven — one row at a time; history payload only loaded for that row). The Edit/"Enter order" link shows when the row is *editable*: at most one non-cancelled order for the date AND (if present) status ∈ `{pending, confirmed, preparing, ready}`. With 2+ same-day orders the row links to `orders.show` instead; `saveStop` also aborts 409 on that case.
- **`POST /chilled-runs/stops/{customer}/order`** (`chilled-runs.save-stop`, in the **office/admin route group** — factory 403 at middleware). Posts `qty[variant_id]` for every visible column + added lines; `ChilledRunController::saveStop()` **diff-applies**: creates a `pending` order when none exists (`order_date = today`, `delivery_date = run's resolved date`, number auto), zero where a line exists removes it (stock unwind), zero-everything **cancels the order keeping lines as history**, then `calculateTotals()` + `reconcilePickingStatus()`.
- **`POST /chilled-runs/confirm-all`** (`chilled-runs.confirm-all`, office/admin group): flips every `pending` order on the selected run/date to `confirmed` — the "send the day's orders to the picking floor" action (`/picking` only lists confirmed/preparing/ready). The page-head button shows the pending count and only renders when there's something to confirm; pending orders carry a warn tag on their row.
- **`App\Http\Controllers\Concerns\MutatesOrderLines`** trait holds the shared line-mutation invariants (`setLineQuantity` / `applyLineQuantity` / `removeLine` / `cancelOrderKeepingLines`) — extracted from and now also used by `OrderController::storeItem/updateItem/destroyItem`. Any new code that changes `quantity_ordered` or removes lines should go through it (it owns the releaseUnits-on-decrease and cancel-keeps-history rules).
- **Row form**: a `<form>` can't wrap a `<tr>`, so the editing row's inputs associate with a hidden `<form id="stop-form-{id}">` rendered after the table via the HTML5 `form=` attribute.
- **History recall**: edit mode ships the customer's last 5 non-cancelled orders (excluding the one being edited, inactive variants filtered) as JSON into the Alpine `stopEditor` component (inline `<script>` in `_run-table.blade.php`) — "Repeat last order" + ←/→ cycling prefill the inputs; history cheese variants that aren't columns yet become "added lines" (select + qty in the Notes cell) and promote to real columns after save.
- **Customer reference**: the editor also posts an optional `customer_reference` behind a small "+ Ref" toggle (auto-open for `requires_reference` customers), saved/cleared in `saveStop()`; the saved value renders under the order number in display mode. See "Customer Reference" under Order Structure for the full cross-surface notes.
- **Unsaved-changes guard**: because each row's typed quantities live only in Alpine/DOM until **Save**, leaving the row otherwise discards them silently. `stopEditor` tracks a dirty flag (`init()` snapshots a `baseline`, then `$watch`es `qty`/`addedLines`/`reference` and `serialize()`-compares — a true value diff, so reverting clears it) on the global `window.__stopDirty` (+ `window.__stopCustomer`). **Two coordinated guards:** (1) a delegated capture-phase click handler intercepts run-sheet nav links marked **`data-confirm-unsaved`** (other rows' Edit/"Enter order" + "Multiple orders" link in `_run-table`, the day tabs in `_day-tabs`, the week prev/next in `index`) and opens a labelled `<x-modal name="discard-stop">` ("Keep editing" / "Discard changes") instead of a native `confirm()` — *Discard* clears the flag and navigates to the stashed href; (2) a `beforeunload` net for the back button / refresh / tab close / sidebar nav (browser-controlled generic dialog — can't be relabelled). **Save** and **Cancel** both clear the flag (Save via a submit listener on `stop-form-{id}`; Cancel via `@click`), so neither double-prompts. The attribute is inert when not editing (the guard only arms inside the `@if ($editId)` script block).

## Mature Conversion (`/cheese-conversion`)

Turns Farmhouse cheese into the separate premium **Mossfield Mature Cheese** product, in two phases. Built from the `Mature Conversion.html` Claude Design handoff — a desktop **wheel allocator** (Farmhouse / Maturing zones; drag wheels or select + Mature→/←Return/quick-allocate stepper). One card per non-mature cheese **wheel batch_item**, at any age.

### Two-phase model
1. **Maturing hold** — a *reversible* set-aside stored in **`batch_items.quantity_maturing`** (migration `2026_06_20_130000_add_quantity_maturing_to_batch_items_table`). Allowed at **any age**. Held wheels stay physical farmhouse stock (`quantity_remaining` unchanged) but are **excluded from order allocation/picking/cutting**. Lowering the hold returns wheels to available — the "Save maturing" button persists the Maturing-zone count (fixes the original "wheels vanish on confirm" bug).
2. **Release** — once the batch passes `Batch::isEligibleForMaturation()` (~5 months), "Release N to mature" consumes the saved hold into the Mature product: get-or-creates **one Mature batch per source** (`batches.source_batch_id`, `production_date` carried forward ⇒ immediately sellable), mints mature wheels, writes a `CheeseConversionLog`, decrements **both** `quantity_remaining` and `quantity_maturing`.
3. **Undo release** — reversible until the mature wheels are cut/sold: returns N wheels to the source batch's `quantity_remaining` + `quantity_maturing`, drops the mature item, deletes the log. Surfaced on the **mature batch's `batches/show`** ("Return to farmhouse hold", gated on `targetBatchItem->available_quantity >= wheels_converted`).

### Key mechanics
- **The single leverage point**: `BatchItem::getAvailableQuantityAttribute()` = `max(0, quantity_remaining − unfulfilledAllocations − quantity_maturing)`. Subtracting the hold here propagates "won't auto-assign" to auto-allocate, manual allocate, picking, `BuildsAllocationData`, and `StockOverviewService::totalValue` automatically. `getHoldableQuantityAttribute()` = `remaining − unfulfilledAllocations` caps the hold and drives the allocator's wheel total.
- **Routes**: `GET /cheese-conversion` (`cheese-conversion.index`, shared admin/office/factory read) + office/admin writes `POST .../hold/{batchItem}`, `POST .../release/{batchItem}`, `POST .../logs/{log}/undo`. Controller `CheeseConversionController` (`index`/`hold`/`release`/`undoRelease`); `guardSourceWheel()` enforces non-mature cheese wheel (no age gate — `release` adds its own eligibility check).
- **Authorization**: hold/release gate on `authorize('create', CheeseConversionLog::class)`; undo on `authorize('delete', $log)` (instance — `delete` is a model-level ability). `CheeseConversionLogPolicy` is a bare `BasePolicy` (office write, factory read, driver deny); factory is denied writes at the route group anyway.
- **Stock**: cheese wheel rows show a distinct **`maturing`** segment (`--state-maturing`, deep amber) removed from `available` — `StockOverviewService::buildCheeseCard()` sums `quantity_maturing`; `resources/views/components/stock/cheese-row.blade.php` renders it. Cutting now guards on `available_quantity` (`CheeseCuttingController::store`) so held/allocated wheels can't be cut. The same deep-amber maturing segment now also appears on the `/batches` and `/cheese-cutting` wheel strips (see "Wheel & Vac-Pack Visualization").
- **Batches lineage**: released wheels are traceable both ways — `Batch::sourceBatch()`/`matureBatches()` (`source_batch_id`) drive "Matured from … / Matured into …" links on `batches/show` **and** on the `/batches` index cards. NB `Batch::generateBatchCode()`'s `-N` suffix is only a same-date collision counter, not a lineage marker.
- **Mature is a normal cheese product** (seeded by `ProductSeeder`: `Mossfield Mature Cheese`, `maturation_days` 150, Whole Wheel + Vacuum Pack variants) ⇒ released wheels flow through stock/allocation and stay cuttable via the existing cheese-cutting flow with no extra work.
- **Views/CSS**: `resources/views/cheese-conversion/index.blade.php` (Alpine `matureCard(total, held)` seeds the saved hold into the Maturing zone; `x-mature.meta` component); `.mc-*` classes + the `.mc-scope` farm/mature color tokens at the bottom of `resources/css/app.css`. Nav: "Mature Conversion" (clock icon, admin/office/factory) in the Production group. No € on the screen — quantities only.
- Tests: `tests/Feature/CheeseConversionTest.php` (hold set-aside/reverse/cap, exclusion from auto-allocate, young-batch holds, release eligibility + product conversion + production carry-forward, undo-to-hold + blocked-once-cut, stock `maturing` segment, held-can't-be-cut, role gating).

## Online Orders Integration

### Web Interface
- **URL**: `/online-orders`; nav link "Online Orders"
- **Features**: preview orders from Mossorders before importing, import selected orders, view import status/history

### Controller: `OnlineOrdersController`
- `index()` - dashboard
- `preview()` - fetch and display orders from the Mossorders API
- `import()` - import orders into the local database

### Configuration
```env
MOSSORDERS_BASE_URL="https://mossorders.example.com"
MOSSORDERS_API_TOKEN="your_api_token"
```
