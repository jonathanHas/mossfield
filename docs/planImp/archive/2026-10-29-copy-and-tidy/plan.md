# Cycle 16 — Copy and tidy before the user guide

Status: ACCEPTED
Revision: 1
Planner: Fable 5.1
Date: 2026-10-29

Small by design: four one-word view edits, one button label, three test
assertions, four ticks in `SMALL_FIXES.md` and one dev-DB row. No PHP logic,
no routes, no migrations. **Parked cycle 12 (delete guards) is not in this
cycle — it becomes cycle 17** (see `RESUME.md`).

## Goal

Clear what is still open after cycles 14 and 15 so the user guide can be
written against screens that use one word for one thing. The order page is
the last screen that says "fulfilled" for the number every other screen calls
"picked" (finding `findings/2026-10-28-cycle-15-browser-checks.md`); the
factory dashboard promises "Continue picking" whether or not anything is in
progress; `SMALL_FIXES.md` still lists four data decisions as open though
they were applied in dev (cycle 15) and in production (Jon, 2026-10-28); and
the dev database still holds the deactivated `review_test` user from the
2026-10-28 review.

## Context

**Baseline.** `main` at `4f0591a`; `php artisan test` → **808 tests, 0
failed** (807 "deprecated" + 1 passed is the pre-existing
`PDO::MYSQL_ATTR_SSL_CA` notice, not failures); `./vendor/bin/pint --test app
tests database` clean. `docs/planImp/RESUME.md` is already modified in the
working tree (the Planner's record file) — not yours.

**1. "fulfilled" on the order page.** The four strings, line numbers
re-checked on 2026-10-29:

| File | Line | Renders when |
|---|---|---|
| `resources/views/orders/partials/allocation-items.blade.php` | 32 | settled-line summary tick (`@if($fulfilled)`), confirmed/preparing/ready |
| `resources/views/orders/partials/allocation-items.blade.php` | 81 | "Current allocations" header in the expanded panel — every open line (the panel is `x-show`, so it is in the HTML even when collapsed) |
| `resources/views/orders/show.blade.php` | 427 | read-only items table, fully picked line (pending/dispatched/delivered/cancelled) |
| `resources/views/orders/show.blade.php` | 435 | read-only items table, any other line |

All four read `{{ …->quantity_fulfilled }} of {{ …->quantity_ordered }} fulfilled`.
The word to use is **picked** — the stepper already says "N of M picked"
(`resources/views/orders/partials/status-stepper.blade.php:38`), the line
summary says "N allocated · N picked" (partial line 70), `/picking` says
"N of M lines picked". Line 432 ("Short N — X of Y supplied") is a different
sentence and stays.

**Why cycle 15's grep missed them.** Its completeness check was
`grep -i fulfil … | grep -vi quantity_fulfilled …` — it discarded every *line*
containing the identifier, and these four lines carry both the identifier and
the copy. The corrected grep matches the copy token itself: a `fulfil…` word
that is not preceded by an identifier character, `$`, `.`, `-` or `->`, and
not followed by an identifier character, `(` or `-`:

```bash
grep -rnP '(?<![\w$.-]|->)[Ff]ulfil(l|led|ment|ling|lment)?(?![\w(-])' resources/views --include='*.blade.php'
```

Run today it prints **12 lines**: the four above plus eight that stay. The
Planner's classification of the eight (the Implementer confirms each one):

| File:line | What it is | Decision |
|---|---|---|
| `layouts/navigation.blade.php:36` | PHP comment `// Fulfillment — picking + allocation` | leave (comment) |
| `layouts/navigation.blade.php:44` | `'label' => 'Fulfillment'` — the sidebar group name | **leave** (the brief keeps the nav label) |
| `components/order/item-row.blade.php:6` | Blade comment | leave |
| `orders/partials/allocation-items.blade.php:9` | Blade comment | leave |
| `orders/partials/allocation-items.blade.php:75–76` | Blade comment | leave |
| `orders/partials/allocation-items.blade.php:105` | `//` comment inside `@php` (not emitted) | leave |
| `orders/partials/allocation-items.blade.php:229` | `//` comment inside `@php` (not emitted) | leave |

Everything else the word appears in is an identifier the grep rightly skips
and the brief keeps as is: `quantity_fulfilled`, `isFullyFulfilled()`,
`weight_fulfilled_kg`, `fulfilled_at`, `fulfilled_total`, the route
`order-allocations.fulfill`, the form id `fulfil-{allocation}` and the
`form="fulfil-…"` inputs that point at it, `OrderPolicy::fulfill`,
`$fulfillPct`, `$fulfillItems`. None of those change.

**The test.** `tests/Feature/OrderShowAllocationInlineTest.php` already has
`makeOrder(string $status)` (one line of 5 × 1L Bottle, customer and batch
item in `setUp()`), and `test_ready_order_keeps_allocation_tables_with_undo`
shows how to make a line fully picked (update the item to allocated 5 /
fulfilled 5 and create an `OrderAllocation` 5/5 with `allocated_at` and
`fulfilled_at`). The show page tolerates a `dispatched` order with no
`dispatched_at` (every read of it is `@if`-guarded), so `makeOrder('dispatched')`
renders. After the fix the rendered page contains no " fulfilled" at all: the
remaining occurrences are Blade comments (stripped) or `//` comments inside
`@php` blocks (not emitted), and the identifiers never appear as a space-led
word. `assertDontSee(' fulfilled')` (default escaping — the needle has nothing
to escape) is therefore safe and bites only on copy.

**2. Dashboard button.** `resources/views/dashboard.blade.php:300`, inside
the `@else` branch for non-office users ("Today on the floor" card):
`<a href="{{ route('picking.index') }}" class="mf-btn-primary">Continue picking →</a>`.
The route closure for `dashboard` (`routes/web.php:32–116`) gives factory
users no picking-state data. `/picking` itself (`picking/index.blade.php:17,168`,
fixed in cycle 14 and pinned by `PickingTest` line 524) chooses "Start
picking" / "Continue picking" from the **selected day's** queue
(`$continueTo['state']`); the dashboard has no selected day, and a
dashboard-wide "any pick recorded on any open order" query would disagree
with the queue whenever a half-picked order sits on another day. **Decision:
a neutral label, "Picking queue →"**, matching the noun style of the three
secondary buttons beside it (Batches, Cheese cutting, Stock). No controller
change. `tests/Feature/UxCopyTest.php` is where dashboard copy is pinned
(its last test renders the dashboard as office); `FinanceNavTest` line 51
shows rendering it as factory (`User::factory()->factoryWorker()->create()`).

**2b. `/backup` table labels — already done, pin only.** Cycle 14 did this
(`TESTING_AND_FIXES.md` → 2026-10-28 cycle 14: "The `/backup` page labels
every table"): `resources/views/backup/index.blade.php:5–30` holds
`$tableLabels`, one friendly name for **all 25** entries of
`BackupService::TABLES` (`app/Services/BackupService.php:48`), rendered at
line 82 as `{{ $tableLabels[$table] ?? $table }}`. The restore flash names no
tables. What is missing is the rule: a table added to `TABLES` without a
label silently falls back to the raw name. One assertion in
`tests/Feature/BackupRestoreTest.php` (whose `test_admin_can_view_the_backup_page`
already renders the page as `$this->admin`) turns the state into a rule.

**Other "Could be prettier" items from the 2026-10-28 review** (archived at
`archive/2026-10-28-ux-review-part-2/UX_REVIEW_2026-10-28.md` line 292) were all
taken by cycle 14 — dialog bodies, "wheel(s)" grammar, "signed out
everywhere", `cut_date` min/max, the `/picking` footer — so nothing else from
that list is in this cycle.

**3. `SMALL_FIXES.md`.** The four data items are at lines 127, 132, 138 and
144 (`- [ ]`, each already carrying a `*Decision (Jon, 2026-10-28): …*`
line). The file's convention for a finished item is `- [x]` plus an italic
`*Done <date> …:* …` line after the body — see lines 25, 30, 49 for the
shape. The other two unticked items (line 100, variable-weight inputs; line
158, the sync message) stay open.

**4. Dev DB.** `users` id 9 is `review_test`, role factory, `is_active = 0`,
created 2026-10-28 11:06. Every FK onto `users` (`payments.recorded_by`,
`invoices.created_by`, `settings.updated_by`, `stock_write_offs.written_off_by`
/ `reversed_by`, `statements.generated_by`, `document_emails.sent_by`,
`credit_notes.created_by`) is `ON DELETE SET NULL` and **none references
id 9**; `sessions` has no row for it. A plain delete is clean. The other
users (ids 2–8) are the seeded accounts and Jon's three admins — untouched.
`.env` has `APP_ENV=local`.

## Constraints

- **Copy only.** No change to routes, form ids (`fulfil-{id}`), model or
  method names, `OrderPolicy::fulfill`, the `Fulfillment` sidebar group
  label, or any stock, money or status rule (`TESTING_AND_FIXES.md` →
  *Invariants worth keeping* is untouched by this cycle and must stay so).
- No migrations. No seeder changes.
- The dev-DB step runs on `mossfield_cl` only, through `php artisan tinker`,
  guarded by `app()->environment('local')`. Never in production.
- Tests first where a test is specified: write it, watch it fail, then make
  the change. Paste both outputs.
- Do not move `docs/planImp/findings/2026-10-28-cycle-15-browser-checks.md`
  — the Planner moves it into this cycle's archive folder on acceptance.
- Do not edit `plan.md`, `RESUME.md`, `TESTING_AND_FIXES.md` or anything
  under `parked/`.
- Do not commit, push or deploy.

## Out of scope

- Delete guards (parked cycle 12 → cycle 17).
- The `MOSSORDERS_UI_ENABLED` decision; the dashboard sync "never completed"
  message (`SMALL_FIXES.md` line 158).
- "Email invoice from the order page" (2026-10-13 review finding 4, deferred).
- The dev-box PCRE JIT fault (Jon fixes it in `php.ini`).
- The two cancelled probe orders ORD-20261013-002 / -003 — history, kept.
- Renaming identifiers that contain "fulfil" (variables, columns, routes).
- Any other string the grep shows that is a comment or the nav label.

## Steps

### 1. Baseline
Files: `docs/planImp/implemented.md`
What: record `git rev-parse --short HEAD` (expect `4f0591a`) and
`git status --short` (expect only `M docs/planImp/RESUME.md` and
`?? docs/planImp/plan.md`-style entries that are the Planner's), then run
`php artisan test`.
Check: 808 tests, 0 failed. Paste the `Tests:` line.

### 2. Pin "picked" on the order page (test first)
Files: `tests/Feature/OrderShowAllocationInlineTest.php`
What: add one test, plus a small private helper that marks the single line
fully picked the way `test_ready_order_keeps_allocation_tables_with_undo`
does (item `quantity_allocated` 5 / `quantity_fulfilled` 5, one
`OrderAllocation` 5/5 with `allocated_at` and `fulfilled_at`). Intent:

```php
public function test_the_order_page_says_picked_never_fulfilled(): void
{
    $office = User::factory()->create();

    $confirmed = $this->makeOrder('confirmed');   // partial line 81: "0 of 5 picked"
    $pending = $this->makeOrder('pending');       // show line 435:  "0 of 5 picked"
    $ready = $this->makeOrder('ready');           // partial line 32: "5 of 5 picked"
    $this->pickWholeLine($ready);
    $dispatched = $this->makeOrder('dispatched'); // show line 427:  "5 of 5 picked"
    $this->pickWholeLine($dispatched);

    foreach ([$confirmed, $pending, $ready, $dispatched] as $order) {
        $this->actingAs($office)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('of 5 picked')
            ->assertDontSee(' fulfilled');
    }
}
```
Check: `php artisan test --filter=OrderShowAllocationInlineTest` → the new
test **fails** on `assertDontSee(' fulfilled')` for the confirmed order (the
first in the loop); the other six tests in the file still pass. Paste the
failure line.

### 3. Change the four strings
Files: `resources/views/orders/partials/allocation-items.blade.php` (lines
32, 81), `resources/views/orders/show.blade.php` (lines 427, 435)
What: in each, the trailing word `fulfilled` becomes `picked`. Nothing else
on those lines changes (the `{{ }}` expressions, classes and styles stay).
Check: `php artisan test --filter=OrderShowAllocationInlineTest` → 7 passed.
`git diff --stat` shows exactly those two view files, 4 lines each side.

### 4. Completeness grep, line by line
Files: none (a check)
What: run the corrected grep from *Context* over `resources/views` and
classify every hit. Expected: **8 lines**, all in the table above (two
`navigation.blade.php`, one `item-row.blade.php`, five
`allocation-items.blade.php`), none user-visible copy; lines 32/81/427/435
no longer appear. If a hit is not in the table, decide it the same way —
rendered text is copy (fix it, add it to the table in `implemented.md`);
a comment, identifier, form id, route or the "Fulfillment" nav label is left.
Also run the brief's wider net to show nothing hides behind a capital or a
tag: `grep -rn '>[Ff]ulfil\|[Ff]ulfil[a-z]*<' resources/views --include='*.blade.php'`
→ expected empty.
Check: paste the grep output and the 8-row classification into
`implemented.md`.

### 5. Pin the factory dashboard button (test first)
Files: `tests/Feature/UxCopyTest.php`
What: add a test that renders `route('dashboard')` as
`User::factory()->factoryWorker()->create()` and asserts
`assertSee('Today on the floor')`, `assertSee('Picking queue')`,
`assertDontSee('Continue picking')`; then renders it as an office user
(`User::factory()->create()`) and asserts `assertDontSee('Picking queue')`
(office gets the order queue, not the card).
Check: `php artisan test --filter=UxCopyTest` → the new test fails on
`assertSee('Picking queue')`; paste the line.

### 6. Change the button
Files: `resources/views/dashboard.blade.php` (line 300)
What: `Continue picking →` becomes `Picking queue →`. Only the label.
Check: `php artisan test --filter=UxCopyTest` → all pass.
`grep -rn 'Continue picking' resources/views` → only
`picking/index.blade.php:168` (the per-day footer, which is correct).

### 7. Pin "every backup table has a label"
Files: `tests/Feature/BackupRestoreTest.php`
What: extend `test_admin_can_view_the_backup_page` (or add a sibling) so
that, on the rendered `/backup` page, for every `$table` in
`\App\Services\BackupService::TABLES` the raw name never appears as a cell:
`->assertDontSee('>'.$table.'<', false)`; and two friendly names do:
`assertSee('Stock write-offs')`, `assertSee('Documents sent')`.
Check: `php artisan test --filter=BackupRestoreTest` → passes. Then prove it
bites: temporarily delete the `'stock_write_offs' => 'Stock write-offs',`
line from `resources/views/backup/index.blade.php`, rerun → fails on
`>stock_write_offs<`; restore the line (`git diff` shows no change to the
view), rerun → passes. Paste both.

### 8. Tick the four data items in `SMALL_FIXES.md`
Files: `docs/planImp/SMALL_FIXES.md`
What: lines 127, 132, 138, 144 — `- [ ]` → `- [x]`, and after each item's
existing `*Decision …*` line add one italic line in the file's convention:
`*Done 2026-10-28 (cycle 15): dev by tinker; production by Jon in the app.*`
(the Mature item also keeps its note about the seeder's 2.300 kg).
Check: `grep -n '^- \[ \]' docs/planImp/SMALL_FIXES.md` lists exactly two
lines (the variable-weight inputs item at ~100 and the sync item at ~158);
`grep -c 'Done 2026-10-28 (cycle 15)' docs/planImp/SMALL_FIXES.md` → 4.

### 9. Delete the dev `review_test` user
Files: none (dev database `mossfield_cl` only)
What: in `php artisan tinker`, guarded:

```php
abort_unless(app()->environment('local'), 500, 'dev only');
$u = \App\Models\User::where('id', 9)->where('username', 'review_test')->where('is_active', false)->first();
$u ? $u->delete() : 'not found — stop';
```
Do not touch any other row. Orders ORD-20261013-002 / -003 stay.
Check: `\App\Models\User::find(9)` → `null`; `\App\Models\User::count()` → 7;
`\App\Models\User::pluck('username')` lists `office_test, factory_test,
driver_test, jon, Bibi, lorraine, admin`. Paste the output.

## Verification

1. `php artisan test` → **811 tests, 0 failed** (808 + the three new tests).
2. `./vendor/bin/pint --test app tests database` → clean.
3. The grep from step 4 → 8 lines, none copy; the wider net → empty.
4. `git status --short` → modified: the two order views, `dashboard.blade.php`,
   the three test files, `SMALL_FIXES.md` (plus the Planner's `RESUME.md`
   and `plan.md`/`implemented.md`). Nothing else.
5. Browser (Jon, after acceptance, as `office_test`): `/orders/3`
   (ORD-20260710-001, confirmed) reads "0 of 23 picked" under Current
   allocations and "0 allocated · 0 picked" on every line; `/orders/4`
   (ORD-20260723-001, ready) reads "✓ 20 of 20 picked". As `factory_test`:
   the dashboard card's button reads "Picking queue →" and opens `/picking`.
   `/users` no longer lists `review_test`.

## Risks

- `assertDontSee(' fulfilled')` is a page-wide net: if a flash or another
  partial on the order page ever prints the word, the test says so — that is
  the point, but the Implementer should read the failure before "fixing" it.
- The `Fulfillment` sidebar label is user-visible and deliberately kept; a
  future copy pass may want it to say "Picking". Not this cycle.
- Step 7's temporary view mutation must be reverted before the final
  `git status`; the check requires `git diff` to be clean for that file.
- Step 9 is irreversible on the dev DB; the guard on `username` and
  `is_active` means a mistyped id deletes nothing.

## Review

Planner, 2026-10-29. Read `implemented.md` to the end and the full
`git diff`; re-ran every check myself.

**Criteria**

- Step 1 baseline — pass (808, 0 failed; HEAD `4f0591a`; dirty files as expected).
- Step 2 test first — pass. New test + `pickWholeLine()` helper match the
  plan's snippet; the paste shows it failing on the confirmed order before
  the view change.
- Step 3 four strings — pass. `git diff` on the two views is exactly four
  `fulfilled` → `picked` substitutions, nothing else on the lines.
  `OrderShowAllocationInlineTest` 7 passed.
- Step 4 grep — pass. Re-run: 8 lines, the same eight as the plan's table,
  each confirmed in context by the Implementer; the wider net is empty.
- Step 5/6 dashboard — pass. Label is `Picking queue →`; `UxCopyTest` 9
  passed; "Continue picking" survives only in `picking/index.blade.php:168`.
- Step 7 backup pin — pass. Assertion loops `BackupService::TABLES`; the
  mutation check was done and the view is unchanged (not in `git status`).
- Step 8 `SMALL_FIXES.md` — pass. Four `[x]` with the Done line; two items
  remain open (100, 162).
- Step 9 dev DB — pass. `User::find(9)` null, count 7, the two probe orders
  still cancelled and present.
- Verification — `php artisan test` **810 tests, 0 failed** (re-run);
  `pint --test` exit 0; `git status` holds only the listed files.

**Deviations**

1. Failure point on `assertSee` rather than `assertDontSee` — accepted; same
   order, same cause, the test is as written.
2. `'Picking queue'` → `'Picking queue →'` — accepted, and the right call:
   the card's body sentence already contained the shorter needle, so the
   plan's assertion would have passed before the fix. The office
   `assertDontSee('Picking queue')` is unchanged and still meaningful.
3. 810 not 811 — accepted; the plan offered "extend or add a sibling" and
   the extension is the better shape (one page render, one test).

**Notes for Planner**

- Copy assertions passing for the wrong reason — accepted as guidance;
  recorded in `TESTING_AND_FIXES.md` as a trap for future plans (assert a
  label with its glyph or its tag, and prove the failing state first).
- The `Fulfillment` sidebar label — deferred to the user-guide copy pass;
  added as an open item in `SMALL_FIXES.md` so it is not lost.
- `SMALL_FIXES.md` line numbers moved — no action (they are cited nowhere
  that matters).

Nothing to redo. Accepted at revision 1.
