# Polish: the follow-ups from cycles 6–8 — implementation

Status: DONE
Plan revision: 1
Implementer: Opus
Date: 2026-10-06

## Baseline
HEAD: 7aadfd1 (the plan's Context says `ebc1c7c` + a staged archive; that archive has since been committed as `7aadfd1`)
Pre-existing dirty files: clean
```
$ php artisan test
  Tests:    674 passed (4288 assertions)
$ ./vendor/bin/pint --test app tests database routes config
(clean — no output)
```

## Steps

### 1. The card's items table shows sold, cut and converted separately — done
Changed:
- `resources/views/batches/partials/batch-card.blade.php`:
  - `$wheelBreakdowns` is now `mapWithKeys` by item id (the strip iterates
    it unchanged).
  - The items table's Sold cell (`data-sold-cell`) reads
    `$wheelBreakdowns->get($item->id)['sold']` for a wheel item, plus a muted
    "· N cut · N converted" (zero parts dropped). Every other item keeps
    `quantity_sold`.
  - `wheelStates()` runs once per wheel item, as before. The header stays
    "Sold".
- `tests/Feature/ConsolidationPassTest.php` +1, on the existing
  `workedBatch()` (2 sold · 1 cut · 1 converted):
  - `quantity_sold` is 4;
  - the card's Sold cells contain "2 · 1 cut · 1 converted";
  - no cell reads "4";
  - the pack row still reads "0".
  The plan's example was 1 cut / 2 converted / 2 sold; I used the fixture
  that already exists, which shows the same thing.

Red check (the new tests on HEAD's card, then restored):
```
  ⨯ the batch cards items table splits sold from cut and converted
  ⨯ a fully written off expired card says so instead of linking
  Tests:    2 failed (10 assertions)
(restored)  Tests:    2 passed (15 assertions)
```
Check output:
```
$ php artisan test --filter="StockWriteOffTest|ConsolidationPassTest"
  Tests:    34 passed (218 assertions)
```

### 2. A fully written-off expired batch says so — done
Changed:
- `batch-card.blade.php`, in the expired block: when `writableUnits() === 0`
  and the loaded items' `written_off_units` sum is > 0, a neutral tag
  **"Written off"** (`data-written-off-tag`).
  - It sits outside the `@can` so factory sees it too: it's status, not an
    action.
  - The link condition is unchanged, so the two never show together.
- `tests/Feature/StockWriteOffTest.php` +1:
  - an expired batch written off through the one-click route shows the tag
    and no `#write-offs` link;
  - an expired batch with stock left shows the link and no tag.
Check: as step 1 (34 passed); red check above.

### 3. The order page tells the truth about a ready order's bill — done
Changed:
- `resources/views/orders/show.blade.php`: a new branch before
  `hasReachedReady()`. For `status === 'ready'` it shows "Awaiting dispatch —
  invoiced when dispatched" (muted, no link). Dispatched/delivered uninvoiced
  orders keep "Awaiting invoice · Finance". The invoiced and consolidated
  branches above are untouched.
- `tests/Feature/OrderTransitionsTest.php` +1:
  - a ready order shows the new text and not "Awaiting invoice";
  - a dispatched uninvoiced order shows "Awaiting invoice" and not the new
    text.
Red check (on HEAD's view): `⨯ a ready orders billing line says awaiting dispatch — 1 failed`.
Check output:
```
$ php artisan test --filter=OrderTransitionsTest
  Tests:    24 passed (102 assertions)
```

### 4. `issue()` refuses to a known page — done
Changed:
- `app/Http/Controllers/Invoicing/UninvoicedOrderController.php`: the
  controller had two `back()` refusals:
  - `issue()`'s `InvoicingException` catch;
  - `issueAll()`'s "Nothing to invoice." branch.
  Both now `redirect()->route('invoicing.uninvoiced.index')`. No other
  `back()` remains in the controller.
- `tests/Feature/InvoiceIssueTest.php` +1:
  `test_issue_refusals_land_on_the_queue_not_back` posts both refusals with
  no prior GET and asserts the redirect target and flash.
  - It is a separate test because in the cycle-8 queue test a GET of the
    queue page sets the session's previous URL, so `back()` happened to land
    on the same page. That first red check passed on HEAD, which is why the
    assertion moved.
  - The red check on HEAD's controller now fails as it should: "Failed
    asserting that two strings are equal."
Check output:
```
$ php artisan test --filter="InvoiceIssueTest|InvoiceNumberingTest"
  Tests:    25 passed (91 assertions)
```

### 5. The sticky sheet header follows the column count — done
Changed:
- `resources/views/chilled-runs/partials/_run-table.blade.php`:
  `$stickyHead` is now a `match`:
  - editing → `''`;
  - `$qtyColCount <= 11` → `xl:overflow-visible`;
  - `<= 15` → `2xl:overflow-visible`;
  - else `''` (always scrolls).
  The comment carries the arithmetic. **The 2xl limit is 15, not the plan's
  20** — see Deviations.
- `tests/Feature/ChilledRunTest.php` +4, through a helper that builds N
  active milk columns and reads the wrapper's classes:
  - 11 → `xl:overflow-visible`;
  - 12 → `2xl:overflow-visible` (and so not `xl:`);
  - 16 → none;
  - editing (3 columns) → none.
- `npm run build`: the built CSS contains
  `2xl\:overflow-visible{overflow:visible}`.

Red check (on HEAD's partial): the 11 and editing cases pass, as they
should; 12 and 16 fail.
```
  ✓ the sticky header follows the column count
  ⨯ twelve columns wait for 2xl before sticking
  ⨯ a very wide sheet always scrolls
  ✓ an editing row always scrolls
```
Check output:
```
$ php artisan test --filter=ChilledRunTest
  Tests:    70 passed (280 assertions)
```
Browser: see *Browser checks*.

### 6. Fragment landings clear the flash by its real height — done
Changed:

`resources/css/app.css`
- New `.mf-landing { scroll-margin-top: calc(var(--mf-feedback-h, 0px) + 1rem); }`.
- The `:target` tint moved from `.scroll-mt-20:target` to
  `.mf-landing:target`; the old rule is deleted.
- The sheet rows' larger rule (flash + both sticky header rows) is now
  `.mf-run tbody tr.mf-landing` (was `tr[id^="stop-"]`). It is more specific
  than `.mf-landing`, so one class serves both contexts.

Views — every `scroll-mt-20` became `mf-landing`, 10 elements in 6 files:
- `orders/show.blade.php` ×3: `#dispatch`, `#order-items`, the read-only
  line rows;
- `orders/partials/allocation-items.blade.php` ×1: the allocation line rows;
- `chilled-runs/partials/_run-table.blade.php` ×2: the editing and display
  rows;
- `delivery-runs/index.blade.php` ×2: `#run-{id}`, `#stop-{id}`;
- `customers/show.blade.php` ×1: `#special-prices`;
- `batches/partials/_write-offs.blade.php` ×1: `#write-offs`.

`grep -rn "scroll-mt-20" resources` → nothing.

Tests
- `OrderItemEditTest`: the existing anchor test also asserts that the
  `#line-{id}` and `#order-items` tags carry `mf-landing`.
- `ChilledRunTest` +1: a sheet row's class carries `mf-landing`.
- `LayoutFeedbackTest` +1:
  - `app.css` has the `.mf-landing` rule with `--mf-feedback-h` and the
    `:target` rule, and no `scroll-mt-20`;
  - no file under `resources/views` contains `scroll-mt-20` (the plan's grep,
    as a test).

Red check (the 7 files reverted to HEAD, then restored):
```
  ⨯ sheet rows are landings
  ⨯ fragment landings clear the flash by its real height
  ⨯ line actions land on the line and the page carries its anchor
  Tests:    3 failed, 12 passed (68 assertions)
```
Check output:
```
$ npm run build            ✓ built in 1.74s
$ php artisan test --filter="OrderItemEditTest|LayoutFeedbackTest|ChilledRunTest"
  Tests:    91 passed (388 assertions)
```
Browser: see *Browser checks*.

### 7. Docs — done
Changed:

`CLAUDE.md`
- Redirect convention: every landing element carries `mf-landing` (the
  rule, the run-sheet override, and that `LayoutFeedbackTest` guards it).
- *Feedback, fallbacks…*: the `:target` tint is `.mf-landing:target`.
- Chilled Run Sheet → Sticky header: keyed on the column count (≤ 11 → xl,
  ≤ 15 → 2xl, more → never).
- Invoicing: the billing line's states, now with "Awaiting dispatch —
  invoiced when dispatched" for `ready`.

`docs/planImp/WORK_IN_PROGRESS.md`
- *Recently Completed: Polish (2026-10-06)*, Last Updated bumped.

Check:
```
$ grep -c "mf-landing" CLAUDE.md        → 2 lines
$ grep -rn "scroll-mt-20" CLAUDE.md     → nothing
```
(My first draft named the old class in the `LayoutFeedbackTest` sentence and
tripped the second check; it now says "the old fixed 80 px Tailwind margin
class".)

## Browser checks
`http://mossfield.local`, admin, real data. Console clean (no errors) on
`/chilled-runs` and `/orders/14`.

**Step 1**
- `/batches?type=cheese`, G010426's strip reads "6 free · 1 maturing · 1
  cut · 2 converted · 2 sold / 12".
- Its items table Whole Wheel Sold cell now reads **"2 · 1 cut · 2
  converted"**; it would have been 5. The Vacuum Pack row reads "0".
- G030426 reads "4 · 4 cut · 4 converted" and its pack row "6"; G010426-1
  reads "1".
- Screenshot looked at: the suffix is muted after the figure; the column
  header is still "Sold".

**Step 2**
- `/batches?status=expired`: **M230726** (written off on 2026-10-04) shows
  "Expired / Written off" and no link.
- M070926, M230726-1, M220626 and Y220626 still show "Write off →".

**Step 3**
- `/orders/3` (ORD-20260710-001, ready) → Invoice: "Awaiting dispatch —
  invoiced when dispatched".
- `/orders/1` (dispatched, uninvoiced) → "Awaiting invoice · Finance".

**Step 5**
- A 1280 px *window* gives a 1248 px viewport (browser chrome), which is
  below `xl`: the wrapper correctly scrolled (`overflow-x: auto`).
- Resized so the **viewport** is 1280: Dublin Tue run, w/c 06/10 (the 11
  default columns):
  - the wrapper is `overflow-x-auto xl:overflow-visible`, computed `visible`;
  - the table and wrapper are both 983 px, and the page's `scrollWidth` is
    1265 (no horizontal scroll);
  - scrolled 120 px into the table, the `<thead>` stays at `top: 0`. Sticky.

**Step 6**
- On `/orders/14` (ORD-20261004-003), line 46 (1L × 12) has class
  `… mf-landing`.
- I clicked its **Update** with the quantity unchanged at 12. The server
  answered "No change to quantity." and redirected to `#line-46`:
  - flash bottom 70 px, `--mf-feedback-h` 70px, **line top 86 px** — exactly
    under the flash + 1rem.
- Dismissed with ×, which set `--mf-feedback-h` to 0px.
  - **Reloading** just restored the browser's saved scroll (Chrome's scroll
    restoration ignores the fragment on reload).
  - A **fresh navigation** to `/orders/14#line-46` lands the line at **16 px**
    (no flash); the old class gave 80 px.
- A run-sheet row (`#stop-27`) has class `mf-landing` and computed
  `scroll-margin-top` 80px (the larger sheet rule with no flash), so the
  override wins.

## Dev-DB state
Nothing written:
- order item 46 is still qty 12, and its `updated_at` (2026-10-04 14:29:37)
  and order 14's `updated_at` are unchanged — the no-op update wrote nothing;
- the latest order is still id 26 and the latest invoice still INV-000008.

## Deviations
1. **Step 5 — the 2xl limit is 15 columns, not 20.** The plan's own
   measurements give 15:
   - 11 columns = 983 px, so the non-quantity columns take ~323 px;
   - at 1536 the panel is ~985 + 256 ≈ 1241 px;
   - (1241 − 323) / 60 ≈ 15.3.
   With `2xl:overflow-visible` at 16–20 columns, the wrapper would stop
   scrolling while the table is wider than its panel: the table would spill
   out of the panel and give the page a horizontal scroll. That's worse than
   a header that scrolls away. The plan's tests are unaffected (12 → 2xl).
   I added a 16-column → `''` case. Not verified in a 1536 px browser, since
   dev has 11 columns.
2. **Step 1 — the test uses the existing `workedBatch()` fixture** (2 sold ·
   1 cut · 1 converted) rather than the plan's 1 cut / 2 converted / 2 sold.
   Same property: the cell reads the split, not `quantity_sold` (4). The dev
   browser check covered the plan's exact shape (G010426 is 1 cut · 2
   converted · 2 sold).
3. **Step 4 — a separate test, not an assertion on the existing refusal
   case.** The existing case GETs the queue page first, so the session's
   previous URL made `back()` land on the same page, and the assertion
   passed on HEAD (red check failed to fail). The new test posts with no
   prior GET.
4. **One assertion outside the plan's files changed wording:**
   `DispatchConfirmationTest::test_the_order_page_shows_one_billing_line_and_no_invoice_buttons`
   asserted "Awaiting invoice" on a **ready** order — exactly the text step
   3 replaces. It now asserts "Awaiting dispatch — invoiced when
   dispatched", and after dispatch asserts that neither text shows. This was
   the only failure in the first full run (683 passed, 1 failed).

## Verification
1. `php artisan test` → **684 passed (4333 assertions), 0 failed** (62.4 s).
   Baseline 674 → +10 tests:
   - Consolidation +1;
   - StockWriteOff +1;
   - OrderTransitions +1;
   - InvoiceIssue +1;
   - ChilledRun +5 (4 sticky + 1 landing);
   - LayoutFeedback +1.
   The OrderItemEdit assertions were added to an existing test.
2. `./vendor/bin/pint --test app tests database routes config` → clean.
3. `npm run build` → ✓ built in 2.43 s; the CSS contains
   `2xl\:overflow-visible` and `.mf-landing`.
4. `grep -rn "scroll-mt-20" resources CLAUDE.md` → nothing.
5. `git status --short` → the files below, plus `implemented.md`. The
   staged archive is already committed as 7aadfd1.
6. Browser: steps 1, 2, 3, 5 and 6 above; the console was clean; no dev-DB
   writes.

**Nothing committed, pushed or deployed.**

## Files changed
App:
- `app/Http/Controllers/Invoicing/UninvoicedOrderController.php`
- `resources/css/app.css`

Views:
- `resources/views/batches/partials/{batch-card,_write-offs}.blade.php`
- `resources/views/orders/show.blade.php`
- `resources/views/orders/partials/allocation-items.blade.php`
- `resources/views/chilled-runs/partials/_run-table.blade.php`
- `resources/views/delivery-runs/index.blade.php`
- `resources/views/customers/show.blade.php`

Tests:
- `tests/Feature/{ConsolidationPass,StockWriteOff,OrderTransitions,InvoiceIssue,ChilledRun,OrderItemEdit,LayoutFeedback,DispatchConfirmation}Test.php`

Docs:
- `CLAUDE.md`, `docs/planImp/WORK_IN_PROGRESS.md`, this file.

## Notes for Planner
- **Step 5 limit.** If you want 20 at 2xl, the sheet would need its own
  horizontal scroller that doesn't break `position: sticky` (e.g. a JS-synced
  header clone). That's more than polish. 15 keeps the "never spill the
  panel" invariant.
- **Refusal tests and `back()`.** Any test that GETs a page before posting a
  refusal can't tell `back()` from an explicit route: the session's previous
  URL hides the difference. Worth knowing for future refusal tests.
- **The `/cheese-cutting` card** (`cheese-cutting/partials/batch-card`) was
  checked: it has no Sold column, so step 1 doesn't apply there.
- **Reload vs fragment:** Chrome restores the saved scroll on reload rather
  than re-jumping to `#…`, so step 6's "reload with the fragment" only shows
  the new margin on a fresh navigation. Not something the app can change.
