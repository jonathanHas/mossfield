# Cycle 18 — Before go-live: RESTRICT foreign keys, invoice send actions, one vocabulary, small fixes — implementation

Status: DONE
Plan revision: 1
Implementer: Opus
Date: 2026-11-07

## Baseline
HEAD: 738a323
Pre-existing dirty files:
```
 M docs/planImp/RESUME.md      (Planner's, not touched here)
 M docs/planImp/plan.md        (Planner's, not touched here)
```
(`implemented.md` held cycle 17's report — committed in `738a323` — and is replaced here.)

## Steps
### 0. Baseline — done
```
php artisan test          → Tests: 847 deprecated, 1 passed (5824 assertions) = 848, 0 failed
pint --test app tests     → PASS … 235 files
route:list | wc -l        → 169
```

### 1. Ten foreign keys to RESTRICT — done
Changed: `database/migrations/2026_11_07_120000_restrict_history_foreign_keys.php` (new),
`tests/Feature/ForeignKeyRestrictTest.php` (new, 6 tests), `tests/Feature/BackupRestoreTest.php`
(`test_restore_reencrypts_customer_pii_readable` deletes `OrderItem` + `Order` before `Customer`).
The migration is one `KEYS` map and a `rewrite(bool $restrict)` used by `up()`/`down()`; per
table, one `Schema::table` drops the FKs and a second re-adds them.

Dev (`mossfield_cl`, populated) — `php artisan migrate`:
```
2026_11_07_120000_restrict_history_foreign_keys .............. 268.31ms DONE
```
Information-schema query (Context A pairs) after up:
```
batches.product_id RESTRICT                     batch_items.batch_id CASCADE
batch_items.product_variant_id RESTRICT         order_allocations.order_item_id CASCADE
cheese_conversion_logs.source_batch_item_id RESTRICT   order_items.order_id CASCADE
cheese_conversion_logs.target_batch_item_id RESTRICT   product_variants.product_id CASCADE
cheese_cutting_logs.source_batch_item_id RESTRICT
cheese_cutting_logs.target_batch_item_id RESTRICT
orders.customer_id RESTRICT
order_allocations.batch_item_id RESTRICT
order_items.product_variant_id RESTRICT
stock_write_offs.batch_item_id RESTRICT
```
Round-trip on dev:
```
php artisan migrate:rollback --step=1 → 2026_11_07_120000_restrict_history_foreign_keys ... 260.06ms DONE
  same query →  14 CASCADE
php artisan migrate                   → 2026_11_07_120000_restrict_history_foreign_keys ... 255.83ms DONE
  same query →   4 CASCADE, 10 RESTRICT
php artisan migrate:status | grep restrict → 2026_11_07_120000_restrict_history_foreign_keys ... [4] Ran
```
Check: ran the whole suite instead of the filter (to catch any other test deleting a parent directly):
```
php artisan test → Tests: 853 deprecated, 1 passed (5849 assertions) = 854, 0 failed
```
Only `BackupRestoreTest:393` needed the fixture change.

### 2. Send the invoice from where it was issued — done
Changed: `routes/web.php` (`POST /invoicing/invoices/send-all` → `invoicing.invoices.send-all`, above the
`{invoice}` wildcards), `InvoiceController::sendAll()` (new), `orders/show.blade.php` (billing line),
`invoicing/invoices/index.blade.php` (ninth column, Send all button + sibling `#send-all-form`),
`tests/Feature/InvoiceSendActionsTest.php` (new, 10 tests), `docs/guide/finance.md`, `docs/guide/orders.md`.

- Order page, `isInvoiced()` branch after the tag: emailed → "emailed d/m/Y"; paper customer → the
  delivery label ("Print / post"); no recipient → "not yet sent · no email address"; else
  `@can('email')` → inline **Email invoice** form (title/body/label as planned), `@else` "not yet sent".
- List row: **PDF** always; " · **Email**" form when issued, not emailed, customer wants email,
  recipient exists and `@can('email')`. `colspan="9"`.
- Send all: the button sits inside the GET filter form after Clear with `form="send-all-form"` (forms
  can't nest), `data-confirm-*` on the button (confirm.js resolves `el.form`, which follows `form=`).

Check:
```
php artisan test --filter="InvoiceSendActionsTest|OrderTransitionsTest|DispatchConfirmationTest|AuthorizationBoundaryTest|ConfirmDialogTest|InvoicingAccessTest|HelpPagesTest"
  → Tests: 98 deprecated (2276 assertions)   (0 failed)
php artisan route:list | wc -l → 170
```

### 3. One vocabulary: "To invoice", "Picking" — done
Changed: `OrderController.php:555` + `ChilledRunController.php:280` flashes ("Finance → To invoice"),
`DispatchService.php:21` docblock, `dashboard.blade.php` + `invoicing/invoices/index.blade.php` tiles,
`invoicing/uninvoiced/index.blade.php` h1, `orders/show.blade.php` ("To invoice · Finance"),
`layouts/navigation.blade.php` (group label + comment), `docs/guide/finance.md` (lines 19, 31, 36, 38 —
31 "the *Awaiting invoice* list" was not in the plan's list but would have failed the grep),
`docs/guide/getting-started.md` (33, 50), tests `OrderTransitionsTest`, `DispatchConfirmationTest` (×2),
`DispatchRunTest`.

Tests: the sidebar's Finance group already has a "To invoice" link on every office page, so a bare
`assertSee/DontSee('To invoice')` would be meaningless (or fail). The billing-line assertions pin the
line's own markup: `assertSee('muted);">To invoice</span>', false)` (sidebar renders `<span>To invoice</span>`).
finance.md:36 reworded: "**To invoice** lists the orders still to be billed. Because dispatch issues
invoices by itself, this is the exceptions list."

Check:
```
grep -rn "Awaiting invoice" app resources docs/guide tests → (no hits)
grep -rn "Fulfillment" resources/views docs/guide          → (no hits)
php artisan test --filter="OrderTransitionsTest|DispatchConfirmationTest|DispatchRunTest|FinanceNavTest|HelpPagesTest"
  → Tests: 69 deprecated (740 assertions)  (0 failed)
```

### 4. Small fixes — done (browser half of the product-page check is under Verification 5)
Changed: `app/Models/BatchItem.php` (`isReadyToSell()` → `$this->batch->isReadyToSell()`, comment kept/updated),
`products/show.blade.php` (Unit header + cell dropped; the actions `<td>` was already `whitespace-nowrap`),
"on it." → "on this." in the greyed-Delete title prefix in `batch-card`, `products/show`, `products/index` (×2),
`customers/index`; `invoicing/invoices/{show,pdf}.blade.php` label is `VAT` (ternary dropped);
`invoicing/emails/index.blade.php` subtitle names the dispatch confirmation; `customers/index.blade.php` Search
button removed (flex form, no empty cell left; Clear stays). Tests: `StockOverviewTest` +1
(`test_cheese_on_its_ready_day_is_ready_for_the_item_and_the_stock_page`), prefix strings in
`DeletionGuardTest` (×4) and `BatchCreateTest` (×1). The blocker *sentences* ("… depends on it, and deleting
it …") are model text and unchanged.

The new test against HEAD's `BatchItem.php` (swapped in temporarily, then restored):
```
Failed asserting that false is true.   Tests: 1 failed (3 assertions)
```
With the fix: `Tests: 1 deprecated (5 assertions)`.

Check:
```
php artisan test --filter="StockOverviewTest|BatchCreateTest|DeletionGuardTest|CheeseConversionTest|InvoiceIssueTest|ConfirmDialogTest|CustomerSpecialPriceTest"
  → Tests: 114 deprecated (525 assertions)  (0 failed)
```

### 5. Docs and decisions — done
Changed: `docs/planImp/OPEN_QUESTIONS.md` (#7 status + `### Decision`), `docs/planImp/SMALL_FIXES.md`
(9 ticked — the six small fixes, One list three names, Fulfillment, the sync item "Closed …"; weight-entry
item annotated, left open), `CLAUDE.md` (a)–(d), `DEPLOYMENT.md:135`.

CLAUDE.md: (a) the `deletionBlocker()` bullet gains the RESTRICT sentence, and its quoted prefix now reads
"depend on this" to match the views; (b) the per-invoice Email / Send all paragraph under *Email + the log*;
(c) `grep -n '"Fulfillment"\|Fulfillment group' CLAUDE.md` → no hits, nothing to change (the remaining
"Fulfillment" mentions are the flow's name); (d) "Awaiting invoice" → "To invoice" at **three** places, not
two (the DispatchService bullet, the order-page line quote, the queue paragraph). Also corrected the
"There are **no invoice buttons on the order screens**" sentence in *Invoicing* — now false — to "no
issue/void buttons" + the new billing-line states (see Deviations).

Check:
```
grep -n "decided 2026-11-07" docs/planImp/OPEN_QUESTIONS.md
  → 278:**Raised:** 2026-11-07 (cycle 17) · **Status:** **decided 2026-11-07 (Jon: do it before go-live), built 2026-11-07 (cycle 18).**
  (also 258: — cycle 16e's #6, pre-existing)
grep -c "^- \[ \]" docs/planImp/SMALL_FIXES.md → 1
```

### 6. Guide pictures — done (39 changed, not 44 — see Deviations)
No demo refresh (today 2026-11-07 < 2026-11-17); no probe rows existed. `node docs/guide/screenshot.mjs` → 44 ✓.
Then `node docs/guide/screenshot.mjs product-page` again after the step-4 follow-up (Deviations #2).
```
git status --short docs/guide/images | wc -l → 39
php artisan test --filter=HelpPagesTest     → Tests: 9 deprecated (470 assertions)
```
The five unchanged are `picking-queue`, `picking-order`, `picking-item-fixed`, `picking-item-wheel`,
`picking-order-done` — the `/picking` phone shell has no sidebar, so the label change can't reach them.

Looked at, and what I saw:
- `sidebar-help` — the group reads **PICKING** over Picking / Stock Allocation.
- `dashboard-office` — first tile **TO INVOICE 2 to bill**; also "Invoices not emailed 2", Overdue €0.00.
- `finance-invoices` — tiles Not yet emailed 2 / **To invoice** 2 / Overdue; **Send all not yet emailed (2)** after
  Clear; ninth column: INV-000004 "PDF" (emailed 07/11), INV-000003 and INV-000002 "PDF · Email". € not blurred
  (it wasn't before; office-only page).
- `finance-awaiting-invoice` — h1 and top bar **To invoice**.
- `customers-list` — Search box + Status select, no Search button.
- `product-page` — columns Name / Size / Weight / Price (blurred) / Stock / Status; actions wrap
  "Edit · / Duplicate · / Delete", greyed Delete inside the panel.
- `order-page-ready` / `-pending` / `-picking` — billing line unchanged ("Awaiting dispatch — invoiced when
  dispatched" on the ready one); sidebar Picking. The demo orders aren't invoiced.
- `finance-invoice-partial` — Summary row **VAT €0.00** (was "Tax").
- Spot checks: `batches-index`, `settings` (and 15 others) differ from HEAD only in the sidebar label box
  (pixel diff bbox x 35–153, y 842–854).

Pixel-diffed every changed picture against HEAD. Differences outside the sidebar are either this cycle's
changes (above, plus `finance-sent` subtitle) or **dev data drift since the 16d/17 shoot, not caused here**:
on dev, someone dispatched ORD-20261107-005 (Organic Store) and emailed INV-000004 at 15:28–15:29 today, before
this session; that changes `customer-page` (Documents sent + a ninth order, taller), `orders-list-organic-store`,
`order-page-*` sibling lists, `finance-sent` rows, `picking-order-partly` ("264 → 258 in stock"),
`stock-overview`, `products-index`, `chilled-runs-dublin` (stock footer), `backup` (row counts). Heights also
changed on `batch-page-expired`, `finance-record-payment`, `variant-form` — viewed: correct pages, nothing broken.
No `all`-audience page changed (the only `all`-page picture differences are the sidebar label and stock-count drift).

### 7. Pint and the suite — done
```
./vendor/bin/pint app tests database → PASS … 311 files (nothing to fix)
php artisan test                     → Tests: 864 deprecated, 1 passed (5908 assertions) = 865, 0 failed
php artisan route:list | wc -l       → 170
```
865 not "≥ 870": the plan's estimate. New tests are 6 + 10 + 1 = 17 (848 → 865).

## Deviations
1. **`sendAll()` with everything skipped** (every unsent invoice lacks an address): redirects to the Invoices list
   with an `error` "No invoices were queued. Skipped (no email address): …" instead of a "0 invoices queued"
   success on Sent — mirrors `StatementBatchController::store()`. The plan's cases behave as written.
2. **Product page actions now wrap.** With Unit dropped and the actions `<td>` `whitespace-nowrap` (it already was),
   the table still needed 702 px in a 678 px wrapper at 1280 × 900: the document didn't scroll
   (`scrollWidth === clientWidth` passed) but Delete sat 8 px past the panel's edge, failing the plan's second
   half of the check. I **removed** `whitespace-nowrap` from that cell, so "Edit · Duplicate · Delete" wraps as the
   customer list's actions do. Measured after: table 678 = wrapper 678, greyed Delete right 1239 < wrapper 1255.
3. **Send-all test fixture**: the plan's "three unsent invoices (one paper, one no address) → the two emailable are
   sent" doesn't add up (that leaves one). The test uses four: two emailable (both sent, one batch id), one paper
   (untouched, no log row), one no-address (skipped, named in the flash as "No Address Shop (INV-…)").
4. **Order-page billing line, `@can('email')` false** (not reachable for office/admin on an issued invoice):
   falls back to "not yet sent", so the line never goes silent.
5. **Extra copy edits needed to satisfy the plan's own greps / truth:** `finance.md:31` ("the *Awaiting invoice*
   list" → *To invoice*); CLAUDE.md had **three** "Awaiting invoice" mentions, not two; CLAUDE.md's
   "There are **no invoice buttons on the order screens**" was made false by step 2 — now "no issue/void buttons"
   + the new billing-line states; CLAUDE.md's quoted greyed-Delete prefix → "depend on this".
6. **Tests for "To invoice"** assert the billing line's own markup (`muted);">To invoice</span>`), because the
   Finance sidebar already shows a "To invoice" link on every office page.
7. **39 pictures changed, not 44** (picking phone shell has no sidebar) — `git status --short docs/guide/images | wc -l` → 39.

## Verification
1. `php artisan test` → **865 tests, 0 failed** (864 deprecated-warning + 1 passed; 5908 assertions).
2. `./vendor/bin/pint --test app tests database` → clean (311 files).
3. `php artisan route:list | wc -l` → **170**.
4. Dev schema: information-schema query → 10 RESTRICT, 4 kept CASCADE (step 1 output); `migrate:rollback --step=1`
   → 14 CASCADE, `migrate` → back to 10/4; `migrate:status` → `2026_11_07_120000_restrict_history_foreign_keys … [4] Ran`.
5. Browser as `office_test`, 1280 × 900, headless Chrome (puppeteer-core, the guide's driver), **console errors: []**,
   nothing pressed that sends or changes state (dialogs opened and **cancelled**):
   - `/orders/9` (Cavistons, INV-000002 issued + unsent): Invoice line "INV-000002 Part paid Email invoice"; form posts
     `/invoicing/invoices/2/email`; clicking opens "Email this invoice?" / "Invoice INV-000002 goes to
     accounts@cavistons.com now. The send is logged under Finance → Sent." / OK "Send"; Cancel → still `/orders/9`.
   - `/orders/23` (INV-000004, emailed): "INV-000004 Unpaid emailed 07/11/2026".
   - `/invoicing`: 9 headers; last cells "PDF" / "PDF · Email" / "PDF · Email"; button "Send all not yet emailed (2)"
     posting `/invoicing/invoices/send-all`; dialog "Send all unsent invoices?" / "2 invoices are emailed to each
     customer's billing address, one at a time, and each send is logged under Sent." / OK "Send all"; cancelled.
     scrollWidth 1280 = clientWidth.
   - `/invoicing/uninvoiced` h1 "To invoice"; sidebar groups Sales / Finance / **Picking** / Miscellaneous / Production.
   - `/customers`: no Search button; typing "Avoca" navigated to `?search=Avoca&is_active=`, rows 16 → 5.
   - `/products/3`: scrollWidth 1280 = clientWidth, no Unit header, greyed Delete inside the panel, title
     "Can't delete — 15 records depend on this. Farmhouse Cheese · Whole Whe…".
   - Afterwards: unsent invoices still INV-000002, INV-000003; `document_emails` 6 rows, newest 15:29:16 (pre-session).
6. `git status --short` — below.

## Files changed
```
 M CLAUDE.md                                     M resources/views/batches/partials/batch-card.blade.php
 M DEPLOYMENT.md                                 M resources/views/customers/index.blade.php
 M app/Http/Controllers/ChilledRunController.php M resources/views/dashboard.blade.php
 M app/Http/Controllers/Invoicing/InvoiceController.php
 M app/Http/Controllers/OrderController.php      M resources/views/invoicing/emails/index.blade.php
 M app/Models/BatchItem.php                      M resources/views/invoicing/invoices/{index,pdf,show}.blade.php
 M app/Services/DispatchService.php              M resources/views/invoicing/uninvoiced/index.blade.php
 M docs/guide/{finance,getting-started,orders}.md
 M docs/guide/images/*.png  (39)                 M resources/views/layouts/navigation.blade.php
 M docs/planImp/OPEN_QUESTIONS.md                M resources/views/orders/show.blade.php
 M docs/planImp/RESUME.md      (pre-existing, Planner's)
 M docs/planImp/SMALL_FIXES.md                   M resources/views/products/{index,show}.blade.php
 M docs/planImp/implemented.md                   M routes/web.php
 M docs/planImp/plan.md        (pre-existing, Planner's)
 M tests/Feature/{BackupRestore,BatchCreate,DeletionGuard,DispatchConfirmation,DispatchRun,OrderTransitions,StockOverview}Test.php
?? database/migrations/2026_11_07_120000_restrict_history_foreign_keys.php
?? tests/Feature/ForeignKeyRestrictTest.php
?? tests/Feature/InvoiceSendActionsTest.php
```
Controllers: 3 (`InvoiceController`, `OrderController`, `ChilledRunController`) — the plan counted "2 controllers +
InvoiceController". Views: 13 files (the plan's "8" undercounted the step-3/4 lists). Edited tests: 7, not 4
(BackupRestore, BatchCreate, DeletionGuard, StockOverview from steps 1/4; three from step 3). No `public/build`,
no `demo-data.json`.

## Notes for Planner
- **Dev data moved today outside this loop**: ORD-20261107-005 dispatched and INV-000004 emailed (15:28–15:29) for
  Organic Store, before this session. That is why `customer-page`, `finance-sent`, order lists and stock counts in
  the pictures changed; if the guide should not show an invoiced Organic Store, re-run demo data / void on dev.
- The **nav group's collapse state** is keyed on the slug of its label (`nav-open:fulfillment` → `nav-open:picking`),
  so anyone who had collapsed the old group sees it open once. Harmless.
- `docs/planImp/archive/2026-11-07-delete-guards/` exists but is **empty** — cycle 17's plan/implemented were not
  moved there (both are in git at `738a323`; this file now holds cycle 18). Worth fixing when archiving cycle 18.
- `customer-page.png` shows a customer email address unblurred (`accounts@organicstore.ie`, demo data) — pre-existing,
  but the vault's gotcha says committed pictures should blur emails; the manifest may want a `blur` entry there.
- `Batch::isReadyToSell()` has no product-type check (the old item method was cheese-only); harmless while only
  cheese gets a `ready_date` (`Batch::boot`), but a milk batch given a future `ready_date` by hand would now read
  not-ready.
- Production deploy needs maintenance mode (the migration), as the plan says. The migration takes ~260 ms on dev.
