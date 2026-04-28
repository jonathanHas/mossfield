# Mossfield Organic Farm Management System

A comprehensive Laravel 12 application for managing dairy farm operations, providing full batch traceability, order management, and advanced stock visualization for Mossfield Organic Farm.

## Features

### 🏭 **Production Management**
- **Batch Tracking**: Complete traceability for milk, yoghurt, and cheese production
- **Batch Coding**: Automated batch code generation (M/Y/G + ddmmyy format)
- **Cheese Maturation**: Track maturation periods and ready-to-sell dates
- **Cheese Cutting**: Convert whole wheels to vacuum-packed units with full traceability
- **Grouped Batch Browser** (`/batches`, `/cheese-cutting`): Batches grouped by product type and cheese variety with three-level collapsible sections. At-a-glance wheel status in every cheese batch header — one circle per wheel (🟡 remaining · ⚪ cut · ⚫ sold) plus a stacked bar for vacuum packs — so stock status is visible without expanding anything

### 📦 **Product & Variant Management**
- **Product CRUD**: Full create, read, update, delete for products
- **Variant Management**: Complete CRUD for product variants
  - Add/edit/delete variants (e.g., "1L Bottle", "Whole Wheel", "Vacuum Pack")
  - Configure size, unit, weight, and pricing per variant
  - Track active/inactive status
  - Nested routes: `/products/{product}/variants/*`
- **Currency**: All prices displayed in euros (€)

### 📦 **Advanced Stock Management**
- **Multi-View Stock Overview**: Timeline, Calendar, and Table views
- **Gantt-Style Timeline**: Visual 6-month production planning view
- **Smart Sorting**: Sort by ready date, cheese type, batch code, or quantity
- **Expiry Tracking**: Automated alerts for items nearing expiry
- **Color-Coded Types**: Visual distinction between cheese varieties

### 🛒 **Customer & Order Management**
- **Customer CRUD**: Complete customer management system
  - Create, view, edit, and delete customers
  - Search and filter by name, email, status, or online account
  - Track credit limits, payment terms, and outstanding balances
  - Link to Mossorders online portal via unique user ID
  - View customer order history and account status
- **Order Processing**: From pending to confirmed to fulfilled
- **Online Order Import**: Import orders from Mossorders portal
  - Web interface at `/online-orders` for preview and import
  - Automatic customer mapping via Mossorders user ID
  - Idempotent imports (safe to run multiple times)
  - Tracks order source (office vs online)
- **Stock Allocation**: Advanced FIFO allocation with availability tracking
- **Auto-Allocation**: Intelligent automatic stock assignment
- **Variable Weight Fulfillment**: Weight-based fulfillment for cheese
  - Individual weight entry per unit (each cheese wheel)
  - Weight-based pricing (€/kg) calculation
  - Actual vs estimated totals tracking
- **Undo Fulfillment**: Reverse fulfillments and restore stock to batches

### 💼 **Business Intelligence**
- **Visual Timeline**: See production flow across months and weeks
- **Maturation Progress**: Track cheese aging with progress indicators
- **Stock Valuation**: Real-time stock value calculations
- **Urgency Indicators**: Highlight items ready within 7-14 days

### 🔧 **Technical Features**
- **Authentication**: Username or email login with Laravel Breeze
- **Database**: SQLite primary database with eloquent relationships
- **Frontend**: Tailwind CSS with Alpine.js for interactive components
- **Testing**: PHPUnit with comprehensive test suite
- **Development**: Hot reloading with Vite and Laravel Pint formatting

## Quick Start (Local Development)

```bash
# Install dependencies
composer install
npm install

# Setup environment
cp .env.example .env
php artisan key:generate

# Setup database
touch database/database.sqlite
php artisan migrate
php artisan db:seed --class=AdminUserSeeder

# Start development
composer run dev
```

> **Production installs: see [`DEPLOYMENT.md`](./DEPLOYMENT.md)** — the single operator runbook for standing up or upgrading mossfield in production (env vars, MySQL bootstrap, migrations, IP allowlist, scheduler, cron, smoke tests). `README.md` is developer-focused; `DEPLOYMENT.md` is operator-focused.

## Test Login

- **Username:** `admin`
- **Email:** `admin@osmanager.local`
- **Password:** `admin123`

> `AdminUserSeeder` refuses to run when `APP_ENV=production`. In non-production environments you can override the default password with `ADMIN_SEED_PASSWORD=<something>` in `.env` before running the seeder.

## API Integration

### Product Export API

The office system provides a read-only API endpoint for external services (e.g., online ordering system) to sync product data.

#### **Endpoint**: `GET /api/products`

**Authentication**: Bearer token (required)

```bash
curl -H "Authorization: Bearer YOUR_OFFICE_API_TOKEN" \
     http://mossfield.local/api/products
```

**Setup**:
1. Generate a secure token: `openssl rand -hex 32`
2. Add to `.env`: `OFFICE_API_TOKEN=your_generated_token`
3. Configure the external service with the same token

**Query Parameters**:
- `only_active` (0/1, default: 1) - Filter to only active products/variants
- `updated_since` (ISO8601 datetime) - Get only variants updated after this date

**Example Request**:
```bash
# Get all active products
curl -H "Authorization: Bearer YOUR_TOKEN" \
     "http://mossfield.local/api/products?only_active=1"

# Get products updated since a specific date (incremental sync)
curl -H "Authorization: Bearer YOUR_TOKEN" \
     "http://mossfield.local/api/products?updated_since=2025-11-15T10:00:00Z"
```

**Response Format**:
```json
{
  "data": [
    {
      "office_product_id": 1,
      "office_variant_id": 1,
      "product_name": "Mossfield Organic Milk",
      "product_type": "milk",
      "variant_name": "1L Bottle",
      "full_name": "Mossfield Organic Milk - 1L Bottle",
      "size": "1L",
      "unit": "bottle",
      "weight_kg": 1.000,
      "base_price": 2.50,
      "is_priced_by_weight": false,
      "is_active": true,
      "stock_available": 150,
      "updated_at": "2025-11-16T10:23:45Z"
    },
    {
      "office_product_id": 3,
      "office_variant_id": 6,
      "product_name": "Mossfield Farmhouse Cheese",
      "product_type": "cheese",
      "variant_name": "Whole Wheel",
      "full_name": "Mossfield Farmhouse Cheese - Whole Wheel",
      "size": "wheel",
      "unit": "wheel",
      "weight_kg": 2.500,
      "base_price": 35.00,
      "is_priced_by_weight": true,
      "is_active": true,
      "stock_available": 12,
      "updated_at": "2025-11-16T10:25:12Z"
    }
  ]
}
```

**Field Descriptions**:
- `office_product_id` / `office_variant_id`: Use these as foreign keys in the external service
- `product_type`: `milk`, `yoghurt`, or `cheese`
- `base_price`: Price in euros (€). For `is_priced_by_weight=true` variants, this is **per kilogram**, not per unit.
- `is_priced_by_weight`: When `true`, render the price as `€X.XX/kg` and compute estimated line totals as `quantity × weight_kg × base_price`. Final totals are settled at fulfillment with the actual weight.
- `is_active`: Both product and variant must be active for this to be `true`
- `stock_available`: Office-side approximation of ready-to-ship units (excludes maturing cheese)
- `updated_at`: Timestamp of last variant update (useful for incremental syncing)

**Security**:
- Returns `401 Unauthorized` if token is missing or invalid
- Returns `403 Forbidden` if caller IP is not on the optional allowlist (see below)
- Returns `422 Unprocessable Entity` if query parameters are invalid
- Returns `429 Too Many Requests` after 60 requests/min per caller IP (`throttle:sync-api`)
- Endpoint is read-only (GET only)
- Does not expose customer data, addresses, or sensitive information

**Hardening controls (middleware runs in this order):**

| Control | Env var | Behaviour |
| --- | --- | --- |
| Rate limit | (none — hard-coded) | 60 req/min per IP, `429` when exceeded |
| IP allowlist | `OFFICE_API_ALLOWED_IPS` | Comma-separated IPs/CIDRs. Blank = allow all. `403` on miss. |
| Token (current) | `OFFICE_API_TOKEN` | Required. Constant-time compared via `hash_equals`. |
| Token (previous) | `OFFICE_API_TOKEN_PREVIOUS` | Optional; accepted during rotation windows. Leave blank outside of a rotation. |

**Audit trail:** every request emits a structured log line (`api_token_auth: accepted | invalid | ip not allowed`) with the caller IP, URI, and an 8-character SHA256 fingerprint of the offered token (never the raw token). Logs go to the default Laravel channel.

**Token rotation runbook** (zero downtime): see `SECURITY_NEXT_STEPS.md`, section H3 — set `_PREVIOUS` to the old token, deploy new `OFFICE_API_TOKEN`, wait for logs to show `token_version: current` only, then clear `_PREVIOUS`.

### Customer Integration with Mossorders

The office system supports full bidirectional integration with the Mossorders online portal.

#### Customer Linking

**Database Field**: `customers.mossorders_user_id`
- Nullable unique integer field linking office customers to Mossorders users
- One-to-one mapping (each Mossorders user maps to at most one office customer)
- Preserves data integrity (no cascade on delete)

**Model Helper**:
```php
$customer->hasOnlineAccount()  // Check if customer has linked Mossorders account
```

**How to Link Customers**:
1. Navigate to Customers → Edit Customer
2. Enter the Mossorders User ID in the "Online Integration" section
3. Save the customer

#### Order Import Commands

**Configuration** (in `.env`):
```env
MOSSORDERS_BASE_URL="https://your-mossorders-instance.com"
MOSSORDERS_API_TOKEN="your_api_token_here"
```

**Preview Online Orders** (read-only):
```bash
# Preview all orders
php artisan mossfield:preview-online-orders

# Preview orders since a specific date
php artisan mossfield:preview-online-orders --since=2025-11-22T00:00:00Z
```

**Import Online Orders** (creates office orders):
```bash
# Import all new orders
php artisan mossfield:import-online-orders

# Import orders since a specific date
php artisan mossfield:import-online-orders --since=2025-11-22T00:00:00Z
```

**Import Behavior**:
- ✅ **Idempotent**: Safe to run multiple times (no duplicates)
- ✅ **Customer Mapping**: Links via `mossorders_user_id`
- ✅ **Order Tracking**: Stores Mossorders order ID for reference
- ✅ **Auto-numbering**: Generates office order numbers (ORD-YYYYMMDD-XXX)
- ⚠️ **Skips unmapped customers**: Logs warning if customer not found
- 📊 **Comprehensive reporting**: Shows import statistics

**Order Source Tracking**:
- Orders with `mossorders_order_id` are marked as "Online" in the UI
- Office-created orders have no `mossorders_order_id` (marked as "Office")
- View order source on customer detail page and order lists

#### Scheduled Sync

The import command is wired to run hourly by Laravel's scheduler, **gated off by default** so deploying the code doesn't start it automatically.

**Toggle on:**
```env
SYNC_SCHEDULE_ENABLED=true
SYNC_ALERT_EMAIL=ops@example.com   # optional — emails the command output when a run fails
```

Then make sure the system cron runs Laravel's scheduler every minute:
```cron
* * * * * cd /var/www/html/mossfield && php artisan schedule:run >> /dev/null 2>&1
```

**What to watch:**
- `storage/logs/sync.log` — daily-rotated (14-day retention), one structured `import_orders: run summary` line per invocation (correlation id, duration_ms, counts).
- `/dashboard` — the "Online order sync" strip shows last-success age and goes yellow/red when stale.
- If `SYNC_ALERT_EMAIL` is set, failed runs email the output.

Scheduled runs wrap with `withoutOverlapping(10)` so a long run doesn't stack on top of the next one.

## System Overview

### Product Types & Batch Coding
Mossfield Organic Farm produces three main product categories:

1. **Milk**: 1L and 2L bottles (batch code: **M**ddmmyy)
2. **Yoghurt**: 250g and 500g tubs (batch code: **Y**ddmmyy)  
3. **Cheese**: Multiple varieties with maturation tracking (batch code: **G**ddmmyy)
   - Farmhouse, Garlic & Basil, Tomato & Herb, Cumin Seed, Mature
   - Produced as wheels, later cut into vacuum packs
   - Full traceability from wheel to individual pack

### Stock Timeline Views

The system provides three distinct views for managing maturing stock:

#### 🗓️ **Timeline View** (Default)
- **Gantt-style layout** showing 6 months of production planning
- **Batch codes** on the left with visual quantity indicators (●●●)
- **Month headers** spanning across their respective weeks
- **Visual symbols**: ▶ (production start), ■ (ready date), progress bars
- **Color-coded** by cheese type with urgency indicators
- **Sorting options**: Ready date, cheese type, batch code, quantity

#### 📅 **Calendar View**
- **Month/week grid** with batches organized by ready dates
- **Weekly subdivisions** with progress indicators
- **Detailed batch cards** with countdown timers

#### 📊 **Table View**
- **Traditional tabular** format with full batch details
- **Progress bars** and expiry warnings
- **Sortable columns** for detailed analysis

### Key Workflows

1. **Production** → Create batches with automatic code generation
2. **Maturation** → Track cheese aging with visual timeline
3. **Cutting** → Convert wheels to vacuum packs (cheese only)
4. **Orders** → Customer orders with stock allocation
5. **Fulfillment** → FIFO allocation with individual weight entry for cheese
6. **Online Orders** → Preview and import orders from Mossorders portal

## Built With Laravel

This application is built on Laravel - a web application framework with expressive, elegant syntax. Laravel provides:

- [Simple, fast routing engine](https://laravel.com/docs/routing)
- [Powerful dependency injection container](https://laravel.com/docs/container)
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent)
- Database agnostic [schema migrations](https://laravel.com/docs/migrations)
- [Robust background job processing](https://laravel.com/docs/queues)
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting)

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
