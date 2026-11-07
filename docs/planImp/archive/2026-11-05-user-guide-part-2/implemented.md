# Cycle 16c — User guide, part 2 — implementation

Status: DONE
Plan revision: 2
Implementer: Fable 5.1 (Jon started this session on Fable rather than Opus; the review still needs a different session)
Date: 2026-11-05

## Baseline
HEAD: 4fda1a7
Pre-existing dirty files:
```
 M docs/planImp/RESUME.md
?? docs/planImp/plan.md
```

## Steps

### 0. Baseline — done
Check output:
```
$ php artisan test
  Tests:    815 deprecated, 1 passed (5317 assertions)      # 816 tests, 0 failed
  Duration: 39.59s
$ ./vendor/bin/pint --test app tests
  PASS   ......................................................... 233 files
```

### 1. Help on the phone — done
Changed: `resources/views/picking/index.blade.php`, `tests/Feature/PickingTest.php`
The dashboard button and a new Help button (`route('help.show', 'picking')`,
`title="Help"`, `aria-label="Help"`, the `help` icon paths from
`navigation.blade.php`) now sit in one `flex items-center gap-2` container.
New test `test_the_queue_links_to_the_picking_help_page`.
Check output:
```
$ php artisan test --filter=PickingTest
  Tests:    28 deprecated (139 assertions)        # 27 → 28, 0 failed
```
The picture check is under step 6 (`picking-queue` re-shot).

### 2. Two wording fixes from 16b — done
Changed: `docs/guide/orders.md`, `docs/guide/getting-started.md`
Check output:
```
$ grep -n 'only status' docs/guide/orders.md
40:Confirm and cancel are the only status buttons until the order is picked. After that come **Loaded onto van**, **Mark dispatched** and **Mark delivered** (see *Send it out*).
$ grep -n 'Loaded onto van' docs/guide/getting-started.md
31:| Sales | **Orders**, **Chilled Runs** (*floor*: both — the floor can tick **Loaded onto van** on the run sheet, nothing else) |
$ php artisan test --filter=HelpPagesTest
  Tests:    6 deprecated (95 assertions)
$ grep -rn "Loaded onto van\|Mark dispatched\|Mark delivered" resources/views
resources/views/orders/show.blade.php:163:   Loaded onto van
resources/views/orders/show.blade.php:190:   Mark dispatched
resources/views/orders/show.blade.php:207:   Mark delivered
```
All three labels are on `orders/show.blade.php`. `_run-table` does **not**
carry the words "Loaded onto van" (see Notes for Planner).

### 3. `demo-data.php`: fresh path and a machine-readable result — done
Changed: `docs/guide/demo-data.php`, `.gitignore`
`GUIDE_FRESH` is read with `filter_var(getenv('GUIDE_FRESH'), FILTER_VALIDATE_BOOLEAN)`
(so `GUIDE_FRESH=0` is off). An open GUIDE order that `canBeCancelled()`
refuses (dispatched/delivered) is reported and left alone rather than forced.
Check output:
```
$ php artisan tinker docs/guide/demo-data.php
GUIDE-A  id=11  ORD-20261029-001  confirmed  2026-10-29  [1L Bottle=36, 2L Bottle=37, 500g Tub=38, Whole Wheel=39]
GUIDE-B  id=12  ORD-20261029-002  pending  2026-11-05  [1L Bottle=40, 250g Tub=41]
GUIDE-C  id=13  ORD-20261029-003  preparing  2026-11-03  [1L Bottle=42, 500g Tub=43, Whole Wheel=44]
GUIDE-D  id=14  ORD-20261029-004  pending  2026-11-03  [2L Bottle=45, Vacuum Pack=46]
Written /var/www/html/mossfield/docs/guide/demo-data.json

before:  freeStockByVariant [5=>18, 4=>556, 1=>5952, 2=>90]; M281026 1L remaining 5972; 4 GUIDE orders, 0 cancelled

$ GUIDE_FRESH=1 php artisan tinker docs/guide/demo-data.php
cancelled  GUIDE-A  id=11  ORD-20261029-001
cancelled  GUIDE-B  id=12  ORD-20261029-002
cancelled  GUIDE-C  id=13  ORD-20261029-003
cancelled  GUIDE-D  id=14  ORD-20261029-004
GUIDE-A  id=15  ORD-20261105-001  confirmed  2026-11-05  [1L Bottle=47, 2L Bottle=48, 500g Tub=49, Whole Wheel=50]
GUIDE-B  id=16  ORD-20261105-002  pending  2026-11-12  [1L Bottle=51, 250g Tub=52]
GUIDE-C  id=17  ORD-20261105-003  preparing  2026-11-10  [1L Bottle=53, 500g Tub=54, Whole Wheel=55]
GUIDE-D  id=18  ORD-20261105-004  pending  2026-11-10  [2L Bottle=56, Vacuum Pack=57]
Written /var/www/html/mossfield/docs/guide/demo-data.json

after:   freeStockByVariant [5=>18, 4=>556, 1=>5952, 2=>90]; M281026 1L remaining 5972; 8 GUIDE orders, 4 cancelled
```
Before and after are **equal**, not "up": one command cancels and re-creates,
and the new orders reserve and pick exactly what the old ones held. That
equality is the proof the cancel returned everything — had the 12 picked
1L bottles of old GUIDE-C not gone back to `M281026`, remaining would read
5960 after the new GUIDE-C picked its 12. The cancelled orders, checked
directly:
```
11 cancelled total=... allocs=0 alloc=0 ful=0 lines=4
12 cancelled           allocs=0 alloc=0 ful=0 lines=2
13 cancelled           allocs=0 alloc=0 ful=0 lines=3
14 cancelled           allocs=0 alloc=0 ful=0 lines=2
```
`git status --short` does not list `docs/guide/demo-data.json` (ignored).

### 4. Manifest placeholders — done
Changed: `docs/guide/screenshot.mjs`, `docs/guide/screenshots.json` (README in step 9)
`fillPlaceholders()` replaces `{GUIDE-X.id}`, `{GUIDE-X.date}`, `{GUIDE-X.number}`
and `{GUIDE-X.item.<variant name>}` in a shot's `url` from
`docs/guide/demo-data.json` (`GUIDE_DEMO_DATA` overrides the path). Nine URLs
rewritten; `/orders/5`, `/picking/5` and the four non-order URLs stay literal.
Check output:
```
$ grep -n '"url"' docs/guide/screenshots.json
43:      "url": "/orders/{GUIDE-B.id}",
51:      "url": "/orders/{GUIDE-A.id}",
60:      "url": "/orders/5",
69:      "url": "/chilled-runs?run=1&date={GUIDE-C.date}",
77:      "url": "/chilled-runs?run=1&date={GUIDE-C.date}&edit=23",
84:      "url": "/picking?date={GUIDE-A.date}",
92:      "url": "/picking/{GUIDE-A.id}",
100:      "url": "/picking/{GUIDE-A.id}/items/{GUIDE-A.item.1L Bottle}",
108:      "url": "/picking/{GUIDE-A.id}/items/{GUIDE-A.item.Whole Wheel}",
116:      "url": "/picking/5",
124:      "url": "/picking/{GUIDE-C.id}",
$ node docs/guide/screenshot.mjs picking-queue
✓ docs/guide/images/picking-queue.png
   # opened: "THURSDAY · 5 NOV / Today", 0 ready · 0 picking · 1 to do,
   # Organic Store ORD-20261105-001 · 44 items · To do; two round buttons
   # (Help ?, Dashboard) top right
$ mv docs/guide/demo-data.json <scratchpad>/ ; node docs/guide/screenshot.mjs picking-queue
✗ picking-queue: placeholder {GUIDE-A.date}: docs/guide/demo-data.json is missing — run "php artisan tinker docs/guide/demo-data.php"
exit=1
   # file restored afterwards
```

### 5. `before` actions and the full-page sidebar — done
Changed: `docs/guide/screenshot.mjs`, `docs/guide/screenshots.json`
`runBefore()` handles `select`/`value`, `type`/`text`, `click`, `waitFor`
(visible), `wait`; anything else throws `before: unknown action {…}` (the
loop's catch prefixes the shot name). A `select` whose value matches no
option is also an error. After the actions the script waits for the network
to go idle. `fullPage` shots get
`aside.h-screen { position: static; height: auto; min-height: 100% } (!important)`
— narrowed from the plan's bare `aside` because the product pages have a
second, inner `<aside>` (the sibling list) that must keep its `max-height`.
`order-new-form` → `before`: select customer 25 (Organic Store), select
variant 5 (Farmhouse Whole Wheel) in the first row, type quantity 2. No "Add
item" click is needed: the form starts with one empty row.
Check output:
```
$ node docs/guide/screenshot.mjs order-new-form
✓ docs/guide/images/order-new-form.png
   # opened: Customer "Organic Store"; under Delivery date "Wicklow Thu · every
   # Thursday — next run date filled in"; line "Farmhouse Cheese · Whole Wheel",
   # qty 2, "~€160.00 · est. 10 kg"; Items ~€160.00, Total ~€160.00 and the
   # "~ Estimated from nominal weights" note; sidebar full height
$ node docs/guide/screenshot.mjs order-page-ready
✓ docs/guide/images/order-page-ready.png
   # opened: sidebar reaches the bottom of the image — Miscellaneous,
   # Production, Help groups and the user card all visible
```

### 6. The part-2 shot list — done (with deviations, see below)
Changed: `docs/guide/screenshots.json`, `docs/guide/screenshot.mjs` (the `blur` option), `docs/guide/images/*.png` (15 re-shot, 14 new)
Check output:
```
$ node docs/guide/screenshot.mjs
✓ … 29 lines, all ✓ …
exit=0
$ du -sh docs/guide/images ; largest
5.7M   docs/guide/images          (29 files)
414582 customer-page.png          # largest; nothing over 500 KB, no scale dropped
377808 chilled-runs-dublin.png
357936 order-page-ready.png
```
Every new PNG opened with Read. What each shows:

| picture | what is in it |
|---|---|
| finance-invoices | tab bar (Invoices active); tiles *Not yet emailed* 2 to send, *Awaiting invoice* 2 to bill, *Overdue* €0.00; filters Customer / Payment / Emailed / Status + **Filter**, Clear; INV-000003 Roundwood (Consolidated tag, Paid, Not sent) and INV-000002 Cavistons (Part paid, Not sent) |
| finance-invoice-partial | INV-000002: **View PDF**, **Download**, **Email invoice**, **Credit note**, a greyed **Void** with "Can't void — remove its payments (1) and credit note (CN-000001) first."; Lines (a "Not supplied — not charged." line), Payments, Credit notes, Email history (one Failed), Summary (Paid, Credited, Balance due), Billed to, **Record a payment**. No **Send reminder** — it only shows on an overdue invoice. Email addresses blurred |
| finance-awaiting-invoice | *Per-order billing (2)* with **Issue invoice** per row and **Issue all 2**; *Awaiting a consolidated run (0)* with **Run consolidated invoices**; *Picked, awaiting dispatch (2)* with a Loaded column and no buttons |
| finance-record-payment | Cavistons chosen; *Apply to invoices* lists INV-000002 with 50.00 filled in the Apply box, Allocated €50.00, Left on account €0.00; Payment panel (Amount received 50, Date received, Method, Reference, Notes); **Auto-fill oldest first**, **Record payment** |
| finance-overdue-empty | "Nothing overdue. Every issued invoice is either paid or still within terms." |
| finance-statements | **Month-end run**; Customer filter; one row, Cavistons 1 Oct – 28 Oct, Not sent, PDF |
| finance-statement-preview | Cavistons, 1–31 Oct: From/To + **Update**; *Account activity* with **Generate** / **Generate & email**; Balance brought forward, Invoice, Credit note, Payment received, Balance due; *Aged breakdown* (Not yet due … 90+ days, Credit, Net owed); *Statements sent* |
| finance-consolidated | From/To, **Preview period**, Last month / This month / Last week / This week; the empty state "No consolidated-billing customers have uninvoiced deliveries between 1 Oct 2026 and 31 Oct 2026." |
| finance-sent | filters Document / Status / Customer + **Filter**; four rows (one invoice and two dispatch confirmations Failed with the reason, one Sent). The *Sent to* column is blurred |
| finance-export | two panels, *Invoices & credit notes* and *Payments*, each From/To + **Download CSV** |
| customers-list | Search box, Status select, **Search**, **+ Add customer**; Name / Contact / Location / Credit limit ("No limit" for Barnhill) / Orders / Status; View · Edit (· Delete where there are no orders). The *Contact* column is blurred |
| customer-page | Organic Store: Basic information, Address, Business terms; *Account* with **Record payment** and **Statement** (no **Apply credit** — it has no credit); Orders (6); *Special prices (8)* with Save / Remove and the add row. No *Recent invoices* or *Documents sent* panel — Organic Store has neither |
| customer-form | the empty *Add a customer* form, all four groups, hints under Email, Credit limit, Billing email, Invoice delivery, Invoicing, Delivery charge (%) |
| delivery-runs | Dublin Tue (15 stops, position boxes, ↑ ↓ ×, **Save order**) and Wicklow Thu (Organic Store); **Show charge settings**, **+ New run**, Edit / Delete per run. No add-a-stop control: every customer is already on a run |

Re-shot part-1 pictures checked by eye: `picking-queue` (step 4),
`order-new-form` and `order-page-ready` (step 5), `chilled-runs-dublin`
(Tue 10/11/2026, Cavistons 12/19 picked `Ref: GUIDE-C`, Roundwood Pending
`Ref: GUIDE-D`, sidebar full height) and `picking-order-partly` (Cavistons
ORD-20261105-003, 1 of 3 lines picked). The other ten returned HTTP 200
from the placeholder URLs and were not opened.

### 7. Finance page — done
Changed: `docs/guide/finance.md` (new; looked first, it did not exist)
129 lines (limit 140). Sections as planned, in the plan's order, with the
headings matching the screens. No € figures in the prose (the one `€` is the
label **Amount received (€)**). Written from the views read in full
(`invoicing/**`) and `CLAUDE.md` → *Invoicing & Statements*, then checked
against the ten pictures.
Check output:
```
$ php artisan test --filter=HelpPagesTest
  Tests:    6 deprecated (161 assertions)         # 95 → 161 assertions: two more pages, 14 more pictures
$ bold-label grep (every **…** against resources/views, app/View, app/Enums, app/Models/Invoice.php)
  0 hits: five emphasis phrases that are not labels ("An invoice is a frozen copy." …),
          "Generate & email" and "Generate & queue" (written `&amp;` in the views — present)
  ≥1 hit: all 59 others (66 distinct bold strings)
$ headless Chrome as office_test: /help → click "Finance"
office  http://mossfield.local/help/finance.md  h1="Finance"  images 10/10 loaded
office console errors: none
```
Where the page follows the view rather than the plan's wording — see Deviations.

### 8. Customers & delivery runs page — done
Changed: `docs/guide/customers.md` (new; did not exist)
92 lines (limit 110). Sections: Find a customer; Add or change a customer;
The customer page (+ Special prices); Delivery runs (Set up a run, Stops,
Who pays the delivery charge).
Check output:
```
$ bold-label grep: 0 hits only for the group heading "Basic information and Address"
  (two real headings joined); every other label ≥ 1 hit
$ headless Chrome as office_test: /help → click "Customers and delivery runs"
office  http://mossfield.local/help/customers.md  h1="Customers and delivery runs"  images 4/4 loaded
        guide links on page: /help/finance.md /help/chilled-runs.md
```

### 9. Index and pointers — done
Changed: `docs/guide/README.md`, `CLAUDE.md`
README table links Finance and Customers and delivery runs; "Production and
stock" and Admin stay *(to write)*. *Screenshots* now covers
`demo-data.json`, the placeholders, `GUIDE_FRESH=1`, `before` and `blur`.
`CLAUDE.md` *Key Docs* bullet carries the plan's sentence verbatim.
Check output:
```
$ php artisan test --filter=HelpPagesTest
  Tests:    6 deprecated (161 assertions)
```

## Deviations

1. **`blur` — a fourth screenshot option the plan did not ask for.** Three of
   the new pictures showed customers' contact details in the clear:
   `customers-list` (every shop's email and phone number, including personal
   Gmail addresses and a mobile number), `finance-sent` (the *Sent to*
   column) and `finance-invoice-partial` (the billing email, twice). The
   plan's risk note accepts shop **names** and shop **money**; it says
   nothing about addresses and phone numbers, `customers.phone` is an
   encrypted column, the PNGs are committed by `ship-jon`, and `/help` is
   open to the factory role. So `screenshot.mjs` takes a per-shot
   `"blur": [css, …]` and blurs those elements for the capture only
   (`filter: blur(7px)`; a selector that matches nothing fails the shot).
   Applied to those three shots. Undo by deleting the three `blur` keys and
   re-running. Not blurred: Organic Store's own email and address on
   `customer-page` (the same details are already in 16b's shipped order
   pictures) and Cavistons' street address in *Billed to* — the Planner or
   Jon may want those blurred too.
2. **`aside.h-screen`, not `aside`**, for the full-page sidebar rule (step 5
   allowed narrowing): the product pages carry a second `<aside>`.
3. **`finance-record-payment`**: there is no **Continue** button for a
   browser with JavaScript — it sits inside `<noscript>`, and the customer
   select submits on change. The `before` is select Cavistons → wait for
   `#amount` → type 50, so the picture shows the oldest-first auto-fill
   (Apply 50.00, Allocated €50.00, Left on account €0.00). Nothing is
   submitted. Also shot `fullPage` (the form is taller than 800 px).
4. **`finance-statement-preview`** uses `?from=2026-10-01&to=2026-10-31`. The
   default period (1–5 Nov) reads "No activity in this period"; October
   shows the invoice, credit note and payment lines the plan wanted.
5. **`finance-consolidated` is the empty state.** The screen has no customer
   picker (only From / To and four period links), and Roundwood — the one
   weekly customer — has no dispatched, uninvoiced order (order 10 is on
   INV-000003). The plan allowed "else the empty form". `finance.md`
   describes the preview from the view.
6. **`finance-awaiting-invoice` is `fullPage`** so all three sections are in
   the picture (the plan's note: "both sections").
7. **Prose follows the views where they differ from the plan's Context:**
   - the sidebar/tab label is **To invoice**; *Awaiting invoice* is the page
     heading. The page section is titled "To invoice" and names both;
   - the invoice page's button is **Credit note**; **Issue credit note** is
     the submit on the form it opens;
   - state labels are **Part paid** and **Credited**, not "Partial"; the
     states table has six rows;
   - the consolidated screen picks a period, not a customer;
   - the statements month-end submit is **Generate & queue** and it offers
     "customers with a balance" or "customers with any activity";
   - the customer list has no online filter (the Mossorders flag is off) and
     its filter is **Status**;
   - **Add stop** / **Add** only renders when a customer has no run — on the
     dev box every customer has one, so it is not in the picture and the
     page says so.
8. **Step 3's "free stock is back up" check** cannot be observed from one
   command (cancel and re-create are the same run); equality before and
   after, plus the four cancelled orders holding zero allocations, is the
   evidence. See step 3.
9. **Implementer model**: this session ran on Fable 5.1, not Opus (Jon's
   choice at kickoff). The review must still come from a different session.

## Verification
1. `php artisan test` →
   ```
   Tests:    816 deprecated, 1 passed (5387 assertions)      # 817 tests, 0 failed
   Duration: 36.68s
   ```
2. `./vendor/bin/pint --test app tests` → `PASS … 233 files`.
3. `node docs/guide/screenshot.mjs` → exit 0, 29 ✓. `docs/guide/images` 5.7 MB,
   29 files, largest `customer-page.png` 414 KB.
4. `php artisan tinker docs/guide/demo-data.php` (no fresh) →
   ```
   GUIDE-A  id=15  ORD-20261105-001  confirmed  2026-11-05  [1L Bottle=47, 2L Bottle=48, 500g Tub=49, Whole Wheel=50]
   GUIDE-B  id=16  ORD-20261105-002  pending  2026-11-12  [1L Bottle=51, 250g Tub=52]
   GUIDE-C  id=17  ORD-20261105-003  preparing  2026-11-10  [1L Bottle=53, 500g Tub=54, Whole Wheel=55]
   GUIDE-D  id=18  ORD-20261105-004  pending  2026-11-10  [2L Bottle=56, Vacuum Pack=57]
   Written /var/www/html/mossfield/docs/guide/demo-data.json
   ```
   Nothing created.
5. `git status --short` → `demo-data.json` absent. Application changes are
   `resources/views/picking/index.blade.php` and `tests/Feature/PickingTest.php`
   only; no migrations. (`GET /help/images/demo-data.json` → 404, as it should.)
6. Browser — done with headless Chrome driving real clicks (a scratch
   puppeteer script, not committed), not by a person:
   ```
   office  /help → click "Finance"                      → /help/finance.md    h1 "Finance"  images 10/10 loaded
   office  /help → click "Customers and delivery runs"  → /help/customers.md  h1 "Customers and delivery runs"  images 4/4 loaded
   office console errors: none
   factory signs in → lands on /picking; click the round Help button → /help/picking  h1 "Picking"  images 6/6 loaded
   factory console errors: none
   ```
   Jon's own look at the two pages in a real browser is still worth doing —
   reading order and wording are a human judgement.

## Files changed
```
 M .gitignore
 M CLAUDE.md
 M docs/guide/README.md
 M docs/guide/demo-data.php
 M docs/guide/getting-started.md
 M docs/guide/images/*.png            (11 of the 15 part-1 pictures changed; 4 re-shot byte-identical)
 M docs/guide/orders.md
 M docs/guide/screenshot.mjs
 M docs/guide/screenshots.json
 M docs/planImp/RESUME.md             (pre-existing, the Planner's)
 M resources/views/picking/index.blade.php
 M tests/Feature/PickingTest.php
?? docs/guide/customers.md
?? docs/guide/finance.md
?? docs/guide/images/{customer-form,customer-page,customers-list,delivery-runs}.png
?? docs/guide/images/finance-{awaiting-invoice,consolidated,export,invoice-partial,invoices,overdue-empty,record-payment,sent,statement-preview,statements}.png
?? docs/planImp/implemented.md
?? docs/planImp/plan.md               (pre-existing, the Planner's)
```
Ignored, on disk: `docs/guide/demo-data.json`.

Dev database: GUIDE orders 11–14 cancelled (stock returned); new GUIDE-A…D
are ids 15–18 (ORD-20261105-001…004), holding the same reservations and
the same 12-bottle pick as before. Nothing else touched; no email sent, no
invoice, payment, credit note or statement created.

## Notes for Planner

1. **Factory can read `/help/finance` and `/help/customers`** (200; role
   filtering is out of scope by the plan). Those pages' pictures show named
   shops' invoice totals, balances, credit limits and special prices — the
   one place the floor can see money the app otherwise hides from it
   (`see-financials`, `FinancialPolicy`). Worth a decision before or at
   ship: accept, or gate the finance/customer pages and their images to
   office/admin.
2. **Contact details in part-1 pictures**: `order-page-*` (16b, shipped)
   show Organic Store's email and street address under *Customer*. Left
   alone; the `blur` option would cover them if wanted.
3. **Driver first names** ("Stuart", "Pat") are in `delivery-runs`,
   `chilled-runs-dublin` and `chilled-runs-enter-order` (the last two since
   16b). The plan's staff-name constraint was about the users page; flagging
   in case first names on a run count.
4. **`getting-started.md` wording from step 2** — "the floor can tick
   **Loaded onto van** on the run sheet, nothing else" — is the plan's text,
   used verbatim, but (a) the words "Loaded onto van" are only on the order
   page; the run sheet's tick is an unlabelled box titled "Mark loaded" under
   a *Loaded* bar, and (b) the floor can also tick it on the order page
   (`@can('load')`). Suggested: "the floor can tick a stop as loaded, on the
   run sheet or the order page, and nothing else".
5. **The dev box's milk expires on 2026-11-07** (`M281026`, the only
   sellable milk). From then a `GUIDE_FRESH=1` run will print
   "! GUIDE-A: 20 short on 1L Bottle" and the milk pick for GUIDE-C will
   throw. 16d's pictures (and any re-shoot after Saturday) need a fresh milk
   batch first — `demo-data.php` could make one, or the plan could say to
   create it in the app.
6. **A consolidated preview with something in it** needs a weekly
   customer's dispatched, uninvoiced order. A `GUIDE-E` order for Roundwood
   taken through pick → loaded → dispatch by the demo script would give the
   picture content, but dispatch sends a confirmation (log mailer) and the
   plan ruled out pressing it this cycle.
7. **`finance-invoice-partial` shows a stray "x"** in *Billed to* (the
   placeholder postcode, frozen into INV-000002's snapshot before cycle 15
   blanked it), and the *Email history* row quotes the dev `.env`'s old
   placeholder from-address. Both are true to the dev data; a fresh invoice
   would look cleaner.
8. **Screen observations while writing (not touched):**
   - the sidebar says **To invoice**, the page says *Awaiting invoice*, the
     Invoices tile says *Awaiting invoice* — three names for one list;
   - on the invoice page the summary row reads *Tax* when there is no
     delivery charge and *VAT (23%)* when there is;
   - *Sent documents*' subtitle lists "docket" but not "dispatch
     confirmation", which is most of what is in the list;
   - the customer list keeps a **Search** button although the box filters as
     you type and the select submits on change.
9. **`{GUIDE-X.number}`** is supported as a placeholder though no shot uses
   it yet (it came free with the lookup).
10. The scratch click-through script lives in the session scratchpad only.
    If the Planner wants verification 6 repeatable, it is ~50 lines and could
    become `docs/guide/check.mjs`.

## Revision 2

Implementer: Opus 5.5 (a different session from revision 1's and from the Planner's)
Date: 2026-11-07

### Baseline
HEAD: 4fda1a7 — tree as revision 1 left it (nothing committed since).

### 10. Page audiences — done
Changed: `app/Http/Controllers/HelpController.php` (public `AUDIENCE`; `show()` 404 then 403; `image()` 403 unless a readable page's Markdown contains `images/<file>`; `index()` drops README table rows linking to an unreadable page; `canRead()` = audience `all` or `Gate::allows('see-financials')`, missing key ⇒ `office`), `tests/Feature/HelpPagesTest.php` (three new cases as specified; the office/admin loop also requests README's pictures — none), `tests/Feature/AuthorizationBoundaryTest.php` (help fixtures → `picking` / `picking-queue.png`).
Check output:
```
$ php artisan test --filter='HelpPagesTest|AuthorizationBoundaryTest|PickingTest'
  Tests:    58 deprecated (1912 assertions)          # 58 tests, 0 failed ("deprecated" = the baseline PDO::MYSQL_ATTR notice)
$ php artisan test --filter=HelpPagesTest
  9 tests incl. every guide page has an audience / the floor cannot open office pages or their pictures / the floor index lists only its pages
  Tests:    9 deprecated (291 assertions)
$ ./vendor/bin/pint --test app tests
  PASS   ......................................................... 233 files
```

### 11. No prices on a page everyone can read — done
Changed: `docs/guide/screenshots.json` (`dashboard-office` gains `"blur": ["table tbody td:nth-child(3)"]` — the dashboard has one `<table>`, the order queue, and *Value* is its third column, rendered only for admin/office), `docs/guide/images/dashboard-office.png` (re-shot, that shot only), `docs/guide/README.md` (one sentence before "To add a picture").
Check output:
```
$ node docs/guide/screenshot.mjs dashboard-office
✓ docs/guide/images/dashboard-office.png        # exit 0
$ md5sum docs/guide/images/*.png | diff - <md5 taken before the shot>
6c6  dashboard-office.png                       # the only picture whose bytes changed
$ git status --short docs/guide/images | wc -l
25                                              # same as before: dashboard-office.png was already M from revision 1's re-shoot
$ php artisan test --filter=HelpPagesTest → 9 tests, 0 failed
```
Opened `dashboard-office.png`: the three *Value* cells of the order queue (An Tairseach, Organic Store, Roundwood) are blurred, unreadable; nothing else is blurred — the *Overdue* tile's €0.00 stays, as the plan says. The picture also picks up today's data (7 Nov; 2 to confirm, 1 to allocate, Expired stock 4 / 10,398 units now that `M281026` has expired).
Other `all`-page pictures opened: `sidebar-help` (nav only, no figures), `chilled-runs-dublin` and `chilled-runs-enter-order` (quantities, crates, stock counts; no `€` anywhere). The six `picking-*` pictures are shot as `factory` (manifest `as`), so the existing factory € redaction applies to them.

### 12. The loaded-tick wording — done
Changed: `docs/guide/getting-started.md` (Sales row, the plan's sentence verbatim).
Check output:
```
$ grep -n 'tick a stop as loaded' docs/guide/getting-started.md | wc -l
1
$ php artisan test --filter=HelpPagesTest → 9 tests, 0 failed
```

### 13. Pointers — done
Changed: `CLAUDE.md` (*Key Docs* → `docs/guide/` bullet: the plan's sentence verbatim), `docs/guide/README.md` (a "Who can read what" paragraph above *Screenshots*: floor sees the everyone pages; money and customer pages, and the pictures only they use, are office; a new page must be added to `HelpController::AUDIENCE`, the tests fail until it is).
Check output:
```
$ php artisan test --filter=HelpPagesTest → Tests: 9 deprecated (291 assertions)   # 9 tests, 0 failed
```

### Deviations (revision 2)
1. Step 11's check ("exactly one more changed picture than before" in `git status --short docs/guide/images`): the count stayed at 25 because `dashboard-office.png` was already modified by revision 1's full re-shoot. Proved instead with an md5 of every PNG before and after: only `dashboard-office.png` changed.
2. Step 10, the office/admin loop in `test_the_floor_cannot_open_office_pages_or_their_pictures` also covers README (index route, not `/help/README`). No pictures are referenced there. It also asserts that at least one picture is office-only, so the test can't pass on an empty set.

Otherwise none.

### Verification (revision 2)
1. `php artisan test` → `Tests: 819 deprecated, 1 passed (5517 assertions)` = **820 tests, 0 failed** (817 + 3).
2. `./vendor/bin/pint --test app tests` → `PASS … 233 files`.
3. Headless Chrome (puppeteer-core, script in the session scratchpad, not in the repo) as `factory_test`: login lands on `/picking`; the round Help button (`a[href$="/help/picking"]`) is there; clicking it opens `/help/picking`, all six pictures loaded (`naturalWidth > 0`); `/help` links only `getting-started.md`, `chilled-runs.md`, `picking.md`; `/help/finance` → **403**, title "No access · Mossfield"; `/help/images/finance-invoices.png` → **403**. The only console errors were those two deliberate 403 loads.
4. As `office_test`: `/help` links six pages (getting-started, orders, chilled-runs, picking, finance, customers); `/help/finance.md` → 200 with all 10 pictures loaded; `/help/customers.md` → 200 with all 4 loaded; no console errors.
5. `git status --short`: application changes are `app/Http/Controllers/HelpController.php`, `resources/views/picking/index.blade.php` and `tests/Feature/{AuthorizationBoundaryTest,HelpPagesTest,PickingTest}.php`; no files under `database/migrations`; `demo-data.json` absent (gitignored). `git diff --stat -- app resources routes tests` → 5 files, +150 −8.

### Files changed (revision 2)
`app/Http/Controllers/HelpController.php`, `tests/Feature/HelpPagesTest.php`, `tests/Feature/AuthorizationBoundaryTest.php`, `docs/guide/screenshots.json`, `docs/guide/images/dashboard-office.png`, `docs/guide/README.md`, `docs/guide/getting-started.md`, `CLAUDE.md`, `docs/planImp/implemented.md`. Everything else in `git status` is revision 1 or the Planner's.

### Notes for Planner (revision 2)
1. `image()` reads the readable pages' Markdown on every picture request: seven files, as the plan allowed. The pictures send `Cache-Control: public, max-age=3600`. On a shared browser (an office PC that the floor also signs in to) a cached office picture could be shown to a floor user for up to an hour without a new request. Low risk; `private` would close it. Not changed: the plan didn't name it.
2. The index filter drops README rows that link to a page; the two *(to write)* rows have no link, so the floor still sees "Production and stock *(to write)*" and "Admin *(to write)*". When 16d writes them, they need `AUDIENCE` entries (the test will force it). "Admin" should probably be `office` or narrower: admins pass `see-financials`, and so does office.
3. Revision 2 changed only the dashboard picture, so the clock churn (16d) is limited to it.
4. Dev milk `M281026` expired today (2026-11-07), as noted in revision 1. The dashboard picture now shows Expired stock 4 / 10,398 units. Harmless, and 16d re-shoots it anyway.
