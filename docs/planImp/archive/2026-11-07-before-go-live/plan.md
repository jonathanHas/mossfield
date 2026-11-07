# Cycle 18 — Before go-live: RESTRICT foreign keys, invoice send actions, one vocabulary, small fixes

Status: ACCEPTED
Revision: 1
Planner: Fable 5.1
Date: 2026-11-07

Read `docs/planImp/planimp.md` first. The Implementer writes
`docs/planImp/implemented.md`; it does not edit this file.

## Goal

Production is deployed but not yet in use, so this is the cheap moment for
the changes that touch live tables and the ones staff would otherwise
report in week one. Four things, decided by Jon on 2026-11-07:

1. **The database refuses what the app refuses.** Cycle 17 made the
   controllers refuse to delete a product, variant or customer that history
   depends on; the schema still cascades underneath (`OPEN_QUESTIONS.md`
   #7). Ten foreign keys go from CASCADE to RESTRICT in one migration, so a
   `tinker` one-liner or a forgotten route can no longer take order lines,
   batches or allocations with it.
2. **The invoice can be sent from where it was issued** (UX review finding
   4, deferred since 2026-10-13): an **Email invoice** action on the order
   page's billing line, **Email** / **PDF** on each row of the Invoices
   list, and **Send all not yet emailed** over the list.
3. **One name per thing**: the exceptions list is **To invoice** everywhere
   (not also "Awaiting invoice"); the sidebar group over Picking and Stock
   Allocation is **Picking** (not "Fulfillment", the last "fulfil" the user
   sees).
4. **Small fixes** from `SMALL_FIXES.md`: `BatchItem::isReadyToSell()`
   delegates to the batch; the product page's variants table fits 1280 px;
   the greyed-Delete hover prefix reads "on this"; the invoice summary row
   is always "VAT"; the Sent list's subtitle names the dispatch confirmation;
   the customer list loses its Search button.

Two items are **closed by decision, not code**: the dashboard's "online
sync has never completed" is the correct report of "no Mossorders portal
configured" and is hidden behind `MOSSORDERS_UI_ENABLED=false`, which stays
false until a portal exists; and the 31-input weight form is a design
question for after the floor has used it, not a polish fix.

## Context

**Baseline.** HEAD `738a323` (cycle 17 shipped; production on the same
commit). **848 tests, 0 failed** (5,824 assertions); `pint --test app
tests` clean (235 files); `php artisan route:list | wc -l` → 169. Working
tree: `M docs/planImp/RESUME.md` (Planner's — leave it) and this file.

### A. Foreign keys (step 1)

The delete rules in `mossfield_cl` today, for the FKs that matter
(constraint names are Laravel's defaults, `{table}_{column}_foreign`):

| FK | now | after |
|---|---|---|
| `orders.customer_id → customers` | CASCADE | **RESTRICT** |
| `batches.product_id → products` | CASCADE | **RESTRICT** |
| `batch_items.product_variant_id → product_variants` | CASCADE | **RESTRICT** |
| `order_items.product_variant_id → product_variants` | CASCADE | **RESTRICT** |
| `order_allocations.batch_item_id → batch_items` | CASCADE | **RESTRICT** |
| `stock_write_offs.batch_item_id → batch_items` | CASCADE | **RESTRICT** |
| `cheese_cutting_logs.source_batch_item_id`, `…target_batch_item_id → batch_items` | CASCADE | **RESTRICT** |
| `cheese_conversion_logs.source_batch_item_id`, `…target_batch_item_id → batch_items` | CASCADE | **RESTRICT** |
| `product_variants.product_id`, `batch_items.batch_id`, `order_items.order_id`, `order_allocations.order_item_id`, `customer_special_prices.*`, `invoice_lines.invoice_id`, `credit_note_lines.*`, `payment_allocations.payment_id` | CASCADE | unchanged — a child that *is* part of its parent |
| `invoices/payments/credit_notes/statements.customer_id` | RESTRICT | unchanged |
| `document_emails.customer_id`, `batches.source_batch_id`, `invoice_lines.order_id/order_item_id` | SET NULL | unchanged |

The first four are what `Product`/`ProductVariant`/`Customer::deletionBlocker()`
guard; the six `batch_items` children are what `Batch::deletionBlocker()`
guards (`app/Models/Batch.php:215-285` counts allocations, cutting logs,
conversion logs and write-offs — so a raw batch delete cascading them was
the same hole). With `product_variants.product_id` left CASCADE, deleting a
product whose variant has an order line fails on the variant's FK — the
right answer.

**Who deletes in bulk.** `ResetTradingData::WIPE_ORDER`
(`app/Console/Commands/ResetTradingData.php:51-68`) deletes children before
parents explicitly ("nothing here relies on a cascade" — its own docblock);
`BackupService::import()` (`app/Services/BackupService.php:161`) runs inside
`Schema::withoutForeignKeyConstraints()`. Neither changes. The app's own
deletes (`grep -rn -e '->delete()' app`) are all of children or of
guarded parents: `CheeseConversionController::undoRelease()` (`:210-220`)
deletes the log **before** the empty mature batch and only when
`deletionBlocker() === null` — survives.

**Tests that will go red**: `BackupRestoreTest:393`
`Customer::query()->delete()` after `seedBusinessData()` made an order for
that customer — with `orders.customer_id` RESTRICT this is 23000/1451.
`:185` deletes the orders first, then the customer — fine. Fix `:393` by
deleting `OrderItem` and `Order` first (the test's point is re-encryption
on restore, not the delete). Any other test that deletes a parent with
children directly is the same fix — say which in Deviations.

**Migration shape** (MariaDB; `dropForeign(['col'])` resolves the default
name; two statements per table):

```php
Schema::table('orders', function (Blueprint $table) {
    $table->dropForeign(['customer_id']);
    $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
});
```

`down()` restores `cascadeOnDelete()` for each. Precedent for FK edits in
this repo: `grep -ln "dropForeign" database/migrations`.

### B. Invoice send actions (step 2)

- **Order page** (`resources/views/orders/show.blade.php:238-262`): the one
  billing line, inside `@can('see-financials')`. The `isInvoiced()` branch
  shows number, payment-state tag and "not yet sent" (`:246-248`). The
  dispatch-confirmation line beside it (`:282-284`) already carries an
  inline Send/Resend form — same construct.
- **Invoice page** (`invoicing/invoices/show.blade.php:40-48`): the
  existing Email form — `@if($invoice->isIssued())`, `@if($invoice->documentRecipient())`,
  posts `invoicing.invoices.email`, title "Email this invoice?". The
  controller (`InvoiceController::email()` `:109-122`) authorizes
  `email` (`InvoicePolicy::email` = office/admin + issued), `sendNow()`s
  through `DocumentMailer`, flashes and `back()`s — so a post from the order
  page or the list lands back where it came from. No controller change
  needed for the two per-invoice actions.
- **Invoices list** (`invoicing/invoices/index.blade.php:74-124`): eight
  columns, the last "Emailed" (`:106-114`: date / delivery-mode tag for a
  paper customer / "Not sent"), `colspan="8"` on the empty row. Three KPI
  tiles above (`:17-30`), the first "Not yet emailed" from
  `headlineStats()['not_emailed']` = `Invoice::notEmailed()->count()`
  (`Invoice.php:137` — issued, `emailed_at` null, customer
  `invoice_delivery = email`). `Invoice::documentRecipient()` (`:325`),
  `hasBeenEmailed()` (`:222`), `Customer::wantsEmailedInvoices()` (`:256`).
- **Batch send precedent**: `StatementBatchController::store()` (`:60-105`)
  — one `Str::uuid()` batch id, `DocumentMailer::queue($doc, $user,
  batchId:)` per document inside try/catch `InvoicingException`, skipped
  names in the flash. `queue()` writes the `document_emails` row (status
  `queued`) and dispatches `SendDocumentEmail`; production's scheduler
  drains the queue every minute; `phpunit.xml` is `QUEUE_CONNECTION=sync`
  so tests see the send. The Sent list is `invoicing.emails.index`
  (`routes/web.php:264`).
- Routes: the `invoicing.` group (`routes/web.php:250-302`); wildcard
  `{invoice}` routes are last (`:296` comment) — a new `POST
  /invoices/send-all` must sit **above** them. `FinancialPolicy` makes
  `viewAny`=`canWrite` (office/admin), so `authorize('create',
  Invoice::class)` is the write gate for send-all.
- `OrderTransitionsTest:477-481` and `DispatchConfirmationTest:301,318`
  assert the billing-line text; `InvoicingTestCase` has `deliveredOrder()`,
  `issue()`, `batchItemFor()`; mail is `array` in tests.

### C. Vocabulary (step 3)

- **"Awaiting invoice" → "To invoice"** at: `invoicing/uninvoiced/index.blade.php:7`
  (the h1; `:2` header already says To invoice), `dashboard.blade.php:127`
  and `invoicing/invoices/index.blade.php:23` (tile labels),
  `orders/show.blade.php:260` ("Awaiting invoice · Finance"),
  `OrderController.php:555` and `ChilledRunController.php:280` (flash
  text "Finance → Awaiting invoice"), the docblock `DispatchService.php:21`.
  Tests: `OrderTransitionsTest:477,481`, `DispatchConfirmationTest:301,318`,
  `DispatchRunTest:192` (message string) — update to the new text, keep
  the assertions meaningful. Guide: `finance.md:19,36,38` (incl. the
  picture's alt text), `getting-started.md:50`; `FinanceNav.php:19` already
  says To invoice (`FinanceNavTest:37`).
- **"Fulfillment" → "Picking"** group label: `layouts/navigation.blade.php:44`
  (and the comment `:36`; the `$fulfillItems` variable may stay). Guide:
  `getting-started.md:33`. `CLAUDE.md` mentions of the nav group, if any
  (`grep -n "Fulfillment" CLAUDE.md` — `:492-498` are the flow's name,
  not the nav; leave those). The group label is in the sidebar of **every**
  guide picture — see step 6.

### D. Small fixes (step 4)

- `BatchItem::isReadyToSell()` (`app/Models/BatchItem.php:391-400`)
  compares a Carbon to a string (`ready_date <= now()->toDateString()`):
  on the ready day itself `"2026-12-27 00:00:00" <= "2026-12-27"` is
  **false**, so the item says not-ready while `Batch::isReadyToSell()`
  (`Batch.php:147-150`, `lte(now())`) says ready. Delegate to the batch.
- Product page variants table (`products/show.blade.php:110-119`): columns
  Name / Size / Unit / Weight / [Price] / Stock / Status / actions; the
  index (`products/index.blade.php`) has no Unit column and fits. Unit
  repeats Size for every seeded variant.
- Greyed-Delete prefix: `batches/partials/batch-card.blade.php:178-193`,
  `products/index.blade.php` (×2), `products/show.blade.php`,
  `customers/index.blade.php` — "depend on it." Pinned by
  `BatchCreateTest:316-319` and `DeletionGuardTest` (`"Can't delete — 1
  record depends on it. "` ×4).
- VAT label: `invoicing/invoices/show.blade.php:261` and
  `invoicing/invoices/pdf.blade.php:144` — `delivery_charge_net > 0 ?
  'VAT (23%)' : 'Tax'`. Products are 0 % VAT, only the delivery charge
  carries 23 %; "VAT" is true in both cases.
- Sent subtitle: `invoicing/emails/index.blade.php:8` "Every invoice,
  credit note, statement, reminder and docket emailed from here…" — the
  list is mostly dispatch confirmations (`DispatchService`).
- Customer list: `customers/index.blade.php:24` already
  `x-on:input.debounce.500ms="$el.form.requestSubmit()"` and the selects
  `onchange` (`:28,37`); the Search button is `:44`. `/orders` dropped its
  button in cycle 15 (`orders/index.blade.php:29-67`, no button).

### E. Guide

44 pictures, manifest `docs/guide/screenshots.json`; `node
docs/guide/screenshot.mjs` with no argument re-shoots the whole set (one
filter argument shoots the matches). The clock is hidden, so an unchanged
screen gives an unchanged PNG. The demo milk batch `M071126` expires
**2026-11-17** — if the shoot happens on or after that day, run
`GUIDE_FRESH=1 php artisan tinker docs/guide/demo-data.php` first (it only
cancels `GUIDE-%` orders and remakes them). Pictures shot as office with
blur selectors; a picture on an `all`-audience page must not show a
readable € figure; no € figures in guide prose. `HelpPagesTest` (9).

## Constraints

- **No data change, no backfill**: the migration alters delete rules only.
  It must run on a populated database (dev has every table in use) and on
  an empty one (`RefreshDatabase`).
- **No real sends from dev.** Dev is `MAIL_MAILER=log`, but pressing Email
  / Send all on dev still stamps real invoices as emailed. The browser
  check is **structural only** for the send actions: the buttons, their
  dialogs and the route they post to — never press them. The behaviour is
  proven in the tests (`MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`).
- Every `data-confirm` trigger names its action in `data-confirm-title`;
  bodies are consequences (`ConfirmDialogTest`). Flashes use the shared
  layout region; prefer `redirect()->route(...)` on the new send-all path.
- No per-row queries added to the Invoices list (customer is already
  eager-loaded; `documentRecipient()` reads it).
- `./vendor/bin/pint`; no CSS/JS changes expected (no `npm run build`).
- Dev box only; no commit, no deploy (Jon ships **with** maintenance mode —
  there is a migration). The Planner does not edit application code; the
  Implementer does not edit this plan. Every departure → `implemented.md`
  → Deviations.

## Out of scope

- Soft deletes; a Delete on any page that lacks one today.
- Changing any FK left "unchanged" in the table above.
- "Email again" or Send reminder on the list rows or the order page (the
  invoice page keeps them); emailing credit notes/statements in bulk.
- The weight-entry redesign (SMALL_FIXES item, annotated in step 5).
- Anything Mossorders beyond the two sentences in step 5.
- The consolidated-invoice run, Issue all, or any `InvoiceService` change.

## Steps

### 0. Baseline
Check: `php artisan test` → 848, 0 failed; `./vendor/bin/pint --test app
tests` clean; `php artisan route:list | wc -l` → 169; HEAD `738a323`;
`git status --short` → `M docs/planImp/RESUME.md`, `?? docs/planImp/plan.md`.

### 1. Ten foreign keys to RESTRICT
Files: `database/migrations/2026_11_07_120000_restrict_history_foreign_keys.php`
(new), `tests/Feature/ForeignKeyRestrictTest.php` (new),
`tests/Feature/BackupRestoreTest.php` (`:393` only)
What:
- The migration: for each of the ten FKs in Context A, `dropForeign` then
  re-add with `restrictOnDelete()`; `down()` re-adds with
  `cascadeOnDelete()`. One `Schema::table` per table (the two log tables
  carry two FKs each). A docblock naming the rule: *the app's
  `deletionBlocker()`s refuse; the database is the backstop (cycle 18,
  OPEN_QUESTIONS #7).*
- `BackupRestoreTest:393`: delete `OrderItem::query()` and `Order::query()`
  (and any other child the fixture made) before `Customer::query()->delete()`.
- `ForeignKeyRestrictTest`:
  - one test reads `information_schema.REFERENTIAL_CONSTRAINTS` joined to
    `KEY_COLUMN_USAGE` for `DB::getDatabaseName()` and asserts `DELETE_RULE
    = 'RESTRICT'` for all ten `(table, column)` pairs and `CASCADE` for
    `batch_items.batch_id`, `order_items.order_id`,
    `order_allocations.order_item_id`, `product_variants.product_id`;
  - behaviour: a raw `DB::table('customers')->whereKey($id)->delete()`
    with one order → `QueryException`, SQLSTATE 23000, the order still
    exists; the same for a product with a batch, a variant with an order
    line, a batch item with an allocation; and a batch **without**
    history still deletes its items (the kept cascade);
  - `php artisan migrate:rollback --step=1` then `migrate` on
    `mossfield_test` is **not** a test — run it once by hand against
    **dev** (`mossfield_cl`) to prove `down()` works, and put both outputs
    in `implemented.md`.
- Run the migration on dev: `php artisan migrate` → one migration, no
  errors (dev has rows in every table — a failure here is a real
  finding; stop and report rather than force).
Check: `php artisan test --filter="ForeignKeyRestrictTest|BackupRestoreTest|DeletionGuardTest|BatchCreateTest|CheeseConversionTest|ResetTradingDataTest|StockWriteOffTest"` → pass.

### 2. Send the invoice from where it was issued
Files: `routes/web.php`, `app/Http/Controllers/Invoicing/InvoiceController.php`,
`resources/views/orders/show.blade.php`,
`resources/views/invoicing/invoices/index.blade.php`,
`tests/Feature/InvoiceSendActionsTest.php` (new, extends `InvoicingTestCase`),
`docs/guide/finance.md`, `docs/guide/orders.md`
What:
- **Order page**, the `isInvoiced()` branch: after the payment-state tag,
  one of —
  - not emailed, `@can('email', $order->invoice)` and
    `documentRecipient()` non-null → an inline form posting
    `invoicing.invoices.email` (like the Confirmation line's), button
    `class="mf-link"` **Email invoice**, `data-confirm-title="Email this
    invoice?"`, body "Invoice {{ number }} goes to {{ recipient }} now. The
    send is logged under Finance → Sent.", `data-confirm-label="Send"`;
  - not emailed, no recipient → muted "not yet sent · no email address";
  - not emailed, paper customer (`! wantsEmailedInvoices()`) → muted
    "{{ delivery label }}" (no button);
  - emailed → muted "emailed {{ d/m/Y }}".
  The success flash from `email()` already reads "Invoice INV-… emailed to
  …" and `back()` lands on the order page.
- **Invoices list**: a ninth column (header empty, `text-right`), per row:
  **PDF** (`invoicing.invoices.pdf`, `target="_blank"`, `mf-link`) always;
  then, when not emailed and the customer wants email and
  `@can('email', $invoice)` and a recipient exists, " · " + an inline form
  posting `invoicing.invoices.email` with button **Email** (same title /
  body / label as above). Nothing else on the row — "Email again" and
  reminders stay on the invoice page. `colspan` → 9.
- **Send all**: `Route::post('/invoices/send-all', [InvoiceController::class,
  'sendAll'])->name('invoices.send-all')` **above** the `{invoice}`
  wildcards. `sendAll()`: `authorize('create', Invoice::class)`; `$queue =
  Invoice::notEmailed()->with('customer')->orderBy('id')->get()`; if empty
  → `redirect()->route('invoicing.invoices.index')->with('warning',
  'Nothing to send — every invoice that should be emailed has been.')`;
  else one `Str::uuid()` batch id, `$this->mailer->queue($invoice,
  $request->user(), batchId: $batchId)` per invoice in try/catch
  `InvoicingException` (skipped → customer name + invoice number); redirect
  to `invoicing.emails.index` with `success` "N invoices queued for
  sending — they appear below as they go." (+ " Skipped (no email
  address): …" when any). Button: on the Invoices list beside the filters'
  Clear link, rendered only when `$stats['not_emailed'] > 0` and
  `@can('create', Invoice::class)`: `mf-btn-secondary` **Send all not yet
  emailed (N)**, `data-confirm-title="Send all unsent invoices?"`, body
  "N invoices are emailed to each customer's billing address, one at a
  time, and each send is logged under Sent.", `data-confirm-label="Send
  all"`.
- Tests (`InvoiceSendActionsTest`), office unless said:
  - order page shows the Email invoice form for an unsent invoice (match
    on the `action`), and after posting it the invoice's `emailed_at` is
    set, a `document_emails` row is `sent`, the page reads "emailed" and
    has no form; factory's order page has neither (financials hidden);
  - no billing address → "no email address", no form; paper customer →
    the delivery label, no form;
  - list: unsent email-customer row has PDF + Email form; paper customer
    and already-emailed rows have PDF only; the empty state spans 9;
  - send-all: three unsent invoices (one paper customer, one with no
    address) → the two emailable are sent (sync queue), the paper one is
    untouched, the flash names the skipped one, redirect to the Sent list;
    nothing to send → the warning flash; factory → 403; the button renders
    with the count only when > 0.
  - `AuthorizationBoundaryTest` stays green (no model binding on the new
    route; it is in the office group).
- Guide: `finance.md` Invoices section — after "Click an invoice number to
  open it.": "Each row has **PDF**, and **Email** while the invoice is
  still to be sent. **Send all not yet emailed** at the top of the list
  sends every one of them in one go; they appear under **Sent** as they
  go." `orders.md:76` "The invoice is not emailed at this point. Send it
  from Finance." → "The invoice is not emailed at this point. **Email
  invoice** on the order's *Invoice* line sends it, or send it from
  Finance." Keep both pages' voice; no € figures.
Check: `php artisan test --filter="InvoiceSendActionsTest|OrderTransitionsTest|DispatchConfirmationTest|AuthorizationBoundaryTest|ConfirmDialogTest|InvoicingAccessTest|HelpPagesTest"` → pass; `php artisan route:list | wc -l` → **170**.

### 3. One vocabulary: "To invoice", "Picking"
Files: the sites listed in Context C (views, two controllers, one
docblock, `layouts/navigation.blade.php`, four tests, `finance.md`,
`getting-started.md`)
What: every user-facing "Awaiting invoice" becomes **To invoice** ("Finance
→ To invoice" in the two flashes; the order page line "To invoice · Finance";
the two tiles; the h1). The sidebar group label becomes **Picking**. Update
the four tests to assert the new strings (same assertions, new text).
`grep -rn "Awaiting invoice" app resources docs/guide tests` → no hits;
`grep -rn "Fulfillment" resources/views docs/guide` → no hits.
Check: `php artisan test --filter="OrderTransitionsTest|DispatchConfirmationTest|DispatchRunTest|FinanceNavTest|HelpPagesTest"` → pass.

### 4. Small fixes
Files: `app/Models/BatchItem.php`, `resources/views/products/show.blade.php`,
`resources/views/batches/partials/batch-card.blade.php`,
`resources/views/products/index.blade.php`,
`resources/views/customers/index.blade.php`,
`resources/views/invoicing/invoices/{show,pdf}.blade.php`,
`resources/views/invoicing/emails/index.blade.php`,
`tests/Feature/StockOverviewTest.php`, `tests/Feature/BatchCreateTest.php`,
`tests/Feature/DeletionGuardTest.php`
What:
- `BatchItem::isReadyToSell()` → `return $this->batch->isReadyToSell();`
  (keep the docblock: milk/yoghurt have no ready gate). Test in
  `StockOverviewTest`: a cheese batch with `ready_date = today` — its item
  `isReadyToSell()` is true and `/stock` shows it as available, not
  `not_ready`.
- Product page: drop the **Unit** column (header and cell) and give the
  actions `<td>` `whitespace-nowrap`. Verify in the browser at 1280 × 900
  as office on `/products/3` (Farmhouse, two variants):
  `document.documentElement.scrollWidth === document.documentElement.clientWidth`
  and the variant row's Delete is visible (greyed, with its title).
- The four greyed-Delete constructs: "on it." → "on this." in the title
  prefix (the sentence after it is unchanged). Update the pinned strings in
  `BatchCreateTest` and `DeletionGuardTest`.
- `show.blade.php:261` and `pdf.blade.php:144`: the label is **VAT** in both
  branches (drop the ternary).
- `emails/index.blade.php:8`: "Every invoice, credit note, statement,
  reminder, dispatch confirmation and docket emailed from here — including
  the ones that failed."
- `customers/index.blade.php:44`: remove the Search button (the form still
  submits on input/change; keep the `<form>`). If the layout leaves an
  empty grid cell, drop that wrapper too — compare with `orders/index`.
Check: `php artisan test --filter="StockOverviewTest|BatchCreateTest|DeletionGuardTest|CheeseConversionTest|InvoiceIssueTest|ConfirmDialogTest|CustomerSpecialPriceTest"` → pass.

### 5. Docs and decisions
Files: `docs/planImp/OPEN_QUESTIONS.md`, `docs/planImp/SMALL_FIXES.md`,
`CLAUDE.md`, `DEPLOYMENT.md`
What:
- `OPEN_QUESTIONS.md` ## 7: status → "**decided 2026-11-07 (Jon: do it
  before go-live), built 2026-11-07 (cycle 18).**" + a `### Decision`
  paragraph naming the ten FKs and the test.
- `SMALL_FIXES.md`: tick the six items done here (`*Done 2026-11-07 (cycle
  18)*` each, one line on what); tick **Dashboard reports the online order
  sync has never completed** with "*Closed 2026-11-07 (Jon): not a defect —
  the correct report of no Mossorders portal; hidden while
  `MOSSORDERS_UI_ENABLED=false`, which stays false until a portal exists.*";
  tick **One list, three names** and **The sidebar group is still called
  "Fulfillment"** (step 3). Annotate **Variable-weight fulfilment renders
  one kg input per unit** (leave unticked): "*2026-11-07: not a polish
  fix — a design question for after the floor has picked real cheese
  orders; `is_bulk_weighed` already covers packs.*"
- `CLAUDE.md`: (a) the `deletionBlocker()` bullet gains "The schema
  agrees: the ten history FKs are RESTRICT (migration `2026_11_07_120000`,
  `ForeignKeyRestrictTest`); a child that is part of its parent still
  cascades."; (b) under *Email + the log*: "Per-invoice **Email** lives on
  the invoice page, the Invoices list rows and the order page's billing
  line (all post `invoicing.invoices.email`, `back()`); **Send all not yet
  emailed** (`invoices.send-all`) queues `Invoice::notEmailed()` under one
  batch id and lands on Sent. `InvoiceSendActionsTest`."; (c) the nav group
  is "Picking" wherever CLAUDE.md names it (`grep -n '"Fulfillment"\|Fulfillment group' CLAUDE.md`);
  (d) "Awaiting invoice" → "To invoice" in the two prose mentions of the
  queue's name (keep `UninvoicedOrderController` etc. as code).
- `DEPLOYMENT.md:135` comment → "default false; leave false until a
  Mossorders portal exists — true shows the Online Orders nav/screens,
  dashboard sync banner and customer link fields".
Check: `grep -n "decided 2026-11-07" docs/planImp/OPEN_QUESTIONS.md` → hit;
`grep -c "^- \[ \]" docs/planImp/SMALL_FIXES.md` → **1**.

### 6. Guide pictures
Files: `docs/guide/images/*.png`
What: the sidebar group label changed, so every picture changes. Delete
any probe rows first (there should be none). Run `node
docs/guide/screenshot.mjs` with no argument → 44 ✓ (if today ≥ 2026-11-17,
`GUIDE_FRESH=1 php artisan tinker docs/guide/demo-data.php` first, and
say so). Then **look at** these and say what you saw: `sidebar-help`
(group reads Picking), `dashboard-office` (tile "To invoice"),
`finance-invoices` (ninth column with PDF/Email, the Send all button if the
dev count is > 0 — € blurred as before), `finance-awaiting-invoice` (h1 "To
invoice"), `customers-list` (no Search button), `product-page` (actions
visible, no Unit column), `order-page-*` (unchanged apart from the
sidebar — the demo orders are not invoiced), `finance-invoice-partial`
("VAT" row). Any other picture should differ only in the sidebar label;
spot-check two. The `all`-page € rule still holds (no `all` page changed).
Check: `git status --short docs/guide/images | wc -l` → 44;
`php artisan test --filter=HelpPagesTest` → 9 passing.

### 7. Pint and the suite
Check: `./vendor/bin/pint app tests database` then `php artisan test` →
≥ 870 tests, 0 failed (say the real number); `php artisan route:list | wc
-l` → 170.

## Verification

1. `php artisan test` → **0 failed**; count and assertions.
2. `./vendor/bin/pint --test app tests database` → clean.
3. `php artisan route:list | wc -l` → 170 (one new route).
4. Dev schema: the information-schema query from Context A against
   `mossfield_cl` → the ten rules read RESTRICT, the kept ones CASCADE;
   `migrate:rollback --step=1` + `migrate` round-trips on dev (outputs in
   `implemented.md`); `php artisan migrate:status` shows the new
   migration ran.
5. Browser as `office_test`, 1280 × 900, console clean, **structural only
   for sends**: `/orders/{a dispatched, invoiced, unsent order}` — if dev
   has none, say so and rely on the test — shows "Email invoice" with its
   dialog text; `/invoicing` shows the ninth column and the Send all button
   with the dev count; `/invoicing/uninvoiced` h1 "To invoice"; sidebar
   group "Picking"; `/customers` no Search button and the list still
   filters as you type; `/products/3` fits (scrollWidth check) and the
   greyed Delete's title starts "Can't delete — … depend on this."
   **Nothing pressed that sends or changes state.**
6. `git status --short` → the migration, 2 new tests + 4 edited, 2
   controllers, `routes/web.php`, `InvoiceController`, `BatchItem`, 8
   views, 44 PNGs, 3 guide pages, `CLAUDE.md`, `DEPLOYMENT.md`,
   `OPEN_QUESTIONS.md`, `SMALL_FIXES.md`. No `public/build`, no
   `demo-data.json`.

## Risks

- **The migration on a populated database.** RESTRICT only constrains
  future deletes; it never fails on existing rows. If MariaDB refuses a
  `dropForeign` (name mismatch), the real name is in
  `information_schema.REFERENTIAL_CONSTRAINTS` — report, don't rename by
  hand on dev.
- **A test that deletes a parent directly** (beyond `BackupRestoreTest:393`)
  — delete the children first in the fixture; never wrap app code in
  `withoutForeignKeyConstraints` to pass a test.
- **`back()` from the order page** after Email: the order page is an
  `x-app-layout` page, so the flash renders; the list likewise. Fine as is.
- **Send all and the queue on dev**: `QUEUE_CONNECTION=database` — a row
  would sit `queued` until a worker runs. Irrelevant because the button is
  never pressed on dev; tests are sync.
- **44 pictures change** for one label — expected; the per-picture checks
  in step 6 are what matters. If the shoot lands after 2026-11-17, the
  demo data must be refreshed first or the milk pictures go empty.
- **"To invoice" on the order page** reads as a state; fine beside
  "· Finance". Don't rename routes, controllers or the `uninvoiced` URL.
- **Dropping the Unit column** loses nothing the edit form doesn't show;
  if a variant on dev has `unit ≠ size` it still shows on its edit page.

## Review

**ACCEPTED** — Planner (Fable 5.1), 2026-11-07, against `implemented.md` (DONE, revision 1).

Reran independently: `php artisan test` → **865 tests, 0 failed (5,908
assertions)**; `pint --test app tests database` → clean (311 files);
`route:list` → **170**; information_schema on `mossfield_cl` → the ten
history keys RESTRICT, `batch_items.batch_id` / `order_items.order_id` /
`order_allocations.order_item_id` / `product_variants.product_id` still
CASCADE; a read-only headless pass as `office_test` and `factory_test`
(1280 × 900, nothing pressed): `/orders/9` one **Email invoice** form,
`/orders/23` none ("emailed 07/11/2026"), `/invoicing` two row **Email**
forms + "Send all not yet emailed (2)" posting `/invoicing/invoices/send-all`,
no "Awaiting invoice" or "Fulfillment" anywhere, no Unit header on
`/products/3`, no Search button on `/customers`, `scrollWidth ===
clientWidth` on every page, no console errors for office; factory sees no
send form on either order page and 403s on `/invoicing`, `/customers`.
Looked at `customer-page`, `finance-invoices`, `product-page`.

All seven deviations are accepted — each is the plan's own intent carried
further (the all-skipped `sendAll` case, the four-invoice fixture, the
`finance.md:31` / CLAUDE.md copy the greps caught) or a correction of the
plan's arithmetic (39 pictures, not 44: the picking shell has no sidebar;
865 tests, not "≥ 870"). Deviation 2 (actions wrap on the product page)
is the right call over an 8 px overflow but leaves a polish item — see
`SMALL_FIXES.md`.

Two things noted, neither a defect of this cycle: the order page's own
totals line and the dispatch confirmation still read "Tax" / "VAT (23%)"
(the plan named only the invoice; `SMALL_FIXES.md`); and the dev data
moved between the cycle-17 ship (15:06) and the Implementer's shoot —
ORD-20261107-005 dispatched, INV-000004 emailed at 15:28 — so
`customer-page` now shows *Recent invoices* and *Documents sent*, which
the guide's prose already describes. The email in it is the demo
customer's, not a real address.
