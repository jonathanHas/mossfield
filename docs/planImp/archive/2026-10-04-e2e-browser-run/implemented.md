# End-to-end browser run: batches → orders → picking → dispatch → invoicing — implementation

Status: DONE
Plan revision: 1
Implementer: Opus
Date: 2026-10-04

## Baseline
HEAD: 5f62755 (plan said `fd879b9` + staged archive; the Planner has since
committed the archive as `5f62755`, so the tree is **clean** — no staged renames)
Pre-existing dirty files: clean
`ls public/hot` → No such file or directory
`.env`: `APP_ENV=local`, `MAIL_MAILER=log` (confirmed with `config:show mail.default` → `log`),
`QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`, `SYNC_SCHEDULE_ENABLED=false`.

Site used: `php artisan serve --host=127.0.0.1 --port=8000`, not
`http://mossfield.local`. My browser rules only allow typing test credentials
on localhost/127.0.0.1/`.test`, and `.local` isn't on that list. Same code,
same DB, same `public/build`.

Background processes (stopped in step 11):
- `php artisan queue:listen --tries=1` (pid 381629)
- `php artisan serve --host=127.0.0.1 --port=8000` (pid 381633)
- Log: grep `storage/logs/laravel.log` (39 545 lines at start) instead of `tail -f`.

DB baseline:
```
{"orders":10,"batches":8,"customers":16,"invoices":3,"payments":0,"credit_notes":0,
 "statements":0,"document_emails":2,"jobs":2,"failed_jobs":0,"invoice_seq":4}
```
**`jobs` was 2 at baseline.** These were two `SendDocumentEmail` jobs queued on
2026-09-12 13:13 (document_emails #2 and #3: dispatch confirmations for orders
from the 2026-09-12 run, batch `86a37f84…`). Nothing had run them, because dev
has no worker or scheduler cron. The listener processed both on start
(`DONE` ×2), so their rows now read `status=sent, sent_at=2026-10-04 13:09`,
three weeks after dispatch. Mail is the `log` driver, so nothing left the
machine. This is the "Queue" risk the plan names, not a finding. It does show
that until a worker runs, the run-level confirmations don't go out.

## Chrome setup
- New tab in a fresh MCP group. First check: `visibilityState: "hidden"`,
  though tool clicks did arrive with `isTrusted: true`. Per the plan I stopped
  and asked Jon to bring the window forward. After that: `visible`,
  `hasFocus() true`, trusted clicks logged.
- **Phone width:** `resize_window 390×844` had no effect while the window was
  maximised. After Jon restored it, the smallest viewport Chrome allows is
  **500 × 659** CSS px (window minimum width). Phone-width phases therefore run
  at **500 px, not 390**, and findings say so.
- Desktop phases: 1440×900 window ⇒ 1408 × 715 viewport.
- Screenshot frames are scaled from the viewport. Clicks use element refs or
  screenshot-frame coordinates.

## Steps

### 0. Prepare and baseline — done
See Baseline above. `ls public/hot` → no such file. Listener and server running.
Trusted-click check: pointerdown logger → `[877,767,true,"DIV"]`.

### 1. Sign in as office; first impressions — done
- `/login`: autofocus on the username; Tab → password; Enter submits; wrong
  password → "These credentials do not match our records." under the username,
  username kept (F-04: focus returns to the username). **Remember me** present.
  Laravel logo (F-03).
- Landed on **/dashboard**: "Good afternoon, Office Tester. All orders
  allocated. Online sync is behind." Amber banner "Online order sync has not
  completed successfully yet" (known: online orders need the mossorders host).
  Tiles: Awaiting invoice 3 · Invoices not emailed 3 · Overdue €557.67 (2) ·
  Unallocated 0 · Ready to ship 3 · Batches maturing 0 "8 ready now" (F-01) ·
  Active products 7/7 · Low stock "not configured" · Customers 16/16.
  **Nothing tells the office there is no milk to sell** (F-02).
- Sidebar walk (desktop 1408). Server response / DOMContentLoaded in ms:
  /orders 63/263 · /chilled-runs 87/365 · /online-orders 45/156 ·
  /invoicing 50/135 · /invoicing/uninvoiced 50/157 · /invoicing/payments 54/189 ·
  /invoicing/statements 80/172 · /invoicing/overdue 34/114 · /picking 36/123 ·
  /order-allocations 30/108 · /delivery-runs 98/282 · /customers 48/269 ·
  /products 65/241 · /batches 71/206 · /cheese-cutting 51/163 ·
  /cheese-conversion 40/232 · /profile 60/204 · /stock and /dashboard similar.
  **Nothing near 2 s; console clean on every page.**
- Sidebar collapse/expand via the hamburger works and persists in
  `localStorage['sidebar-collapsed']`. Mobile drawer at 500 px opens from the
  hamburger, scrolls, closes with ×; no horizontal page scroll (scrollWidth
  485 < 500). Stray topbar divider at 500 px (F-05).
- `/stock`: milk card reads "No active milk batches." The expired state is
  not visible; milk just vanishes (F-02). Total stock value €7,689.
  Yoghurt shows only Y230726 (no expiry). Expired Y220626 (1 470 units) is
  invisible too.

Probe (read-only, for F-01/F-02):
```
16 M220626 milk status=active exp=2026-07-02 ready=- remaining=2610
17 G030426 cheese status=active exp=2028-04-03 ready=2026-06-02 remaining=80
18 Y220626 yoghurt status=active exp=2026-07-12 ready=- remaining=1470
19 M230726 milk status=active exp=2026-08-02 ready=- remaining=951
20 Y230726 yoghurt status=active exp=- ready=- remaining=514
21 M230726-1 milk status=active exp=2026-08-02 ready=- remaining=246
22 M070926 milk status=active exp=2026-09-17 ready=- remaining=630
23 G030426-1 cheese status=active exp=- ready=2026-08-31 remaining=4
```
Findings: F-01 … F-05.

### 2. Production: batches — done
Created (office_test, desktop, real input; product chosen with keyboard on the native select):
1. **M041026** (id 24): 1L × 240, 2L × 96, raw 500 L, notes tag. Expiry
   auto-filled **14/10/2026** ✓ the moment Milk was chosen. The 10L Churn row
   sits there blank. That's harmless (blank = not produced) but it's a third
   box to tab past every day. Yield shows 100 % (F-06).
2. **Y041026** (id 25): 250g × 120, 500g × 60, raw 60 L (the plan gave no
   figure; 60 = the finished kg). Expiry stays blank with no warning that the
   batch will never expire; the batch page has no expiry row (F-07).
3. **G010426** (id 26): Whole Wheel × 12, production **01/04/2026** typed into
   the date field. Past date accepted; amber banner "ready to sell on
   31/05/2026"; only the Whole Wheel row shown, with a note that packs come
   from cutting ✓. Raw 600 L (plan gave none). No collision. Batch page:
   "~60.00 kg · 0 of 12 wheels weighed · Yield ~10.0% (nominal)". That's
   meaningful as nominal and honestly labelled.

Break attempts:
- Second milk batch today, 1L × 12, submitted with **Enter** → **M041026-1** (id 27) ✓.
- Expiry 01/10 before production 04/10 → refused (top flash + inline) ✓,
  but quantities lost and expiry overwritten (F-08).
- All quantities blank → flash "You must specify production quantities for
  at least one product variant." ✓ (flash only, no inline marker).
- 1L = 0 → same refusal ✓. 1L = −5 → blocked by the browser's own `min=0`
  bubble "Value must be greater than or equal to 0." ✓ (native validation
  bubble, not a dialog).
- 3 000-wheel batch → **accepted** (F-09); deleted via UI (in-app dialog,
  danger button) — copy nit in F-11.
- Product list offers Mature for a fresh batch (F-10).

`/batches`: expired batches carry an **"Expired"** tag (good; better than
/stock). G010426 card strip = **12 yellow circles, "12 free / 12"** ✓;
G030426 "Matured into G030426-1" and G030426-1 "Matured from G030426" ✓.
Each batch page opened (24–27) ✓.
`/stock` milk card: 1L M041026 240 · 1L M041026-1 12 · 2L M041026 96,
all "Exp 14/10" ✓ (matches entry). Yoghurt Y041026 120/60 ✓. Cheese G010426
12 ✓. Total stock value €7,689 → €10,245.

Probe:
```
24 M041026 exp=2026-10-14 ready=-
   1L Bottle produced=240 remaining=240
   2L Bottle produced=96 remaining=96
25 Y041026 exp=- ready=-
   250g Tub produced=120 remaining=120
   500g Tub produced=60 remaining=60
26 G010426 exp=- ready=2026-05-31
   Whole Wheel produced=12 remaining=12
27 M041026-1 exp=2026-10-14 ready=-
   1L Bottle produced=12 remaining=12
batch 28 exists? false
```
Check: codes, expiry and quantities match what I typed ✓; cheese
`ready_date` 2026-05-31 is in the past ✓.
Console clean throughout. Findings: F-06 … F-11.

### 3. Cheese: cut a wheel, hold and release to mature — done
- `/cheese-cutting`: G010426 listed "Ready to cut", 12 free ✓. Expanded →
  "Whole Wheel · 12 wheels available · Cut wheel" → `/cheese-cutting/cut/46`.
  Pack type select has one option "Vacuum Pack (0.250kg each)". Typing 18
  packs auto-fills total 4.500 with a live "Trim / waste" ✓.
- **Break attempts first, then the real cut**, so an accidental acceptance
  couldn't throw off the wheel counts:
  - packs 5.500 > wheel 5.120 → client shows trim **−0.380** in red, but Cut
    wheel stays enabled; server refuses "The packs cannot weigh more than the
    wheel they came from." (top + inline) ✓.
  - 60 packs, total 4.680 → "60 packs at 0.250 kg each is 15.000 kg — more
    than the 5.120 kg wheel. At most 20 packs can come off it." ✓ (very good message)
  - cut date 01/03/2026 → "The cut date cannot be before the batch was produced (2026-04-01)." ✓
  - cut date 05/10/2026 → "The cut date field must be a date before or equal to today." ✓
  - Every refusal kept date, pack type, wheel weight and pack count, but
    **reset the measured total weight to nominal** (F-12, S2).
- Real cut: 5.120 kg wheel, 18 packs, 4.680 kg, today, notes tag → "Cheese
  wheel cut successfully! Created 18 vacuum packs." Strip: 11 free · 1 cut;
  packs 18/18. Batch page: **"Trim loss 0.440 kg over 1 weighed cut"**, row
  waste 0.440 kg, avg pack 0.260 ✓. Finished weight ~60.12 kg, "1 of 12 wheels
  weighed" ✓. "Stock remaining 29 units" mixes wheels and packs (F-14).
- `/cheese-conversion`: one wheel moved by **real drag**, one by **select +
  Mature →**; Save maturing · 2 → "Maturing hold updated — 2 wheel(s) set
  aside." **Reload → still 2 held** ✓. `/stock` G010426 wheels: available 9 ·
  maturing 2 · cut 1 ✓. `/batches` strip: 1 grey, 2 deep-amber, 9 yellow,
  "9 free · 2 maturing · 1 cut / 12" ✓. Batch page "Cut Whole Wheel (9 left)"
  and the cut form "Wheels available 9" exclude the held wheels ✓.
- **Release 1**: the UI can only release the whole saved hold (F-13).
  Workaround: Return 1 (button) → Save (hold 1) → Release 1 → dialog →
  "Released 1 wheel(s) to mature cheese." → **G010426-1** (id 29), production
  01/04/2026 carried forward, ready 29/08/2026 (+150 d) ✓; lineage "Matured
  from G010426" / "Matured into G010426-1" both ways ✓.
- Re-held 1 (using the **Quick allocate** stepper −×4 → "Next 1 →", Save) so
  undo could be checked against "back to 2". On `/batches/29` **Return to
  farmhouse hold** → dialog "Return 1 wheel(s) to the farmhouse maturing
  hold?" (red Return) → "Returned 1 wheel(s)…". Source: remaining 11,
  **maturing 2** ✓; log deleted ✓. Mature batch 29 stays as an empty shell
  (item 50 at 0/0) and was **reused** by the next release (see Notes).
- Release 1 again and leave: drag Maturing → Farmhouse (reverse drag works)
  → Save (1) → Release 1 → re-hold 1 by drag → Save.

Probe:
```
G010426 Mossfield Farmhouse Cheese
  Whole Wheel produced=12 remaining=10 maturing=1 available=9
  Vacuum Pack produced=18 remaining=18 maturing=0 available=18
G010426-1 Mossfield Mature Cheese
  Whole Wheel produced=1 remaining=1 maturing=0 available=1
cuts: 5 conversions: 3
```
Check ✓: produced 12, remaining 10 (−1 cut, −1 converted), maturing 1,
available 9; Vacuum Pack item 18; Mature batch with 1 wheel. Batch page
reconciles (on hand 10 + sold 0 + cut 1 + converted 1 = 12), nothing red.
Console clean. Findings: F-12, F-13, F-14.

### 4. Customers and the run — done
- **E2E Corner Shop** (id 27): corner@e2e.test, 1 Test Street Dublin D01 E2E1,
  phone 01 555 0101, credit limit 2000, Net 14 (keyboard ↓↓ on the focused
  select), per order, email delivery, statements on, **requires_reference on**,
  notes tag. → "Customer created successfully." (lands on the list; F-16)
- **E2E Weekly Deli** (id 28): deli@e2e.test, 2 Test Lane, **weekly**,
  **delivery charge 5 %**, notes tag; credit limit left at the default €0.00
  and terms at the default Immediate (the plan didn't specify).
- Field clarity: every field the plan asked about has helper text.
  *Billing email* "Where invoices and statements go. Leave blank to use the
  main email." *Invoice delivery* "'Print' and 'no invoice' keep this customer
  out of the not-yet-emailed queue." *send_statements* is labelled "Include in
  month-end statement runs". *Delivery charge (%)* "% of order value
  (VAT-inclusive) — overrides the fixed run charge." Only the **Mossorders
  user ID** is opaque for an office user (F-17).
- Special prices on Corner Shop: picker labels read "Organic Milk · 1L Bottle
  — standard €2.50" ✓. Added €1.20 → "Special price added." Duplicate 1L @
  1.30 → refused inline under the select: "This customer already has a
  special price for that variant — edit the existing one instead." (top
  flash + inline, input kept) ✓. Inline edit → 1.25 → "Special price
  updated." ✓. Every action reloads at the top (F-16).
- `/delivery-runs`: Edit run → charge 0.00 → **6.15** (helper "The total
  charged to flagged stops — enter it VAT-inclusive; the 23% VAT is broken out
  on the invoice. 0 = no charge.") → "Delivery run "Dublin Tue" updated." Add
  stop (select + Add) ×2 ✓. "Add stop" also lists *Organic Store*, a 16th
  customer not on any run (the plan's context said all 16 were on run #1;
  15 stops + Organic Store unassigned).
- Reorder: ↑/↓ only, one step per page reload. Moved each to positions 1 and
  2 with 15 moves each (focused the "Move up" button, pressed a real Enter;
  see Deviations) → F-15.
- Show charge settings → toggled Corner Shop "€ charge: off" → **on** ("E2E
  Corner Shop will now get the Dublin Tue delivery charge on new orders.");
  the reveal stays open via `?charges=1` ✓; run header shows "Charge €6.15".
  The % customer shows "off" (F-18).
- Throwaway: created **E2E Throwaway** (no orders), searched "Throwaway",
  Delete → dialog "Are you sure you want to delete this customer?" (red
  Delete) → "Customer deleted successfully." ✓ (search filter dropped
  afterwards, F-16). `/customers?search=E2E` lists exactly the two E2E
  customers ✓ (Enter submits the search).

Probe:
```
27 E2E Corner Shop mode=per_order run=1 pos=1 charge=1 pct=- ref=1 currentCharge=6.15
28 E2E Weekly Deli mode=weekly run=1 pos=2 charge=0 pct=5.00 ref=0 currentCharge=0
run charge: 6.15
throwaway in DB: 0
special: [{"product_variant_id":1,"price":"1.25"}]
```
Check ✓: Corner Shop `currentCharge=6.15`; Weekly Deli `currentCharge=0`
with `pct=5` (percentage precedence). Console clean. Findings: F-15 … F-18.

### 5. Orders — the desktop form — done
- **ORD-20261004-001** (id 12) for E2E Corner Shop, delivery Tue 06/10, ref
  **PO-E2E-1**. The Customer ref box **auto-opened** for the requires_reference
  customer ✓ (and stays collapsed for Weekly Deli ✓). Lines 1L × 24, 2L × 6,
  250g × 12, Farmhouse Whole Wheel × 2, Mature Whole Wheel × 1. Saved:
  1L **24 × €1.25 = €30.00** (special price ✓); wheel line **€160.00 ~10.000 kg**
  (weight-aware ✓); Mature €55.00 per unit (known, SMALL_FIXES). Subtotal
  €292.40 · Delivery charge **€5.00** · VAT (23%) **€1.15** · Total
  **€298.55** = 292.40 + 5.00 + 1.15 to the cent ✓. The form preview showed
  €322.40 (F-20).
- Break attempts: no lines → "You must add at least one item to the order."
  (customer kept) ✓. Qty 0 → browser `min=1` bubble ✓. Customer with no run
  (Organic Store) → accepted with no delivery charge ✓. Same variant twice →
  two lines (F-23). Picker labels all read `Product · Variant (price)` ✓.
  Probe order **ORD-20261004-002** (id 13, Organic Store, E2E-tagged) was
  cancelled (dialog), then used for F-19 and cancelled again.
- **Confirm** (dialog) → confirmed. Before auto-allocating I read the
  pickers: 1L {M041026 240, M041026-1 12}; 250g {**Y230726 295**, Y041026 120};
  wheels {G010426 9, G030426 26}; Mature {G010426-1 1, G030426-1 4}.
  **Auto allocate** (dialog) → "Auto-allocated 45 units across 5 lines."; order
  → preparing. FIFO took 1L/2L from M041026 ✓, **yoghurt from Y230726**
  (23/07, no expiry: older non-expired wins, as FIFO says, so the 10-week-old
  tubs go first; consequence of F-07), wheels from G010426 (01/04, oldest) ✓,
  Mature from G010426-1 (production carried to 01/04, beats G030426-1) ✓.
- Removed the 2L allocation (dialog "Remove this allocation?") → "Allocation
  removed successfully."; re-added from the picker (M041026, 6) → "Allocated
  6 units from batch M041026." ✓.
- 1L raised to 30 → "24 allocated · 0 of 30 fulfilled", stock picker
  reappears for the shortfall ✓; lowered to 20 → allocation trimmed to 20
  (DB row 31 = 20) ✓ (on the way I hit F-21). Add item 500g → went in as
  ×16 (F-22); **Remove line** (dialog) → "Item removed. Any picked stock has
  been returned to its batch." ✓. Delivery charge edit 6.15 → 0 → total
  €287.40, VAT line gone ✓; → 6.15 → €293.55 ✓.
- **ORD-20261004-003** (id 14): E2E Corner Shop, Tue 06/10, 1L × 12, ref
  PO-E2E-2, **left pending**. Charged another €6.15 (F-24).
- **ORD-20261004-004** (id 15): E2E Weekly Deli, 2L × 12 + 500g × 6 →
  subtotal €87.00, "Delivery charge (5%) €3.54 · VAT €0.81 · Total €91.35" ✓;
  confirmed; 2L → 20 → subtotal €123.00, charge €5.00 + €1.15 (= 6.15 = 5 %)
  recomputed live ✓; back to 12 → €91.35 ✓. Credit limit €0 not enforced
  (F-25).
- Edit-form status dropdown bypasses the workflow (F-19, **S1**).

Probe:
```
12 ORD-20261004-001 E2E Corner Shop status=preparing sub=287.40 charge=6.15 pct=- tax=1.15 total=293.55 ref=PO-E2E-1
   Organic Milk · 1L Bottle qty=20 alloc=20 ful=0 price=1.25 line=25.00
   Organic Milk · 2L Bottle qty=6 alloc=6 ful=0 price=4.50 line=27.00
   Organic Yoghurt · 250g Tub qty=12 alloc=12 ful=0 price=1.70 line=20.40
   Farmhouse Cheese · Whole Wheel qty=2 alloc=2 ful=0 price=16.00 line=160.00
   Mature Cheese · Whole Wheel qty=1 alloc=1 ful=0 price=55.00 line=55.00
14 ORD-20261004-003 E2E Corner Shop status=pending sub=15.00 charge=6.15 pct=- tax=1.15 total=21.15 ref=PO-E2E-2
   Organic Milk · 1L Bottle qty=12 alloc=0 ful=0 price=1.25 line=15.00
15 ORD-20261004-004 E2E Weekly Deli status=confirmed sub=87.00 charge=4.35 pct=5.00 tax=0.81 total=91.35 ref=
   Organic Milk · 2L Bottle qty=12 alloc=0 ful=0 price=4.50 line=54.00
   Organic Yoghurt · 500g Tub qty=6 alloc=0 ful=0 price=5.50 line=33.00
```
(Order 13 is Organic Store, so it isn't in this E2E-customer probe: `status=cancelled`.)
Check ✓: order 12 1L `price=1.25`; `charge=6.15 tax=1.15`; `total = subtotal
+ 6.15`. Weekly Deli `charge = round(87.00 × 0.05, 2) = 4.35`, `pct=5`. The 1L
line is at 20 (not the plan's original 24) because the plan's own edit steps
end at 20. Console clean. Findings: F-19 … F-26.

### 6. Orders — the chilled-run sheet — done
- `/chilled-runs?date=2026-10-06` → "Tue 06/10/2026". E2E Corner Shop at
  stop 1, E2E Weekly Deli at stop 2 ✓. Corner Shop row merges its two orders:
  "ORD-20261004-001 · ORD-20261004-003 · Pending · 41/53 allocated · Ref:
  PO-E2E-1 · Multiple orders — edit above". The order numbers link to
  `orders.show`, not an inline editor ✓ (see F-27 for copy and second ref).
  Cells: 1L 32 with sub-line **20/32**, others ✓. Weekly Deli shows "Not
  allocated", 0/12 and 0/6. Summary strip: 17 stops, milk 50 · 4 crates,
  yoghurt 18 · 3 crates, cheese 3, allocated 0/2 "41 of 71 units reserved".
  Stock footer **checked by hand**: 1L 232 (= 240 − 20 + 12), 2L 90, 16L 3,
  250g 403 (283 + 120), 500g 276, Farmhouse 33 (7 + 26), Mature 4 ✓. Crate
  rows: 1L 32 → 2 crates, 2L 18 → 2 crates + 2 extra ✓.
- **Enter order** for An Tairseach (existing customer, no order that day):
  1L 10, Tab → 2L 4, **+ Ref** → "E2E-20261004 sheet", Save → "Order for An
  Tairseach Organic Farm Shop saved (ORD-20261004-005)." → pending row ✓. No
  notes field in the editor (F-28).
- Edit again → **Repeat last order** fills 1L 23 / 2L 25 / 500g 32 and turns
  three vacuum-pack lines that aren't columns (Farmhouse 20, Garlic & Basil
  10, Mediterranean 10) into **added lines** in the Notes cell, labelled "from
  Tue 08/09 · ORD-20260910-001" ✓. **←** → "from Tue 14/07 · ORD-20260710-001";
  **←** → "from Tue 23/06 · ORD-20260622-001" (the added lines disappear: that
  order had none) ✓; **→** back to 14/07 ✓.
- With that row dirty, clicked Avoa Ballsbridge's **Enter order** → styled
  modal "Unsaved quantities for An Tairseach Organic Farm Shop · You've
  entered quantities that haven't been saved. Leaving now will lose them." →
  **Keep editing** (values kept, `__stopDirty` still true, scroll lock
  released) → again → **Discard changes** → moved to Avoa's editor (`edit=20`)
  ✓; Cancel there. An Tairseach still saved at 10/4 ✓.
- **Confirm all (2)** (dialog "Confirm 2 pending orders on this run? They will
  appear on the picking queue.") → "2 orders confirmed — now on the picking
  queue."; the button disappears and no Pending tags remain ✓.
- **Allocate all (3)** (dialog "Reserve available stock (oldest batches
  first) for 3 orders on this run?") → "Allocated 44 units across 3 orders."
  Every cell ✓; allocated bar 3/3 "All stops covered — ready to pick"; footer
  1L 210, 2L 74, 500g 270 (dropped by exactly the new reservations) ✓; the
  Allocate all button hides ✓. No `short` flags (every column covered).
- Edit allocated row **down**: An Tairseach 1L 10 → 6 (Enter) → saved; row
  "6 ✓"; footer 1L 210 → **214** (4 released) ✓.
- Cancel by zeroing: entered **ORD-20261004-006** for Avoa Ballsbridge (1L ×
  2, ref "E2E-20261004 to cancel") so An Tairseach's order survives for step
  8. Edit → 0 → "Order for Avoa Ballsbridge cancelled — all quantities
  cleared. Any reserved or picked stock has been returned."; row back to "No
  order this week" ✓; lines kept (probe).

Probe:
```
orders on 2026-10-06:
  ORD-20261004-001 E2E Corner Shop preparing lines=5 alloc=41/41 charge=6.15
  ORD-20261004-003 E2E Corner Shop preparing lines=1 alloc=12/12 charge=6.15
  ORD-20261004-004 E2E Weekly Deli preparing lines=2 alloc=18/18 charge=4.35
  ORD-20261004-005 An Tairseach Organic Farm Shop preparing lines=2 alloc=10/10 charge=0.00
  ORD-20261004-006 Avoa Ballsbridge cancelled lines=1 alloc=0/2 charge=0.00
```
Check ✓: no pending left; allocations equal ordered on every live order.
(Allocating moved confirmed orders to `preparing`.) Console clean. Findings:
F-27, F-28.

### 7. Picking — phone width, factory role — done
Width: **500 × 659** (Chrome's minimum; see Chrome setup). Signed out (menu
→ Log Out), resized, signed in as factory_test → lands on **/picking** ✓
(the username box was pre-filled "office_test" by Chrome's own autofill; I
cleared it).
- Queue: Tuesday's four orders listed under "TUE 6 OCT · 4" in delivery
  order ✓, but behind stale July ready orders, and the strip reads 149/230
  (F-30). **No € on any picking screen**: checked `€` count = 0 on the queue,
  the overview, every pick screen and both celebration screens ✓.
- ORD-20261004-001 overview: 5 lines with batch codes, "Mossfield Organic Milk
  is next. From batch M041026 · 222 in stock" ✓.
- **1L**: stepper starts at 20; **+ clamps at 20** ✓ (+ not visibly disabled);
  − → 19; chip "20" → 20; Picked · Next → "Picked 20 × 1L Bottle from batch
  M041026." ✓.
- **250g**: Picked → "Picked 12 × 250g Tub from batch Y230726." ✓.
- **Farmhouse wheel**: running-total card; two empty weight boxes; button
  "**Weigh each piece first**" stays disabled until both are filled ✓. **Comma
  test**: "5,210" → 5210 kg, green OK (F-29, **S1**); corrected to 5.210 →
  total 5.21 → + 4.980 → **10.19 kg**, button enables ✓ → "Picked 2 × Whole
  Wheel (10.19 kg) from batch G010426." ✓.
- **Mature**: 5.300 → "Picked 1 × Whole Wheel (5.3 kg) from batch G010426-1." ✓.
- **Undo 250g**: line screen "Picked — 12 of 12 from Y230726 · Undo last pick"
  → dialog "Undo this pick? Stock will be restored to the batch." (red Undo) →
  "Undid pick of 12 — stock restored to batch." DB: bi 33 remaining 283 →
  **295**, reservation kept (alloc 12, ful 0) ✓. Re-picked ✓.
- **2L short**: "Can't finish this line — mark short" → dialog "Can't finish
  this line? The 6 unpicked units will be released back to stock and won't be
  supplied or billed." (red Mark short) → amber **"Order closed — short. 4 of
  5 lines picked · 1 short (6 units)"**, NOT SUPPLIED 2L "Short 6", status
  "Ready · short" ✓.
- **Weekly Deli** ORD-20261004-004: 2L × 12, 500g × 6 → green **"Order
  ready. 2 lines picked"** ✓.
- Left Corner Shop's second order ORD-20261004-003 **unpicked on purpose**,
  so step 8 can check the "not ready" skip message.
- Tap targets: main buttons are full-width and ~50 px tall, comfortable. The
  fixed footer covers the short button and part of the batch list until you
  scroll (F-31). Back arrow → order overview → queue, which makes sense.
- `/orders` as factory at 500 px: renders, no €, but cramped (F-31).

Probe:
```
ORD-20261004-001 ready total=269.59
  Organic Milk · 1L Bottle ord=20 ful=20 short=0 kg=0.000 fulfilled_total=25.00 invoiceable=25
  Organic Milk · 2L Bottle ord=6 ful=0 short=6 kg=0.000 fulfilled_total=0.00 invoiceable=0
  Organic Yoghurt · 250g Tub ord=12 ful=12 short=0 kg=0.000 fulfilled_total=20.40 invoiceable=20.4
  Farmhouse Cheese · Whole Wheel ord=2 ful=2 short=0 kg=10.190 fulfilled_total=163.04 invoiceable=163.04
  Mature Cheese · Whole Wheel ord=1 ful=1 short=0 kg=5.300 fulfilled_total=55.00 invoiceable=55
  batch_item 33 Y230726 250g Tub remaining=283 produced=300
  batch_item 42 M041026 1L Bottle remaining=220 produced=240
  batch_item 46 G010426 Whole Wheel remaining=8 produced=12
  batch_item 50 G010426-1 Whole Wheel remaining=0 produced=1
a34 unit_weights=[5.21,4.98]
```
Check ✓: wheel `kg=10.190`, `fulfilled_total = 10.190 × 16.00 = 163.04`; 2L
`short=6`, `invoiceable=0.00`; each picked batch item dropped by exactly the
picked quantity (1L 240→220, 250g 295→283 after undo/re-pick, wheels 10→8,
Mature 1→0); order `ready`, total 263.44 + 6.15 = 269.59. Console clean.
Findings: F-29 … F-31.

### 8. Load and dispatch — done
- As **factory_test** (desktop 1408) on `/chilled-runs?date=2026-10-06`: **no
  Confirm all / Allocate all / Dispatch run buttons, no Edit or Enter-order
  links**, no € ✓. Loaded boxes are buttons titled "Mark loaded"; Corner Shop
  has two (orders 12 and 14, top = 001). Ticked 001 → "E2E Corner Shop marked
  loaded." (Loaded counter stays 0/3: the stop's other order isn't loaded);
  ticked Weekly Deli 004 → "E2E Weekly Deli marked loaded." → 1/3. Left 003
  and 005 unloaded and unpicked so the skip messages could be checked.
- As **office_test** (sign-in returned me to the intended run sheet URL ✓):
  heading "Tue 06/10/2026" checked; **Dispatch run (2)** → dialog "Dispatch 2
  loaded orders? Figures are frozen: invoices are issued for per-order
  customers and a dispatch confirmation is emailed to each shop. Invoices are
  sent from Finance." → **Dispatch** → "2 stops dispatched · 1 invoice issued
  · 2 confirmations queued · skipped: E2E Corner Shop, An Tairseach Organic
  Farm Shop (not ready)." (F-32). The redirect came back well under a second;
  the listener sent both queued confirmations within ~1 s (`14:06:07`,
  `14:06:08`).
- Corner Shop 001 → **INV-000004** ✓; Weekly Deli 004 → **no invoice** ✓.
  **Not observed:** I didn't open Weekly Deli's order page before the weekly
  run, so its "Billed on the weekly invoice" line wasn't seen in the browser.
  The template (`orders/show.blade.php:201–203`) renders "Billed on the
  weekly consolidated invoice" for that case; it now shows INV-000006. (An
  earlier draft of this report said "Awaiting invoice · Finance" here; that
  line was on An Tairseach's order 16 before dispatch.) Finance's To-Invoice
  wording ("Awaiting a consolidated run") was checked in step 9.
- `/orders/12`: "Invoice **INV-000004** · Unpaid · not yet sent" (links to
  Finance) ✓; "Confirmation · Sent 4 Oct to corner@e2e.test · view" ✓;
  "Dispatched 04/10/2026 14:06"; "Supplied short" banner listing the 2L ✓.
  **Dispatch confirmation** view: actual weights (Farmhouse 10.190 kg ·
  €163.04), batch codes, "Not supplied — not charged" for 2L (Qty 0 €0.00),
  delivery €5.00 + VAT €1.15, Total €269.59, "INVOICE TO FOLLOW" ✓.
  `?download=1` → `application/pdf`, `%PDF-1.7`, 1.76 MB (F-34). **Docket**:
  ordered / picked / short / weight columns, **no prices** ✓. Both print the
  internal notes (F-33).
- Changing the invoiced order: no line controls are shown once dispatched ✓;
  Edit → Update → refused "…Void the invoice first." ✓ (F-34: Edit still
  offered).
- **Mark delivered** (dialog) → "Order ORD-20261004-001 marked as delivered."
  → DB `delivered_at 2026-10-04 14:08:05` ✓. My first attempt showed the
  invoice-refusal text; that was the **previous** flash. My click had missed
  the dialog's OK, so nothing was posted (code confirms `markDelivered` has no
  invoice block). Not an app finding.
- **Per-order dispatch**: An Tairseach ORD-20261004-005, as office on the
  desktop panel: Fulfil 1L (6) → "Fulfilled 6 units."; Fulfil 2L (4) →
  "Fulfilled 4 units." → status Ready; **Mark dispatched** → dialog "Dispatch
  order ORD-20261004-005? The figures are frozen and an invoice is issued, and
  a dispatch confirmation is emailed to farmshop@antairseach.ie." →
  "Order ORD-20261004-005 dispatched. Invoice INV-000005 issued — send it from
  Finance. Confirmation emailed to farmshop@antairseach.ie." (inline send;
  mail driver is `log`) ✓. **This used INV-000005**, so the step 9 Weekly
  Deli consolidated invoice will be INV-000006, not the plan's INV-000005.
- Re-checked the step-5 worry about pre-filled desktop wheel weights:
  `orders/partials/allocation-items.blade.php:144` uses `placeholder=` (+
  `required`), not `value=`. What I saw were placeholders. Not a finding.

Probe:
```
ORD-20261004-001 E2E Corner Shop delivered disp=14:06 loaded=y invoice=INV-000004
ORD-20261004-003 E2E Corner Shop preparing disp=- loaded=n invoice=-
ORD-20261004-004 E2E Weekly Deli dispatched disp=14:06 loaded=y invoice=-
ORD-20261004-005 An Tairseach Organic Farm Shop dispatched disp=14:09 loaded=n invoice=INV-000005
ORD-20261004-006 Avoa Ballsbridge cancelled disp=- loaded=n invoice=-
document_emails:
  #6 dispatch_confirmation to=farmshop@antairseach.ie status=sent sent=y err=-
  #5 dispatch_confirmation to=deli@e2e.test status=sent sent=y err=-
  #4 dispatch_confirmation to=corner@e2e.test status=sent sent=y err=-
  #3 dispatch_confirmation to=ie-avocainvoicing@aramark.ie status=sent sent=y err=-   (baseline backlog)
  #2 dispatch_confirmation to=farmshop@antairseach.ie status=sent sent=y err=-       (baseline backlog)
jobs pending=0 failed=0
grep -c "Dispatch confirmation" storage/logs/laravel.log: 3 before → 6 after
```
(The plan's probe used `to_email` / `emailed_at`; the columns are `recipient` /
`status` / `sent_at`.) Check ✓: per-order customers have invoices, Weekly Deli
doesn't; every new confirmation row sent with no error; jobs 0, failed 0; log
grew by exactly the 3 dispatched orders. Console clean. Findings: F-32 … F-34.

### 9. Finance — done
- `/invoicing`: **INV-000004** E2E Corner Shop, issued 04/10, **due 18/10/2026**
  (net 14 ✓), €269.59, Unpaid, Not sent. Opened: lines match picked quantities
  and the **actual** wheel weight (10.190 kg · €163.04); the 2L short line
  shows **"—" qty, €0.00, "Not supplied — not charged."** (understandable for
  a customer); "Delivery charge €5.00" line; summary Subtotal €263.44 ·
  Delivery €5.00 · VAT (23%) €1.15 · Total **€269.59** = order total ✓. The
  invoice shows no PO ref or order number (F-35). **PDF** → 200
  `application/pdf` `%PDF-1.7` (1.76 MB). **Email invoice** (dialog "Email
  invoice INV-000004 to corner@e2e.test?", Send) → "Invoice INV-000004 emailed
  to corner@e2e.test."; history row Sent; log has the mail; the invoice
  leaves the not-emailed set (probe `emailed=y`) ✓.
- **To Invoice**: "Awaiting a consolidated run (1) · E2E Weekly Deli · Weekly
  consolidated · 1 order · 06/10/2026 · €91.35" ✓; no other E2E order listed ✓
  (also lists three old per-order orders; two of them are not dispatched, F-38).
- **Consolidated**: typed 05/10–11/10 → preview "1 customer · E2E Weekly Deli
  · €91.35 · 2L 12 €54.00 · 500g 6 €33.00 · Delivery charge (5%) €3.54";
  **sequence still 6 after preview** ✓ → Issue selected (dialog "Issue
  consolidated invoices for the selected customers?") → "1 consolidated
  invoice issued (**INV-000006**)." (005 had already gone to An Tairseach in
  step 8.) INV-000006 shows "Lines · 5 Oct – 11 Oct 2026 · ORD-20261004-004 ·
  06/10/2026", 5 % line + VAT €0.81, total €91.35 ✓.
- **Payments**: €50.00 bank transfer, applied €50 to INV-000004 → "Payment of
  €50.00 recorded…", still due €219.59 → **partial** ✓. €229.59 applying
  €219.59 → "…€10.00 is on account as credit." → INV-000004 Paid; customer
  page "CREDIT ON ACCOUNT €10.00" ✓ ("NET OWED €-10.00", F-38). **Apply
  credit** with nothing open: the button isn't offered (sensible). **Remove**
  the second payment (dialog, red Remove) → "…removed; invoice balances
  restored." → INV-000004 paid 50.00, balance 219.59, **partial**; credit gone
  (outstanding 219.59) ✓. Re-recorded the exact €219.59 using **Auto-fill
  oldest first** → **paid**; order 12 `payment_status` mirror = paid ✓.
  Default apply-nothing behaviour: F-36.
- **Credit note** on INV-000006: 500g line qty 1, VAT 0, €5.00, reason
  "damaged tub E2E-20261004" → "Credit note **CN-000001** issued for €5.00."
  → INV-000006 credited 5.00, balance **86.35** ✓; PDF 200 `%PDF-1.7`; Email
  (dialog) → "Credit note CN-000001 emailed to deli@e2e.test." + log ✓.
- **Statement** E2E Corner Shop: defaults to Sept (F-38); set 01/10–31/10 →
  brought forward €0.00 · INV-000004 €269.59 · pay1 €50.00 · pay3 €219.59 ·
  **Balance due €0.00**; the removed payment doesn't appear; aged buckets all
  €0.00 ✓. **Generate & email** (dialog "Generate and email this statement to
  corner@e2e.test?", Send) → "Statement emailed to corner@e2e.test." → DB
  statement row (period 1–31 Oct, closing 0.00, `emailed_at` set) +
  document_emails `statement` sent + log "Statement to 31 Oct" ✓. **So the
  `name="send"` path from cycle 1 really generates and emails.**
  `/invoicing/statements` lists it ✓.
- **Void**: INV-000006 (credit note) and INV-000004 (payments) have **no Void
  button** (F-37). Server-side refusal confirmed: direct POST → 403, 403 ✓.
  Voided **INV-000005** (ORD-20261004-005, unpaid): modal with required
  reason, focus in the reason box → "Invoice INV-000005 voided. Its orders
  are back in the uninvoiced queue." → To Invoice lists "ORD-20261004-005 ref
  E2E-20261004 sheet … Dispatched €33.00" → Issue invoice → "Invoice
  **INV-000007** issued for order ORD-20261004-005." Number 005 not reused ✓.
- **Dashboard** tiles: Awaiting invoice **3** (the three pre-existing per-order
  orders; Weekly Deli is no longer waiting) ✓; Invoices not emailed **5**
  (INV-1,2,3,6,7; INV-4 emailed, INV-5 void) ✓; Overdue **€557.67 · 2
  invoices** (INV-1 + INV-2; INV-6 is due today, so not overdue yet) ✓. "Batches
  maturing 0 · **13** ready now" (F-01 grows with every batch).

Probe:
```
INV-000001 cust=25 total=211.67 paid=0.00 credited=0.00 balance=211.67 state=overdue void=n emailed=n
INV-000002 cust=10 total=346.00 paid=0.00 credited=0.00 balance=346.00 state=overdue void=n emailed=n
INV-000003 cust=20 total=76.00 paid=0.00 credited=0.00 balance=76.00 state=unpaid void=n emailed=n
INV-000004 cust=27 total=269.59 paid=269.59 credited=0.00 balance=0.00 state=paid void=n emailed=y
INV-000005 cust=10 total=33.00 paid=0.00 credited=0.00 balance=0.00 state=void void=y emailed=n
INV-000006 cust=28 total=91.35 paid=0.00 credited=5.00 balance=86.35 state=partial void=n emailed=n
INV-000007 cust=10 total=33.00 paid=0.00 credited=0.00 balance=33.00 state=unpaid void=n emailed=n
E2E Corner Shop outstanding=0
E2E Weekly Deli outstanding=86.35        (→ 76.35 after step 10's €10.00 payment)
seq invoice=8 credit_note=2
statements: 1 emails: credit_note 1/1, dispatch_confirmation 5/5, invoice 1/1, statement 1/1
```
Check ✓: `total − paid − credited = balance` on every invoice; states as
expected; Corner Shop outstanding 0; every email row sent; the invoice
sequence went 4 → 8 for exactly four issues (004, 005, 006, 007); the preview
and the two refused voids used no numbers. Console clean. Findings: F-35 … F-38.

### 10. The unhappy paths — done
- **Cancel with picked stock**: ORD-20261004-007 (id 18), E2E Corner Shop,
  1L × 5, delivery 13/10, ref PO-E2E-10. Confirm → Auto allocate (dialog) →
  "Auto-allocated 5 units across 1 line." → Fulfil on the desktop panel →
  "Fulfilled 5 units." (M041026 1L 214 → **209**; order ready) → **Cancel
  order** (dialog "…Any picked stock will be returned to its batch.") →
  "Order cancelled. Reserved and picked stock has been returned to its batch."
  → remaining **214** (+5) ✓; order alloc 0 / ful 0 ✓. (On the way, the line
  select opened on a different highlighted option and picked 16L Bucket, and
  type-ahead on "·" landed on 2L. Home + Down×2 fixed it. Keyboard
  selection in long native selects is fiddly; not filed separately.)
- **Back after a POST**: after Confirm order (PRG redirect) → Back → still on
  /orders/18 showing the confirmed state (the old flash re-shown from bfcache)
  → Forward → same → F5 → no resubmission prompt, flash gone, no duplicate
  action ✓.
- **Double-click**: Confirm order (double-click the button, then double-click
  the dialog's Confirm) → confirmed once ✓. **Payment form double-click
  Save → duplicate payment** (F-39, **S1**); duplicate removed via UI.
- **Two tabs**: opened /orders/14 in a second tab, allocated M041026-1 × 12 in
  tab A ("Allocated 12 units from batch M041026-1."). Tab B was a background
  tab (`visibilityState: hidden`, input doesn't reach it), so I closed tab A
  to bring B forward. That dissolved the MCP tab group and lost tab B. Redid
  it in one visible tab: removed the allocation (dialog), opened the page so
  the picker showed "M041026-1 — 12 available", selected it, then stood in for
  "the other tab" with `OrderItem::find(46)->allocateFromBatchItem(BatchItem::find(47), 12)`
  in tinker (alloc 44), then clicked **Allocate** on the stale page → refused
  "Cannot allocate this quantity. Check available stock and order limits." →
  probe: one allocation row of 12, no over-reservation ✓ (message nit in F-41).
  See Deviations.
- **Validation display**: empty customer form → browser-native bubble on the
  first empty required field ("Please fill in this field.", scrolled to Name;
  5 required fields invalid). Empty batch form → native "Please select an
  item in the list." on Product. Neither reaches the server; errors are
  neither inline nor at the top, just the browser bubble one field at a time.
  Payment amount **-5** silently becomes 5 (F-40). No page put an error on a
  different page from its form.
- **404s**: `/orders/999999`, `/invoicing/invoices/999999`, `/picking/999999` →
  "404 | NOT FOUND", no stack trace ✓ (bare page, F-41).
- **Role leak** (factory_test): `/invoicing`, `/customers`,
  `/orders/12/dispatch-confirmation` → **403** each ✓ (no € shown).
  **driver_test**: lands on /dashboard with only "Your route manifest will
  appear here once delivery routes are set up…" and a one-item nav
  (Dashboard) ✓. Known: FULFILMENT_PLAN Phase 4.

Probe:
```
payments=3 (E2E: [{"id":3,"customer_id":27,"amount":"50.00","reference":"E2E-20261004 pay1"},
                  {"id":5,"customer_id":27,"amount":"219.59","reference":"E2E-20261004 pay3"},
                  {"id":6,"customer_id":28,"amount":"10.00","reference":"E2E-20261004 dblclick"}])
bi42 M041026 1L remaining=214
ORD-20261004-007 cancelled alloc=0 ful=0
order14 item46 ord=12 alloc=12 rows=47:12
failed_jobs=0 jobs=0
```
Check: **payments count was NOT unchanged by the double-click.** It went 2 → 4
(one intended, one duplicate). I removed the duplicate, giving 3 (F-39).
The cancelled order's batch item was restored (209 → 214) ✓. `failed_jobs`
0 ✓. Console clean. Findings: F-39 … F-41.

### 11. Wrap up — done
- Queue listener (pid 381629) and `php artisan serve` (pid 381633) stopped;
  `pgrep -af "queue:listen|artisan serve"` → only the pgrep itself. Browser
  tab closed. (No `tail -f` was started; I grepped the log instead.)
- `git status --short` → `?? docs/planImp/implemented.md` (only).
- Final DB counts next to the baseline:

| | baseline | final | Δ | |
|---|---|---|---|---|
| orders | 10 | 17 | +7 | ids 12–18 |
| batches | 8 | 13 | +5 | 24, 25, 26, 27, 29 (28 created and deleted) |
| customers | 16 | 18 | +2 | 27, 28 (Throwaway created and deleted) |
| invoices | 3 | 7 | +4 | INV-000004…007 |
| payments | 0 | 3 | +3 | 3, 5, 6 (4 and 7 removed) |
| credit_notes | 0 | 1 | +1 | CN-000001 |
| statements | 0 | 1 | +1 | id 3 |
| document_emails | 2 | 8 | +6 | ids 4–9 (+ baseline #2/#3 now sent) |
| jobs | 2 | 0 | | baseline backlog drained by the listener |
| failed_jobs | 0 | 0 | | |
| invoice_seq | 4 | 8 | +4 | exactly four issues |

## E2E records table

| Type | id | Code / detail | End state |
|---|---|---|---|
| Batch | 24 | M041026 milk (1L 240 · 2L 96), exp 14/10 | 1L 214 left, 2L 80 left |
| Batch | 25 | Y041026 yoghurt (250g 120 · 500g 60), no expiry | untouched (FIFO took Y230726) |
| Batch | 26 | G010426 Farmhouse 12 wheels, made 01/04/2026 | wheels 8 left, 1 maturing, 1 cut, 1 converted, 2 sold; vac-pack item 49 = 18 |
| Batch | 27 | M041026-1 milk 1L 12 | 12 remaining (12 reserved by order 14) |
| Batch | 28 | G041026 3 000-wheel probe | **deleted** via UI |
| Batch | 29 | G010426-1 Mature (release) | 1 wheel, sold on order 12 |
| Cutting log | 7 | G010426 wheel 5.120 kg → 18 packs 4.680 kg (trim 0.440) | |
| Conversion log | 4 | G010426 → G010426-1, 1 wheel (earlier log deleted by the undo) | |
| Customer | 27 | E2E Corner Shop (corner@e2e.test, net 14, per order, ref required, run 1 pos 1, delivery charge on) | outstanding €0 |
| Customer | 28 | E2E Weekly Deli (deli@e2e.test, weekly, 5 %, run 1 pos 2) | outstanding €76.35 |
| Customer | — | E2E Throwaway | **deleted** via UI |
| Special price | — | Corner Shop · 1L Bottle €1.25 | |
| **Existing data changed** | run 1 | Dublin Tue `delivery_charge` 0.00 → **6.15**; stops re-numbered (E2E at 1–2, the other 15 shifted to 3–17) | Planner to decide whether to revert |
| Order | 12 | ORD-20261004-001 Corner Shop (ref PO-E2E-1) | delivered, INV-000004 paid |
| Order | 13 | ORD-20261004-002 **Organic Store** (existing customer) probe | cancelled |
| Order | 14 | ORD-20261004-003 Corner Shop (ref PO-E2E-2), 1L × 12 | preparing, 12 reserved on M041026-1, unpicked |
| Order | 15 | ORD-20261004-004 Weekly Deli | dispatched, INV-000006 |
| Order | 16 | ORD-20261004-005 **An Tairseach** (existing customer, sheet, ref "E2E-20261004 sheet") | dispatched, INV-000005 void → INV-000007 |
| Order | 17 | ORD-20261004-006 **Avoa Ballsbridge** (existing customer, sheet, ref "E2E-20261004 to cancel") | cancelled |
| Order | 18 | ORD-20261004-007 Corner Shop (ref PO-E2E-10) | cancelled (stock restored) |
| Invoice | 7 | INV-000004 Corner Shop €269.59 | paid, emailed |
| Invoice | 8 | INV-000005 An Tairseach €33.00 | **void** |
| Invoice | 9 | INV-000006 Weekly Deli (consolidated 5–11 Oct) €91.35 | credited 5, paid 10, balance 76.35 |
| Invoice | 10 | INV-000007 An Tairseach €33.00 | unpaid, not emailed |
| Payment | 3, 5 | Corner Shop €50.00 + €219.59 | (4 €229.59 removed) |
| Payment | 6 | Weekly Deli €10.00 | (7 duplicate removed, F-39) |
| Credit note | 3 | CN-000001 €5.00 on INV-000006 | emailed |
| Statement | 3 | Corner Shop 1–31 Oct 2026, closing 0.00 | emailed |
| Document emails | 4–9 | 3 dispatch confirmations (incl. **farmshop@antairseach.ie**), invoice, credit note, statement | all `sent` to the **log** mailer |

## Findings

41 findings. Index by severity (details below, grouped by phase):
- **S1 (3):** F-19 edit-form status dropdown bypasses dispatch/picking ·
  F-29 comma in a wheel weight becomes ×1000 with no bound (€83 360 line) ·
  F-39 double-click records a duplicate payment.
- **S2 (3):** F-12 refused cut form resets the measured pack weight to
  nominal · F-20 order form previews standard prices and omits delivery ·
  F-35 per-order invoice has no PO ref or order number.
- **S3 (19):** F-01, F-02, F-06, F-07, F-08, F-09, F-10, F-13, F-15, F-21,
  F-22, F-23, F-24, F-25, F-30, F-32, F-33, F-36, F-40.
- **S4 (16):** F-03, F-04, F-05, F-11, F-14, F-16, F-17, F-18, F-26, F-27,
  F-28, F-31, F-34, F-37, F-38, F-41.

### Phase 1 — Sign in, first impressions

### F-01 · S3 · Phase 1 · desktop 1408
**Where:** /dashboard as office_test, "Batches maturing" tile
**Steps:** 1. sign in as office_test 2. read the tile
**Expected:** "ready now" means sellable batches past their ready date (a count the office can act on)
**Actual:** "0 · 8 ready now". 8 is every `active` batch, including 4 expired milk batches (latest expired 2026-09-17), expired yoghurt Y220626 (exp 2026-07-12, 1 470 units), and G030426-1 (4 left). The query in `routes/web.php:46` checks only `status='active'` and `ready_date` null-or-past. It ignores `expiry_date` and stock remaining, so expired and empty batches count as "ready".
**Evidence:** screenshot: the tile reads `0  8 ready now` beside "Ready to ship 3 packed". Probe: batches 16,18,19,21,22 are `status=active` with expiry before today.
**Console:** clean
**Suggested fix (optional):** share the `BatchItem::allocatable()` expiry rule (strict `>` today) and require stock remaining.

### F-02 · S3 · Phase 1 · desktop 1408
**Where:** /stock milk card; /dashboard
**Steps:** 1. sign in as office_test (every milk batch expired) 2. open /dashboard 3. open /stock
**Expected:** the office gets told there is no sellable milk: an expired-stock hint, a count of expired batches, or a dashboard warning
**Actual:** /stock milk card says **"No active milk batches."** That's untrue as worded: four milk batches are `status=active` with 4 437 units remaining, all past expiry. They just vanish. The dashboard has no stock warning ("Low stock — not configured"), and its headline reads "All orders allocated." Expired stock is never surfaced. It sits `active` for ever, holding 5 907 units across 5 batches (incl. yoghurt Y220626), with no screen saying "N batches expired, X units to write off". Ties to OPEN_QUESTIONS #1 (no write-off route), but the **visibility** gap is separate.
**Evidence:** screenshot: Milk card empty-state text. Probe: M220626 2610, M230726 951, M230726-1 246, M070926 630 remaining, all expired.
**Console:** clean
**Suggested fix (optional):** empty-state copy "No sellable milk — 4 expired batches (4 437 units)" linking to /batches; a dashboard "expired stock" tile.

### F-03 · S4 · Phase 1 · desktop 1408
**Where:** /login (guest layout)
**Steps:** 1. sign out 2. look at /login
**Expected:** Mossfield branding (the app's "M Mossfield Dairy" mark)
**Actual:** the stock Laravel logo above the form
**Evidence:** screenshot: grey Laravel cube logo over the white card
**Console:** clean

### F-04 · S4 · Phase 1 · desktop 1408
**Where:** /login after a failed attempt
**Steps:** 1. type office_test, Tab, wrong password, Enter
**Expected:** focus lands on the password field (the username is kept, so the password is what needs retyping)
**Actual:** message "These credentials do not match our records." under the username. Username kept, password cleared, focus back on **username** (`autofocus`), so a keyboard user has to Tab before retyping
**Evidence:** `document.activeElement.name` → `login` after the failed submit
**Console:** clean

### F-05 · S4 · Phase 1 · narrow 500
**Where:** topbar at a 500 px viewport (any page)
**Steps:** 1. narrow the window to 500 px
**Expected:** bell icon on its own, or bell + date
**Actual:** the date chip is hidden but its vertical divider stays, so a lone `|` sits at the right edge after the bell
**Evidence:** zoomed screenshot of the topbar right corner: bell, then a thin grey bar, nothing after
**Console:** clean

### Phase 2 — Production: batches

### F-06 · S3 · Phase 2 · desktop 1408
**Where:** /batches/create → /batches/24 (milk)
**Steps:** 1. Milk, raw milk 500 L, 1L × 240, 2L × 96 2. Create batch
**Expected:** the form's helper says, for milk, raw milk "equals the finished volume", so 500 L vs 432 L bottled should be flagged (warning or refusal), or yield should show 86.4 %
**Actual:** accepted silently; the batch page shows "Finished weight ~432.00 kg · Yield ~100.0% (nominal)". `Batch::getProductionYieldAttribute()` returns a hard-coded `1.0` for milk (`app/Models/Batch.php:210`), so a 68 L loss can't be seen anywhere
**Evidence:** screenshot: batch info panel "RAW MILK USED 500.00 L / FINISHED WEIGHT ~432.00 kg Yield ~100.0% (nominal)"
**Console:** clean
**Suggested fix (optional):** compute milk yield like the others (finished litres / raw), or validate raw = Σ(qty × unit volume) for milk. Unsure whether 500/432 is a realistic entry or a design choice. Filed S3.

### F-07 · S3 · Phase 2 · desktop 1408
**Where:** /batches/create (yoghurt; cheese too) and /batches/25
**Steps:** 1. choose Mossfield Organic Yoghurt 2. watch the expiry field 3. create
**Expected:** told that the batch will have **no expiry**, so it never falls off /stock or the allocator (or yoghurt gets a shelf life)
**Actual:** the expiry stays blank under the hint "Auto-filled from the product's shelf life, when set." Nothing says it isn't set. The batch page then has **no Expiry row at all** (milk shows one). Effect already visible in the data: Y230726 (made 23/07, ten weeks ago) still shows 295 + 216 tubs "available" on /stock and is what the allocator would hand out
**Evidence:** screenshot: Y041026 batch info panel with Production date and Stock remaining, no expiry field; probe `25 Y041026 exp=-`
**Console:** clean
**Suggested fix (optional):** set `shelf_life_days` on yoghurt, or show "No expiry — this batch stays sellable until sold" under the field and on the batch page.

### F-08 · S3 · Phase 2 · desktop 1408
**Where:** /batches/create after any server-side refusal
**Steps:** 1. Milk, raw 10, **expiry 01/10/2026** (before production 04/10), 1L × 10 2. Enter
**Expected:** refusal; the form keeps every value, with the bad expiry still in the field next to its error
**Actual:** refusal shown twice (top flash + inline under expiry: "The expiry date field must be a date after production date.") ✓. But (a) **all variant quantities are blank** again (1L 10 → empty), and (b) the expiry field now shows the **auto-filled 14/10/2026**, so the error sits beside a valid date and the operator can't see what was wrong. Product, raw milk and notes survive
**Evidence:** JS after the redirect: `exp: "2026-10-14"`, `q: ["","",""]`, notes kept; screenshot of the expiry field showing 14/10/2026 with the red message beneath
**Console:** clean
**Suggested fix (optional):** repopulate `batch_items[*][quantity_produced]` from `old()`; don't let the Alpine shelf-life auto-fill overwrite an `old('expiry_date')`.

### F-09 · S3 · Phase 2 · desktop 1408
**Where:** /batches/create
**Steps:** 1. Farmhouse Cheese, raw milk 30 000 L, Whole Wheel × **3 000** 2. Create
**Expected:** some upper bound or an "are you sure — 3 000 wheels?" check (a typo of 30 for 3 000 is easy)
**Actual:** accepted: "Batch G041026 created successfully! 3000 wheels produced." /batches renders 3 000 circles (page 595 KB, still ~250 ms). Total stock value would jump by about €240 000 at nominal. I deleted it through the UI (Delete → dialog → "Batch deleted successfully.")
**Evidence:** flash text above; batch 28 created then deleted (probe: `batch 28 exists? false`)
**Console:** clean
**Suggested fix (optional):** a per-type sanity ceiling, or a confirm step when quantity × weight far exceeds raw milk ÷ typical yield. Design choice; filed S3.

### F-10 · S3 · Phase 2 · desktop 1408
**Where:** /batches/create product select
**Steps:** 1. open the Product dropdown
**Expected:** per CLAUDE.md, Mature is "not made fresh" (only reached by releasing aged Farmhouse wheels), so it shouldn't be offered for a new production batch
**Actual:** "Mossfield Mature Cheese (Cheese)" is offered. A fresh Mature batch would have no `source_batch_id`, no lineage, and its own 150-day ready date
**Evidence:** product options list: 1 Milk, 2 Yoghurt, 3 Farmhouse, 4 Garlic & Basil, 5 Mediterranean, 6 Cumin, **7 Mature**
**Console:** clean
**Suggested fix (optional):** exclude products only reachable by conversion (e.g. a flag on Product) from the create form. Unsure whether intentional (e.g. buying in mature cheese). Filed S3.

### F-11 · S4 · Phase 2 · desktop 1408
**Where:** batch screens. Small copy and number nits, grouped because each is a one-liner
**Steps / Actual:**
- /batches/create cheese row subtitle reads **"wheel wheel"** (size + unit both "wheel"); same in the batch page's Size column.
- /stock milk row for 12 bottles reads **"1 cases × 16"**: 12 isn't a case of 16, and "1 cases" is plural.
- /batches G041026 "Ready: 03/12/2026 **(1 month from now)**" for a date 60 days out (diffForHumans rounding).
- /batches Mature card G030426-1 "Raw milk: **0.0L**". It's converted, so "—" or "from G030426" would read better.
- Batch delete dialog: title **"Are you sure?"** and message **"Are you sure?"**. It doesn't name the batch (cycle 1 carried the old text verbatim). The batch-card Delete is offered even on batches the server will refuse (G030426 has sales/cuts; `deletionBlocker()` refuses after you confirm).
**Expected:** "wheel"; "12 bottles (less than a case)"; "2 months"; "—"; "Delete batch G041026?"
**Evidence:** screenshots of each; texts quoted above
**Console:** clean

### Phase 3 — Cheese: cut, hold, release

### F-12 · S2 · Phase 3 · desktop 1408
**Where:** /cheese-cutting/cut/{batchItem} after any refused submission
**Steps:** 1. G010426 → Cut wheel 2. pack type, wheel weight 5.120, packs 18, **total weight typed as the weighed 4.680** 3. cut date 01/03/2026 (before production) → submit
**Expected:** refusal; the form keeps the **measured** 4.680 kg so the operator only fixes the date
**Actual:** refusal shown (top flash + inline) ✓. The date, wheel weight and pack count are kept. But **Total weight comes back as 4.500** (18 × 0.250 nominal), recalculated by the form's Alpine auto-fill over `old('total_weight_kg')`. It happened on all three refusals I triggered (packs > wheel, cut date before production, cut date tomorrow). After the first one, the field even shows 4.500 under the error "The packs cannot weigh more than the wheel", which isn't the value that was refused. An operator who fixes the date and presses Cut again records **4.500 kg instead of the weighed 4.680**. The trim then reads 0.620 instead of 0.440, silently wrong in `cheese_cutting_logs` and in Batch trim-loss totals
**Evidence:** JS after each redirect: `total_weight_kg: "4.500"` (typed 4.680 / 5.500); `cut_date` and `vacuum_packs_created` survived. The correct cut was only recorded because I retyped 4.680 before the final submit
**Console:** clean
**Suggested fix (optional):** initialise the Alpine total from `old('total_weight_kg')` when present; only auto-fill when the pack count changes. Same family as F-08.

### F-13 · S3 · Phase 3 · desktop 1408
**Where:** /cheese-conversion, "Release N to mature"
**Steps:** 1. hold 2 wheels, Save 2. want to release **1** of the 2 (the plan's step, and a normal "one is ready, keep the other ageing" case)
**Expected:** a way to choose how many held wheels to release (the server accepts any 1…hold: `release()` validates `min:1|max:quantity_maturing`)
**Actual:** the release form's `quantity` is a **hidden input fixed to the saved hold**. The only way to release 1 of 2 is: return one wheel → Save maturing (hold 1) → Release 1 → move one back → Save again. That's four extra actions, and the hold briefly drops to 0. Also, after moving wheels **without saving**, the header button still reads "Release 2 to mature" while the zone shows 1. Clicking it releases the saved 2, not the visible 1. The dialog does say "Release 2 held wheel(s)…", but the visible zone contradicts it
**Evidence:** `#release-form-46` → `quantity hidden 2`; screenshot: Maturing zone "1" beside header button "Release 2 to mature"
**Console:** clean
**Suggested fix (optional):** a small number input (default = saved hold) on the release button, or release the zone's count after an implicit save; disable Release while the zone is dirty.

### F-14 · S4 · Phase 3 · desktop 1408
**Where:** /batches/26 batch information
**Steps:** 1. cut one wheel of G010426 into 18 packs 2. open the batch page
**Expected:** stock remaining per unit type, e.g. "10 wheels · 18 packs"
**Actual:** "STOCK REMAINING **29 units**" (later 28): wheels and vacuum packs added together. Same on the /batches card ("Stock: 29")
**Evidence:** batch page text "STOCK REMAINING 29 units" with 11 wheels + 18 packs on hand
**Console:** clean

### Phase 4 — Customers and the run

### F-15 · S3 · Phase 4 · desktop 1408
**Where:** /delivery-runs, stop ordering
**Steps:** 1. Add stop E2E Corner Shop (lands at position 16) and E2E Weekly Deli (17) 2. move them to positions 1 and 2
**Expected:** drag-to-reorder, a position box, or "move to top"
**Actual:** only ↑/↓, one place per click, and **every click is a full page reload that lands back at the top of the page**. Getting two new stops to the front took **30 reloads** (15 each), scrolling back down to the row each time. A new customer is always appended last, so every new shop that should be early on the route costs this. Each ↑/↓ form also posts the **entire** `positions[]` array as it stood when the page rendered, so two people reordering at once (or a stale tab) would overwrite each other's order
**Evidence:** flash "Stop order updated for Dublin Tue." 30 times; final order 1 E2E Corner Shop, 2 E2E Weekly Deli; forms `/delivery-runs/1/reorder` with full `positions[]=…` lists
**Console:** clean
**Suggested fix (optional):** drag handle (SortableJS) or a "Position" number input; at minimum keep scroll (`#stop-27` anchor) and send only the moved id + direction.

### F-16 · S4 · Phase 4 · desktop 1408
**Where:** several office forms. After saving you land somewhere other than where you were working
**Steps / Actual:**
- /customers/create → **Create customer** redirects to the **customer list**, not the new customer. The next job (special prices, run assignment) means searching for the customer you just made.
- /customers/{id} special prices: Add, Save (edit) and the duplicate refusal all reload at **scroll 0**, while the Special prices panel is at the very bottom of the page. The add-variant dropdown also still offers variants that already have a special price (the duplicate is refused on submit with a good message: "This customer already has a special price for that variant — edit the existing one instead.").
- /delivery-runs Add stop and ↑/↓ reload at the top (see F-15).
- /customers?search=Throwaway → Delete → back to the **unfiltered** list (search dropped).
**Expected:** stay on or return to the thing just edited (anchor / `redirect()->route('customers.show', …)` with a fragment)
**Evidence:** `scrollY: 0` after each special-price action; `path: /customers` after create
**Console:** clean

### F-17 · S4 · Phase 4 · desktop 1408
**Where:** /customers/create and edit → "Online integration · Mossorders user ID"
**Steps:** 1. open the customer form
**Expected:** a picker or lookup of portal accounts (by email/name), or the field hidden behind the Online Orders screen
**Actual:** a bare number input, "Link this customer to their Mossorders online portal account". An office user has no way to know a portal database id
**Evidence:** field `mossorders_user_id` type number, no lookup
**Console:** clean

### F-18 · S4 · Phase 4 · desktop 1408
**Where:** /delivery-runs → Show charge settings
**Steps:** 1. E2E Weekly Deli has `delivery_charge_percent = 5` 2. open the charge reveal
**Expected:** its stop shows that it pays 5 % (which overrides the run's fixed €6.15)
**Actual:** shows "€ charge: off" like any uncharged stop, and toggling it "on" would have no effect while the % is set. Nothing on this screen tells the office that the percentage customer is charged
**Evidence:** screenshot: row 2 "E2E Weekly Deli · € charge: off"; probe `pct=5.00 currentCharge=0`
**Console:** clean

### Phase 5 — Orders, desktop form

### F-19 · S1 · Phase 5 · desktop 1408
**Where:** /orders/{order}/edit → "Order status" dropdown (office_test)
**Steps:** 1. open the cancelled probe order ORD-20261004-002 (id 13, nothing allocated or picked) → Edit 2. Order status → **Dispatched** 3. Update order
**Expected:** status moves only along the workflow: picking via allocate/pick, dispatch via `DispatchService` (row lock, invoice for per-order customers, confirmation email, `dispatched_at`). Per CLAUDE.md "DispatchService is the one place an order goes out"
**Actual:** "Order updated successfully." The order is `dispatched` with **`dispatched_at = null`, no invoice, no dispatch confirmation, 0 allocated, 0 picked**. The order page then shows "Awaiting invoice · Finance", i.e. €12.00 of goods that never left the fridge is queued to bill. `OrderController::update()` validates `status` as any of the 7 values with no transition check (`app/Http/Controllers/OrderController.php:315`). The same dropdown would set `ready` with nothing picked (skipping `reconcilePickingStatus`), `delivered` from pending, or revive a cancelled order to `pending`. I set it back to Cancelled the same way (→ "Order cancelled.")
**Evidence:** probe after save `status=dispatched dispatched_at=null invoice_id=null emails=0 alloc=0 ful=0`; options list `pending, confirmed, preparing, ready, dispatched, delivered, cancelled`, none disabled
**Console:** clean
**Suggested fix (optional):** drop status from the edit form (it's already driven by Confirm / Cancel / pick / Dispatch / Delivered buttons), or validate allowed transitions server-side (`cancelled` only via `canBeCancelled()`, never `dispatched`/`delivered`/`ready` from here).

### F-20 · S2 · Phase 5 · desktop 1408
**Where:** /orders/create, line totals and "Order total" (before saving)
**Steps:** 1. customer E2E Corner Shop (special price 1L = €1.25, delivery charge €6.15) 2. 1L × 24, 2L × 6, 250g × 12, Farmhouse Wheel × 2, Mature Wheel × 1
**Expected:** the preview matches what will be saved: 1L line €30.00; the order total includes the delivery charge (€298.55)
**Actual:** the picker shows standard prices ("1L Bottle (€2.50)"), the 1L line previews **€60.00**, and "Order total" reads **€322.40** (no special price, no delivery charge). After saving, the order is €298.55 (1L at €1.25 ✓, + €5.00 + €1.15 VAT ✓). An office user quoting a total on the phone from the form would be €23.85 out, in the customer's disfavour
**Evidence:** screenshot of the form "Order total €322.40"; saved order page "Subtotal €292.40 · Delivery charge €5.00 · VAT (23%) €1.15 · Total €298.55"
**Console:** clean
**Suggested fix (optional):** pass `$customer->unitPriceFor()` per variant (and the current delivery charge / %) into the create form's JS when the customer changes, or label the preview "at standard prices, before delivery".

### F-21 · S3 · Phase 5 · desktop 1408
**Where:** /orders/{order} inline allocation panel (confirmed/preparing/ready)
**Steps:** 1. order 12, 1L line, 24 allocated 2. aim for the line's **Qty** box to change 24 → 30, type 30, Enter
**Expected:** one obvious quantity control per line
**Actual:** each line has two number inputs both showing "24": the allocation row's **Fulfil** quantity (form `/order-allocations/31/fulfill`) and the line's **Qty** (PATCH `/orders/12/items/38`). My input landed in the Fulfil box. Enter was blocked only because 30 > `max=24` ("Value must be less than or equal to 24."). Typing any value ≤ 24 there and pressing Enter **records a pick of that many units** (stock leaves `quantity_remaining`), with no dialog. Fulfil has no `data-confirm`; Undo exists, but the operator thought they were editing the order
**Evidence:** JS after the attempt: input in `/order-allocations/31/fulfill` holds 30, invalid; line Qty still 24; DB `a31 alloc=24 ful=0` (nothing happened only because of max)
**Console:** clean
**Suggested fix (optional):** visually separate "Picked" from "Ordered" (labels on both boxes, different widths), and/or add a confirm (or remove Enter-to-submit) on Fulfil.

### F-22 · S3 · Phase 5 · desktop 1408
**Where:** /orders/{order} → Add item
**Steps:** 1. Add item → 500g Tub 2. click the Quantity box 3. type 6
**Expected:** quantity 6
**Actual:** the box is pre-filled with **1** and clicking puts the caret after it, so the line went in as **500g × 16** (+€88.00). No preview before "Item added to order."
**Evidence:** JS before submit `q: "16"`; order subtotal jumped €287.40 → €375.40
**Console:** clean
**Suggested fix (optional):** leave it blank with `placeholder="1"`, or select-on-focus.

### F-23 · S3 · Phase 5 · desktop 1408
**Where:** /orders/create
**Steps:** 1. Organic Store 2. line 1: 1L × 5, line 2: 1L × 3 3. Create
**Expected:** one 1L line × 8 (as Add item does, "merge-by-variant"), or a warning
**Actual:** two separate 1L lines (5 and 3) on ORD-20261004-002. Add item on the order page merges into the existing line, so the two entry paths disagree, and picking, the sheet and invoices then carry two lines for one product
**Evidence:** order 13 "Items 2 · 1L Bottle 0 of 5 · 1L Bottle 0 of 3"
**Console:** clean

### F-24 · S3 · Phase 5 · desktop 1408
**Where:** delivery charge on a second same-day order
**Steps:** 1. E2E Corner Shop (flagged, run charge €6.15) already has ORD-20261004-001 for Tue 06/10 2. create ORD-20261004-003 for the same customer, same day, 1L × 12
**Expected:** one delivery charge per drop (customer + run + date), or at least a warning
**Actual:** each order snapshots the full €6.15, so the shop is charged **€12.30 delivery for one van stop**. The €15.00 top-up order costs €21.15 (41 % delivery)
**Evidence:** probe: orders 12 and 14 both `charge=6.15 tax=1.15`
**Console:** clean
**Suggested fix (optional):** design decision. Filed S3.

### F-25 · S3 · Phase 5 · desktop 1408
**Where:** customer credit limit
**Steps:** 1. E2E Weekly Deli has credit limit **€0.00** (the form requires the field) 2. create and confirm an €91.35 order
**Expected:** a warning or a block (`Customer::canPlaceOrder()` exists for this)
**Actual:** no warning anywhere. `canPlaceOrder()` is never called (grep: only its definition). The field is mandatory but purely decorative
**Evidence:** `grep -rn canPlaceOrder app resources` → `app/Models/Customer.php:201` only
**Console:** clean

### F-26 · S4 · Phase 5 · desktop 1408
**Where:** order screens. Small copy and ergonomics, grouped
**Steps / Actual:**
- /orders/{id} sibling list: a just-created order shows age **"13h"** (measured from `order_date` midnight, not created_at).
- Sibling and list summaries read "24× Mossfield Organic Milk · 6× Mossfield Organic Milk". The variant is missing, so 1L and 2L look identical.
- Cancel-order dialog buttons read **"Cancel"** and **"Cancel order"** side by side.
- Confirm order → flash **"Order updated successfully."** (not "Order confirmed").
- /orders/create: the read-only **Line total** box is in the tab order (Tab from Quantity lands on it, then on ×), so keyboard entry across lines misfires.
- /orders/create: delivery date isn't pre-filled from the customer's run day (E2E Corner Shop is on "Dublin Tue").
- Every action on the long /orders/{id} page (allocate, remove, update qty, add item) reloads at the **top**, so you scroll back to the line each time.
**Evidence:** texts quoted; `scrollY: 0` after each action
**Console:** clean

### Phase 6 — Orders, chilled-run sheet

### F-27 · S4 · Phase 6 · desktop 1408
**Where:** /chilled-runs?date=2026-10-06, a stop with two orders that day (E2E Corner Shop)
**Steps:** 1. Corner Shop has ORD-20261004-001 (ref PO-E2E-1) and ORD-20261004-003 (ref PO-E2E-2) for Tue 06/10 2. read the row
**Expected:** both refs shown (the driver hands over two POs); clear per-order loaded ticks; a clear pointer to where to edit
**Actual:** only **"Ref: PO-E2E-1"** shows, so PO-E2E-2 is invisible on the sheet. Two unlabelled loaded checkboxes sit side by side with nothing saying which order each belongs to. The edit hint reads **"Multiple orders — edit above"**, though nothing above is editable (the two order numbers are links). Notes from both orders are joined with " · " (fine)
**Evidence:** row text "ORD-20261004-001 · ORD-20261004-003 · Pending · 41/53 allocated · Ref: PO-E2E-1 · Multiple orders — edit above"; screenshot showing two stacked checkboxes left of the name
**Console:** clean

### F-28 · S4 · Phase 6 · desktop 1408
**Where:** /chilled-runs row editor (Enter order / Edit)
**Steps:** 1. Enter order for An Tairseach
**Expected:** a notes field, as on /orders/create (the Notes column is right there)
**Actual:** the Notes cell in edit mode holds only "Add line…" (and the ref box after "+ Ref"). Orders entered on the sheet can't carry a note, so I tagged E2E sheet orders through the customer ref. After Save the page reloads at the **top**: edit mode opens at `#stop-10`, but the save redirect drops the anchor (same family as F-16)
**Evidence:** editor inputs `qty[13],qty[1],qty[2],qty[14],qty[3],qty[4],qty[5],qty[15],customer_reference` — no notes; `scrollY: 0` after "Order for … saved"
**Console:** clean

### Phase 7 — Picking (phone width, factory)

### F-29 · S1 · Phase 7 · narrow 500
**Where:** /picking/{order}/items/{wheel line} as factory_test, per-piece weight box. The desktop fulfil form shares the server rule
**Steps:** 1. ORD-20261004-001 → Farmhouse Whole Wheel × 2 2. in Wheel #1 type **5,210** (comma, as an Irish keyboard or a scale display might show)
**Expected:** comma accepted as the decimal separator (→ 5.210), or refused, or at least flagged as far off "expected ~ 5.00 kg"
**Actual:** the number input drops the comma, so the value becomes **5210**. The running total reads **"5210.00 kg"** and the row turns green **"● OK"** right beside "expected ~ 5.00 kg". Once wheel #2 is weighed the Picked button would enable and submit it. The server only checks `weights.* => numeric|min:0.001` (`ValidatesFulfilmentWeights`), with no upper bound or plausibility check. So the line would store 5 210 kg and bill **€83 360** (5210 × €16/kg) instead of ~€83, and the error would only surface on the invoice, if anyone read it. (I did not submit it; I corrected to 5.210.)
**Evidence:** JS after typing: `v0: "5210", valid: true, badInput: false, total: "5210.00 kg"`; zoomed screenshot of Wheel #1 "5210" with green "● OK"; `app/Http/Controllers/Concerns/ValidatesFulfilmentWeights.php` rules quoted above
**Console:** clean
**Suggested fix (optional):** `type="text" inputmode="decimal"` with comma→dot normalisation, plus a server-side plausibility bound (e.g. ≤ 3× nominal `weight_kg` per unit, or a hard cap) and an amber "check this weight" state client-side.

### F-30 · S3 · Phase 7 · narrow 500
**Where:** /picking ("Today") as factory_test
**Steps:** 1. sign in as factory_test (lands on /picking ✓)
**Expected:** today's / the next run's work; the "Items picked" strip for that work starting 0/N
**Actual:** the queue titled **"Today"** leads with **TUE 14 JUL** (An Tairseach, Ready) and **TUE 28 JUL** (Barnhill, Ready) and ends with "NO DELIVERY DATE" (Organic Store, Ready). These are ready orders from 2–3 months ago that were never dispatched, and they stay on the picker's screen indefinitely. The strip reads **"149 / 230 · 65%"** before anything for Tuesday is picked, because it counts them; the pills read "3 ready · 0 picking · 4 to do". With no date filter and stale ready orders, the floor's progress number is meaningless
**Evidence:** page text "TUE 14 JUL · 1 … TUE 28 JUL · 1 … TUE 6 OCT · 4 … NO DELIVERY DATE · 1"; "ITEMS PICKED 149 / 230 65%"
**Console:** clean
**Suggested fix (optional):** default the queue to the next delivery date (with a day switcher), and drop `ready` orders older than N days into a "stale — tell the office" bucket.

### F-31 · S4 · Phase 7 · narrow 500
**Where:** picking flow ergonomics, grouped
**Steps / Actual:**
- **Skip** only skips one screen. After picking the next line, "Picked · Next" returns to the first unsettled line, i.e. the one just skipped (happened three times on ORD-20261004-001).
- The qty stepper's **+** stays visually enabled at the cap (20 of 20); taps do nothing.
- "Can't finish this line — mark short" sits below the fixed footer and needs a scroll on a 659 px-tall screen; the batch list is also partly under the footer until scrolled.
- /orders as factory at 500 px: the table (588 px) scrolls sideways inside a 435 px card. Status is off-screen, order numbers wrap mid-number ("ORD- / 20261004- / 001"). Fine if the factory never leaves /picking, but the sidebar offers Orders.
**Evidence:** texts and measurements above
**Console:** clean

### Phase 8 — Load and dispatch

### F-32 · S3 · Phase 8 · desktop 1408
**Where:** /chilled-runs?date=2026-10-06 → Dispatch run (office_test)
**Steps:** 1. Corner Shop: ORD-…-001 ready + loaded, ORD-…-003 not picked, not loaded 2. Weekly Deli ORD-…-004 ready + loaded 3. An Tairseach ORD-…-005 allocated, not picked, not loaded 4. Dispatch run (2) → Dispatch
**Expected:** a flash that says which **orders** went and which didn't, and why ("ORD-…-003 not loaded / not ready")
**Actual:** "**2 stops dispatched** · 1 invoice issued · 2 confirmations queued · skipped: **E2E Corner Shop**, An Tairseach Organic Farm Shop (not ready)." The count is orders, not stops. Corner Shop appears as both dispatched (via 001) and skipped (via 003) with no order number, so the office can't tell from the flash which of its two orders is stuck. The reason given is only "not ready", though both skipped orders were also not loaded. The summary strip then reads "Dispatched 1/3" (stops), which disagrees with the flash's "2 stops"
**Evidence:** flash text quoted; probe `001 dispatched … 003 preparing loaded=n … 004 dispatched … 005 preparing loaded=n`
**Console:** clean
**Suggested fix (optional):** "2 orders dispatched … skipped: ORD-20261004-003 E2E Corner Shop (not picked, not loaded); ORD-20261004-005 An Tairseach (not picked, not loaded)".

### F-33 · S3 · Phase 8 · desktop 1408
**Where:** /orders/{id}/dispatch-confirmation (emailed to the customer) and /orders/{id}/docket
**Steps:** 1. order with office notes "E2E-20261004 order 1 desktop form" 2. dispatch 3. open the confirmation / docket
**Expected:** office notes stay internal, or the form says the Notes field is customer-visible
**Actual:** both customer-facing documents print a **NOTES** block with the order's internal notes verbatim. The order form's Notes box has no hint that it goes to the customer, and on the sheet the same notes show in the driver-facing Notes column. An internal remark ("customer slow payer — check before loading") would be emailed to the shop
**Evidence:** dispatch-confirmation page text ends "NOTES · E2E-20261004 order 1 desktop form"; docket the same
**Console:** clean
**Suggested fix (optional):** separate "Notes for customer" from internal notes, or label the field "Printed on the dispatch confirmation".

### F-34 · S4 · Phase 8 · desktop 1408
**Where:** dispatch-stage odds and ends, grouped
**Steps / Actual:**
- /orders/12 (invoiced, dispatched): **Edit** is still offered. The form loads with no warning, and only on Update does it refuse ("This order is on invoice INV-000004, so editing or cancelling it would no longer match the bill. Void the invoice first.").
- Dispatch-confirmation PDF (`?download=1`) is **1.76 MB** for a one-page document (fonts embedded?). Every per-order email carries that attachment.
- Loaded tick flash "E2E Corner Shop marked loaded." doesn't name which of the stop's two orders was ticked.
- Per-order **Mark dispatched** works on an order with no loaded tick (ORD-…-005 `loaded=n`), while Dispatch run insists on loaded. Possibly intended; worth a decision.
**Evidence:** quoted texts; fetch of `/orders/12/dispatch-confirmation?download=1` → `application/pdf`, 1 761 431 bytes, `%PDF-1.7`
**Console:** clean

### Phase 9 — Finance

### F-35 · S2 · Phase 9 · desktop 1408
**Where:** per-order invoice INV-000004 (screen and PDF): `resources/views/invoicing/invoices/pdf.blade.php`
**Steps:** 1. E2E Corner Shop is flagged "Always require a customer reference" 2. ORD-20261004-001 carries ref **PO-E2E-1** 3. dispatch → INV-000004 issued 4. open the invoice / its PDF
**Expected:** the customer's PO reference (and our order number) on the invoice: that's the document their accounts team matches against a PO
**Actual:** a per-order invoice prints **neither the order number nor the customer reference**. The template only prints "Order ORD-… · ref …" headings when `$invoice->isConsolidated()` (`pdf.blade.php:10, 85–95`). The dispatch confirmation and docket do show "Customer ref: PO-E2E-1", but the invoice the customer pays against doesn't. For the customers who asked for a ref, this is the one document that needs it
**Evidence:** INV-000004 page text has no "PO-E2E-1" or "ORD-"; template lines quoted
**Console:** clean
**Suggested fix (optional):** print "Your ref: … · Order: …" in the header of every invoice (one line per order on consolidated ones, as now).

### F-36 · S3 · Phase 9 · desktop 1408
**Where:** /invoicing/payments/create (Record a payment)
**Steps:** 1. Record payment for E2E Corner Shop (one open invoice €219.59) 2. type the amount 219.59 and press Record payment, i.e. don't touch the per-invoice "Apply" box
**Expected:** the amount is applied to the open invoice(s) oldest first by default, or the form warns that nothing is applied
**Actual:** every Apply box starts **blank** (placeholder 0.00) and the summary shows "Allocated €0.00 · Left on account €219.59". Recording it would leave INV-000004 **unpaid** with the money sitting as unexplained credit. The invoice would keep ageing, and statements/overdue reminders would go to a customer who has paid. "Auto-fill oldest first" exists but is opt-in, below the fold under the Notes box. The Amount box is pre-filled "0", the same caret trap as F-22
**Evidence:** screenshot of the summary "Allocated €0.00 · Left on account €219.59" after typing the amount; after Auto-fill: "Allocated €219.59 · Left on account €0.00"
**Console:** clean
**Suggested fix (optional):** auto-fill on amount change (user can still override), or block submit with "€219.59 isn't applied to any invoice — apply oldest first?".

### F-37 · S4 · Phase 9 · desktop 1408
**Where:** invoice Void, refused paths
**Steps:** 1. INV-000004 (has payments) and INV-000006 (has a credit note) 2. look for Void
**Expected:** the plan's expectation was a visible refusal message ("Void must be refused")
**Actual:** the **Void button is simply absent** with no hint why. An office user hunting for it gets no explanation ("remove the payments / credit note first"). The server does refuse (I posted the void form directly: **403** for both), but a stale tab would show a bare 403 page rather than a flash
**Evidence:** button list on both pages has no "Void"; `fetch POST /invoicing/invoices/{7,9}/void` → 403, 403
**Console:** clean

### F-38 · S4 · Phase 9 · desktop 1408
**Where:** finance defaults and copy, grouped
**Steps / Actual:**
- /invoicing/consolidated defaults to **last month**. Shortcuts are Last month / This month / Last week: no **This week**, though weekly customers are what it's for.
- /invoicing/customers/{id}/statement also defaults to **last month** (Sept → "No activity in this period"). The obvious use, "send this month's statement", needs two dates typed.
- Customer page "NET OWED **€-10.00**" when in credit; should read "−€10.00" or "€10.00 in credit".
- INV-000006 with a credit note and no payment shows state **Partial** ("Part paid" filter), though nothing was paid.
- A **weekly-consolidated** customer created with the form's default terms ("Immediate") gets consolidated invoices **due on the issue day** (INV-000006 issued and due 4 Oct for a 6 Oct delivery). The customer form could default terms from invoicing mode, or warn.
- To Invoice offers **Issue invoice / Issue all 3** on two **Ready (not dispatched)** July orders. The page copy explains it ("anything picked but not yet dispatched"), but one click bills goods that haven't left. Worth a decision.
**Evidence:** texts quoted; probe `INV-000006 … credited=5.00 balance=86.35 state=partial`
**Console:** clean

### Phase 10 — Unhappy paths

### F-39 · S1 · Phase 10 · desktop 1408
**Where:** /invoicing/payments/create → Record payment (and, by construction, every POST form that has no confirm dialog)
**Steps:** 1. Record payment for E2E Weekly Deli: €10.00, apply €10.00 to INV-000006, reference "E2E-20261004 dblclick" 2. **double-click** Record payment
**Expected:** one payment
**Actual:** **two payments** were created (ids 6 and 7, both €10.00, same reference). INV-000006 showed paid €20.00 / balance €66.35 for €10 received. The submit button has no submit-once guard (`:disabled="!valid()"` only). I found no double-submit guard anywhere in `resources/views` or `resources/js` (grep for submit handlers or submitting flags: none), so the other no-dialog forms are exposed **by construction**: Create order, Create customer, Add item, Allocate, Fulfil, Issue invoice from the queue. I only double-clicked the payment form. Actions behind the new in-app confirm dialog are protected in practice, because the dialog closes on the first click (Confirm order double-click → confirmed once). I removed the duplicate (payment 7) through the UI → "Payment of €10.00 for E2E Weekly Deli removed; invoice balances restored."
**Evidence:** probe after the double-click `payments=4 deli=[{id:6,amount:10.00},{id:7,amount:10.00}]`, `INV6 paid=20.00 balance=66.35`; after removal `payments=3`, `INV6 paid=10.00 balance=76.35`
**Console:** clean
**Suggested fix (optional):** a global "disable submit buttons on first submit" (one Alpine/JS listener in `app.js`, beside `confirm.js`), plus an idempotency guard on payment store (e.g. reject an identical customer+amount+reference within N seconds).

### F-40 · S3 · Phase 10 · desktop 1408
**Where:** /invoicing/payments/create → Amount received
**Steps:** 1. type **-5** into Amount received (e.g. trying to record a refund)
**Expected:** refused ("must be at least 0.01"), as `min="0.01"` intends
**Actual:** the field ends up **5** (valid) and the summary reads "Left on account €5.00". `x-model.number="amount"` turns the lone "-" into NaN and wipes it, so the sign is silently dropped. Submitting would record an incoming payment of €5.00
**Evidence:** JS after typing: `val: "5", valid: true, summary "Left on account €5.00"`; input `type="number" step="0.01" min="0.01" x-model.number="amount"` (`invoicing/payments/create.blade.php:113`)
**Console:** clean
**Suggested fix (optional):** bind without `.number` and parse on submit, or `x-on:input` that keeps the raw string; let `min` do its job.

### F-41 · S4 · Phase 10 · desktop 1408
**Where:** error pages and stale-page refusals, grouped
**Steps / Actual:**
- Stale allocation (order 14 opened, the same 12 units of M041026-1 allocated elsewhere, then Allocate on the stale page) → refused, no double reservation ✓, but the message is generic: "Cannot allocate this quantity. Check available stock and order limits." It doesn't say the line is already fully allocated / someone else took it.
- 404 (`/orders/999999`, `/invoicing/invoices/999999`, `/picking/999999`) and 403 (factory on `/invoicing`, `/customers`, `/orders/12/dispatch-confirmation`) are the bare Laravel error pages ("404 | NOT FOUND", "403 | YOU DO NOT HAVE PERMISSION…") with no link back into the app or to the user's home (/picking for factory).
- (Back/refresh after a POST: no resubmission prompt, no duplicate action ✓. Back re-shows the previous flash from the browser cache, which is harmless.)
**Evidence:** quoted texts
**Console:** clean

## Confirmed correct

**Phase 1:** login autofocus, Tab order, Enter submits, generic wrong-password
message on the login key, Remember me present; every sidebar page renders
under 0.4 s with a clean console; sidebar collapse persists; mobile drawer
works at 500 px with no horizontal scroll.

**Phase 2:** milk expiry auto-fills production + 10 (14/10); batch codes
M041026 / Y041026 / G010426 and the same-day collision M041026-1; past
production date accepted with the correct ready date (+60 d); expiry before
production refused; all-blank / 0 refused; negative blocked by the browser;
cheese create shows wheels only; batch delete in-app dialog works; `/batches`
tags expired batches "Expired"; the wheel strip and /stock figures match entry.

**Phase 3:** all four cut rules refused with clear messages (the packs × nominal
message is excellent); trim 0.440 kg recorded and summed; maturing hold
persists across reload; held wheels excluded from /stock available, the
cutting count, the cut form and the batch page; release creates a Mature batch
with production carried forward and +150 d ready; lineage links both ways;
undo returns wheels to the hold and deletes the log; reconciliation holds
(10 + 1 + 1 = 12); drag both directions, select + Mature →, Return and the
quick-allocate stepper all work.

**Phase 4:** special price add / duplicate refusal / inline edit; run charge
saved; Add stop; ↑ reorder; charge toggle with the reveal staying open;
throwaway delete; customer search; `currentDeliveryCharge()` 6.15 vs 0 with
`pct=5`.

**Phase 5:** special price snapshot (1L €1.25); weight-aware wheel estimate
€160; delivery charge €6.15 split €5.00 + €1.15 with net + VAT = gross; total
to the cent; customer-ref auto-reveal; no-lines and qty-0 refusals; confirm
dialog; FIFO auto-allocate (oldest non-expired batch wins); remove / re-add an
allocation; raise shows the shortfall, lower releases the reservation; remove
line returns stock; charge edit 0 ↔ 6.15 recomputes; 5 % charge recomputes
live on a line change and backs out VAT correctly.

**Phase 6:** stop order; multi-order row merge; stock footer and crate maths
checked by hand; Enter order / + Ref / Save; Repeat last order with
non-column cheeses as added lines; ← / → history; unsaved-changes modal (Keep
editing keeps the values, Discard navigates); Confirm all hides when done;
Allocate all fills every cell and moves the footer by exactly the
reservations; edit-down releases; zero-everything cancels and keeps lines.

**Phase 7:** factory lands on /picking; **no € on any picking screen**;
stepper clamps; quick chips; per-piece weights with a running total and
submit disabled until all are weighed; undo restores stock and keeps the
reservation; mark short → amber "Order closed — short."; clean pick → green
"Order ready."; stock decremented by exactly the picked quantities; weight
billing 10.190 × 16 = 163.04.

**Phase 8:** factory sees no run-level buttons, no edit links, no €; loaded
ticks; Dispatch run dispatches only ready + loaded, skips the rest by name,
issues an invoice for per-order only, queues confirmations that the listener
sends within ~1 s; dispatch confirmation document (actual weights, short line
at €0, INVOICE TO FOLLOW) and its PDF; docket without prices; edit refused on
an invoiced order pointing at Void; Mark delivered with timestamp; per-order
Mark dispatched issues an invoice and sends the confirmation inline.

**Phase 9:** due date = issue + 14; invoice lines snapshot actual weights and
the short line at €0; invoice email + log + not-emailed filter;
consolidated preview consumes no number; issue → number; partial → paid →
credit → removal restores; exact re-payment → paid and the order mirror flips;
credit note reduces the balance and emails; statement ledger correct and the
`name="send"` path generates **and** emails; void refused server-side (403)
on paid / credited invoices; void works on a clean invoice, returns the order
to the queue, and the next issue takes the next number; dashboard finance
tiles match.

**Phase 10:** cancel returns picked stock; no resubmission on Back / refresh;
dialog-gated actions are double-click safe; stale-page allocation can't
over-reserve; clean 404s with no trace; factory 403 on finance / customers /
the dispatch confirmation; driver placeholder only.

## Deviations

1. **Site and host.** Used `php artisan serve --host=127.0.0.1:8000` instead
   of `http://mossfield.local`: my browser rules only allow typing test
   credentials on localhost/127.0.0.1/`.test`. Same code and DB.
2. **Phone width is 500 px, not 390.** Chrome's minimum window width gives a
   500 × 659 viewport (after Jon un-maximised the window). Phase 7 and the
   drawer check ran at 500.
3. **Chrome visibility.** Twice the automation tab was `hidden` (start of the
   run; after the two-tab step). Each time I stopped and asked Jon to bring
   the window forward, per the plan, rather than continuing with synthetic
   events.
4. **Two-tab test.** A background tab can't take real input, and closing tab A
   dissolved the tab group (tab B lost). The "other tab" allocation was done
   with a one-line tinker call (`allocateFromBatchItem`) while the browser held
   the stale page; the stale form was then submitted with real input. The
   server-side race was tested; the second browser tab was not.
5. **Order of sub-steps.** The cut break attempts ran before the real cut, and
   2L was marked short last (after the other lines), so an accidental
   acceptance couldn't throw off later counts. The plan's "release 1"
   needed hold-to-1 first because the UI can't release part of a hold (F-13).
6. **Extra records.** A second sheet order (Avoa Ballsbridge, ORD-…-006) for
   the zero-to-cancel test, so An Tairseach's order survived for step 8. A
   probe order for Organic Store (ORD-…-002) for the no-run / duplicate-variant
   test, cancelled. Both use existing (non-E2E) customers and are tagged via
   notes/ref.
7. **Raw milk figures** for yoghurt (60 L) and cheese (600 L) weren't in the
   plan; the form requires them.
8. **Numbering shift.** The per-order dispatch in step 8 took INV-000005, so
   the consolidated invoice is **INV-000006** (plan expected 005) and the
   re-issue after void is INV-000007.
9. **Probe column names.** The plan's step 8 probe used `to_email` /
   `emailed_at`, which don't exist on `document_emails`; I used `recipient` /
   `status` / `sent_at` (and `kind->value`, since `kind` is an enum).
10. **Two pieces of cleanup** I did through the UI to keep later figures true:
    deleted the 3 000-wheel probe batch (G041026) and removed the duplicate
    payment from F-39. Order 13 was flipped to `dispatched` and back to
    `cancelled` while proving F-19.
11. **Delete batch / customer / payment / void** actions were run on E2E data
    only (plus the run #1 charge change the plan asked for).

## Verification

1. `git status --short` → `?? docs/planImp/implemented.md` only. The plan
   expected two staged archive renames as well; the Planner had already
   committed them as `5f62755`, so there are none.
2. Step 0 baseline and step 11 final counts are both above, with the E2E
   records table ✓.
3. Every step has its DB probe output and its Check answered ✓. Step 10's
   payment check **failed** (F-39).
4. `## Findings` numbered F-01…F-41 with no gaps; each has Where / Steps (or
   Steps / Actual for grouped S4s) / Expected / Actual / Evidence / Console ✓.
5. `php artisan test --filter=AuthorizationBoundaryTest` → **21 passed (1376
   assertions)** ✓.
6. `failed_jobs` = 0 ✓ (`jobs` = 0).

## Files changed
```
?? docs/planImp/implemented.md
```
No application code, test, migration or other doc was touched. `public/build`
was not rebuilt.

## Notes for Planner

- **Self-review (after DONE, at Jon's request):** I re-checked every cited
  file/line (routes/web.php:46, Batch.php:210, OrderController.php:315,
  pdf.blade.php:10, payments/create.blade.php:113, allocation-items.blade.php:144),
  every figure in the findings (4 437 / 5 907 units, €23.85, 41 %, €240 000,
  €83 360), and the records table against the DB. All match. I corrected one
  claim (step 8: Weekly Deli's billing line was not actually observed) and
  softened F-39's scope to "by construction".

- **Cleanup decisions for you:** run #1 "Dublin Tue" now has
  `delivery_charge = 6.15` and re-numbered stops. Three existing customers
  have E2E-tagged orders (Organic Store 13 cancelled, An Tairseach 16
  dispatched + INV-000007 unpaid, Avoa Ballsbridge 17 cancelled). The listener
  also sent the two **pre-existing** queued confirmations (#2, #3) at baseline,
  to the log only.
- **Order 14 (ORD-20261004-003)** is still `preparing` with 12 units of
  M041026-1 reserved. It will keep showing on /picking and the Tuesday sheet
  until picked, shorted or cancelled.
- **Mail went only to the log**, including one dispatch confirmation addressed
  to a real-looking customer address (farmshop@antairseach.ie, step 8
  per-order dispatch as the plan asked). If the dev `.env` ever points at real
  SMTP, re-running this plan would email that shop.
- **CLAUDE.md drift** I noticed (not edited):
  - "Undo release … drops the mature item": in practice the mature batch and
    item are kept at 0/0 and reused by the next release (batch 29, item 50).
  - The plan's context said all 16 customers are on run #1 (15 stops); one
    (Organic Store) isn't on any run.
- **Not findings, but worth knowing:**
  - The dispatch-confirmation PDF and the invoice PDF are both ~1.4–1.8 MB.
  - Per-order dispatch doesn't require the loaded tick; the run-level button does.
  - Keyboard selection in long native `<select>`s is fiddly (the dropdown
    reopens on the last highlighted option; type-ahead stops at "·").
- **Things not covered**: Backup/Restore, Online Orders, Users (out of scope);
  the overdue-reminder send (Overdue page visited, nothing sent); Export CSV;
  the monthly consolidated path; picking a vacuum-pack (bulk-weighed) line on
  the phone; 390 px proper.
- **Suggested triage order:** F-39 and F-29 (money written wrongly with no
  warning); F-19 (status bypass); then F-12, F-20, F-35, F-36; then the
  stock-visibility pair F-01/F-02/F-07, which together mean expired and
  never-expiring stock both mislead the office.
