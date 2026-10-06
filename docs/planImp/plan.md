# Order-flow polish: named dialogs, honest stepper, quieter blank line

Status: READY
Revision: 1
Planner: Fable 5.1
Date: 2026-10-06

## Goal

The Planner walked the order-taking flow in Chrome on 2026-10-06 (`/orders/create`
→ Confirm → Auto allocate; run sheet → Enter order → Repeat last order → Save).
The flow works and reads well. Five small things would make it read better,
and one of them generalises: of the **47** `data-confirm` dialogs in the app,
only the cancel-order one has a real title — every other one opens under
"Are you sure?". This cycle gives every dialog a title that names the action,
makes the order progress stepper honest about "allocated, not yet picked",
tidies the run sheet's capacity banner, and makes the create form's trailing
blank line read as optional. Copy and one small template change; no data, no
routes, no arithmetic.

## Context

HEAD `042126f` + the archive of cycle 10 (staged). Suite **695 passed, 0
failed**; any failure is real. `pint --test app tests database routes config`
clean. Nothing pushed or deployed.

**Facts the steps rely on**

- **The dialog**: `resources/views/layouts/partials/confirm-dialog.blade.php`
  (shared by both layouts) + `resources/js/confirm.js`. Per trigger it reads
  `data-confirm` (body), `data-confirm-title` (default "Are you sure?" — `confirm.js:40`),
  `data-confirm-label` (OK button), `data-confirm-cancel-label` (default
  "Cancel", reset on every open), `data-confirm-danger`. The cancel-order
  trigger (`orders/show.blade.php:536–537`) is the model: title, both labels,
  danger.
- **Where the 47 triggers are**: `grep -rn 'data-confirm="' resources/views`.
  Two bodies are themselves "Are you sure?" (`products/index.blade.php:83`,
  `:153`, `products/show.blade.php:157`, `customers/index.blade.php:100`).
  Tags span several lines, so a per-line grep for the title misses it — see
  the test design in step 1.
- **`ConfirmDialogTest`** already scans every view for a native `confirm(`
  (`test_no_view_uses_the_native_confirm_dialog`) and pins the cancel-order
  labels (`test_the_cancel_order_dialog_names_both_choices`). The new scan
  goes beside them.
- **Allocation-items partial** (`orders/partials/allocation-items.blade.php`)
  sets the Fulfil title already via `data-confirm-title="Record pick"` on
  lines 150/199/215 — those three are fine; the partial's Remove / Undo /
  Remove line / Close short (`:224`, `:236`, `:319`, `:331`) are not.
- **Stepper**: `resources/views/orders/partials/status-stepper.blade.php` —
  `$steps` array, `preparing` labelled **Picking**; CSS `.mf-pstep-label`
  (`app.css:215`). `orders/show` renders it with `$order->orderItems` loaded
  (`quantity_ordered`, `quantity_fulfilled` are columns on the lines). After
  Auto allocate the order is `preparing` with nothing picked, so the current
  step reads "Picking" while the banner beneath says "ready to weigh & pack".
- **Create form** (`orders/create.blade.php`): the first row is server-rendered
  (`:76–135`, and the `old('items')` branch above it); `createOrderItem()`
  (`:190`) is the JS template for every further row; `appendEmptyItem()` /
  `ensureTrailingEmptyItem()` (`:176`, `:249`) keep one blank row at the end
  once the last row has a product. The blank row's `<select>` placeholder is
  "Select product" in all three places and it carries a × remove button.
  Empty rows are dropped server-side today (`OrderController::store()` `:295` filters items with no `product_variant_id` before validating; the probe submitted with one).
- **Capacity banner**: `chilled-runs/index.blade.php:80` —
  `<div><b>Capacity.</b> {{ $activeRun->capacity_note }}</div>` renders
  "Capacity. 1600 kg".
- No test pins "Select product", "using FIFO", "Capacity." or the stepper's
  "Picking" label (grepped `tests/Feature`).

## Constraints

- **Copy only reads differently; nothing behaves differently.** No route,
  controller, model, migration or arithmetic change. The invariants list in
  `TESTING_AND_FIXES.md` → *Invariants worth keeping* is untouched by design.
- Every dialog title is a short question or imperative naming the action
  ("Confirm this order?", "Delete this run?", "Email this invoice?") — **never
  "Are you sure?"**, never a sentence. Bodies stay as they are unless a step
  says otherwise. Danger dialogs keep `data-confirm-danger`.
- A dialog whose OK label could be read as the opposite of its cancel label
  gets an explicit `data-confirm-cancel-label` (step 1 lists them). Elsewhere
  the default "Cancel" stays.
- The create form must still submit with the trailing blank row present and
  still drop it server-side (as today).
- `./vendor/bin/pint` on PHP; **`npm run build`** only if CSS changes (step 3
  avoids it — inline style on the one new element, or an existing class).
- **Do not commit.** Planner commits on acceptance. Nothing pushed/deployed.

## Out of scope

- Rewording dialog **bodies** beyond the two steps that name one (the
  auto-allocate FIFO body and the four bare "Are you sure?" bodies).
- The Mature Whole Wheel price (€55.00 per unit while Farmhouse/Cumin wheels
  are €16.00/kg) — test data, a pricing decision for Jon; noted in
  `SMALL_FIXES.md` as judgement, not code.
- The run sheet's `Capacity` *content* (free text on the run) — only the label.
- Any change to the picking phone screens' copy.

## Steps

### 1. Every dialog has a title

Files: every view listed by `grep -rln 'data-confirm="' resources/views`
(47 triggers across ~22 files), `tests/Feature/ConfirmDialogTest.php`

What:
- Add `data-confirm-title="…"` to every `data-confirm` trigger that lacks one.
  Title = the action as a short question, e.g. (not exhaustive — the
  Implementer writes the rest in the same voice):
  - `orders/show` Confirm → **"Confirm this order?"**, body "It goes to the
    picking floor and stock can be allocated to it.", label "Confirm order",
    **`data-confirm-cancel-label="Not yet"`** (a "Cancel" button beside a
    Confirm-order button reads as cancelling the order).
  - `orders/show` Auto allocate → **"Allocate stock?"**, body "Reserves
    available stock for every line, oldest batches first. Nothing is picked
    yet.", label "Allocate" (drops "FIFO" — the banner above already says
    "oldest batches first").
  - `orders/show` Dispatch → "Dispatch this order?", cancel label "Not yet";
    Mark delivered → "Mark as delivered?".
  - `orders/show` + `allocation-items` Remove line → "Remove this line?" /
    "Remove the only line?" (when `$isOnlyLine`); Close short → "Close this
    line short?"; Remove allocation → "Remove this allocation?"; Undo → "Undo
    this pick?".
  - `chilled-runs/index` Confirm all → "Confirm the day's orders?"; Allocate
    all → "Allocate stock for the run?"; Dispatch run → "Dispatch the run?",
    cancel label "Not yet".
  - Deletes/removes everywhere → "Delete this run?", "Remove this stop?",
    "Delete this product?", "Delete this variant?", "Delete this customer?",
    "Delete this batch?", "Remove this payment?", "Remove this special price?",
    "Delete this user?", "Deactivate this user?"; the four bare
    "Are you sure…" **bodies** become one sentence saying what goes
    ("This product and its variants are removed." etc. — check what the
    controller actually refuses/cascades before promising).
  - Finance → "Email this invoice?", "Send a reminder?", "Send all
    reminders?", "Issue these invoices?", "Issue consolidated invoices?",
    "Generate statements?", "Send this statement?", "Email this credit
    note?", "Apply credit?".
  - Stock → "Write off expired stock?", "Reverse this write-off?", "Release to
    mature?", "Return to farmhouse hold?"; picking → "Reopen this line?",
    "Mark this line short?", "Can't finish this line?"; admin → "Restore this
    backup?", "Reset the ceilings?".
- **Test** (`ConfirmDialogTest::test_every_dialog_names_its_action`): walk
  `File::allFiles(resource_path('views'))`, split each file's contents on `<`
  so every chunk is one tag's attributes, and for every chunk containing
  `data-confirm="` assert it also contains `data-confirm-title="` and that the
  title value is not "Are you sure?". Report offenders as `file:line` like
  the native-confirm scan does. Also assert no `data-confirm="Are you sure`
  body remains.
- Extend `test_the_cancel_order_dialog_names_both_choices` with a sibling for
  the confirm-order trigger (title, "Not yet", "Confirm order").

Check: `php artisan test --filter=ConfirmDialogTest` → pass (the new scan
fails until every trigger is done — run it first to get the list). Browser:
Confirm order on a pending order → dialog titled "Confirm this order?" with
"Not yet" / "Confirm order"; Auto allocate → "Allocate stock?" with no "FIFO".

### 2. The stepper says what has been picked

Files: `resources/views/orders/partials/status-stepper.blade.php`,
`tests/Feature/OrderTransitionsTest.php` (or a new `OrderStepperTest` if that
file's fixtures don't fit)

What: keep the six steps and the label **Picking**, but when the order is
`preparing` render a second line under that step's label:
`{{ picked }} of {{ ordered }} picked` where `picked = orderItems->sum('quantity_fulfilled')`
and `ordered = orderItems->sum('quantity_ordered')` (loaded collection — no
query). "0 of 26 picked" is the honest reading after Auto allocate; "12 of 26
picked" mid-pick. Style it with the existing muted token inline
(`style="font-size: 11px; color: var(--muted);"`) or an existing utility
class so no CSS build is needed; if a new class is unavoidable, add
`.mf-pstep-sub` beside `.mf-pstep-label` and run `npm run build`.
Test: a `preparing` order with one line 10 ordered / 4 fulfilled renders
"4 of 10 picked"; a `confirmed` order renders no "picked" line.

Check: `php artisan test --filter="OrderTransitionsTest|OrderStepperTest"` → pass.
Browser: `/orders/{preparing order}` shows the count under Picking.

### 3. Capacity banner

Files: `resources/views/chilled-runs/index.blade.php`

What: `<b>Capacity.</b> {{ … }}` → `<b>Capacity:</b> {{ … }}` ("Capacity:
1600 kg"). No test (copy); `ChilledRunTest` still passes.

Check: `/chilled-runs` renders "Capacity: 1600 kg".

### 4. The trailing blank line reads as optional

Files: `resources/views/orders/create.blade.php`

What:
- The JS template (`createOrderItem`) placeholder option reads
  **"Add another item…"**; the server-rendered first row (both branches)
  keeps "Select product" (the order has no items yet — "another" would be
  wrong). `relabelOptions()` / `ensureTrailingEmptyItem()` unchanged.
- The blank trailing row hides its × (a remove button on an empty optional
  row is noise): in `createOrderItem()` and `ensureTrailingEmptyItem()`, give
  the row a dashed border while its product is empty
  (`div.style.borderStyle = 'dashed'`; back to solid on `change` when a
  product is chosen) and `remove-item` `hidden` while empty, shown once a
  product is picked. Inline style / the existing `hidden` utility — no build.
- Submitting with the blank row present still works (server already drops
  rows with no product); `OrderStoreOptionalFieldsTest`/`OrderCustomerReferenceTest` pass
  unchanged.

Check: browser `/orders/create` → pick a product on row 1 → row 2 appears
dashed, "Add another item…", no ×; pick a product on row 2 → solid, × shows,
row 3 appears dashed. Create with row 3 blank → saves two lines.

### 5. Docs and the small-fixes list

Files: `CLAUDE.md`, `docs/planImp/SMALL_FIXES.md`, `docs/planImp/WORK_IN_PROGRESS.md`

What: `CLAUDE.md` → *Feedback, fallbacks, dialogs and PDFs* bullet on
`confirm.js`: "**Every `data-confirm` trigger carries `data-confirm-title`**
naming the action — `ConfirmDialogTest::test_every_dialog_names_its_action`
scans the views; the JS default 'Are you sure?' is a fallback, never a
design." `SMALL_FIXES.md`: add the Mature Whole Wheel price as a **judgement**
item (data, not code) under the pricing section. `WORK_IN_PROGRESS.md`:
*Recently Completed: Order-flow polish (2026-10-06)* — one line.

Check: `grep -n "test_every_dialog_names_its_action" CLAUDE.md` → hit.

## Verification

1. `php artisan test` → **0 failed**; record the count (695 + the new tests).
2. `./vendor/bin/pint --test app tests database routes config` → clean.
3. `grep -rn 'data-confirm="' resources/views | wc -l` equals
   `grep -rn 'data-confirm-title="' resources/views | wc -l` (both 47, or
   both the new count if a trigger was added) — a quick double-check beside
   the test.
4. `git status --short` → only views, the two tests, the three docs, and the
   staged archive. No `public/build` change unless step 2 needed a class.
5. Browser as `office_test` (console clean): the four checks in steps 1–4,
   on a fresh probe order tagged `E2E-20261006 polish` — **cancel it
   afterwards** and note the id in `implemented.md`.

## Risks

- **Scanning multi-line tags.** Splitting on `<` is enough because no
  attribute value in these views contains a literal `<`; if one does, the
  chunk still contains both attributes and the test passes — the only false
  negative would be a `data-confirm` whose title sits on another tag, which
  isn't a pattern here.
- **Blade inside the JS template** (`createOrderItem` is a template literal
  containing `@foreach`) — change only the placeholder `<option>` text and
  the button, not the loop.
- **"Not yet" on Dispatch all / Confirm all** sits beside a count ("Confirm 5
  pending orders…"); keep the body as is so the number stays visible.
- **The stepper partial is included on `orders/show` only** (grep before
  assuming); the sum reads the loaded `orderItems`, which `show()` eager-loads.

## Review

(Planner fills in after `implemented.md` is DONE.)
