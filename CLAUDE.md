# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Key Docs

- **`README.md`** — feature overview, dev setup, API reference
- **`DEPLOYMENT.md`** — production install/upgrade runbook (env vars, MySQL bootstrap, IP allowlist, scheduler cron, smoke tests). Start here for any "how do I deploy / set up a new prod install" question.
- **`SECURITY_NEXT_STEPS.md`** — security-review tracking doc + token-rotation runbook
- **`WORK_IN_PROGRESS.md`** — current in-flight issues and recently completed work
- **mossorders side:** same split exists at `/var/www/html/mossorders/{README,AGENTS,DEPLOYMENT}.md`

## Project Overview

This is a Laravel 12 application for Mossfield Organic Farm - a dairy producer that bottles milk, produces cheese, and makes yoghurt. The application aims to provide full batch traceability, order management, and invoicing capabilities.

**Tech Stack:**
- **Framework**: Laravel 12 with PHP 8.2+
- **Frontend**: Blade templates with Tailwind CSS and Alpine.js
- **Build System**: Vite for asset compilation
- **Database**: SQLite (default) with Eloquent ORM
- **Authentication**: Laravel Breeze with email verification (supports username OR email login)
- **Testing**: PHPUnit with Feature and Unit test suites

## Development Commands

### Starting Development
```bash
# Install PHP dependencies
composer install

# Install Node.js dependencies
npm install

# Copy environment file (if needed)
cp .env.example .env

# Generate application key
php artisan key:generate

# Create database file and run migrations
touch database/database.sqlite
php artisan migrate

# Start development server (includes server, queue, logs, and Vite)
composer run dev
```

### Individual Services
```bash
# PHP development server
php artisan serve

# Vite development server for assets
npm run dev

# Queue worker
php artisan queue:listen --tries=1

# Log viewer
php artisan pail --timeout=0
```

### Building for Production
```bash
# Build frontend assets
npm run build

# Optimize Laravel for production
php artisan optimize
```

### Testing
```bash
# Run all tests
composer run test
# OR
php artisan test

# Run specific test suite
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit

# Run specific test file
php artisan test tests/Feature/ExampleTest.php
```

### Code Quality
```bash
# Laravel Pint (code formatter)
./vendor/bin/pint

# Clear caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

## Architecture

### Directory Structure
- `app/Http/Controllers/` - HTTP controllers including Auth controllers from Breeze
- `app/Models/` - Eloquent models (User model included)
- `app/View/Components/` - Blade components (AppLayout, GuestLayout)
- `resources/views/` - Blade templates with auth views and dashboard
- `resources/js/` - JavaScript files (Alpine.js setup)
- `resources/css/` - CSS files (Tailwind CSS)
- `routes/` - Route definitions (web.php, auth.php)
- `database/migrations/` - Database migrations
- `tests/` - PHPUnit tests (Feature and Unit)

### Key Components
- **Authentication**: Laravel Breeze provides login, registration, password reset, and email verification
- **User Management**: Profile editing and account deletion functionality
- **Database**: Uses SQLite by default with standard Laravel migrations
- **Frontend**: Server-side rendered Blade templates with Tailwind CSS styling
- **Asset Pipeline**: Vite handles CSS and JavaScript compilation with hot reloading

### Configuration
- Database configured for SQLite in `.env`
- Vite configuration in `vite.config.js` handles asset compilation
- Tailwind CSS configured in `tailwind.config.js` with forms plugin
- PHPUnit configured in `phpunit.xml` with SQLite in-memory testing database

## Database

The application uses SQLite by default. The database file is located at `database/database.sqlite`.

### Common Database Operations
```bash
# Create new migration
php artisan make:migration create_example_table

# Run migrations
php artisan migrate

# Rollback migrations
php artisan migrate:rollback

# Seed database
php artisan db:seed

# Access database directly
php artisan tinker
```

## Frontend Development

The frontend uses Blade templates with Tailwind CSS and Alpine.js:
- Tailwind CSS for styling with forms plugin
- Alpine.js for interactive components
- Vite for asset building and hot reloading
- Blade components for reusable UI elements

### Asset Files
- `resources/css/app.css` - Main CSS file
- `resources/js/app.js` - Main JavaScript file with Alpine.js
- `resources/js/bootstrap.js` - Bootstrap configuration with Axios

## Authentication Flow

Based on Laravel Breeze, modified for a closed operator/factory/driver system:
- Login/logout (supports username OR email — email is optional)
- Password reset flow (email users only — degrades gracefully for email-less accounts)
- Profile management (edit profile, change password, delete account)
- **Self-registration is disabled** — admin creates users via `/users`
- **Email verification is disabled** — factory and driver users often have no work email

### Username Authentication
The application supports flexible login using either username or email:
- Login form accepts "Username or Email"
- `LoginRequest::getLoginField()` switches on `filter_var(..., FILTER_VALIDATE_EMAIL)`
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

Seeder refuses to run in production. All authentication routes are in `routes/auth.php` (controllers in `app/Http/Controllers/Auth/`) — the `register` and `verification.*` routes have been removed from that file but the unused controller classes remain in place.

## Database Connections

### Primary Database
- SQLite by default (`database/database.sqlite`)
- Configuration in `config/database.php`

### POS Database (Placeholder)
A commented-out `pos` connection is configured for future uniCenta integration:
- Located in `config/database.php`
- Uncomment and set environment variables when ready
- Designed for read-only access to POS data
- Usage: `DB::connection('pos')->table('products')->get()`

## External Integrations

### Mossorders Online Portal Integration

The office system supports linking customers to external Mossorders user accounts.

**Customer Model Fields**:
- `mossorders_user_id` (nullable, unique) - Links office customer to Mossorders user account
- Each Mossorders user can map to at most one office customer
- Deleting a Mossorders user does not cascade (preserves office data integrity)

**Helper Method**:
```php
$customer->hasOnlineAccount()  // Returns true if linked to Mossorders
```

**Database**:
- Migration: `2025_11_22_163725_add_mossorders_user_id_to_customers_table.php`
- Unique index: `customers_mossorders_user_unique`

**Usage**:
- This field is a preparation for future integration workflows
- Allows API endpoints or admin tools to establish customer-user mappings
- Supports scenarios where office customers want online ordering access

## Security Posture

The sync integration has been hardened across three phases. The operator-facing runbook lives in `SECURITY_NEXT_STEPS.md` (DB migrations, `.env` flips, token rotation). Invariants to respect when editing code:

### Auth & middleware
- **Routes are grouped by role, not by `verified` status.** Email verification is disabled (see Authentication Flow). Two tiers of business route groups exist in `routes/web.php`:
  - `['auth', 'role:admin,office,factory']` — shared read-capable routes (products, batches, orders, stock, cheese-cutting index). Factory is view-only; writes are blocked via policies inside the controller (`$this->authorize(...)` per action).
  - `['auth', 'role:admin,office']` — office-only flows (customers, product variants, order-allocations, online-orders, cheese-cutting write actions).
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
- `phone`, `address`, `city`, `postal_code`, `notes` use the `App\Casts\EncryptedNullable` cast. Written values are encrypted, reads fall back to plaintext for pre-migration rows and log a warning.
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
- `App\Enums\UserRole` — `admin | office | factory | driver`. Required on every user (column `users.role`). Also: `users.is_active` boolean (default true); inactive users are rejected at login by filtering the Auth::attempt credentials array.
- Policies live in `app/Policies/` and are registered in `app/Providers/AuthServiceProvider.php`. `BasePolicy` implements the CRUD baseline (`admin` via `before()` → all, `office` → full CRUD, `factory` → `viewAny`/`view` only, `driver` → deny-all). `UserPolicy` is admin-only on every ability. Add per-model policies by extending `BasePolicy`; override only if the model needs narrower rules.
- **Controllers enforce writes via `$this->authorize('ability', $model)`** at the top of each action — `authorizeResource()` doesn't work in Laravel 12 because the new base `Controller` is a POPO without a `middleware()` method. Add explicit `authorize` calls in any new write action.
- Column-level `see-financials` gate in `AuthServiceProvider::boot()` (admin/office only). Used in order blades (`@can('see-financials') ... @endcan`) to hide unit prices, line totals, subtotals, tax, and totals from factory users who share the `/orders` read screens.
- **Last active admin is protected** — `User::isLastActiveAdmin()` returns true if demoting/deactivating/deleting this user would leave zero active admins. Called from `UserController::update/destroy/deactivate` and `ProfileController::destroy`. Block with a flash error on hit; never allow it through.
- **Session invalidation** on role change + deactivation — `User::logOutEverywhere()` deletes rows from the database `sessions` table where `user_id = $this->id`. Requires `SESSION_DRIVER=database` (default). Don't skip this step when changing role or flipping `is_active` to false — elevated cookies would otherwise outlive the change.
- **Role-gated nav** in `resources/views/layouts/navigation.blade.php` — shared `@php` block at top defines `$canSeeCustomers`/etc., wraps both desktop and responsive link lists. Nav visibility is separate from policy access (nav gates discovery, policy gates access — they can diverge, e.g. factory CAN view Customer model in-line but has no Customers nav link).

### Seeders
`AdminUserSeeder` refuses to run in production. Password overridable via `ADMIN_SEED_PASSWORD`. In addition to the admin user, it seeds `office_test`, `factory_test` (no email), and `driver_test` (no email) for local role testing.

## Troubleshooting

### Storage Permission Issues
If you encounter "Permission denied" errors for `storage/framework/views/`:

```bash
# Clear compiled views and caches
php artisan view:clear
php artisan config:clear
php artisan route:clear

# Fix storage permissions
chmod -R 775 storage/
chmod -R 775 bootstrap/cache/
```

This typically happens when Laravel runs under different users (web server vs CLI).

## Business Context

Mossfield Organic Farm produces three main product types:
1. **Milk**: 1L and 2L bottles (batch code: Mddmmyy)
2. **Yoghurt**: 250g and 500g tubs (batch code: Yddmmyy)
3. **Cheese**: Multiple varieties - Farmhouse, Garlic & Basil, Tomato & Herb, Cumin Seed, Mature (batch code: Gddmmyy)
   - Produced as wheels, later cut into vacuum packs
   - Requires maturation tracking
   - Each vacuum pack must be traceable to original wheel

## Implemented Features

### ✅ **Core Production & Batch Management**
- **Batch Traceability**: Complete tracking with automated batch code generation
- **Product Management**: Products with full CRUD operations
- **Product Variant Management**: Complete CRUD for product variants (sizes, packaging types)
  - Create, edit, and delete variants for any product
  - Track weight, price, and active status per variant
  - Nested resource routes: `/products/{product}/variants/*`
- **Cheese Cutting System**: Convert wheels to vacuum packs with full traceability
- **Stock Overview**: Real-time stock levels and valuations
- **Grouped Batch Browser** (`/batches`): Batches grouped by product type (Milk / Yoghurt / Cheese) and, within Cheese, sub-grouped by variety. Three-level collapsible hierarchy (type → variety → batch) using native `<details>`/`<summary>`. Each batch card renders an at-a-glance status strip in its always-visible header: one small circle per wheel produced (**yellow** = remaining, **grey** = cut, **black** = sold as whole wheel) plus a 128px stacked mini-bar for vacuum packs (yellow=remaining, black=sold). Same layout is applied on `/cheese-cutting` with the "Cut Wheel" action surfaced inside each batch's expanded body.

### ✅ **Advanced Stock Visualization**
- **Multi-View System**: Three distinct views for stock management
  - **Timeline View**: Gantt-style 6-month production planning
  - **Calendar View**: Month/week grid with batch details
  - **Table View**: Traditional detailed tabular format
- **Smart Sorting**: By ready date, cheese type, batch code, or quantity
- **Visual Indicators**: Progress bars, urgency alerts, and color coding
- **Mobile Responsive**: Optimized for all screen sizes

### ✅ **Customer & Order Management**
- **Customer CRUD**: Complete customer management system
  - Create, view, edit, and delete customers
  - Search and filter (by name, email, status, online account)
  - Track credit limits, payment terms, and outstanding balances
  - Full address management (street, city, postal code, country)
  - Link to Mossorders online portal via `mossorders_user_id`
  - View customer order history and online account status
- **Order Processing**: Complete workflow from pending to fulfilled
- **Stock Allocation**: Advanced FIFO allocation with real-time availability
- **Auto-Allocation**: Intelligent automatic stock assignment
- **Variable Weight Fulfillment**: Weight-based fulfillment for cheese products
  - Individual weight entry per unit (e.g., each cheese wheel)
  - Weight-based pricing (€/kg) for variable-weight items
  - Running total calculation during fulfillment
  - Fulfilled totals based on actual weight vs. estimated
  - Pre-fulfillment `line_total` is also weight-aware: `qty × weight_kg × unit_price` for weight-priced items (set in `OrderItem::boot()`); `fulfilled_total` overrides this once actual weights are known
- **Undo Fulfillment**: Ability to reverse fulfillments and restore stock
- **Online Orders UI**: Web interface at `/online-orders` to preview and import orders from Mossorders portal

### ✅ **Business Intelligence**
- **Maturation Timeline**: Visual representation of cheese aging process
- **Expiry Tracking**: Automated alerts for items nearing expiry dates
- **Stock Valuation**: Real-time calculation of stock values
- **Production Planning**: Visual 6-month production overview

### 📊 **Stock Timeline Views**

The system provides three specialized views for stock management:

#### 🗓️ **Timeline View** (Primary)
- **Gantt-style layout** with batch codes on left, timeline on right
- **6-month visibility** (24 weeks) with responsive column limits
- **Month headers** spanning across their respective weeks
- **Visual symbols**:
  - `▶` Production start (green)
  - `■` Ready date (color-coded by urgency)
  - `●●●` Quantity indicators (~10 units each)
  - Progress bars showing maturation span
- **Smart sorting**: Ready date, cheese type, batch code, quantity
- **Visual grouping** when sorted by cheese type
- **Responsive design**: 12 weeks (desktop), 8 weeks (tablet), 6 weeks (mobile)

#### 📅 **Calendar View**
- **Month/week grid** with detailed batch cards
- **Progress indicators** and countdown timers
- **Color-coded cheese types** with visual legend

#### 📊 **Table View**
- **Traditional tabular** format with sortable columns
- **Progress bars** and expiry warnings
- **Detailed batch information** and links

### 🔄 **API Integration & Mossorders Sync**
- **Product Export API**: Read-only API endpoint for external services
  - Endpoint: `GET /api/products`
  - Authentication: Bearer token (`OFFICE_API_TOKEN`)
  - Returns flattened product variants with stock availability
  - Supports incremental sync with `updated_since` parameter
  - Currency: All prices in euros (€)
  - Each variant payload includes `is_priced_by_weight` so the consumer (mossorders) can render `€/kg` labels and compute weight-based estimates

- **Mossorders Order Import**: Bidirectional integration with online portal
  - **Preview Command**: `php artisan mossfield:preview-online-orders [--since=]`
    - Read-only preview of orders from Mossorders API
    - Displays order data without importing
  - **Import Command**: `php artisan mossfield:import-online-orders [--since=]`
    - Imports online orders into office system
    - Maps to customers via `customers.mossorders_user_id`
    - Idempotent (safe to run multiple times)
    - Auto-generates office order numbers
    - Tracks source via `orders.mossorders_order_id`
    - Customer `notes` from the online order are preserved (read from the API payload; falls back to `"Imported from Mossorders order …"` when absent). The web-based importer in `OnlineOrdersController` uses the same fallback.
  - **Configuration**: Set `MOSSORDERS_BASE_URL` and `MOSSORDERS_API_TOKEN` in `.env`

### 🔄 **Future Enhancements**
- **Invoice Generation**: Generate invoices per order with configurable pricing
- **Customer Portal**: Allow customers to view orders and download invoices
- **Advanced Reporting**: Production reports and analytics
- **Integration Options**: POS system integration (uniCenta ready)

## Product & Variant Structure

### Products
Products are the main categories (e.g., "Mossfield Organic Milk", "Mossfield Farmhouse Cheese").

**Key Fields**:
- `name` - Product name
- `type` - milk, yoghurt, or cheese
- `maturation_days` - For cheese only (e.g., 90 days)
- `is_active` - Active status

### Product Variants
Variants represent specific sizes/packaging of products (e.g., "1L Bottle", "Whole Wheel", "Vacuum Pack").

**Key Fields**:
- `product_id` - Parent product reference
- `name` - Variant name (e.g., "1L Bottle", "Vacuum Pack")
- `size` - Size descriptor (e.g., "1L", "wheel", "pack")
- `unit` - Unit type (e.g., "bottle", "wheel", "pack")
- `weight_kg` - Weight in kilograms (decimal)
- `base_price` - Price in euros (€)
- `is_variable_weight` - Boolean: whether weight varies per unit (e.g., cheese wheels)
- `is_priced_by_weight` - Boolean: whether pricing is per kg (€/kg)
- `is_active` - Variant availability

**Variable Weight Products**:
- Cheese wheels are variable weight (each wheel weighs differently)
- Fixed products (milk bottles, standard vacuum packs) have consistent weights
- Variable weight items require individual weight entry at fulfillment
- Price displayed as €X.XX/kg for weight-priced items

### Pricing Display Helpers
The `ProductVariant` model centralises all price formatting and estimation. Always use these accessors instead of hand-formatting `base_price`:

- `$variant->price_label` — `"€12.50/kg"` for weight-priced variants, `"€3.50"` otherwise
- `$variant->estimated_unit_price` — per-unit price estimate (uses nominal `weight_kg` when priced by weight); use this for cart line totals and `data-price` attributes
- `$variant->calculatePrice($quantity, $weightKg = null)` — weight-aware total; pass an actual weight to override the nominal estimate

`OrderItem::boot()::saving` uses the same logic when computing `line_total`. `StockController` valuations use `calculatePrice()` to avoid the N×base_price overstatement on weight-priced cheese.

### Products Master-Detail View
`/products` is the grouped-card index; `/products/{product}` (show), `/products/{product}/edit`, `/products/{product}/variants/create`, and `/products/{product}/variants/{variant}/edit` are all master-detail — sibling products in a left pane (`lg:grid-cols-[320px_1fr]`, hidden below `lg`), selected page content on the right.

- **Shared list-builder**: `app/Http/Controllers/Concerns/BuildsProductList` trait. `ProductController::show/edit` and `ProductVariantController::create/edit` all `use BuildsProductList` and call `$this->buildProductList($request, $product)` to populate `$productList`, `$listFilters`, `$listTotal`, `$listLimit`. **Don't drop these from the views** — the shared sidebar partial requires them.
- **Sidebar partial**: `resources/views/products/_sibling_list.blade.php`. Consumes `$mode` (`'show'` or `'edit'`) — sibling product rows link via `route('products.'.$mode, …)`. Variant pages pass `$mode = 'show'` (sibling clicks land on that product's show page; users pick a variant to edit from there).
- **Variant nesting in sidebar**: pass `$showActiveVariants = true` and (optionally) `$activeVariant = $variant` to expand the *active* product's row with its variant list inline. Variant pages (`variants/create.blade.php`, `variants/edit.blade.php`) opt in; product show/edit pages don't.
- `ProductController::update()` redirects to `products.show`, not `products.index`. `destroy()` still redirects to index. `ProductVariantController` store/update/destroy still redirect to `products.show`, which lands on the master-detail.
- Sidebar groups by `$typeOrder = ['milk','yoghurt','cheese']` (matches `ProductController::index()` line 16 and the trait) — keep the three locations in sync if a new product type is added.

### Controllers
- **ProductController** - CRUD operations for products
  - `index()` and `show()` eager-load variants with `withSum('batchItems as total_stock', ...)` to avoid N+1 on stock display
- **ProductVariantController** - CRUD operations for variants (nested under products)
  - Routes: `products.variants.create`, `products.variants.store`, `products.variants.edit`, `products.variants.update`, `products.variants.destroy`
  - Views: `resources/views/products/variants/create.blade.php`, `resources/views/products/variants/edit.blade.php`
  - `create()` accepts `?from={variantId}` to prefill the form when duplicating an existing variant; the source is exposed to the view as `$source`
- **BatchController** - Batch CRUD + grouped browser at `/batches`
  - `index()` eager-loads `batchItems` with `withCount('sourceCuttingLogs')` so the wheel visualization's "cut" count is one query per page, not N+1
  - View composes three nested `<details>` levels (type → cheese variety → batch) and defers each batch card to `resources/views/batches/partials/batch-card.blade.php`
- **CheeseCuttingController** - Same eager-load pattern on `index()`; view at `resources/views/cheese-cutting/index.blade.php` uses `resources/views/cheese-cutting/partials/batch-card.blade.php`, which keeps the header wheel/pack visuals but swaps in per-wheel **Cut Wheel** action buttons in the expanded body

### Wheel & Vac-Pack Visualization (shared logic)
Cheese batches render an at-a-glance status strip in the card header:
- **Wheels**: one circle per unit of `quantity_produced`. Colors derived as `cut = source_cutting_logs_count` (requires the `withCount` above), `sold = produced − remaining − cut`, `remaining = produced − cut − sold`. Always clamped to `max(0, …)` to survive counter drift.
- **Vac packs**: a 128px stacked bar (`w-32 h-2`) with yellow=remaining / black=sold segments proportional to `quantity_produced`. One row per non-wheel cheese batch item with `produced > 0`. Chosen over per-pack markers because vac-pack counts can reach the hundreds.
- The same classification (`str_contains(strtolower($variant->name), 'wheel')`) is used in `CheeseCuttingController::store()` — keep them aligned when renaming variants.
- Each cheese sub-group header on `/batches` and `/cheese-cutting` also shows aggregate remaining/cut/sold totals so the summary stays visible even when all batches underneath are collapsed.

### Validation
Both controllers delegate to FormRequests (`app/Http/Requests/ProductRequest.php`, `app/Http/Requests/ProductVariantRequest.php`):

- Product names are unique (`unique:products,name`)
- Variant names are unique within their product (`unique:product_variants,name` scoped to `product_id`)
- `maturation_days` is required when `type=cheese`
- `weight_kg` is required for variants unless `is_variable_weight=true` AND `is_priced_by_weight=false` (i.e. weight is needed whenever it's load-bearing for pricing)
- `base_price` minimum is 0.01 to prevent free variants
- Checkbox booleans are coerced via `prepareForValidation()` so unchecked values become `false` instead of being dropped from the payload

## Customer Structure

### Customers
Customers represent buyers who place orders through the office system or online portal.

**Key Fields**:
- `name` - Customer name
- `email` - Email address (unique)
- `phone` - Contact phone number (optional)
- `address`, `city`, `postal_code`, `country` - Full address details
- `credit_limit` - Maximum outstanding balance (decimal, in euros)
- `payment_terms` - enum: `immediate`, `net_7`, `net_14`, `net_30`
- `is_active` - Active status (boolean)
- `notes` - Internal notes (optional)
- `mossorders_user_id` - Link to Mossorders online portal user (nullable, unique)

**Helper Methods**:
- `hasOnlineAccount()` - Returns true if linked to Mossorders portal
- `getOutstandingBalanceAttribute()` - Calculates total unpaid orders
- `canPlaceOrder(float $orderAmount)` - Checks credit limit availability

**Controller**:
- **CustomerController** - Full CRUD operations
  - Routes: `customers.index`, `customers.create`, `customers.store`, `customers.show`, `customers.edit`, `customers.update`, `customers.destroy`
  - Views: `resources/views/customers/` (index, create, edit, show)
  - Features: Search/filter, order history, online account status

## Order Structure

### Orders
Orders track customer purchases and their fulfillment status.

**Key Fields**:
- `order_number` - Auto-generated (format: ORD-YYYYMMDD-XXX)
- `customer_id` - Foreign key to customers
- `order_date`, `delivery_date` - Order and delivery dates
- `status` - enum: `pending`, `confirmed`, `preparing`, `ready`, `dispatched`, `delivered`, `cancelled`
- `payment_status` - enum: `pending`, `paid`, `partial`, `overdue`
- `subtotal`, `tax_amount`, `total_amount` - Financial totals (decimals, in euros)
- `delivery_address` - Optional override of customer default address
- `notes` - Order notes
- `mossorders_order_id` - Link to Mossorders online order (nullable, unique)

**Helper Methods**:
- `scopeFromMossorders($query)` - Scope to filter imported online orders
- `isFullyAllocated()` - Checks if all items have stock allocated
- `canBeCancelled()` - Checks if order can be cancelled

### Currency
All prices throughout the application are displayed in euros (€).

### Orders Master-Detail View
`/orders` is a paginated table; `/orders/{order}` is a master-detail layout — sibling orders in a left pane (`lg:grid-cols-[320px_1fr]`, hidden below `lg`), selected order detail on the right.

- `OrderController::show(Request $request, Order $order)` accepts the same filters as `index()` (`status`, `payment_status`, `customer_id`) via query string, fetches up to 50 sibling orders matching them, and force-includes the selected order in the list even when outside the cap. View receives `$orderList`, `$listFilters`, `$listTotal`, `$listLimit`. **Don't drop these from `show()`** — the blade depends on them and degrades to an empty list otherwise.
- `orders/index.blade.php` rows link via `route('orders.show', array_merge($rowFilters, ['order' => $order->id]))` so filter state flows into the master pane. Apply the same pattern from any new entry point that should preserve filter context.

## Order Allocation & Fulfillment

### Allocation Flow
1. **Order Created** → Status: pending
2. **Order Confirmed** → Available for allocation
3. **Stock Allocated** → Links order items to specific batch items (FIFO)
4. **Fulfillment** → Reduces actual batch stock, records weights for variable items
5. **Order Completed** → All items fulfilled

### Order Allocations Table
Tracks the link between order items and batch items:
- `order_item_id` - Which order item this allocation is for
- `batch_item_id` - Which batch the stock comes from
- `quantity_allocated` - How many units reserved
- `quantity_fulfilled` - How many units actually picked/shipped
- `actual_weight_kg` - Actual weight recorded at fulfillment (for variable weight items)
- `allocated_at` - When allocation was created
- `fulfilled_at` - When fulfillment was completed

### Variable Weight Fulfillment
For cheese wheels and other variable-weight products:

**Database Fields**:
- `product_variants.is_variable_weight` - Marks variant as requiring weight entry
- `product_variants.is_priced_by_weight` - Marks variant as €/kg pricing
- `order_allocations.actual_weight_kg` - Stores actual weight at fulfillment
- `order_items.weight_fulfilled_kg` - Total weight fulfilled for the line item
- `order_items.fulfilled_total` - Calculated total based on actual weight

**UI Behavior** (`/order-allocations/{order}`):
- Variable weight items show "Variable Weight" badge
- Fulfillment form shows individual weight inputs for each unit (#1, #2, #3...)
- Running total calculated as weights are entered
- Price shown as €X.XX/kg with estimated vs fulfilled totals

**Controller**: `OrderAllocationController`
- `show()` - Display order with available stock and current allocations
- `allocate()` - Reserve stock from a batch item
- `deallocate()` - Remove an allocation (if not fulfilled)
- `fulfill()` - Mark allocated items as picked, record weights
- `unfulfill()` - Undo fulfillment, restore stock to batch
- `autoAllocate()` - Automatically allocate using FIFO

### Routes
```php
GET  /order-allocations                    # List orders needing allocation
GET  /order-allocations/{order}            # Show order allocation page
POST /order-allocations/{orderItem}/allocate    # Allocate stock
DELETE /order-allocations/{allocation}     # Remove allocation
POST /order-allocations/{allocation}/fulfill    # Fulfill allocation
POST /order-allocations/{allocation}/unfulfill  # Undo fulfillment
POST /order-allocations/{order}/auto-allocate   # Auto-allocate order
```

## Online Orders Integration

### Web Interface
- **URL**: `/online-orders`
- **Navigation**: "Online Orders" link in main navigation
- **Features**:
  - Preview orders from Mossorders before importing
  - Import selected orders into office system
  - View import status and history

### Controller: `OnlineOrdersController`
- `index()` - Main online orders dashboard
- `preview()` - Fetch and display orders from Mossorders API
- `import()` - Import orders into local database

### Configuration
```env
MOSSORDERS_BASE_URL="https://mossorders.example.com"
MOSSORDERS_API_TOKEN="your_api_token"
```