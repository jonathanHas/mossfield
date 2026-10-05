# Stock write-offs: a fifth state for stock that is lost

Status: ACCEPTED
Revision: 1
Planner: Fable 5.1
Date: 2026-10-04

## Goal

Stock can currently leave a batch three ways — sold, cut, converted — so a
mouldy wheel, a dropped crate, a tasting sample or a batch that expired on the
shelf has nowhere honest to go: the expired batches on dev still hold 5,907
units as `active` stock. This cycle adds the **write-off**: office/admin
record that N units of a batch item were lost, with a reason from an
extendable list, an optional note, and for weighed stock the measured weight;
the units leave sellable stock everywhere at once (they come off
`quantity_remaining`, which every free-stock surface already reads), stay
counted in the batch's finished weight (the make produced them), and appear
as a fifth state in the reconciliation
`on hand + dispatched + cut + converted + written off = produced`. A
write-off is reversible and stays on the record. Expired stock gets a
one-click write-off. Decisions: `OPEN_QUESTIONS.md` #1.

## Context

HEAD `742674c` + the staged archive of cycle 6. Suite **617 passed, 0 failed**;
any failure is real. `pint --test app tests database routes config` clean.
Nothing pushed or deployed.

**The model to extend**

- `BatchItem` (`app/Models/BatchItem.php`): `quantity_produced`,
  `quantity_remaining`, `quantity_maturing`, `unit_weight_kg`; relations
  `orderAllocations`, `sourceCuttingLogs`, `targetCuttingLogs`,
  `sourceConversionLogs`, `targetConversionLogs`. `available_quantity` =
  `max(0, remaining − outstanding reservations − maturing)`; `holdable_quantity`
  = `remaining − outstanding reservations`. **Every free-stock surface derives
  from `quantity_remaining`**, so reducing it is the whole of "leaves sellable
  stock" — no change needed at the eight sites.
- `BatchItem::wheelStates()` (`:109`): `cut`, `converted`, `reserved` from
  eager-loaded aggregates (`array_key_exists`, cycle 6), `sold = produced −
  remaining − cut − converted`, then `allocated`/`maturing`/`free`. Rendered
  by `batches/partials/_wheel-strip.blade.php` and `_wheel-legend.blade.php`
  (colour tokens in `resources/css/stock.css`: `--state-damaged: var(--danger)`
  exists and is unused).
- `BatchItem::getWeightBreakdownAttribute()` (`:163–234`): wheels in four
  states, measured where a measurement exists (dispatched →
  `actual_weight_kg`; cut → `wheel_weight_kg`), nominal otherwise;
  `reconciles = on_hand + dispatched + cut + converted === produced`;
  `nominal_kg` counts on-hand + converted + unweighed cut + unweighed
  dispatched × unit weight. Packs/milk: `produced × unit` (packs from cuts
  excluded). `Batch::finished_product_weight` / `weight_coverage` sum these;
  `BatchController::show()` eager-loads what the breakdown reads.
- `BatchItem::getQuantitySoldAttribute()` (`:80`) is `produced − remaining`.
- `StockOverviewService`: simple cards `sold = produced − remaining` (`:121`);
  wheel rows `sold = produced − remaining − cut − converted` (`:196`);
  segments arrays at `:131–135`, `:205–211`; `components/stock/cheese-row`
  renders any `segments` state with `var(--state-{state})`; the index-style
  eager-loads (`StockOverviewService:21–27`, `BatchController::index():20–35`,
  `CheeseCuttingController::index():26–33`) carry the counts/sums
  `wheelStates()` reads.
- `/batches?status=expired` (cycle 4) matches `status=expired` or active past
  expiry; the dashboard **Expired stock** tile (`routes/web.php`) counts
  active, past-expiry batches with `quantity_remaining > 0` and links there;
  `/stock` cards print an expired note (`stock/partials/_expired-note`).
  Batch `status` enum: `active | sold_out | expired`.
- Batch page `batches/show.blade.php`: panels for info (`:45`), items table
  (`:134`), cutting history (`:189`), the items/actions panel (`:248`) with
  Cut / hold buttons and the conversion history incl. "Return to farmhouse
  hold" (`:339`, a `data-confirm` form). `Batch::deletionBlocker()` counts
  allocations/cuts/conversions from the five `*_count`s the index eager-loads.
- Admin-ish CRUD pattern to copy: `DeliveryRunController` + `resources/views/delivery-runs/`
  (index with inline forms; create/edit pages). Policies: `BasePolicy`
  subclasses registered in `AuthServiceProvider::$policies`. Office routes
  live in the `role:admin,office` group in `routes/web.php` (`:138+`).
- Housekeeping that fails tests if missed: `ResetTradingData::WIPE_ORDER`
  (trading tables, FK order, `batch_items` near the end) / `KEEP` (reference
  data) — `ResetTradingDataTest` fails on an unclassified table;
  `BackupService::TABLES` (insert order) — `BackupRestoreTest` round-trips
  them; `AuthorizationBoundaryTest` needs a fixture for every new route
  parameter; `ConfirmDialogTest` scans views for `confirm(`;
  `LayoutFeedbackTest` forbids a second flash region.
- Seeders: `DatabaseSeeder` calls `AdminUserSeeder`, `ProductSeeder`,
  `BatchSeeder`, `CustomerSeeder`. Production installs run migrations and
  selected seeders (`DEPLOYMENT.md`); defaults a feature needs should ship
  in the **migration** so no deploy step is forgotten.
- Nav: Production group (`layouts/navigation.blade.php:66`) holds Batches,
  Cheese Cutting, Mature Conversion for admin/office/factory.

## Constraints

- **Units leave via `quantity_remaining` only.** No new column feeds any
  free-stock expression; the eight sites stay untouched (`TESTING_AND_FIXES.md`
  → invariants).
- **Write-offs never touch reserved stock.** The bound is
  `holdable_quantity` (remaining − outstanding reservations). Held
  (maturing) wheels **can** be written off — a maturing wheel going mouldy
  is the main case — and the hold is clamped to what remains.
- **Reversal keeps the row.** `reversed_at`/`reversed_by` — never delete.
  Aggregates and the weight model read **active** write-offs only (this is a
  legitimate filter: a reversed write-off is not a write-off; don't confuse
  it with the `fulfilled_at` rule).
- **Written-off units count in finished weight and yield, not in sellable
  stock** (OPEN_QUESTIONS #1, point 6).
- Office/admin write, factory read-only (standard `BasePolicy`), driver
  denied.
- `data-confirm` on reverse and on the expired bulk action; no new flash
  region; `npm run build` after CSS; `./vendor/bin/pint` on PHP.
- **Do not commit.** Planner commits on acceptance. Nothing pushed/deployed.

## Out of scope

- Partial-weight loss on a unit that stays in stock (trim/salvage) —
  recorded as out of scope in OPEN_QUESTIONS #1.
- Valuing losses (€ per batch), reports, a scheduled "expired → write off"
  sweep (write-off of expired stock is a manual one-click action).
- Changing batch `status` automatically (a fully written-off batch simply
  has 0 remaining; the dashboard tile and `/stock` notes drop it because
  they filter on `quantity_remaining > 0`).
- Cycle 8's policy items (delivery per drop, credit-limit warning, Mature
  conversion-only, sanity ceiling, milk yield, internal notes, dispatch
  alignment).

## Steps

### 1. Schema and models

Files: `database/migrations/2026_10_04_130000_create_write_off_reasons_table.php` (new),
`database/migrations/2026_10_04_130100_create_stock_write_offs_table.php` (new),
`app/Models/WriteOffReason.php` (new), `app/Models/StockWriteOff.php` (new),
`app/Models/BatchItem.php`, `app/Models/Batch.php`, `app/Models/User.php`

What:
- `write_off_reasons`: `id`, `code` (string 40, unique, slug), `label`
  (string 80), `is_active` (bool, default true), `sort_order` (unsigned
  smallint, default 0), timestamps. **The migration inserts the defaults**
  (`insertOrIgnore` on `code`): `spoiled` "Spoiled / mouldy", `damaged`
  "Damaged / dropped", `sample` "Sample / tasting", `own_use` "Own use",
  `stocktake` "Stocktake adjustment", `expired` "Expired", `returned`
  "Customer return (not resaleable)", `sort_order` 10, 20, … 70.
- `stock_write_offs`: `id`, `batch_item_id` FK (`constrained()->cascadeOnDelete()`,
  like the other child tables — the delete guard is `Batch::deletionBlocker()`,
  step 6), `write_off_reason_id` FK (`constrained()->restrictOnDelete()`),
  `quantity` (unsigned int), `weight_kg` (decimal 8,3 nullable — the
  measured weight of the written-off units, variable-weight variants only),
  `note` (string 255 nullable), `written_off_on` (date), `written_off_by`
  FK users (`nullOnDelete`), `reversed_at` (timestamp nullable),
  `reversed_by` FK users nullable (`nullOnDelete`), timestamps. Index
  `(batch_item_id, reversed_at)`.
- `WriteOffReason`: fillable `code, label, is_active, sort_order`; scope
  `active()`; `writeOffs()` hasMany; `isInUse()` = `writeOffs()->exists()`.
- `StockWriteOff`: fillable all of the above; casts `weight_kg decimal:3`,
  `written_off_on date`, `reversed_at datetime`; `batchItem()`, `reason()`,
  `writtenOffBy()`, `reversedBy()`; scope `active()` (`whereNull('reversed_at')`);
  `isReversed()`.
- `BatchItem::writeOffs()` hasMany; `BatchItem::getWrittenOffUnitsAttribute()`
  reads `written_off_units` when eager-loaded (`array_key_exists`, the
  cycle-6 rule) else sums active rows. `getQuantitySoldAttribute()` becomes
  `max(0, produced − remaining − written_off_units)`.
- `Batch::writeOffs()` hasManyThrough batch_items (like `cuttingLogs()`).
- `User::stockWriteOffs()` hasMany (optional, for the audit list).

Check: `php artisan migrate` clean; `php artisan tinker --execute='echo
App\Models\WriteOffReason::count();'` → 7; `php artisan migrate:rollback
--step=2 && php artisan migrate` round-trips.

### 2. Recording and reversing — the service

Files: `app/Services/StockWriteOffService.php` (new), `app/Services/InvoicingException.php`
(reuse pattern — or a new `App\Services\StockException`), `tests/Feature/StockWriteOffTest.php` (new)

What:
- `record(BatchItem $item, WriteOffReason $reason, int $quantity, ?float $weightKg,
  ?string $note, CarbonInterface $on, User $by): StockWriteOff` in one
  `DB::transaction` with `lockForUpdate()` on the batch item:
  - refuse `quantity < 1`; refuse `quantity > holdable_quantity` with the
    message `Only N units can be written off — M are reserved for orders.
    Release them first.` (M = outstanding reservations when they bite,
    else the plain bound);
  - refuse `weight_kg` on a non-variable-weight variant; refuse
    `weight_kg` > 3 × nominal × quantity when a nominal exists (the cycle-3
    bound, same message family);
  - refuse an inactive reason; refuse `on` before the batch's production
    date or after today;
  - `quantity_remaining −= quantity`; then
    `quantity_maturing = min(quantity_maturing, max(0, quantity_remaining −
    outstanding reservations))`;
  - create the row; return it.
- `reverse(StockWriteOff $wo, User $by): void` — refuse if already reversed;
  `quantity_remaining += quantity` (sanity: refuse if that would exceed
  `quantity_produced` — it can't, but say so if it would); stamp
  `reversed_at/by`. **Does not** restore a maturing hold (note in the
  flash: "…returned to free stock").
- `writeOffExpired(Batch $batch, ?string $note, User $by): Collection` — for
  every item of a batch whose `isExpired()` is true, record
  `holdable_quantity` units with reason `expired`; skip items with 0; return
  the rows; refuse (exception) when the batch isn't expired or nothing is
  writable.
- Tests (`StockWriteOffTest`, new; fixtures in the file's own `setUp`, as
  `CheeseConversionTest` does): record reduces `quantity_remaining` and the
  units vanish from `BatchItem::available_quantity`, `freeStockByVariant()`,
  `/stock`'s available segment, the cutting index and the picking batch
  chooser (five sites, one assertion each — the point of the invariant);
  over-reserved refused with the message; a held wheel written off clamps
  `quantity_maturing`; weight refused on milk; weight bound; inactive reason
  refused; date bounds; reverse restores and stamps; double reverse refused;
  `writeOffExpired` writes off every item and skips a non-expired batch;
  `quantity_sold` excludes write-offs.

Check: `php artisan test --filter=StockWriteOffTest` pass.

### 3. The fifth state in the weight model and the wheel strip

Files: `app/Models/BatchItem.php`, `app/Services/StockOverviewService.php`,
`app/Http/Controllers/BatchController.php`, `app/Http/Controllers/CheeseCuttingController.php`,
`resources/views/batches/partials/_wheel-strip.blade.php`,
`resources/views/batches/partials/_wheel-legend.blade.php`,
`resources/views/components/stock/cheese-row.blade.php` (only if its state
list is hard-coded), `resources/css/stock.css`,
`tests/Feature/BatchFinishedWeightTest.php`, `tests/Feature/StockOverviewTest.php`,
`tests/Feature/StockWriteOffTest.php`

What:
- **Eager-loads**: `BatchController::index()`, `CheeseCuttingController::index()`
  and `StockOverviewService::build()` add
  `->withSum(['writeOffs as written_off_units' => fn ($q) => $q->whereNull('reversed_at')], 'quantity')`
  next to `converted_wheels`. `BatchController::show()` adds
  `'batchItems.writeOffs.reason'` and `'batchItems.writeOffs.writtenOffBy'`.
- **`wheelStates()`**: `written_off` from the loaded sum (null → 0) else a
  query; `sold = max(0, produced − remaining − cut − converted − written_off)`;
  `remaining = max(0, produced − cut − converted − sold − written_off)`;
  return it in the array. Strip: a circle in `var(--state-damaged)` with
  title "Written off" placed after converted; legend and the numeric
  summary print `N written off` (drop when 0, as the others). Rename the
  token to `--state-written-off` (keep `--state-damaged` as an alias) so the
  stock component's `var(--state-{{ $state }})` resolves for a segment key
  `written_off` — check how `cheese-row` maps keys to tokens (underscore vs
  hyphen) and make them agree.
- **`weight_breakdown`**: new key `written_off` (units) for every kind;
  wheels: each active write-off adds its `quantity` to `written_off`; when
  `weight_kg` is set it adds to `weighed` (by `quantity`) and `measured_kg`,
  else to a `writtenOffNominalUnits` that joins the `nominal_kg` product;
  `reconciles = on_hand + dispatched + cut + converted + written_off ===
  produced`. Packs/milk: `written_off` units counted but **`nominal_kg`
  unchanged** (produced × unit — they were made). Update the docblock's
  state list.
- **Batch page coverage line**: "N of M wheels weighed" keeps counting
  write-offs with a weight as weighed.
- **`StockOverviewService`**: simple cards `sold = max(0, produced − remaining −
  written_off)` and a `written_off` segment; wheel rows `sold = … −
  written_off` and a `written_off` segment after `converted`; packs likewise.
  `buildCheeseCard`'s `tag`/totals include it where `sold` is summed.
- Tests: `BatchFinishedWeightTest` — extend the reconciliation case with one
  wheel written off **with** a weight (measured, `weighed` +1) and one
  **without** (nominal), asserting the five states sum to produced and
  `finished_product_weight` still equals the made weight; a milk batch with
  a write-off keeps `nominal_kg = produced × unit` and `written_off = N`.
  `StockOverviewTest` — a written-off bottle shows in a `written off`
  segment and not in `sold`. `StockWriteOffTest` — `/batches` card summary
  reads "… · 2 written off · …" and the strip has two `--state-written-off`
  circles; the index query count doesn't grow (extend the cycle-6 flat-count
  test's fixture with a write-off).

Check: `php artisan test --filter="BatchFinishedWeightTest|StockOverviewTest|StockWriteOffTest|BatchCreateTest|ConsolidationPassTest"`
pass.

### 4. Routes, policies, controller

Files: `routes/web.php`, `app/Policies/StockWriteOffPolicy.php` (new),
`app/Policies/WriteOffReasonPolicy.php` (new), `app/Providers/AuthServiceProvider.php`,
`app/Http/Controllers/StockWriteOffController.php` (new),
`app/Http/Requests/StockWriteOffRequest.php` (new),
`tests/Feature/StockWriteOffTest.php`, `tests/Feature/AuthorizationBoundaryTest.php`

What:
- Policies: both `extends BasePolicy` (office write, factory view, driver
  deny); register.
- Routes in the **office group**:
  `POST /batches/{batch}/write-offs` → `store` (`batches.write-offs.store`);
  `POST /batches/{batch}/write-offs/expired` → `storeExpired`
  (`batches.write-offs.expired`); `POST /write-offs/{writeOff}/reverse` →
  `reverse` (`write-offs.reverse`).
- `StockWriteOffRequest::authorize()` = `can('create', StockWriteOff::class)`
  (the cycle-3 rule for FormRequests); rules: `batch_item_id` required,
  exists, **belongs to the route batch** (closure rule);
  `write_off_reason_id` required, exists, active; `quantity` required int
  min 1; `weight_kg` nullable numeric min 0.001; `note` nullable max 255;
  `written_off_on` required date. Service exceptions → `redirect()->route('batches.show',
  $batch)->withFragment('write-offs')->withErrors([...])->withInput()`;
  success → same fragment with the flash `Written off N × {variant} — {reason}.`
- `reverse()`: `authorize('update', $writeOff)`; service; flash `Write-off
  reversed — N units returned to free stock.`; redirect to the batch page
  fragment.
- `storeExpired()`: `authorize('create')`; `note` nullable; flash lists what
  was written off per item.
- Tests: factory 403 on all three; office succeeds; a `batch_item_id` from
  another batch → 422; `AuthorizationBoundaryTest::buildFixtures()` gains a
  `writeOff` row and a `write_off_reason` (step 5 adds that parameter).

Check: `php artisan test --filter="StockWriteOffTest|AuthorizationBoundaryTest"` pass.

### 5. Reasons are manageable

Files: `app/Http/Controllers/WriteOffReasonController.php` (new),
`resources/views/write-off-reasons/index.blade.php` (new), `routes/web.php`,
`resources/views/layouts/navigation.blade.php`, `tests/Feature/StockWriteOffTest.php`

What:
- `Route::resource('write-off-reasons', …)->only(['index', 'store', 'update'])`
  in the office group (no destroy: a used reason is **deactivated**, an
  unused one may be deleted — add `destroy` guarded by `isInUse()` → flash
  error, if you prefer; either way no orphaned write-offs).
- One page: the list (label, code, active, in-use count) with inline
  rename / activate-deactivate forms, and an "Add reason" form (label →
  code auto-slugged server-side, unique). Pattern: `delivery-runs/index`.
- Nav: under Production for admin/office only — "Write-off reasons" is
  admin housekeeping, so put it in the **Admin** group beside Users (or
  Miscellaneous — your call, say which), not in the factory's view.
- Tests: add/rename/deactivate; deactivated reason absent from the batch
  page's select and refused by the service; factory 403.

Check: `php artisan test --filter=StockWriteOffTest` pass.

### 6. The batch page: record, see, reverse; the expired one-click

Files: `resources/views/batches/show.blade.php`,
`resources/views/batches/partials/_write-offs.blade.php` (new),
`resources/views/batches/partials/batch-card.blade.php`, `app/Models/Batch.php`,
`app/Http/Controllers/BatchController.php`, `resources/views/dashboard.blade.php`,
`resources/views/stock/partials/_expired-note.blade.php`, `tests/Feature/StockWriteOffTest.php`

What:
- New panel **Write-offs** (`id="write-offs"`, `scroll-mt-20`) after the
  cutting history: the form (`@can('create', StockWriteOff::class)`): item
  `<select>` (hidden when the batch has one item), quantity, weight (shown
  only for a variable-weight variant — Alpine on the item select, label
  "Measured weight of these N units (kg)", optional), reason `<select>` of
  active reasons with a "Manage reasons" link, note, date (default today);
  then the history table: date · item · qty · kg · reason · note · by ·
  Reverse (`data-confirm="Reverse this write-off? N units go back into free
  stock." data-confirm-label="Reverse"`); reversed rows struck through with
  "reversed {date} by {name}". Factory sees the table, no forms.
- **Expired one-click**: when `$batch->isExpired()` and any item has
  `holdable_quantity > 0`, a danger-tone button in the batch header
  **"Write off expired stock (N units)"** with `data-confirm` naming the
  units and the reason, posting `batches.write-offs.expired` (optional note
  in the dialog is not possible — accept none; the service writes the note
  "Expired — written off from the batch page").
- Info panel: a **Written off** row ("N units · X kg measured") when > 0;
  the reconciliation warning text gains the fifth term.
- Card (`batch-card`): an expired card's "Expired" tag is followed by a
  "Write off →" link to the batch page `#write-offs` when the batch still
  has holdable units; `Batch::deletionBlocker()` and `dependentCounts()`
  count write-offs (`stock_write_offs_count` via `withCount` on the index
  eager-load — reversed rows count too; they're history).
- Dashboard tile sub-line "write off or close" → "write off" linking as now;
  `/stock` expired note unchanged (already links to the expired list).
- Tests: the panel renders for office with the form and for factory without
  it; a POST writes off and the page shows the row; reverse works through
  the UI route; the expired button appears only on an expired batch with
  stock and writes everything off; the card link appears; `deletionBlocker`
  names write-offs.

Check: `php artisan test --filter="StockWriteOffTest|BatchCreateTest"` pass.
Browser as office: `/batches/19` (M230726, expired, 951 left) → "Write off
expired stock (951 units)" → dialog → the panel lists one row per item,
`/stock` milk note drops from 4 expired batches to 3, the dashboard tile from
5 to 4; reverse one row → back; on G010426 write off 1 wheel at 4.900 kg
reason Spoiled → strip shows a red circle, coverage counts it weighed,
finished weight unchanged, `/cheese-cutting` shows one fewer free wheel.

### 7. Housekeeping the suite insists on

Files: `app/Console/Commands/ResetTradingData.php`, `app/Services/BackupService.php`,
`tests/Feature/BackupRestoreTest.php`, `tests/Feature/ResetTradingDataTest.php`

What:
- `ResetTradingData::WIPE_ORDER`: `stock_write_offs` **before** `batch_items`
  (FK order); `KEEP`: `write_off_reasons`.
- `BackupService::TABLES`: `write_off_reasons` after `users`;
  `stock_write_offs` after `batch_items` (and after `users` — it references
  both).
- Tests: `ResetTradingDataTest` passes as-is once classified (that's the
  check); `BackupRestoreTest` round-trip includes a write-off and a custom
  reason and restores both.

Check: `php artisan test --filter="ResetTradingDataTest|BackupRestoreTest"` pass.

### 8. Docs

Files: `CLAUDE.md`, `docs/planImp/WORK_IN_PROGRESS.md`, `README.md`

What:
- `CLAUDE.md`: a new **Stock Write-offs** section (model, the
  `holdable_quantity` bound, maturing clamp, reversal keeps the row,
  active-only aggregates, the five-state reconciliation, the eager-load key
  `written_off_units`, routes/policies, reasons CRUD, the expired one-click);
  update **Finished weight & yield** (five states), **Wheel & Vac-Pack
  Visualization** (the `written_off` state and token), **Stock Overview**
  (segment), **Resetting Dev Trading Data** (the two tables' classification),
  and the Business Intelligence bullet that said losses can't be recorded.
- `README.md` → Implemented Features: one bullet.
- `WORK_IN_PROGRESS.md`: *Recently Completed: Stock write-offs (2026-10-04)*.

Check: `grep -n "Stock Write-offs\|written_off_units\|writeOffExpired" CLAUDE.md` → hits.

## Verification

1. `php artisan test` → **0 failed**; record the count.
2. `./vendor/bin/pint --test app tests database routes config` → clean.
3. `npm run build` → ok.
4. `php artisan migrate:status | tail -4` shows both migrations ran;
   `php artisan tinker --execute='echo App\Models\WriteOffReason::count();'` → 7.
5. `git status --short` → only files this plan names (+ the staged archive).
6. Browser checks from step 6 with real input; console clean.
7. Dev-DB probe: the browser checks **will** write off real expired dev stock
   (M230726) and one G010426 wheel — that's the feature working; reverse the
   G010426 one so its lineage numbers stay as `RESUME.md` records them, and
   leave the expired write-off in place (it is correct). Record before/after
   counts of `stock_write_offs`, `batch_items.quantity_remaining` for the
   touched items, and the dashboard tile.

## Risks

- **Reconciliation tests**: every existing fixture must still reconcile with
  `written_off = 0`; the new term defaults to 0 when the relation/aggregate
  is absent — don't make `weight_breakdown` lazy-load `writeOffs` (the
  docblock's "reads only LOADED relations" rule). `BatchController::show()`
  loads it; for other callers an unloaded relation must read as zero, not
  as a query — mirror how `sourceCuttingLogs` is handled.
- **`whereNull('reversed_at')` is a legitimate filter.** Say so in a comment
  where it's used so a future reader doesn't "fix" it like the
  `fulfilled_at` one.
- **The maturing clamp** can silently shrink a hold; the flash must say
  "hold reduced to N" when it does.
- **Expired one-click on a batch with reservations**: it writes off only the
  holdable units and the flash must list what was skipped and why.
- **State key spelling**: `written_off` in PHP arrays vs `written-off` in CSS
  tokens — one mapping, tested by the strip's rendered class/style.
- **Migration defaults**: `insertOrIgnore` keyed on `code` so re-running on a
  DB that already has reasons doesn't duplicate; the test DB gets them from
  the migration too (RefreshDatabase).

## Review

Reviewed 2026-10-05 against `implemented.md` (Status: DONE, 617 lines, read
to the end) and the diff: service, request, controller, both migrations,
both models, `BatchItem`/`Batch`/`StockOverviewService`/`BackupService`,
routes, policies, the write-offs panel and the batch-page header. Planner
reran: `php artisan test` → **647 passed, 0 failed** (+30); `pint --test …
config` → PASS 289 files; `npm run build` ok; both migrations `[6] Ran`; 7
reasons; dev DB: 4 write-off rows (2 active), M230726 at 0/0/0, G010426
back at `remaining 7 · hold 1`, `reconciles = y`, finished weight 60.31;
orders/payments/invoices unchanged. Checked that the only consumer of
`weight_breakdown` (the batch page) loads `writeOffs`, so the
"unloaded reads as none" rule cannot mislead.

### Criteria — all eight pass

1 Schema and defaults in the migration (`insertOrIgnore`), FKs as specified,
models with `active()`; `quantity_sold` excludes write-offs. 2 One service
path, locked, with every refusal the plan listed and the exact over-reserved
message; maturing clamp with `holdReducedTo`; reverse keeps the row;
`writeOffExpired()` skips reserved units. Ten service tests incl. the five
free-stock sites. 3 Fifth state everywhere: `wheelStates()`, strip, legend,
`weight_breakdown` (measured where weighed), `/stock` segments, one
key→token/label mapping across the three stock components; the index
query count stays flat. 4 Routes in the office group, FormRequest carrying
the policy and the belongs-to-batch closure, flashes as specified,
boundary-test fixtures. 5 Reasons page (no destroy; deactivate), slug codes,
nav in Miscellaneous — right call, office needs it. 6 Panel with the form
(weight only for weighed items), history with reverse, the expired
one-click, the Written-off info row, the card link, `deletionBlocker()`
counting write-offs. 7 Reset and backup tables classified; **plus**
`BackupService::ADDED_LATER` so pre-deploy backups stay restorable — a real
deployment hazard the plan missed, found and fixed with tests. 8 Docs.

### Deviations — all accepted

1 baseline; 2 the two index views' `array_fill_keys` and `StockException`;
3 the key/token mapping; **4–5 two more "sold = produced − remaining" sites
found in the browser** (batch-page variant table, card pack bar) and fixed
with tests; 6 comma handler on the weight box (F-29 pattern); **7 corrects
the plan**: finished weight *does* move when a write-off is weighed — from
nominal to measured for that wheel, exactly as a dispatched or cut wheel —
and that is the model working; 8 `ADDED_LATER`; 9 Miscellaneous nav; 10–12
fine.

### Notes for Planner — decisions

- Card items table lumps cut/converted into "Sold" (pre-existing) → **next
  polish batch**: use `wheelStates()['sold']` for wheels.
- `isExpired()` (`< now()`) vs tile (`<= today`) agree in practice → leave.
- `ADDED_LATER` convention → documented in CLAUDE.md by the Implementer;
  `DEPLOYMENT.md` has no backup section to amend → nothing more.
- `/batches?status=expired` still lists a fully written-off batch → **next
  polish batch**: a "written off" tag on such cards (the filter is right —
  it *is* expired; what's missing is the state).
- Dev data: M230726 written off (correct, kept); G010426 probe reversed as
  the plan asked.

Nothing to redo. Archive: `mkdir -p docs/planImp/archive/2026-10-05-write-offs`
and move `plan.md` + `implemented.md` there.
