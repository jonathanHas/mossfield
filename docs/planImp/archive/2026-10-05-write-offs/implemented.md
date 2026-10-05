# Stock write-offs: a fifth state for stock that is lost — implementation

Status: DONE
Plan revision: 1
Implementer: Opus
Date: 2026-10-05

## Baseline
HEAD: bcfcb95 (the plan's Context says `742674c` + a staged archive; that archive has since been committed as `bcfcb95`)
Pre-existing dirty files: clean

## Steps

### 1. Schema and models — done
Changed:

New migrations
- **`2026_10_04_130000_create_write_off_reasons_table.php`**: columns `code`
  (40, unique), `label` (80), `is_active`, `sort_order`; inserts the seven
  defaults via `insertOrIgnore` keyed on `code`, with sort orders 10…70.
- **`2026_10_04_130100_create_stock_write_offs_table.php`**: columns as
  planned, with these FKs and index:
  - `batch_item_id` cascades;
  - `write_off_reason_id` is `restrictOnDelete`;
  - `written_off_by` / `reversed_by` are `nullOnDelete`;
  - index on `(batch_item_id, reversed_at)`.

New models
- **`app/Models/WriteOffReason.php`**: scopes `active()` and `ordered()`
  (the second is extra, for the selects), `writeOffs()`, `isInUse()`.
- **`app/Models/StockWriteOff.php`**: casts as planned; `batchItem()`,
  `reason()`, `writtenOffBy()`, `reversedBy()`; `scopeActive()`, with a
  comment that the `reversed_at` filter is legitimate; `isReversed()`.

Existing models
- `app/Models/BatchItem.php`:
  - `writeOffs()`.
  - `getWrittenOffUnitsAttribute()` reads the eager-loaded
    `written_off_units` by `array_key_exists`, then a loaded relation, then
    one active-sum query.
  - `getQuantitySoldAttribute()` is `max(0, produced − remaining −
    written_off_units)`.
- `app/Models/Batch.php`: `writeOffs()` hasManyThrough batch_items.
- `app/Models/User.php`: `stockWriteOffs()`.
Check output:
```
$ php artisan migrate
  2026_10_04_130000_create_write_off_reasons_table ............. 150.70ms DONE
  2026_10_04_130100_create_stock_write_offs_table .............. 471.50ms DONE
$ php artisan tinker --execute='echo App\Models\WriteOffReason::count();'
7
$ php artisan migrate:rollback --step=2 && php artisan migrate
  … DONE (both) — round-trips; count 7 again:
  spoiled,damaged,sample,own_use,stocktake,expired,returned
```

### 2. Recording and reversing — the service — done
Changed:

**new** `app/Services/StockException.php`
- A flash-message refusal, same pattern as `InvoicingException`.

**new** `app/Services/StockWriteOffService.php`
- `record()`, in one transaction with `lockForUpdate()` on the item:
  - refuses `quantity < 1`, an inactive reason, and
    `quantity > holdable_quantity`. That message is exactly "Only N units
    can be written off — M are reserved for orders. Release them first.",
    or "… — that's all that's left." when nothing is reserved;
  - refuses `weight_kg` on a non-variable-weight variant, and anything over
    3 × nominal × quantity ("… Check the decimal point.");
  - refuses a date before production or after today;
  - takes `quantity_remaining −= quantity`, then clamps `quantity_maturing`
    to `max(0, remaining − still owed)`;
  - creates the row. When the clamp bit, it sets a non-persisted
    `$writeOff->holdReducedTo` (declared on `StockWriteOff`) for the flash.
- `reverse()`:
  - refuses an already-reversed row, and a reversal that would exceed
    `quantity_produced`;
  - takes `increment('quantity_remaining', qty)` and stamps
    `reversed_at`/`by`;
  - does not restore a hold.
- `writeOffExpired()`:
  - refuses a batch that isn't expired, and a missing or inactive `expired`
    reason;
  - writes off each item's `holdable_quantity` (reserved units stay) in one
    transaction;
  - refuses when nothing is writable.

**new** `tests/Feature/StockWriteOffTest.php`, 10 cases with fixtures in its
own `setUp`:
- the seven defaults;
- **the five free-stock sites** (`available_quantity`,
  `freeStockByVariant()`, `/stock` available segment, `/cheese-cutting`
  "Wheels left", `/picking` batch chooser `max`), one assertion each;
- over-reserved refused with the exact message;
- held wheels clamp the hold (`holdReducedTo` = 2);
- weight refused on milk, plus the 3× bound;
- inactive reason refused;
- both date bounds;
- reverse restores and stamps, and a double reverse is refused;
- `writeOffExpired()` takes every item, keeps the reserved units and
  refuses a fresh batch;
- `quantity_sold` excludes write-offs.
Check output:
```
$ php artisan test --filter=StockWriteOffTest
  Tests:    10 passed (34 assertions)
```

### 3. The fifth state in the weight model and the wheel strip — done
Changed:

`app/Models/BatchItem.php`
- `wheelStates()`: `written_off` comes from `written_off_units` (eager sum,
  loaded relation or one query).
  - `sold = max(0, produced − remaining − cut − converted − written_off)`.
  - `remaining = max(0, produced − cut − converted − sold − written_off)`.
  - `written_off` is returned in the array; docblock updated.
- `weight_breakdown`:
  - new key `written_off` for every kind;
  - wheels: each **active** write-off in the **loaded** `writeOffs`
    relation adds its quantity, to `weighed`/`measured_kg` when weighed,
    else to the nominal units;
  - `reconciles` has the fifth term;
  - packs/milk count `written_off` but `nominal_kg` is unchanged;
  - an **unloaded** relation reads as none, with no query (the plan's Risk).
    The docblock is updated to five states.

Eager-loads
- `BatchController::index()`, `CheeseCuttingController::index()` and
  `StockOverviewService::build()` add
  `withSum(['writeOffs as written_off_units' => whereNull('reversed_at')], 'quantity')`,
  with a comment saying the filter is legitimate.
- `BatchController::show()` adds `batchItems.writeOffs.reason`,
  `.writtenOffBy` and `.reversedBy`.

`app/Services/StockOverviewService.php`
- Simple cards, wheel rows and pack rows subtract `written_off` from `sold`
  and add a `written_off` segment (after `converted` on wheel rows).
- `buildCheeseCard`'s tag doesn't sum `sold`, so it's unchanged.

Token and labels
- `resources/css/stock.css`: `--state-written-off: var(--danger)`, with
  `--state-damaged` kept as an alias.
- **One key → token/label mapping** in
  `components/stock/{cheese-row,case-blocks,case-pictograph}.blade.php`:
  - `$token = fn ($s) => '--state-'.str_replace('_', '-', $s)`;
  - the label is `str_replace('_', ' ', …)`;
  - the state order gains `written_off` (case components: replacing the
    unused `damaged`), and so do the summary lists.

`batches/partials/_wheel-strip.blade.php`
- Red circles titled "Written off", after converted; "N written off" in the
  summary (dropped when 0).

`batches/partials/_wheel-legend.blade.php`
- A written-off total, shown when > 0.

`resources/views/batches/index.blade.php` + `cheese-cutting/index.blade.php`
- The `array_fill_keys([...])` totals gain `written_off`. These files aren't
  named by the plan; see Deviations.

Tests
- `BatchFinishedWeightTest` +3:
  - 10 wheels = 4 on hand + 2 dispatched + 1 cut + 1 converted + 2 written
    off (one at 4.900 kg, one unweighed; a third written off then reversed
    doesn't count). `reconciles`; weighed 4; measured 20.02; nominal 30.0;
    `finished_product_weight` 50.02; coverage weighed 4.
  - A milk write-off: `written_off` 10, `on_hand` 190, `nominal_kg` 200.
  - An unloaded relation costs 0 queries and reads 0.
  - `loaded()` gains `batchItems.writeOffs`.
- `StockOverviewTest` +1: segments available 25, written_off 3, sold 4;
  `/stock` prints "written off" and `var(--state-written-off)`.
- `StockWriteOffTest` +1: the `/batches` card says "2 written off" and "4
  free", with exactly two `background: var(--state-written-off);` circles.
- `BatchCreateTest::test_the_batch_screens_cost_the_same_queries_at_any_size`:
  the fixture gains a write-off, and the counts stay flat.
Check output:
```
$ php artisan test --filter="BatchFinishedWeightTest|StockOverviewTest|StockWriteOffTest|BatchCreateTest|ConsolidationPassTest"
  Tests:    51 passed (300 assertions)
$ npm run build
✓ built in 5.02s
```
(Full suite at this point: 631 passed, 1 failed —
`ResetTradingDataTest::every table in the schema is classified`, which
step 7 classifies.)

### 4. Routes, policies, controller — done
Changed:

Policies
- **new** `app/Policies/StockWriteOffPolicy.php` and
  `WriteOffReasonPolicy.php`, both bare `BasePolicy`.
- Both registered in `app/Providers/AuthServiceProvider.php`.

`routes/web.php` (office group)
- `batches.write-offs.store`, `batches.write-offs.expired`,
  `write-offs.reverse`.

**new** `app/Http/Requests/StockWriteOffRequest.php`
- `authorize()` = `can('create', StockWriteOff::class)`.
- Rules as planned: a closure rule "That item belongs to another batch.",
  and the reason `Rule::exists(...)->where('is_active', true)` with the
  message "Choose a reason that is still in use.".

**new** `app/Http/Controllers/StockWriteOffController.php`
- `store()`: a service refusal redirects to `batches.show#write-offs`
  `withErrors(['write_off' => …])->withInput()`. Success flashes "Written
  off N × {variant} — {reason}.", plus " The maturing hold was reduced to N."
  when the clamp bit.
- `storeExpired()`: `authorize('create')`; the default note is "Expired —
  written off from the batch page". The flash lists what was written off
  per item **and** "Kept (reserved for orders): …".
- `reverse()`: `authorize('update', $writeOff)`; flash "Write-off reversed —
  N units returned to free stock.".

`app/Services/StockWriteOffService.php`
- `writeOffExpired()` returns an Eloquent collection; the controller
  `load()`s on it.

`tests/Feature/AuthorizationBoundaryTest.php`
- `buildFixtures()` gains a `StockWriteOff` row and the `spoiled` reason;
  `params` gains `writeOff` and `write_off_reason` (the step 5 resource
  parameter).

`tests/Feature/StockWriteOffTest.php` +5:
- office store, then reverse, through the routes, with exact flashes and
  `#write-offs` redirects;
- a service refusal returns the exact message under `write_off`;
- an item from another batch gives a **422** on a JSON post (validation; a
  form post redirects with the same error);
- the expired route writes off 7 and keeps 3 reserved, with the exact flash
  and note;
- factory and driver get 403 on all three routes and nothing changes.
Check output:
```
$ php artisan test --filter="StockWriteOffTest|AuthorizationBoundaryTest"
  Tests:    37 passed (1470 assertions)
```

### 5. Reasons are manageable — done
Changed:

**new** `app/Http/Controllers/WriteOffReasonController.php`
- `index`: ordered, with `withCount('writeOffs')`.
- `store`: label required, max 80, unique. The code is
  `Str::slug(label, '_')`, made unique with `_2`, `_3`…; `sort_order` is max
  + 10.
- `update`: rename (label, unique except self) and/or `is_active`; the
  flash says which.
- **No destroy** — a used reason is deactivated. The FK
  `restrictOnDelete` is the backstop.

**new** `resources/views/write-off-reasons/index.blade.php`
- A table of label (an inline rename form), code, used count, status tag and
  Deactivate/Reactivate, plus an "Add reason" form.
- Forms are gated `@can`.

`routes/web.php`
- `Route::resource('write-off-reasons', …)->only(['index','store','update'])`
  in the office group.

`resources/views/layouts/navigation.blade.php`
- **Miscellaneous** group, "Write-off reasons" (alert icon), for office and
  admin. Not the Admin group: that is admin-only, and office needs it.

`tests/Feature/StockWriteOffTest.php` +2:
- Add gives code `broken_seal`, active, sort 80; a duplicate is refused;
  rename keeps the code; deactivate. The deactivated reason is refused by
  the request ("Choose a reason that is still in use.") **and** the
  service; then reactivate.
- Factory 403 on index, store and update; there is no `destroy` route.
- The deactivated reason being absent from the batch-page select is pinned
  in step 6.
Check output:
```
$ php artisan test --filter="StockWriteOffTest|AuthorizationBoundaryTest"
  Tests:    39 passed (39 cases incl. the matrix; 1520 assertions)
```

### 6. The batch page: record, see, reverse; the expired one-click — done
Changed:

**new** `resources/views/batches/partials/_write-offs.blade.php`
- Panel `id="write-offs"` (`scroll-mt-20`), included after the cutting
  history, before Actions.
- Form, gated `@can('create', StockWriteOff::class)`:
  - an item select listing items with produced > 0, each with its on-hand
    count; a single item shows as text with a hidden input instead;
  - quantity;
  - weight, shown only for a variable-weight item (Alpine), labelled
    "Measured weight of these N units (kg)", optional, disabled when hidden
    so it never posts;
  - date (default today, `max` today);
  - a reason select of **active** reasons, with a "Manage reasons" link;
  - note; Write off.
  - The service's refusal shows under the form via `@error('write_off')`.
- History, newest first: date · item · qty · kg · reason · note · by ·
  Reverse.
  - Reverse uses `data-confirm="Reverse this write-off? N units go back into
    free stock."`, `data-confirm-label="Reverse"`.
  - A reversed row is muted and struck through, with "reversed {date} by
    {name}".
- Factory sees the history only.

`resources/views/batches/show.blade.php`
- **Expired one-click** in the header, when `isExpired()` and
  `writableUnits() > 0`, for create-capable users: "Write off expired stock
  (N units)", danger, with a `data-confirm` that names the units and says
  reserved stock stays.
- A **Written off** info row ("N units · X kg measured", linking to
  `#write-offs`) when > 0.
- The reconciliation warning's title has the fifth term.

`app/Models/Batch.php`
- `writtenOffSummary()` reads loaded relations only.
- `writableUnits()` = Σ holdable, using the index's eager-loaded
  `quantity_currently_allocated` when present, so a card costs no query.
- `deletionBlocker()` / `dependentCounts()` count `write_offs_count`
  (reversed rows too — history), with a "N write-off record(s)" reason.

`app/Http/Controllers/BatchController.php`
- `index()`: `withCount` gains `writeOffs`.
- `show()`: passes `$writeOffReasons` (active, ordered; empty for
  read-only users) and `$writeOffs` (flattened, newest first).

`resources/views/batches/partials/batch-card.blade.php`
- An expired card's "Expired" tag is followed by "Write off →"
  (`…/batches/{id}#write-offs`) when `writableUnits() > 0`, for
  create-capable users.

`resources/views/dashboard.blade.php`
- The tile sub-line reads "N units · write off".
- `tests/Feature/DashboardTest.php`: its assertion follows the new text
  (Deviations).

`tests/Feature/StockWriteOffTest.php` +4:
- Panel for office: form, active reasons only (a deactivated "Sample /
  tasting" is not offered — pins step 5), weight label, history row,
  `4.900`, Reverse, Manage reasons. Factory: history, no form, no Reverse.
- A POST shows "Written off · 3 units" and the reverse confirm; after
  reverse, "reversed {date} by {name}" and `line-through`.
- The expired button appears only for an expired batch with stock, not for
  fresh, empty-expired or factory, and is gone once written off.
- The card link appears; a reversed write-off still blocks deletion ("1
  write-off record"), and admin DELETE is refused.
Check output:
```
$ php artisan test --filter="StockWriteOffTest|BatchCreateTest|DashboardTest"
  Tests:    37 passed (211 assertions)
```
Browser: see *Browser checks*.

### 7. Housekeeping the suite insists on — done (plus older-backup tolerance; see Deviations)
Changed:

`app/Console/Commands/ResetTradingData.php`
- `WIPE_ORDER` gains `stock_write_offs`, after the log tables and before
  `order_items`/`batch_items`.
- `KEEP` gains `write_off_reasons`.

`app/Services/BackupService.php`
- `TABLES`: `write_off_reasons` after `users`; `stock_write_offs` after
  `batch_items`.
- **New `ADDED_LATER`**: a backup made before these tables existed still
  restores. A missing `stock_write_offs` restores as empty; a missing
  `write_off_reasons` leaves the current reasons untouched.

`tests/Feature/BackupRestoreTest.php` +2:
- A custom reason plus a write-off (4 units, note) round-trip, and the
  batch item's remaining is restored to 96.
- A payload without the two tables restores: write-offs made since are
  gone, and the 7 reasons remain.
Check output:
```
$ php artisan test --filter="ResetTradingDataTest|BackupRestoreTest"
  Tests:    23 passed (128 assertions)
```

### 8. Docs — done
Changed:

`CLAUDE.md`
- A new **Stock Write-offs** section (before Mature Conversion) covering:
  model and tables (defaults inserted by the migration); the one service
  path; the `holdable_quantity` bound; the maturing clamp; reversal keeping
  the row; active-only aggregates (and why that filter is legitimate); the
  `written_off_units` eager-load key; the five-state reconciliation;
  routes and policies; reasons CRUD and nav; batch page and expired
  one-click; backups and `ADDED_LATER`; out of scope; tests.
- **Finished weight & yield**: five states; written-off units count in the
  weight, not in stock; `writeOffs` joins the eager-load list.
- **Wheel & Vac-Pack Visualization**: the red `written_off` state, the
  token (with `--state-damaged` as an alias), and the one key→token/label
  mapping.
- **Stock Overview**: a *Written off* segment bullet. The "A write-off route
  is OPEN_QUESTIONS #1." sentence (the "losses can't be recorded" line the
  plan meant) is replaced.
- **Resetting Dev Trading Data**: write-off reasons are kept;
  `stock_write_offs` is wiped before `batch_items`.

`README.md`
- A **Stock Write-offs** bullet under Production Management.

`docs/planImp/WORK_IN_PROGRESS.md`
- A new "Recently Completed: Stock write-offs (2026-10-04)" entry.
Check output:
```
$ grep -n "Stock Write-offs\|written_off_units\|writeOffExpired" CLAUDE.md
311:- **Written off**: every card has a `written_off` segment (… `written_off_units` eager-load) …
660:## Stock Write-offs
665:- **The one path**: `App\Services\StockWriteOffService` (`record()` / `reverse()` / `writeOffExpired()` …
668:- **Eager-load key**: `withSum(['writeOffs as written_off_units' => …
```

## Browser checks
`php artisan serve --host=127.0.0.1:8000`, a fresh `npm run build`, real mouse
and keyboard, office user, 1408 px. The tab came up hidden once and Jon
brought it forward. No console errors on any page.

### `/batches/19` (M230726, expired)
- The header shows **"Write off expired stock (951 units)"** (950 × 1L + 1 ×
  10L Churn; the 2L item has 0).
- The dialog: title "Write off expired stock?", "Write off 951 units of
  M230726 as Expired? Anything reserved for orders stays. You can reverse it
  from the Write-offs panel.", button **Write off** ✓.
- Confirm gives "Wrote off expired stock: 950 × 1L Bottle, 1 × 10L
  Churn." and `#write-offs`. The panel lists **one row per item** (date,
  item, qty, reason Expired, note "Expired — written off from the batch
  page", by Office Tester, Reverse).
- Info row: "Written off 951 units". Stock remaining reads "0 × 1L Bottle ·
  0 × 2L Bottle · 0 × 10L Churn". The button is gone ✓.
- `/stock` milk note: "**3** expired batches still hold 3,486 units" (was 4
  holding 4,437) ✓.
- Dashboard tile: "EXPIRED STOCK | **4** | 4,956 units · write off" (was 5
  / 5,907) ✓.
- **Reverse** the 10L Churn row:
  - The dialog read "Reverse this write-off? 1 unit go back…" — grammar
    fixed to "goes", re-checked later on G010426 ✓.
  - Confirm gives "Write-off reversed — 1 unit returned to free stock.";
    the row is struck through with "reversed 05/10/2026 by Office Tester";
    Written off reads 950; the header button is back as "(1 unit)" ✓.
- Clicked it again to leave the expired batch fully written off, as the plan
  asks: "Wrote off expired stock: 1 × 10L Churn.", 951 units, 3 rows
  (one reversed).
- **Found and fixed here**: the variant table's **Sold** column counted
  written-off units as sold (the 10L Churn showed "1 sold"). It now
  subtracts them and gains a **Written off** column (see Deviations). The
  re-check shows "1L Bottle 980 · 0 · 30 sold · 950 written off".

### `/batches/26` (G010426)
- Before: finished weight ~60.31 kg, "3 of 12 wheels weighed",
  `/cheese-cutting` "Wheels left" **6**.
- In the panel: Item Whole Wheel (default); the weight box is shown for the
  weighed item.
  - Typing "4,900" gave `4900`: the box had no comma→point handler (F-29
    again). Added the cycle-3 handler (see Deviations), then typed 4.900.
- Reason Spoiled / mouldy (keyboard), note "E2E-20261005 cycle7 browser check
  (to reverse)", **Write off** → "Written off 1 × Whole Wheel — Spoiled /
  mouldy."; `#write-offs`; row qty 1, kg **4.900**.
- Info row: "Written off 1 unit · 4.900 kg measured".
- Coverage: "**4 of 12** wheels weighed" ✓ (the write-off counts as
  weighed).
- Finished weight **~60.21 kg**: −0.10, because that wheel now counts at its
  measured 4.900 instead of nominal 5.000. That is the model working
  (measured where measured); the plan's "finished weight unchanged" assumed
  nominal = measured. See Deviations.
- `/batches` card strip: "5 free · 1 maturing · 1 cut · 2 converted · **1
  written off** · 2 sold / 12", with **one** red `title="Written off"`
  circle ✓. "Can't delete — 6 records depend on it" (was 5).
- `/cheese-cutting` G010426 "Wheels left" **5** (one fewer) ✓. The hold of
  1 was untouched: 6 remain, all holdable.
- **Reversed it** (dialog "Reverse this write-off? 1 unit goes back into
  free stock."): back to ~60.31 kg, 3 of 12 weighed, no Written off row,
  `/cheese-cutting` 6. The reversed row stays on the record ✓.

### `/write-off-reasons`
- The seven reasons with their use counts (spoiled 1, expired 3), each with
  Rename and Deactivate.
- "Write-off reasons" appears in the nav (desktop and drawer).

## Deviations
1. **Baseline HEAD is `bcfcb95`, not `742674c` + staged archive** — the
   archive was committed before this cycle. Tree clean.
2. **Files the plan doesn't name**, each needed by a step:
   - `resources/views/batches/index.blade.php` and
     `cheese-cutting/index.blade.php`: their `array_fill_keys([...])` totals
     for the variety header needed a `written_off` key (step 3), or
     `+= $v` on an undefined key.
   - `app/Services/StockException.php`: new; the plan offered it as an
     option.
3. **`--state-written-off` mapping**: the plan asked to make key and token
   agree. The PHP key stays `written_off` and the CSS token is
   `--state-written-off`. All three stock components derive the token
   (`_`→`-`) and label (`_`→space) from the key in one place. The case
   components' state order replaces the unused `damaged` with `written_off`
   (`--state-damaged` stays as an alias token).
4. **Batch page variant table** (step 6 file): "Sold" for non-wheels was
   `produced − on hand`, which counted write-offs as sold. Found in the
   browser. It now subtracts `written_off`, and a **Written off** column was
   added. Pinned in `StockWriteOffTest`.
5. **Card pack bar** (`batch-card`, step 6 file): it had the same "sold =
   produced − remaining" problem. It now subtracts write-offs, draws a red
   written-off segment and says "N written off". Pinned in
   `StockWriteOffTest`.
6. **Comma handler on the write-off weight box**, found in the browser.
   Same `execCommand('insertText')` handler as cycle 3; the server's 3×
   bound would have refused 4900 anyway.
7. **Finished weight after a weighed write-off is not "unchanged"** (step 6
   browser check). It moves from nominal to measured for that wheel (−0.10
   kg here), exactly as a dispatched or cut wheel does. Written-off units
   still count; that is the plan's point 6, and the tests pin it.
8. **`BackupService::ADDED_LATER`** (step 7) wasn't asked for. With the new
   tables in `TABLES`, `validatePayload()` would refuse **every existing
   backup** ("missing the 'write_off_reasons' table"), so no
   pre-deploy backup could be restored after deploy. Now a missing
   `stock_write_offs` restores as empty and a missing `write_off_reasons`
   keeps the current rows. Tested.
9. **Nav placement**: Miscellaneous (office and admin), not the Admin group,
   which is admin-only. The plan left this to me ("say which").
10. **`DashboardTest`** assertion text moved from "write off or close" to
    "write off" — the plan changed the tile text.
11. **`WriteOffReason::scopeOrdered()`** was added for the selects and the
    reasons page; not in the plan.
12. **No destroy route for reasons** — the plan offered destroy-if-unused as
    optional; deactivation covers it.

## Verification
1. `php artisan test` → **647 passed (4164 assertions), 0 failed** (617 → 647:
   +30 cases — 26 in the new `StockWriteOffTest`, +3
   `BatchFinishedWeightTest`, +1 `StockOverviewTest`, +2 `BackupRestoreTest`;
   others extended).
2. `./vendor/bin/pint --test app tests database routes config` → `PASS … 289 files`.
3. `npm run build` → `✓ built in 2.45s`.
4. `php artisan migrate:status | tail -3` →
   `2026_10_04_130000_create_write_off_reasons_table … [6] Ran`,
   `2026_10_04_130100_create_stock_write_offs_table … [6] Ran`.
   `WriteOffReason::count()` → **7**.
5. `git status --short` → the plan's files, plus those in Deviation 2. The
   cycle-6 archive was already committed.
6. Browser checks from step 6 with real input, console clean (above).
7. Dev-DB probe:

   | | before | after |
   |---|---|---|
   | `stock_write_offs` | 0 | **4** (2 active) |
   | M230726 1L Bottle | 950 | 0 |
   | M230726 2L Bottle | 0 | 0 |
   | M230726 10L Churn | 1 | 0 |
   | G010426 wheel | 7 (hold 1) | 7 (hold 1) |
   | G010426 packs | 18 | 18 |
   | Dashboard tile | 5 batches / 5,907 units | **4 / 4,956** |

   - The four rows: M230726 1L 950 (active), 10L 1 (reversed), 10L 1
     (active), G010426 wheel 1 (reversed).
   - Orders 24, payments 4, invoices 7 — unchanged.

## Files changed
```
 M CLAUDE.md
 M README.md
 M app/Console/Commands/ResetTradingData.php
 M app/Http/Controllers/BatchController.php
 M app/Http/Controllers/CheeseCuttingController.php
 M app/Models/{Batch,BatchItem,User}.php
 M app/Providers/AuthServiceProvider.php
 M app/Services/{BackupService,StockOverviewService}.php
 M docs/planImp/WORK_IN_PROGRESS.md
 M resources/css/stock.css
 M resources/views/batches/index.blade.php                     (Deviation 2)
 M resources/views/batches/partials/{_wheel-legend,_wheel-strip,batch-card}.blade.php
 M resources/views/batches/show.blade.php
 M resources/views/cheese-cutting/index.blade.php              (Deviation 2)
 M resources/views/components/stock/{case-blocks,case-pictograph,cheese-row}.blade.php
 M resources/views/dashboard.blade.php
 M resources/views/layouts/navigation.blade.php
 M routes/web.php
 M tests/Feature/{AuthorizationBoundary,BackupRestore,BatchCreate,
   BatchFinishedWeight,Dashboard,StockOverview}Test.php
?? app/Http/Controllers/{StockWriteOff,WriteOffReason}Controller.php
?? app/Http/Requests/StockWriteOffRequest.php
?? app/Models/{StockWriteOff,WriteOffReason}.php
?? app/Policies/{StockWriteOff,WriteOffReason}Policy.php
?? app/Services/{StockException,StockWriteOffService}.php     (StockException: Deviation 2)
?? database/migrations/2026_10_04_130000_create_write_off_reasons_table.php
?? database/migrations/2026_10_04_130100_create_stock_write_offs_table.php
?? docs/planImp/implemented.md
?? resources/views/batches/partials/_write-offs.blade.php
?? resources/views/write-off-reasons/index.blade.php
?? tests/Feature/StockWriteOffTest.php
```
No pre-existing dirty files. Nothing committed.

## Notes for Planner
- **The batch card's items table still lumps cut and converted wheels into
  "Sold"** (`quantity_sold` = produced − remaining − written off). That
  predates this cycle: G010426 shows "5 sold" where the strip says 2 sold,
  1 cut and 2 converted. Write-offs are now excluded correctly; the
  cut/converted part is a separate small fix (use `wheelStates()['sold']`
  for wheels, as the batch page's variant table does).
- **`isExpired()` vs the tile's filter**: `Batch::isExpired()` is
  `expiry_date < now()` (true from 00:00:01 on the expiry day); the
  dashboard tile and `/stock` use `expiry_date <= today`. They agree in
  practice. The button uses `isExpired()`.
- **The "Write off →" card link** costs no query (it reads the eager-loaded
  reservation sum). The batch page header uses `holdable_quantity` per item
  (one query each), which is fine for a single page.
- **Backups**: `ADDED_LATER` is a small, general mechanism. The next table
  added to `BackupService::TABLES` should go there too, or older backups
  stop restoring. Worth a line in `DEPLOYMENT.md`'s backup section (not
  touched — not in this plan).
- **A write-off doesn't change batch `status`**, per the plan. M230726 is
  now `active` with 0 remaining; it drops off the tile and notes but is
  still listed under `/batches?status=expired` (the filter matches active
  past expiry regardless of stock). Filtering that list on `remaining > 0`,
  or prompting to close the batch, is a possible follow-up.
