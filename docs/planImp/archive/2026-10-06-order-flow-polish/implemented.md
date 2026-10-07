# Order-flow polish: named dialogs, honest stepper, quieter blank line — implementation

Status: DONE
Plan revision: 2
Implementer: Opus
Date: 2026-10-06

## Baseline
HEAD: 01b9e47 (the plan's Context says `042126f` + a staged archive; that archive has since been committed as `01b9e47`)
Pre-existing dirty files: clean
```
$ php artisan test
  Tests:    695 passed (4439 assertions)
$ ./vendor/bin/pint --test app tests database routes config
(clean — no output)
$ grep -rn 'data-confirm="' resources/views | wc -l        → 47
$ grep -rn 'data-confirm-title="' resources/views | wc -l  → 6
```

## Steps

### 1. Every dialog has a title — done
Test first: `ConfirmDialogTest::test_every_dialog_names_its_action`.
- It splits each view on `<` so every chunk is one tag (multi-line tags
  included) and reports `file:line` for any `data-confirm=` chunk with no
  `data-confirm-title`, an empty one, or one containing "Are you sure".
- A second assertion lists any body starting "Are you sure".
- Its first run listed **41** untitled triggers (47 − the 6 already titled).

Changed — 41 titles across 22 views, applied by one script keyed on each
reported `file:line`, which checked that every key was applied:
- **orders/show**:
  - Confirm → "Confirm this order?", body "It goes to the picking floor and
    stock can be allocated to it.", label "Confirm order", **cancel "Not
    yet"**;
  - Dispatch → "Dispatch this order?", **cancel "Not yet"**;
  - Mark delivered → "Mark as delivered?";
  - Auto allocate → "Allocate stock?", body "Reserves available stock for
    every line, oldest batches first. Nothing is picked yet.", label
    "Allocate" (no "FIFO");
  - Remove line → `{{ $isOnlyLine ? 'Remove the only line?' : 'Remove this
    line?' }}`.
- **orders/partials/allocation-items**: Remove allocation "Remove this
  allocation?", Undo "Undo this pick?", Remove line (the same dynamic title),
  Close short "Close this line short?".
- **chilled-runs/index**: "Confirm the day's orders?", "Allocate stock for
  the run?", "Dispatch the run?" (**cancel "Not yet"**). The bodies with
  counts are unchanged.
- **delivery-runs**: "Delete this run?" (body trimmed to "Its customers will
  be unassigned (not deleted)." — the old body repeated the title's
  question), "Remove this stop?".
- **products/index + products/show**: "Delete this product?", "Delete this
  variant?" ×2. **customers/index**: "Delete this customer?".
  **customers/show**: "Apply credit?", "Remove this special price?".
- **users**: "Deactivate this user?", "Delete this user?".
- **invoicing**: "Remove this payment?", "Issue consolidated invoices?",
  "Send all reminders?", "Generate statements?", "Send this statement?",
  "Email this invoice?", "Send a reminder?", "Issue these invoices?", "Email
  this credit note?".
- **backup** "Restore this backup?"; **settings** "Reset the ceilings?".
- **stock**: cheese-conversion "Release to mature?", batches/show "Return to
  farmhouse hold?", write-offs "Reverse this write-off?".
- **picking/item**: "Reopen this line?", "Undo this pick?", "Mark this line
  short?", "Undo the last pick?", "Can't finish this line?".

**Bodies rewritten** (the "Are you sure" ones), after checking what the
controllers and FKs actually do:
- products/index product delete → "This deletes the product, its variants,
  and every batch and order line that uses them. It can't be undone."
  `ProductController::destroy()` refuses nothing; FKs cascade products →
  variants/batches → batch_items → allocations, cut/conversion logs,
  write-offs, and variants → order_items, special prices. **See Notes for
  Planner.**
- the two variant deletes → "This deletes the variant, its stock in every
  batch, and every order line for it. It can't be undone."
- customer delete → "This deletes the customer and their special prices. A
  customer with orders can't be deleted — set them inactive instead."
  (`CustomerController::destroy()` refuses with orders.)
- **cancel order** (`orders/show:537`) — a fifth "Are you sure…" body the
  plan didn't list; the new assertion caught it → "Reserved and picked stock
  goes back to its batch; the order stays on record as cancelled." This
  matches the controller's own flash. Its title and labels, already pinned,
  are unchanged.

`ConfirmDialogTest` +1 sibling: `test_the_confirm_order_dialog_names_both_choices`
(title, the new body, "Not yet", "Confirm order"). No existing test pinned
any replaced text (grepped).
Check output:
```
$ php artisan test --filter=ConfirmDialogTest
  Tests:    6 passed (19 assertions)
$ grep -rn 'data-confirm="' resources/views | wc -l        → 47
$ grep -rn 'data-confirm-title="' resources/views | wc -l  → 47
$ php artisan test   (after step 1)
  Tests:    697 passed (4446 assertions)
```
Browser: see *Browser checks*.

### 2. The stepper says what has been picked — done
Changed:
- `resources/views/orders/partials/status-stepper.blade.php`: the six steps
  and the "Picking" label are unchanged.
  - Under that step, **only while the order is `preparing`**, a second line
    `data-picked-count` reads "{Σ quantity_fulfilled} of {Σ quantity_ordered}
    picked", summed over the loaded `orderItems` (no query).
  - Styled inline (`font-size: 11px; color: var(--muted)`), so no new class
    and no build.
  - The partial is included only by `orders/show` (grepped), which
    eager-loads the lines.
- `tests/Feature/OrderTransitionsTest.php` +2 (its `makeOrder()` fixture
  fits, plus a small `lineOn()` helper):
  - a `preparing` order with lines 10/4 and 6/0 renders exactly **"4 of 16
    picked"** — two lines, to prove it sums; the plan's single 10/4 line is
    the same property;
  - `confirmed` and `ready` orders render no count.
Red check (HEAD's partial): the preparing case fails; the "no count" case
passes on HEAD, as expected.
Check output:
```
$ php artisan test --filter=OrderTransitionsTest
  Tests:    26 passed (110 assertions)
```

### 3. Capacity banner — done
- `resources/views/chilled-runs/index.blade.php:80`: `<b>Capacity.</b>` →
  `<b>Capacity:</b>`. Copy only; no test, as the plan says. ChilledRunTest
  is in the full run.

### 4. The trailing blank line reads as optional — done
Changed `resources/views/orders/create.blade.php` (JS and the template only):
- `createOrderItem()`'s placeholder `<option>` → **"Add another item…"**.
  Both server-rendered branches (`old('items')` and the fresh first row)
  keep "Select product". The `@foreach` inside the template literal is
  untouched.
- New `refreshBlankRow()`:
  - for every row **after the first**, the trailing row with no product is
    **dashed** (`style.borderStyle`) with its **× hidden**
    (`style.display = 'none'`);
  - any other row is solid with the × shown;
  - the first row is never touched ("another" would be wrong there).
- It's called at the end of `appendEmptyItem()`, in both change handlers
  (template rows and server rows), after both remove handlers, and once on
  DOMContentLoaded. `ensureTrailingEmptyItem()` and `relabelOptions()` are
  unchanged.
- The × uses inline `display`, not the `hidden` utility: the button also
  carries `grid`, and two display utilities on one element depend on CSS
  order. No build.
- Server side unchanged: empty rows are still filtered in
  `OrderController::store()`.
Check output:
```
$ php artisan test --filter="OrderStoreOptionalFieldsTest|OrderCustomerReferenceTest|OrderItemEditTest|DeliveryChargeTest"
  Tests:    52 passed (213 assertions)
```
Browser: see *Browser checks*.

### 5. Docs and the small-fixes list — done
Changed:
- `CLAUDE.md` → the `confirm.js` bullet under *Feedback, fallbacks, dialogs
  and PDFs* states the rule:
  - every trigger carries `data-confirm-title`;
  - the scan test and its "Are you sure" body check;
  - the JS default is a fallback;
  - when to give a cancel label ("Not yet").
- `docs/planImp/SMALL_FIXES.md` → the **Mature Whole Wheel price is already
  listed** under *Judgement needed — data and pricing* (since 2026-09-13).
  I added the 2026-10-06 re-sighting to that item rather than duplicate it.
- `docs/planImp/WORK_IN_PROGRESS.md` → *Recently Completed: Order-flow
  polish (2026-10-06)*.
Check:
```
$ grep -n "test_every_dialog_names_its_action" CLAUDE.md
157:- **`confirm.js`** also reads `data-confirm-cancel-label … ConfirmDialogTest::test_every_dialog_names_its_action …
```

## Browser checks
As **`office_test`**. The `mossfield.local` session had signed out, and I
don't type passwords on that host. I ran `php artisan serve` on
`127.0.0.1:8010` (same `.env` and dev DB), where the browser was already
signed in as Office Tester. The server is stopped again (curl → 000).
Console clean (no errors).

**Step 4 — `/orders/create`**, customer E2E Corner Shop, row by row:
- initially: row 0 solid, × shown, "Select product";
- 1L chosen on row 0 → row 1 appears **dashed, × hidden, "Add another
  item…"**;
- 2L chosen on row 1 → row 1 solid with × shown; row 2 appears dashed, ×
  hidden, "Add another item…".
- Screenshot looked at: the dashed trailing row reads as optional, and the
  two filled rows keep their ×.
- Notes "E2E-20261006 polish" → **Create order** with row 2 blank →
  **ORD-20261006-003 (id 29)** with **2 lines** (the blank row was dropped
  server-side). Flash: "Order ORD-20261006-003 created successfully! Delivery
  charge already on ORD-20261004-001 for that day."

**Step 1 — dialogs:**
- **Confirm order** → "**Confirm this order?** It goes to the picking floor
  and stock can be allocated to it." with buttons **Not yet** / **Confirm
  order**. Confirmed → "Order confirmed."
- **Auto allocate** → "**Allocate stock?** Reserves available stock for
  every line, oldest batches first. Nothing is picked yet." with **Cancel** /
  **Allocate**; no "FIFO" in the dialog. Allocated → "Auto-allocated 4 units
  across 2 lines."
- **Cancel order** (cleanup) → "**Cancel this order?** Reserved and picked
  stock goes back to its batch; the order stays on record as cancelled."
  with **Keep order** / **Cancel order**.

**Step 2 — stepper:**
- While **confirmed**, the current step is "Confirmed" with no count.
- After Auto allocate (now `preparing`), the current step reads
  **"Picking"** with **"0 of 4 picked"** beneath it in small muted text
  (zoomed screenshot checked).

**Step 3 — capacity:**
- `/chilled-runs?run=1&date=2026-10-06` → "**Capacity: 1600 kg**".

**Browser automation note:** a dialog trigger clicked by element reference
didn't open the dialog, and the first coordinate click on a page often only
focused the window. A second click at the button's centre opened it every
time. That's the tool, not the app: the dialog code is unchanged apart from
the attributes.

## Dev-DB state after the probe
- **ORD-20261006-003 (id 29)**, E2E Corner Shop, notes "E2E-20261006
  polish": **cancelled** from its own Cancel dialog. It keeps its 2 lines as
  history; `quantity_allocated` 0 and 0 allocation rows — the 4 reserved
  units are back.
- Nothing else written by me. ORD-20261006-001 and -002 (both cancelled)
  predate this session's work, presumably the Planner's walk.

## Deviations
1. **A fifth "Are you sure…" body: cancel-order** (`orders/show:537`), not
   in the plan's list of four. The new body assertion caught it. Reworded to
   "Reserved and picked stock goes back to its batch; the order stays on
   record as cancelled." (matching the controller's flash). The title and
   labels the existing test pins are unchanged.
2. **"Delete this run?" body trimmed** to "Its customers will be unassigned
   (not deleted)." Its old body began with the same question the title now
   asks, and the dialog would have said it twice.
3. **Step 2's test uses two lines** (10/4 + 6/0 → "4 of 16 picked") rather
   than one 10/4 line, so it proves the sum, not just a single line.
4. **Step 5 — the Mature price item already existed** in `SMALL_FIXES.md`
   (2026-09-13). I added the 2026-10-06 sighting to it instead of adding a
   duplicate.
5. **Browser as office on `127.0.0.1:8010`** rather than `mossfield.local`.
   Same code and DB; the `mossfield.local` session had expired and I can't
   enter a password on that host.

## Verification
1. `php artisan test` → **699 passed (4454 assertions), 0 failed** (73.0 s).
   Baseline 695 → +4:
   - `ConfirmDialogTest` +2 (the scan and the confirm-order sibling);
   - `OrderTransitionsTest` +2 (picked count shown / not shown).
2. `./vendor/bin/pint --test app tests database routes config` → clean.
3. `grep -rn 'data-confirm="' resources/views | wc -l` → **47**;
   `grep -rn 'data-confirm-title="' resources/views | wc -l` → **47**.
4. `git status --short` → 25 views, the two tests, `CLAUDE.md`,
   `SMALL_FIXES.md`, `WORK_IN_PROGRESS.md`, and `implemented.md`. No
   `public/build` change — no CSS was touched. The staged archive is already
   committed as 01b9e47.
5. Browser: the step 1–4 checks above, as office_test; the console was
   clean; the probe order was cancelled (id 29).

**Nothing committed, pushed or deployed.**

## Files changed
Views (25):
- `orders/{show,create}`, `orders/partials/{allocation-items,status-stepper}`
- `chilled-runs/index`, `delivery-runs/index`
- `products/{index,show}`, `customers/{index,show}`, `users/index`
- `invoicing/{consolidated/create,credit-notes/show,invoices/show,overdue/index,payments/show,statements/batch-create,statements/preview,uninvoiced/index}`
- `backup/index`, `settings/index`
- `cheese-conversion/index`, `batches/show`, `batches/partials/_write-offs`
- `picking/item`

Tests:
- `tests/Feature/ConfirmDialogTest.php`, `tests/Feature/OrderTransitionsTest.php`

Docs:
- `CLAUDE.md`, `docs/planImp/SMALL_FIXES.md`,
  `docs/planImp/WORK_IN_PROGRESS.md`, this file.

## Notes for Planner
- **Deleting a product or a variant destroys history** — worth a cycle of
  its own. `ProductController::destroy()` / `ProductVariantController::destroy()`
  refuse nothing, and the schema cascades:
  - products → product_variants, batches;
  - batches → batch_items → order_allocations, cheese_cutting_logs,
    cheese_conversion_logs, stock_write_offs;
  - product_variants → **order_items** and customer_special_prices.
  So deleting a product with sales removes **lines from past orders**
  (orders don't recalc, so their stored totals stop matching their lines)
  along with all batch traceability. Invoices survive as snapshots
  (`invoice_lines.order_item_id` SET NULL). The new dialog bodies now say
  plainly what goes, but the real fix is a refusal like
  `Batch::deletionBlocker()` (deactivate instead). I didn't change the
  controllers — the plan forbids it.
- **The allocation banner still says "using FIFO (oldest batches first)"**
  (`orders/show`, the "Assign stock to every line" banner above Auto
  allocate). The plan dropped "FIFO" from the dialog on the premise that the
  banner already says "oldest batches first". It does, alongside the jargon.
  A one-word copy fix if wanted.
- **`Customer` delete**: `payments`/`invoices`/`credit_notes`/`statements` are
  `RESTRICT` on `customer_id`. A customer with no orders but a payment or
  statement would therefore error at the DB rather than get the friendly
  refusal. Unlikely in practice; noted only because the dialog body now
  promises "A customer with orders can't be deleted".

---

## Revision 2

### 6. A body never repeats its title — done
Test first: in `test_every_dialog_names_its_action`, after the title check,
an **echo** check. For each trigger whose title and body aren't Blade
expressions, the trimmed, lower-cased body must not start with the title or
the title minus its `?`.

The first run listed **12**: the Planner's eleven, less the two `$isOnlyLine`
bodies (Blade expressions — done by hand, as the plan says), **plus two the
list didn't name**:
- `batch-card` "Delete batch G…? This can't be undone." (title "Delete
  batch?");
- `consolidated/create` "Issue consolidated invoices for the selected
  customers?".

Changed — each body is now the consequence only, keeping every number and
name it carried:

| View | Title | New body |
|---|---|---|
| `orders/partials/allocation-items` Remove allocation | Remove this allocation? | The reserved units go back to free stock. |
| `allocation-items` Undo (fulfilment) | Undo this pick? | Stock goes back to the batch. *(was "Undo fulfillment? …" — a reworded echo the prefix check can't see; fixed by hand)* |
| `allocation-items` Close short | Close this line short? | {{ quantity_remaining }} units will be released back to stock, and won't be supplied or billed. |
| `orders/show` + `allocation-items` Remove line | Remove the only line? / Remove this line? | This is the only item — the order is cancelled and any picked stock returns to its batch. / Any picked stock returns to its batch. |
| `customers/show` special price | Remove this special price? | The customer pays the base price on new orders; existing lines keep theirs. |
| `invoicing/payments/show` | Remove this payment? | The invoices it settled go back to outstanding. |
| `invoicing/consolidated/create` | Issue consolidated invoices? | Each selected customer gets one invoice covering their uninvoiced deliveries in the period. |
| `batches/partials/batch-card` | Delete batch? | Batch {{ code }} and its items are removed. This can't be undone. |
| `batches/partials/_write-offs` | Reverse this write-off? | {{ n }} unit(s) go(es) back into free stock. |
| `picking/item` Reopen | Reopen this line? | It needs picking again and the order goes back to Preparing. |
| `picking/item` Undo ×2 | Undo this pick? / Undo the last pick? | Stock goes back to the batch. |
| `picking/item` Mark short / Can't finish | Mark this line short? / Can't finish this line? | The {{ n }} unpicked unit(s) will be released back to stock and won't be supplied or billed. |

- **Banner** (`orders/show.blade.php:357`): "…to every line using FIFO
  (oldest batches first)." → "Auto allocate pulls available stock to every
  line, oldest batches first."
- **Kept as they are**: bodies that are questions but add what the title
  doesn't ("Email invoice INV-… to …?", "Remove Shop X from this run?",
  "Dispatch 5 loaded orders? …"). The plan asks to keep every number and
  name, and these aren't echoes.
- `FIFO` still appears on the picking phone screens
  (`picking/item.blade.php:52, :292`), which this plan keeps out of scope.

Two existing tests pinned the old body copy (my first grep, for different
strings, missed them). Only those assertions changed:
- `BatchCreateTest` (the batch card's delete body) now expects "Batch {code}
  and its items are removed. This can't be undone.";
- `StockWriteOffTest` (the reverse dialog) now asserts the title attribute
  and "3 units go back into free stock.".

Check output:
```
$ php artisan test --filter=ConfirmDialogTest
  Tests:    6 passed (20 assertions)
$ php artisan test --filter="BatchCreateTest|StockWriteOffTest|ConfirmDialogTest"
  Tests:    48 passed (259 assertions)
$ grep -rn 'data-confirm="' resources/views | wc -l        → 47
$ grep -rn 'data-confirm-title="' resources/views | wc -l  → 47
```

### Browser check (step 6)
As `office_test`, on `127.0.0.1:8010` (the same set-up as revision 1;
stopped afterwards, curl → 000).
- `/orders/14` (ORD-20261004-003, **preparing**, one unfulfilled
  allocation) → Remove → the dialog read: "**Remove this allocation?** The
  reserved units go back to free stock." with **Cancel** / **Remove** — the
  question once, then the consequence.
- I dismissed it (and closed the tab) without confirming. **No
  POST/DELETE/PATCH** reached the server (its log). Order 14 still has its
  allocation row (12 allocated), `updated_at` unchanged at 2026-10-04
  13:57:24.
- Console clean.
- The FIFO banner only shows on an order with unallocated lines; order 14
  is fully allocated, so the new wording is checked by grep (`orders/show`
  has no "FIFO") rather than on screen.

### Revision 2 — Deviations
1. **Two echoes beyond the plan's list** (batch delete, consolidated issue)
   — the new check found them, so they're fixed.
2. **The `allocation-items` Undo body** ("Undo fulfillment? …") was a
   reworded echo that the prefix check can't catch. Fixed by hand to match
   its sibling on the picking screen.
3. **Two copy assertions updated** (`BatchCreateTest`,
   `StockWriteOffTest`), not named in step 6's files, because they pinned
   the replaced bodies.

### Revision 2 — Verification
1. `php artisan test` → **699 passed (4456 assertions), 0 failed** (67.4 s).
   No new test methods — the echo check lives inside the existing scan.
2. `./vendor/bin/pint --test app tests database routes config` → clean.
3. `data-confirm=` **47** / `data-confirm-title=` **47**.
4. `git status --short` adds `batches/partials/batch-card.blade.php`,
   `tests/Feature/BatchCreateTest.php` and
   `tests/Feature/StockWriteOffTest.php` to revision 1's list. No
   `public/build` change.
5. Dev DB: **no writes** in revision 2. The probe from revision 1 (id 29) is
   still cancelled.

**Nothing committed, pushed or deployed.**
