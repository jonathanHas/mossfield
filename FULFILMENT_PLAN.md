# Fulfilment Plan

Roadmap for implementing the Claude-Design "Mossfield Fulfilment" handoff
(`mossfield-order-fullfillment/` bundle, May 2026). Tracks the gap between
the current state of `/orders` and the design's full picking → dispatch →
delivery flow across desktop and mobile.

Tick the boxes as work lands. Future sessions can resume from the next
unchecked phase.

---

## Context

**What we have today** (verified against `main`, May 2026):
- `orders.status` enum already includes `dispatched` and `delivered` — they're
  reachable only via the generic edit form; no dedicated CTAs.
- `OrderController` exposes `index/show/create/store/edit/update`. No status-
  transition methods.
- `OrderPolicy` is a stub extending `BasePolicy` → admin/office write, factory
  read-only, driver denied. `'see-financials'` gate hides totals from factory.
- `OrderAllocationController` is complete and tested
  (`OrderAllocationConcurrencyTest`) — picking flow works end-to-end via
  `fulfill` / `unfulfill`.
- CSS uses the `mf-*` namespace. `mf-stepper` / `mf-step*` from the design's
  `mf.css` are **NOT** yet in `resources/css/app.css`.
- No `dispatched_at`, `delivered_at`, `picked_at`, `picked_by`, `driver_id`,
  `vehicle`, or `route` columns exist on `orders` — all greenfield.
- No `DispatchController`, no `/dispatches` route, no driver-role surfaces.

**What the design proposes** (5 sections, 12 artboards):
1. Enhanced `/orders/{order}` — status stepper + ready-ribbon + status-driven CTAs.
2. `/dispatches` queue grouped by delivery date with overdue flagging.
3. Bulk dispatch confirmation (driver + vehicle + ordered stop list).
4. Mobile factory picking (5 phone screens — Today, Order overview, Pick fixed,
   Pick variable-weight, Order ready).
5. Driver route + proof-of-delivery (2 phone screens — Route in progress,
   Confirm delivery).

Source design files live in the `mossfield-order-fullfillment/` handoff bundle
(extracted to `/tmp/mossfield-design/` during planning). Authoritative
references: `Mossfield Fulfilment.html`, `shared.jsx`, `desktop-screens.jsx`,
`mobile-screens.jsx`, `styles/mf.css`.

**Decisions locked in for this work**:
- Status transitions get **named routes + small controller methods**
  (`orders.dispatch`, `orders.deliver`) rather than overloading the generic
  PATCH. Cleaner authorization story, room to add per-transition validation.
- Wholesale.mossfield.ie is a staging host — we can ship and iterate, no
  production-gate worry. (See memory: project_deploy_environment.)

---

## Update — shipped since this plan (2026-05-25)

Work has moved past Phase 1 and diverged from a few of its details below. Current state on `main`:

- **Allocation folded inline.** The separate `/order-allocations/{order}` page is retired — its `show()` now **redirects to `orders.show`**, and the picking UI (per-item allocate / fulfil / undo / auto-allocate) renders inline on the order detail page via `orders/partials/allocation-items.blade.php` for `confirmed/preparing/ready`. So the Phase 1 CTAs "Manage allocation →" / "Continue picking →" were **removed** (allocation is on the same page now). The worklist `order-allocations.index` and the POST/DELETE action routes remain.
- **Order line editing.** Add (`orders.items.store`), change quantity (`orders.items.update`), and remove (`orders.items.destroy`) lines on an existing order. Shrinking/removing unwinds allocations via `OrderItem::releaseUnits()` (returns reserved + picked stock); removing the only line cancels the order.
- **Status auto-reconcile + cancel.** `Order::reconcilePickingStatus()` keeps the ready⇄preparing boundary correct after edits/undo (undoing a pick un-sticks a wrongly-"ready" order). `canBeCancelled()` now covers `pending|confirmed|preparing|ready`, and cancelling returns **all** reserved + picked stock.
- **Variable-weight capture (desktop done).** Cheese variants are flagged for weighing; fulfilment supports **per-unit** entry (wheels) and a **single total** (`is_bulk_weighed`, vacuum packs). Order totals reflect the **actual fulfilled weight** once a line is fully picked (`invoiceable_total` / `calculateTotals`). This is the desktop version of Phase 3/4's variable-weight picking — the **mobile** factory screens remain the open work.

## Update (2026-06-04)

- **Mobile picking shipped (2026-06-03).** Phases 3/4's factory screens are live at `/picking` — one-tap allocate+fulfil via `OrderPolicy::fulfill`, per-piece and bulk weight entry, € redacted. See `CLAUDE.md` → "Mobile Picking Flow".
- **Chilled run sheet shipped (2026-06-04).** `/chilled-runs` (second design handoff) covers day-grouped order **entry**, "Confirm all" (pending→confirmed → picking queue), and **load-out** (per-stop loaded tick, `OrderPolicy::load`) for the weekly delivery runs. This overlaps a chunk of what **Phase 2's dispatch queue** intended for chilled routes — revisit Phase 2's scope before building it (the remaining gap is the actual `dispatched` transition per run/van and any non-run orders).
- **Still open from the original plan**: driver screens (M6–M7 — route manifest, signature/POD), bulk dispatch.

---

## Phase 1 — Enhanced `/orders/{order}` ✅ **code complete, awaiting browser smoke**

> **Stepper coverage (added post-Phase-1):** the same partial renders at the
> top of `order-allocations/show.blade.php`. The allocation controller now
> auto-flips `confirmed → preparing` on first allocation and
> `preparing → ready` once every item is fully fulfilled, so the stepper
> stays in sync without manual status edits. Covered by
> `tests/Feature/OrderAllocationStatusTransitionsTest`.

Lowest-risk slice: drops into the existing show view, adds two named routes,
no new role surfaces.

### Schema
- [x] Migration `add_dispatched_and_delivered_at_to_orders_table` — two
      nullable timestamps. No backfill needed (existing dispatched/delivered
      rows just won't have a precise timestamp; we display `updated_at`-style
      "—" until set).
- [x] Add `'dispatched_at' => 'datetime'` and `'delivered_at' => 'datetime'`
      to `Order::$casts`. Add both to `$fillable`.

### Routes (`routes/web.php`, inside the `role:admin,office,factory` group, next
to `Route::resource('orders', …)`)
- [x] `POST /orders/{order}/dispatch` → `OrderController@markDispatched`, name
      `orders.dispatch`.
- [x] `POST /orders/{order}/deliver` → `OrderController@markDelivered`, name
      `orders.deliver`.

> Factory is denied at the policy layer (`BasePolicy::canWrite` → office only).
> Putting the routes in the read-shared group is consistent with how
> `orders.update` lives there today.

### Controller (`app/Http/Controllers/OrderController.php`)
- [x] `markDispatched(Order $order): RedirectResponse`
  - `$this->authorize('update', $order)`
  - Guard: status must be `ready`; otherwise flash error + redirect back.
  - Update `status = 'dispatched'`, `dispatched_at = now()`.
  - Redirect to `orders.show` with success flash.
- [x] `markDelivered(Order $order): RedirectResponse`
  - Same shape; guard requires `dispatched`. Sets `delivered_at`.

### CSS (`resources/css/app.css`)
- [x] Port the `mf-stepper`, `mf-step`, `.mf-step-node`, `.mf-step-label`
      block from `mossfield-order-fullfillment/project/styles/mf.css`
      (lines 209-251). Keep variable references (`--accent`, `--ink`,
      `--line`, etc.) — they all already exist in app.css.

### View — `resources/views/orders/show.blade.php`
- [x] Insert a blade partial `orders/partials/status-stepper.blade.php`
      between the page header and the two-column info grid. Takes `$order`,
      renders 6 steps (pending → confirmed → preparing → ready → dispatched
      → delivered). Skip rendering entirely when `status === 'cancelled'`.
      **Also rendered at the top of `order-allocations/show.blade.php`** so
      the user keeps the pipeline context when they hit "Manage allocation".
      The allocation page's back link goes to `orders.show`, not the queue.
- [x] Add a contextual **ready-ribbon** (`mf-flash-success`) just above the
      Order Information panel **when `status === 'ready'`**. Show a "Mark as
      dispatched" inline button. *(Picked-at timestamp deferred — needs join
      onto `order_allocations.fulfilled_at`; trivial follow-up, not blocking.)*
- [x] Replace the existing footer CTA block (lines 216-264 of show.blade.php)
      with status-driven CTAs:
  - `pending` → "Confirm order" (existing, keep) + "Cancel order"
  - `confirmed` → ~~"Manage allocation →"~~ + "Cancel order" *(allocation is now inline — see "Update" above; the nav CTA was removed)*
  - `preparing` → ~~"Continue picking →"~~ *(superseded — allocation renders inline on the order page)*
  - `ready` → "Mark as dispatched" (primary, ink) + "Print delivery note"
    (secondary, ghost — link to nothing yet; tooltip "coming soon")
  - `dispatched` → "Mark as delivered" (accent) + read-only meta showing
    `dispatched_at`
  - `delivered` → read-only "Delivered · {delivered_at d/m H:i}" pill
  - `cancelled` → read-only "Cancelled" pill; no actions
- [x] All CTAs use `<form method="POST" action="{{ route('orders.dispatch', $order) }}">`
      with `@csrf`. JS `confirm()` prompt for the dispatch/deliver transitions
      (matches the existing "Confirm order" pattern).

### Tests (`tests/Feature/OrderTransitionsTest.php` — new)
- [x] admin can POST `/orders/{order}/dispatch` on a ready order → status =
      dispatched, dispatched_at populated.
- [x] office can dispatch.
- [x] factory gets 403 on dispatch.
- [x] driver gets 403 on dispatch.
- [x] dispatch on a pending/confirmed order → redirect back with error;
      status unchanged.
- [x] admin can POST `/orders/{order}/deliver` on a dispatched order →
      delivered, delivered_at populated.
- [x] deliver on a non-dispatched order → error; status unchanged.

> Test DB note: `phpunit.xml` points at a separate `mossfield_test` MySQL
> database (created May 2026). Grant the `mossfield` MySQL user full
> privileges on that schema for `RefreshDatabase` to work.

### Verification
- [x] `php artisan test --filter=OrderTransitionsTest` green (10/10).
- [ ] `npm run build` (or `npm run dev`) — confirm stepper renders, no Tailwind
      purge issues with the new mf-step classes (they're plain CSS, not
      `@apply`, so purge shouldn't touch them).
- [ ] Smoke through the order detail page in each status (`pending`, `ready`,
      `dispatched`, `delivered`) — manually edit a test order's status via
      tinker or seeder to verify each footer state renders.

---

## Phase 2 — Dispatch queue + bulk dispatch

Surfaces the "ready" pile as a first-class workflow.

### Schema
- [ ] Migration `add_dispatch_meta_to_orders_table` — add `route` (nullable
      string), `driver_id` (nullable FK → users, with `onDelete('set null')`),
      `vehicle` (nullable string), `dispatched_at_eta` (nullable datetime).
- [ ] Update `Order::$fillable` + casts.
- [ ] Add `driver()` BelongsTo relation on `Order`.

### Routes (`routes/web.php`, new — admin/office only group)
- [ ] `GET /dispatches` → `DispatchController@index`, name `dispatches.index`.
- [ ] `GET /dispatches/bulk` → `DispatchController@bulkCreate`, name
      `dispatches.bulk.create`.
- [ ] `POST /dispatches/bulk` → `DispatchController@bulkStore`, name
      `dispatches.bulk.store`.

### Controller — `app/Http/Controllers/DispatchController.php` (new)
- [ ] `index`: group ready orders by delivery date (Today / Overdue / Upcoming).
      Reuse the design's `DispatchSection` shape. Computed stats: today count,
      overdue count, today's load (kg, €).
- [ ] `bulkCreate`: accept ?orders[]=… in query, render the bulk confirmation
      page with driver/vehicle selects.
- [ ] `bulkStore`: validate, mark all selected orders dispatched in one
      transaction (`Order::whereIn('id', $ids)->where('status', 'ready')->update([...])`)
      and dispatch notification emails.

### Navigation
- [ ] Add "Dispatch" nav item to the Sales group in
      `resources/views/layouts/navigation.blade.php` between "Stock Allocation"
      and "Customers". Gate behind `$canSeeAllocation` (same admin/office set
      that sees allocation today).

### Views
- [ ] `resources/views/dispatches/index.blade.php` — full design page including
      stats strip, overdue banner, three `DispatchSection` panels.
- [ ] `resources/views/dispatches/bulk.blade.php` — driver/vehicle/leaves-at
      form + ordered stop list + notification preview flash.

### Email
- [ ] Notification mailable `App\Mail\OrderDispatchedNotification` — minimal
      template referencing order_number, delivery_date, tracking link
      placeholder.

### Tests
- [ ] `DispatchControllerTest`: index lists ready orders; overdue grouping
      works; factory denied; bulk store dispatches all selected, sets
      `driver_id` / `vehicle`, sends mailables (using `Mail::fake()`).

---

## Phase 3 — Mobile factory picking flow ✅ **shipped 2026-06-03**

Phone-first surfaces for the factory role. Full reference docs live in
`CLAUDE.md` → "Mobile Picking Flow (`/picking`)". Implementation diverged from
the original sketch below in a few deliberate ways:

### Scope (as landed)
- [x] New `/picking` route group inside `role:admin,office,factory` —
      `PickingController` with `index/show/item/pick/undo`. Office keeps the
      desktop inline allocation UI for power use; both flows write the same
      `order_allocations` rows so they interoperate.
- [x] Factory **is redirected** to `/picking` on login
      (`AuthenticatedSessionController::store()` branches on `isFactory()`);
      a "Picking" nav item (Sales group, admin/office/factory) covers
      discovery for everyone else. Factory dashboard card links there too.
- [x] **Permissions (decided with user):** factory gains allocate + fulfil +
      undo via a new narrow `OrderPolicy::fulfill` ability — their first write
      carve-out. `update` stays office/admin; factory still cannot edit
      orders, lines, or prices. Every € on the picking blades is behind
      `@can('see-financials')`.

### Screens (fewer files than planned — branches over views)
- [x] `picking/index.blade.php` — `MobileToday` (queue + items-picked strip)
- [x] `picking/show.blade.php` — `MobileOrderOverview`, **and** renders the
      `MobileOrderReady` celebration variant once fully fulfilled (no separate
      `ready.blade.php`)
- [x] `picking/item.blade.php` — `MobilePickFixed` + `MobilePickVariable` as
      branches (fixed stepper / per-piece weights with running total / bulk
      single-total via `is_bulk_weighed`), plus a picked-summary + undo state
      for done lines. Alpine-driven; submit disabled until weights complete.
- [x] `layouts/picking.blade.php` (`<x-picking-layout>`) — stripped phone
      shell, no sidebar/topbar, 560px column cap.
- Omitted from the design (no data model behind them): aisle locations,
  barcode scan, shift timer, variance-€ card, "Hand off to dispatch" (no
  dispatch feature yet — Phase 2).

### Endpoints (as landed — differs from the sketch)
- [x] `POST /picking/{order}/items/{orderItem}/pick` — **one-tap
      allocate+fulfil** (`OrderItem::pickFromBatchItem()`): reuses/widens the
      office reservation row (`order_allocations` is unique per
      (order_item, batch_item) — see `extendAllocation()`), then fulfils once
      with the recorded weight. Transactional; fails closed on stale stock.
- [x] `POST /picking/{order}/items/{orderItem}/undo` — unfulfils the line's
      latest pick; reservation survives, stock returns to batch.
- The planned `start`/`ready` endpoints were unnecessary: first pick flips
  `confirmed → preparing` and `Order::reconcilePickingStatus()` already owns
  the `preparing ⇄ ready` boundary (including demotion on undo).

### CSS
- [x] `mob-*` patterns ported to the bottom of `resources/css/app.css`
      (`.mob-shell`, `.mob-head`, `.mob-card`, `.mob-tap-list/-row`,
      `.mob-footer` — now `position: fixed` + safe-area inset, `.mob-fab`,
      `.mob-weight`, `.swatch-batch`, `.mob-step-btn`). Progress bars reuse
      the existing `.mf-bar`.

### Tests
- [x] `tests/Feature/PickingTest.php` — 17 tests: role access (factory in,
      driver 403, office write routes still 403 for factory), one-tap pick,
      reservation reuse/widening, weight validation + €/kg pricing parity
      with desktop, status transitions, undo, queue scoping, € redaction,
      login redirect, all view branches render.

---

## Phase 4 — Driver route + proof of delivery

Driver role becomes first-class.

### Schema
- [ ] Migration: `dispatches` table grouping orders dispatched together
      (driver_id, vehicle, started_at, completed_at), join table
      `dispatch_stops` for the ordered manifest. **OR** add
      `dispatch_group_id` to orders pointing at a `Dispatch` model. Decide
      during plan-out.
- [ ] Migration: `delivery_proofs` table — order_id, received_by, signature
      (path/blob), photo_path, leave_at_door (bool), notes, captured_at,
      captured_by_user_id.

### Routes (driver role)
- [ ] `GET /drive` → `DriverRouteController@today`
- [ ] `GET /drive/{order}` → `DriverRouteController@show`
- [ ] `POST /drive/{order}/arrive`
- [ ] `POST /drive/{order}/deliver` — accepts signature, photo, leave-at-door.

### Roles & nav
- [ ] Driver role gets `/drive` only. Add `driver` to the navigation builder
      with a single nav item, hide everything else.
- [ ] Update `routes/web.php`: add a `role:driver` group.

### Signature pad
- [ ] Pick a library. `signature_pad` (Szymon Nowak, no deps, 9kb gz) is the
      industry standard and the simplest fit. Render to PNG, store on the
      default disk, save the relative path in `delivery_proofs.signature_path`.

### Verification
- [ ] Manual: dispatch an order, log in as driver_test, open `/drive`, deliver
      with signature, verify the proof persists.

---

## Cross-cutting / docs (any phase)

- [x] Update `WORK_IN_PROGRESS.md` once each phase lands. *(done through Phase 3, 2026-06-03)*
- [x] Update `README.md` "Implemented Features" section. *(done through Phase 3, 2026-06-03 — mobile picking + role test users)*
- [ ] Update `CLAUDE.md` Order Structure section once schema columns are
      added (`dispatched_at`, `delivered_at`, etc.). *(Phase 2/4 — no new
      columns yet; Phase 3 docs already landed in `CLAUDE.md` → "Mobile
      Picking Flow" + Security Posture.)*

---

## Out of scope (explicit non-goals)

- Real maps integration (Phase 4 uses an abstracted SVG path placeholder).
- Customer-facing tracking link (the bulk dispatch email teases it but the
  link target is "coming soon").
- Print stylesheets — the user explicitly asked for digital-only fulfilment.
- Customer self-service signature retrieval. Proofs are internal-only for v1.
