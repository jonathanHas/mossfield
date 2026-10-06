# Polish: the follow-ups from cycles 6–8

Status: READY
Revision: 1
Planner: Fable 5.1
Date: 2026-10-06

## Goal

Clear the six small things the last three reviews deferred: the batch card's
items table still lumps cut and converted wheels into "Sold"; a fully
written-off expired batch shows no sign it has been dealt with; the order
page says "Awaiting invoice" for an order that can't be invoiced until it is
dispatched; the To-Invoice queue's manual *Issue* refuses with `back()`; the
run sheet's sticky header is keyed on a breakpoint when it should be keyed on
how many columns the sheet has; and fragment landings use a fixed 80 px scroll
margin where the flash's real height is known. Nothing here changes stock,
money or any rule.

## Context

HEAD `ebc1c7c` + the staged archive of cycle 8. Suite **674 passed, 0 failed**;
any failure is real. `pint --test app tests database routes config` clean.
Nothing pushed or deployed.

**Facts the steps rely on**

- `batches/partials/batch-card.blade.php`: the wheel strip already uses
  `wheelStates()` (`:13–15`, `$wheelBreakdowns`); the **items table** at
  `:206/:231` prints `$item->quantity_sold` = `produced − remaining −
  written_off`, which for a wheel item includes cut and converted wheels
  (G010426 reads "5 sold" where the strip says 2 sold · 1 cut · 2 converted).
  The expired tag block is `:46–53`: "Expired" plus a "Write off →" link when
  `writableUnits() > 0`; when a batch is fully written off neither the link
  nor any tag says so. `BatchItem::written_off_units` is eager-loaded on the
  index (`written_off_units`).
- `orders/show.blade.php:232–238`: `@elseif($order->hasReachedReady())` →
  "Awaiting invoice · Finance". Since cycle 8 a `ready` order **cannot** be
  issued (`InvoiceService` requires dispatched/delivered); only
  dispatched/delivered uninvoiced orders are "awaiting invoice".
- `UninvoicedOrderController::issue()` catches `InvoicingException` and
  returns `back()->with('error', …)`; `CLAUDE.md` (Flash messages) prefers an
  explicit `redirect()->route(...)` on refusal paths so a Referer-less
  request can't land the message outside the layout.
- `_run-table.blade.php:37`: `$stickyHead = ! $editId ? 'xl:overflow-visible' : ''`;
  `$qtyColCount` is computed at `:5`. Cycle 6 measured the 11-column dev
  sheet at 983 px inside a 985 px panel at `xl` (1280): a 12th column
  overflows. Each quantity column is 60 px.
- Fragment landings: 10 elements carry `scroll-mt-20` (order lines,
  `#order-items`, `#special-prices`, `#write-offs`, `#dispatch`, run-stop
  rows, `#run-{id}`, sheet rows); `app.css:748` animates
  `.scroll-mt-20:target`; the sheet rows have their own
  `scroll-margin-top: calc(var(--mf-feedback-h, 0px) + 80px)` (`:524`).
  `--mf-feedback-h` is the sticky flash region's height, set by
  `layouts/partials/feedback.blade.php` as the page parses and zeroed on
  dismiss (cycle 6).
- Tests to extend: `StockWriteOffTest` (card tests live there),
  `ConsolidationPassTest` (finding-8 card test), `OrderTransitionsTest`,
  `InvoiceIssueTest`, `ChilledRunTest`, `OrderItemEditTest`,
  `LayoutFeedbackTest`.

## Constraints

- No arithmetic changes; `quantity_sold` stays as it is (packs and
  milk/yoghurt are right) — the card reads the wheel figure from
  `wheelStates()`, which it already computes.
- No new routes, migrations or config.
- `npm run build` after CSS; `./vendor/bin/pint` on PHP.
- **Do not commit.** Planner commits on acceptance. Nothing pushed/deployed.

## Out of scope

- The two stale July `ready` orders on dev (dispatch or cancel is Jon's
  call; recorded in `RESUME.md`).
- The batch-ceiling numbers (Jon's).
- `FULFILMENT_PLAN.md` Phase 4.

## Steps

### 1. The card's items table shows sold, cut and converted separately

Files: `resources/views/batches/partials/batch-card.blade.php`,
`tests/Feature/StockWriteOffTest.php` (or `ConsolidationPassTest`)

What: for a wheel item, the Sold cell reads `wheelStates()['sold']` (reuse
`$wheelBreakdowns`, keyed by item id, rather than calling it again) and the
row gains a muted "· N cut · N converted" after it when either is non-zero;
for other items keep `quantity_sold`. The column header stays "Sold".
Test: a wheel item with 1 cut, 2 converted and 2 sold renders "2" in the Sold
cell with "1 cut · 2 converted" beside it (not "5").

Check: `php artisan test --filter="StockWriteOffTest|ConsolidationPassTest"` pass.

### 2. A fully written-off expired batch says so

Files: `resources/views/batches/partials/batch-card.blade.php`,
`tests/Feature/StockWriteOffTest.php`

What: in the expired block, when `writableUnits() === 0` and the batch's
active write-offs > 0 (sum `written_off_units` over the loaded items), render
a neutral tag **"Written off"** after "Expired" (instead of the link). Test: an
expired batch fully written off shows the tag and no "Write off →"; one with
stock left shows the link and no tag.

Check: `php artisan test --filter=StockWriteOffTest` pass.

### 3. The order page tells the truth about a ready order's bill

Files: `resources/views/orders/show.blade.php`, `tests/Feature/OrderTransitionsTest.php`

What: split the `hasReachedReady()` branch — `ready` → "Awaiting dispatch —
invoiced when dispatched"; `dispatched|delivered` → "Awaiting invoice ·
Finance" as now. Test: a ready order's page shows the first and not the
second; a dispatched uninvoiced order the second.

Check: `php artisan test --filter=OrderTransitionsTest` pass.

### 4. `issue()` refuses to a known page

Files: `app/Http/Controllers/Invoicing/UninvoicedOrderController.php`,
`tests/Feature/InvoiceIssueTest.php`

What: `issue()`'s catch returns
`redirect()->route('invoicing.uninvoiced.index')->with('error', …)`; check
`issueAll()` and the controller's other refusals for `back()` and treat them
the same. Test: the existing refusal case asserts the redirect target.

Check: `php artisan test --filter=InvoiceIssueTest` pass.

### 5. The sticky sheet header follows the column count

Files: `resources/views/chilled-runs/partials/_run-table.blade.php`,
`tests/Feature/ChilledRunTest.php`

What: `$stickyHead` = (not editing) and: `$qtyColCount <= 11` →
`xl:overflow-visible`; `<= 20` → `2xl:overflow-visible`; else `''` (always
scroll). Comment the arithmetic (60 px per column; 11 fit at 1280, ~20 at
1536). Test: with the default columns (≤ 11) the wrapper has
`xl:overflow-visible`; add active variants until 12 columns → it has
`2xl:overflow-visible` and not `xl:`.

Check: `php artisan test --filter=ChilledRunTest` pass; browser at 1280 with
the dev run → header still sticky.

### 6. Fragment landings clear the flash by its real height

Files: `resources/css/app.css`, the 10 views carrying `scroll-mt-20`
(`grep -rln "scroll-mt-20" resources/views`), `tests/Feature/OrderItemEditTest.php`,
`tests/Feature/LayoutFeedbackTest.php`

What: one class **`.mf-landing`** — `scroll-margin-top: calc(var(--mf-feedback-h, 0px) + 1rem)`
plus the existing `:target` animation moved onto `.mf-landing:target`;
replace every `scroll-mt-20` with `mf-landing`; the sheet rows keep their own
larger rule (header + flash) — make it `.mf-run tbody tr.mf-landing` so
there's one class on the element and two rules by context. Delete the
`.scroll-mt-20:target` rule. Tests: the order page's line rows and the sheet
rows carry `mf-landing`; `grep -rn "scroll-mt-20" resources` → nothing.

Check: `npm run build`; `php artisan test --filter="OrderItemEditTest|LayoutFeedbackTest|ChilledRunTest"`
pass. Browser: change a line qty → the line lands just under the flash; ×
the flash, reload with the fragment → the line lands near the top.

### 7. Docs

Files: `CLAUDE.md`, `docs/planImp/WORK_IN_PROGRESS.md`

What: CLAUDE.md → the redirect convention names `mf-landing` (not
`scroll-mt-20`); Chilled Run Sheet → sticky header by column count; the
order-page billing line's three states. `WORK_IN_PROGRESS.md`: *Recently
Completed: Polish (2026-10-06)*.

Check: `grep -n "mf-landing" CLAUDE.md` → hits; `grep -rn "scroll-mt-20" CLAUDE.md` → none.

## Verification

1. `php artisan test` → **0 failed**; record the count.
2. `./vendor/bin/pint --test app tests database routes config` → clean.
3. `npm run build` → ok.
4. `grep -rn "scroll-mt-20" resources CLAUDE.md` → nothing.
5. `git status --short` → only files this plan names (+ the staged archive).
6. Browser checks from steps 1, 2, 3, 5, 6 with real input; console clean.
   Nothing in this cycle writes to the dev DB.

## Risks

- **Step 6 touches ten views**: a missed `scroll-mt-20` leaves one element
  without a margin — the grep is the check.
- **Step 5's test needs 12 columns**: the sheet's milk/yoghurt columns are
  *all active* variants, so creating extra active milk variants in the test
  is enough; clean them up within the test's transaction.
- **Step 1 must not re-run `wheelStates()`** per row (it already ran for the
  strip); key `$wheelBreakdowns` by item id.

## Review

(Planner fills this in after reading implemented.md and the diff.)
