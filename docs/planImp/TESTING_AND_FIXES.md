# Testing & Fixes

Running log of deliberate test passes over this app and the defects they turned
up. One section per audit, newest first. The point is that a bug found once
stays found: every finding below names the test that pins it, so a regression
shows up as a failure rather than as a phone call from the office.

## Running the suites

```bash
php artisan test
```

Targeted runs:

```bash
php artisan test --filter=StockFlowAuditTest
php artisan test --testsuite=Feature
./vendor/bin/pint --test
```

Tests run on **MySQL** — `phpunit.xml` hardcodes the `mossfield_test` database,
its user and its password on `127.0.0.1:3306`, rather than inheriting them from
`.env`. Two consequences worth knowing: `php artisan test` can never touch your
dev data, and the credentials there are not a free choice — the app `.env` uses
the same user, so one credential set covers both.

MySQL is deliberate, not incidental: `lockForUpdate()` and queued jobs then
behave as they do in production, which the concurrency and allocation tests
depend on. Nothing in this app runs on SQLite, in any environment — but
`config/database.php` still falls back to `'sqlite'` when `DB_CONNECTION` is
unset, and `.env.example` still ships `DB_CONNECTION=sqlite`, so a fresh
`cp .env.example .env` silently points dev at a SQLite file while the suite
stays on MySQL. Set the MySQL block by hand.

### Resetting the dev database between passes

Every browser pass leaves residue (test customers, probe orders, bogus cut
logs). The seeders can't clear it — `db:seed` is additive (`BatchSeeder` adds
3–5 batches per product per run, `CustomerSeeder` has no duplicate guard) and
`migrate:fresh --seed` throws away the real customer and user rows that no
seeder can rebuild. So a pass starts and ends with:

```bash
php artisan mossfield:reset-trading-data --seed=1            # dry run: prints the plan, changes nothing, exit 1
php artisan mossfield:reset-trading-data --seed=1 --confirm  # executes
```

- **Keeps** users, customers (+ `customer_special_prices`), products, product
  variants, delivery runs.
- **Wipes**, in FK-safe order and explicitly (no cascade is relied on):
  document_emails, statements, payment_allocations, payments,
  credit_note_lines, credit_notes, invoice_lines, invoices, order_allocations,
  cheese_cutting_logs, cheese_conversion_logs, order_items, orders,
  batch_items, batches. One transaction. Then `document_sequences` restart at 1
  (so the next invoice is `INV-000001` again) and the wiped tables'
  `AUTO_INCREMENT` restarts (so "/batches/17" in a write-up means the same row
  after the next reset). Queued `jobs` are left alone but counted in the plan.
- **Regenerates** stock by running `BatchSeeder` against the surviving
  products — except Mature, which is never made fresh.
- **`--seed=N`** seeds the MT generator before the seeder, which draws
  everything from `rand()`/`array_rand()`, so the same seed reproduces the same
  batches, items and quantities. Production dates are relative to *today*, so
  batch codes (`Mddmmyy`) drift with the calendar while counts, quantities and
  day-offsets stay fixed: a pass is exactly reproducible on the same day, and
  reproducible up to the date stamp on another. Use the same seed for a whole
  pass and write it down next to the figures.
- Refuses in production; refuses to do anything without `--confirm`.

`ResetTradingDataTest` pins all of that, and also fails if a migration adds a
table nobody has classified as kept, wiped or infrastructure — so a new trading
table can't quietly survive resets. It should be run **before** a pass (known
starting state) and **after** it (so the next person doesn't inherit your
probes); the 2026-09-12 pass below was cleaned up by hand instead, which is
what prompted the command.

## Known-failing baseline

**None.** The suite reads green; any failure is real.

Until 2026-10-04 seven stock Laravel Breeze tests failed on every run
(`Auth\RegistrationTest`, `Auth\EmailVerificationTest`, one case each in
`Auth\AuthenticationTest` and `ProfileTest`) because they tested
self-registration and email verification, both deliberately disabled in this
app. The registration/verification tests, their orphaned controllers and
views were deleted; the other two were corrected to post the `login` field
and to stop asserting `email_verified_at` behaviour. If a future Breeze
upgrade re-scaffolds them, delete them again rather than re-documenting a
baseline.

---

## 2026-10-06 — Settings (Plan/Implement cycle 10)

**Scope:** Jon asked for the batch ceilings (cycle 8) to be changeable by an
admin without a deploy. Suite afterwards: **695 passed, 0 failed** (+11;
`SettingsTest` is new).

- **`settings` table** (`key` string PK, JSON `value`, `updated_by`) behind a
  static API on `App\Models\Setting`: `get($key, $default)`, `set()`,
  `forget()`, `flushCache()`, and `batchCeiling($type)` — the one read site
  (`BatchController::store()`). **Config stays the default**: no rows ⇒
  exactly today's behaviour; a stored value wins. All rows are read once per
  request and cached, keyed on the `app()` instance so every test and every
  request under a long-lived worker starts from the database; `set()`,
  `forget()` and `BackupService::import()` clear it (a restore otherwise left
  stale values cached — red-checked).
- **`/settings`** (admin-only: `role:admin` group, `SettingPolicy` mirrors
  `UserPolicy`, `SettingsRequest` carries the policy) — one panel, three
  number inputs with "default 5 000" and "changed by {name} on {date}", Save
  naming each change ("cheese 200 → 500"), **Reset to defaults** (PUT
  `reset=1`, confirm) shown only when something is stored. Nav: Admin →
  Settings.
- Housekeeping: `ResetTradingData::KEEP`, `BackupService::TABLES` with
  `ADDED_LATER['settings'] = false` (older backups keep current settings);
  the authorisation matrix covers the two routes automatically.
- The dev `settings` table is empty — defaults in force until Jon enters his
  numbers at `/settings`.

### Invariants worth keeping

- **Config is the default, the DB is the override, and `Setting::` is the
  only reader.** Don't `config()` a tunable value anywhere else.
- **Anything that writes `settings` outside the model clears the cache**
  (`Setting::flushCache()`), as `BackupService::import()` does.
- **The next admin setting** = a config default + a key + a form field. A
  second one justifies a small registry (key → label, rules, default) rather
  than another hand-written block in the controller and request.

---

## 2026-10-06 — Polish (Plan/Implement cycle 9)

**Scope:** the follow-ups deferred by the cycle-6/7/8 reviews. Suite
afterwards: **684 passed, 0 failed** (+10). No stock or money moved.

- The batch card's items table reads a wheel row's **Sold** from
  `wheelStates()` (with "· N cut · N converted" beside it) instead of
  `quantity_sold`, which counts cut and converted wheels as sold.
- An expired card that has been fully written off shows a **"Written off"**
  tag where the "Write off →" link was.
- The order page's billing line has three states: `ready` → "Awaiting
  dispatch — invoiced when dispatched"; dispatched/delivered uninvoiced →
  "Awaiting invoice · Finance"; invoiced → the number.
- `UninvoicedOrderController` refuses to the queue page, never `back()`.
- The run sheet's sticky header is keyed on the **column count**: ≤ 11
  quantity columns stick at `xl`, ≤ 15 at `2xl`, more always scroll (60 px per
  column; 11 columns = 983 px at 1280; the non-quantity columns take ~323 px).
- Every fragment landing carries **`mf-landing`** — `scroll-margin-top:
  calc(var(--mf-feedback-h) + 1rem)` plus the `:target` tint; the run-sheet
  rows add the header height by context. `LayoutFeedbackTest` greps the
  views so the old fixed margin can't creep back.

### Dev-database action on acceptance

The two stale July `ready` orders (ORD-20260710-001 An Tairseach, 81 units;
ORD-20260723-001 Barnhill, 31 units) were **cancelled** by the Planner through
the same path `OrderController::update()` uses (every line's committed units
released, status `cancelled`, totals recalculated). They were never
dispatched; the picked stock went back to their (long-expired) July batches,
which now show that much more expired stock — honest dev data.

### Invariants worth keeping

- **A refusal test must not GET the page first.** The session's previous URL
  makes `back()` land where an explicit redirect would, and the test passes
  on broken code (found red-checking step 4).
- **Fragment targets carry `mf-landing`**, nothing else; the sheet's header
  allowance lives in `.mf-run tbody tr.mf-landing`.
- **The sticky sheet header never spills the panel**: the column-count
  thresholds are the only switch; a new wrapper with `overflow` set breaks it.

---

## 2026-10-05 — The policy batch (Plan/Implement cycle 8)

**Scope:** `OPEN_QUESTIONS.md` #5 — eight rules the end-to-end run found the
app had no opinion on, decided by Jon (seven on 2026-10-04, F-38 on
2026-10-05) and built in one cycle. Suite afterwards: **674 passed, 0 failed**
(+27 net; `CreditLimitWarningTest` is new).

### The rules, as built

- **One delivery charge per drop** (F-24). `Customer::currentDeliveryCharge()`
  takes the delivery date; a second non-cancelled order for the same
  customer and day with a charge already on it gets €0, at every creation
  site (the two Mossorders imports carry no date, so nothing changes there
  until the payload does). The flash names the order carrying the charge;
  the order form's preview shows €0 "(already charged on ORD-… that day)".
  Percentage customers are unaffected. **Caveat:** cancelling the charged
  order leaves the other at €0 — the office edits the surviving one.
- **The credit limit warns, never blocks** (F-25). `Customer::creditWarning()`
  — "{name} is at €X outstanding against a €L credit limit — this order
  takes it to €Y." — as a warning flash at create, at confirm and at
  Confirm all (one line per customer), and as a banner on open orders
  (`see-financials`). `credit_limit = 0` means no limit.
- **Mature is made by conversion only** (F-10). `products.conversion_only`,
  set on Mature **by the migration**; the batch form omits such products
  and `store()` refuses them; the conversion controller finds Mature by the
  flag (name as fallback) and refuses it as a *source* by id.
- **A sanity ceiling** (F-09). `config('mossfield.batch_ceilings')` per type
  (milk 5 000, yoghurt 3 000, cheese 200 units per variant — starting
  guesses; Jon sets them); above it the form comes back with "{N} × {variant}
  — the usual ceiling is {C}. Tick 'Yes, this batch really is that big' to
  save it."
- **Milk yield is computed** (F-06) — bottled ÷ raw like every other type —
  and bottling more litres than the raw milk entered is refused.
- **Order notes are internal** (F-33) — gone from the dispatch confirmation
  and the docket (PDF text verified); the order form says so.
- **Mark dispatched requires the loaded tick** (F-34) — enforced in
  `DispatchService::dispatch()` so the order page and the run button share
  the rule; the order page gains a "Loaded onto van" tick (`OrderPolicy::load`,
  factory may) via `toggleLoaded(return=order)`.
- **Issue only for dispatched/delivered** (F-38a) — the `InvoiceService`
  guard; `ready` orders sit in the To-Invoice queue under "Picked, awaiting
  dispatch" with no Issue button, and the dashboard tile counts only
  billable orders.
- **Weekly/monthly customers default to Net 14** (F-38b) on the customer
  form when the mode is chosen; the cycle-5 hint still shows if the office
  sets Immediate back.

### Follow-ups (cycle 9, polish)

- The order page's billing line says "Awaiting invoice" for a `ready` order;
  "Awaiting dispatch" is now the truth.
- `UninvoicedOrderController::issue()` refuses with `back()`.
- Two July `ready` orders on dev (ORD-20260710-001, ORD-20260723-001) are
  stale test data — dispatch or cancel them.

### Invariants worth keeping

- **Dispatch and issue rules live in the services**, not the controllers:
  `DispatchService` requires `ready` + `loaded_at`; `InvoiceService` requires
  `dispatched|delivered`. A new button inherits both.
- **The credit limit never blocks.** If that ever changes, it is a decision
  for Jon, not a code review.
- **Conversion-only products have no production batches.** The flag, not
  the name, is the rule.

---

## 2026-10-05 — Stock write-offs (Plan/Implement cycle 7)

**Scope:** `OPEN_QUESTIONS.md` #1, decided by Jon on 2026-10-04 and built in
one cycle. Stock can now leave a batch a fifth way — **written off** — with a
reason from an extendable list, an optional note, and for weighed stock the
measured weight of the lost units. Suite afterwards: **647 passed, 0 failed**
(+30; `StockWriteOffTest` is new with 26 cases).

### What it does

- **Record** (office/admin, batch page → Write-offs panel): units come off
  `batch_items.quantity_remaining`, which every free-stock surface already
  reads — so the allocator, picker, `/stock`, cutting index and the mossorders
  export all exclude them with no change at those sites (pinned, one
  assertion per site). The bound is `holdable_quantity` (remaining − units
  still owed to orders): reserved stock can't be written off ("Only N units
  can be written off — M are reserved for orders. Release them first.");
  **held maturing wheels can**, and the hold is clamped with the flash saying
  so. Weight is refused on fixed-weight variants and above 3 × nominal.
- **Reverse**: the row stays, stamped `reversed_at/by`; units return to free
  stock (not to a hold).
- **Reasons**: `write_off_reasons`, seeded **by the migration** (spoiled,
  damaged, sample, own use, stocktake, expired, returned) so no deploy step
  is forgotten; managed at `/write-off-reasons` (Miscellaneous nav,
  office/admin): add (slug code), rename, deactivate — never delete
  (`restrictOnDelete` is the backstop).
- **Expired one-click**: an expired batch's page offers "Write off expired
  stock (N units)"; it writes off each item's holdable units with reason
  `expired` and names what it kept for orders. On dev M230726's 951 ghost
  units are gone; the dashboard tile dropped 5 → 4 batches.
- **The model**: `on hand + dispatched + cut + converted + written off =
  produced`. A weighed write-off is measured (counts toward "N of M wheels
  weighed"), an unweighed one nominal; written-off units stay in finished
  weight and yield (the make produced them). `wheelStates()` gains
  `written_off` (red circle, `--state-written-off`); `/stock` cards gain a
  `written off` segment; `quantity_sold` and the two other
  "sold = produced − remaining" sites (batch-page variant table, card pack
  bar) subtract write-offs.
- **Housekeeping**: `ResetTradingData` wipes `stock_write_offs`, keeps
  `write_off_reasons`; `BackupService::TABLES` carries both — and gained
  **`ADDED_LATER`**, because otherwise every backup taken before this deploy
  would have been refused as "missing the write_off_reasons table". A
  missing `stock_write_offs` restores as empty; a missing
  `write_off_reasons` keeps the current rows. **Any table added to
  `TABLES` from now on goes in `ADDED_LATER` too.**

### Interpretation recorded

"Units and kilograms" = whole units, with kg as the measured weight of
those units. Partial-weight loss on a unit that stays on the shelf is out
of scope (a sixth state); raise it if it matters.

### Follow-ups (next polish batch)

- The card's items table lumps cut and converted wheels into "Sold"
  (pre-existing) — use `wheelStates()['sold']`.
- `/batches?status=expired` lists a fully written-off batch with no sign
  that it's been dealt with — a "written off" tag on the card.

### Invariants worth keeping

- **Write-offs go through `StockWriteOffService`** — the only place that
  moves `quantity_remaining` for a loss; the row is never deleted.
- **Aggregates read active write-offs only** (`whereNull('reversed_at')`) —
  a legitimate filter; it is not the `fulfilled_at` mistake.
- **Five states reconcile.** A new way for stock to leave is a sixth state
  in `weight_breakdown` and `wheelStates()`, not a tweak to "sold".
- **New backup tables go in `ADDED_LATER`.**

---

## 2026-10-04 — Loose ends (Plan/Implement cycle 6)

**Scope:** the cycle-5 finding, two defects the Planner found reading for the
plan, and three leftovers. Suite afterwards: **617 passed, 0 failed** (+5).

### Finding 1 — topping up a reservation from the same batch was a 500

`OrderItem::allocateFromBatchItem()` always inserted; `order_allocations` is
unique per `(order_item, batch_item)`, so "allocate 6 from M041026, then 4
more from M041026" on the order page crashed. It now looks the pair up under
the batch-item lock and widens it through `extendAllocation()` (the path
`pickFromBatchItem()` already used), under the same availability and
line-ceiling guards; a widened row loses its `fulfilled_at` stamp. Pinned by
five cases in `OrderAllocationConcurrencyTest` (same-batch top-up, over the
line, over the batch, partly picked, fully picked then widened); the cycle-5
test that had to dodge the bug now uses the same batch.

### Finding 2 — the conversion allocator was the one free-stock site still gated on `fulfilled_at`

`CheeseConversionController::index()` summed `quantity_allocated −
quantity_fulfilled` only `whereNull('fulfilled_at')`; the other seven sites
(and the server-side `holdable_quantity` the hold endpoint enforces) sum over
all allocations. The screen and its own server could disagree about how
many wheels were holdable. Gate removed; `CheeseConversionTest` pins
`matureCard(2, 0)` for a 5-wheel item owing 2, and that a hold of 3 is
refused. **Correction to the cycle-6 plan's reasoning:**
`fulfillAllocation()` stamps `fulfilled_at` only when a row is *fully*
picked, so a stamped row owes units only after being widened (which now
un-stamps it) or via older data — the practical reach was narrow. The
invariant is still the point: `grep -rn "whereNull('fulfilled_at')" app`
must return only the `BatchItem` docblock, and does.

### Finding 3 — the `/batches` N+1 was a `??` on a loaded null

`BatchItem::wheelStates()` read the eager-loaded sums with `??`, but
`withSum` returns **NULL** for an item with no allocations or no conversions,
so every such item re-ran the SUM. `array_key_exists` on the attributes now
decides "loaded"; a loaded null is 0. Measured in `BatchCreateTest`
(2 → 4 batches): `/batches` 12→18 queries became **6→6**, `/cheese-cutting`
10→16 became **4→4**, `/cheese-conversion` flat at 5. The test asserts the
totals stay flat.

### Also

- The run sheet's column headers are **sticky at `xl+` in display mode**:
  `.mf-panel`'s clip and the scroll wrapper are lifted there (`xl:overflow-visible`),
  `<thead>` sticks under the flash via a `--mf-feedback-h` custom property
  the feedback partial measures as the page parses (zeroed on dismiss), and
  `border-collapse: separate; border-spacing: 0` because Chrome mis-painted a
  stuck collapsed header. Below `xl`, and while a row is being edited (it
  needs ~35 px more), the wrapper scrolls as before. Rows land at
  `calc(var(--mf-feedback-h) + 80px)`.
- `/orders` Payment and actions cells don't wrap; the unused Breeze
  `application-logo` component is gone.

### Follow-ups (next polish batch, not urgent)

- The `xl` fit is tight (983 px in 985 with 11 columns): make `$stickyHead`
  depend on the column count, not only the breakpoint.
- Order-page lines use a fixed `scroll-mt-20`; `calc(var(--mf-feedback-h) +
  1rem)` would clear a two-line flash exactly.

### Invariants worth keeping

- **A second allocation from the same batch is a top-up.** Any new
  reservation path goes through `allocateFromBatchItem()` /
  `extendAllocation()`, never a raw insert.
- **Eager-loaded aggregates are tested with `array_key_exists`**, never `??`
  — `withSum` is null on an empty set.
- **The sticky header relies on no clipping ancestor at `xl+`.** A new
  wrapper around `.mf-run` with `overflow` set will silently break it.

---

## 2026-10-04 — Copy and polish (Plan/Implement cycle 5)

**Scope:** the S4 findings from the end-to-end run and the four follow-ons
from cycle 4. Thirteen steps, all landed, with tests and real-input browser
checks. Suite afterwards: **612 passed, 0 failed** (+14). One new finding
came out of it (below).

### What changed

- **A redirect that lands on an element still shows its message.** The
  feedback region (`layouts/partials/feedback.blade.php`) is sticky
  (`.mf-feedback`, z-30 — below the drawer and the modal), each message has a
  dismiss × and the wrapper hides when the last one goes; the element a
  fragment landed on flashes the accent tint (`.scroll-mt-20:target`). Sheet
  rows got `scroll-mt-20`. `LayoutFeedbackTest` still pins exactly-once.
- **`Route::fallback(fn () => abort(404))`** is the last line of
  `routes/web.php`, so an unmatched URL 404s inside the `web` group and the
  error page knows who is signed in (factory → picking, others → dashboard,
  guest → login; JSON stays JSON). `ErrorPagesTest`; the authorisation matrix
  allowlists the fallback (it carries neither `auth` nor `guest` by design).
- **Third allocate refusal**: "This line only needs N more — allocate at most
  that many." `OrderAllocationConcurrencyTest`.
- **Login** shows the Mossfield mark — `<x-brand-mark>` (new component, now
  also used by the sidebar so the two can't drift) on the app background —
  and focuses the **password** after a failed attempt. `AuthenticationTest`.
- **Topbar divider** hides with the date chip below `sm`.
- **Batch copy**: no "wheel wheel"; cases read "12 loose — less than a case
  of 16" / "2 cases × 16 + 8 loose" (full cases, not a ceil); the ready
  countdown says "in 60 days" inside ~3 months (`Batch::readyIn()`); a
  converted Mature batch shows "—" for raw milk; stock reads **"7 wheels · 18
  packs"** (`Batch::remaining_label`, variant names when two share a unit).
  **Delete is hidden when it would be refused**: the rule moved to
  `Batch::deletionBlocker()` (the one place), reading the five dependency
  counts that now ride the index's eager-load — one query, pinned — and the
  dialog names the batch. `BatchCreateTest`, `StockOverviewTest`.
- **Mossorders id** tucked in a collapsed `<details>` (open when set or
  refused).
- **Dialogs**: `confirm.js` reads `data-confirm-cancel-label` (reset every
  open); Cancel order asks "Cancel this order?" with **Keep order / Cancel
  order**. `ConfirmDialogTest`.
- **Multi-order stops** on the sheet show every ref ("PO-E2E-1 · PO-E2E-2"),
  label each loaded tick with its order number, and say "open each to edit".
  `ChilledRunTest`.
- **Order numbers don't wrap** at phone width.
- **PDFs weigh 36–40 KB instead of 1.4–1.8 MB**: `config/dompdf.php` published
  with `enable_font_subsetting => true`; `pdftotext` output identical before
  and after (€, —, · and batch codes intact). `DispatchConfirmationTest`
  asserts < 300 KB.
- **Finance labels**: a credit-note-only invoice reads **"Credited"** (state
  value still `partial`); the customer form warns when a weekly/monthly
  customer is left on Immediate terms. `PaymentStateTest`.
- `SMALL_FIXES.md` finally ticks the `confirm()` item (done in cycle 1).

### Finding — topping up a reservation from the same batch is a 500

Found by the Implementer writing step 3's test: a line of 10 with 6 reserved
from batch X, then **Allocate 4 more from batch X** on the order page →
`UniqueConstraintViolation` on `order_allocations (order_item_id,
batch_item_id)`. `OrderItem::allocateFromBatchItem()` always inserts;
`pickFromBatchItem()` already solves the same case with `extendAllocation()`.
A normal office action crashes — **cycle 6**, test-first. Also noted for
cycle 6: `BatchItem::wheelStates()` runs per-item SUMs on `/batches` although
the index eager-loads the figures; the run sheet's `<thead>` isn't sticky so a
saved row lands with its headers off-screen; two more `/orders` cells wrap at
phone width; `components/application-logo.blade.php` is unused.

### Invariants worth keeping

- **`Batch::deletionBlocker()` is the delete rule.** Views hide the button on
  it, the controller refuses on it. If the index stops eager-loading the five
  counts the card falls back to one query per batch — the test pins one.
- **Messages dismiss, they don't disappear**: the sticky region is the only
  flash surface on `x-app-layout` pages; don't add a second.
- **`Route::fallback` stays last** in `web.php`.
- **PDF fonts are subset.** A new glyph in a document is worth one
  `pdftotext` check.

---

## 2026-10-04 — Friction (Plan/Implement cycle 4)

**Scope:** the S3 findings from the end-to-end run plus the cycle-3
follow-ons — the places where the screen misled or cost the operator effort
though the arithmetic underneath was right. Twelve steps, all landed, each
with tests and real-input browser checks. Suite afterwards: **598 passed, 0
failed** (+41).

### What changed, by finding

- **Expired stock is visible (F-01, F-02, F-07).** The dashboard's "ready
  now" now means sellable (matured, not expired by the strict rule, with
  stock); an **Expired stock** tile (batches · units) links to
  `/batches?status=expired`, which now also matches active batches past
  expiry (every expired batch in dev was still `status=active`). `/stock`
  cards carry an `expired` summary from one grouped query
  (`StockOverviewService::expiredByType()`): "No sellable milk." + "4 expired
  batches still hold 4,437 units — see Batches." The batch form says so when
  a product has no shelf life; the batch page prints "None — stays sellable
  until sold". `DashboardTest` (new), `StockOverviewTest`, `BatchCreateTest`.
- **The picking queue shows one day (F-30).** `PickingController::index()`
  partitions `ready` orders dated before today into a collapsed "Ready,
  awaiting dispatch — N orders from earlier days" bucket (the office's to
  dispatch); the rest is one day at a time — `?date=`, else today, else the
  nearest future day, else the latest past day — with ◀ ▶ and the strip
  counting that day only. `confirmed`/`preparing` orders with past dates stay
  selectable: they still need picking. `PickingTest`.
- **Picking ergonomics (F-31 + cycle-3 note).** Skip cycles forward and
  wraps; stepper `−`/`+` disable at the limits; the short button clears the
  footer; a `weight_kg` of 0 means no expected-weight thresholds on either
  surface (the server's rule). `PickingTest`.
- **Partial mature release (F-13).** "Release [n] of N" — a number input
  bound to the sibling release form by `form=`, confirm text rewritten live,
  the button disabled with "Save maturing first" while the zone has unsaved
  moves. `CheeseConversionTest`.
- **Order line controls (F-21, F-22, F-23).** The allocation row's box is
  labelled **Pick** and Fulfil asks ("Record a pick of N × … from batch …?");
  the line's box is **Ordered:**; Add item's quantity is blank with a
  placeholder (blank = 1); `store()` merges the same variant into one line
  like `storeItem()` does. `OrderAddItemTest`, `OrderStoreOptionalFieldsTest`.
- **The order page as one tool (F-26, F-20 note).** Every line/allocation
  action redirects `->withFragment('line-{id}')` (`#order-items` after a
  removal) and rows carry `id="line-{id}" class="scroll-mt-20"`; the create
  form defaults the delivery date to the customer's next run day and
  relabels options with the customer's special price (`€1.25 · special`);
  `tabindex=-1` on Line total; "Order confirmed."; sibling age from
  `created_at` and summaries name the variant. `OrderItemEditTest`,
  `OrderTransitionsTest` (15 redirect assertions tightened to the fragment).
- **Office forms land where the work is (F-16, F-28).** Customer create →
  the customer; delete keeps the search; special prices → `#special-prices`
  and the picker omits already-priced variants; run assign/toggle →
  `#stop-{id}`, unassign → `#run-{id}`; the sheet editor has a **Notes**
  textarea (dirty-tracked) and saves land on `#stop-{id}`.
  `CustomerSpecialPriceTest`, `ChilledRunTest`.
- **Reordering a run is one form (F-15, F-18).** Position boxes + one Save
  order posting `positions` (id ⇒ wanted position); ↑/↓ post `move`
  (customer, direction) — exactly one shape per request, every id must be on
  the run. A stop given a new position is taken out and re-inserted there
  (so "17" on 17 stops means last — a plain sort would have left it 16th);
  the page lands on the stop that was moved. A percent-charge stop shows
  "5 % of order value" instead of a misleading toggle. `ChilledRunTest`.
- **Dispatch and loaded flashes (F-32, F-34).** "N orders dispatched ·
  skipped: ORD-… Shop (not picked, not loaded)" — per order, every reason;
  the loaded tick names the order; an invoiced order shows "On invoice INV-…
  — void it to edit" instead of Edit. `DispatchRunTest`, `OrderTransitionsTest`.
- **Finance defaults and messages (F-37, F-38, F-41, cycle-3 note).** A
  disabled Void names what blocks it ("remove its payments (1) and credit
  note (CN-…) first"); a **This week** consolidated shortcut; statement
  preview defaults to this month to date; "€X in credit" never "€-X";
  allocate refusals say "already fully allocated (N of N)" or "only N
  available in this batch"; a refused payment keeps its manual split.
  `InvoiceVoidTest`, `ConsolidatedInvoiceTest`, `StatementTest`,
  `PaymentAllocationTest`, `OrderAllocationConcurrencyTest`.
- **Error pages lead home (F-41).** `resources/views/errors/{layout,404,403}`:
  standalone, one link — factory → `/picking`, signed-in → dashboard, guest →
  login; the request path in mono; JSON requests still get JSON.
  `ErrorPagesTest` (new). Known limit: an *unmatched* URL 404s before the
  `web` group runs, so a signed-in user sees "Sign in" (which then forwards
  them home) — cycle 5 adds a `Route::fallback()` inside the group.

### Invariants worth keeping

- **Line, stop and panel actions redirect to the element they edited**
  (`->withFragment(...)`); status-level actions to the top. The element needs
  an `id` and `scroll-mt-20`. The flash then renders out of view — cycle 5.
- **Expiry is strict and "expired" is a view of active batches**, not a
  status: `?status=expired`, the tile and the card notes all read
  `status=active AND expiry_date <= today AND quantity_remaining > 0`.
- **The picking queue never hides work**: only `ready` orders go to the stale
  bucket.
- **`reorder()` takes `positions` (id ⇒ position) xor `move`**; the old
  `positions[]` list shape is gone.

---

## 2026-10-04 — Money and state (Plan/Implement cycle 3)

**Scope:** the eight end-to-end findings that could write wrong money or wrong
state with no warning (F-39, F-29, F-19, F-12, F-08, F-20, F-35, F-36/F-40 —
details one section down). Opus implemented from the Fable plan; every step
has a test and was also driven in the browser with real input. Suite
afterwards: **557 passed, 0 failed** (+21 cases).

### Finding 1 — a double-clicked form posted twice (F-39)

No form had a submit-once guard; *Record payment* recorded the money twice.
**`resources/js/submit-once.js`** (imported after `confirm.js`): on a POST
submit that actually goes through (`!defaultPrevented` — so the submit
`confirm.js` parks behind its dialog is ignored), mark the form
`data-submitting`/`aria-busy`, disable its submit buttons — including
`form="id"` ones — after a tick (so the submitter's `name`/`value` is already
in the form data), drop any re-entrant submit, release on `pageshow` and after
10 s (download forms never navigate). GET forms are skipped. **Payments are
idempotent per rendered form**: a `form_token` (uuid, unique index, migration
`2026_10_04_120000`) travels with the form; a repeat POST — checked before
`record()` and again in a `UniqueConstraintViolationException` catch for the
simultaneous case — redirects to the payment already recorded. Pinned by four
cases in `PaymentAllocationTest`.

### Finding 2 — "5,210" was 5 210 kg and nothing stopped it (F-29)

`ValidatesFulfilmentWeights::normaliseWeightInput()` turns decimal commas into
dots before validation in both callers (`PickingController::pick()`,
`OrderAllocationController::fulfill()`), and when the variant has a nominal
`weight_kg` each per-unit weight is bounded at **3 × nominal** (bulk lines:
× quantity) with a message that quotes the usual weight and says "check the
decimal point". Client: a typed comma becomes a dot via
`execCommand('insertText')` (writing to a number input's value blanks a
half-typed `"5."`), and above 2 × nominal the picking row's **OK** tag turns
amber **Check** (desktop row turns the warn colour). Four cases in
`FulfilmentUnitWeightsTest`, red-checked.

### Finding 3 — the order edit form could set any status (F-19)

`OrderController::update()` accepted all seven statuses; the edit form's
dropdown could mark an order `dispatched` with no invoice, no timestamp and
nothing picked. Now `status` is `sometimes` (defaults to the current status)
and `isAllowedStatusChange()` permits only unchanged, `pending → confirmed`
and `→ cancelled` via `canBeCancelled()` — refused with a flash before
anything is written. The edit form shows a read-only status tag; the show
page's Confirm/Cancel quick-actions are the two allowed moves and are
unchanged. Six cases in `OrderTransitionsTest`.

### Finding 4 — refused forms threw the typed figures away (F-12, F-08)

The cut form's on-load script recomputed `total_weight_kg` as packs × nominal
over `old()`, so a resubmit after fixing a date recorded the nominal, not the
weighed figure; the batch form rebuilt its quantity rows from JS with no
`old()` seed and let the shelf-life auto-fill overwrite the refused expiry.
Both scripts now keep restored values on load (`totalTouched` /
`initialising` flags) and only recompute on a real change. Tests pin the
`old()` rendering; the script behaviour was verified in the browser.

### Finding 5 — the order form previewed the wrong money (F-20)

`OrderController::create()` now passes `customerPrices` (special price ×
nominal weight for €/kg variants) and `customerCharges` (fixed charge or
percentage); the form prices each line at the selected customer's rate,
shows **Items / Delivery (incl. VAT) / Total**, and re-prices when the
customer changes. Browser: preview equals the saved order to the cent.

### Finding 6 — the invoice didn't name the PO (F-35)

`invoices/pdf.blade.php` builds `$orderLookup` for every invoice (it was
consolidated-only) and prints `Order ORD-… · Your ref: …` in the details
block for per-order invoices; the HTML show page gets the same line with the
order linked. Consolidated invoices keep the per-order headings they already
had. Two tests.

### Finding 7 — payments landed as credit by default; "-5" became 5 (F-36, F-40)

The Apply boxes start blank, so a typed amount with no further action was
recorded as unallocated credit while the invoice stayed unpaid; and
`x-model.number` turned a lone `-` into NaN and dropped the sign. The amount
is now a string model (`min="0.01"` refuses negatives; server message "A
payment must be more than zero. Record refunds as credit notes."), and the
amount is auto-applied oldest-first as it's typed unless the operator has
edited an Apply box.

### Invariants worth keeping

- **Every POST form submits once.** New forms get it for free; a form that
  must stay clickable after a POST (a download) relies on the 10 s release.
- **Payments carry a `form_token`.** Any new payment entry point should mint
  one; the unique index is the backstop.
- **Weights are normalised and bounded in the trait**, never per controller.
  A new fulfilment surface calls `normaliseWeightInput()` then merges
  `fulfilmentWeightRules()`.
- **Status changes outside Confirm/Cancel go through their own routes.** Don't
  widen `isAllowedStatusChange()`; add a route.
- **A refused form hands back what was typed.** Scripts that auto-fill a
  field must check for a restored `old()` value on load.

---

## 2026-10-04 — End-to-end browser run (Plan/Implement cycle 2)

**Scope:** a full working day driven through Chrome with real input by an
Opus Implementer from a Fable plan: three batches made (milk, yoghurt, a
backdated cheese), a cut, a maturing hold / release / undo, two new customers
on the run, orders by desktop form and by the chilled-run sheet, Confirm all →
Allocate all, picking on a 500 px screen as factory (fixed, per-piece wheel
weights, bulk, undo, short), load and **Dispatch run**, per-order dispatch,
invoice → email → partial / over-payment / credit → credit note → statement →
void → reissue, then the unhappy paths (cancel with picked stock, back
button, double-click, stale-page race, validation, 404/403). Every phase ended
with a read-only database probe. **No code changed**; the output is 41
findings and a long confirmed-correct list. Full report with evidence:
`archive/2026-10-04-e2e-browser-run/implemented.md`.

**What held** (the headline): stock and money arithmetic was right at every
probe — batch counters, cut trim, hold/release/undo reconciliation, FIFO,
special-price snapshot, weight-aware estimates, the exact 23 % VAT back-out,
picked quantities leaving `quantity_remaining`, short lines at €0, invoice
snapshots, consolidated preview burning no number, payment states, credit
notes, statement ledgers, void/reissue numbering, cancel returning picked
stock, no over-reservation on a stale page, no € on any factory screen,
every console clean. The bugs are in the **forms and the surfaces**, not the
model.

### Findings (41) — severity index

| # | S | Where | One line |
| --- | --- | --- | --- |
| F-39 | **S1** | Record payment | Double-click records two payments; no form has a submit-once guard |
| F-29 | **S1** | Picking / desktop weights | "5,210" becomes 5210 kg, shows green OK, no upper bound → would bill €83 360 |
| F-19 | **S1** | Order edit form | Status dropdown sets `dispatched`/`ready`/`pending` directly, bypassing DispatchService, picking and invoicing |
| F-12 | S2 | Cut form | A refused submission resets the weighed pack total to nominal |
| F-20 | S2 | Order create | Preview uses standard prices and omits delivery: €322.40 shown, €298.55 saved |
| F-35 | S2 | Per-order invoice | Prints neither order number nor customer PO ref |
| F-01 | S3 | Dashboard | "ready now" counts expired and empty batches |
| F-02 | S3 | /stock, dashboard | Expired stock vanishes ("No active milk batches" with 4 437 units active-but-expired); nothing surfaces it |
| F-06 | S3 | Batch page | Milk yield hard-coded 100 % (500 L raw → 432 L bottled accepted silently) |
| F-07 | S3 | Batch create | Yoghurt/cheese get no expiry and nothing says so; never-expiring stock stays "available" |
| F-08 | S3 | Batch create | Refusal blanks the quantities and overwrites the bad expiry with the auto-fill |
| F-09 | S3 | Batch create | 3 000 wheels accepted without a question |
| F-10 | S3 | Batch create | Mature offered as a fresh production batch |
| F-13 | S3 | Mature conversion | Can only release the whole saved hold; header button disagrees with the zone |
| F-15 | S3 | Delivery runs | Reorder is ↑/↓ one step per full reload (30 reloads for two stops) |
| F-21 | S3 | Order allocation panel | Two unlabelled number boxes per line; Enter in the wrong one records a pick |
| F-22 | S3 | Add item | Pre-filled 1 + caret → 16 instead of 6 |
| F-23 | S3 | Order create | Same variant twice → two lines (Add item merges) |
| F-24 | S3 | Delivery charge | Second same-day order charges a second €6.15 |
| F-25 | S3 | Credit limit | Required field, never enforced (`canPlaceOrder()` unused) |
| F-30 | S3 | /picking | "Today" leads with July's stale ready orders; progress 149/230 before any pick |
| F-32 | S3 | Dispatch run flash | Counts orders as "stops", names the same shop as dispatched and skipped, omits order numbers |
| F-33 | S3 | Dispatch confirmation / docket | Internal order notes printed to the customer |
| F-36 | S3 | Record payment | Apply boxes blank by default → money lands as credit, invoice stays unpaid |
| F-40 | S3 | Record payment | "-5" silently becomes 5 |
| F-03/04/05 | S4 | Login, topbar | Laravel logo; focus after failure; stray divider at 500 px |
| F-11 | S4 | Batches | "wheel wheel", "1 cases × 16", "1 month" for 60 days, "0.0L", generic delete dialog |
| F-14 | S4 | Batch page | "29 units" adds wheels and packs |
| F-16 | S4 | Office forms | Save lands on the list / top of page (customers, special prices, runs, search dropped) |
| F-17/18 | S4 | Customer form, run charges | Bare Mossorders id input; % customer shows "charge: off" |
| F-26 | S4 | Orders | Age "13h" on a new order; variant missing from summaries; Cancel/Cancel order; "updated" on confirm; tab order; no run-day default; scroll to top |
| F-27/28 | S4 | Run sheet | Second ref hidden; unlabelled per-order loaded ticks; no notes in the row editor |
| F-31 | S4 | Picking | Skip loops back; + not disabled at cap; short button under the footer; /orders cramped |
| F-34 | S4 | Dispatch | Edit offered on invoiced order; 1.76 MB PDF; loaded flash unnamed; per-order dispatch needs no loaded tick |
| F-37/38 | S4 | Finance | Void silently absent; last-month defaults; "€-10.00"; credit-only = "Partial"; weekly + Immediate terms due on issue; Issue offered on undispatched |
| F-41 | S4 | Errors | Generic stale-allocation message; bare 404/403 pages |

**Triage:** cycle 3 (`plan.md`, 2026-10-04) takes the S1/S2 set plus F-08 and
F-36/F-40; cycle 4 the S3/S4 friction; the design decisions (F-06, F-09,
F-10, F-24, F-25, F-33, F-34, F-38) are `OPEN_QUESTIONS.md` #5.

### Dev-database state after the run

Kept on purpose. Customers 27 **E2E Corner Shop** and 28 **E2E Weekly Deli**
on run #1 at positions 1–2 (the other 15 shifted to 3–17; run #1
`delivery_charge` is now **€6.15**, reaching only Corner Shop). Batches 24
M041026, 25 Y041026, 26 G010426 (backdated to 2026-04-01; 8 wheels left,
1 maturing, 1 cut → 18 packs, 1 converted), 27 M041026-1, 29 G010426-1.
Orders 12–18 (`ORD-20261004-001…007`; 14 is still `preparing` with 12 units
reserved and will sit on `/picking` until picked or cancelled). Invoices
INV-000004 (paid, emailed), 005 (void), 006 (consolidated weekly, credited
€5, paid €10), 007 (unpaid). CN-000001. One statement. Three existing
customers carry E2E-tagged orders (Organic Store 13 cancelled, An Tairseach
16 → INV-000007, Avoa 17 cancelled). The listener also drained two queued
dispatch confirmations left from 2026-09-12. **All mail went to the log** —
including one to a real-looking customer address — so never point dev `.env`
at SMTP with this data in place. `mossfield:reset-trading-data --confirm` is
the way back to a clean slate (it also wipes G030426's lineage).

---

## 2026-10-04 — Housekeeping batch (first Plan/Implement cycle)

**Scope:** four long-deferred items cleared in one pass, run under the new
`planimp.md` protocol — Fable planned (`plan.md`), an Opus session implemented
(`implemented.md`), Fable reviewed the diff and reran verification. Archived
in `archive/2026-10-04-housekeeping-batch/`. Suite afterwards: **536 passed,
0 failed** — the first green run in the project's history.

### Finding 1 — `/stock` showed a batch as available on the day the allocator refused it

`Batch::isExpired()` (`expiry_date->lt(now())`) and `BatchItem::allocatable()`
(`expiry_date > today`) both treat the expiry day itself as expired;
`StockOverviewService` alone used `>=`. So for one day per batch `/stock`
quoted stock that auto-allocate, manual allocate and the picker would all
decline — a one-line breach of the free-stock invariant ("no surface may show
as available what the allocator would not hand out"). Was `WORK_IN_PROGRESS.md`
follow-up #6, "needs an owner"; the owner is the allocator's rule. Now `>`.
Pinned by `StockOverviewTest::test_a_batch_expiring_today_is_excluded_like_the_allocator_excludes_it`,
which builds yesterday/today/tomorrow batches and asserts the service and
`allocatable()` return the same single batch.

### Finding 2 — the seven "known-failing" Breeze tests are gone

`Auth\RegistrationTest`, `Auth\EmailVerificationTest`, their four orphaned
controllers and two views deleted (a reference grep across `app routes
resources tests config` was pasted first — every hit was inside a deleted
file). `AuthenticationTest` now posts the app's `login` field and gains
username-login and inactive-user cases; `ProfileTest` stops asserting
`email_verified_at`. Route count unchanged at 157. The *Known-failing
baseline* section above now says: none.

### Finding 3 — 41 native `confirm()` dialogs replaced by one in-app dialog

Native `confirm()` is unstyleable, untestable and blocks browser automation
(it froze two testing sessions). Every destructive or irreversible action in
21 views now carries `data-confirm="…"` (message verbatim), optional
`data-confirm-label` and `data-confirm-danger`, driven by
`resources/js/confirm.js` over `layouts/partials/confirm-dialog.blade.php`
(the Breeze `<x-modal>`), included once in each layout. Two details that
matter: confirmed submits go through `form.requestSubmit(submitter)` so a
button's `name`/`value` still posts (`statements/preview` relies on
`name="send"`), and the confirmed-flag is cleared by `setTimeout(0)` as well as
on the re-entrant submit, because `requestSubmit` fires no `submit` event when
constraint validation refuses — otherwise the next click would skip the dialog.
The two picking buttons that did `if (confirm()) getElementById(…).submit()`
became `type="submit" form="…"`. If no dialog markup is present (a page outside
both layouts) the JS falls back to `window.confirm` rather than letting the
action through. Pinned by `ConfirmDialogTest` (a scan of `resources/views` for
`confirm(`, both layouts render the dialog, `/users` marks Deactivate as
danger). Verified with real mouse/keyboard on `/users` (Deactivate → Cancel /
Escape / backdrop / confirm → Reactivate) and `/picking/3/items/13` (Reopen,
Undo via `undo-line-form`, Can't finish via `short-line-form`), console clean.

### Also

`WORK_IN_PROGRESS.md` refreshed (January "current issue" removed, next steps
rewritten, both broken `DEPLOYMENT.md` links fixed, cheese-variant table
replaced with the real ten). `AdminUserSeeder` re-run on the dev DB to restore
the documented `admin` login.

### Invariants worth keeping

- **Expiry is strict everywhere**: a batch expiring today is out of `/stock`,
  the allocator, the picker and `Batch::isExpired()` alike. A new free-stock
  surface copies `BatchItem::allocatable()`'s `>`.
- **No native `confirm()` in a view** — `ConfirmDialogTest` fails the build on
  the first one. New destructive actions use `data-confirm` on the form or the
  submit button; a button that must submit a *different* form uses the HTML
  `form="…"` attribute, not JS.
- **The suite is green.** Any failure is real.

### Not verified in the browser yet

`statements/preview` "Generate & email" (that `name="send"` reaches the
controller through `requestSubmit`) and the chilled-run Confirm all / Allocate
all / Dispatch run buttons, which share a page with the `data-confirm-unsaved`
guard. Listed in `handoff/REPORT.md` for the browser session.

---

## 2026-10-04 — Authorisation boundary pass

**Scope:** the gap every earlier pass named and none covered — "every
authorisation boundary; this ran entirely as an admin". Logged-out access,
each of the four roles against every route, the factory write carve-outs,
deactivation and role change mid-session, and the belongs-to guards on nested
resources (`/orders/{a}/items/{b}`, `/customers/{a}/special-prices/{b}`).
Done test-first from the router rather than through the browser: the new
`tests/Feature/AuthorizationBoundaryTest.php` **walks `Route::getRoutes()`** so
a route added later is covered automatically — it must be behind `auth` (or be
a guest page), it must carry a `role:` group unless it is an account page, and
each role gets exactly what the group says. Parameters are filled from real
rows because `role` runs *after* route-model binding, so a made-up id would 404
before the role check and prove nothing; a parameter with no fixture fails the
suite. 21 cases, 1,376 assertions. Suite 529 + the 7 known.

**What held.** Every route is behind `auth` or `guest`; every authenticated
route outside the account pages has a role group; guests are bounced to login
from all 150-odd of them; driver is 403 on every role-gated route and sees a
€-free dashboard; factory is 403 on every office/admin route and on every write
in the shared group except the two carve-outs; office is 403 on `/users` and
`/backup`; the belongs-to guards hold; the API returns 401 without a bearer.
The group structure described in `CLAUDE.md` is what the router actually does.

### Finding 1 — deactivating a user did not log them out if they had ticked "Remember me"

`User::logOutEverywhere()` deleted the user's rows from the `sessions` table,
which is what the docs promised. But a "Remember me" login also sets a
recaller cookie, and on the next request `SessionGuard` rebuilt a session from
`users.remember_token` — which nothing had changed — and signed the user
straight back in. Neither `EnsureUserHasRole` nor the `auth`-only routes looked
at `is_active`; only the policies did. So a deactivated factory user with the
cookie kept `/dashboard`, `/stock`, `/cheese-cutting`, `/cheese-conversion`
and their own `/profile` (including the password form), and a deactivated
office user kept everything those pages' controllers didn't `authorize()`.

Two fixes, each sufficient on its own:

- **`logOutEverywhere()` now cycles `remember_token`** (`Str::random(60)`,
  `saveQuietly()`) before deleting the sessions, so the cookie is dead.
- **`App\Http\Middleware\EnsureUserIsActive`**, appended to the `web` group
  in `bootstrap/app.php`: any request from an inactive account is logged out,
  the session invalidated, and redirected to login with "This account has been
  deactivated." This also covers a user deactivated by a direct database edit,
  which `logOutEverywhere()` never sees.

Pinned by `test_deactivating_a_user_invalidates_their_remember_me_cookie`
(which first proves the cookie *does* sign in on a cold browser, so the
assertion can't pass vacuously), `test_deactivation_and_role_change_cycle_the_remember_token`,
`test_a_deactivated_user_is_logged_out_on_their_next_request` and
`test_a_deactivated_user_cannot_use_the_account_pages_either`.

### Finding 2 — `DELETE /orders/{order}` was a 500 for everyone

`Route::resource('orders', …)` registered `orders.destroy`, but
`OrderController` has no `destroy()` — orders are cancelled, never deleted.
Any role hitting it got "Call to undefined method", after passing the role
check. No view linked to it. The resource is now `->except(['destroy'])`.
Caught by `test_factory_cannot_write_in_the_shared_group_except_through_the_carve_outs`
asserting 403 and getting 500.

### Finding 3 — a factory user posting an incomplete product form got a validation redirect, not a 403

`ProductRequest` (a `FormRequest`) runs before the controller body, so its
validation fired before `ProductController::store()`'s `authorize('create')`.
With a valid payload factory still got the 403, so nothing was writable — but
the refusal depended on the order of two checks in different files.
`ProductRequest::authorize()` now mirrors the policy (`create` for store,
`update` for the bound product), and `CheeseCutRequest::authorize()` (which
returned `true`) checks `create` on `CheeseCuttingLog`. **Rule:** a
`FormRequest` on a route whose group admits a role the action denies must
carry the policy check in `authorize()`, not only in the controller.

### Finding 4 — four read actions had no `authorize()` at all

`StockController::index`, `CheeseCuttingController::index/create/store` and
`CheeseConversionController::index` relied on the route group alone. Harmless
while the group holds, but they were exactly the pages finding 1's deactivated
user could still open, because the policies' `is_active` check never ran. All
five now call `authorize()` like every other action.

### Also fixed

`InvoicingTestCase::batchItemFor()` had never been used by a passing test and
didn't set `raw_milk_litres`, which MySQL strict mode refuses. Fixed in place.

### Invariants worth keeping

- **A new route is covered by the matrix the moment it is registered.** If it
  binds a model the fixture map doesn't know, the suite says so; add the row
  to `buildFixtures()`. If factory may write through it, add its name to
  `FACTORY_WRITE_CARVE_OUTS` — deliberately, with the policy to match.
- **`is_active` is enforced in three places**: login (credentials array),
  `EnsureUserIsActive` (every web request), and the policies. Keep all three;
  the middleware is what makes the other two sufficient.
- **Deactivation = `logOutEverywhere()`**, which now means sessions *and*
  remember token. Any new path that disables or demotes a user must call it.

### Not covered by this pass

Still untested: double-submit and back-button resubmit, two-tab concurrent
edits, CSRF (Laravel's, assumed), the password-reset flow for an inactive
user (they can reset but still can't log in — by inspection), the mossorders
export's IP allowlist beyond the existing `Api` tests, and the browser-level
version of all of this (the matrix is HTTP-level; nav visibility per role is
still only inspected by eye).

---

## 2026-09-12 — Browser-level pass over production, cutting and conversion

**Scope:** the same spine as the audit below, but driven through the rendered UI
as an operator sees it, plus forged POSTs to probe request validation
independently of the client-side guards. Admin user on `mossfield.local`.

**Why it found anything.** The audit below verified the model and controller
seams and found them sound. This pass covers the two layers above that: what the
**view** computes, and what the **form request** accepts. Both turn out to
diverge from the invariants the audit established. Nothing below contradicts it —
the model layer is right; the screens and the validators haven't caught up.

No test file added yet; every finding names the test that should pin it.

### Finding 1 — there is a seventh free-stock surface, and it disagrees

The invariant below names six places that compute free stock and says they must
agree. It is missing one: **the product/variant stock figure on `/products`**.
It sums batch stock without regard to expiry or maturation.

*(Correction, added on re-verification: this finding originally also attributed
the cheese-cutting screen and the batch page's action buttons to this figure.
They read a single item's raw `quantity_remaining` instead — same class of
disagreement, different code path. See the separate note at the end.)*

| Variant | `/products` | `/stock` available |
| --- | --- | --- |
| Milk 1L Bottle | 2,700 | 438 |
| Milk 2L Bottle | 1,726 | 192 |
| Yoghurt 250g Tub | 783 | 295 |
| Yoghurt 500g Tub | 1,198 | 216 |
| Farmhouse Whole Wheel | 33 | 31 |

The arithmetic identifies the cause exactly. Milk: 11 + 2,700 + 1,726 = **4,437**,
which is the sum of every milk batch including the three expired ones
(630 + 951 + 246 + 2,610). Only M070926's 630 are live. Yoghurt: 3 + 783 + 1,198
= **1,984** = live batch 514 + expired batch 1,470. Cheese is the mild case —
33 counts the 2 wheels held as maturing alongside the 31 free.

Allocation itself is correct: auto-allocate on a 35-wheel line took **31**, not
33, exactly as the audit says it should. So the sellable-stock query exists and
works; `/products` is using a different one.

The sharpest expression of the disagreement is on `/batches/17`, where two
buttons sit side by side reading **"Cut Whole Wheel (33 left)"** and
**"Convert to Mature (31 free)"** — the cut action offering wheels the conversion
action knows are held. That pair turns out to come from a different source than
`/products`; see the correction above.

Related: the three expired milk batches are still flagged **Active** on
`/batches`, which is probably upstream of all of this.

*Test:* one live batch of 10 and one expired batch of 100 → the variant's product
stock figure must read 10. Assert the same for a held-maturing wheel. Then add
the product surface to the six-way agreement invariant below.

**Fixed (2026-09-12).** The figure was `ProductVariant::total_stock`, loaded as
`withSum('batchItems as total_stock', 'quantity_remaining')` in
`ProductController::index()`, `::show()` and `BuildsProductList`, with the
accessor's fallback doing the same raw `SUM` — no batch status, no expiry, no
reservations, no hold. All three loaders now call
`ProductVariant::attachFreeStock()` (one query for the whole list) over
**`BatchItem::freeStockByVariant()`**, which is `stockByVariant()`'s expression
made shared: `allocatable()` candidates, `reserved_units = SUM(quantity_allocated
− quantity_fulfilled)`, the `isReadyToSell() && ! isExpired()` predicates, then
`max(0, remaining − reserved − maturing)` per item. The accessor's cold fallback
goes through the same function. `tests/Feature/ProductStockFigureTest.php`
(7 cases) pins expiry (strict — expiring today is out), non-active status,
ready_date, the maturing hold, owed-not-whole reservations after a partial pick,
and a direct equality with `Σ available_quantity` over allocatable items, on all
three surfaces plus the fallback. Not touched: the batch page's
"Cut Whole Wheel (N left)" and the cut form's "Wheels available" read a single
item's raw `quantity_remaining`, not this figure — same disagreement, separate
fix; and `Api\ProductExportController::calculateStockAvailable()` (the
`stock_available` field mossorders sees) still sums `quantity_remaining` over
ready batches without subtracting reservations or the hold.

**Re-verified through the UI (2026-09-12).** `/products` and `/stock` now agree on
every variant:

| Variant | `/products` before | `/products` after | `/stock` available |
| --- | --- | --- | --- |
| Milk 10L Churn | 11 | **0** | — (no live churn stock) |
| Milk 1L Bottle | 2,700 | **438** | 438 |
| Milk 2L Bottle | 1,726 | **192** | 192 |
| Yoghurt 16L Bucket | 3 | **3** | 3 |
| Yoghurt 250g Tub | 783 | **295** | 295 |
| Yoghurt 500g Tub | 1,198 | **216** | 216 |
| Farmhouse Whole Wheel | 33 | **29** | 29 |
| Farmhouse Vacuum Pack | 0 | **101** | 101 |

The 10L Churn dropping 11 → 0 is correct and is the clearest single case: every
churn sat in an expired batch.

**Still open, as the fix note says.** `/batches/17` continues to show
**"Cut Whole Wheel (31 left)"** beside **"Convert to Mature (29 free)"**, and
`/cheese-cutting/cut/26` still reports **"Wheels available 31"** where `/products`
now says 29. So the cut path still offers the 2 wheels that are held as maturing —
the operator-visible half of this finding survives on a different code path.
Worth carrying forward as its own finding rather than a footnote, and worth
deciding whether the cut form should refuse a held wheel outright.

**The eighth surface deserves promoting too.**
`Api\ProductExportController::calculateStockAvailable()` is the `stock_available`
that **mossorders shows customers ordering online**. Of all the disagreeing
surfaces, that is the one where being wrong turns into an oversell rather than an
internal inconsistency. Untested by this pass — the online portal was never
opened.

### Finding 2 — cheese cutting has no conservation of mass

`total_weight_kg` on the cut form is unbounded above. A single 5.000 kg wheel
accepted **100 vacuum packs totalling 25 kg**:

```
POST /cheese-cutting/cut/26
  cut_date=2026-09-12  vacuum_pack_variant_id=6
  vacuum_packs_created=100  total_weight_kg=25          -> 302, recorded
```

Batch G030426 after: finished weight 191.50 → **216.75 kg**, yield 4.8% →
**5.4%**, stock remaining 38 → **132 units**, vacuum packs produced 6 → 107.
Total stock value across the app moved €9,585 → €9,879.

The weight arithmetic is the part that bites even when operators behave. Every
cut inflates the batch and drifts the yield upward; the 25 kg cut only made it
visible. The first probe cut, a legitimate-looking 1 pack, moved finished weight
191.50 → 191.75.

**Mechanism — read from the code on 2026-09-13, and not what this finding
originally assumed.** There is no accumulator. `finished_weight` is derived:

```php
// Batch::getFinishedProductWeightAttribute()
$total += $item->quantity_produced * ($item->unit_weight_kg ?? 0);
```

and `CheeseCuttingController::store()` increments the vacuum-pack item's
`quantity_produced`, decrements the **wheel item's `quantity_remaining`**, and
never touches the wheel item's `quantity_produced`. So a cut mints pack-produced
without unminting wheel-produced, and the derived total counts the wheel and its
own packs at the same time. The arithmetic matches exactly at every step:

| | wheels produced × 5.000 | packs produced × 0.250 | total |
| --- | --- | --- | --- |
| before | 38 → 190.00 | 6 → 1.50 | **191.50** |
| after the 1-pack cut | 38 → 190.00 | 7 → 1.75 | **191.75** |
| after the 100-pack cut | 38 → 190.00 | 107 → 26.75 | **216.75** |

Two consequences that change what a fix has to do:

1. **`total_weight_kg` is recorded on the log and used nowhere** — not in stock,
   not in the derived weight, not in pricing. Capping it, on its own, would fix
   nothing about the mass inflation. It is worth capping so the log is truthful,
   but it is not the bug.
2. **The real defect is the meaning of `quantity_produced`.** The weight accessor
   treats it as "exists now"; the cutting code treats it as "was ever made".
   `quantity_produced` is right and must not change — it is the production-sheet
   wheel count, written once in `BatchController::store()` from the form,
   validated `required|integer|min:1`, never touched again anywhere in `app/`
   (`BatchController::update()` accepts only expiry date, notes and status). The
   weight accessor is asking the wrong column.

### Decision (2026-09-13) — what the finished weight is for

Settled with Jon, and it changes the scope of the fix. Two things came out of
tracing every weight in the app.

**Finished weight is a nominal figure dressed as a measurement.** A wheel's true
weight is unknown until someone weighs it. `Batch::getFinishedProductWeightAttribute()`
and `BatchItem::getTotalWeightAttribute()` both multiply a count by the variant's
nominal `weight_kg` and present the result as fact. Three other nominal call sites
are honest about it and need no change: `OrderAllocation::getExpectedWeightAttribute()`
(named "expected", rendered with a tilde), `ProductVariant::calculatePrice()`
(a fallback superseded by the measured weight at fulfilment), and the mossorders
export (a spec weight).

**Real weights are captured in two places and only one is used.** Fulfilment
requires `actual_weight_kg` for weight-priced lines (`PickingController`,
`OrderAllocationController`), sums it into `order_items.weight_fulfilled_kg`, and
prices the line from it — that is the money, and it is correct. Cutting requires
`cheese_cutting_logs.total_weight_kg` and **reads it nowhere**. Conversion has a
`total_weight_kg` column that looks like a measurement but is computed as
`unit_weight_kg × wheels` — nominal.

Also: the per-wheel `weights[]` inputs on the fulfilment form are never persisted.
They appear nowhere in `app/`; JavaScript sums them into the hidden
`actual_weight_kg` and the individual figures are discarded. That is the same
field finding 5 is about.

**The decision: derive the weight from real measurements where they exist, fall
back to nominal where they don't, and show how much is measured.** The yield then
firms up over a batch's life instead of being fixed at production.

Per wheel, exactly one of four states, and the counts for each are already
derivable:

| state | weight source |
| --- | --- |
| on hand (incl. maturing) | nominal |
| dispatched whole | measured — `order_allocations.actual_weight_kg` |
| cut | measured — **the wheel's weight before cutting** (new field) |
| converted out to a mature batch | nominal — not weighed until sold from that batch |

`remaining + dispatched + cut + converted = produced` is the reconciliation, and
is worth asserting as a test.

**Cutting must capture the wheel's weight, not just the packs'.** The existing
`total_weight_kg` is the weight of the packs produced, so it excludes rind and
trim. Counting a cut wheel at pack weight would bias yield *downward* as more
wheels are cut — the opposite distortion, equally misleading. The operator has
the wheel on the scale anyway, so the cut form gains a wheel-weight field.
Recording both gives a **waste figure per cut**, which Jon wants for reconciling
production against sales, and makes the over-yield in this finding impossible by
construction: pack weight cannot exceed wheel weight.

**And the derived pack item must stop being summed separately.** Under a per-wheel
model a cut wheel contributes its weight once — as the wheel — never as the wheel
plus the packs it became. That is what actually removes the double count; the
validation cap only stops it being absurd.

Separately, `vacuum_packs_created` is `required|integer|min:1` with **no upper
bound**, which is what let 100 packs come off one wheel.

*Test:* a cut yielding more than the source wheel weighs is invalid; a valid cut
never raises the batch's finished weight; and the finished weight of a batch with
cuts equals what its physical contents actually weigh.

### Pass 2a (2026-09-13) — the measurements are captured

Scope of this pass: record the weights. The derivation
(`Batch::getFinishedProductWeightAttribute()`, `BatchItem::getTotalWeightAttribute()`)
is untouched and still nominal — that is pass 2b, and the second and third
*Test* lines above are still open until it lands.

**Cutting weighs the wheel.** `cheese_cutting_logs.wheel_weight_kg`
(migration `2026_09_13_120000`, `decimal(8,3)`, **nullable**) is the wheel on
the scale before it is cut; `total_weight_kg` keeps its meaning — the sellable
packs that came off it. `CheeseCuttingLog::waste_kg` is the difference, per cut;
`Batch::trim_loss_kg` sums it across the batch, with
`measured_cuts_count` / `unmeasured_cuts_count` alongside so the figure is
never quietly partial. The cut form has the field (required, with a live
"Trim / waste" readout); `batches/show`'s cutting history gains Wheel / Packs
weight / Waste columns and a "Trim loss … over N weighed cuts · M not weighed"
line in the header.

*Backfill: none — nullable, constraint on new rows only.* Writing the nominal
variant weight into the old rows would present a guess as a measurement, which
is precisely the habit the Decision above is about; a null reads honestly as
"not weighed", and pass 2b's derivation falls back to nominal for unmeasured
states anyway, so nothing is lost by leaving it null. The dev DB has exactly one
such row (log 1 on G030426, 2026-07-11, 6 packs / 1.600 kg).

**The headline bug is closed by construction.** All cut rules now live in
`App\Http\Requests\CheeseCutRequest` (was inline `validate()`):
`total_weight_kg` is `lte:wheel_weight_kg` ("The packs cannot weigh more than
the wheel they came from"), and an `after()` rule caps `vacuum_packs_created`
at ⌊wheel ÷ the pack variant's nominal `weight_kg`⌋, naming the arithmetic in
the message ("100 packs at 0.250 kg each is 25.000 kg — more than the 5.000 kg
wheel. At most 20 packs can come off it"). The 25 kg-from-5 kg probe is refused
on both counts. Equal is allowed (a lossless cut is odd, not impossible).

**Finding 3 rode along in the same request:** `cut_date` is now
`after_or_equal:{batch production date}` as well as `before_or_equal:today`,
with a message that names the date.

**Also fixed on the way:** `Batch::cuttingLogs()` was
`hasMany(CheeseCuttingLog, 'source_batch_item_id')` — comparing `batches.id`
with a *batch item* id, so a batch's "cutting history" showed whichever batch
item happened to share its number. Now `hasManyThrough` batch_items.
`BatchController::show()` eager-loads through it.

Tests: `tests/Feature/CheeseCutWeightTest.php` (11) — required wheel weight,
both weights + waste stored, the form field, packs ≤ wheel (25-from-5 refused,
+1 g refused, equal allowed), packs × nominal ≤ wheel (100 refused, 21 refused,
20 allowed), the refusal message on the form, cut_date bounds (2020 / day
before production refused; production day and today allowed; tomorrow
refused), trim loss across two cuts, the batch-page columns and total, the
relation fix, and a legacy unweighed row rendering as "not weighed" with a null
(not nominal) waste.

**Re-verified through the UI (2026-09-13).** Every refusal lands with its reason
and nothing is recorded:

| probe | response |
| --- | --- |
| wheel 5.000, packs 18, total 25.000 | *"The packs cannot weigh more than the wheel they came from."* |
| wheel 5.000, 100 packs | *"100 packs at 0.250 kg each is 25.000 kg — more than the 5.000 kg wheel. At most 20 packs can come off it."* |
| `cut_date` 01/01/2020 | *"The cut date cannot be before the batch was produced (2026-04-03)."* |
| `cut_date` 02/04/2026 (day before production) | same message |
| wheel weight omitted | *"The wheel weight field is required."* |

A sane cut — wheel 5.120, 18 packs, 4.680 kg — recorded both weights and the
batch page's cutting history now reads
`5.120 kg / 18 / 4.680 kg / 0.440 kg waste / 0.260 kg avg`, with the legacy row
honestly showing **"not weighed"** and a null waste, under the header
**"Trim loss 0.440 kg over 1 weighed cut · 1 not weighed"**. The relation fix is
visible too: the history is this batch's, not whichever item shared its id.

**Finding 5 confirmed closed.** On a 3-wheel line, posting
`weights[]=4.000, 5.000, 6.000` with `actual_weight_kg=100` stored **15.000 kg**
and billed €240.00 (15 × €16.00) — the forged client total was ignored. Refusals
are clear: a total with no `weights[]` → *"Weigh each unit — one weight per unit
picked."*; three weights for a quantity of two → *"Enter exactly one weight per
unit picked (2)."*; a zero → *"Each weight must be more than zero."* Undoing one
unit left **9.000 kg**, so the 6.000 wheel came back rather than an average.

As the report predicted, finished weight still inflates — 191.50 → 196.00 kg and
4.8% → 4.9% after the verification cut. That is pass 2b's job, and it is the only
part of this finding still open.

Not verified: the bulk-weighed path (item 6 of the report's checklist). No bulk
line was exercised, so nothing is claimed about it either way.

### Finding 11 — a missing optional field 500s on order creation

Found while setting up the finding 5 verification, and the same shape as the
`notes` bug pass 2a fixed in the cutting controller.

```
POST /orders   (customer_id, order_date, delivery_date, items[0][...])
  -> 500  Undefined array key "delivery_address"
          OrderController.php:263
```

`validate()` drops keys that were never submitted, and line 263 reads the key
unconditionally. The browser form always posts `delivery_address` (the textarea
submits empty), so the UI never hits it — only a client that omits the optional
field does. `?? null` closes it, the same way the cutting controller's did.

Worth a sweep rather than a one-line fix: this is now the second instance of the
pattern, so it is worth checking every controller that reads a validated array key
that isn't `required`. The mossorders import path should be checked specifically —
it is the one client that posts orders without a browser form behind it.

*Test:* creating an order without `delivery_address` succeeds rather than 500s.

**Closed (2026-09-13, pass 2b).** Sweep done across all of `app/` — every
`$validated[...]` / `validated()` read against a non-`required` rule, plus every
payload read in the mossorders import path (`ImportOnlineOrders`,
`OnlineOrdersController`, `OnlineOrderImportService`), which was already fully
guarded with `isset`/`??`. The **only** unguarded reads were the three in
`OrderController::store()` — `delivery_date`, `delivery_address`, `notes` (all
`nullable`) — now `?? null`, matching the `customer_reference` line beside them
(the one field the original author knew could vanish, because its input is behind
an Alpine toggle). Every other optional read is `??`-guarded, behind `isset`/
`filled`, coerced via `$request->boolean()`/`has()`, or mass-assigned without
indexing. `tests/Feature/OrderStoreOptionalFieldsTest.php` posts only
`customer_id`, `order_date`, `items` and asserts the order lands with nulls. (The
existing `OrderCustomerReferenceTest` posts the keys present-but-null, which
`validated()` keeps — it never covered absence.)

### Finding 3 — `cut_date` has no lower bound

`before_or_equal:today` is present and works — a 2030 date is rejected with a
clear message. `after_or_equal:` the batch production date is missing, so a cut
dated **01/01/2020** was recorded against a batch produced 03/04/2026. The rule
is half-written rather than absent.

*Test:* `cut_date` earlier than `batch.production_date` is invalid.

**Closed (2026-09-13, with finding 2 pass 2a).** `CheeseCutRequest` rules
`cut_date` as `after_or_equal:{production_date}|before_or_equal:today`.
Pinned by `CheeseCutWeightTest::test_cut_date_cannot_precede_the_batch_production_date`
(2020 and the day before production refused; the production day, today
allowed; tomorrow refused).

### Finding 4 — "Release to mature" is a no-op

Batch G030426, age 5 months, flagged **Ready to release**, 2 wheels held.

```
POST /cheese-conversion/release/26  quantity=2     -> 302 -> /stock, maturing still 2
POST /cheese-conversion/release/26  quantity=50    -> 302 -> /stock, maturing still 2
POST /cheese-conversion/release/26  quantity=-3    -> 302 -> /stock, maturing still 2
```

All three identical: redirect to `/stock`, no state change, no error, no flash.
Indistinguishable from success. No mature batch is created and no Mature product
exists in the catalogue — only Cumin, Farmhouse, Garlic & Basil and Mediterranean
— so the likeliest reading is that the release has no destination and fails
silently.

Note the contrast with its sibling: `/cheese-conversion/hold/26` validates
properly, refusing `quantity=999` with *"must not be greater than 31"* and
`quantity=-5` with *"must be at least 0"*, both bounded to real available stock.
**The hold endpoint is the pattern findings 2 and 5 should copy — the correct
version already exists in this codebase.**

*Test:* release moves held wheels out of the source batch; over-release is
refused; a missing mature product produces a visible error rather than a redirect.

**Closed (2026-09-12).** Two causes, both fixed.

1. *No destination.* `ProductSeeder` has seeded `Mossfield Mature Cheese` (+ Whole
   Wheel / Vacuum Pack) for a while; this database predated it. Confirmed the
   seeder is a no-op for everything already present (every `firstOrCreate` key
   matched the dev rows), then ran `php artisan db:seed --class=ProductSeeder`:
   6 → 7 products, variants 15 + 16 added, nothing else touched.
   `tests/Feature/MatureProductSeedTest.php` pins that the seeder provides
   exactly what `release()` resolves, is idempotent, and that a release against
   a seeded catalogue actually mints a Mature batch.
2. *`quantity` ignored.* `release()` now validates `quantity` as
   `required|integer|min:1|max:{saved hold}` — the same bound and the same
   messages as `hold()` ("must not be greater than 2", "must be at least 1") —
   and releases exactly that many, so a partial release is possible. Failures
   redirect to the allocator explicitly (a manual `Validator`, not
   `$request->validate()`'s `back()`), where the layout renders them. The UI
   change that goes with it: the Release button used to submit the *hold* form,
   whose hidden `quantity` is the allocator's live (possibly unsaved) Maturing
   count. It now submits a dedicated sibling `release-form-{id}` (via `form=`)
   carrying the **saved** hold — so what the button says ("Release 2 to mature")
   is what is posted, and what is posted is what the server will accept.
   `CheeseConversionTest` gains four cases (50 / 3 / 0 / −3 / "two" / absent all
   refused with nothing released; over-release shows the hold() message; partial
   release; the form posts the saved hold) and its existing release POSTs now
   carry `quantity`.

### Finding 5 — the billed weight is whatever the browser says it is

The audit below confirms weight-priced lines bill recorded kg rather than the
nominal estimate. They do — but the recorded kg arrives from the client and is
stored without recomputation. `actual_weight_kg` is a hidden input summed in JS
from the per-wheel `weights[]` boxes:

```
POST /order-allocations/28/fulfill
  quantity=2  weights[]=4.800  weights[]=5.200  actual_weight_kg=100   -> accepted
```

The line then reads **"100.000 kg × €16.00"** for two wheels whose own recorded
weights total 10 kg. It takes tampering to do deliberately, but it also means any
JS bug in that sum mis-prices orders with nothing server-side to catch it.

Recompute the total from `weights[]` server-side and ignore the submitted value,
or reject a mismatch outside tolerance.

*Test:* posting `weights[]=[4.8, 5.2]` with `actual_weight_kg=100` stores 10.0.

**Closed (2026-09-13, finding 2 pass 2a).** The per-unit weights are now what
the server validates and stores; the client total is ignored.

- `order_allocations.unit_weights_kg` (migration `2026_09_13_120100`, JSON,
  nullable; cast `App\Casts\KilogramList` so it always reads back as
  `list<float>` at 3 dp) holds one figure per unit in pick order, appended
  across partial picks. Null on bulk-weighed lines and on rows fulfilled before
  the migration.
- `App\Http\Controllers\Concerns\ValidatesFulfilmentWeights` is shared by
  `OrderAllocationController::fulfill()` and `PickingController::pick()`: a
  per-unit variable-weight line requires `weights[]` with **exactly `quantity`
  entries**, each `numeric|min:0.001`; a bulk-weighed line still takes one
  `actual_weight_kg`; a fixed line takes neither. The old shape — a per-unit
  line posting only `actual_weight_kg` — is refused. `OrderItem::fulfillAllocation()`
  / `pickFromBatchItem()` take the list and **recompute** `actual_weight_kg` as
  its sum; `[4.8, 5.2]` with `actual_weight_kg=100` stores 10.000 and bills
  10 kg × €/kg.
- `unfulfillAllocation()` now takes back the **last N** unit weights and exactly
  their kg (was: an average), falling back to the average only where no list
  exists.
- The picking form's per-piece inputs had no `name` at all (Alpine-only) — they
  now post `weights[]`; the office form already did.

Tests: `tests/Feature/FulfilmentUnitWeightsTest.php` (7) — both forms store the
list and recompute, the validation shapes (missing / too few / too many /
non-numeric / zero), bulk unchanged, partial picks append, undo pops the
unit's own weight, and both forms post `weights[]` by name. `PickingTest`,
`OrderFulfillmentWeightTest` and `StockFlowAuditTest` were updated to post
`weights[]` for per-unit lines.

### Finding 6 — `items.*` validation errors are never rendered

Post an order line with `quantity=-5` past the HTML5 `min` and the request is
correctly refused — 422, `items.0.quantity` must be at least 1. The browser lands
back on `/orders/create` with the form repopulated and **no error anywhere on the
page**. The validation is right; the blade view isn't rendering the bag.

Two fixes: render `@error('items.*.quantity')` and siblings, and give the fields
human labels — *"The items.0.quantity field must be at least 1"* is a raw
attribute name. The same silent-failure shape applies to the fulfil form, the cut
form and the release endpoint in finding 4.

**Closed (2026-09-12).** Three parts, and nothing remains open:

- *Never rendered* — fixed by the layout feedback region (finding 9): every
  page renders the default bag once, so `items.*` errors surface on
  `/orders/create` (re-verified in the browser: one message, exactly once).
- *Raw attribute names* — fixed by the `attributes` map on
  `OrderController::store()`: "The quantity field must be at least 1." /
  "The selected product is invalid."
- *Which line?* — this was the only part still open after the previous pass,
  and the earlier note left it ambiguous. With several lines the global summary
  names the field but not the row. `orders/create.blade.php` now also renders
  `<x-input-error>` **inline in the offending row** (the `old('items')`
  repopulation branch — the only one that renders after a failure), under the
  product select and the quantity input. `LayoutFeedbackTest::test_order_line_errors_render_inline_in_the_offending_row`
  posts three lines (valid / −5 / unknown product) and asserts each message
  appears in its own row only, once in the summary + once inline, and no raw
  `items.1.quantity` anywhere. (A blank product is a discarded template row by
  design — `store()` filters those before validating — so it is not an error.)

### Finding 7 — order line variants are indistinguishable

The `/orders/create` product dropdown labels options with the variant name only,
producing three identical `Whole Wheel (€16.00/kg)` entries and four identical
`Vacuum Pack (€4.50)` entries. I picked one intending Farmhouse and the order came
out as **Mediterranean**. On a wholesale order form that is a mis-shipment
mechanism. Prefix the product name, or group by product with `<optgroup>`. Same
dropdown on the order detail "Add item" form.

**Closed (2026-09-13).** All four pickers (`/orders/create` incl. its JS
add-row template, the `/orders/{order}` Add item form, the customer special-price
form, the chilled-run "Add line…" select) already grouped by `<optgroup>` — which
only helps while the list is open; the closed control still read
"Whole Wheel (€16.00/kg)" with no product. Options now render
`ProductVariant::picker_label` — `Farmhouse Cheese · Whole Wheel` — via
`Product::short_name` (the shared "Mossfield " prefix dropped), so no two options
read identically anywhere, list open or closed. `ConsolidationPassTest` parses
every `<select>` on the four pages and asserts no duplicate option text, and
checks the JS template separately.

### Finding 8 — the batch page folds cut wheels into "sold"

`/batches/17` reports Whole Wheel **sold 6**; `/stock` reports **4 sold, 2 cut**
for the same batch. Same root as finding 1 — a second opinion about what the
batch contains.

**Closed (2026-09-13).** Two surfaces, one source.

- **The batch page** table reads Produced · On hand · Sold · Cut · Converted from
  `BatchItem::weight_breakdown` — the same states the finished weight above it is
  derived from, so the page agrees with itself and with `/stock`. G030426 reads
  38 · 31 · 4 · 2 · 1 (was "sold 7"). Packs show "—" for cut/converted.
- **The cards** (`/batches`, `/cheese-cutting`, and both pages' variety headers)
  used four copies of a `sold = produced − remaining − cut` formula, none of
  which knew about converted wheels. They are replaced by one
  `BatchItem::wheelStates()` (free / allocated / maturing / cut / **converted** /
  sold, summing to produced; uses the index queries' loaded aggregates —
  `source_cutting_logs_count`, the new `converted_wheels` withSum,
  `quantity_currently_allocated` — and falls back to querying) rendered by two
  shared partials, `batches/partials/_wheel-strip` and `_wheel-legend`. The strip
  gained a purple converted dot; the numeric summary labels every figure and
  drops zero states, which also closes the "unexplained fifth segment" item in
  `SMALL_FIXES.md` (it was allocated = 0).
- On the way: `BatchController::index()` and `StockOverviewService` dropped the
  `whereNull('fulfilled_at')` gate on their reservation `withSum` — the last two
  sites carrying the shape the invariant forbids.

`ConsolidationPassTest` works a 10-wheel batch through pick / cut / hold+release
and asserts the page row (10 · 6 · 2 · 1 · 1), its agreement with `/stock`'s
segments, and that both card surfaces read "2 sold · 1 converted", not "3 sold".

### Consolidation pass verified through the UI (2026-09-13)

Findings 7 and 8 closed, and the small-fixes batch confirmed. Checked against the
consolidation report's own list.

**Finding 7.** Every option in the `/orders/create` variant picker now reads
`<Product> · <Variant> (price)` — `Organic Milk · 1L Bottle (€2.50)`,
`Cumin Cheese · Vacuum Pack (€4.50)` — and across all 17 options there are **no
duplicates**. The collision that produced a Mediterranean order when Farmhouse was
intended cannot recur.

**Finding 8.** `/batches/17`'s stock table splits the states it used to fold:
`Produced 38 · On hand 26 · Sold 4 · Cut 4 · Converted 4`, with `—` for cut and
converted on the vacuum-pack row. `/stock` reads the same four numbers. The batch
list's summary strip reads **"26 free · 4 cut · 4 converted · 4 sold / 38"**, and
the compact dot strip above it now labels every figure — including the `0` that had
no meaning, which turns out to be *"Allocated to orders"*. Three surfaces, one
reconciliation.

**Small fixes, 9 ticked.** Confirmed live: the dashboard greeting is two sentences
(*"All orders allocated. Online sync is behind."*); `/` redirects to `/dashboard`
signed in; a partially allocated line now reads *"4 of 30 still unallocated — no
stock left for the rest"* in place of the old flat contradiction; the cut form at
zero free renders **no form at all**, just a warning and links out; and a fully
held batch disappears from `/cheese-cutting` entirely.

**Fonts are self-hosted.** A clean page load requests nine `.woff2` files from
`/build/assets/` and **nothing** from `fonts.bunny.net`. The internal app no longer
needs the public internet to render as designed.

Not verified live: the new *"Every ready wheel is spoken for"* empty state. With
G030426 fully held the cutting list was not empty, because the Mature batch
G030426-1 now offers 4 cuttable wheels — so the all-held branch could not be
reached. Both messages are present and correctly distinguished in
`cheese-cutting/index.blade.php`, verified by inspection rather than in the browser.

Two items were deliberately left unticked and remain open: the 31-input
variable-weight fulfilment form, and the native `confirm()` dialogs. Both are
larger than the batch they sat in.

One observation rather than a defect: the batch-list dot strip carries its labels
in `title` attributes, so five numbers in a row are only distinguishable on hover.
A visible legend would read better on a screen someone scans rather than explores.
The brief asked for labels and it has them.

### Finding 9 — the cut form advertises stock the cut endpoint will refuse

Follow-up to finding 1, and the surviving half of it. `/cheese-cutting/cut/{id}`
displays **"Wheels available"** from the batch item's raw `quantity_remaining`
(`cheese-cutting/create.blade.php:38`); the batch page's **"Cut Whole Wheel
(N left)"** button does the same (`batches/show.blade.php:220`). Neither subtracts
the maturing hold or reservations.

Proved by holding the whole batch. With all 31 remaining wheels set aside as
maturing — `/stock` then showing **no available line at all**, just
`MATURING 31 · CUT 3 · SOLD 4` — the cut form still read:

```
Wheels available    31
```

**The controller is right; only the screen is wrong.** Two cut attempts against
the fully-held batch were both refused: wheels stayed at 31 maturing, cut stayed
at 3, packs stayed at 107. (The same form with 2 held and 29 free had accepted a
cut minutes earlier, so the guard is on free stock, not on validation.)

But the refusal is invisible. Both attempts returned **302 to `/stock`** — the
success path — with no flash, no error, nothing to distinguish a refused cut from
a completed one. An operator reads "Wheels available 31", fills the form,
submits, lands on the stock overview, and has no way to know nothing happened.
This is finding 6's silent-failure shape on a third endpoint (finding 4 is the
fourth).

*Fix:* both surfaces should read free stock — `BatchItem::freeStockByVariant()`
or `available_quantity` — so they can never offer a held wheel; and the refusal
path needs a flash message naming the reason.

*Test:* with the batch fully held, the cut form reports 0 available and a POSTed
cut is refused **with a visible error**.

**Fixed (2026-09-12).** Both surfaces now read `available_quantity`
(`cheese-cutting/create.blade.php` "Wheels available"; `batches/show.blade.php`
filters the Cut buttons on it and prints `(N left)` from it — a fully held batch
shows the disabled "No wheels available" button instead of a Cut link). Per-item
accessor reads, a handful per batch page — the same thing the neighbouring
"Convert to Mature (N free)" already did.

The invisible refusal was **not** a missing message. `CheeseCuttingController::store()`
already returned `back()->withErrors(['error' => 'No free wheels remaining…'])`,
and so did both refusal branches in `CheeseConversionController::release()`
(finding 4), and `OrderController::store()`'s `items.*` rules fail correctly
(finding 6). What all three shared was the **destination**: `x-app-layout`
rendered neither session flashes nor the error bag — every page opted in
individually, and the pages a refusal actually landed on hadn't. `/stock`
(where a Referer-less POST's `back()` went) rendered nothing; `/orders/create`
rendered `$errors->get('items')` but not the nested `items.0.quantity` keys.
One fix covers all three: **`layouts/partials/feedback.blade.php`, included
once in `layouts/app.blade.php`**, renders `session('success'|'error'|'warning')`
and the default error bag on every page. The ~20 per-page copies (and
`invoicing/partials/_flash`) were removed so nothing renders twice
(`LayoutFeedbackTest` pins exactly-once). The two cut/release refusals also
swapped `back()` for explicit `redirect()->route(...)` to the form / allocator
page, so a scripted POST can't land the message on `/` (the welcome page is
outside `x-app-layout`). `items.*` got human attribute labels ("The quantity
field must be at least 1"). Also found by the new test: omitting the optional
`notes` field on a cut POST threw `Undefined array key "notes"` — the textarea
always posts, so only forged requests hit it; now `?? null`.

Tests: `tests/Feature/CheeseCutFreeStockTest.php` (form reads free stock,
batch-page button count / disappears when fully held, refusal lands on the
form with the reason — with and without a Referer — and a permitted cut still
confirms) and `tests/Feature/LayoutFeedbackTest.php` (flash on a never-opted-in
page, exactly-once on a page that used to render its own, `items.*` message
visible with a human label, release refusal visible). Findings 4 and 6 are
**not** closed by this — release still ignores `quantity` and the Mature
product is still unseeded on that DB; the refusals are just visible now.

**Re-verified through the UI (2026-09-12), and one surface was missed.**
Confirmed fixed: `/batches/17` now reads **"Cut Whole Wheel (29 left)"** beside
**"Convert to Mature (29 free)"**, agreeing with `/products` and `/stock`; with
all 31 wheels held the batch page shows **"No wheels available"** and no Cut
link, and `/cheese-cutting/cut/26` reports **"Wheels available 0"**. A POSTed cut
against the fully-held batch now lands back on the cut form showing **"No free
wheels remaining to cut (remaining wheels are allocated or set aside for
maturing)."** The release refusal now shows **"Mature cheese product is not
configured. Run the product seeder."** on `/cheese-conversion` — which is
finding 4's cause, stated on screen. A real browser submission of a `-5` order
line lands back on `/orders/create` with **"The quantity field must be at least
1."** rendered exactly once. All 20 main pages return 200 after the layout
change.

**Still reading `quantity_remaining`: the cutting index itself.**
`/cheese-cutting` — the "Cheese Cutting" nav destination, and where an operator
actually starts — was not among the surfaces fixed. With 29 free and 2 held it
shows:

```
Wheels left: 31
Available wheels · Whole Wheel · 31 wheels remaining   [Cut wheel]
```

So the index still advertises 31 and still offers its own **Cut wheel** link off
that number. With the batch fully held it continued to list the batch under
**"Ready to cut"**. This is the same fix as the other two surfaces
(`available_quantity`), on `cheese-cutting/index.blade.php` and whatever feeds
`CheeseCuttingController::index()` — which the invariant list below already flags
for separate reasons (it still wraps its `withSum` in `whereNull('fulfilled_at')`).
Lower severity than before the fix, because the controller refuses and now says
why — but it is the first screen in the workflow.

**Cutting index fixed (2026-09-12).** `CheeseCuttingController::index()` keeps
its one `withSum` (alias unchanged, the card's strip still reads it) but the
`whereNull('fulfilled_at')` wrapper is gone, and after loading it stamps a
`free_quantity` on every batch item from the sums already in hand —
`max(0, remaining − owed − maturing)`, the invariant's expression, with no SUM
per row — then **drops any batch with no free wheel** from `$readyBatches`, so a
fully held batch no longer appears under "Ready to cut" and the count tag is
honest. `partials/batch-card.blade.php` builds `$cuttableWheels`, "Wheels left"
and each "N wheels available" line (was "remaining") from `free_quantity`; the
empty state now says why ("cut, sold, reserved or set aside to mature"). The
per-wheel strip and the sub-group header totals are the shared four-way formula
and were left alone. Pinned in `CheeseCutFreeStockTest` by four index cases:
29-free-of-31 reads 3-of-5 not 5, a fully held batch is absent, reserved wheels
are subtracted after a partial pick (5 wheels, 3 reserved, 1 picked ⇒ 2), and
the same with `fulfilled_at` force-stamped on the partial allocation ⇒ still 2 —
the case the old `whereNull` gate would have got wrong.

### Finding 10 — the mossorders export reports expired stock as available

`Api\ProductExportController::calculateStockAvailable()` is the `stock_available`
field the online ordering portal reads. It sums `quantity_remaining` over batches
that are `status = 'active'` with a passed `ready_date` — and checks **nothing
else**. No `expiry_date`, no reservations, no maturing hold.

Expiry is the sharp edge, because expired batches are not closed. Batch 16
(M220626) has `expiry_date = 2026-07-02` — over two months past — and
`status = active`, confirmed on its own edit form. It and two more expired milk
batches therefore count in full:

| Variant | export reports | actually sellable |
| --- | --- | --- |
| 1L Bottle | ~2,700 | 438 |
| 2L Bottle | ~1,726 | 192 |
| 10L Churn | ~11 | 0 |

Roughly a **sixfold oversell** on the one line customers order most.

Note the comment: *"Uses the existing `total_stock` accessor if available."* It
does not. The `method_exists()` branch reimplements a different sum in PHP over
the loaded `batchItems`, so the accessor fix in finding 1 did **not** propagate
here despite the code reading as though it defers to it. That vestigial
`method_exists()` check is worth deleting outright — both branches always exist,
and it is what disguised the divergence.

Every other disagreeing surface produces an internal inconsistency someone
notices. This one produces a customer ordering milk that expired in July.

*Not verified live* — the endpoint is token-gated and the portal was never opened.
The conclusion is read from the controller plus the confirmed batch statuses.

*Test:* an expired-but-active batch contributes 0 to `stock_available`; a reserved
unit contributes 0; a held wheel contributes 0; and the exported figure equals the
`/products` figure for the same variant.

**Fixed (2026-09-12).** `ProductExportController::index()` now calls
`BatchItem::freeStockByVariant($variants->pluck('id'))` once for the whole
export and reads `stock_available` from the map — the same function behind
`ProductVariant::total_stock`, so the portal agrees with `/products` by
construction. `calculateStockAvailable()`, `isBatchReady()` and the
`batchItems.batch` eager load are gone, and so is the `method_exists()` shim
(`getFullName()` had the same false "uses the accessor if available" shape and
went too — it's `$variant->full_name`). Query count is fixed regardless of
variant count. `tests/Feature/Api/ProductExportStockTest.php` (7 cases) pins
expiry (weeks-old and today), reservations after a partial pick, the maturing
hold, `ready_date`, equality with `total_stock`, and the query ceiling.

### Cosmetic and lower priority

- `/` still serves the **stock Laravel welcome page**, docs links and a Laravel
  Cloud "Deploy now" button included.
- Dashboard greeting reads *"All orders allocated, and online sync is behind"* —
  two unrelated clauses joined by "and".
- Dashboard reports **online order sync has never completed successfully**. Not
  investigated.
- A line with 31 units allocated still displays *"No available stock. No
  ready-to-sell stock available for this product variant"* beneath it. It means
  the outstanding 4; it reads as a contradiction.
- Variable-weight fulfilment renders **one kg input per unit** — 31 boxes in a row
  for a 31-wheel allocation. Painful on a desktop, unusable on a phone.
- The batch progress bar draws five segments (`31 · 0 · 2 · 1 · 4 / 38`) against a
  four-item legend. The stray `0` means nothing on screen.
- Data: customer **"Avoa Ballsbridge"** (Avoca). Yoghurt **16L Bucket at
  €72.00/kg** against a 16 kg unit weight implies €1,152 a bucket — likely meant
  as €72.00 flat, or the €/kg flag is wrong. Garlic & Basil Whole Wheel is €40.00
  flat where every other wheel is €16.00/kg; possibly deliberate.
- Batch "total weight" columns use the variant's **nominal** unit weight
  (33 × 5.000 = 165.00 kg) on a product sold by actual weight.
- Fonts load from `fonts.bunny.net` — an internal LAN app that needs the internet
  to render as designed.
- Destructive actions use native `confirm()` via inline `onclick`. Functional, but
  unstyleable, untestable, and it blocks browser automation outright.

### Confirmed correct through the UI

Order cancellation returned all 31 allocated **and** 2 fulfilled wheels to the
batch exactly · auto-allocate excludes maturing stock · the mature-conversion hold
endpoint validates against real available stock with clear messages · cut-form
quantity and weight validation refuses negative weight, zero weight and zero packs
· future cut dates refused · order status advances Pending → Confirmed → Preparing
on its own as allocation happens · zero-stock lines produce a genuinely good
message pointing at closing the line short · customers with orders lose their
Delete link · `/orders/999999`, `/batches/999999`, `/customers/999999`, bad slugs
and non-numeric IDs all return clean 404s with no stack trace and no `APP_DEBUG`
leak · no JavaScript console errors on any page visited, and no failed requests
across 466 captured.

### Not covered by this pass

Invoicing and VAT through the UI (tile reconciliation, multi-rate documents,
rounding, credit notes, consolidated weekly/monthly) · picking and delivery runs ·
the online order sync, and the mossorders export endpoint itself (token-gated;
finding 10 is read from the code) · **every authorisation boundary** — this ran entirely as an
admin, so `/users` and `/backup` as a non-admin, logged-out access, and IDOR by
URL on `/orders/{id}`, `/customers/{id}`, `/invoicing/{id}` are all untested ·
double-submit, back-button resubmit and two-tab concurrent edit · mobile layout.

### Dev-database state

**Repaired in place on 2026-09-13 — verified through the UI.** The damage below
has been reversed surgically rather than by reseeding, because `CustomerSeeder`
produces fictional customers and a `migrate:fresh --seed` would have replaced the
realistic customer set with them. Confirmed after the repair: G030426 reads
`finished weight 191.50 kg · yield 4.8%`, wheels
`available 31 · maturing 1 · cut 1 · converted 1 · sold 4 = 38`, vacuum packs back
to `6 produced / 6 sold / 0 remaining`; `/products` shows Farmhouse Whole Wheel 31
and the milk figures unchanged at 438 / 192; the CLAUDE-TEST customer is gone
(16 customers, the original count), `/orders/8` returns 404 and the seven original
orders are intact. Batch **G030426-1** and its converted wheel were kept on
purpose. The record of what was wrong is below.

This pass left marks on the dev copy: batch **G030426** had one wheel cut dated
01/01/2020, one wheel cut into 100 packs, and a finished weight inflated from
191.50 kg to 216.75 kg. Also customer **CLAUDE-TEST Wholesale** and cancelled
order **ORD-20260912-002**.

The finding 9 probe held all 31 wheels of G030426 and then returned the hold to
its original 2; both refused cuts changed nothing.

Verifying the finding 4 fix then released **1 wheel** for real, which is not
reversible through the UI: batch **G030426-1 · Mossfield Mature Cheese** now
exists (1 wheel, matured from G030426) and the Mature product and its two
variants were added by the seeder. Both were kept in the repair: the seeded
product is intended, and the converted wheel is a working feature demonstrating
itself.

**Consolidation verification (2026-09-13)** created and cancelled orders 10 and
11, and held then released G030426's wheels to reach the empty states. `/stock`
is back where it started the pass: `available 26 · cut 4 · converted 4 · sold 4`.

**Pass 2b verification (2026-09-13)** left two more cuts on G030426 (5.000 and
5.120 kg wheels), released 3 more wheels to G030426-1, and cancelled a milk order
10 for Organic Store. `/stock` now reads
`available 26 · cut 4 · converted 4 · sold 4 = 38`, with G030426-1 holding 4
wheels. `mossfield:reset-trading-data --confirm --seed=1` is the way back.

**Pass 2a verification (2026-09-13)** left one more real cut on G030426 (wheel
5.120 kg → 18 packs, 4.680 kg, 0.440 kg waste) and a cancelled order 9 for
Organic Store whose 3 wheels were returned to stock in full. `/stock` reads
`available 30 · maturing 1 · cut 2 · converted 1 · sold 4 = 38`.
`mossfield:reset-trading-data --confirm` now exists if a clean slate is wanted.

### Re-verification of pass 1 (2026-09-13)

Driven through the UI. All confirmed:

- `/cheese-cutting` now reads **"Wheels left: 29"**, matching `/products` and
  `/stock`. With all 31 wheels held the batch disappears from the list —
  "Ready to cut · 0 batches".
- Release refusals are bounded and legible: `50` → *"The quantity field must not
  be greater than 31."*, `-3` and `0` → *"must be at least 1."*, `two` →
  *"must be an integer."* Nothing released in any case.
- A real release of 1 works end to end and carries traceability both ways:
  G030426 shows *"Matured into G030426-1"*, G030426-1 shows *"Matured from
  G030426"*, with a `ready_date` of 31/08/2026 (five months after production).
  `/stock` gained a **CONVERTED** category.
- `/orders/create` with three lines (valid / `-5` / unknown variant) renders a
  *"Please fix the following"* summary **plus** the message inline in its own row
  — quantity error on line 2 only, invalid-product error on line 3 only, line 1
  clean, no raw `items.1` attribute names anywhere.
- All 20 main pages still return 200.

Two small things noticed while verifying, neither worth a finding of its own:

- The `/cheese-cutting` empty state explains the wrong reason. When every wheel is
  held it still says *"Cheese batches need to mature before they can be cut. Check
  back when batches reach their ready date."* — but the batch **is** ready; its
  wheels are held or allocated. The refusal message on the cut form gets this
  right; the empty state should too.
- The batches list now folds **converted** wheels into "sold" alongside cut ones —
  it reads `sold 5` where `/stock` reads `sold 4 · converted 1`. That is finding 8
  (still open) widening rather than a new defect; worth fixing both labels in one
  go.

---

## 2026-09-12 — Order → allocation → adjustment → invoicing audit

**Scope:** the money-and-stock spine end to end. Order entry, FIFO reservation,
picking, every stock adjustment path (undo, cancel, line edit, line removal,
short close, maturing hold), then invoicing.

**Coverage added:** `tests/Feature/StockFlowAuditTest.php` — 22 cases, driven
through the real HTTP routes rather than the models directly, so the controller
guards are exercised alongside the model methods. The existing suites cover each
stage in isolation; this one checks the seams, on one rule: **a unit of stock is
never decremented twice, never lost, and never billed for more than was actually
supplied.**

### Confirmed correct (no change needed)

Reservation never decrements stock · picking decrements exactly once · FIFO
takes the oldest batch · two orders can't reserve the same units ·
over-allocation past `quantity_ordered` is refused · expired and
still-maturing batches are excluded · the maturing hold removes wheels from
allocation · undo restores the batch and keeps the reservation · cancel returns
reserved **and** picked stock · line edits release the surplus · removing the
only line cancels the order and keeps it as history · short-closed lines bill
only what was supplied (exactly €0.00 when nothing was picked) · weight-priced
lines bill recorded kg, not the nominal estimate · the 23% delivery VAT backs
out so net + VAT equals the entered gross to the cent · the invoice snapshot
holds and locks the order against edits · void returns the order to the queue
and re-issues under a new number.

### Finding 1 — `available_quantity` under-reported after a partial pick

`BatchItem::getHoldableQuantityAttribute()` summed the **whole**
`quantity_allocated` of every allocation whose `fulfilled_at` was null. The
fulfilled portion has already left `quantity_remaining`, so those units were
subtracted a second time.

Batch of 20, order 10, pick 4 → 16 physically remain and 6 are still reserved,
so 10 are free. It reported **6**. Four units of real stock were invisible to
every allocation path.

### Pass 2b (2026-09-13) — the weight is derived from what was measured

**The derivation.** `BatchItem::weight_breakdown` puts every unit of an item in
exactly one state and weighs it from the best source that exists:

| state | units | source |
| --- | --- | --- |
| on hand (incl. maturing) | `quantity_remaining` | nominal |
| dispatched whole | Σ `order_allocations.quantity_fulfilled` (never gated on `fulfilled_at`) | measured — `actual_weight_kg` (the pre-2a rows have the total, no unit list — still measured) |
| cut | count of `sourceCuttingLogs` | measured — `wheel_weight_kg`; nominal when null (pre-2a) |
| converted out | Σ `sourceConversionLogs.wheels_converted` — the wheel count, **not** the log count | nominal |

`on_hand + dispatched + cut + converted === produced` is the per-item
`reconciles` flag. **Vacuum packs that came off a cut wheel contribute nothing** —
the wheel already did — so only packs no cut log accounts for (`produced − Σ
targetCuttingLogs.vacuum_packs_created`, clamped) count, nominally. Milk and
yoghurt items stay `produced × unit weight`. `Batch::finished_product_weight`
(same name, so `production_yield` and the page keep working) is Σ `total_kg`;
`Batch::weight_coverage` gives `wheels_weighed / wheels_produced`, `measured_kg`,
`nominal_kg`, `reconciles`. Both read only *loaded* relations —
`BatchController::show()` eager-loads `orderAllocations`, `sourceCuttingLogs`,
`sourceConversionLogs`, `targetCuttingLogs` on the items and the batch hands
itself down to each item, so the page computes the figure with zero extra
queries (checked). `quantity_produced` is untouched.

**G030426 now reads ~188.31 kg, yield ~4.7 %, "5 of 38 wheels weighed"** (was
196.00 / 4.9 %): on hand 31 × 5.000 = 155.000 (the brief's "30 on hand" is the
*available* count — the held wheel is on hand too); dispatched 4 wheels measured
4.870 + 4.200 + 9.120 = 18.190; cut 2 — one measured 5.120, one pre-2a at
nominal 5.000; converted 1 at nominal 5.000. Measured 23.310 kg, nominal
165.000 kg, reconciles 31 + 4 + 2 + 1 = 38. The 24 packs add nothing.

**Coverage on screen.** The Finished weight figure carries a `~` whenever any of
it is nominal, a hover title with the measured/nominal split, "N of M wheels
weighed" under it (and "does not reconcile" in red if the four states don't sum
to produced), and the yield line is `~4.7%` or `(nominal)` when nothing is
measured. Milk/yoghurt read `(nominal)`.

**The "Total weight" column: relabelled, not moved.** `BatchItem::total_weight`
is `remaining × unit weight` — the *on hand* state, which the table above
defines as nominal because nothing still on the shelf has been weighed (wheels
are weighed on the way out). There is no measured basis to move it to, and a
produced-basis measured figure per row would just restate the batch total and
reintroduce the pack-row double count in the table. Header now "On hand
(nominal)", cells `~155.00 kg`. Accessor unchanged.

**The Decision's *Test* line, refined.** "A valid cut never raises
`finished_weight_kg`" was written in the nominal world. Under the measured model
a cut changes the figure by exactly (measured wheel − nominal wheel) — up or
down — and *never* by the pack weight. That is what the test pins: a wheel
weighed at nominal cut into 4.68 kg of packs leaves the figure unchanged; at
4.900 it drops 0.100.

**Rider.** `StockOverviewService` computed the `/stock` `converted` segment from
the *count* of conversion logs; `release()` writes one log per release carrying
`wheels_converted = N`, so a 3-wheel release showed 1. Now `withSum(...
wheels_converted)`. (`BatchController::deletionBlocker()` keeps its count — it
names *records* in the way.)

Tests: `tests/Feature/BatchFinishedWeightTest.php` (10) — the reconciliation
through the real pick / cut / hold+release routes (6 + 2 + 1 + 1 = 10, 50.120 kg,
3 of 10 weighed, packs add nothing); a cut never adds pack weight; an unweighed
legacy cut falls back to nominal; packs no cut explains count nominally; a
measured total with no unit list still counts as measured; milk unchanged; the
page states coverage and the on-hand label; a wholly measured batch drops the
tilde; a Mature batch uses the same derivation (nominal until sold, measured
after); the `/stock` converted segment counts wheels. Finding 2 is **closed**;
the open question (attributing a converted wheel's eventual sale weight back to
the source batch) is in the pass 2b report, unbuilt.

**Re-verified through the UI (2026-09-13).** The figure and its arithmetic hold,
and the conservation-of-mass bug is gone.

`/batches/17` reads **~188.31 kg**, *"5 of 38 wheels weighed"*, **Yield ~4.7%**,
against 196.00 kg / 4.9% before the pass. The tooltip splits it honestly —
*"23.310 kg measured · 165.000 kg nominal"* — and the stock table's column is
relabelled **On hand (nominal)** with its own explanation on hover.

The decisive test, two more cuts through the form:

| cut | expected | read |
| --- | --- | --- |
| wheel weighed at the nominal 5.000 | no change | ~188.31 kg |
| wheel weighed at 5.120 | +0.120 | **~188.43 kg** |

Under the old derivation those two cuts would have added roughly 9 kg between
them. The measured portion rose 23.310 → 33.430 (the two newly weighed wheels)
while the nominal fell 165.000 → 155.000, which is the same two wheels leaving
the unweighed pool — the figure moves by the *difference* between measured and
nominal, exactly as the report's reformulated test says.

Also confirmed: `/batches/23` (the Mature batch) reads `~5.00 kg · 0 of 1 wheels
weighed` with no yield line; a milk batch reads `Yield ~100.0% (nominal)`;
**Finding 11** is closed — `POST /orders` carrying only `customer_id`,
`order_date` and `items` now creates the order instead of 500-ing; and the
`/stock` rider is right — holding and releasing **3** wheels in one go moved the
converted segment 1 → **4**, where the old log-count would have shown 2.

One further check not in the report's list: moving 3 wheels from on hand to
converted left the finished weight **unchanged at ~188.43 kg**. Both states are
nominal, so nothing should move, and nothing did.

### The reformulated test line is the right one

The Decision section's original wording — *"a valid cut never raises the batch's
finished weight"* — was written before the measured model existed and is wrong
under it. A cut now moves the figure by (measured wheel − nominal wheel), in
either direction. The pass 2b report's reformulation is correct and this section
supersedes the earlier line.

### Finding 2 — on a short-closed line, that loss was permanent

`OrderItem::releaseUnits()` pass 1 lowers `quantity_allocated` to match
`quantity_fulfilled` but never stamps `fulfilled_at`, so the allocation stayed
"outstanding" forever. Batch of 20, pick 6, close short: 14 remain, nothing is
outstanding, availability reported **8**. Nothing later cleared it, so those 6
units were lost to allocation until someone happened to re-pick that batch.

**Fix for 1 and 2 (one line).** `BatchItem::getHoldableQuantityAttribute()` now
sums the units still *owed*:

```php
$allocated = (int) $this->orderAllocations()
    ->sum(DB::raw('quantity_allocated - quantity_fulfilled'));
```

The `whereNull('fulfilled_at')` filter is gone on purpose — a fully-fulfilled
allocation contributes zero to this sum anyway, and relying on the stamp is what
made finding 2 permanent.

`ChilledRunController::stockByVariant()` had the same wrong sum and was fixed
the same way. Its comment claims it mirrors `available_quantity` "so the footer
can never promise more than Allocate all will actually take" — it did mirror
the buggy one, so the two agreed with each other while both under-reported
against `/stock` and `/batches`. Visible symptom: those screens showed more
free stock than the allocator would hand out.

### Finding 3 — deleting a batch destroyed the allocations under invoiced orders

`batch_items.batch_id` cascades, and so does every child of a batch item
(`order_allocations`, `cheese_cutting_logs`, `cheese_conversion_logs`, on both
their source and target sides). `BatchController::destroy()` had no guard.
Deleting a batch behind a delivered, invoiced order left
`order_items.quantity_fulfilled` at 10 while `batch_codes` on the live order
went empty. The invoice keeps its own snapshotted codes, so the document
survived — the order's traceability did not.

**Fix.** `BatchController::destroy()` now refuses via a `deletionBlocker()`
check covering all four dependency types, naming what is in the way and pointing
at closing the batch (`sold_out` / `expired`) instead. A batch nothing depends
on is still deletable, so a genuine mis-entry can still be undone — both halves
are tested.

### Verification

`StockFlowAuditTest` 22/22 pass. Full suite **7 failing / 428 passing** — the 7
are the known Breeze baseline above, unchanged. `pint` clean on all four
touched files.

### Invariants worth keeping

- **The reservation is `quantity_allocated - quantity_fulfilled`, never
  `quantity_allocated`.** Eight places compute free stock:
  `BatchItem::getHoldableQuantityAttribute()`,
  `ChilledRunController::stockByVariant()`, `StockOverviewService`,
  `BatchController::index()`, `CheeseCuttingController::index()`,
  `CheeseConversionController::index()`, and `BatchItem::freeStockByVariant()`
  serving two surfaces — `ProductVariant::total_stock` (the `/products` figure)
  and `Api\ProductExportController` (`stock_available`, what the portal shows
  customers) — both added by the browser pass above. They must agree, or two
  screens will quote different stock for the same batch. Never gate the sum on
  `fulfilled_at`. (All three index-style sums — `CheeseCuttingController`,
  `BatchController::index()`, `StockOverviewService` — are now ungated.)
- **Per-wheel state counts** (`BatchItem::weight_breakdown`, `StockOverviewService`)
  use `quantity_fulfilled` for dispatched units (never `fulfilled_at`) and
  Σ `wheels_converted` for converted (never the conversion-log count).
- **`BatchItem::available_quantity` fires a `SUM` on every access** — never read
  it in a loop. `ChilledRunController::stockByVariant()` exists precisely to do
  it as one `withSum` query; follow that pattern for any new bulk surface.
- **Anything that deletes rows under a batch item needs a guard, not a
  cascade.** The FKs are all `onDelete('cascade')`; the controller is the only
  thing standing between an operator and a hole in the audit trail.

### Left alone deliberately

`releaseUnits()` still doesn't stamp `fulfilled_at` when pass 1 leaves an
allocation with `quantity_fulfilled == quantity_allocated`, so the column can
read null on an allocation that owes nothing. It is now cosmetic — no
availability path depends on the stamp, and `PickingController::batchOptionsFor()`
already uses the correct `whereColumn('quantity_fulfilled', '<',
'quantity_allocated')` comparison. Fixing it would touch the short-supply
behaviour pinned by the 25 cases in `OrderShortSupplyTest` for no functional
gain; if you do change it, treat that suite as the contract.
