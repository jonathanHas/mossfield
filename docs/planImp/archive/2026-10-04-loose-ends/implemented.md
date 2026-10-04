# Loose ends: an allocate crash, a gated reservation sum, an N+1, and three small things — implementation

Status: DONE
Plan revision: 1
Implementer: Opus
Date: 2026-10-04

## Baseline
HEAD: a3d254b (the plan's Context says `292cbfd` + a staged archive; that archive has since been committed as `a3d254b`)
Pre-existing dirty files: clean

## Steps

### 1. Topping up a reservation from the same batch widens the row — done
Changed:

`app/Models/OrderItem.php`
- In `allocateFromBatchItem()`, after the batch-item lock, an existing
  `(order_item, batch_item)` row is looked up with `lockForUpdate()`. If one
  is found, the call delegates to
  `extendAllocation($existing, $lockedBatchItem, $quantity)` and returns
  `$existing->fresh()`, or `null` on refusal.
- Otherwise the old insert path runs unchanged. The comment names the unique
  index.
- No nested-transaction problem: `extendAllocation()`'s inner transaction
  becomes a savepoint and its re-lock is on the same connection.

`tests/Feature/OrderAllocationConcurrencyTest.php`
- The cycle-5 case (`test_asking_for_more_than_the_line_needs_…`) now tops up
  from the **same** batch:
  - 6 then 5 is refused with "only needs 4 more", with one row of 6.
  - 6 then 4 gives "Allocated 4 units from batch …" and one row of 10.
- +3 new:
  - A top-up beyond the batch's free stock (6 of 10, then +5) is refused with
    "Only 4 units are available …" and the row stays 6.
  - A partly picked row (6, 2 picked, +4) ends at 10 allocated, 2 fulfilled,
    `fulfilled_at` null.
  - A fully picked row (6, 6 picked, which stamps `fulfilled_at`, then +4)
    ends at 10 allocated, 6 fulfilled, **`fulfilled_at` cleared**. See
    Deviations.
Check output:
```
$ php artisan test --filter="OrderAllocationConcurrencyTest|OrderShortSupplyTest|PickingTest"
  Tests:    57 passed (222 assertions)
```
Red check (HEAD's `OrderItem.php` with the new tests): 3 of 9 fail — the
same-batch top-up and both partly/fully picked cases. Restored: 9 pass.
Browser: see *Browser checks*.

### 2. The conversion index stops gating on `fulfilled_at` — done
Changed: `app/Http/Controllers/CheeseConversionController.php` — the
`withSum` is now `->withSum('orderAllocations as quantity_currently_allocated',
DB::raw('quantity_allocated - quantity_fulfilled'))`, the same shape as
`CheeseCuttingController::index()`. It carries the cutting controller's
"never gated on fulfilled_at" comment plus "the last site still filtering on
it, until 2026-10-04".

`tests/Feature/CheeseConversionTest.php` +1
(`test_the_allocator_never_gates_the_reservation_on_fulfilled_at`):
- Setup: 5 wheels with an allocation of 3, of which 1 is picked
  (`fulfilled_at` stamped, as the cutting template does); remaining 4.
- The page renders `matureCard(2, 0)`, not `matureCard(4, 0)`.
- `holdable_quantity` is 2, and POST hold 3 is refused (`quantity` error;
  hold stays 0).
Check output:
```
$ php artisan test --filter="CheeseConversionTest|CheeseCutFreeStockTest"
  Tests:    31 passed (141 assertions)
$ grep -rn "whereNull('fulfilled_at')" app
app/Models/BatchItem.php:311:     * `whereNull('fulfilled_at')` filter: a fully-fulfilled allocation
```
(The one hit is the docblock explaining why the filter was removed; no code
hit.)

Red check (HEAD's controller with the new test): it fails with "To contain:
matureCard(2, 0)". Restored: it passes.

### 3. `wheelStates()` trusts a loaded null — done
Changed:

`app/Models/BatchItem.php`
- `wheelStates()` decides "eager-loaded?" with
  `array_key_exists($key, $this->getAttributes())` for
  `source_cutting_logs_count`, `converted_wheels` and
  `quantity_currently_allocated`. A loaded `null` casts to 0.
- It only queries when the key is absent; the relation-loaded fallbacks are
  kept. The comment says why (`withSum` → NULL on an empty set).

`tests/Feature/BatchCreateTest.php`
- +1 `test_the_batch_screens_cost_the_same_queries_at_any_size`: cheese
  batches including the NULL case (no allocations, no conversions) plus a
  reserved one, measured at 2 then 4 batches. It asserts the **total** query
  counts of `/batches`, `/cheese-cutting` and `/cheese-conversion` are equal.
- The existing test's comment now points to this one.

Query counts (`[/batches, /cheese-cutting, /cheese-conversion]`, 2 → 4 batches):

| Code | 2 batches | 4 batches |
|---|---|---|
| HEAD `BatchItem.php` (red check) | `[12, 10, 5]` | `[18, 16, 5]` — fails |
| this change | **`[6, 4, 5]`** | **`[6, 4, 5]`** — flat |

Nothing else grows with the batch count. `/cheese-conversion` was already
flat, because it doesn't call `wheelStates()`.
Check output:
```
$ php artisan test --filter="BatchCreateTest|CheeseCutFreeStockTest|ConsolidationPassTest"
  Tests:    30 passed (172 assertions)
```

### 4. A saved sheet row lands under its headers — done (at `xl+`, display mode; see Deviations)
Changed: `resources/views/chilled-runs/partials/_run-table.blade.php`
- In display mode, the panel and the table wrapper get `xl:overflow-visible`
  (via `$stickyHead`). `.mf-panel` itself is `overflow: hidden`, which also
  traps sticky, so it needs lifting as well as the wrapper.
- While a row is being edited (`$editId`) both keep the old scroll behaviour.

`resources/css/app.css`
- `.mf-run` uses `border-collapse: separate; border-spacing: 0` (was
  `collapse`).
- `.mf-run thead { position: sticky; top: var(--mf-feedback-h, 0px);
  z-index: 2 }`.
- `.mf-run tbody tr[id^="stop-"] { scroll-margin-top: calc(var(--mf-feedback-h,
  0px) + 80px) }`. The header is 28 + 46 = 74 px, measured.

`resources/views/layouts/partials/feedback.blade.php`
- An inline script right after the region sets `--mf-feedback-h` to its
  height while the page parses, before the browser scrolls to the fragment.
- The region's Alpine `x-effect` sets it to `0px` once every message is
  dismissed.

What the browser showed, in order (1408 px unless stated):

1. **Plan as written (`lg:`, sticky `<th>`).** At 1028 px (`lg`) the table
   (782 px) overflowed the panel (733 px) and the page scrolled sideways, so I
   switched to **`xl:`**, as the plan's Risks allow. At 1280 px the table fits
   (983 in 985).
2. The stuck `<th>` sat **under the sticky flash** (both at `top: 0`; flash
   z-index 30), and the flash is always showing right after a save. So the
   header was invisible exactly when the row landed. Hence `--mf-feedback-h`.
3. With `border-collapse: collapse`, Chrome painted only part of a stuck
   header cell. Row text (addresses) showed through the empty group-row cells
   in the customer and notes columns, even though `elementsFromPoint` put the
   `<th>` on top with an opaque background. Making `<thead>` sticky instead of
   each `<th>` didn't fix it; `border-collapse: separate; border-spacing: 0`
   did. Cells only draw top/bottom borders, so the sheet looks the same; I
   checked the footer rows too.
4. **Edit mode** widens the table about 35 px (46 px inputs plus padding in
   60 px columns, and the Save/Cancel/+Ref buttons). Without a scroll
   container that spilled out of the panel, so the wrapper stays a scroll
   container while editing (see Deviations).

Final checks:
- Avoca Monkstown (row 6), Save with nothing entered ("Nothing to save — no
  quantities entered." — **no data change**): `#stop-19`; flash bottom 70;
  `<thead>` at **70–144**; the row lands at **150**, just under the headers ✓.
- Scrolling down 5 ticks: the header stays at 70–144 ✓.
- × on the flash: `--mf-feedback-h` goes to `0px` and the header rows move to
  **0 / 28** (flush at the top) ✓.
- No page-level horizontal overflow (`scrollWidth 1393 = clientWidth`), in
  display and edit mode.
- 500 px: the wrapper scrolls sideways (782 inside 435) with no page overflow ✓.
- 1028 px (below `xl`): the wrapper scrolls (782 inside 731) with no page
  overflow.
- Console clean. No test (CSS), per the plan.
```
$ npm run build
✓ built in 3.48s
```

### 5. The last two `/orders` cells don't wrap — done
Changed: `resources/views/orders/index.blade.php` — `whitespace-nowrap` on the
Payment `<td>` and the actions `<td>`.

Browser at 500 px, scrolled right:
- "Paid" tag (top 444, bottom 465) and "inv" link (top 447, bottom 461) sit
  on one line.
- "View · Edit" is one line on every row checked ✓.

### 6. Delete the unused Breeze logo — done
Changed: `resources/views/components/application-logo.blade.php` deleted.
```
$ grep -rn "application-logo" resources app tests
(no output — exit 1; grep matches contents, and nothing references it)
$ php artisan view:cache
   INFO  Blade templates cached successfully.
$ php artisan view:clear
   INFO  Compiled views cleared successfully.
$ php artisan test --filter="AuthenticationTest|ErrorPagesTest"
  Tests:    14 passed (48 assertions)
```

### 7. Docs — done
Changed:

`CLAUDE.md`
- **Order Allocation**: the `allocate()` bullet says `allocateFromBatchItem()`
  widens an existing pair via `extendAllocation()` — a second allocation is a
  top-up, not a new row.
- **Mature Conversion**, before "the same expression must appear in all
  eight free-stock sites": no site may filter on `fulfilled_at`;
  `CheeseConversionController::index()` was the last until 2026-10-04; the
  grep is the check.
- **Wheel & Vac-Pack Visualization**: `wheelStates()` reads the eager-loaded
  sums by `array_key_exists`, because `withSum` gives NULL for an empty set.
- **Chilled Run Sheet**: a *Sticky header* bullet before Views/CSS covers
  `xl+` display mode, `--mf-feedback-h`, the edit-mode and below-`xl`
  scrolling, and `border-collapse: separate`.

`docs/planImp/WORK_IN_PROGRESS.md`
- New "Recently Completed: Loose ends (2026-10-04)" entry.
Check output:
```
$ grep -n "extendAllocation\|array_key_exists\|lg:overflow-visible" CLAUDE.md
404:  - **Maturing hold**: … reads the eager-loaded … by `array_key_exists` …
561:- `allocate()` - reserve stock from a batch item — `OrderItem::allocateFromBatchItem()` widens … via `extendAllocation()` …
597:- **`OrderItem::pickFromBatchItem(…)`** … `extendAllocation()` … (pre-existing)
```
`lg:overflow-visible` has no hit because the breakpoint is `xl:` (Deviation
2); line 624 holds the `xl:overflow-visible` sticky-header bullet.

## Browser checks
`php artisan serve --host=127.0.0.1:8000`, a fresh `npm run build`, real mouse
and keyboard, office user. The tab came up hidden once and Jon brought it
forward. No console errors on any page.

### Step 1 — top-up from the same batch
- I made a confirmed probe order in tinker: **ORD-20261004-014** (id 25), E2E
  Corner Shop, 1L × 10, notes "E2E-20261004 cycle6 allocate probe (to
  cancel)".
- On `/orders/25`, Choose batch → M041026 (keyboard), qty 6, Allocate →
  "Allocated 6 units from batch M041026." (`#line-65`).
- Then M041026 again, qty 4 (prefilled), Allocate → "**Allocated 4 units
  from batch M041026.**" The line shows **one** allocation row: M041026,
  10 allocated, 0 fulfilled, "10 allocated · 0 fulfilled" ✓. (Before this
  cycle the second click was a 500.)
- Then **Cancel order** → "Order cancelled. Reserved and picked stock has
  been returned to its batch." The order has 0 allocation rows.

### Steps 4 and 5
See their sections above (1408 / 1280 / 1028 / 500 px).

## Deviations
1. **Baseline HEAD is `a3d254b`, not `292cbfd` + staged archive** — the
   archive was committed before this cycle. Tree clean.
2. **Step 4 breakpoint is `xl:`, not `lg:`.** At 1028 px the 11-column dev
   sheet (782 px) overflowed its 733 px panel and scrolled the page
   sideways. The plan's Risks say to use `xl:` then.
3. **Step 4 also lifts `.mf-panel`'s `overflow: hidden`** (at `xl+`, on this
   panel only). The plan named only the wrapper, but `.mf-panel` clips with
   `overflow: hidden`, which also stops `position: sticky` working.
4. **Step 4 keeps the scroll wrapper while a row is being edited.** The edit
   row needs about 35 px more than the sheet's columns. Without a scroll
   container it spilled past the panel and scrolled the page by ~9 px. The
   plan's own flow (Save → the page reloads in display mode → the row lands
   under the header) is unaffected.
5. **Step 4: `<thead>` is sticky (not each `<th>`) and the table is
   `border-collapse: separate; border-spacing: 0`.** With `collapse`, Chrome
   painted the stuck header cells only partly and body text showed through,
   even though the `<th>` was the top element with an opaque background.
   Diagnosed with a red-background probe and `elementsFromPoint`.
   `separate` + 0 spacing fixed it, and the borders look the same (cells only
   draw top/bottom borders).
6. **Step 4: the header sits under the sticky flash
   (`--mf-feedback-h`)**, which meant touching
   `layouts/partials/feedback.blade.php` (not a step-4 file). The plan
   expected "a visible flash sits above the header". But a flash is
   *always* showing right after a save, so with `top: 0` the header was
   hidden behind it exactly when the row landed, defeating the step's goal.
   - An inline script measures the region as it parses, before the fragment
     scroll, into `--mf-feedback-h`; dismissing sets it to 0.
   - The sheet's `scroll-margin-top` adds it too.
   - The header then lands flush at the top once the flash is dismissed, as
     the plan's check asks.
7. **Step 1 test: the "partly fulfilled" case doesn't start stamped.**
   `fulfillAllocation()` stamps `fulfilled_at` only when a row is **fully**
   picked (`quantity_fulfilled >= quantity_allocated`), so after a 2-of-6
   pick it was already null. The plan's case (6, 2 picked, +4 → 10/2/null) is
   kept. I added a fully-picked case (6, 6 picked → stamped, +4 → 10/6/null),
   which is the one that proves the top-up clears the stamp. (The plan's
   Context says the stamp is set "on a partial pick (`OrderItem.php:203`)";
   that's not what `fulfillAllocation()` does.)
8. **Step 1's browser probe was created in tinker**, as the plan's
   Verification 8 allows ("a tagged probe you cancel").

## Verification
1. `php artisan test` → **617 passed (3937 assertions), 0 failed** (612 → 617:
   +5 cases — +3 in `OrderAllocationConcurrencyTest` (one case rewritten),
   +1 `CheeseConversionTest`, +1 `BatchCreateTest`).
2. `./vendor/bin/pint --test app tests database routes config` → `PASS … 277 files`.
3. `npm run build` → `✓ built in 3.90s`.
4. `grep -rn "whereNull('fulfilled_at')" app` →
   `app/Models/BatchItem.php:320:     * \`whereNull('fulfilled_at')\` filter: …`
   (docblock only).
5. `grep -rn "application-logo" resources app tests` → nothing (exit 1).
6. `git status --short` → only files this plan names, plus
   `layouts/partials/feedback.blade.php` (Deviation 6). The cycle-5 archive
   was already committed.
7. Browser checks for steps 1, 4 and 5 with real input, console clean (above).
8. Dev-DB probe:
   - Orders 23 → **24**: the probe ORD-20261004-014, **cancelled**, 0
     allocation rows.
   - Allocations 33 → 33.
   - Payments 4 and invoices 7, unchanged.
   - The sheet saves in step 4 were empty saves ("Nothing to save"), so no
     change.
   - Step 2 changes what the allocator *shows* for any dev card with
     partly picked reservations. The dev data has none with a stamped
     partial reservation (fulfilled rows are fully picked), so no card
     changed on dev.

## Files changed
```
 M CLAUDE.md
 M app/Http/Controllers/CheeseConversionController.php
 M app/Models/BatchItem.php
 M app/Models/OrderItem.php
 M docs/planImp/WORK_IN_PROGRESS.md
 M resources/css/app.css
 M resources/views/chilled-runs/partials/_run-table.blade.php
 D resources/views/components/application-logo.blade.php
 M resources/views/layouts/partials/feedback.blade.php     (Deviation 6)
 M resources/views/orders/index.blade.php
 M tests/Feature/BatchCreateTest.php
 M tests/Feature/CheeseConversionTest.php
 M tests/Feature/OrderAllocationConcurrencyTest.php
?? docs/planImp/implemented.md
```
No pre-existing dirty files. Nothing committed.

## Notes for Planner
- **The `xl` fit is tight.** The dev run (11 columns, 2 cheese columns) is
  983 px wide inside a 985 px panel at 1280. A run with a third or fourth
  cheese column will overflow at exactly 1280 and push the page sideways in
  display mode (each cheese column is 60 px). Options:
  - a `2xl:` breakpoint;
  - shrinking the 300 px Notes column at `xl`;
  - setting `$stickyHead` from `$qtyColCount` (e.g. only when ≤ 9 quantity
    columns).
  Worth deciding before more cheeses are seeded.
- **`--mf-feedback-h` is page-global.** Other sticky elements (none yet)
  could use it. The order-page lines still use plain `scroll-mt-20`
  (80 px), which already clears a one-line flash. A two-line flash would
  cover a little of the landed line; switching `.scroll-mt-20` to
  `calc(var(--mf-feedback-h) + 1rem)` would make that exact.
- **The plan's Context on `fulfilled_at`** (Deviation 7) is worth a one-line
  correction in `TESTING_AND_FIXES.md`. `fulfillAllocation()` stamps only
  full picks, so the free-stock reasoning still holds: the sum must not be
  gated, because a row can be stamped and then **widened** (exactly what step
  1 now does), and before this change a widened row kept its stale stamp
  until `extendAllocation()` cleared it.
