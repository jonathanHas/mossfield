# Cycle 16 — Copy and tidy before the user guide — implementation

Status: DONE
Plan revision: 1
Implementer: Opus
Date: 2026-10-29

## Baseline
HEAD: 4f0591a
Pre-existing dirty files:
```
 M docs/planImp/RESUME.md
?? docs/planImp/plan.md
```
(`implemented.md` is this file, new.)

## Steps
### 1. Baseline — done
`php artisan test`:
```
  Tests:    807 deprecated, 1 passed (5139 assertions)
```
808 tests, 0 failed ("deprecated" = the pre-existing `PDO::MYSQL_ATTR_SSL_CA` notice).

### 2. Pin "picked" on the order page (test first) — done
Changed: `tests/Feature/OrderShowAllocationInlineTest.php` — new private `pickWholeLine(Order)` (item 5/5, one 5/5 `OrderAllocation` with both timestamps, as in `test_ready_order_keeps_allocation_tables_with_undo`) and `test_the_order_page_says_picked_never_fulfilled` exactly as the plan's snippet.
Check output (before the view change):
```
  ⨯ the order page says picked never fulfilled                           0.05s
   FAILED  Tests\Feature\OrderShowAllocationInlineTest > the order page says…
  To contain: of 5 picked
  at tests/Feature/OrderShowAllocationInlineTest.php:251
  ➜ 251▕                 ->assertSee('of 5 picked')
```
Fails on the confirmed order (first in the loop); the other six tests pass. It fails on `assertSee('of 5 picked')` rather than `assertDontSee(' fulfilled')` — see Deviations.

### 3. Change the four strings — done
Changed: `resources/views/orders/partials/allocation-items.blade.php` lines 32, 81; `resources/views/orders/show.blade.php` lines 427, 435 — trailing `fulfilled` → `picked`, nothing else on the line.
```
32:  {{ $orderItem->quantity_fulfilled }} of {{ $orderItem->quantity_ordered }} picked
81:  <span class="text-[12px] font-mono" style="color: var(--ink-2);">{{ $orderItem->quantity_fulfilled }} of {{ $orderItem->quantity_ordered }} picked</span>
427: {{ $item->quantity_fulfilled }} of {{ $item->quantity_ordered }} picked
435: <span class="font-mono">{{ $item->quantity_fulfilled }} of {{ $item->quantity_ordered }} picked</span>
```
`php artisan test --filter=OrderShowAllocationInlineTest`:
```
  Tests:    7 deprecated (35 assertions)
```
`git diff --stat` (RESUME.md is the Planner's pre-existing change):
```
 docs/planImp/RESUME.md                             | 48 ++++++++++++++--------
 .../orders/partials/allocation-items.blade.php     |  4 +-
 resources/views/orders/show.blade.php              |  4 +-
 tests/Feature/OrderShowAllocationInlineTest.php    | 38 +++++++++++++++++
```

### 4. Completeness grep, line by line — done
```
resources/views/components/order/item-row.blade.php:6:    slot (the fulfilment panel) spanning the full row beneath.
resources/views/layouts/navigation.blade.php:36:    // Fulfillment — picking + allocation
resources/views/layouts/navigation.blade.php:44:    if ($fulfillItems) $groups[] = ['label' => 'Fulfillment', 'items' => $fulfillItems];
resources/views/orders/partials/allocation-items.blade.php:9:    fulfilment panel (the only "card" on the page) is reserved for lines still
resources/views/orders/partials/allocation-items.blade.php:75:        {{-- Expanded fulfilment panel (collapsed by default for settled lines —
resources/views/orders/partials/allocation-items.blade.php:76:             fully fulfilled, or closed short). --}}
resources/views/orders/partials/allocation-items.blade.php:105:                                        // Fulfil asks first (F-21): Enter in the wrong box used to record a pick.
resources/views/orders/partials/allocation-items.blade.php:229:                                                // Batch, Production, Allocated, Fulfilled + Weight (variable) + Action (can update).
--- count: 8
```
Wider net (`'>[Ff]ulfil\|[Ff]ulfil[a-z]*<'`): empty (grep exit 1).

| File:line | What it is (confirmed by reading context) | Decision |
|---|---|---|
| `components/order/item-row.blade.php:6` | inside the `{{-- … --}}` block opening at line 2 | leave |
| `layouts/navigation.blade.php:36` | PHP `//` comment | leave |
| `layouts/navigation.blade.php:44` | `'Fulfillment'` sidebar group label | leave (kept by the brief) |
| `orders/partials/allocation-items.blade.php:9` | inside the `{{-- … --}}` header (lines 1–12) | leave |
| `orders/partials/allocation-items.blade.php:75` | Blade comment | leave |
| `orders/partials/allocation-items.blade.php:76` | same Blade comment | leave |
| `orders/partials/allocation-items.blade.php:105` | `//` inside `@php` | leave |
| `orders/partials/allocation-items.blade.php:229` | `//` inside `@php` | leave |

No hit outside the plan's table; 32/81/427/435 gone.

### 5. Pin the factory dashboard button (test first) — done (one assertion tightened)
Changed: `tests/Feature/UxCopyTest.php` — `test_factory_dashboard_button_names_the_picking_queue`: factory → `assertSee('Today on the floor')`, `assertSee('Picking queue →')`, `assertDontSee('Continue picking')`; office → `assertDontSee('Picking queue')`.
First run with the plan's `assertSee('Picking queue')` did **not** fail there — it passed and the test failed one step later:
```
  Not to contain: Continue picking
  ➜ 212▕             ->assertDontSee('Continue picking');
```
because the card's body copy (`dashboard.blade.php:296`) already reads "Pick today's orders from the Picking queue." — so the plan's needle can't tell the button from the sentence. Tightened to the button label `'Picking queue →'` (see Deviations). Re-run before the view change:
```
  To contain: Picking queue →
  ➜ 211▕             ->assertSee('Picking queue →')
  Tests:    1 failed (3 assertions)
```

### 6. Change the button — done
Changed: `resources/views/dashboard.blade.php:300` — `Continue picking →` → `Picking queue →`, label only.
```
  Tests:    9 deprecated (52 assertions)        # --filter=UxCopyTest, all pass
$ grep -rn 'Continue picking' resources/views
resources/views/picking/index.blade.php:168:                {{ $continueTo['state'] === 'todo' ? 'Start picking' : 'Continue picking' }}
```

### 7. Pin "every backup table has a label" — done
Changed: `tests/Feature/BackupRestoreTest.php` — extended `test_admin_can_view_the_backup_page`: for each `BackupService::TABLES` entry `assertDontSee('>'.$table.'<', false)`, plus `assertSee('Stock write-offs')->assertSee('Documents sent')`. (`BackupService` was already imported.)
```
  Tests:    19 deprecated (126 assertions)          # --filter=BackupRestoreTest, passes
# temporarily deleted the 'stock_write_offs' => 'Stock write-offs', line from backup/index.blade.php:
  Not to contain: >stock_write_offs<
  ➜ 105▕             $response->assertDontSee('>'.$table.'<', false);
  Tests:    1 failed (14 assertions)
# git checkout -- resources/views/backup/index.blade.php
$ git diff --stat resources/views/backup/index.blade.php
(empty)
  Tests:    19 deprecated (126 assertions)          # passes again
```

### 8. Tick the four data items in `SMALL_FIXES.md` — done
Changed: `docs/planImp/SMALL_FIXES.md` — Avoca, 16L bucket, Garlic & Basil, Mature: `- [ ]` → `- [x]`, and after each `*Decision …*` line `*Done 2026-10-28 (cycle 15): dev by tinker; production by Jon in the app.*` (the Mature item's 2.300 kg seeder note is kept — it is part of its Decision line).
```
$ grep -n '^- \[ \]' docs/planImp/SMALL_FIXES.md
100:- [ ] **Variable-weight fulfilment renders one kg input per unit.** A 31-wheel
162:- [ ] **Dashboard reports the online order sync has never completed successfully.**
$ grep -c 'Done 2026-10-28 (cycle 15)' docs/planImp/SMALL_FIXES.md
4
```
(The sync item is now at 162, not ~158 — the four added lines sit above it.)

### 9. Delete the dev `review_test` user — done
Looked first (`.env`: `APP_ENV=local`, `DB_DATABASE=mossfield_cl`):
```
local mossfield_cl
{"id":9,"username":"review_test","role":"factory","is_active":false,"created_at":"2026-10-28T11:06:33.000000Z"}
sessions for 9: 0
payments.recorded_by SET NULL refs9=0
invoices.created_by SET NULL refs9=0
settings.updated_by SET NULL refs9=0
stock_write_offs.reversed_by SET NULL refs9=0
stock_write_offs.written_off_by SET NULL refs9=0
statements.generated_by SET NULL refs9=0
document_emails.sent_by SET NULL refs9=0
credit_notes.created_by SET NULL refs9=0
```
Then the plan's guarded tinker delete:
```
deleted: true
NULL                     # User::find(9)
7                        # User::count()
["admin","Bibi","driver_test","factory_test","jon","lorraine","office_test"]
```
No other row touched; ORD-20261013-002 / -003 untouched.

## Deviations
1. **Step 2's failure point.** The plan predicted the new test would fail on `assertDontSee(' fulfilled')`. It fails one assertion earlier, on `assertSee('of 5 picked')`, because that assertion comes first in the chain. It is the same order (confirmed, first in the loop) and the same cause. The test is as written in the plan.
2. **Step 5's needle tightened from `'Picking queue'` to `'Picking queue →'`.** The card's body copy at `dashboard.blade.php:296` already says "…from the Picking queue.", so the plan's `assertSee('Picking queue')` passed before the fix and did not pin the button. The arrow makes it match only the button label. The office assertion `assertDontSee('Picking queue')` stays as planned: it holds because office doesn't render the card at all.
3. **Test count is 810, not 811.** Step 7 said "extend `test_admin_can_view_the_backup_page` (or add a sibling)". I extended it, so it adds assertions, not a test: 808 + 2 new tests = 810.

## Verification
1. `php artisan test`:
   ```
     Tests:    809 deprecated, 1 passed (5184 assertions)
   ```
   810 tests, 0 failed (see Deviation 3 for 810 vs 811).
2. `./vendor/bin/pint --test app tests database`: `PASS ... 304 files`, exit 0.
3. The step 4 grep gives 8 lines, all comments or the kept nav label (table under step 4). The wider net is empty.
4. `git status --short`:
   ```
    M docs/planImp/RESUME.md                                   # pre-existing (Planner)
    M docs/planImp/SMALL_FIXES.md
    M resources/views/dashboard.blade.php
    M resources/views/orders/partials/allocation-items.blade.php
    M resources/views/orders/show.blade.php
    M tests/Feature/BackupRestoreTest.php
    M tests/Feature/OrderShowAllocationInlineTest.php
    M tests/Feature/UxCopyTest.php
   ?? docs/planImp/implemented.md
   ?? docs/planImp/plan.md                                     # pre-existing (Planner)
   ```
   Nothing else. `resources/views/backup/index.blade.php` is unchanged after step 7's temporary mutation.
5. Browser checks: Jon's, after acceptance (not run here).

## Files changed
As in Verification 4. Mine: `SMALL_FIXES.md`, the three views, the three tests and `implemented.md`. Pre-existing: `RESUME.md` and `plan.md`. Dev DB: `users` id 9 deleted.

## Notes for Planner
- **A copy assertion can pass for the wrong reason.** A short phrase like "Picking queue" can already appear in nearby prose (Deviation 2). For button and label pins, asserting the label with its glyph (`→`), or with the tag (`'>Label<', false`), is the safer default for future plans.
- The `Fulfillment` sidebar group label (`navigation.blade.php:44`) is now the only user-visible "fulfil…" word in the views. It was deliberately kept this cycle (Risks), so it is a candidate for the user-guide copy pass.
- Line numbers cited in `SMALL_FIXES.md` (e.g. "~158" for the sync item) have moved by four lines (now 162).
