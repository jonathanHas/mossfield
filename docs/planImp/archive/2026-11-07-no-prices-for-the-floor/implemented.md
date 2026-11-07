# Cycle 16e — No prices for the floor; `/stock` tells not-ready cheese apart — implementation

Status: DONE
Plan revision: 1
Implementer: Opus
Date: 2026-11-07

## Baseline
HEAD: 45da65f
Pre-existing dirty files (the Planner's, not mine):
```
 M docs/planImp/RESUME.md
?? docs/planImp/plan.md
```

## Steps
### 0. Baseline — done
```
$ php artisan test
  Tests:    819 deprecated, 1 passed (5696 assertions)     ← 820 tests, 0 failed
$ ./vendor/bin/pint --test app tests
  PASS ... 233 files
$ git rev-parse --short HEAD → 45da65f
```
`git status --short` was not empty: the Planner's `RESUME.md` edit and the untracked `plan.md` (see Baseline).

### 1. Hide the stock value from the floor — done
Changed: `resources/views/stock/overview.blade.php` — the `<div class="lg:text-right">` (label + figure) wrapped in `@can('see-financials')`.
Check: see step 4 (`FloorPricesTest` case 1) and Verification 4 (browser).

### 2. Hide the price column from the floor — done
Changed: `resources/views/products/index.blade.php`, `resources/views/products/show.blade.php` — `<th>Price</th>` and the `price_label` `<td>` each wrapped in `@can('see-financials')`; `€/kg` tag untouched.
```
$ grep -n colspan resources/views/products/index.blade.php resources/views/products/show.blade.php
(no output)
$ grep -rn "€\|price" resources/views/products/{index,show,_sibling_list}.blade.php resources/views/stock resources/views/components/stock | grep -v "€/kg"
show.blade.php:135   @if($variant->is_priced_by_weight)       ← the €/kg tag's guard
show.blade.php:145   {{ $variant->price_label }}              ← gated
overview.blade.php:17 €{{ number_format($total_value, 0) }}   ← gated
index.blade.php:131  @if($variant->is_priced_by_weight)
index.blade.php:140  {{ $variant->price_label }}              ← gated
```
Check: `FloorPricesTest` cases 2–3 and Verification 4.

### 3. Not-ready cheese is its own segment and not in the value — done
Changed: `app/Services/StockOverviewService.php` (`not_ready` in both branches via `$batch->isReadyToSell()`; `totalValue()` filters `isReadyToSell()`; class comment rewritten), `resources/views/components/stock/cheese-row.blade.php` (`not_ready` after `allocated` in `$order` + comment), `resources/css/stock.css` (`--state-not-ready: var(--info)`). `npm run build` → `✓ built`; `grep -l state-not-ready public/build/assets/*.css` → `app-CSvduFTX.css`.
Pre-check: `grep -rn "segments\['available'\]" app resources tests` → only `StockOverviewTest.php:222` (a milk row); no other reader of the cheese segments.
Dev DB, `StockOverviewService::build()` before → after:
```
before: total_value=11089
  wheels G030426 total=38 {"available":18,"allocated":3,"maturing":7,"cut":2,"converted":0,"written_off":0,"sold":8}
  wheels G281026 total=40 {"available":40,"allocated":0,"maturing":0,...,"sold":0}
  packs  G030426 total=24 {"available":13,"allocated":0,"written_off":0,"sold":11}
after:  total_value=7889
  wheels G030426 total=38 {"available":18,"allocated":3,"not_ready":0,"maturing":7,"cut":2,"converted":0,"written_off":0,"sold":8}
  wheels G281026 total=40 {"available":0,"allocated":0,"not_ready":40,"maturing":0,...,"sold":0}
  packs  G030426 total=24 {"available":13,"allocated":0,"not_ready":0,"written_off":0,"sold":11}
Whole Wheel: freeStockByVariant([5]) → {"5":18}  (/products unchanged); calculatePrice(40) = 3200 = 11089 − 7889
```

### 4. Tests — done
Changed: `tests/Feature/FloorPricesTest.php` (new — checked absent first; 3 cases: `/stock`, `/products`, `/products/{cheese}`, each factory vs office), `tests/Feature/StockOverviewTest.php` (+`test_cheese_before_its_ready_date_is_not_ready_and_not_valued`).
Fixtures need `raw_milk_litres` on the cheese batch (NOT NULL column without default) — the plan's sketch didn't mention it.
```
$ php artisan test --filter='FloorPricesTest|StockOverviewTest|ProductStockFigureTest'
  Tests:    19 deprecated (126 assertions)          ← 19 passed, 0 failed
```
Red checks (temporary, restored afterwards; diffs re-verified):
```
views reverted to HEAD → FloorPricesTest: 3 failed (7 assertions)
service reverted to HEAD → test_cheese_before_its_ready_date…: Failed asserting that 12 is identical to 0.
```

### 5. Guide and docs — done
Changed: `docs/guide/stock.md` (Not ready bullet after the Cheese line; value line now "shown to the office only, not the floor"; Price-column sentence after the Stock bullet; 59 lines), `CLAUDE.md` (Stock Overview → Cheese wheels + Total value bullets), `docs/guide/images/stock-overview.png`.
The screen renders the label "NOT READY" (`.stock-segments__label` is `text-transform: uppercase`); the guide writes the other labels title-case (**Available**, **Maturing**), so **Not ready** follows that.
```
$ node docs/guide/screenshot.mjs stock-overview
✓ docs/guide/images/stock-overview.png
```
Picture inspected: G281026 row shows 40 blue dots, "NOT READY 40", no available; Total stock value blurred (unreadable); other rows unchanged.
`/help/stock` as `factory_test` → 200, contains "not ready", no `€` figure (headless check below).

### 6. Pint and the suite — done (after one test-fixture change, see Deviations)
```
$ ./vendor/bin/pint app tests  → PASS 234 files (nothing to fix)
$ php artisan test
   FAILED  Tests\Feature\CheeseConversionTest > stock overview shows maturin…
  Tests:    822 deprecated, 1 failed, 1 passed (5736 assertions)     ← 824 tests, 1 failed
$ php artisan test --filter=test_stock_overview_shows_maturing
  Failed asserting that 0 is identical to 3.   at tests/Feature/CheeseConversionTest.php:523
```
Why: the test's fixture is `farmhouseBatch(2, 5)` — 2 months old, Farmhouse `maturation_days` 90 ⇒ `ready_date` in the future. With 2 held, the row is now `maturing 2, not_ready 3, available 0`; the test asserts `available 3`. That is the behaviour the plan asks for (step 3), not a regression — but changing an existing test is not in the plan, so I stopped and asked Jon. Jon chose to age the fixture (Deviation 1). After it:
```
$ php artisan test
  Tests:    823 deprecated, 1 passed (5736 assertions)     ← 824 tests, 0 failed
```

## Deviations
1. **`tests/Feature/CheeseConversionTest.php` changed** (not in the plan). `test_stock_overview_shows_maturing_segment_and_excludes_from_available` built its batch with `farmhouseBatch(2, 5)` — 2 months old, short of Farmhouse's 90-day ready date — so after step 3 its 3 free wheels read `not_ready`, and `available 3` failed. Asked Jon (protocol forbids rewriting a test unilaterally); he chose to age the fixture: `farmhouseBatch(6, 5)` plus a one-line comment. The assertions (`maturing 2`, `available 3`) are untouched and still test what the name says. The young-batch + not-ready case is covered by the new `StockOverviewTest` case.
2. Plan's step 6 said ≥ 827 tests; the real number is **824** (820 + 3 in `FloorPricesTest` + 1 in `StockOverviewTest`, the four the plan's step 4 specifies). The plan's 827 looks like a miscount, not missing work.
3. Fixtures in both new tests set `raw_milk_litres` on the cheese batch — the column is NOT NULL with no default. Not a behaviour change.

## Verification
1. `php artisan test` → **824 tests, 0 failed** (5,736 assertions; reported as "823 deprecated, 1 passed").
2. `./vendor/bin/pint --test app tests` → `PASS 234 files`, exit 0.
3. `php artisan test --filter=HelpPagesTest` → `Tests: 9 deprecated (470 assertions)` — 9 passing.
4. Headless Chrome (puppeteer-core, the 16d `helpcheck.mjs` pattern; script `/tmp/claude-1000/-var-www-html-mossfield/4e7e0c1a-d849-41ed-b882-c6551800ba62/scratchpad/floorcheck.mjs`). `euroFig` = `body.innerText` matches of `€\s?\d…`; `tables` = `<thead th>` count : distinct `<tbody tr>` cell counts.
```
factory_test /stock       200 {"euroFig":[],"perKg":false,"value":false,"notReady":true}
factory_test /products    200 {"euroFig":[],"perKg":true,"tables":["6:6"×7],"ths":"NAME,SIZE,WEIGHT,STOCK,STATUS"}
factory_test /products/3  200 {"euroFig":[],"perKg":true,"tables":["7:7","6:6"],"ths":"NAME,SIZE,UNIT,WEIGHT,STOCK,STATUS,…"}
factory_test /help/stock  200 {"euroFig":[],"value":true,"notReady":true}   ← the guide's text, not a figure
factory_test console errors: []
office_test  /stock       200 {"euroFig":["€7,889"],"value":true,"notReady":true}
office_test  /products    200 {"euroFig":["€18.00","€2.50","€4.50","€72.00"],"perKg":true,"tables":["7:7"×7],"ths":"NAME,SIZE,WEIGHT,PRICE,STOCK,STATUS"}
office_test  /products/3  200 {"euroFig":["€16.00","€4.50"],"perKg":true,"tables":["8:8","6:6"],"ths":"NAME,SIZE,UNIT,WEIGHT,PRICE,STOCK,…"}
office_test console errors: []
```
   Header and cell counts match for both roles (columns line up).
5. `/stock` as office on the dev box: G281026 row `not_ready 40`, `available 0` (and the picture shows "NOT READY 40", no available). Total stock value **€11,089 before step 3 → €7,889 after**; the difference €3,200 = `calculatePrice(40)` for the Whole Wheel. `/products` Whole Wheel figure still 18.
6. `git status --short` (below): no `demo-data.json`, no migrations, nothing under `app/Models` or `app/Http`.

## Files changed
```
 M CLAUDE.md
 M app/Services/StockOverviewService.php
 M docs/guide/images/stock-overview.png
 M docs/guide/stock.md
 M docs/planImp/RESUME.md                       ← pre-existing (Planner's)
 M resources/css/stock.css
 M resources/views/components/stock/cheese-row.blade.php
 M resources/views/products/index.blade.php
 M resources/views/products/show.blade.php
 M resources/views/stock/overview.blade.php
 M tests/Feature/CheeseConversionTest.php       ← Deviation 1
 M tests/Feature/StockOverviewTest.php
?? docs/planImp/implemented.md
?? docs/planImp/plan.md                          ← pre-existing (Planner's)
?? tests/Feature/FloorPricesTest.php
```
`npm run build` rebuilt `public/build/` (untracked). Nothing committed or deployed.

## Notes for Planner
- The plan's Risks named only the seeded-data test as likely to break; the one that actually broke was `CheeseConversionTest`'s maturing-segment test (young fixture). Worth a grep for `StockOverviewService` in tests when a future plan changes its segments: `grep -rln StockOverviewService tests`.
- The blurred value in `stock-overview.png` is unreadable, but the blur keeps the figure's shape (a leading `€` and four digits are guessable as a length). Fine for the rule as written; mention it if the bar is "no hint of a figure".
- `Batch::isReadyToSell()` compares `ready_date->lte(now())` while `BatchItem::isReadyToSell()` compares against `now()->toDateString()`. They agree for a date-cast `ready_date` (midnight ≤ now on the ready day), so the service's per-batch check and the value's per-item check can't disagree today — but they are two definitions of one rule. Not touched (out of scope: no `Batch`/`BatchItem` changes).
- Unchanged and out of scope as planned: the `/batches` and `/cheese-cutting` wheel strips still show not-ready wheels as free.
