# Loose ends: an allocate crash, a gated reservation sum, an N+1, and three small things

Status: READY
Revision: 1
Planner: Fable 5.1
Date: 2026-10-04

## Goal

Close what cycles 4–5 left behind and two defects the Planner found while
reading for this plan. After it: topping up a reservation from the same batch
on the order page works instead of crashing; the mature-conversion allocator
counts reserved wheels the same way every other free-stock surface does; the
`/batches` page costs the same number of queries whether it shows 2 batches
or 40; a saved run-sheet row lands under its column headers; the last two
`/orders` cells don't wrap at phone width; and an unused Breeze component is
gone.

## Context

HEAD `292cbfd` + the staged archive of cycle 5. Suite **612 passed, 0 failed**;
any failure is real. `pint --test app tests database routes config` clean.
Nothing pushed or deployed.

**Facts the steps rely on**

- `OrderItem::allocateFromBatchItem()` (`app/Models/OrderItem.php:116–147`)
  locks the batch item, checks availability and the line ceiling, then
  **always** `create()`s an `order_allocations` row. The table is unique per
  `(order_item_id, batch_item_id)`, so a second allocation from the same batch
  throws `UniqueConstraintViolationException` → 500 on
  `POST /order-allocations/{orderItem}/allocate` (found in cycle 5; the
  cycle-5 test for the "only needs N more" message had to use a second batch
  item to dodge it). `OrderItem::extendAllocation()` (`:276–307`, private)
  already does the right thing for `pickFromBatchItem()`: lock, check
  `available_quantity >= units` (which excludes the row's own unfulfilled
  reservation), check the line ceiling, widen `quantity_allocated`, clear
  `fulfilled_at` when under-fulfilled, roll up the line.
- **Free-stock invariant** (`CLAUDE.md` → Mature Conversion; `TESTING_AND_FIXES.md`
  → 2026-09-12 audit, finding 1): the reservation is
  `SUM(quantity_allocated − quantity_fulfilled)` over **all** allocations,
  **never filtered on `fulfilled_at`** — `fulfilled_at` is stamped on a
  partial pick (`OrderItem.php:203`) while units are still owed.
  `CheeseConversionController::index()` (`:34–38`) still has
  `->withSum(['orderAllocations as quantity_currently_allocated' => fn ($qa)
  => $qa->whereNull('fulfilled_at')], DB::raw('quantity_allocated - quantity_fulfilled'))`.
  The view (`cheese-conversion/index.blade.php:11–13`) computes `holdable =
  remaining − that sum`, so a partially picked reservation makes wheels look
  holdable that are already owed. `CheeseCuttingController::index()` (`:30–33`)
  and `BatchController::index()` are ungated — copy them.
- `BatchItem::wheelStates()` (`app/Models/BatchItem.php:109–128`) reads
  `getAttribute('converted_wheels') ?? <query>` and
  `getAttribute('quantity_currently_allocated') ?? <query>`. `withSum` yields
  **`null`** when there are no matching rows, so every item with no
  conversions and every item with no allocations runs a live `SUM` — the
  N+1 the cycle-5 report saw on `/batches` (and `/cheese-cutting`, which
  eager-loads the same sums). `source_cutting_logs_count` is a `withCount`
  (0, never null) and is fine. `BatchCreateTest::test_the_index_hides_delete_…`
  (`:307–340`) already has a `DB::enableQueryLog()` helper that counts the
  `/batches` queries at 2 and 4 batches but only asserts the deletion-count
  query; the cycle-5 report says the remaining growth is entirely these SUMs.
- Run sheet: `_run-table.blade.php:48–58` wraps `<table class="mf-run">` in
  `<div class="overflow-x-auto">`; `.mf-run thead th { background: var(--bg) }`
  (`app.css:513`) plus tinted `.col-*` header backgrounds (`:549,550,584`).
  A scroll container (`overflow-x: auto` makes `overflow-y` compute to
  `auto` too) traps `position: sticky` inside itself, so a sticky `<thead>`
  only works when the wrapper isn't a scroll container. The sheet fits at
  the desktop widths the office uses (~1 000 px for 17 columns); the
  horizontal scroll matters only below that.
- `/orders` (`orders/index.blade.php:100–132`): the number and status cells
  have `whitespace-nowrap` (cycle 5); the Payment cell (`:115–120`) and the
  actions cell (`:124–130`) don't.
- `resources/views/components/application-logo.blade.php` is referenced
  nowhere (grep of `resources` and `app`).
- Tests: `OrderAllocationConcurrencyTest` (allocation window cases;
  `test_asking_for_more_than_the_line_needs_says_how_many_it_needs` at
  `:158` uses a second batch item), `CheeseConversionTest`
  (`test_held_wheels_are_excluded_from_auto_allocation`), `CheeseCutFreeStockTest`
  (`test_the_cutting_index_never_gates_the_reservation_on_fulfilled_at` at
  `:214` is the template for step 2's test), `BatchCreateTest`, `ChilledRunTest`.

## Constraints

- The reservation expression stays `quantity_allocated − quantity_fulfilled`
  over all allocations at every site; this cycle only removes a filter, it
  never adds one.
- `allocateFromBatchItem()` keeps its return type (`?OrderAllocation`) and its
  guards (lock, availability, line ceiling) — widening must pass the same
  checks.
- No other behaviour changes; every flash text stays.
- `npm run build` after CSS changes; `./vendor/bin/pint` on touched PHP.
- **Do not commit.** Planner commits on acceptance. Nothing pushed/deployed.

## Out of scope

- `OPEN_QUESTIONS.md` #1 and #5.
- Making the sheet header sticky **inside** a horizontal scroll container
  (needs a JS-synced header clone); below the breakpoint it just scrolls.
- Anything in `/orders` beyond the two `whitespace-nowrap`s.

## Steps

### 1. Topping up a reservation from the same batch widens the row (cycle-5 finding)

Files: `app/Models/OrderItem.php`, `tests/Feature/OrderAllocationConcurrencyTest.php`

What:
- In `allocateFromBatchItem()`, after acquiring the lock (inside the
  transaction), look for an existing row:
  `$existing = $this->orderAllocations()->where('batch_item_id', $lockedBatchItem->id)->lockForUpdate()->first()`.
  If found, delegate to `extendAllocation($existing, $lockedBatchItem, $quantity)`
  and return `$existing->fresh()` on success, `null` on refusal. Otherwise
  the current insert path. (`extendAllocation()` opens its own
  `DB::transaction` — nested as a savepoint, fine; it re-locks the batch
  item, which the outer lock already holds in the same transaction — also
  fine.) Keep a one-line comment on why: the unique index.
- Tests (`OrderAllocationConcurrencyTest`): a line of 10, allocate 6 from
  batch X, then **4 more from X** → one `order_allocations` row with
  `quantity_allocated = 10`, line `quantity_allocated = 10`, HTTP redirect
  with the success flash (not 500); a top-up that would exceed the line
  (6 then 5) → refused with the "only needs 4 more" message, row still 6; a
  top-up beyond the batch's free stock → refused with the "only N available"
  message; a top-up on a row that is **partly fulfilled** (6 allocated, 2
  picked, then +4) → `quantity_allocated = 10`, `quantity_fulfilled = 2`,
  `fulfilled_at` null. Rewrite the cycle-5 case at `:158` to use the same
  batch item now that it can.

Check: `php artisan test --filter="OrderAllocationConcurrencyTest|OrderShortSupplyTest|PickingTest"`
pass. Browser (office, any confirmed order with ≥ 2 lines of stock): allocate
part of a line from a batch, then allocate the rest from the **same** batch →
"Allocated N units from batch …", one row in the allocations table.

### 2. The conversion index stops gating on `fulfilled_at` (invariant breach)

Files: `app/Http/Controllers/CheeseConversionController.php`,
`tests/Feature/CheeseConversionTest.php`

What:
- Remove the `=> fn ($qa) => $qa->whereNull('fulfilled_at')` constraint so the
  `withSum` matches `CheeseCuttingController::index()` exactly (same
  `DB::raw('quantity_allocated - quantity_fulfilled')`, no filter). Add the
  same comment the cutting controller carries about never gating on
  `fulfilled_at`.
- Test (`CheeseConversionTest`, modelled on `CheeseCutFreeStockTest:214`): a
  5-wheel item with an allocation of 3 of which **1 is picked**
  (`quantity_fulfilled = 1`, `fulfilled_at` set, `quantity_remaining` 4) →
  GET `/cheese-conversion` as office renders that card with `matureCard(2, 0)`
  (holdable = 4 remaining − 2 still owed), **not** `matureCard(4, 0)`. Also
  POST hold with `quantity = 3` → refused (the server-side cap uses
  `holdable_quantity`, which is ungated — this pins that view and server
  agree).
- Paste `grep -rn "whereNull('fulfilled_at')" app` into the report — the
  only hit may be the docblock at `app/Models/BatchItem.php:~311` that
  explains why the filter was removed; no **code** hit. (`fulfilled_at` is
  still *written* in several places; that's fine.)

Check: `php artisan test --filter="CheeseConversionTest|CheeseCutFreeStockTest"`
pass; the grep is empty.

### 3. `wheelStates()` trusts a loaded null (the `/batches` N+1)

Files: `app/Models/BatchItem.php`, `tests/Feature/BatchCreateTest.php`

What:
- In `wheelStates()`, decide "was it eager-loaded" with
  `array_key_exists('converted_wheels', $this->getAttributes())` (and the
  same for `quantity_currently_allocated`), treating a loaded `null` as 0;
  only query when the key is absent. Keep the relation-loaded fallbacks.
  Apply the same `array_key_exists` test to `source_cutting_logs_count` for
  symmetry.
- Test: extend `BatchCreateTest::test_the_index_hides_delete_on_a_batch_that_cannot_be_deleted_without_extra_queries`
  (or add a sibling) to assert the **total** `/batches` query count is the
  same at 2 and at 4 cheese batches, where at least one batch item has no
  allocations and no conversions (the null case). If something *else* still
  grows with the batch count, don't paper over it — report the queries and
  set the assertion to what you can justify, with the reason.
- Also GET `/cheese-cutting` and `/cheese-conversion` in the same test with
  the same before/after and assert they don't grow either (they eager-load
  the same sums).

Check: `php artisan test --filter="BatchCreateTest|CheeseCutFreeStockTest|ConsolidationPassTest"`
pass; the report quotes the before/after query counts.

### 4. A saved sheet row lands under its headers (cycle-5 note)

Files: `resources/views/chilled-runs/partials/_run-table.blade.php`,
`resources/css/app.css`

What:
- The wrapper becomes `class="overflow-x-auto lg:overflow-visible"`, so at
  `lg` and up the table is not inside a scroll container and sticky works;
  below `lg` it still scrolls sideways and the header simply scrolls away.
- `.mf-run thead th { position: sticky; top: 0; z-index: 2; }` (the existing
  `background: var(--bg)` and the `.col-*` tints keep the body from showing
  through). The sticky flash region is `z-index: 30`, so a visible flash
  sits above the header; once dismissed, `top: 0` is exactly right.
- Give the sheet's `<tr id="stop-…">`s a larger `scroll-margin-top` to clear
  the header row — measure the header height in the browser (it is two rows:
  group row + column row) and set `scroll-mt-[Npx]` accordingly (or a
  `.mf-run tbody tr { scroll-margin-top: … }` rule).

Check: `npm run build`; browser at 1408 px: edit a row far down the sheet,
Save → the row lands **below** the column headers, which stay visible while
scrolling; dismiss the flash → the header is flush at the top. At 500 px the
sheet still scrolls sideways. No test (CSS).

### 5. The last two `/orders` cells don't wrap (cycle-5 note)

Files: `resources/views/orders/index.blade.php`

What: `whitespace-nowrap` on the Payment `<td>` and the actions `<td>`.

Check: browser at 500 px — "Paid · inv" and "View · Edit" each on one line.

### 6. Delete the unused Breeze logo

Files: `resources/views/components/application-logo.blade.php` (delete)

What: `grep -rn "application-logo" resources app tests` first and paste it
(expect only the file itself); then delete the file.

Check: `php artisan view:cache && php artisan view:clear` clean;
`php artisan test --filter="AuthenticationTest|ErrorPagesTest"` pass.

### 7. Docs

Files: `CLAUDE.md`, `docs/planImp/WORK_IN_PROGRESS.md`

What:
- `CLAUDE.md` → Order Allocation: `OrderItem::allocateFromBatchItem()` widens
  an existing `(order_item, batch_item)` row via `extendAllocation()` — the
  table is unique per pair, so a second allocation from the same batch is a
  top-up, not a new row.
- Mature Conversion → the "eight free-stock sites" sentence: state plainly
  that `CheeseConversionController::index()` was the one still gating on
  `fulfilled_at` until 2026-10-04 and that **no site may** (the grep in step
  2 is the check).
- Wheel & Vac-Pack Visualization: `wheelStates()` reads the eager-loaded
  sums by `array_key_exists`, because `withSum` returns null for an empty
  set — a `??` would re-query.
- Chilled Run Sheet → Views/CSS: the header is sticky at `lg+`; below that
  the wrapper scrolls.
- `WORK_IN_PROGRESS.md`: *Recently Completed: Loose ends (2026-10-04)*.

Check: `grep -n "extendAllocation\|array_key_exists\|lg:overflow-visible" CLAUDE.md` → hits.

## Verification

1. `php artisan test` → **0 failed**; record the count.
2. `./vendor/bin/pint --test app tests database routes config` → clean.
3. `npm run build` → ok.
4. `grep -rn "whereNull('fulfilled_at')" app` → only the `BatchItem.php` docblock.
5. `grep -rn "application-logo" resources app tests` → nothing.
6. `git status --short` → only files this plan names (+ the staged archive).
7. Browser checks for steps 1, 4, 5 with real input; console clean.
8. Dev-DB probe: step 1's browser check may create reservations — use an
   existing confirmed/preparing E2E order (14 is `preparing` with 12 reserved)
   or a tagged probe you cancel; nothing else moves.

## Risks

- **Nested transactions in step 1**: `extendAllocation()` wraps its own
  `DB::transaction`; inside the outer one it becomes a savepoint. The outer
  `lockForUpdate` on the batch item is held by the same connection, so the
  inner re-lock doesn't deadlock. If MySQL complains, call the extension
  logic without the inner transaction (extract a `widenLockedAllocation()`
  that assumes the lock is held, and have both callers use it).
- **Step 2 may change what the allocator shows on dev**: any card whose
  reservations were partly picked will show fewer holdable wheels. That's
  the correct number; note any card that changes.
- **Step 3's total-count assertion** can expose another N+1; the plan says
  report it, don't hide it.
- **Sticky header at `lg+`** relies on the table fitting; if a run has many
  cheese columns the table may overflow its panel without a scroll container
  at `lg`. Check with the widest dev run (17 columns) and say what you saw;
  if it overflows, use `xl:` instead.

## Review

(Planner fills this in after reading implemented.md and the diff.)
