# Cycle 16e — No prices for the floor; `/stock` tells not-ready cheese apart

Status: ACCEPTED
Revision: 1
Planner: Fable 5.1
Date: 2026-11-07 (accepted 2026-11-07)

Read `docs/planImp/planimp.md` first. The Implementer writes
`docs/planImp/implemented.md`; it does not edit this file.

## Goal

A factory login no longer sees money on the two shared screens that never
had the gate: the **Total stock value** on `/stock` and the **Price** column
on `/products` and `/products/{product}` are shown to the office only, as
every € on the order, picking and dashboard screens already is
(`OPEN_QUESTIONS.md` #6, Jon's decision of 2026-11-07). The floor keeps
every count. Alongside it, the one `/stock` fault the 16d review found:
cheese that has not reached its ready date is shown as **Available** and
valued, although `/products`, the allocator, the picker and the run sheet
would not hand it out. It becomes its own **not ready** segment and leaves
the valuation, so the stock overview agrees with the rest of the app.

## Context

**Where the gate lives.** `Gate::define('see-financials', …)` in
`AuthServiceProvider::boot()` — admin and office pass, factory does not.
Views use `@can('see-financials') … @endcan` (e.g. `dashboard.blade.php:209`
and `:239`, the order blades, the picking blades). The guide calls it "office
information" and blurs these figures in its pictures because the pictures are
shot as `office`; nothing in the guide changes its mind about that.

**The three sites** (the only `€` with a figure a factory user can reach in
these views — `grep -rn "€" resources/views/products resources/views/stock
resources/views/components/stock` finds nothing else; `variants/create` and
`variants/edit` are office-only routes):

- `resources/views/stock/overview.blade.php:11-18` — the
  `<div class="lg:text-right">` holding the "Total stock value" label and
  `€{{ number_format($total_value, 0) }}`. Its parent is a flex row with the
  title on the left; with the right-hand child gone the title still sits
  correctly. `$total_value` comes from `StockOverviewService::build()`
  (`StockController`), computed for everyone — leave that, hide the render.
- `resources/views/products/index.blade.php:109` (`<th class="mf-th">Price</th>`)
  and `:137` (`<td class="mf-td font-mono font-medium">{{ $variant->price_label }}</td>`).
  The `€/kg` tag at `:130-132` carries no figure and **stays** (decision).
- `resources/views/products/show.blade.php:114` (header) and `:142` (cell);
  `€/kg` tag at `:133-135` stays.

Header and cell must be gated together or the table misaligns. No row uses
`colspan` across these tables (check with grep before relying on it).

**Ready date.** `batches.ready_date` is set for cheese at creation from
`product.maturation_days` (`Batch::boot`, `Batch.php:42-44`).
`Batch::isReadyToSell()` (`:147`) and `BatchItem::isReadyToSell()` (`:391`,
cheese with a `ready_date` ⇒ `ready_date <= today`; milk and yoghurt always
true) gate "sellable now". `BatchItem::freeStockByVariant()` (`:350`) filters
`isReadyToSell() && ! batch->isExpired()` — that is why `/products` shows 18
wheels for the variant where `/stock` shows 40 available (dev box: G281026,
ready 27/12/2026). `BatchItem::isAvailableForAllocation()` is the same test
plus `available_quantity > 0`.

**The service.** `app/Services/StockOverviewService.php`: `build()` loads
every item of an active, unexpired batch with `batch.product` and
`productVariant` eager-loaded (so `isReadyToSell()` costs no query), then
`totalValue($items)` (`:83`, sums `calculatePrice(available_quantity)` over
**all** items) and `buildCheeseCard()` (`:166`). In the wheel branch
(`:198-222`) `available = remainingClamped − allocated − maturing`; in the
pack branch (`:223-241`) `available = remaining − allocated`. Neither looks at
the ready date. Milk and yoghurt (`buildSimpleCard`) are always ready — no
change there.

**The row component.** `resources/views/components/stock/cheese-row.blade.php`
renders one dot per unit in `$order = ['available', 'allocated',
'maturing', 'cut', 'converted', 'written_off', 'sold']`, colour
`var(--state-<key with _→->)`, label `str_replace('_', ' ', $key)`, and a
labelled figure per non-zero segment. Adding a key to `$order` and a token to
`resources/css/stock.css` (`:6-15`) is the whole rendering change — this is
how `written_off` was added. Tokens already defined: available = accent
green, allocated = warn amber, maturing = deep amber `oklch(0.55 0.11 56)`,
cut = grey, converted = purple, written-off = danger red, sold = near-black,
empty = `--line-2`. `--info` (`app.css:44`, `oklch(0.55 0.08 235)`, a muted
blue) is used by `.mf-tag-info` — the `€/kg` tag — and by nothing in the
strip, so it is free for *not ready*.

**Tests to copy.** `tests/Feature/StockOverviewTest.php` builds products,
variants, batches and items with `::create([...])` and reads
`app(StockOverviewService::class)->build()['milk']['variants'][0]['segments']`
then asserts on `/stock` (`assertSee('written off')`,
`assertSee('var(--state-written-off)', false)`) — copy
`test_a_written_off_bottle_is_its_own_segment_not_sold` (`:201`).
`tests/Feature/ProductStockFigureTest.php:240` is the factory-vs-office
pattern for `/products` (`User::factory()->factoryWorker()`, plain
`User::factory()` is office). `ChilledRunTest.php:268` is the
`assertDontSee('€')` precedent — usable as-is on `/stock` (no `€/kg` there),
**not** on the product pages, where the `€/kg` tag survives: there, assert
that the body has no `€` followed by a digit
(`preg_match('/€\s*\d/', $response->getContent())` is 0) and that the known
`price_label` (e.g. `€2.50`) is absent for the floor and present for the
office.

**The guide.** `docs/guide/stock.md:20` already says "*Total stock value* at
the top is office information"; `:13-17` lists the segment labels. The
`stock-overview` picture (`docs/guide/screenshots.json:345`, shot as office,
blur on the value) **changes** when the 40 G281026 wheels turn from
available to not ready, so it is re-shot: `node docs/guide/screenshot.mjs
stock-overview` (the argument is a name filter, `screenshot.mjs:5,40`). The
`products-index` and `product-page` pictures are shot as office and do not
change. Dev DB: the demo milk batch M071126 expires 2026-11-17, so a plain
run of `demo-data.php` is not needed today; do not run `GUIDE_FRESH`.

**Baseline.** HEAD `45da65f`, tree clean, production on the same commit.
820 tests, 0 failed (5,696 assertions); `pint` clean.

## Constraints

- Gate with `@can('see-financials')`, never a role check — the gate is the
  one definition of who sees money (`CLAUDE.md` → Roles & authorization).
- Counts stay for the floor: Stock column, segments, batch codes, expiry,
  case maths, expired notes. Only the € figure and its label/header go.
- The `€/kg` tag stays on both product pages (decision; it carries no figure).
- Not-ready units move from `available` to `not_ready`; nothing else in a
  row changes (`allocated`, `maturing`, `cut`, `converted`, `written_off`,
  `sold` are as before) and the dots still sum to `total`.
- The valuation excludes not-ready units — the same filter
  `freeStockByVariant()` applies — and nothing else about it changes.
- No migrations, no controller or route changes, no change to
  `BatchItem`/`Batch`. Dev box only; nothing on production; no commit, no
  deploy (Jon ships).
- The Planner does not edit application code; the Implementer does not edit
  this plan. Every departure goes in `implemented.md` → Deviations.
- Guide pictures: `stock-overview` is re-shot as office with its blur; no
  readable `€` figure may appear in it. `docs/guide/demo-data.json` stays
  gitignored.

## Out of scope

- The `/batches` and `/cheese-cutting` wheel strips (`BatchItem::wheelStates()`),
  which show a not-ready wheel as free — the batch card already prints the
  ready-date countdown (`Batch::readyIn()`); leave them.
- Valuing not-ready or maturing stock separately, or any other change to
  what "Total stock value" means.
- Hiding `base_price` anywhere else (the variant forms are office routes;
  the API is token-gated).
- The other open `SMALL_FIXES` items.
- Cycle 17 (delete guards, parked cycle 12).

## Steps

### 0. Baseline
Check: `php artisan test` → 820 tests, 0 failed; `./vendor/bin/pint --test
app tests` clean; `git status --short` empty; HEAD `45da65f`.

### 1. Hide the stock value from the floor
Files: `resources/views/stock/overview.blade.php`
What: wrap the `<div class="lg:text-right">` block (label **and** figure,
lines 11–18) in `@can('see-financials') … @endcan`. Nothing else on the page
changes.
Check: as `factory_test`, `/stock` has no `€` anywhere and no "Total stock
value"; as `office_test` both are there; the title block renders the same
for both.

### 2. Hide the price column from the floor
Files: `resources/views/products/index.blade.php`,
`resources/views/products/show.blade.php`
What: in each file wrap the `<th class="mf-th">Price</th>` and the
`price_label` `<td>` in `@can('see-financials') … @endcan`. Leave the `€/kg`
tag, the Weight and Stock columns and the row actions as they are. Confirm
no `colspan` in these tables assumes the column count.
Check: as `factory_test`, `/products` and `/products/{product}` have no
"Price" header, no `€` followed by a digit, the `€/kg` tag on a weight-priced
variant, and the Stock figures; as `office_test` the column is back. Columns
line up in both.

### 3. Not-ready cheese is its own segment and not in the value
Files: `app/Services/StockOverviewService.php`,
`resources/views/components/stock/cheese-row.blade.php`,
`resources/css/stock.css`
What:
- `buildCheeseCard()`: in both the wheel and the pack branch, after
  `$available` is computed, when `! $batch->isReadyToSell()` set
  `$notReady = $available; $available = 0;` else `$notReady = 0`, and emit
  `'not_ready' => $notReady` in `segments` **between `allocated` and
  `maturing`** (wheels) / **between `allocated` and `written_off`** (packs).
  Use `Batch::isReadyToSell()` on the loaded `$batch` (one definition, no
  query).
- `totalValue()`: sum over `$items->filter(fn ($i) => $i->isReadyToSell())`
  — the same filter as `BatchItem::freeStockByVariant()`; say so in the
  comment. Update the class comment at `:13-16` ("Mirrors
  `isAvailableForAllocation()`") so it is true again: active, unexpired
  **and, for the value and the available segment, ready**.
- `cheese-row.blade.php`: add `'not_ready'` to `$order` after `'allocated'`
  (and to the comment). Label and token follow from the key: "not ready",
  `var(--state-not-ready)`.
- `stock.css`: add `--state-not-ready: var(--info);` beside the other
  tokens with a one-line comment (cheese before its ready date — counted,
  not sellable yet).
Check: on the dev box `/stock` shows G281026's 40 wheels as **not ready**
(blue dots, "not ready 40" on the right, "available" gone from that row);
the other cheese rows are unchanged; the office's Total stock value drops by
those 40 wheels' value; `/products` still says 18 for the variant. Run
`npm run build` so the token is in the built CSS.

### 4. Tests
Files: `tests/Feature/FloorPricesTest.php` (new),
`tests/Feature/StockOverviewTest.php`
What:
- `FloorPricesTest` — one rule, three screens. Fixtures: a milk product with
  a `€2.50` variant and a cheese product with a weight-priced wheel
  (`is_variable_weight`, `is_priced_by_weight`, `base_price` e.g. 12.50) in
  an active, ready batch with stock. Cases:
  1. factory `/stock` → 200, `assertDontSee('€')`,
     `assertDontSee('Total stock value')`; office → sees both.
  2. factory `/products` → 200, no `Price` header
     (`assertDontSee('>Price<', false)` or equivalent), no `€` followed by a
     digit, `assertSee('€/kg')`, sees the stock figure; office → sees
     `€2.50`, `€12.50/kg` and the header.
  3. the same pair for `/products/{cheese}`.
- `StockOverviewTest` — a new case modelled on `:201`: a cheese product
  (`maturation_days` 60) with a Whole Wheel variant, a batch produced 10
  days ago (so `ready_date` is in the future and `expiry_date` null or far
  off), 12 wheels remaining; a second, ready cheese batch (production 90
  days ago) with 5 wheels. Assert the first row's segments are
  `available 0, not_ready 12`, the second's `available 5, not_ready 0`
  (rows sort by label then production date — read them by `batch_code`);
  `total_value` equals the ready batch's value alone
  (`calculatePrice(5)`); the page shows "not ready" and
  `var(--state-not-ready)`. Assert the dots still sum: every segment of the
  not-ready row adds up to `total`.
- **Amendment (Planner, 2026-11-07, mid-cycle):**
  `CheeseConversionTest::test_stock_overview_shows_maturing_segment_and_excludes_from_available`
  builds a 2-month-old Farmhouse batch (ready at 90 days), so under step 3
  its 3 free wheels are correctly *not ready* and the test fails. Age the
  fixture — `farmhouseBatch(2, 5)` → `farmhouseBatch(6, 5)` in that one
  test — and keep its assertions (maturing 2, available 3). The test is
  about the hold, not the ready date; the young-batch case is the new
  `StockOverviewTest` case above. Note it under Deviations anyway so the
  review sees it.
Check: `php artisan test --filter='FloorPricesTest|StockOverviewTest|ProductStockFigureTest|CheeseConversionTest'`
→ all pass; then the full suite.

### 5. Guide and docs
Files: `docs/guide/stock.md`, `docs/guide/images/stock-overview.png`,
`CLAUDE.md`
What:
- `stock.md`: in the segment list add, after the Cheese line, "Cheese that
  has not reached its ready date shows as **Not ready**: counted, but not
  for sale yet and not in the value." Change `:20` to say the office sees
  the value and the floor does not (one sentence, no figure). In the
  Products section add one sentence after the Stock bullet: "The *Price*
  column is shown to the office only." Keep the page ≤ 110 lines and every
  bold label verbatim from the views (`grep` "not ready" in the rendered
  page — the component lower-cases the label, so write **Not ready** only
  if that is what the screen shows at the figure; otherwise match the
  screen).
- Re-shoot: `node docs/guide/screenshot.mjs stock-overview` → 1 ✓; the
  value is blurred; the not-ready row is visible.
- `CLAUDE.md` → "Stock Overview (`/stock`)": the **Total value** bullet
  gains "office only (`@can('see-financials')`); not-ready cheese excluded,
  like `freeStockByVariant()`"; the **Cheese wheels** bullet gains the
  `not_ready` segment. Two short edits, no new sections. The "Products
  Master-Detail" section needs nothing.
Check: `/help/stock` as factory shows the new text and picture; the picture
has no readable `€`.

### 6. Pint and the suite
Check: `./vendor/bin/pint app tests` then `php artisan test` → ≥ 827 tests,
0 failed (say the real number); `git status --short` lists only the files
above (`public/build/` is not tracked, so the build leaves no diff).

## Verification

1. `php artisan test` → **≥ 827 tests, 0 failed** (state the number and
   assertions).
2. `./vendor/bin/pint --test app tests` → clean.
3. `php artisan test --filter=HelpPagesTest` → still 9 passing (the guide
   edit and picture must not break the audience walk).
4. Browser (headless is fine, the 16d `helpcheck.mjs` pattern): as
   `factory_test`, `/stock`, `/products`, `/products/{id}` — no `€` with a
   digit anywhere in `body.innerText`; `€/kg` present on the product pages;
   Stock column present. As `office_test`, all three show prices and the
   value. No console errors.
5. `/stock` as office on the dev box: G281026 row reads "not ready 40", no
   "available"; the Total stock value is lower than before step 3 by that
   row's value (record both figures in `implemented.md`, not in the guide).
6. `git status --short` → no `demo-data.json`, no migrations, no changes
   under `app/Models` or `app/Http`.

## Risks

- **Hiding the header without the cell** (or the reverse) shifts every
  column in that table for the floor. Gate both, check in the browser as
  `factory_test`.
- **The seeded-data test** (`StockOverviewTest::test_stock_overview_renders_for_seeded_data`)
  may assert on figures that include not-ready cheese from `BatchSeeder`;
  if it fails, read why before changing the expectation — the new figure
  should match `/products` for the same variant.
- **Other callers of `buildCheeseCard()` segments**: `grep -rn "segments\['available'\]"
  app resources tests` before changing — the dashboard does not read this
  service's rows today, but confirm.
- **CSS token not built**: Vite bundles `stock.css` via `app.css`; without
  `npm run build` the dot is transparent on the dev box and the picture is
  wrong. Build before shooting.
- **The picture's blur selector** `div.text-\[32px\].font-mono` still
  matches (shot as office). Check the picture before counting it done.

## Review

### Revision 1 — reviewed 2026-11-07 (Planner: Fable 5.1)

**Verdict: ACCEPTED.**

Reran by the Planner: `php artisan test` → 824 tests, 0 failed (5,736
assertions); `pint --test` → 234 files clean; a two-role headless pass over
`/stock`, `/products`, `/products/3` and `/help/stock` (factory: no `€`
with a digit anywhere, `€/kg` still on both product pages, header and row
cell counts equal — 6:6 and 7:7 — so the columns line up; office: prices
and `€7,889` back, 7:7 and 8:8; no console errors; the not-ready dot
computes to `oklch(0.55 0.08 235)` for both). Read the diff in full and the
re-shot `stock-overview.png` (value blurred, G281026 a row of blue dots
with "NOT READY 40", nothing else moved).

- Steps 0–6: all done as written. The gates wrap header and cell together;
  the service change is the two-line destructuring in both branches plus
  the `isReadyToSell()` filter on the value; `cheese-row` and `stock.css`
  as specified; `FloorPricesTest` covers the three screens for both roles
  with the `€\s*\d` regex as asked; the `StockOverviewTest` case pins
  segments, the dots-sum-to-total invariant and the valuation; guide,
  picture and `CLAUDE.md` done.
- Deviation 1 (aged `CheeseConversionTest` fixture): accepted — it was
  added to step 4 as an amendment mid-cycle when the Implementer asked.
- Deviation 2 (824, not ≥ 827): accepted; the plan's arithmetic was wrong
  (820 + 4 = 824), the work is complete.
- Deviation 3 (`raw_milk_litres` on the cheese fixtures): accepted; the
  plan's sketch omitted a NOT NULL column.
- Notes: (1) grep `tests` for `StockOverviewService` next time its
  segments change — taken, added to the Risks habit. (2) The blur keeps
  the figure's length — fine under the rule as written (no *readable*
  figure). (3) `Batch::isReadyToSell()` and `BatchItem::isReadyToSell()`
  are two statements of one rule that agree today — noted in
  `SMALL_FIXES.md` as a tidy-up, not a bug. (4) `/batches` wheel strips
  unchanged — as planned.

