# UX review, part 2: the backup, the admin's own account, repeat submits, and the smaller truths

Status: ACCEPTED
Revision: 1
Planner: Fable 5.1
Date: 2026-10-28

## Goal

The Planner walked the app in Chrome on 2026-10-28 as the office, the factory
on a phone, the driver and the admin, and off the finance happy path, and
wrote up 25 findings in `docs/planImp/UX_REVIEW_2026-10-28.md`. This cycle
takes all 25 plus the cheap items under *Could be prettier*.

What changes for the people using it: the backup actually contains the
invoices, payments, credit notes, statements and the invoice counter (today
it silently leaves all nine finance tables out while promising "every
business table"); an admin can no longer demote or deactivate *themselves*
and get dropped on the login page without a word; Back-then-submit no longer
records a second write-off or a second cut; a lost session gets a real page
instead of Laravel's grey 419; undoing a mature release no longer leaves a
0-unit ghost batch on every stock screen; the batch filters, the accounting
CSV, the reasons list and the batch page stop showing figures that mislead;
the factory and the driver are no longer offered things they cannot do; and
a dozen screens say what is true in words a person can act on.

Decisions taken by the Planner (Jon can overrule any in the review):

- **Finding 5** — the empty Mature batch is **deleted on undo** when it holds
  nothing else, rather than hidden on five screens. One site instead of
  five, and the next release recreates it (same code, same lineage).
- **Finding 6** — code change is a label only ("nominal from the source
  wheel"). The seeder is coherent (Farmhouse 2.500, Mature 2.300); the odd
  figure is the dev database's Mature variant, created through the UI at
  2.3 kg beside a 5 kg Farmhouse wheel. That is data: step 24 sets it.
- **Finding 15** — honest copy now. Opening the run sheet to drivers is
  `FULFILMENT_PLAN.md` Phase 4 and wants a design pass first.
- **Finding 18** — the release flash names the mature batch and where the
  undo lives; it stays on the allocator (the office is usually working down
  several batches there). Flashes are escaped text, so no link.
- **Finding 22** — VAT % is prefilled from the invoice line, amounts start
  blank, and the sentence says so. (The cause is an Alpine `x-model` on an
  input whose model starts undefined, which blanks the `value=` attribute.)
- **Finding 25** — Generate lands on the Statements list, scrolled to the
  new row, which is where the PDF and the email button are.
- **Finding 3** — the per-render `form_token` the payment form already uses,
  on both the write-off form and the cut form. Not `Cache-Control: no-store`:
  a token refuses the duplicate whatever the browser cached.

## Context

HEAD `af2630b`, `main`, working tree clean apart from the untracked review
doc. Baseline run by the Planner on 2026-10-28: **746 tests, 0 failed**
(PHPUnit flags 745 as "deprecated" — the pre-existing `PDO::MYSQL_ATTR_SSL_CA`
notice, not failures), 4 788 assertions, 46 s; `pint --test app tests
database routes config` clean across 314 files. Any failure after this cycle
is real.

Environment facts from the review that are **not** this cycle's to fix: PHP
8.5 under this box's Apache fails PCRE JIT allocation, so the PDF and CSV
download routes 500 in the browser (`pcre.jit=0` in the Apache `php.ini`
fixes it; the test suite is unaffected); `.env` still has the placeholder
from-address, so every mail send fails on this box. Neither is a test
failure and neither is in scope.

**Facts the steps rely on**

- **Backup.** `app/Services/BackupService.php`: `TABLES` (16 tables, FK
  insert order; restore deletes in reverse) and `ADDED_LATER`
  (`table => bool`; `true` = a backup without it restores the table as
  empty, `false` = current rows kept). `validatePayload()` throws for any
  `TABLES` entry missing from the payload unless it is in `ADDED_LATER`.
  Restore runs inside `Schema::withoutForeignKeyConstraints`, so insert
  order is for sanity, not correctness. `export()`, `currentRowCounts()`
  and the page's table all iterate `TABLES`, so adding a table is one line
  plus a label. `FORMAT_VERSION` stays 2 — a format bump would refuse every
  existing backup, and `ADDED_LATER` already handles the missing tables.
  View `resources/views/backup/index.blade.php` has a `$tableLabels` map
  that is missing `settings`, `write_off_reasons`, `stock_write_offs`
  (they print raw). `tests/Feature/BackupRestoreTest.php` has helpers
  `seedBusinessData()` and `archiveUpload(?array $payload)`; its
  `test_a_backup_from_before_write_offs_still_restores` (line 220) is the
  pattern for "an older backup without table X".
  Finance FKs (from the migrations): `invoices.customer_id`,
  `invoice_lines.invoice_id/order_id/order_item_id`, `orders.invoice_id`,
  `payments.customer_id`, `payment_allocations.payment_id/invoice_id`,
  `credit_notes.invoice_id/customer_id`, `credit_note_lines.credit_note_id/
  invoice_line_id`, `statements.customer_id`, `document_emails.customer_id`,
  `document_sequences` (no FKs). `ResetTradingData` classifies
  `document_sequences` as infrastructure — that is its own list and does
  not reference `BackupService::TABLES`; nothing to keep in sync.
- **Users.** `UserController::update()` (`app/Http/Controllers/UserController.php`
  58–94) guards the *last admin* but not *self*; `destroy()` and
  `deactivate()` do guard self (`'You cannot deactivate your own account.'`).
  `logOutEverywhere()` cycles `remember_token` and deletes the user's
  `sessions` rows — on self that kills the current session, hence the silent
  login page. The form partial `resources/views/users/partials/form.blade.php`
  renders the role `<select>` with `old('role', $user?->role?->value)` — on
  create `$user` is null, so the first option (Administrator) wins (finding
  16). `UserRole::assignable()` lists the roles. `UserRequest` requires
  `role` and coerces `is_active`. The users index already hides Deactivate /
  Delete on the `(you)` row (`users/index.blade.php` 68, 84). There is **no**
  user-management feature test; `AuthorizationBoundaryTest` only walks the
  routes. `User::factory()->admin()` / `->factoryWorker()` / `->driver()`
  exist; `sessions` is a real table under `RefreshDatabase`.
- **Form token pattern.** `PaymentController::create()` passes
  `'formToken' => (string) Str::uuid()`; the view posts
  `<input type="hidden" name="form_token" value="{{ old('form_token', $formToken) }}">`;
  `store()` validates `form_token => nullable|uuid`, calls
  `repeatSubmission($token)` (finds the existing row by token → redirect
  with "already recorded — this was a repeat submission") **before**
  writing, and catches `UniqueConstraintViolationException` for the
  near-simultaneous case. Migration `2026_10_04_120000_add_form_token_to_payments_table`
  is the column shape: `string('form_token', 36)->nullable()` + unique
  index. Copy this exactly.
  Write-offs: `StockWriteOffController::store()` → `StockWriteOffService::record(item, reason, qty, weight, note, on, by)`;
  `StockWriteOffRequest` holds the rules; the form is
  `resources/views/batches/partials/_write-offs.blade.php` (its `@php`
  block at the top is where a token can be minted — `BatchController::show()`
  need not change). Cuts: `CheeseCuttingController::create(BatchItem)` /
  `store(CheeseCutRequest, BatchItem)`, the log is created inline in
  `store()`'s transaction; form `resources/views/cheese-cutting/create.blade.php`
  (line 46). `CheeseCutRequest::validated()` only returns ruled fields.
  `submit-once.js` re-enables buttons on `pageshow` on purpose (bfcache);
  leave it.
- **Error pages.** `resources/views/errors/{layout,404,403}.blade.php`;
  the layout picks the one link (guest → login "Sign in", factory →
  picking, else dashboard) and yields `title`, `code`, `message`. Laravel
  renders `errors::419` automatically for a `TokenMismatchException` (and
  for `abort(419)`), so a view is the whole fix. `ErrorPagesTest` is the
  pattern; a test can register a throwaway route with `Route::get(...)`
  inside the test method.
- **Mature undo.** `CheeseConversionController::undoRelease()` (174–203):
  refuses when `targetBatchItem->available_quantity < wheels_converted`,
  then in a transaction decrements the mature item/batch, re-credits the
  source `quantity_remaining` + `quantity_maturing`, deletes the log, and
  `back()`s (from the mature batch page). `release()` get-or-creates one
  Mature batch per `source_batch_id` and one item per variant, so after
  undo the batch sits at `quantity_produced = 0` — the ghost. `Batch::
  deletionBlocker()` (`app/Models/Batch.php` 220) returns null when no
  allocations / cut logs / conversion logs / write-off rows (reversed ones
  too) depend on the batch — evaluate it **after** `$log->delete()`.
  `batch_items.batch_id` cascades on delete; `cheese_conversion_logs`
  cascade from `batch_items`; `batches.source_batch_id` is `nullOnDelete`.
  `CheeseConversionTest::test_undo_release_returns_wheels_to_the_hold`
  (366) asserts the mature item still exists at 0 — the plan changes it.
- **Batch filters.** `BatchController::index()` 45–72: `status=expired`
  already means "closed as expired OR active past expiry" (DashboardTest
  pins it); `status=active` is a bare `where('status','active')`;
  `maturation_status=ready` is `ready_date null or <= now`. `Batch::isExpired()`
  is strict (`expiry_date <= today`). The index empty state
  (`batches/index.blade.php` 150–158) is the first-run copy whatever the
  filters. `DashboardTest::batch(expiry, remaining)` is a ready helper.
- **Batch page.** `batches/show.blade.php`: stock table header 166–177
  (the "Sold" column; the cell is `$soldUnits` from `weight_breakdown` —
  `dispatched` for wheels, `produced − on_hand − written_off` otherwise,
  i.e. *picked*, dispatched or not); "Unit weight" cell 185; the Actions
  panel 275–337 has **only** a cheese branch, and the lineage/undo block
  338–382 is inside it too — so on milk/yoghurt it is a heading over
  nothing. Cutting logs table starts near 233; there is no `id=` anchor on
  the page at all. `UxCopyTest::test_the_batch_page_does_not_promise_more_actions`
  (155) is the test that touched this panel last.
- **Export.** `Invoicing/ExportController::invoiceRows()` 65–92 yields one
  row per invoice with `$invoice->status->value` (`InvoiceStatus::Void =
  'void'`); the class docblock and `invoicing/export/index.blade.php` line
  9 both promise "a column total is the period's net turnover".
  `InvoicingExportTest` extends `InvoicingTestCase` (`issue()`,
  `deliveredOrder()` helpers); `InvoiceVoidTest` shows how to void.
- **Run charge toggle.** `delivery-runs/index.blade.php` 85–100: a
  percentage customer gets a tag, everyone else the toggle form — the run's
  own `delivery_charge` is not consulted. `DeliveryChargeTest::test_index_shows_the_reveal_button_and_toggle_form`
  (142) builds a run at €4.50 via `makeRun()` / `customerOnRun()`.
- **Reasons.** `WriteOffReasonController::index()` → `withCount('writeOffs')`;
  view `write-off-reasons/index.blade.php` line 38 prints
  `$reason->write_offs_count` under "Used". `StockWriteOff::scopeActive()`
  = `whereNull('reversed_at')`.
- **Dashboard.** `resources/views/dashboard.blade.php`: driver branch 51–65
  ("Your route manifest will appear here once delivery routes are set up…");
  expired tile 145–154 with `:sub="… ' units · write off'"`.
  `DashboardTest` line 71 asserts the office sees `12 units · write off`
  and line 75 that factory sees "Expired stock". `$user` is in scope.
  `StockWriteOffPolicy` is a bare `BasePolicy` (factory cannot `create`).
- **Products.** `products/index.blade.php` line 77: the per-card Edit link
  is outside any `@can` (View, and the `@can('delete')` Delete beside it,
  are fine); the variant Edit/Duplicate/Delete at 145–150 are already under
  `@can('update')`. `products/show.blade.php` is already gated.
- **Mature conversion page.** `CheeseConversionController::index()` passes
  `readyBatches`, `months`; `matureProduct()` resolves by `conversion_only`
  then by name; `matureWheelVariant()` wants an active variant named
  `%wheel%`. The Release control lives in `cheese-conversion/index.blade.php`
  76–96, gated `$canConvert && $eligible && $maturing > 0`, with the
  dialog body "Release N held wheel(s) into mature cheese?" under the title
  "Release to mature?" (the body restates the title — house rule says the
  body is the consequence; `ConfirmDialogTest` only catches a body that
  *starts* with the title). `release()` refuses with "Mature cheese product
  is not configured. Run the product seeder." The products create form has
  the checkbox labelled "Made only by conversion — no production batches
  (e.g. Mature)". `MatureProductSeedTest` and `CheeseConversionTest` set up
  the mature product in `setUp()`.
- **Picking.** `picking/item.blade.php`: the pick form's Alpine state
  (176–199) starts `weights: {}`, `bulkWeight: ''`, `qty: {{ $initialQty }}`,
  `batch: '{{ $initial id }}'` — never from `old()`. `PickingController::pick()`
  validates with `$request->validate()` (a failure redirects back **with
  input**), so `old('weights')` is there to read. The stepper line 264
  prints "of N needed · <maxQty> in batch" where `maxQty` is the *clamped*
  `min($needed, max)` from `$batchMaxJson` (line 25), and the batch rows
  (292) print "min(needed, max) pickable (FIFO)"; `$option['max']` is the
  real figure. `PickingController::show()` (126–137) picks `$nextOrder`
  from every confirmed/preparing order, same day first, else the first in
  date order — which is the three-month-old one. `picking/index.blade.php`
  47–55 is the day header; `$selected` is `Y-m-d` or `'none'`; `$continueTo`
  (line 17) is a `picking` row or, failing that, a `todo` row, and the
  footer (162–168) always says "Continue picking". `Order::isDeliveryOverdue()`
  exists (cycle 13). `PickingTest` has `orderFor($deliveryDate, $qty)` and
  `orderWithItem()` helpers; `test_variable_weight_pick_requires_a_weight`
  (246) is the refused-weight shape.
- **Credit note form.** `invoicing/credit-notes/create.blade.php`: copy at
  line 11; inputs for `vat_rate` and `amount` carry `value="{{ … }}"` **and**
  `x-model.number="rates[i]"` / `amounts[i]` — `creditNoteForm(creditable)`
  (line 89) initialises neither, so Alpine blanks both on load. That is why
  the review saw empty VAT and Credit boxes. `suggestLinesFor()` returns
  `vat_rate` and `amount` per line. `CreditNoteTest::test_office_raises_a_credit_note_through_the_form`
  (112) covers the POST; nothing pins the create page's prefill.
- **Cutting.** Batch page "Cut Whole Wheel" (`batches/show.blade.php` 301)
  → `cheese-cutting.create`; the form's "← Back to cutting" (line 10),
  the held-wheels dead end (42), Cancel (120) and `store()`'s success
  redirect all go to `cheese-cutting.index`. The `cut_date` input (52) has
  no `min`/`max`; `CheeseCutRequest` bounds it to [production date, today].
  `CheeseCutWeightTest::cutPost()` and `cut()` helpers; line 104 asserts the
  success redirect to the index — keep that for a cut started from the
  index.
- **Statements.** `Invoicing/StatementController::store()` 61–91 → `back()`
  on every path. There is no per-statement show route; the list is
  `invoicing.statements.index` (`invoicing/statements/index.blade.php`,
  rows from line ~45, no `id=` per row). `StatementTest` 120–143 asserts
  `assertRedirect()` with no target.
- **Copy sites.** `orders/show.blade.php` 348 "N units across"; `wheel(s)`
  in `CheeseConversionController` 77/166/202, `cheese-conversion/index`
  86/91, `batches/show` 362/370; `UserController` 93 "User updated.".
- Two Alpine rules (from `planimp.md`): never use an `@` shorthand that is
  also a Blade directive; anything nullable behind `x-show` needs `?.`.

## Constraints

- **Stock arithmetic is untouched.** Nothing here changes the free-stock
  expression (`remaining − (allocated − fulfilled) − maturing`, eight
  sites), the five-state reconciliation, or any `quantity_*` write except
  the mature-undo path, which already existed; the batch delete there goes
  through `Batch::deletionBlocker()`, never a bare `delete()`.
  `TESTING_AND_FIXES.md` → *Invariants worth keeping*.
- **Backups must stay restorable in both directions**: a backup taken
  before this cycle restores (the nine tables come back empty, which is
  what they were), and one taken after restores the finance data exactly.
  `FORMAT_VERSION` stays 2.
- **A repeat submission is refused, never silently re-run**: the second
  POST of the same `form_token` redirects to the panel with a flash and
  writes nothing. The unique index is the backstop for two simultaneous
  posts.
- **Role boundaries do not move**: no route changes group; `AuthorizationBoundaryTest`
  must pass unchanged. The driver stays deny-all.
- Flash messages are escaped text (`feedback.blade.php` prints `{{ }}`);
  do not add `{!! !!}`.
- Every `data-confirm` keeps a `data-confirm-title`; a body is the
  consequence, not the title again (`ConfirmDialogTest`).
- Do not commit, push or deploy. Do not run anything under `deploy/bin/`.
- Do not rewrite or delete an existing test to pass; where this plan
  changes an assertion it says so in the step.

## Out of scope

- The 2026-10-13 review's queued items (D20 junk postcode on invoice and
  statement, D22 delivery-date hint, D24 "~" on weight-priced estimates,
  #33 capacity note colour on `/delivery-runs`, F34 run-sheet address line,
  #29 the duplicated Finance navigation) — the next cycle. Finding #4 of
  that review (email the invoice from the order page) stays deferred by Jon.
- Driver access to the run sheet / `OrderPolicy::load` for drivers
  (`FULFILMENT_PLAN.md` Phase 4).
- Changing `ProductSeeder` weights, the production Mature variant's weight,
  or the yoghurt shelf life (Jon's data decisions; see step 24 and Risks).
- `pcre.jit`, the mail from-address, and anything else about the dev box.
- `Cache-Control: no-store`, or a token on any form other than write-off
  and cut (the payment form already has one; the others are idempotent or
  guarded by state).
- Deleting the review's `review_test` user (id 9) — Jon's call.
- Moving the credit-note Reason field, or any other form re-layout.
- Any change to `Batch::generateBatchCode()`'s `-N` suffix.

## Steps

Order matters only within A (the migration in step 3 before the tests that
use it). Steps are grouped as the review ranks them.

### A. Data loss and lock-outs

### 1. The backup covers the finance tables

Files: `app/Services/BackupService.php`, `resources/views/backup/index.blade.php`,
`tests/Feature/BackupRestoreTest.php`

What:
- `TABLES` becomes, in this order: `users, settings, write_off_reasons,
  products, delivery_runs, product_variants, customers, document_sequences,
  invoices, batches, batch_items, stock_write_offs, orders,
  customer_special_prices, order_items, cheese_cutting_logs,
  cheese_conversion_logs, order_allocations, invoice_lines, credit_notes,
  credit_note_lines, payments, payment_allocations, statements,
  document_emails`. (`invoices` before `orders` because `orders.invoice_id`;
  `invoice_lines` after `order_items`; `credit_note_lines` after
  `invoice_lines`; `payment_allocations` after both `payments` and
  `invoices`.) Update the docblock: the restore's reverse order deletes
  `document_emails` first and `users` last.
- `ADDED_LATER` gains the nine finance tables, all `=> true`, with a
  comment: "cycle 14, finance — a backup from before the invoicing module
  had no invoices, payments or sequences; restoring it as empty is exact".
- View: `$tableLabels` gets every table in `TABLES` (Settings, Write-off
  reasons, Stock write-offs, Invoice numbering, Invoices, Invoice lines,
  Credit notes, Credit note lines, Payments, Payment allocations,
  Statements, Documents sent); the page copy stays ("every business
  table") because it is now true.
- Tests, in `BackupRestoreTest`:
  1. `test_finance_tables_round_trip`: issue an invoice for a delivered
     order, record a payment allocated to it, raise a credit note, generate
     a statement (reuse `InvoicingTestCase`-style setup inline or via the
     services — `InvoiceService::issueForOrder`, `PaymentService::record`,
     `CreditNoteService::issue`, `StatementService::generate`), note
     `DocumentSequence` values; download; wipe the finance tables; restore;
     assert the invoice number, the payment allocation, the credit note
     total, the statement and `document_sequences.next_value` all come back
     identical, and `orders.invoice_id` still points at the restored
     invoice.
  2. `test_a_backup_from_before_invoicing_still_restores`: build a payload
     (via `archiveUpload`) with the nine tables removed → restore succeeds,
     `Invoice::count() === 0`, and the pre-existing business rows are back.
  3. The existing loop in `test_archive_round_trips_and_stores_pii_as_plaintext`
     now covers 25 tables — no change needed, just confirm it passes.

Check: `php artisan test --filter=BackupRestoreTest` → all pass, including
the two new cases. `/backup` as admin lists 25 labelled rows (no raw
`snake_case` names).

### 2. An admin cannot demote or deactivate themselves; new users default to Office

Files: `app/Http/Controllers/UserController.php`,
`resources/views/users/partials/form.blade.php`,
`tests/Feature/UserManagementTest.php` (new)

What:
- `update()`: after computing `$roleChanging` / `$deactivating` and **before**
  the two last-admin checks, if `$user->id === $request->user()->id` and
  either is true, return
  `back()->withInput()->withErrors(['role' => "You can't change your own role or deactivate yourself — ask another admin."])`
  (put it under `is_active` when only deactivating). Nothing is written.
- On a successful role change or deactivation of *another* user the flash
  becomes `"User updated — {$user->name} has been signed out everywhere."`;
  otherwise `'User updated.'`.
- Form partial: when `$user?->id === auth()->id()`, render the role
  `<select>` and the Active checkbox `disabled`, add hidden inputs carrying
  the current `role` and `is_active` (so `UserRequest` still validates),
  and a muted hint under each: "You can't change your own role / active
  flag — ask another admin." The two `disabled` controls must not be the
  only ones posting those names (disabled inputs are not submitted).
- Role default on create: `old('role', $user?->role?->value ?? \App\Enums\UserRole::Office->value)`.
- New `UserManagementTest` (RefreshDatabase; `User::factory()->admin()`):
  - admin PATCHes their own record role → office: redirect with the error,
    role still admin, `$this->assertAuthenticatedAs($admin)` on a follow-up
    GET `/users` → 200.
  - admin PATCHes own record `is_active => 0`: same shape.
  - admin PATCHes another admin to office: 302 to `users.index`, flash
    contains "signed out everywhere", a `sessions` row inserted for that
    user beforehand (`DB::table('sessions')->insert([...])` with
    `id`, `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity`)
    is gone.
  - GET `/users/create` as admin: the selected option is Office
    (`assertSee('value="office" selected', false)` — match the partial's
    actual markup).
  - the edit page for the admin's own record shows the hint and a
    `disabled` role select.
  - last-admin guard still refuses (one active admin demotes themselves →
    the *self* message wins, role unchanged) — one assertion, so the two
    guards are shown to coexist.

Check: `php artisan test --filter=UserManagementTest` → all pass;
`php artisan test --filter=AuthorizationBoundaryTest` unchanged.

### 3. Back + submit no longer records a second write-off or cut

Files: `database/migrations/2026_10_28_120000_add_form_token_to_stock_write_offs_and_cheese_cutting_logs.php` (new),
`app/Models/StockWriteOff.php`, `app/Models/CheeseCuttingLog.php`,
`app/Http/Requests/StockWriteOffRequest.php`, `app/Http/Requests/CheeseCutRequest.php`,
`app/Services/StockWriteOffService.php`, `app/Http/Controllers/StockWriteOffController.php`,
`app/Http/Controllers/CheeseCuttingController.php`,
`resources/views/batches/partials/_write-offs.blade.php`,
`resources/views/cheese-cutting/create.blade.php`,
`tests/Feature/StockWriteOffTest.php`, `tests/Feature/CheeseCutWeightTest.php`

What:
- Migration: on both tables `string('form_token', 36)->nullable()` with a
  unique index (`stock_write_offs_form_token_unique`,
  `cheese_cutting_logs_form_token_unique`), mirroring the payments one;
  `down()` drops both.
- `form_token` into both models' `$fillable`.
- Requests: `'form_token' => ['nullable', 'uuid']` in both rule sets.
- `StockWriteOffService::record()` gains a trailing `?string $formToken = null`
  and stores it on the row. `StockWriteOffController::store()`: before
  `record()`, if `StockWriteOff::where('form_token', $token)->exists()`
  → `toPanel($batch)->with('success', 'Already recorded — this was a repeat submission.')`
  and nothing written; wrap `record()` so a `UniqueConstraintViolationException`
  returns the same redirect. Pass the token through.
- `_write-offs.blade.php`: in the top `@php` block
  `$writeOffFormToken = old('form_token', (string) \Illuminate\Support\Str::uuid());`
  and a hidden `form_token` input in the form.
- `CheeseCuttingController::create()` passes `'formToken' => (string) Str::uuid()`;
  the form posts it as `old('form_token', $formToken)`. `store()`: before
  the transaction, if a `CheeseCuttingLog` with that token exists →
  redirect to where the cut would have landed (index, or the batch page
  after step 21) with `'Already recorded — this was a repeat submission.'`;
  the log row stores the token; catch the unique violation the same way.
- Tests: in `StockWriteOffTest`, post the same payload twice with one
  `form_token` → one row, `quantity_remaining` down once, the second
  response redirects to `#write-offs` with the repeat flash. Same shape in
  `CheeseCutWeightTest` for a cut (one log, one pack item increment, wheel
  decremented once). Also assert a post with **no** token still works (the
  column is nullable — existing tests cover this already; just don't break
  them).

Check: `php artisan migrate` runs clean; `php artisan tinker --execute="echo (int) Schema::hasColumn('stock_write_offs','form_token'), (int) Schema::hasColumn('cheese_cutting_logs','form_token');"`
prints `11`; `php artisan test --filter="StockWriteOffTest|CheeseCutWeightTest|CheeseCutFreeStockTest"`
→ all pass.

### 4. A lost session gets a page, not "419 | PAGE EXPIRED"

Files: `resources/views/errors/419.blade.php` (new), `tests/Feature/ErrorPagesTest.php`

What: `@extends('errors.layout')` with title "Session ended", code `419`,
message "Your session ended before that was saved — sign in and try again."
The layout's link does the rest (guest → "Sign in", signed-in → dashboard /
picking). Test: inside a new test method register
`Route::get('/_test-419', fn () => abort(419))->middleware('web')` and
assert 419, the sentence, the login link, no "Stack trace"; a second case as
a signed-in office user asserts the dashboard link.

Check: `php artisan test --filter=ErrorPagesTest` → all pass.

### B. Figures

### 5. Undoing a release removes the empty mature batch

Files: `app/Http/Controllers/CheeseConversionController.php`,
`tests/Feature/CheeseConversionTest.php`

What: in `undoRelease()`'s transaction, after `$log->delete()`: reload the
mature batch with its items; if every item has `quantity_produced === 0`
**and** `$matureBatch->fresh()->deletionBlocker() === null`, delete the
batch (items cascade) and remember that in a local flag. Redirect: when the
batch was deleted, `redirect()->route('batches.show', $source->batch)` (the
mature page is gone) with the flash
`"Returned {N} {wheel|wheels} to the farmhouse maturing hold. Batch {code} held nothing else and was removed."`;
otherwise `back()` with the existing sentence (pluralised, step 16).
Tests: change `test_undo_release_returns_wheels_to_the_hold` to assert the
mature batch and item are gone (`Batch::find($id)` null) and the redirect
lands on the source batch page; add `test_undo_keeps_a_mature_batch_that_still_holds_history`:
release 3, record and reverse a 1-wheel write-off on the mature item (so
`available_quantity` is back to 3 but a write-off row exists), undo →
wheels return, batch **kept** at 0 produced, flash without "removed"; add
`test_a_second_release_after_undo_recreates_the_batch_with_the_same_code`
(release → undo → release → `batch_code` equals the first one, one log).
Also check `/batches`, `/stock` and the farmhouse card no longer list the
ghost after the first test (assert the mature code is absent from
`GET /batches` and `GET /stock`).

Check: `php artisan test --filter=CheeseConversionTest` → all pass.

### 6. The converted item says where its weight came from

Files: `resources/views/batches/show.blade.php`

What: in the Unit weight cell (line 185), when `$batch->source_batch_id`
is set, append a muted `<span class="block text-[11px]">nominal from the source wheel</span>`
under the figure. Nothing else (the figure is right; the variant's 2.3 kg
is data — step 24).

Check: `CheeseConversionTest` still passes; a mature batch page shows the
note, a farmhouse one does not (one assertion added to
`test_undo_keeps_a_mature_batch_that_still_holds_history` or a fresh case).

### 7. "Active" and "Ready to sell" exclude expired batches; a filtered empty state says so

Files: `app/Http/Controllers/BatchController.php`, `resources/views/batches/index.blade.php`,
`tests/Feature/DashboardTest.php`

What:
- `status=active` → `where('status','active')->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>', today()))`.
- `maturation_status=ready` → the existing `ready_date` clause **and**
  `where('status', '!=', 'expired')` and the same expiry clause. (`maturing`
  unchanged; `sold_out` unchanged.)
- Empty state: `@php $filtered = request()->hasAny(['type','status','maturation_status']); @endphp`
  → filtered: heading "No batches match these filters", a
  "Clear filters" link to `route('batches.index')`, no Create button;
  unfiltered: the current copy.
- Tests in `DashboardTest` (it owns the `batch()` helper and the expired
  filter case): active filter lists the sellable batch's code and not the
  active-past-expiry one; `?status=sold_out` with nothing sold out shows
  "No batches match these filters" and not "Create first batch".

Check: `php artisan test --filter=DashboardTest` → all pass.

### 8. The batch page's "Sold" column is "Picked / sold"

Files: `resources/views/batches/show.blade.php`

What: header text "Picked / sold" with
`title="Picked for an order — dispatched or still on the floor. Cut, converted and written-off units are their own columns."`.
The figure is unchanged.

Check: grep the tests for `>Sold<` / `'Sold'` (the Planner found none);
`php artisan test --filter="BatchFinishedWeightTest|StockWriteOffTest"`
→ pass.

### 9. Void invoices export with zero money

Files: `app/Http/Controllers/Invoicing/ExportController.php`,
`tests/Feature/InvoicingExportTest.php`

What: in `invoiceRows()`, when `$invoice->status === InvoiceStatus::Void`,
emit `0.00` for Net, VAT, Gross, Paid, Credited and Balance (row kept,
status `void`, number and customer kept). Update the docblock: "void
invoices are kept as rows with zero amounts so the sum is still the net
turnover". Test: issue two invoices, void one (as `InvoiceVoidTest` does),
download → the void row has `0.00` in the six money columns and `void` in
Status; the live row is unchanged.

Check: `php artisan test --filter=InvoicingExportTest` → all pass.

### 10. No charge toggle on a run that has no charge

Files: `resources/views/delivery-runs/index.blade.php`, `tests/Feature/DeliveryChargeTest.php`

What: between the percentage branch and the form, add
`@elseif ((float) $run->delivery_charge <= 0)` → a muted `<span x-show="showCharges" x-cloak>`
reading "run has no charge" linking to `route('delivery-runs.edit', $run)`
(title "Set a delivery charge on the run first"). Test: a run with
`delivery_charge => 0` and one stop → the index does **not** contain
`route('delivery-runs.toggle-charge', $stop)` and does contain "run has no
charge"; the existing €4.50 case still sees the toggle.

Check: `php artisan test --filter=DeliveryChargeTest` → all pass.

### 11. "Used" counts active write-offs; reversed ones are named

Files: `app/Http/Controllers/WriteOffReasonController.php`,
`resources/views/write-off-reasons/index.blade.php`, `tests/Feature/StockWriteOffTest.php`

What: `withCount(['writeOffs as active_count' => fn ($q) => $q->whereNull('reversed_at'), 'writeOffs as reversed_count' => fn ($q) => $q->whereNotNull('reversed_at')])`;
the cell prints `active_count`, and when `reversed_count > 0` a muted
`<span class="text-[11.5px]">+ N reversed</span>`. Test: a reason with one
reversed write-off reads `0` with "+ 1 reversed"; a reason with one active
reads `1`.

Check: `php artisan test --filter=StockWriteOffTest` → all pass.

### C. Roles

### 12. The factory's expired tile does not say "write off"

Files: `resources/views/dashboard.blade.php`, `tests/Feature/DashboardTest.php`

What: `:sub="number_format($expiredUnits).' units'.($user->can('create', \App\Models\StockWriteOff::class) ? ' · write off' : '')"`.
Test: extend `test_ready_now_counts_only_sellable_batches_and_expired_stock_has_a_tile`
— the factory response `assertSee('12 units')` and `assertDontSee('write off')`;
the office assertion on line 71 stays.

Check: `php artisan test --filter=DashboardTest` → pass.

### 13. Factory sees no Edit link on the products index

Files: `resources/views/products/index.blade.php`, `tests/Feature/ProductStockFigureTest.php`

What: wrap the separator + Edit link (lines 76–77) in `@can('update', $product)`.
Test: factory `GET /products` → `assertDontSee(route('products.edit', $product), false)`;
office → `assertSee(...)`.

Check: `php artisan test --filter=ProductStockFigureTest` → pass.

### 14. The driver dashboard tells the truth

Files: `resources/views/dashboard.blade.php`, `tests/Feature/DashboardTest.php`

What: replace the two sentences with "The run sheet hasn't been opened to
drivers yet. The office has your stops for today." Test: driver `GET /dashboard`
→ 200, sees the new sentence, does not see "once delivery routes are set up".

Check: `php artisan test --filter=DashboardTest` → pass.

(Finding 16 — role default — is in step 2.)

### D. Words and states

### 15. Release is hidden when Mature cheese is not set up

Files: `app/Http/Controllers/CheeseConversionController.php`,
`resources/views/cheese-conversion/index.blade.php`, `tests/Feature/CheeseConversionTest.php`

What: `index()` passes `'matureReady' => $matureProduct !== null && $this->matureWheelVariant($matureProduct) !== null`.
View: the Release control's condition gains `&& $matureReady`; when
`! $matureReady`, a `mf-flash mf-flash-warn` strip under the page intro
(office/admin only — `@can('create', CheeseConversionLog::class)`):
"Mature cheese isn't set up as a product yet — add it under Products with
'Made only by conversion' ticked, with a Whole Wheel variant." linking to
`route('products.create')`. `release()`'s refusal uses the same sentence
(drop "Run the product seeder."). Test: delete the mature product in a
case → index shows the strip and no `release-form-`; POST release → error
with the new sentence; factory sees neither the strip nor the button.

Check: `php artisan test --filter="CheeseConversionTest|MatureProductSeedTest"` → pass.

### 16. The release flash names the batch; "wheel(s)" becomes a word; dialog bodies are consequences

Files: `app/Http/Controllers/CheeseConversionController.php`,
`resources/views/cheese-conversion/index.blade.php`, `resources/views/batches/show.blade.php`,
`resources/views/orders/show.blade.php`, `tests/Feature/CheeseConversionTest.php`

What:
- `release()` flash: `"Released {N} {wheel|wheels} into mature batch {code} ({product name}). Undo from that batch's page."`
  (the mature batch is in scope inside the transaction — capture it).
- `hold()` / `undoRelease()` flashes and the three `wheel(s)` view sites use
  `Str::plural('wheel', $n)`.
- Dialog bodies: Release → "The wheels leave this farmhouse batch and become
  mature cheese stock, in a batch traced back to it." (both the static
  `data-confirm` and the `x-on:input` rewrite — keep the count in the
  rewrite: "N wheels leave …"); Return (batch page) → "They go back into the
  farmhouse batch's maturing hold; this mature batch loses them."
- `orders/show.blade.php` 348: `{{ $n }} {{ Str::plural('unit', $n) }} across`.
- Test: `test_release_converts_held_wheels_to_mature` additionally asserts
  the flash contains the mature batch's `batch_code` and "Undo from".

Check: `php artisan test --filter="CheeseConversionTest|ConfirmDialogTest|OrderShortSupplyTest"` → pass.

### 17. A refused weight keeps the weights already typed

Files: `resources/views/picking/item.blade.php`, `tests/Feature/PickingTest.php`

What: initialise the pick form's Alpine state from `old()`:
`batch: @js((string) old('batch_item_id', $initial['batchItem']->id))`,
`qty: {{ (int) old('quantity', $initialQty) }}`,
`weights: @js((object) array_values((array) old('weights', [])))`,
`bulkWeight: @js((string) old('actual_weight_kg', ''))`, and an
`init() { this.clamp(); }` so a stale qty is clamped to the batch. The
`weights[]` inputs are already `x-model`-bound by index, so they repopulate.
Test: per-unit variant, needed 2, post `weights => ['5.1', '52']` → the
redirect `assertSessionHasInput('weights')`; follow with a GET of the item
page and `assertSee('5.1')` plus the "Check the decimal point" message.

Check: `php artisan test --filter=PickingTest` → pass.

### 18. The pick screen names the batch's real stock

Files: `resources/views/picking/item.blade.php`, `tests/Feature/PickingTest.php`

What: add `$batchStockJson` (item id → raw `$option['max']`) beside
`$batchMaxJson`; the stepper line reads
`of {{ $needed }} needed · <span x-text="batchStock[batch]"></span> in this batch`;
each batch row reads `Produced dd/mm · {{ $option['max'] }} in stock{{ $j === 0 ? ' · oldest first' : '' }}`
(drop "pickable" and "(FIFO)"; line 52's "in stock (FIFO)" → "in stock ·
oldest first"). The stepper's clamp (`batchMax`) is unchanged. Test: a
line needing 2 from a batch of 23 → page shows "23 in stock" and
"in this batch", not "pickable".

Check: `php artisan test --filter=PickingTest` → pass.

### 19. Next up never points at a dead order; the day header says when a day is past

Files: `app/Http/Controllers/PickingController.php`, `resources/views/picking/show.blade.php`,
`resources/views/picking/index.blade.php`, `tests/Feature/PickingTest.php`

What:
- `show()`: `$nextOrder = same-day candidate ?? first candidate whose delivery_date is null or >= today`;
  a past-dated order is never offered unless it is the same day as the one
  just finished.
- `picking/show.blade.php` next-up card: when `$nextOrder->isDeliveryOverdue()`,
  a `mf-tag mf-tag-warn` "Past its delivery date" beside the date.
- `picking/index.blade.php` day header: when `$selected` is a date before
  today, the same tag beside the `<h1>`; the footer label reads "Start
  picking" when `$continueTo['state'] === 'todo'`, "Continue picking" when
  `picking`.
- Tests: (a) today's order completed with a July confirmed order and a
  tomorrow order in the queue → next-up is tomorrow's; with only the July
  order → no next-up card; (b) `GET /picking?date=<past day with work>`
  shows "Past its delivery date"; today does not; (c) a day with only
  untouched orders shows "Start picking".

Check: `php artisan test --filter=PickingTest` → pass.

### 20. The credit-note form prefills VAT, leaves amounts blank, and says so

Files: `resources/views/invoicing/credit-notes/create.blade.php`, `tests/Feature/CreditNoteTest.php`

What: `creditNoteForm(creditable, rates, amounts)` taking
`@js(array_map(fn ($l) => (float) $l['vat_rate'], $suggested))` and
`@js(array_map(fn ($i) => (string) old("lines.$i.amount", ''), array_keys($suggested)))`,
assigning both into the component's state (`rates` / `amounts` keyed by
index); remove the now-redundant `value=` attributes from the two
`x-model` inputs. Copy: "Enter the amount to credit on each line and leave
the others blank." Test: GET the create page for an invoice whose line has
a VAT rate → the page contains the rates array with that rate, and no
`lines[0][amount]` input carries a `value="…"` (a `preg_match` on the
content).

Check: `php artisan test --filter=CreditNoteTest` → pass.

### 21. Cutting from the batch page returns to the batch; the date box knows its bounds

Files: `resources/views/batches/show.blade.php`, `app/Http/Controllers/CheeseCuttingController.php`,
`resources/views/cheese-cutting/create.blade.php`, `tests/Feature/CheeseCutWeightTest.php`

What:
- Batch page: the "Cut …" buttons link `route('cheese-cutting.create', ['batchItem' => $wheelItem, 'return' => 'batch'])`;
  give the cutting-logs panel `id="cutting" class="… mf-landing"`.
- `create(Request $request, BatchItem $batchItem)`: `$returnTo = $request->query('return') === 'batch' ? route('batches.show', $batchItem->batch) . '#cutting' : route('cheese-cutting.index');`
  passed to the view; the three links (Back, dead-end Back, Cancel) use it
  and the form posts a hidden `return`.
- `store()`: on success redirect to `batches.show` + `#cutting` when
  `$request->input('return') === 'batch'`, else the index (existing). The
  refusal redirect to `cheese-cutting.create` carries `['return' => $request->input('return')]`
  when set.
- `cut_date` input gets `min="{{ production date }}"` and `max="{{ today }}"`.
- Tests: post with `return => 'batch'` → redirect to the batch page with
  `#cutting`; the existing line-104 case (no `return`) still lands on the
  index; the create page with `?return=batch` has a Cancel link to the
  batch page.

Check: `php artisan test --filter="CheeseCutWeightTest|CheeseCutFreeStockTest"` → pass.

### 22. No empty Actions panel on milk and yoghurt batches

Files: `resources/views/batches/show.blade.php`, `tests/Feature/UxCopyTest.php`

What: wrap the whole Actions `mf-panel` (275–384, including the lineage
block) in `@if ($batch->product->type === 'cheese')`. Test: extend
`test_the_batch_page_does_not_promise_more_actions` (or add a case) — a
milk batch page does not contain `>Actions<`; a cheese one does.

Check: `php artisan test --filter=UxCopyTest` → pass.

### 23. Generate statement lands on the statement

Files: `app/Http/Controllers/Invoicing/StatementController.php`,
`resources/views/invoicing/statements/index.blade.php`, `tests/Feature/StatementTest.php`

What: every `back()` in `store()` becomes
`redirect()->route('invoicing.statements.index')->withFragment('statement-'.$statement->id)`
(same flashes; the generated-but-not-sent error keeps its text). Index rows
get `id="statement-{{ $statement->id }}" class="mf-landing"`. Test: the
line-139 POST asserts the redirect target with the fragment and that the
index page contains that `id`.

Check: `php artisan test --filter=StatementTest` → pass.

### 24. Dev database tidy (data, not code)

Files: none

What, with `php artisan tinker`:
1. Batch 25 (the ghost from finding 5): if `Batch::find(25)->batchItems->sum('quantity_produced') === 0`
   and `deletionBlocker()` is null, delete it. Record the output.
2. Product variant 15 (dev Mature Whole Wheel): `weight_kg` → `5.000`, to
   match the dev Farmhouse wheel it is converted from. Record before/after.
Leave `review_test` (user 9) alone.

Check: `/batches` no longer lists G030426-1; the G030426 card no longer says
"Matured into".

## Verification

1. `php artisan migrate` → one new migration applied.
2. `php artisan test` → **0 failed**; expect roughly 770 tests (746 + the
   new cases). Paste the summary line.
3. `./vendor/bin/pint --test app tests database routes config` → clean.
4. `php artisan test --filter="BackupRestoreTest|UserManagementTest|ErrorPagesTest|CheeseConversionTest|DashboardTest|PickingTest|StockWriteOffTest|CheeseCutWeightTest"`
   → pass (the eight suites that carry new cases).
5. `grep -rn "wheel(s)\|units across\|Run the product seeder\|once delivery routes\|pickable" app resources/views`
   → no matches.
6. Browser (for Jon, after acceptance): `/backup` shows 25 labelled tables;
   `/users/8/edit` as `admin` shows a disabled role and the hint; write off
   1 tub on `/batches/23`, press Back, click Write off → "Already recorded";
   log out in a second tab, reverse a write-off in the first → the 419 page
   with Sign in; release 1 wheel on `/cheese-conversion`, open the mature
   batch, Return to farmhouse hold → lands on G030426 and the mature batch
   is gone from `/batches` and `/stock`; `/batches?status=active` has no
   Expired cards; the picking item page keeps 5.1 after 52 is refused.

## Risks

- **Backup table order.** Restore disables FK checks, so a wrong order only
  matters for readability — but `export()` orders rows by `id` per table and
  nothing else, and a backup with the nine tables restored on a box whose
  `document_sequences` were *higher* will lower the counter to the backup's
  value. That is correct (the invoices come with it) but worth a line in
  `DEPLOYMENT.md`'s backup note if it has one; the Implementer should say
  in Notes whether it does.
- **Self-guard vs. the hidden inputs.** If the Implementer disables the
  controls without the hidden inputs, `UserRequest` fails on `role`
  required and the admin sees a validation error instead of the hint. The
  test for the own-record edit page must POST the rendered form's values.
- **Form token and `old()`.** On a refused write-off the form re-renders
  with `old('form_token')` — the same token — which is right: the retry is
  the same submission. Only a *successful* write stores the token, so a
  refusal never blocks the retry.
- **Deleting the mature batch** is new behaviour. `deletionBlocker()` is the
  guard; any history row (even a reversed write-off) keeps the batch. If a
  production mature batch ever sits at 0 produced from an earlier undo, it
  stays until someone undoes again — acceptable; note it in Notes if seen.
- **`maturation_status=ready` now hides expired cheese.** A closed-as-expired
  cheese batch with wheels is reachable under Expired, as before.
- **Alpine `init()` in an object literal** — `x-data="{ …, init() {…} }"`
  is called automatically by Alpine; no `x-init` needed, but do not also
  add `x-init="clamp()"` or it runs twice (harmless, but noise).
- **Finding 6 in production** — the production Mature variant may carry the
  seeder's 2.3 kg beside a 2.5 kg Farmhouse wheel, which is coherent; or
  Jon's real 5 kg wheels, in which case the Mature variant should be set
  to match from `/products`. Not this cycle's call.
- **Yoghurt shelf life** is data, not a bug: Jon sets it on the product.

## Review

Reviewed 2026-10-28 by the Planner (Fable) against `implemented.md` and the
full `git diff` (51 tracked files, 923+/88−, plus the new migration, the
419 view and `UserManagementTest`). Re-ran the verification myself:
`php artisan test` → **780 tests, 0 failed (5016 assertions)**; `pint --test`
→ PASS, 316 files; `git status` matches the report's file list exactly.

**Accepted — Status: ACCEPTED.** All 24 steps are done as planned, and the
Check for every step holds. What I verified beyond the test run:

- **Step 1** — `TABLES` is in FK order (`invoices` before `orders` for
  `orders.invoice_id`; `invoice_lines` after `order_items`; the restore
  runs inside `withoutForeignKeyConstraints` anyway) and the nine finance
  tables are `ADDED_LATER => true`. The round-trip test checks the
  `document_sequences` map, so a restore that lowers invoice numbering is
  visible in the test, not just in prose.
- **Step 2** — the self guard sits before the last-admin checks, keys the
  error on `role` or `is_active`, and the form carries hidden current values
  behind disabled controls, so saving your own name posts unchanged
  role/active and passes. `destroy()` and `deactivate()` already refused
  self (unchanged).
- **Step 3** — the token is minted per render via `old('form_token', uuid)`,
  so a refused submit keeps its token and a successful one stores it; the
  pre-check plus the `UniqueConstraintViolationException` catch outside the
  transaction covers both Back+submit and a double-click race. The repeat
  check runs before the free-wheel check on cuts (right: the second post of
  the last wheel's cut must say "Already recorded").
- **Step 5** — `fresh('batchItems')` loads items without counts, so
  `deletionBlocker()` takes its query branch and counts *after*
  `$log->delete()`; `batch_items.batch_id` cascades. The second-release test
  proves the code is reused.
- **Step 17** — `weights` is seeded 0-based from `array_values(old())`,
  matching the `x-model="weights[i-1]"` rows; `init()` is Alpine's
  auto-called hook, no `x-init` duplicate.
- **Step 20** — `lines.*.amount` is `nullable` and `issue()` drops
  `amount <= 0`, so blank amounts submit cleanly; `description` is
  `required_with:amount`, so a blank line needs nothing.
- **Step 19** — `$selected` is a date string or `'none'`; the view guards
  `'none'` before `Carbon::parse`.

**Deviations — all accepted:**

1. The four test edits are each the minimum the plan's own change forced
   (`AuthorizationBoundaryTest` copy assertion; the two `MATURE_NOT_SET_UP`
   assertions, which would otherwise pass vacuously; the `ChilledRunTest`
   fixture now charges €4.50 so the toggle it asserts still exists;
   `flushSession()` in `ConsolidationPassTest`). The routes matrix is
   untouched. The plan should have named them.
2. "← Back to batch" when `return=batch` — correct; the plan omitted it.
3. Hiding the sibling `release-form-{id}` as well as the control — better
   than the plan: no release form exists on the page at all.
4. The `cut()` extraction so the unique-violation catch sits outside the
   transaction — the right shape.
5. `MATURE_NOT_SET_UP` as a public const shared by view, refusal and tests —
   good; one string, three readers.

**Notes for Planner — decisions:**

1. *No backup note in `DEPLOYMENT.md`* — **deferred to the next cycle** as
   one step: a sentence on the `/backup` page ("Restoring resets invoice
   numbering to the backup's") and a short § in `DEPLOYMENT.md` naming the
   page. Recorded in the vault's open items.
2. *Statements landing is page 1 only* — **accepted as-is**. 30 per page,
   `latest('period_end')`; a statement for an old period can sort past page 1
   and the fragment finds nothing, but the flash still lands. Revisit if the
   list ever grows past a page.
3. *`PickingTest::batchItem()` makes cheese batches that aren't ready* —
   **deferred to the next cycle** as a test-hygiene step: set `ready_date`
   after create in the helper so the item-page tests exercise the chooser.
4. *Flash names the mature batch, whose code prefix-matches the source* —
   **recorded** in `TESTING_AND_FIXES.md` as a trap for `strpos($html,
   $code)` tests. No code change.
5. *`$pickable` keeps the grep from reaching zero* — **rejected** (a rename
   for a grep's sake); the verification grep should have said "rendered
   text". Recorded.
6. *Migration applied to the dev DB* — as expected.

Nothing to send back. Jon archives: `mkdir -p
docs/planImp/archive/2026-10-28-ux-review-part-2`, move `plan.md`,
`implemented.md` and `UX_REVIEW_2026-10-28.md` there (the review file too,
as cycle 13 did), then commit the cycle with explicit `git add` paths.
