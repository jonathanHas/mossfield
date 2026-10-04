# End-to-end browser run: batches → orders → picking → dispatch → invoicing

Status: READY
Revision: 1
Planner: Fable 5.1
Date: 2026-10-04

## Goal

A full working day for the farm, driven through Chrome with real mouse and
keyboard as the office, factory and admin would do it: make the day's batches,
take orders (desktop form **and** the chilled-run sheet), send them to the
floor, pick them on a phone-width screen, load and dispatch the run, and bill —
invoices, a payment, a credit note, a statement. The output is **not code**:
it is a numbered list of bugs and friction, each reproducible, severity-rated,
and checked against the database so "it looked fine" is never the evidence.
The Planner triages the list into the next plan(s).

## Context

### Roles for this cycle

This is a QA cycle. The Implementer **changes no application code, no tests,
no migrations and no docs other than `implemented.md`**. A bug found is
written up, not fixed — even a one-liner. The one exception is `## Step 0`'s
environment preparation (build assets, start a queue worker), which touches
nothing tracked by git.

### Environment (verified 2026-10-04)

- HEAD `fd879b9`; working tree has only the staged archive of cycle 1.
- Site: `http://mossfield.local` (Apache vhost, returns 200). `php artisan
  serve --host=127.0.0.1 --port=8000` also works if the vhost misbehaves.
  `public/build` is current (`npm run build` ran in cycle 1); there is no
  `public/hot`, so don't start `npm run dev` — it would switch the page to
  the Vite dev server.
- `.env`: `MAIL_MAILER=log` (emails land in `storage/logs/laravel.log` — grep
  for `To:` / the subject), `QUEUE_CONNECTION=database` (the **run-level
  Dispatch run queues** its dispatch confirmations; nothing sends them unless
  a worker runs — see step 0), `SESSION_DRIVER=database`.
- Logins (`CLAUDE.md` → *Dev / Staging Seeded Users*): `admin`, `office_test`,
  `factory_test`, `driver_test`, all password `admin123`. Factory lands on
  `/picking` after login.
- Dev DB `mossfield_cl` — **do not run `mossfield:reset-trading-data`**; the
  browser-testing session relies on batch G030426's state. Current data:
  7 products / 16 variants (milk: 1L, 2L, 10L Churn · yoghurt: 250g, 500g,
  16L Bucket · five cheeses each with Whole Wheel + Vacuum Pack; cheese
  `maturation_days` 60, Mature 150; only milk has `shelf_life_days` = 10).
  16 customers, all `per_order`, all on delivery run **#1 "Dublin Tue"**
  (`day_of_week` 2, charge €0, 15 stops). 8 batches: **every milk batch is
  expired** (latest M070926 expired 2026-09-17), Y230726 is the only
  sellable yoghurt, G030426 has 26 free Farmhouse wheels (and 4 cut, 4
  converted → G030426-1 Mature, 4 sold). 10 orders (3 ready, 3 dispatched,
  4 cancelled), 3 invoices (`INV-000001..3`), no payments/credit notes/
  statements. Last order number `ORD-20260913-003`.
- Today is **Sunday 2026-10-04**. The run sheet resolves "Dublin Tue" inside
  the ISO week of the anchor date, so the default view is **last Tuesday
  (2026-09-29)**. Use the week-next arrow or `?date=2026-10-06` to work on
  **Tuesday 2026-10-06**. The picking queue has no date filter (all
  confirmed/preparing/ready orders, delivery date ascending).

### Tagging everything you create

Every record created in this run must be findable afterwards: customer names
start with **`E2E `**, every batch/order/customer `notes` field contains
**`E2E-20261004`**, and the customer email domain is `@e2e.test`. Write the
ids into `implemented.md` as you go (a table at the end: type, id, code).
The Planner decides later whether to clean them up or keep them.

### The Chrome extension — lessons from cycle 1

- Load the tools in **one** `ToolSearch` call; call `tabs_context_mcp` first;
  open a **new tab** for the run.
- Before trusting any click, check `document.visibilityState === "visible"`
  and that a capture-phase `pointerdown` logger sees `isTrusted: true`. If
  the tab is hidden or input doesn't arrive, stop and reset Chrome rather
  than switching to DOM-dispatched events — **this cycle is about what a
  human experiences, and synthetic events hide exactly the friction we want
  to find** (focus, scroll, hover, keyboard, transitions).
- Read the console after every page and every action (`read_console_messages`,
  then filter). Every console error or failed request is a finding.
- Phone-width phases use `resize_window` to **390 × 844**; desktop phases
  1280+ wide. Record which width a finding was seen at.
- Screenshots: take one per finding and describe it in the finding — the
  files can't be saved to the repo, so the description is the record. A GIF
  (`gif_creator`) of the picking flow is welcome but optional.
- Never trigger a native dialog. The app no longer has `confirm()` (cycle 1)
  but the chilled-run **unsaved-changes** guard still uses `beforeunload`
  for back/refresh — don't navigate with unsaved quantities except where a
  step says so (and then via an in-app link, which opens the styled dialog).

### What already has an owner — do not re-report

`SMALL_FIXES.md` → *Judgement needed* (Avoa/Avoca, €72/kg bucket, Garlic &
Basil and Mature wheel prices) and *Forms and ergonomics* (one weight box per
wheel on the **desktop** allocation panel); `OPEN_QUESTIONS.md` #1–4 (no
write-off route, cuts/releases irreversible, reconciliation not scheduled);
`FULFILMENT_PLAN.md` Phase 4 (no driver screens — driver sees a placeholder);
Online Orders (needs the mossorders host); Backup & Restore (restore is
destructive). If you hit one of these, one line "known — see X" is enough.

### Rules the app claims to enforce (probe these deliberately)

From `CLAUDE.md`; the tester's job is to find where the screen disagrees
with the rule.

- Batch codes `Mddmmyy` / `Yddmmyy` / `Gddmmyy`, same-day collision gets
  `-N`. Milk `expiry_date` auto-fills `production_date + 10`. Expiry must be
  after production.
- A batch expiring **today** is out of `/stock`, the allocator and the picker
  (cycle 1).
- Cutting: wheel weight required; packs ≤ wheel weight; packs × nominal ≤
  wheel; `cut_date` within [production, today]; can't cut a held or reserved
  wheel; the form is replaced by a refusal when no free wheel.
- Mature hold at any age; **release** only once eligible (~5 months →
  `maturation_days` of the Mature product, 150 days); release bounded by the
  saved hold; undo from the Mature batch page while the wheels are uncut.
- Orders: `unit_price` snapshots the customer's rate (special price else
  base); line total weight-aware for €/kg; delivery charge gross with 23 %
  VAT backed out (`net + VAT = gross` exactly); `customer_reference` reveal
  auto-opens for `requires_reference` customers.
- Status: pending → confirmed (Confirm) → preparing (first pick/allocation)
  → ready (every line settled) → dispatched → delivered. Adding unpicked work
  to a `ready` order reverts it to `preparing`. Cancelling returns reserved
  **and** picked stock. Removing the only line cancels the order.
- Short supply: "Close short"/"Mark short" settles a line; the order can
  reach `ready`; the invoice bills only what was picked (€0.00 for a
  never-picked short line); reopening puts it back.
- Picking: factory sees no €; per-piece weights for wheels, one total for
  vacuum packs; undo restores stock; the celebration screen is green when
  clean, amber "closed — short" otherwise.
- Dispatch (per order or per run): status flips under a row lock; a
  **per-order** customer gets an invoice issued immediately; a **weekly/
  monthly** customer does not (waits for the consolidated run); a Dispatch
  Confirmation is emailed (queued on the run path, sent inline per order).
  The invoice is **not** emailed at dispatch.
- Invoice = snapshot. Editing an invoiced order's lines is refused and points
  at Void. Void returns the order to *Awaiting invoice* and keeps the history.
  Numbering `INV-000NNN` claimed inside the transaction (a refused issue
  doesn't burn a number).
- Payments belong to the customer; unallocated money is credit on account;
  `payment_state` derived (unpaid/partial/paid/overdue/void).
- Credit notes reduce the invoice directly; over-payment is released back to
  credit.
- `Customer::outstanding_balance` = open invoice balances − unallocated credit.

## Constraints

- **No code, test, migration or documentation changes.** `git status` at the
  end must show only `docs/planImp/implemented.md` (plus the already-staged
  archive renames).
- No `mossfield:reset-trading-data`, no `migrate:fresh`, no seeding beyond
  what step 0 says, no edits to existing customers' money fields, no touching
  batch G030426 or G030426-1 except reading them.
- Real input only (see the Chrome notes). If real input is impossible after a
  Chrome reset, set `BLOCKED` with what you saw.
- Do not commit, push or deploy.
- Report every finding in the format under *Findings format*, numbered
  `F-01…`, in the phase where it was found. Severity scale:
  **S1** data wrong / stock or money incorrect / action impossible;
  **S2** wrong behaviour with a workaround, or a 500/console error;
  **S3** friction — confusing, slow, surprising, needs a second look;
  **S4** polish — copy, alignment, wording.
- Also keep a **Confirmed correct** list per phase: things you deliberately
  tried that behaved as the rules say. It's as valuable as the bug list.

## Out of scope

- Fixing anything. Changing wording. Adding a test.
- Online Orders, Backup & Restore, Users (the authorisation pass covered
  `/users`; a deactivate/reactivate round trip was done in cycle 1).
- Performance measurement beyond "this page felt slow" (note it with the
  approximate wait).
- The browser-testing session's own checklist in `handoff/REPORT.md` — if you
  pass through one of its items, record the result in `implemented.md`
  under that phase (the Planner will fold it into `REPORT.md`); do not edit
  `REPORT.md`.

## Steps

Each step ends with a **DB probe** — a read-only `php artisan tinker
--execute='…'` (or a `SELECT` through `DB::table()`), pasted with its output.
Example shapes are given; adjust ids to what you created. Update
`implemented.md` after every step. If you run out of session before the end,
set `Status: BLOCKED` with "stopped after step N — resume from N+1" so a
second Implementer session can continue from the file.

### 0. Prepare and baseline

What:
- `cd /var/www/html/mossfield && git status --short && git rev-parse --short HEAD`
  → record. `ls public/hot` must say no such file.
- Start a queue worker in a background shell for the whole run:
  `php artisan queue:work --tries=1 --stop-when-empty` is **not** enough (it
  exits); use `php artisan queue:listen --tries=1` in the background and note
  its PID so you can stop it at the end. The run-level Dispatch run won't
  email anything otherwise.
- `tail -f storage/logs/laravel.log` in another background shell (or grep it
  later) — this is where "emails" go.
- Record the DB baseline:
  ```
  php artisan tinker --execute='echo json_encode(["orders"=>App\Models\Order::count(),"batches"=>App\Models\Batch::count(),"customers"=>App\Models\Customer::count(),"invoices"=>App\Models\Invoice::count(),"payments"=>App\Models\Payment::count(),"credit_notes"=>App\Models\CreditNote::count(),"statements"=>App\Models\Statement::count(),"document_emails"=>App\Models\DocumentEmail::count(),"jobs"=>DB::table("jobs")->count(),"failed_jobs"=>DB::table("failed_jobs")->count(),"invoice_seq"=>App\Models\DocumentSequence::where("key","invoice")->value("next_value")]);'
  ```
- Chrome: load tools, `tabs_context_mcp`, new tab, visibility + trusted-input
  check as described. Window 1280 wide or more.

Check: all four numbers/outputs pasted; the pointerdown logger shows a trusted
click.

### 1. Sign in as office; first impressions

What (as `office_test`):
- `/login`: tab order, Enter submits, wrong password message, **Remember me**
  present. Sign in. Where do you land, what does the dashboard say with every
  milk batch expired? Does anything on the dashboard tell the office "you
  have no milk to sell"?
- Walk the sidebar once: every link, every page renders, console clean. Note
  anything that takes more than ~2 s. Collapse/expand the sidebar; the
  mobile drawer at 390 px.
- `/stock`: with all milk expired, what does the milk card show? Is the
  "expired" state visible or does milk just vanish?

Probe: none (read-only phase). Record page load feel and any console noise.

### 2. Production: batches

What (as `office_test`, desktop):
- `/batches/create`: make **three** batches dated **today**:
  1. Milk — 1L × 240, 2L × 96, `raw_milk_litres` 500, notes `E2E-20261004`.
     Watch the expiry auto-fill (should be 2026-10-14). Note whether the
     10L Churn row is in the way for a producer who never uses it.
  2. Yoghurt — 250g × 120, 500g × 60, notes tag. Yoghurt has no shelf life:
     what does the expiry field do? Is the operator told it will have no
     expiry (and therefore never fall off `/stock`)?
  3. Farmhouse Cheese — Whole Wheel × 12, **production date 2026-04-01**
     (backdated ≈ 6 months so it is ready to cut **and** eligible for
     maturing), notes tag. Does the form accept a past date? Does the batch
     code come out `G010426`? Does it collide with anything?
- Then try to break it: a second milk batch today (expect `M041026-1`);
  expiry before production (expect refusal); zero/negative quantities; all
  quantities blank; a 3 000-wheel cheese batch (any sanity limit?). Note how
  each refusal is shown — inline, flash, or lost.
- `/batches`: find your batches in the grouped browser; the card header
  strip for the cheese batch (12 yellow circles); open each batch page.
  Is "finished weight" / yield meaningful for a batch with no weights yet?
- `/stock`: milk card now has stock; check the segment numbers equal what
  you entered.

Probe:
```
php artisan tinker --execute='foreach (App\Models\Batch::where("notes","like","%E2E-20261004%")->with("batchItems.productVariant")->get() as $b) { echo $b->id." ".$b->batch_code." exp=".($b->expiry_date?->toDateString()??"-")." ready=".($b->ready_date?->toDateString()??"-").PHP_EOL; foreach ($b->batchItems as $i) echo "   ".$i->productVariant->name." produced=".$i->quantity_produced." remaining=".$i->quantity_remaining.PHP_EOL; }'
```
Check: codes, expiry, quantities match what you typed; cheese `ready_date`
is in the past.

### 3. Cheese: cut a wheel, hold and release to mature

What (as `office_test`):
- `/cheese-cutting`: your G010426 should be "ready to cut". Cut **one**
  wheel: wheel weight 5.120 kg, into Vacuum Packs, 18 packs, total packs
  weight 4.680 kg, cut date today. Read the resulting trim/waste figure on
  the batch page (expect 0.440 kg).
- Try to break it: packs weight > wheel weight; 60 packs from a 5 kg wheel
  (packs × nominal > wheel); cut date before production; cut date tomorrow.
  How is each refusal shown, and does the form keep what you typed?
- `/cheese-conversion`: drag/select **2** wheels of G010426 into Maturing,
  **Save maturing**; reload — are 2 still held? Go to `/stock`: the cheese
  row should show 2 maturing, and `/batches` card 2 deep-amber circles. Try
  to cut one of the held wheels via the batch page — expect the "Wheels
  available" count to exclude them.
- **Release 1** to mature (eligible — production 2026-04-01 is > 150 days
  ago). A Mature batch `G010426-1`-style code should appear with 1 wheel and
  lineage lines both ways. Then on the Mature batch page **Return to
  farmhouse hold** (undo) and confirm the hold is back to 2.
- Release 1 again and **leave it** (you'll sell a Mature wheel later).

Probe:
```
php artisan tinker --execute='$b=App\Models\Batch::where("batch_code","like","G010426%")->with("batchItems")->get(); foreach($b as $x){echo $x->batch_code." ".$x->product->name.PHP_EOL; foreach($x->batchItems as $i) echo "  ".$i->productVariant->name." produced=".$i->quantity_produced." remaining=".$i->quantity_remaining." maturing=".$i->quantity_maturing." available=".$i->available_quantity.PHP_EOL;} echo "cuts: ".App\Models\CheeseCuttingLog::count()." conversions: ".App\Models\CheeseConversionLog::count().PHP_EOL;'
```
Check: Farmhouse wheel item: produced 12, remaining 10 (−1 cut, −1
converted), maturing 1, available 9; a Vacuum Pack item with 18; Mature
batch with 1 wheel. The batch page's reconciliation
(`on hand + dispatched + cut + converted = produced`) shows no red.

### 4. Customers and the run

What (as `office_test`):
- `/customers/create`: create **`E2E Corner Shop`** — email
  `corner@e2e.test`, full address, `payment_terms` net_14, `credit_limit`
  2000, **`requires_reference` on**, `invoicing_mode` per order,
  `invoice_delivery` email, notes tag. Then **`E2E Weekly Deli`** — email
  `deli@e2e.test`, `invoicing_mode` **weekly**, `delivery_charge_percent`
  **5**, notes tag. Note every field that confused you (what is
  `billing_email` vs `email`? what does `send_statements` do? is
  `mossorders_user_id` something an office user should see?).
- On `E2E Corner Shop` → **Special prices**: 1L Bottle at **€1.20**. Try a
  duplicate for the same variant (expect refusal); edit it to €1.25; note
  the UX.
- `/delivery-runs`: set run #1 "Dublin Tue"'s **delivery charge to €6.15**
  (gross; VAT backs out to €5.00 + €1.15). Assign both E2E customers to the
  run; reorder them to positions 1 and 2 (drag? buttons? note it). Open
  **Show charge settings** and tick `apply_delivery_charge` for
  **E2E Corner Shop only**.
- Try: deleting a customer that has no orders (make and delete a throwaway
  `E2E Throwaway`); searching/filtering `/customers` for "E2E".

Probe:
```
php artisan tinker --execute='foreach (App\Models\Customer::where("name","like","E2E%")->get() as $c) echo $c->id." ".$c->name." mode=".$c->invoicing_mode->value." run=".$c->delivery_run_id." pos=".$c->run_position." charge=".($c->apply_delivery_charge?1:0)." pct=".($c->delivery_charge_percent??"-")." ref=".($c->requires_reference?1:0)." currentCharge=".$c->currentDeliveryCharge().PHP_EOL; echo "run charge: ".App\Models\DeliveryRun::find(1)->delivery_charge.PHP_EOL;'
```
Check: Corner Shop `currentCharge=6.15`; Weekly Deli `currentCharge=0` with
`pct=5` (percentage takes precedence over the fixed charge).

### 5. Orders — the desktop form

What (as `office_test`):
- `/orders/create` for **E2E Corner Shop**, delivery **Tue 2026-10-06**:
  1L × 24, 2L × 6, 250g yoghurt × 12, **Farmhouse Whole Wheel × 2**,
  **Mature Whole Wheel × 1**. The **Customer ref** field should auto-open
  (requires_reference) — type `PO-E2E-1`. Notes tag. Save.
  - Is the 1L line priced at the special €1.25? Is the wheel line's estimate
    weight-aware (2 × 5 kg × €16/kg = €160)? Is the delivery charge shown as
    net €5.00 + VAT €1.15 and does Subtotal + 5.00 + 1.15 = Total to the cent?
- Try to break the form: no lines; quantity 0; a customer with no run;
  same variant twice (merge or two lines?); the picker labels (every option
  should read `Product · Variant`).
- **Confirm** the order (dialog). Then on the show page: **Auto allocate** —
  with 2 Farmhouse wheels wanted and 9 available, 1 Mature wanted and 1
  available, milk/yoghurt from your batches, expect everything allocated
  from the E2E batches (FIFO — but are there older *non-expired* batches
  that should win? Y230726 for yoghurt has no expiry; check which batch the
  yoghurt line took and whether that's what FIFO says).
  Then **Remove** one allocation and re-add it manually from the picker.
- Edit lines: raise 1L to 30 (allocation shortfall shown?); lower to 20
  (released?); **Add item** 500g yoghurt × 6; **Remove** the 500g line again.
  Change the delivery charge on `/orders/{id}/edit` to €0 and back to €6.15
  — totals follow?
- Create a **second** order for E2E Corner Shop, same date, 1L × 12 only,
  **leave it pending** (it's for the sheet's "multiple orders" state).
- Create a **third** order for **E2E Weekly Deli**, 2L × 12 + 500g × 6, same
  date; confirm it. Check its delivery charge is **5 % of the subtotal**,
  recomputed when you change a quantity.

Probe:
```
php artisan tinker --execute='foreach (App\Models\Order::whereHas("customer", fn($q)=>$q->where("name","like","E2E%"))->with("orderItems.productVariant","customer")->get() as $o) { echo $o->id." ".$o->order_number." ".$o->customer->name." status=".$o->status." sub=".$o->subtotal." charge=".$o->delivery_charge." pct=".($o->delivery_charge_percent??"-")." tax=".$o->tax_amount." total=".$o->total_amount." ref=".$o->customer_reference.PHP_EOL; foreach ($o->orderItems as $i) echo "   ".$i->productVariant->picker_label." qty=".$i->quantity_ordered." alloc=".$i->quantity_allocated." ful=".$i->quantity_fulfilled." price=".$i->unit_price." line=".$i->line_total.PHP_EOL; }'
```
Check: Corner Shop order 1: 1L `price=1.25`; `charge=6.15 tax=1.15`;
`total = subtotal + 6.15`. Weekly Deli: `charge = round(subtotal×0.05, 2)`,
`pct=5`.

### 6. Orders — the chilled-run sheet

What (as `office_test`, `/chilled-runs?date=2026-10-06`):
- Read the sheet: your two customers at positions 1–2; Corner Shop shows
  **"Multiple orders"** (two orders that day) linking to `/orders`; Weekly
  Deli shows its quantities and a pending/confirmed tag; the stock footer
  shows free stock per column and flags anything short.
- Pick an **existing** customer with no order that day; **Enter order** via
  the row editor: type 1L × 10, 2L × 4, use the **+ Ref** toggle, Save. The
  row should now show the order (pending). Edit again: **Repeat last order**
  / ←→ history for a customer that has history (one of the 16); note what
  happens when history contains a cheese variant that isn't a column.
- Type a quantity in a row, then click another row's **Edit** — the
  **unsaved-changes dialog** must appear; Keep editing; then Discard.
- **Confirm all** (dialog) → pending orders become confirmed; the pending
  tag disappears; the button hides when nothing is pending.
- **Allocate all** (dialog) → the per-cell `✓` / `n/m` sub-lines appear; the
  allocated bar moves; the stock footer's `short` flag clears for covered
  columns. Then edit one allocated row **down** — is the reservation
  released (sub-line updates)?
- Cancel one of the sheet-entered orders by zeroing every quantity and
  saving — expect "cancelled, lines kept".

Probe:
```
php artisan tinker --execute='echo "orders on 2026-10-06: ".PHP_EOL; foreach (App\Models\Order::whereDate("delivery_date","2026-10-06")->with("customer","orderItems")->get() as $o) echo "  ".$o->order_number." ".$o->customer->name." ".$o->status." lines=".$o->orderItems->count()." alloc=".$o->orderItems->sum("quantity_allocated")."/".$o->orderItems->sum("quantity_ordered").PHP_EOL;'
```
Check: no `pending` left except any you deliberately left; allocations equal
ordered for covered lines.

### 7. Picking — phone width, factory role

What: sign out, sign in as `factory_test` → lands on `/picking`. **Resize to
390 × 844.**
- Queue: the Tuesday orders are listed, oldest delivery first; the
  units-picked strip reads 0/N. **No € anywhere** on any picking screen —
  check each one.
- Open the Corner Shop order: pick the **1L** line (fixed qty — stepper
  clamps to the batch max?), the **250g** line, then the **Farmhouse Whole
  Wheel** line with per-piece weights **5.210** and **4.980**, then the
  **Mature wheel** at 5.300. Is the running total right? Is the submit
  disabled until both weights are in? Can you type a weight with a comma?
- **Undo** the 250g pick (dialog, red Undo) — stock back; re-pick.
- The **2L** line: pretend the fridge is empty — **Mark short — no stock**
  (or "Can't finish"). The order should reach **ready** with an amber
  "closed — short" celebration, not green.
- Open the Weekly Deli order, pick everything cleanly — green celebration.
- Tap targets: can you hit every button with a thumb? Does the fixed footer
  cover anything? Does the back navigation make sense? Note each.
- Still at 390 px: try `/orders` as factory — is the desktop app usable at
  phone width at all, or should the factory never leave `/picking`?

Probe:
```
php artisan tinker --execute='$o=App\Models\Order::whereHas("customer",fn($q)=>$q->where("name","E2E Corner Shop"))->where("status","!=","cancelled")->orderBy("id")->first(); echo $o->order_number." ".$o->status." total=".$o->total_amount.PHP_EOL; foreach ($o->orderItems as $i) echo "  ".$i->productVariant->picker_label." ord=".$i->quantity_ordered." ful=".$i->quantity_fulfilled." short=".$i->quantity_short." kg=".($i->weight_fulfilled_kg??"-")." fulfilled_total=".($i->fulfilled_total??"-")." invoiceable=".$i->invoiceable_total.PHP_EOL; foreach (App\Models\BatchItem::whereIn("id",$o->orderItems->flatMap->orderAllocations->pluck("batch_item_id"))->get() as $bi) echo "  batch_item ".$bi->id." remaining=".$bi->quantity_remaining.PHP_EOL;'
```
Check: wheel line `kg=10.190`, `fulfilled_total = 10.190 × 16.00 = 163.04`;
2L line `short=6`, `invoiceable=0.00`; each picked batch item's `remaining`
dropped by exactly the picked quantity; order `ready`.

### 8. Load and dispatch

What:
- As `factory_test` on `/chilled-runs?date=2026-10-06` (desktop width): tick
  **loaded** on both E2E stops. Confirm factory sees **no Dispatch run / Confirm
  all / Allocate all** buttons and no edit links.
- As `office_test`: **Dispatch run** (dialog). Expect: both ready+loaded
  orders dispatched; anything not loaded or not ready **named** in the
  flash; Corner Shop (per-order) gets `INV-000004`; Weekly Deli (weekly)
  gets **no** invoice and shows "Billed on the weekly invoice" on its order
  page. Dispatch confirmations are **queued** — with the listener running
  they should appear in `laravel.log` within seconds (one per order,
  subject/recipient visible). Note how long the button took.
- `/orders/{corner}`: billing line "Invoice INV-000004 · Unpaid" links to
  Finance; **Dispatch confirmation** view and `?download=1` PDF open; the
  **docket** prints without prices. Try to **Add item** / change a line →
  refused, pointing at Void. **Mark delivered** → delivered with timestamp.
- Also dispatch one order the **per-order way**: confirm + allocate + pick
  (as office, desktop panel this time — note the per-unit weight boxes but
  don't re-report them) your sheet-entered order for the existing customer,
  then **Mark dispatched** on its page. Its confirmation sends inline.

Probe:
```
php artisan tinker --execute='foreach (App\Models\Order::whereDate("delivery_date","2026-10-06")->with("customer","invoice")->get() as $o) echo $o->order_number." ".$o->customer->name." ".$o->status." disp=".($o->dispatched_at?->format("H:i")??"-")." loaded=".($o->loaded_at?"y":"n")." invoice=".($o->invoice?->number??"-").PHP_EOL; echo "document_emails: ".PHP_EOL; foreach (App\Models\DocumentEmail::orderByDesc("id")->take(6)->get() as $d) echo "  ".$d->kind." to=".$d->to_email." sent=".($d->emailed_at?"y":"n")." err=".($d->error??"-").PHP_EOL; echo "jobs pending=".DB::table("jobs")->count()." failed=".DB::table("failed_jobs")->count().PHP_EOL;'
```
Check: per-order customers have invoices, Weekly Deli doesn't; every
dispatch confirmation row `sent=y`, `err=-`; `jobs=0 failed=0`;
`grep -c "Dispatch" storage/logs/laravel.log` grew by the number of orders.

### 9. Finance

What (as `office_test`, desktop):
- `/invoicing`: `INV-000004` listed Unpaid, due date = dispatch + 14 days
  (net_14). Open it: lines match the picked quantities and **actual** wheel
  weights; the 2L short line is **€0.00 with Qty 0** (or absent — note which,
  and whether the customer would understand it); delivery line €5.00 + VAT
  €1.15; total = order total. **PDF** renders. **Email** (dialog) → log shows
  it; the invoice leaves the *not emailed* filter.
- **To Invoice** (`/invoicing/uninvoiced`): the Weekly Deli order is there as
  "waiting for the weekly run"; nothing else E2E.
- **Consolidated** (`/invoicing/consolidated`): preview weekly for E2E Weekly
  Deli for the week containing 2026-10-06 — preview consumes **no** number
  (sequence unchanged); issue → `INV-000005`; the 5 % delivery line is on it.
- **Payments**: record **€50.00** bank transfer from E2E Corner Shop
  allocated to `INV-000004` → state **partial**. Record a second payment for
  the **full balance + €10** → invoice paid, **€10 credit on account**
  visible on the customer page. **Apply credit** with nothing open — what
  happens? Delete the second payment → invoice back to partial, credit gone.
  Re-record the exact balance → paid.
- **Credit note**: on `INV-000005` (Weekly Deli) raise a credit of €5.00
  "damaged tub" → invoice balance drops by 5; PDF; email.
- **Statement**: preview E2E Corner Shop for this month → opening 0, the
  invoice, the payments, closing 0; aged buckets all zero; **Generate &
  email** (this is the `name="send"` path from cycle 1 — the statement must
  be **generated and emailed**, not just previewed). Check `/invoicing/statements`
  lists it and the log has the email.
- **Void** `INV-000005`?? — it has a credit note, so Void must be **refused**.
  Instead void nothing; try to void `INV-000004` — it has payments, also
  refused. Record the messages. Then issue a fresh per-order invoice you
  *can* void: the sheet-entered order dispatched in step 8 got one — **void
  it** → the order returns to *Awaiting invoice*, the number is not reused,
  **Issue** again from the queue → next number.
- Dashboard as office: the three finance tiles (Awaiting invoice / Not
  emailed / Overdue) — do the counts match what you just did?

Probe:
```
php artisan tinker --execute='foreach (App\Models\Invoice::orderBy("id")->get() as $i) echo $i->number." cust=".$i->customer_id." total=".$i->total." paid=".$i->amount_paid." credited=".$i->amount_credited." balance=".$i->balance." state=".$i->payment_state." void=".($i->voided_at?"y":"n").PHP_EOL; foreach (App\Models\Customer::where("name","like","E2E%")->get() as $c) echo $c->name." outstanding=".$c->outstanding_balance.PHP_EOL; echo "seq invoice=".App\Models\DocumentSequence::where("key","invoice")->value("next_value")." credit_note=".App\Models\DocumentSequence::where("key","credit_note")->value("next_value").PHP_EOL; echo "statements: ".App\Models\Statement::count()." emails: ".App\Models\DocumentEmail::selectRaw("kind, count(*) c, sum(emailed_at is not null) sent")->groupBy("kind")->get()->map(fn($x)=>$x->kind." ".$x->sent."/".$x->c)->join(", ").PHP_EOL;'
```
Check: balances reconcile (`total − paid − credited = balance`), states as
expected, Corner Shop `outstanding = 0`, every email row sent, invoice
sequence advanced by exactly the number of issues (preview and refused
issues burn nothing).

### 10. The unhappy paths

What (as `office_test`):
- **Cancel with picked stock**: make a quick order for E2E Corner Shop
  (1L × 5), confirm, pick it via the desktop panel, then **Cancel order**
  (dialog). The batch item's `quantity_remaining` must go back up by 5.
- **Back button after a POST**: confirm an order, press browser Back, then
  Forward/refresh — any resubmission prompt, any duplicate action?
- **Double-click**: on a fresh pending order, double-click **Confirm order**
  as fast as you can; on a payment form, double-click Save. Any duplicate
  payment? (Probe `payments` count.)
- **Two tabs**: open the same order in two tabs; allocate in one, then try
  to allocate the same stock in the other. Which error, and is it readable?
- **Validation display**: submit the customer form empty; the batch form
  empty; the payment form with a negative amount. Are errors inline, at the
  top, or both? Any page where the error lands on a different page than
  the form?
- **404s**: `/orders/999999`, `/invoicing/invoices/999999`, `/picking/999999`
  as the relevant role — clean 404 page, no stack trace.
- **Role leak check**: as `factory_test`, type `/invoicing`, `/customers`,
  `/orders/{corner}/dispatch-confirmation` — 403 each; as `driver_test` the
  dashboard placeholder only.

Probe: `payments` count unchanged by the double-click; the cancelled order's
batch item remaining restored; `failed_jobs` still 0.

### 11. Wrap up

What:
- Stop the queue listener and the log tail.
- Final `git status --short` (only `implemented.md` + the staged archive).
- Final DB counts (same command as step 0) next to the baseline.
- The **E2E records table** (every id/code you created, by type).
- `## Findings` — the full numbered list, grouped by phase, in the format
  below. `## Confirmed correct` per phase. `## Notes for Planner` — anything
  that isn't a finding (ideas, questions, "I'd have expected…").

### Findings format

```
### F-07 · S2 · Phase 5 · desktop 1280
**Where:** /orders/create as office_test
**Steps:** 1. choose E2E Corner Shop 2. add Farmhouse Whole Wheel × 2 3. Save
**Expected:** line estimate €160.00 (2 × 5.000 kg × €16.00/kg), per CLAUDE.md "Pricing Display Helpers"
**Actual:** €32.00 — the estimate ignores weight
**Evidence:** screenshot described: …; probe output: line_total=32.00
**Console:** clean | <error text>
**Suggested fix (optional):** …
```

Severity goes in the heading. One finding per cause — if ten pages share a
problem, one finding listing the pages. If you're unsure whether something
is a bug or a design choice, file it as **S3** and say so.

## Verification

1. `git status --short` → only `docs/planImp/implemented.md` and the two
   staged archive renames.
2. Step 0 baseline and step 11 final counts both present in `implemented.md`,
   with the E2E records table.
3. Every step has its DB probe output pasted and its Check answered.
4. `## Findings` numbered without gaps; every finding has Where / Steps /
   Expected / Actual / Evidence / Console.
5. `php artisan test` **not required** (no code changed) — but run
   `php artisan test --filter=AuthorizationBoundaryTest` once at the end as
   a smoke that the environment is sane: all pass.
6. `DB::table('failed_jobs')->count()` = 0, or each failure explained.

## Risks

- **Expired milk stock.** Everything sellable in milk comes from the batches
  you create in step 2; if that step is blocked, steps 5–9 can't proceed as
  written — set BLOCKED there rather than improvising with G030426.
- **The run sheet's week.** Forgetting `?date=2026-10-06` puts you on last
  Tuesday; Confirm all / Allocate all / Dispatch run act on **that** date's
  orders. Check the heading before pressing a run-level button.
- **Queue.** Without the listener, run-level dispatch leaves jobs in `jobs`
  and no emails; that would look like a bug. It isn't — step 0 covers it.
- **Dialogs.** All app confirms are in-app now; the only native dialog left
  is `beforeunload` on the run sheet. Don't refresh/back with unsaved
  quantities.
- **Real input.** If Chrome input stops arriving mid-run, reset Chrome and
  continue; don't fall back to synthetic events for UX observations.
- **Session length.** This is long. Update `implemented.md` after every step;
  BLOCKED-with-resume-point is a fine outcome.

## Review

(Planner fills this in after reading implemented.md.)
