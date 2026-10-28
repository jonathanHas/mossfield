# UX review, part 2 — implementation

Status: DONE
Plan revision: 1
Implementer: Opus
Date: 2026-10-28

## Baseline
HEAD: af2630b
Pre-existing dirty files:
```
 M docs/planImp/README.md
?? docs/planImp/UX_REVIEW_2026-10-28.md
?? docs/planImp/plan.md
```

## Steps

### 1. The backup covers the finance tables — done
Changed: `app/Services/BackupService.php` (TABLES now 25 in the plan's order, docblock, ADDED_LATER +9 `=> true` with the cycle-14 comment), `resources/views/backup/index.blade.php` (labels for all 25), `tests/Feature/BackupRestoreTest.php` (+`invoicedOrder()` helper, `test_finance_tables_round_trip`, `test_a_backup_from_before_invoicing_still_restores`).
The round-trip test checks invoice number + balance, `orders.invoice_id` → restored invoice, line count, the payment allocation (payment id, invoice id, €10), credit note number/total/lines, the statement id + closing balance, and the full `document_sequences` key→next_value map. `document_emails` is in the payload but the test doesn't create a row (nothing in the services path writes one without mailing).
Check output:
```
php artisan test --filter=BackupRestoreTest
  Tests:    19 deprecated (98 assertions)        # 17 existing + 2 new, 0 failed
tinker: 25 tables, missing labels: []
```

### 2. An admin cannot demote or deactivate themselves; new users default to Office — done
Changed: `app/Http/Controllers/UserController.php` (self guard before the last-admin checks; error under `role`, or `is_active` when only deactivating; "signed out everywhere" flash), `resources/views/users/partials/form.blade.php` (`$isSelf`: hidden `role`/`is_active` carry the current values, select + checkbox `disabled`, the two hints; create defaults to Office), `tests/Feature/UserManagementTest.php` (new, 7 cases — includes a PATCH of your own record with the rendered values, per Risks).
Check output:
```
php artisan test --filter=UserManagementTest     Tests: 7 deprecated (41 assertions)   # 0 failed
php artisan test --filter=AuthorizationBoundaryTest   Tests: 21 deprecated (1444 assertions)
```

### 3. Back + submit no longer records a second write-off or cut — done
Changed: migration `2026_10_28_120000_add_form_token_to_stock_write_offs_and_cheese_cutting_logs.php` (new), `StockWriteOff` / `CheeseCuttingLog` fillable, both requests (`form_token` nullable uuid), `StockWriteOffService::record(..., ?string $formToken = null)`, `StockWriteOffController::store()` (pre-check + `UniqueConstraintViolationException` → `repeatSubmission()`), `_write-offs.blade.php` (token in `@php`, hidden input), `CheeseCuttingController` (`create()` passes `formToken`; `store()` checks the token first, the transaction moved into a private `cut()` so the unique violation can be caught outside it; shared `afterCut()` redirect, also used by step 21), `cheese-cutting/create.blade.php` (hidden token). Tests: `StockWriteOffTest::test_a_repeat_submission_of_the_same_form_is_refused` (+ fresh token and no token still write), `test_the_form_carries_a_token`; `CheeseCutWeightTest::test_a_repeat_submission_of_the_same_cut_is_refused`, `test_the_cut_form_carries_a_token`.
The repeat check in the cut `store()` runs **before** the free-wheel check, so a repeat of the last free wheel's cut says "Already recorded", not "No free wheels".
Check output:
```
php artisan migrate   → 2026_10_28_120000_add_form_token_to_stock_write_offs_and_cheese_cutting_logs  DONE
tinker hasColumn x2   → 11
php artisan test --filter="StockWriteOffTest|CheeseCutWeightTest|CheeseCutFreeStockTest"   Tests: 51 deprecated (277 assertions)  # 0 failed
```

### 4. A lost session gets a page — done
Changed: `resources/views/errors/419.blade.php` (new), `tests/Feature/ErrorPagesTest.php` (+2: guest → Sign in + login link, no "PAGE EXPIRED"/stack trace; office → dashboard link).
Check output:
```
php artisan test --filter=ErrorPagesTest   Tests: 8 deprecated (34 assertions)   # 0 failed
```

### 5. Undoing a release removes the empty mature batch — done
Changed: `CheeseConversionController::undoRelease()` — after `$log->delete()`, reloads the mature batch; deletes it when every item is at 0 produced **and** `deletionBlocker()` is null; redirects to the source batch page with "… Batch {code} held nothing else and was removed.", else `back()`. Tests: `test_undo_release_returns_wheels_to_the_hold` **changed as the plan instructs** (batch + item gone, redirect to source batch, code absent from `/batches` and `/stock`); new `test_undo_keeps_a_mature_batch_that_still_holds_history` (reversed write-off keeps it, 0 produced, flash without "removed"); new `test_a_second_release_after_undo_recreates_the_batch_with_the_same_code`.
One test detail: the flash names the removed code, so the `/batches` assertion first GETs the dashboard to consume the flash — otherwise `assertDontSee($code)` matches the flash, not a ghost card.
Check output:
```
php artisan test --filter=CheeseConversionTest   Tests: 24 deprecated (127 assertions)   # 0 failed
```

### 6. The converted item says where its weight came from — done
Changed: `batches/show.blade.php` Unit weight cell — muted "nominal from the source wheel" when `$batch->source_batch_id`. Assertions (mature page shows it, farmhouse page doesn't) are in `test_undo_keeps_a_mature_batch_that_still_holds_history`.
Check output: same run as step 5 — 24 passed (the assertion failed before the view change, passed after).

### 7. "Active" and "Ready to sell" exclude expired batches; filtered empty state — done
Changed: `BatchController::index()` (`status=active` → active and `expiry_date` null or `> today()`; `maturation_status=ready` adds `status != expired` and the same expiry clause), `batches/index.blade.php` (filtered empty state: "No batches match these filters" + Clear filters, no Create), `DashboardTest` (+3: active filter, ready-to-sell excludes active-past-expiry and closed-expired, filtered empty state on `?status=sold_out`).
Check output:
```
php artisan test --filter=DashboardTest   Tests: 11 deprecated (56 assertions)   # 0 failed (includes steps 12 + 14)
```

### 8. "Sold" column → "Picked / sold" — done
Changed: `batches/show.blade.php` header + the plan's `title`.
Check output:
```
grep -rn ">Sold<\|'Sold'" tests      → (no matches)
php artisan test --filter="BatchFinishedWeightTest|StockWriteOffTest"   Tests: 40 deprecated (255 assertions)   # 0 failed
```

### 9. Void invoices export with zero money — done
Changed: `Invoicing/ExportController::invoiceRows()` — a `$money` closure emits `0.00` for the six money columns when `status === InvoiceStatus::Void` (number, customer, status kept); docblock updated. Test `InvoicingExportTest::test_a_void_invoice_exports_with_zero_money` (void row zeros + `void`, live row unchanged, Gross column sums to the live invoice only).
Check output:
```
php artisan test --filter=InvoicingExportTest   Tests: 6 deprecated (31 assertions)   # 0 failed
```

### 10. No charge toggle on a run that has no charge — done
Changed: `delivery-runs/index.blade.php` (`@elseif ((float) $run->delivery_charge <= 0)` → muted "run has no charge" link to the run's edit page, behind `showCharges`), `DeliveryChargeTest::test_a_run_with_no_charge_offers_no_toggle`. The existing €4.50 case is unchanged and passes.
Check output:
```
php artisan test --filter=DeliveryChargeTest   Tests: 33 deprecated (123 assertions)   # 0 failed
```

### 11. "Used" counts active write-offs; reversed ones named — done
Changed: `WriteOffReasonController::index()` (`active_count` / `reversed_count`), `write-off-reasons/index.blade.php` (prints `active_count`, "+ N reversed" muted when > 0), `StockWriteOffTest::test_used_counts_active_write_offs_and_names_reversed_ones` (spoiled row reads "0 + 1 reversed", damaged row "1" with no "reversed").
Check output:
```
php artisan test --filter=StockWriteOffTest   Tests: 28 deprecated (158 assertions)   # 0 failed
```

### 12. The factory's expired tile does not say "write off" — done
Changed: `dashboard.blade.php` tile `:sub` (adds " · write off" only when `$user->can('create', StockWriteOff::class)`); `DashboardTest` factory assertions `12 units` + `assertDontSee('write off')`. Office assertion on `12 units · write off` unchanged.
Check output: in the step 7 run (11 passed).

### 13. Factory sees no Edit link on the products index — done
Changed: `products/index.blade.php` (separator + Edit inside `@can('update', $product)`), `ProductStockFigureTest::test_the_factory_gets_no_edit_link_on_the_products_index`.
Check output:
```
php artisan test --filter=ProductStockFigureTest   Tests: 8 deprecated (55 assertions)   # 0 failed
```

### 14. The driver dashboard tells the truth — done
Changed: `dashboard.blade.php` driver sentence; `DashboardTest::test_the_driver_dashboard_tells_the_truth`.
Check output: in the step 7 run (11 passed).

### 15. Release is hidden when Mature cheese is not set up — done
Changed: `CheeseConversionController` (public const `MATURE_NOT_SET_UP` holds the sentence once; `index()` passes `matureReady`; `release()`'s refusal uses the const), `cheese-conversion/index.blade.php` (warn strip under the intro for `$canConvert` users with a link to `products.create`; both the Release control **and** its sibling hidden `release-form-{id}` gain `&& $matureReady`), `CheeseConversionTest::test_release_is_hidden_when_mature_cheese_is_not_set_up` (strip + no `release-form-`, POST refused with the sentence, factory sees neither).
`LayoutFeedbackTest:175` and `MatureProductSeedTest:68` asserted the old sentence — see Deviations.
Check output:
```
php artisan test --filter="CheeseConversionTest|MatureProductSeedTest|ConfirmDialogTest|OrderShortSupplyTest|LayoutFeedbackTest"
  Tests: 68 deprecated (290 assertions)   # 0 failed (covers 15 + 16)
```

### 16. Release flash names the batch; "wheel(s)" → a word; dialog bodies are consequences — done
Changed: `CheeseConversionController` (`release()` returns the mature batch out of the transaction; flash "Released N wheel(s→plural) into mature batch {code} ({product}). Undo from that batch's page."; `hold()` and `undoRelease()` use `Str::plural`), `cheese-conversion/index.blade.php` (static body "The wheels leave this farmhouse batch …"; the `x-on:input` rewrite "N wheels leave …" / "1 wheel leaves …"), `batches/show.blade.php` ("Released N {plural}" line; Return body "They go back into the farmhouse batch's maturing hold; this mature batch loses them."), `orders/show.blade.php` (`Str::plural('unit', …)`). Test: `test_release_converts_held_wheels_to_mature` additionally asserts the code, "Undo from" and "Released 3 wheels into mature batch".
Check output: same run as step 15.

### 17. A refused weight keeps the weights already typed — done
Changed: `picking/item.blade.php` pick-form Alpine state from `old()` (`batch`, `qty`, `weights`, `bulkWeight` as the plan wrote them) + `init() { this.clamp(); }` (no `x-init`). Test `PickingTest::test_a_refused_weight_keeps_the_weights_already_typed` (post `['5.1','52']` → `assertSessionHasInput('weights')`; GET shows "Check the decimal point" and `weights:` … `5.1` … `52` … `bulkWeight:` in order).
Test note: the `PickingTest::batchItem()` helper's cheese batch gets a ready_date 90 days out from `Batch::boot()`, so the item page shows "No available stock" for it. The two new item-page tests set `ready_date` to yesterday after creating the batch (the existing tests only POST, which doesn't hit the chooser).

### 18. The pick screen names the batch's real stock — done
Changed: `picking/item.blade.php` (`$batchStockJson` raw `max` beside `$batchMaxJson`; stepper "of N needed · <batchStock[batch]> in this batch"; batch rows "Produced dd/mm · {max} in stock · oldest first" on the first; product card "in stock · oldest first"). Clamp (`batchMax`) unchanged. Test `test_the_pick_screen_names_the_real_batch_stock` (23 in stock, "in this batch", "oldest first", no "pickable", no "(FIFO)").

### 19. Next up never points at a dead order; past days say so — done
Changed: `PickingController::show()` (`$nextOrder` = same day, else the first candidate with `delivery_date` null or ≥ today), `picking/show.blade.php` (warn tag "Past its delivery date" when `$nextOrder->isDeliveryOverdue()` — reachable via the same-day path), `picking/index.blade.php` (same tag beside the `<h1>` when `$selected` is a date before today; footer "Start picking" for a `todo` row, "Continue picking" for `picking`). Tests: `test_next_up_never_points_at_an_order_past_its_date` (tomorrow beats July; with tomorrow cancelled, no next-up card), `test_a_past_day_says_so_and_an_untouched_day_says_start`.
Check output (17–19):
```
php artisan test --filter=PickingTest   Tests: 27 deprecated (135 assertions)   # 0 failed
```

### 20. Credit-note form prefills VAT, leaves amounts blank, says so — done
Changed: `invoicing/credit-notes/create.blade.php` (`creditNoteForm(creditable, rates, amounts)` seeded with the lines' VAT rates and `old("lines.$i.amount", '')`; `value=` removed from the two `x-model` inputs; copy "Enter the amount to credit on each line and leave the others blank."). Test `CreditNoteTest::test_the_form_prefills_vat_and_leaves_amounts_blank` (delivered order with a €4.50 charge → rates `[0,23]` in the `creditNoteForm(…)` call; no `lines[n][amount]` input carries `value=`).
Check output:
```
php artisan test --filter=CreditNoteTest   Tests: 11 deprecated (33 assertions)   # 0 failed
```

### 21. Cutting from the batch page returns to the batch; date bounds — done
Changed: `batches/show.blade.php` (Cut buttons → `?return=batch`; cutting-history panel `id="cutting"` + `mf-landing`), `CheeseCuttingController` (`create(Request, BatchItem)` computes `$returnTo`; `store()` success/repeat via `afterCut()`; refusal redirect to `cheese-cutting.create` carries `return=batch` when set), `cheese-cutting/create.blade.php` (Back / dead-end Back / Cancel use `$returnTo`; hidden `return`; `cut_date` `min`/`max`). Tests: `CheeseCutWeightTest::test_a_cut_started_from_the_batch_page_returns_there` (batch page link, Cancel to `…#cutting`, min/max, redirect `#cutting`, anchor present), `test_a_cut_started_from_the_index_still_returns_to_the_index`. The existing line-104 index case is unchanged and passes.
Back-link label: "← Back to batch" when returning to the batch, "← Back to cutting" otherwise (see Deviations).
Check output:
```
php artisan test --filter="CheeseCutWeightTest|CheeseCutFreeStockTest"   Tests: 26 deprecated (138 assertions)   # 0 failed
```

### 22. No empty Actions panel on milk and yoghurt — done
Changed: `batches/show.blade.php` — the whole Actions panel (incl. lineage/undo) inside `@if ($batch->product->type === 'cheese')`; the now-redundant inner cheese `@if` removed. Test `UxCopyTest::test_milk_and_yoghurt_batches_have_no_empty_actions_panel` (milk: no `>Actions<`; cheese: present).
Check output:
```
php artisan test --filter=UxCopyTest   Tests: 8 deprecated (46 assertions)   # 0 failed
```

### 23. Generate statement lands on the statement — done
Changed: `Invoicing/StatementController::store()` (all four `back()` → one `$landing` = statements index `#statement-{id}`, same flashes), `invoicing/statements/index.blade.php` (rows `id="statement-{id}"` + `mf-landing`), `StatementTest` line-139 case asserts the target with fragment and the `id` on the index.
Check output:
```
php artisan test --filter=StatementTest   Tests: 11 deprecated (40 assertions)   # 0 failed
```

### 24. Dev database tidy (data, not code) — done
Changed: dev DB `mossfield_cl` only, via tinker.
Check output:
```
before: batch 25: G030426-1 Mossfield Mature Cheese source=G030426 produced=0 blocker=null
        variant 15: Mossfield Mature Cheese / Whole Wheel weight_kg=2.300
        farmhouse wheels: [{"id":5,"name":"Whole Wheel","weight_kg":"5.000"}]
after:  deleted batch 25 (G030426-1)
        batch 25 now: null
        G030426 mature children: 0          # so its card no longer says "Matured into"
        variant 15 weight_kg: 2.300 -> 5.000
```
`review_test` (user 9) left alone. Not checked in the browser (`/batches`); the query above is the same relation the card's lineage line reads.

## Deviations

1. **Four existing tests pinned text or fixtures this plan changes, and the plan did not name them.** Each failed only because of a plan-mandated change; each was adjusted minimally, keeping what it tests:
   - `AuthorizationBoundaryTest::test_driver_keeps_the_account_pages` asserted the old driver sentence (`'route manifest'`) — now asserts step 14's new sentence. (The plan said this suite must pass "unchanged"; its *routes* matrix is unchanged, only this copy assertion moved.)
   - `LayoutFeedbackTest:175` / `MatureProductSeedTest:68` asserted / dont-asserted "Mature cheese product is not configured" — both now use `CheeseConversionController::MATURE_NOT_SET_UP` (step 15's sentence). Left as-is, the `assertDontSee` would have passed vacuously.
   - `ChilledRunTest::test_run_page_has_position_inputs_one_save_and_a_percent_tag` built its run with no charge and expected a toggle on the fixed-charge stop. Step 10 removes that toggle by design, so the fixture now gives the run `delivery_charge => 4.50`; both assertions unchanged.
   - `ConsolidationPassTest` (two card tests): the release flash now names the mature batch (`G…-1`), whose code starts with the source code, so `strpos($html, $code)` landed in the flash. Added `$this->flushSession()` after the fixture; assertions unchanged.
2. **Cut-form back link label** (step 21): the plan didn't say. "← Back to cutting" now reads "← Back to batch" when `return=batch` (otherwise unchanged), since it no longer goes to cutting.
3. **Release form, step 15**: the plan gated "the Release control"; the sibling hidden `release-form-{id}` (rendered further down the card) got the same `&& $matureReady`, so the page has no release form at all when Mature isn't set up (the test asserts no `release-form-`).
4. **Step 3, cut controller shape**: the transaction moved into a private `cut()` so `UniqueConstraintViolationException` is caught outside it (a caught exception inside `DB::transaction` would still have rolled back, but the catch has to be outside to return a redirect cleanly). The repeat check runs before the free-wheel check.
5. `MATURE_NOT_SET_UP` is a public const on the controller so the view, `release()` and the tests share one string.

## Verification

1. `php artisan migrate` → the one new migration applied during step 3 (`2026_10_28_120000_add_form_token_to_stock_write_offs_and_cheese_cutting_logs … DONE`); re-run at the end: `Nothing to migrate.`
2. `php artisan test`:
   - first run: `Tests: 775 deprecated, 4 failed, 1 passed (4998 assertions)` — the four in Deviation 1.
   - after those fixes: **`Tests: 779 deprecated, 1 passed (5016 assertions)`, Duration 41.94s — 780 tests, 0 failed** (baseline 746; "deprecated" = the pre-existing `PDO::MYSQL_ATTR_SSL_CA` notice).
3. `./vendor/bin/pint --test app tests database routes config` → first run 1 issue (`ordered_imports` in `ExportController.php`, my `use App\Enums\InvoiceStatus`); fixed with pint on that file → `PASS … 316 files`.
4. `php artisan test --filter="BackupRestoreTest|UserManagementTest|ErrorPagesTest|CheeseConversionTest|DashboardTest|PickingTest|StockWriteOffTest|CheeseCutWeightTest"` → `Tests: 141 deprecated (760 assertions)`, 0 failed.
5. `grep -rn "wheel(s)\|units across\|Run the product seeder\|once delivery routes\|pickable" app resources/views` → only `resources/views/picking/item.blade.php`: the PHP variable `$pickable` (lines 22–29, 88, 146, 289) and the Blade comment `{{-- ── Nothing pickable ── --}}` (147). None is rendered text; the visible "pickable" is gone (step 18's test asserts it). I did not rename the variable — not in the plan.
6. Browser checks — for Jon after acceptance (not run here).

## Files changed

`git status --short` at the end (pre-existing: `docs/planImp/README.md`, `UX_REVIEW_2026-10-28.md`, `plan.md` — not touched by me; `implemented.md` is this report):
```
 M app/Http/Controllers/BatchController.php
 M app/Http/Controllers/CheeseConversionController.php
 M app/Http/Controllers/CheeseCuttingController.php
 M app/Http/Controllers/Invoicing/ExportController.php
 M app/Http/Controllers/Invoicing/StatementController.php
 M app/Http/Controllers/PickingController.php
 M app/Http/Controllers/StockWriteOffController.php
 M app/Http/Controllers/UserController.php
 M app/Http/Controllers/WriteOffReasonController.php
 M app/Http/Requests/CheeseCutRequest.php
 M app/Http/Requests/StockWriteOffRequest.php
 M app/Models/CheeseCuttingLog.php
 M app/Models/StockWriteOff.php
 M app/Services/BackupService.php
 M app/Services/StockWriteOffService.php
 M docs/planImp/README.md                         (pre-existing)
 M resources/views/backup/index.blade.php
 M resources/views/batches/index.blade.php
 M resources/views/batches/partials/_write-offs.blade.php
 M resources/views/batches/show.blade.php
 M resources/views/cheese-conversion/index.blade.php
 M resources/views/cheese-cutting/create.blade.php
 M resources/views/dashboard.blade.php
 M resources/views/delivery-runs/index.blade.php
 M resources/views/invoicing/credit-notes/create.blade.php
 M resources/views/invoicing/statements/index.blade.php
 M resources/views/orders/show.blade.php
 M resources/views/picking/index.blade.php
 M resources/views/picking/item.blade.php
 M resources/views/picking/show.blade.php
 M resources/views/products/index.blade.php
 M resources/views/users/partials/form.blade.php
 M resources/views/write-off-reasons/index.blade.php
 M tests/Feature/AuthorizationBoundaryTest.php     (Deviation 1)
 M tests/Feature/BackupRestoreTest.php
 M tests/Feature/CheeseConversionTest.php
 M tests/Feature/CheeseCutWeightTest.php
 M tests/Feature/ChilledRunTest.php                (Deviation 1)
 M tests/Feature/ConsolidationPassTest.php         (Deviation 1)
 M tests/Feature/CreditNoteTest.php
 M tests/Feature/DashboardTest.php
 M tests/Feature/DeliveryChargeTest.php
 M tests/Feature/ErrorPagesTest.php
 M tests/Feature/InvoicingExportTest.php
 M tests/Feature/LayoutFeedbackTest.php            (Deviation 1)
 M tests/Feature/MatureProductSeedTest.php         (Deviation 1)
 M tests/Feature/PickingTest.php
 M tests/Feature/ProductStockFigureTest.php
 M tests/Feature/StatementTest.php
 M tests/Feature/StockWriteOffTest.php
 M tests/Feature/UxCopyTest.php
?? database/migrations/2026_10_28_120000_add_form_token_to_stock_write_offs_and_cheese_cutting_logs.php
?? docs/planImp/UX_REVIEW_2026-10-28.md            (pre-existing)
?? docs/planImp/implemented.md
?? docs/planImp/plan.md                            (pre-existing)
?? resources/views/errors/419.blade.php
?? tests/Feature/UserManagementTest.php
```
`git diff --stat`: 51 files changed, 923 insertions(+), 88 deletions(-) (tracked files only). Nothing committed.

## Notes for Planner

1. **DEPLOYMENT.md has no backup note** (Risks asked): the only "backup" mentions are `mysqldump` before the encryption migration (§ line 164) and restoring a dump on rollback (line 345). Nothing documents the `/backup` page, so there is nowhere obvious for "a restore can lower `document_sequences`" — worth a short §, or a line on the `/backup` page itself.
2. **Statements landing is page-1 only**: the index is `latest('period_end')`, paginated at 30. A statement generated for an old period can sort past page 1, and `#statement-{id}` then finds nothing (the redirect still lands on the list with its flash). Fine at today's volumes.
3. **`PickingTest::batchItem()` makes cheese batches that aren't ready** (`Batch::boot()` sets `ready_date = production + 90 days`, overriding the helper's `ready_date`). The existing item-page tests only check 200s, so they never noticed the page says "No available stock". My two new tests set `ready_date` after create; the helper itself could do the same.
4. **The undo flash on a deleted mature batch** names the removed code; on the next page that code is a prefix-match for the farmhouse code in any test using `strpos($html, $code)` — the same trap Deviation 1 hit in `ConsolidationPassTest`. Release's flash has the same property.
5. The verification grep for "pickable" can't reach zero without renaming `$pickable` in `picking/item.blade.php` (Verification 5). Suggest either rename it in a later cycle or narrow the grep to rendered text.
6. Dev DB: besides step 24, the step-3 migration is now applied to `mossfield_cl` (as the plan's Verification 1 expects).
