# Cycle 16c — User guide, part 2: tooling leftovers, Finance, Customers & runs

Status: ACCEPTED
Revision: 2
Planner: Fable 5.1
Date: 2026-11-05 (revision 1: 2026-10-31); accepted 2026-11-07

**Revision 2 — read this first.** Steps 0–9 are done and pass review (see
*Review* at the end); do not redo them. The Implementer does **steps 10–13
only** (*Revision 2 steps*, after step 9), then appends a `## Revision 2`
section to the existing `implemented.md` (same headings: steps, deviations,
verification, notes) and sets `Plan revision: 2`. Do **not** re-shoot the
pictures apart from the one named in step 11.

Second of three guide cycles. 16b built `/help`, the screenshot tooling and
the four order-flow pages. This cycle closes what 16b deferred (a Help link
on the phone, a demo-data `--fresh` path, manifest placeholders so pictures
stop going stale, a `before` hook for form pictures, the sidebar cut in
full-page shots, two wording fixes), then writes **Finance** and
**Customers & delivery runs**. Production, stock and admin pages are
**16d**. Parked cycle 12 (delete guards) stays cycle 17.

## Goal

An office user opens Help and finds the money side explained in the order
they work it: what gets invoiced when, how to issue the exceptions, record a
lump-sum payment, raise a credit note, chase overdue accounts, send
statements and export for the accountant. A second page covers setting up a
customer (terms, invoicing mode, delivery, special prices, the credit
warning) and the delivery runs the sheet is built from. The tooling changes
make the pictures reproducible after a database reset without editing the
manifest by hand, and give the floor a Help link where they actually are.

## Context

**Baseline.** `main` at `4fda1a7` (cycle 16b shipped, production at the
same revision). `php artisan test` → **816 tests, 0 failed**; pint clean.
Working tree clean apart from `docs/planImp/plan.md` (this file) and
possibly `RESUME.md`. `ship-jon` now commits untracked files, so **anything
you create that must not ship goes in `.gitignore`** (step 3 creates one
such file).

**What exists (read before step 1).** `app/Http/Controllers/HelpController.php`,
`resources/views/help/show.blade.php`, `tests/Feature/HelpPagesTest.php`
(reads the real `docs/guide/` folder: every page linked from the index
exists, every page renders, every referenced picture and every manifest
shot has its PNG), `docs/guide/screenshot.mjs` (options per shot: `name`,
`as`, `url`, `width`, `height`, `fullPage`, `mobile`, `clip`, `waitFor`,
`scale`; default scale 1.5), `docs/guide/screenshots.json` (15 shots),
`docs/guide/demo-data.php` (tinker include; orders `GUIDE-A…D`, idempotent
on `customer_reference`; prints `REF  id=N  ORD-…  status  date  [Variant=itemId, …]`),
and the five pages. The 16b archive is `docs/planImp/archive/2026-10-31-user-guide-part-1/`;
its `implemented.md` → *Notes for Planner* is the list this cycle's steps
1–6 answer.

**Phone shell.** `resources/views/layouts/picking.blade.php` has no nav at
all; the queue page `resources/views/picking/index.blade.php` draws its own
header with one round icon button linking to the dashboard (line 66,
`class="iconbtn"`, `title="Dashboard"`). The Help link goes beside it.
`tests/Feature/PickingTest.php` covers `/picking` as factory; add the
assertion there.

**Manifest dates and ids are hard-coded** (`/orders/11`, `?date=2026-10-29`,
`/picking/11/items/36`). Today the GUIDE-A order is already two days past
its delivery date, and after a `mossfield:reset-trading-data` the ids are
gone. The fix has two halves: the demo script writes what it made to a JSON
file, and the screenshot script substitutes placeholders from it.
`php artisan tinker <file>` takes no arguments, so the script reads an
environment variable (`GUIDE_FRESH=1`) for the fresh path.

**Cancelling an order in a script.** `OrderController::update()` on
`→ cancelled` calls `OrderItem::releaseUnits($item->quantity_allocated)` for
every line inside a transaction, then writes the status. The model helper
`releaseUnits()` (`app/Models/OrderItem.php:490`) is public and returns
reserved units first, picked units last (stock back to the batch). Mirror
exactly that; do not delete orders (orders are never deleted) and do not
pull the `MutatesOrderLines` controller trait into a script.

**Sticky sidebar in full-page shots.** The sidebar `<aside>` is
`h-screen md:sticky`; a full-page capture therefore shows it ending after
one viewport height. Puppeteer's `page.addStyleTag({content})` injects CSS
for the capture only; the fix is a screenshot-side rule, not a view change.
Because the layout is a flex row, `position: static; height: auto;
min-height: 100%` on the aside makes it stretch with the page.

**Finance data on the dev box (2026-10-31).**

| What | State |
|---|---|
| Invoices | `INV-000001` Barnhill, **void**; `INV-000002` Cavistons (22), issued 28 Oct, due 27 Nov, total 216.80, **partial** (balance 89.80 after a €100 payment and credit note `CN-000001` for 27.00); `INV-000003` Roundwood (12), **paid** (€50 payment) |
| Payments | 2 (Roundwood 50.00, Cavistons 100.00, both 28 Oct) |
| Credit notes | 1 (`CN-000001` against INV-000002) |
| Statements | 1 (Cavistons, period to 28 Oct) |
| Sent documents | 4 rows: one dispatch confirmation sent, two failed, one failed invoice email (`MAIL_MAILER=log` now, so new sends will succeed and land in `storage/logs/laravel.log`) |
| Awaiting invoice | dispatched, uninvoiced: order 1 (An Tairseach), order 6 (Barnhill); ready, awaiting dispatch: 4 (Barnhill), 5 (Organic Store) |
| Invoicing modes | 15 per-order, 1 weekly (**Roundwood Stores**) |
| Overdue | nothing is overdue today (INV-000002 is due 27 Nov) |

So the finance pictures use **Cavistons** for the invoice page (issued,
part-paid, with a credit note and a payment allocation — the richest single
page), **Roundwood** for the consolidated run (weekly), and the *Awaiting
invoice* queue as it stands. The *Overdue* page will be empty; its picture
shows the empty state and the page text says what appears there. Organic
Store (25) has no invoices; the Customers page uses it for the customer
page (Account panel, special prices if any, order history) and the form.

**Customer data.** 16 customers; Dublin Tue run (1) has 15 stops, Wicklow
Thu (2) has Organic Store alone (€12.30 run charge, Organic Store flagged
to pay it). Customer form labels (exact): *Customer name*, *Email*,
*Phone*, *Street address*, *City*, *Postal code*, *Country*, *Credit limit
(€)*, *Payment terms* (Immediate / Net 7 days / Net 14 days / Net 30 days),
*Billing email*, *VAT number*, *Invoice delivery*, *Invoicing*, *Delivery
charge (%)*, *Mossorders user ID* (only when the Mossorders flag is on —
it is off), *Notes*, *Active customer*, *Always require a customer
reference on orders*. Customer page panels: *Basic information*,
*Address*, *Business terms*, *Account* (with **Record payment**,
**Statement**, **Apply credit**), *Special prices*, *Recent invoices*,
*Documents sent*, *Orders*. Delivery runs page: per-run stop list with
↑/↓ arrows, Position boxes and **Save order**, **Add** (assign a
customer), × remove, **Show charge settings** reveal, **Delete** run.

**Finance screen labels (exact, from the views).** Invoices index:
**Filter**. Invoice page: **Email invoice** (or **Email again**), **Send
reminder**, **Void** (opens a modal), **Record a payment** (a link to the
payment form for this customer). Awaiting invoice: **Issue invoice** per
row, **Issue all N**; a second section *Picked, awaiting dispatch* has no
buttons. Payments: **Record a payment** → a two-step form (*Customer* →
**Continue**, then *Amount received (€)*, *Date received*, *Method*,
*Reference*, *Notes*, and the allocation across open invoices). Overdue:
**Remind** per row, **Remind everyone**. Statements: **Month-end run**, a
per-customer preview and PDF. Consolidated: **Preview period**, **Issue
selected**. Sent documents: **Filter**. Export: **Download CSV**. Credit
note: **Issue credit note**. As with 16b, grep every bold label in
`resources/views` + `app/View` before writing it.

**Sources per page** (`CLAUDE.md` sections): Finance → *Invoicing &
Statements* (all sub-sections: the snapshot invariant, numbering, money
model, VAT, consolidated, email + the log, statements, config) and
*Dispatch from the sheet*. Customers → *Customer Structure*, *Customer
Special Prices*, *Credit limit warns, never blocks*, *Delivery Charge*,
*Consolidated invoicing*, *Chilled Run Sheet → Data model*, and the
delivery-runs bullets under *Key mechanics*. Where a view and `CLAUDE.md`
disagree, the view wins; note it.

## Constraints

- **Application code changes are two small ones**: the Help link in
  `picking/index.blade.php` (step 1) and nothing else in `app/`,
  `resources/views/` (other than that file) or `routes/`. No migrations. No
  stock, money or status logic (`TESTING_AND_FIXES.md` → *Invariants worth
  keeping*).
- **Scripts stay dev-only.** `demo-data.php` keeps its two guards
  (`APP_ENV=local`, database `mossfield_cl`). `GUIDE_FRESH` cancels only
  orders whose `customer_reference` starts with `GUIDE-`, through
  `releaseUnits`, and never deletes a row.
- **No real sends.** Nothing in this cycle presses Email, Remind, Issue,
  Void, Record payment or Month-end run on the dev box; pictures show the
  screens before the action. (`MAIL_MAILER=log` would make it harmless, but
  the pictures should show the queue as it is.)
- **Pictures of money are fine on office pages** (16b decision). No `€`
  figures in the prose.
- **Staff names.** No admin or users-page pictures this cycle (16d).
- **Commit policy:** do not commit, push or deploy. Jon ships with
  `ship-jon`.

## Out of scope

- Production, stock and admin guide pages (16d): batches, cutting, mature
  conversion, stock overview, write-offs, users, settings, backup. The
  dev box's expired stock stays as it is for 16d's pictures.
- Role filtering of the help index; search; printing.
- Any change to the finance or customer screens themselves. A screen that
  reads wrong while you write about it goes in *Notes for Planner*.
- Delete guards (cycle 17), the Mossorders flag decision, finding 4 of
  2026-10-13 (Email invoice from the order page).

## Steps

### 0. Baseline
Files: `docs/planImp/implemented.md` (new)
What: record HEAD (expect `4fda1a7`), `git status --short`, `php artisan
test`, `./vendor/bin/pint --test app tests`.
Check: 816 tests, 0 failed; pint clean.

### 1. Help on the phone
Files: `resources/views/picking/index.blade.php`, `tests/Feature/PickingTest.php`
What: beside the dashboard icon button (line 66) add a second round icon
button of the same style linking to `route('help.show', 'picking')`, with
`title="Help"` and `aria-label="Help"`, the `help` icon paths from
`navigation.blade.php` (`M12 22a10 10 0 1 0 0-20…` + the question mark).
Keep both buttons in one flex container so the header's right side stays
aligned. In `PickingTest` add `test_the_queue_links_to_the_picking_help_page`:
factory GET `/picking` sees `route('help.show', 'picking')`, and that page
returns 200 for factory.
Check: `php artisan test --filter=PickingTest` → all pass; the
`picking-queue` picture (re-shot in step 7) shows two round buttons top
right.

### 2. Two wording fixes from 16b
Files: `docs/guide/orders.md`, `docs/guide/getting-started.md`
What: `orders.md` → *Confirm, or cancel*, replace "Confirm and cancel are
the only status changes you make by hand. Everything after that follows
from picking and dispatch." with "Confirm and cancel are the only status
buttons until the order is picked. After that come **Loaded onto van**,
**Mark dispatched** and **Mark delivered** (see *Send it out*)."
`getting-started.md` sidebar table, Sales row: "(*floor*: both, read-only)"
→ "(*floor*: both — the floor can tick **Loaded onto van** on the run
sheet, nothing else)".
Check: `grep -n 'only status' docs/guide/orders.md` shows the new sentence;
`php artisan test --filter=HelpPagesTest` passes; the three bold labels are
in the views (they are — `orders/show.blade.php`, `_run-table`).

### 3. `demo-data.php`: fresh path and a machine-readable result
Files: `docs/guide/demo-data.php`, `.gitignore`
What:
- At the top, after the guards: if `getenv('GUIDE_FRESH')` is truthy,
  for every non-cancelled order whose `customer_reference` is `like
  'GUIDE-%'`, inside a `DB::transaction`: `releaseUnits($item->quantity_allocated)`
  on each line, then `update(['status' => 'cancelled'])`, then
  `calculateTotals()`; print one line per cancelled order. Then fall
  through to the normal create path, which now finds nothing and makes
  fresh orders for the coming run dates.
- At the end, write `docs/guide/demo-data.json` (`base_path(...)`,
  `JSON_PRETTY_PRINT`): `{"generated": "<ISO date-time>", "orders": {"GUIDE-A": {"id": 11, "number": "ORD-…", "status": "confirmed", "date": "2026-10-29", "items": {"1L Bottle": 36, "2L Bottle": 37, …}}, …}}`.
  Print its path on the last line.
- Add `/docs/guide/demo-data.json` to `.gitignore` (ids are this box's;
  `ship-jon` would otherwise commit it).
- Update the header comment: the two invocations, what `--fresh` does, the
  JSON.
Check: `php artisan tinker docs/guide/demo-data.php` → four lines, nothing
created, JSON written; `GUIDE_FRESH=1 php artisan tinker docs/guide/demo-data.php`
→ four "cancelled" lines then four new orders with **new ids** and delivery
dates on or after today (`Order::where('customer_reference','like','GUIDE-%')->count()`
is now 8, four cancelled); free stock for `M281026` 1L is back up by the
cancelled reservations (compare `BatchItem::freeStockByVariant([1])` before
and after); `git status --short` does not list `demo-data.json`.

### 4. Manifest placeholders
Files: `docs/guide/screenshot.mjs`, `docs/guide/screenshots.json`, `docs/guide/README.md`
What: the script reads `docs/guide/demo-data.json` if it exists and, in
every shot's `url`, replaces `{GUIDE-A.id}`, `{GUIDE-A.date}`,
`{GUIDE-A.item.1L Bottle}` (and the same for B–D) with the JSON values. A
placeholder with no value is an error naming the shot (do not shoot a
wrong order silently). Rewrite the 15 URLs: `/orders/{GUIDE-B.id}`,
`/orders/{GUIDE-A.id}`, `/chilled-runs?run=1&date={GUIDE-C.date}`, …,
`/picking?date={GUIDE-A.date}`, `/picking/{GUIDE-A.id}/items/{GUIDE-A.item.1L Bottle}`,
`/picking/{GUIDE-A.id}/items/{GUIDE-A.item.Whole Wheel}`,
`/picking/{GUIDE-C.id}`. Order 5 stays literal (it is not a GUIDE order).
README → *Screenshots*: one sentence on placeholders and `GUIDE_FRESH=1`.
Check: `node docs/guide/screenshot.mjs picking-queue` after step 3's fresh
run shows the **new** GUIDE-A date's queue with Organic Store "To do";
removing `demo-data.json` temporarily makes the same command fail with a
message naming the placeholder (restore it after).

### 5. `before` actions and the full-page sidebar
Files: `docs/guide/screenshot.mjs`, `docs/guide/screenshots.json`
What:
- A per-shot `before` array of small actions run after the page loads and
  before the capture: `{"select": "<css>", "value": "<v>"}`,
  `{"type": "<css>", "text": "<t>"}`, `{"click": "<css>"}`,
  `{"wait": <ms>}` (and `{"waitFor": "<css>"}`). Each dispatches the
  events a real user would (`page.select`, `page.type`, `page.click`).
  Unknown action → error naming the shot.
- Use it on `order-new-form`: select Organic Store in `#customer_id`
  (value = its id, from the page's options — read `orders/create.blade.php`
  for the customer select and the add-line control), add one line with a
  Farmhouse wheel so the `~` estimate and the delivery-date run hint are in
  the picture. Read the view for the exact ids/selectors; if a line needs a
  click on an "Add item" control, do that first.
- For every `fullPage` shot (and only those), inject
  `aside { position: static !important; height: auto !important; min-height: 100% !important; }`
  with `page.addStyleTag` before the capture, so the sidebar runs the full
  height of the picture. If `aside` is too broad a selector on some page
  (check the markup — it should be the one sidebar), narrow it.
Check: `node docs/guide/screenshot.mjs order-new-form` → the picture shows
a customer chosen, the run named under the delivery date, one line with a
`~` total; `node docs/guide/screenshot.mjs order-page-ready` → the sidebar
reaches the bottom of the image with the Miscellaneous and Production
groups visible.

### 6. The part-2 shot list
Files: `docs/guide/screenshots.json`
What: add these (office = `office_test`, 1280 × 800 unless noted):

| name | url | notes |
|---|---|---|
| finance-invoices | /invoicing | |
| finance-invoice-partial | /invoicing/invoices/2 | fullPage — Cavistons: issued, part-paid, credit note, payment allocation, the Email / Send reminder / Void buttons |
| finance-awaiting-invoice | /invoicing/uninvoiced | both sections |
| finance-record-payment | /invoicing/payments/create | `before`: select Cavistons, click **Continue**; then the amount form with the open invoice listed — if Continue is a full form POST the `before` click plus `waitFor` on `#amount` handles it |
| finance-overdue-empty | /invoicing/overdue | the empty state |
| finance-statements | /invoicing/statements | |
| finance-statement-preview | /invoicing/customers/22/statement | fullPage — Cavistons' ledger with the aged buckets |
| finance-consolidated | /invoicing/consolidated | `before`: select Roundwood and a period if the form needs it, click **Preview period**; else the empty form |
| finance-sent | /invoicing/emails | |
| finance-export | /invoicing/export | |
| customers-list | /customers | |
| customer-page | /customers/25 | fullPage — Organic Store |
| customer-form | /customers/create | fullPage, empty |
| delivery-runs | /delivery-runs | fullPage — both runs, stops in order |

Re-shoot everything (`node docs/guide/screenshot.mjs` with no filter) so
part 1's pictures pick up steps 3–5. Open every new PNG with `Read` and
confirm it shows what the table says; list what each one shows in
`implemented.md` as 16b did.
Check: script exits 0, 29 ✓; `du -sh docs/guide/images` and the largest
file recorded; nothing over 500 KB (drop `scale` on a fullPage shot if so,
as 16b did).

### 7. Finance page
Files: `docs/guide/finance.md` (new)
What: for the office; the order of the day's money work. Sections:
- *How invoicing works here* — an invoice is a frozen copy of the order at
  the moment it is issued; it is issued **automatically when an order is
  dispatched** for per-order customers; weekly and monthly customers are
  billed on a combined invoice from the Consolidated screen; nothing is
  emailed until you send it; an invoiced order cannot be changed, only
  voided and re-issued. The **Finance** group in the sidebar and the tab
  bar across the top of every finance screen are the same eight screens.
- *Invoices* — the list and its filters (status, customer, not yet
  emailed); the invoice page: the lines, the balance, payments and credit
  notes against it, **Email invoice** / **Email again**, **Send reminder**,
  **Void** (only while nothing is paid or credited against it; voiding
  returns the order to Awaiting invoice), **Record a payment**, the PDF.
  Picture: finance-invoices, finance-invoice-partial.
- *Awaiting invoice* — the exceptions list: dispatched orders whose
  automatic invoice failed or whose customer is on a combined invoice;
  **Issue invoice** / **Issue all**; the lower *Picked, awaiting dispatch*
  list has no buttons because an invoice is only issued once the order has
  left. Picture: finance-awaiting-invoice.
- *Record a payment* — shops pay lump sums: choose the customer, enter the
  amount, date, method and reference, then spread it across their open
  invoices (oldest due first is offered); anything left over is credit on
  the account, spent later with **Apply credit** on the customer page.
  Picture: finance-record-payment.
- *Credit notes* — from the invoice page: reduce an invoice by lines or an
  amount; if that leaves it over-paid the difference becomes credit on the
  account. Label: **Issue credit note**.
- *Overdue* — invoices past their due date (terms come from the customer's
  payment terms); **Remind** / **Remind everyone** attaches the invoice;
  the *Overdue* tile on the dashboard counts the same list. Picture:
  finance-overdue-empty.
- *Statements* — a customer's ledger (opening balance, invoices, credits,
  payments, closing balance, aged buckets); the per-customer preview and
  PDF; **Month-end run** sends one to every customer with a balance.
  Picture: finance-statements, finance-statement-preview.
- *Consolidated invoices* — for weekly/monthly customers: pick the customer
  and period, **Preview period** (nothing is numbered yet), **Issue
  selected**. Picture: finance-consolidated.
- *Sent* — every document emailed (dispatch confirmations, invoices,
  reminders, statements, credit notes) with sent / failed; a failed send
  can be retried from the document. Picture: finance-sent.
- *Export* — the CSV for the accountant for a period. Picture:
  finance-export.
- *What the invoice states mean* — a table: Unpaid, Partial, Paid, Overdue,
  Void (derived from balance and due date, so they cannot go stale).
Under 140 lines. No `€` figures. Every bold label grepped.
Check: `php artisan test --filter=HelpPagesTest` passes with the new page;
read `/help/finance` as `office_test` once through against the pictures.

### 8. Customers & delivery runs page
Files: `docs/guide/customers.md` (new)
What: for the office. Sections:
- *Find a customer* — the list, search, active / online filters.
  Picture: customers-list.
- *Add or change a customer* — the form field by field in groups: contact
  (email optional unless invoices go by email; address required, the rest
  optional), *Business terms* (**Credit limit (€)** — blank is no limit, and
  a limit **warns, never blocks**: the order still goes through with a
  warning naming the figure; **Payment terms**; **Invoice delivery**;
  **Invoicing** per order / weekly / monthly and what choosing weekly or
  monthly does to the terms; **Delivery charge (%)** for a negotiated
  percentage instead of the run's fixed charge), **Always require a
  customer reference on orders** (the Customer ref box opens by itself),
  **Active customer**. Picture: customer-form.
- *The customer page* — what each panel is for: *Account* (open balance,
  credit, aged; **Record payment**, **Statement**, **Apply credit**),
  *Special prices* (a per-product price for this shop; it applies to new
  order lines from then on, existing orders keep their price), *Recent
  invoices*, *Documents sent*, *Orders*. Picture: customer-page.
- *Delivery runs* — a run is a fixed weekly route (name, day, driver,
  capacity note, a fixed delivery charge including VAT); a customer is on
  at most one run, in a stop order; **Add** a stop, ↑/↓ or the Position
  boxes + **Save order** to reorder, × to take a stop off (the customer is
  kept); **Show charge settings** reveals the per-stop toggle for the run's
  delivery charge and shows a percentage customer's rate instead; the
  charge is added **once per drop** (one charge per customer per delivery
  date). The run sheet (see [Chilled runs](chilled-runs.md)) is built from
  these. Picture: delivery-runs.
Under 110 lines. Every bold label grepped.
Check: as step 7.

### 9. Index and pointers
Files: `docs/guide/README.md`, `CLAUDE.md`
What: README table — link Finance and Customers & delivery runs, keep
Production/stock and Admin as *(to write)*; *Screenshots* section mentions
`GUIDE_FRESH=1`, the placeholders and `before`. `CLAUDE.md` *Key Docs*
bullet: add "`GUIDE_FRESH=1` remakes the demo orders; the manifest takes
ids and dates from `docs/guide/demo-data.json` (gitignored)".
Check: `HelpPagesTest` passes (the index links resolve).

## Revision 2 steps

**Why.** `/help` sits in the `role:admin,office,factory` group and
`HelpController` checks nothing further, so a floor user can open
`/help/finance`, `/help/customers` and `/help/orders` and every picture under
`/help/images/`. Those pictures show named shops' invoice totals, balances,
credit limits, special prices and order values. Everywhere else the app hides
money from the floor (`see-financials` gate, `FinancialPolicy`, the picking and
run-sheet € redaction tests). Revision 1 put role filtering out of scope; with
finance pictures in the guide that no longer holds. The fix is a per-page
audience, checked for the page and for its pictures.

**Baseline for revision 2.** The tree as revision 1 left it (nothing
committed since `4fda1a7`): `php artisan test` → 817 tests, 0 failed; pint
clean.

### 10. Page audiences
Files: `app/Http/Controllers/HelpController.php`, `tests/Feature/HelpPagesTest.php`, `tests/Feature/AuthorizationBoundaryTest.php`
What:
- In `HelpController` add a public constant listing every page and who may
  read it:
  ```php
  public const AUDIENCE = [
      'README' => 'all', 'getting-started' => 'all',
      'chilled-runs' => 'all', 'picking' => 'all',
      'orders' => 'office', 'finance' => 'office', 'customers' => 'office',
  ];
  ```
  `all` = anyone the route group admits. `office` = the user passes the
  existing `see-financials` gate (admin and office). A page missing from the
  map is treated as `office` (fail closed).
- `show()`: missing file → 404 as now; then a page the user may not read →
  `abort(403)` (the standard error page with its link home).
- `image()`: missing file → 404 as now; then 403 unless **some page the user
  may read references `images/<file>`** (scan the readable pages' Markdown
  for `images/<name>.png`; seven small files, no cache needed). So a picture
  follows its pages, and there is no second list to keep.
- `index()`: before rendering the README, drop the table rows whose link
  points at a page the user may not read (rows are single lines starting
  `|` and containing `](<page>.md)`), so the floor's index lists only its
  pages.
- `HelpPagesTest`, new cases (keep the existing six green):
  1. `test_every_guide_page_has_an_audience` — every `docs/guide/*.md`
     basename is a key of `HelpController::AUDIENCE`, each value `all` or
     `office`; no key without a file.
  2. `test_the_floor_cannot_open_office_pages_or_their_pictures` — as
     factory: each `office` page → 403 at `/help/<page>` and `/help/<page>.md`;
     every picture referenced only by `office` pages → 403; each `all` page
     and each of its pictures → 200. As office and as admin: every page and
     every referenced picture → 200.
  3. `test_the_floor_index_lists_only_its_pages` — factory `GET /help` does
     not contain `route('help.show', 'finance.md')`, `…'customers.md'` or
     `…'orders.md'` and does contain `…'picking.md'`; office sees all of them.
- `AuthorizationBoundaryTest::buildFixtures()`: change the help fixtures to
  a page and picture the floor may read — `'page' => 'picking'`,
  `'file' => 'picking-queue.png'` — so the matrix still expects 200 for
  factory on the three help routes.
Check: `php artisan test --filter='HelpPagesTest|AuthorizationBoundaryTest|PickingTest'`
→ all pass (HelpPagesTest 9 tests). `./vendor/bin/pint --test app tests` clean.

### 11. No prices on a page everyone can read
Files: `docs/guide/screenshots.json`, `docs/guide/images/dashboard-office.png`, `docs/guide/README.md`
What: `getting-started.md` is an `all` page and its `dashboard-office`
picture shows the *Value* column of the order queue (three shops' order
totals). Add a `blur` entry for that column to the `dashboard-office` shot
(the third cell of the order-queue rows — read `dashboard.blade.php` for a
selector that matches only those cells; the *Overdue* tile's total stays).
Re-shoot **that shot only**: `node docs/guide/screenshot.mjs dashboard-office`.
Open the PNG and confirm the three values are unreadable and nothing else
is blurred. Check the other `all` pages' pictures for a `€` by opening
`sidebar-help`, `chilled-runs-dublin` and `chilled-runs-enter-order`
(the run sheet is €-free by test; say what you saw). README →
*Screenshots*: one sentence — a picture used on a page everyone can read
must not show prices; blur them.
Check: `git status --short docs/guide/images` shows exactly one more changed
picture than before (`dashboard-office.png`); `HelpPagesTest` passes.

### 12. The loaded-tick wording
Files: `docs/guide/getting-started.md`
What: revision 1's sentence was the Planner's and is wrong twice (the run
sheet's tick is not labelled "Loaded onto van", and the floor can also tick
it on the order page). Sales row becomes: "(*floor*: both — the floor can
tick a stop as loaded, on the run sheet or the order page, and nothing
else)".
Check: `grep -n 'tick a stop as loaded' docs/guide/getting-started.md` → one
line; `HelpPagesTest` passes.

### 13. Pointers
Files: `CLAUDE.md`, `docs/guide/README.md`
What: `CLAUDE.md` *Key Docs* bullet for `docs/guide/` — add: "Each page has
an audience in `HelpController::AUDIENCE` (`all`, or `office` = the
`see-financials` gate); an office page and the pictures only it uses are
403 for the floor, and a new page must be listed there (`HelpPagesTest`)."
README → *Screenshots* (or a short *Who can read what* line above it): the
same in one plain sentence for whoever adds a page.
Check: `HelpPagesTest` passes.

### Revision 2 verification
1. `php artisan test` → **820 tests, 0 failed** (817 + three; say the real
   number). 2. `./vendor/bin/pint --test app tests` → clean.
3. As `factory_test` in a browser (headless is fine): `/picking` → round
   Help button → `/help/picking` opens with its pictures; `/help` lists
   Getting started, Chilled runs and Picking only; `/help/finance` →
   the 403 page; `/help/images/finance-invoices.png` → 403.
4. As `office_test`: `/help` lists six pages; `/help/finance` and
   `/help/customers` open with every picture loaded.
5. `git status --short` → application changes are `HelpController.php`,
   `picking/index.blade.php` and the three test files; no migrations;
   `demo-data.json` absent.

## Verification

1. `php artisan test` → **≥ 817 tests, 0 failed** (816 + the PickingTest
   case; say the real number).
2. `./vendor/bin/pint --test app tests` → clean.
3. `node docs/guide/screenshot.mjs` → exit 0, 29 ✓.
4. `php artisan tinker docs/guide/demo-data.php` (no fresh) → the four
   current orders, nothing created, JSON rewritten.
5. `git status --short` → `demo-data.json` absent; application changes are
   `picking/index.blade.php` and `PickingTest.php` only; no migrations.
6. Browser as `office_test`: `/help/finance` and `/help/customers` open from
   the index, every picture loads; as `factory_test`: the round Help button
   on `/picking` opens `/help/picking`.

## Risks

- **A `before` click that submits a form** (Continue on the payment form)
  navigates; wait for navigation or a selector on the next page, not a
  fixed delay. If a page's JS needs a real event sequence the script cannot
  produce, shoot the empty form and say so.
- **`GUIDE_FRESH` and picked stock.** GUIDE-C's milk line is picked;
  `releaseUnits` restores those 12 units to `M281026`. The check in step 3
  confirms free stock rose. Do not run the fresh path against anything but
  `GUIDE-%`.
- **Finance pictures carry other shops' money** (Cavistons, Roundwood,
  Barnhill). Jon has accepted shop names in pictures; the office pages are
  the office's own view.
- **The invoice page's Void opens a modal** — the picture shows the button
  only; do not open the modal (no state change, but the modal would hide
  the page).
- **Prose on the money model is the easiest place to be wrong.** Write
  from `CLAUDE.md` → *Invoicing & Statements*, check each sentence against
  the screen, and leave anything you cannot confirm out rather than guess.

## Review

### Revision 1 — reviewed 2026-11-05 (Planner, a separate session from the Implementer's)

**Verdict: steps 0–9 pass. Not accepted yet — one gap the plan left open
(who can read the money pages) is closed by revision 2, steps 10–13.**

Rerun by the Planner: `php artisan test` → 817 tests, 0 failed (5387
assertions); `./vendor/bin/pint --test app tests` → 233 files pass;
`php artisan tinker docs/guide/demo-data.php` → the four orders (ids 15–18),
nothing created (18 orders before and after, 8 `GUIDE-*`, 4 cancelled);
`git check-ignore` confirms `demo-data.json`; one shot taken into the
scratchpad through `GUIDE_MANIFEST` (`customers-list` ✓, contact column
blurred) and the missing-JSON failure reproduced (exit 1, names the
placeholder). The full re-shoot was **not** rerun: the top bar carries a
clock, so every re-shoot rewrites every office picture.

| Step | Result |
|---|---|
| 0 Baseline | pass |
| 1 Help on the phone | pass — diff read; two buttons in one flex container; `test_the_queue_links_to_the_picking_help_page`; `picking-queue.png` opened (Help and Dashboard buttons top right) |
| 2 Wording | pass as specified — but the `getting-started` sentence the plan dictated is wrong (note 4); step 12 |
| 3 Fresh path + JSON | pass — mirrors `OrderController::update()`'s cancel; refuses what `canBeCancelled()` refuses; never deletes |
| 4 Placeholders | pass — nine URLs; missing value fails the shot |
| 5 `before` + sidebar | pass |
| 6 Shots | pass — 29 PNGs, 5.7 MB, largest 414 KB. Opened by the Planner: `customers-list`, `finance-invoice-partial`, `customer-page`, `picking-queue`, `dashboard-office` |
| 7 Finance page | pass — 129 lines. Planner's own label grep: 112 distinct bold strings across both pages, the only misses are the five emphasis phrases and one joined heading. Behaviour claims checked against code: void hidden unless `?status=void`; void needs a reason and is blocked by payments/credit notes; **Remove payment** restores balances; credit-note form and its own Email/Void; **Remind everyone** skips invoices reminded today; "No email"; statement **Update** / **Generate** / **Generate & email**; month-end modes and `send_statements` opt-out; **Issue selected** with per-customer ticks; *By* column on Sent; export's negative credit notes; the six state labels (`Invoice::payment_state_label`) |
| 8 Customers page | pass — 92 lines. Checked: debounced search + **Status**; Delete only when `orders_count === 0`; `InvoiceDelivery` labels; billing-email hint; special-price panel labels; run form labels (`delivery-runs/partials/_form`); **Add stop**; "€ charge: on/off" |
| 9 Index and pointers | pass |

**Deviations**
1. `blur` option — **accepted**, and a good catch. Customers' emails and
   phone numbers do not belong in committed pictures.
2. `aside.h-screen` — accepted (the plan allowed narrowing).
3. `finance-record-payment` without **Continue** — accepted; the plan was
   wrong (the button is `<noscript>` only).
4. Statement preview for October — accepted.
5. `finance-consolidated` empty — accepted; the plan was wrong about a
   customer picker. A preview with content is deferred (note 6).
6. `finance-awaiting-invoice` fullPage — accepted.
7. Prose follows the views — accepted on all seven points; the plan's
   Context had **To invoice**/*Awaiting invoice*, **Credit note**, "Partial"
   and the consolidated picker wrong.
8. Step 3's check — accepted; equality before/after plus zero allocations
   on the cancelled orders is the better evidence.
9. Implementer on Fable — accepted; this review is a different session.

**Notes for Planner**
1. Floor can read the finance and customer pages — **fixed now**, step 10
   (and it applies to `orders.md`, shipped in 16b, whose pictures show order
   totals). Decided by the Planner on the app's existing rule (the floor
   never sees money); Jon can overrule and accept revision 1 as it stands.
2. Organic Store's email and address in pictures — **rejected**: it is
   Jon's own shop, used as the example on purpose; after step 10 only the
   office sees those pages. Cavistons' street address in *Billed to* is a
   shop's public address on an office-only page — left.
3. Driver first names on the run pictures — **accepted as is**: the floor
   sees the same names on the real run sheet.
4. Loaded-tick wording — **fixed now**, step 12.
5. Dev milk expires 2026-11-07 — **deferred to 16d**, which re-shoots
   anyway: its first step makes a sellable milk batch (in `demo-data.php`).
   Revision 2 re-shoots only the dashboard, which does not need milk.
6. Consolidated preview with content — **deferred to 16d** (a `GUIDE-E`
   weekly order taken to dispatched by the demo script; dispatch sends a
   confirmation, so it needs its own decision under `MAIL_MAILER=log`).
7. Stray "x" postcode and the old from-address in INV-000002 — **accepted**:
   true to the dev data, on an office-only page.
8. Four screen observations — **recorded** in `SMALL_FIXES.md` → *Display
   and consistency* (2026-11-05), for a later copy cycle.
9. `{GUIDE-X.number}` — accepted.
10. `check.mjs` — **rejected** for now: `HelpPagesTest` pins pages and
    pictures; a browser click-through stays a per-cycle check.

**Found by the Planner, not in the report**
- `dashboard-office.png` (on `getting-started.md`, a page for everyone,
  shipped in 16b) shows three shops' order values — step 11.
- Every re-shoot rewrites every office picture because of the top-bar
  clock (11 of 15 part-1 PNGs changed with no screen change; ~3 MB of new
  blobs per full re-shoot). **Deferred to 16d**: hide the clock for the
  capture so an unchanged screen gives an unchanged file.

### Revision 2 — reviewed 2026-11-07 (Planner; the Implementer was an Opus session)

**Verdict: ACCEPTED.** Steps 10–13 done as specified; the cycle is complete.

Rerun by the Planner: `php artisan test` → **820 tests, 0 failed** (5517
assertions); `./vendor/bin/pint --test app tests` → 233 files pass. Diff
read in full: `HelpController.php` (+47 −2), `HelpPagesTest.php` (+76),
`AuthorizationBoundaryTest.php` (fixtures), `screenshots.json`,
`getting-started.md`, `README.md`, `CLAUDE.md`. `dashboard-office.png`
opened: the three *Value* cells are blurred and nothing else is;
`dashboard.blade.php` has exactly one `<table>`, so the selector cannot
catch anything but the order queue.

| Step | Result |
|---|---|
| 10 Page audiences | pass — `AUDIENCE` as specified; `show()` 404 before 403; `image()` follows the readable pages' Markdown; the index filter is a single-line regex over the README table rows; `canRead()` fails closed on a missing key. The three tests cover classification, every page and picture for the floor/office/admin, and the index. Matrix fixtures moved to `picking` / `picking-queue.png` |
| 11 Dashboard blur | pass — one shot re-shot, proved by md5 (deviation 1); the other `all` pictures are €-free |
| 12 Loaded-tick wording | pass — sentence verbatim |
| 13 Pointers | pass — `CLAUDE.md` sentence verbatim; README has a *Who can read what* paragraph |

**Deviations (revision 2)**
1. md5 instead of the `git status` count — accepted; the plan's check was
   wrong (`dashboard-office.png` was already modified by revision 1).
2. The office/admin loop also covers README and asserts the office-only
   picture set is non-empty — accepted; both strengthen the test.

**Notes for Planner (revision 2)**
1. `Cache-Control: public, max-age=3600` on pictures — **deferred to 16d**:
   change to `private` (one word) so a shared office PC's cache cannot show
   an office picture to a floor login. Low risk now; cheap then.
2. *(to write)* rows and an `admin` audience — **accepted**; already on
   16d's list.
3. Clock churn limited to one picture — accepted.
4. Dev milk expired today — accepted; known, 16d's first step.

**Found by the Planner, not in the report**
- `getting-started.md` line 62 and `chilled-runs.md` line 72 (both `all`
  pages) link to `orders.md`, which is now office-only, so the floor gets
  the 403 page with its link home. Acceptable behaviour (that is what the
  403 page is for), but the links could say *(office)*. **Deferred to
  16d** as a one-line wording item.

Jon: archive `plan.md` + `implemented.md` to
`archive/2026-11-05-user-guide-part-2/`, then `ship-jon --no-maintenance`
(no migration).
