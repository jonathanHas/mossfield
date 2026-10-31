# Cycle 16b — User guide, part 1: help pages and the order flow — implementation

Status: DONE
Plan revision: 1
Implementer: Opus
Date: 2026-10-29

## Baseline
HEAD: 862e293
Pre-existing dirty files:
```
 M docs/planImp/RESUME.md
 M package-lock.json
 M package.json
?? docs/guide/
?? docs/planImp/plan.md
```

## Steps
### 0. Baseline — done
Check output:
```
php artisan test
  Tests:    809 deprecated, 1 passed (5184 assertions)   → 810 tests, 0 failed
./vendor/bin/pint --test app tests database
  PASS ... 304 files
```

### 1. The help routes, controller and view — done
Changed: `app/Http/Controllers/HelpController.php` (new), `resources/views/help/show.blade.php` (new), `routes/web.php` (three routes after the `/picking` routes + `use`), `resources/css/app.css` (`.mf-prose` at the end of `@layer components`); `npm run build`.
Check output (curl with a cookie jar, signed in as `office_test`):
```
GET /help                                   200  "Mossfield user guide"  href="http://mossfield.local/help/orders.md"
GET /help/orders                            200  <h2>Find an order</h2> ×1  <img src="images/orders-list-organic-store.png"
GET /help/orders.md                         200  <h2>Find an order</h2> ×1  <img src="images/orders-list-organic-store.png"
GET /help/images/orders-list-organic-store.png  200 image/png
GET /help/planimp                           404
GET /help/images/x.svg                      404
GET /help/..%2Fplanimp                      404 (Apache refuses the encoded slash before Laravel)
signed out GET /help                        302 → /login
```
First pass the index carried `href="orders.md"` — see Deviations 1.

### 2. Help in the sidebar — done
Changed: `resources/views/layouts/navigation.blade.php` (last group, `label => null`, gated `$canSeeOrders`; `'help'` icon).
Check output:
```
office_test  /dashboard  href="http://mossfield.local/help" ×2 (desktop + responsive lists)
factory_test /dashboard  href="http://mossfield.local/help" ×2
/help/orders: Help link carries style="color: var(--ink); font-weight: 600; background: var(--panel); ..." —
              identical to the Settings link on /settings as admin
```

### 3. Tests — done (test 5 added after step 7)
Changed: `tests/Feature/HelpPagesTest.php` (new), `tests/Feature/AuthorizationBoundaryTest.php` (`'page' => 'orders'`, `'file' => 'orders-list-organic-store.png'`).
Check output:
```
php artisan test --filter='HelpPagesTest|AuthorizationBoundaryTest'
  Tests:    26 deprecated (1509 assertions)   → 26 passed, 0 failed (5 HelpPagesTest so far)
```

### 4. Demo data for the pictures — done
Changed: `docs/guide/demo-data.php` (new). Guarded (`local` + `mossfield_cl`), idempotent on `customer_reference`, no deletes. Variants are found by product + variant name, customers by name (no hard-coded ids). Allocation mirrors `AllocatesOrderStock` (allocatable → `isAvailableForAllocation()` → FIFO by `production_date`).
Check output — first run, then the second run (identical, nothing created):
```
GUIDE-A  id=11  ORD-20261029-001  confirmed  2026-10-29  [1L Bottle=36, 2L Bottle=37, 500g Tub=38, Whole Wheel=39]
GUIDE-B  id=12  ORD-20261029-002  pending  2026-11-05  [1L Bottle=40, 250g Tub=41]
GUIDE-C  id=13  ORD-20261029-003  preparing  2026-11-03  [1L Bottle=42, 500g Tub=43, Whole Wheel=44]
GUIDE-D  id=14  ORD-20261029-004  pending  2026-11-03  [2L Bottle=45, Vacuum Pack=46]
Order::where('customer_reference','like','GUIDE-%')->count()  → 4
o11 every line alloc = ordered, ful 0 (confirmed + allocated); o13 i42 1L ord 12 alloc 12 ful 12, other lines allocated
o11/o12 delivery_charge 12.30 (Organic Store's run charge, two different dates); o13/o14 0.00
/picking?date=2026-10-29 (factory_test): "1 to do" … "Organic Store ORD-20261029-001 · 44 items To do"
/chilled-runs?run=1&date=2026-11-03: Cavistons row with ✓ alloc sub-lines (12, 6, 1), Roundwood Stores row, 2 × "Pending"
```

### 5. The shot manifest for part 1 — done
Changed: `docs/guide/screenshots.json` (the four test shots replaced by the 15-shot part-1 list). Ids from step 4: A = 11 (A-milk = 36, A-wheel = 39), B = 12, C = 13; GUIDE-A date 2026-10-29, GUIDE-C/D date 2026-11-03. The four test PNGs were removed and regenerated under the new names.
Manifest choices beyond the plan's table (all manifest options the script already had):
- `sidebar-help`: `clip: "aside"` (the sidebar is an `<aside>`; the `<nav>` inside it scrolls) and `height: 1300` — at 1100 the nav scrolled and Help was below the fold.
- `chilled-runs-enter-order`: `height: 1250` — at 800 Barnhill's row (stop 7) was off screen.
- `picking-item-wheel`: `height: 1320` instead of fullPage — fullPage left the fixed footer floating over Wheel #1.
- `order-page-picking` `scale: 1`, `order-page-ready` `scale: 1.25` — see step 6.
Check output: `node docs/guide/screenshot.mjs` → 15 × ✓, exit 0. Every PNG opened with Read and compared with the table:
```
dashboard-office          office dashboard, to-do line, tiles, order queue (B and D pending, order 3 unallocated)  ✓
sidebar-help              full office sidebar, Help at the bottom                                                 ✓
orders-list-organic-store list filtered to Organic Store                                                         ✓
order-new-form            empty create form (+ Customer ref, Create order)                                        ✓
order-page-pending        ORD-20261029-002, Pending, Confirm order button, Update/Remove per line                ✓
order-page-picking        ORD-20261029-001, Confirmed, inline picker: 4 lines allocated, wheel weight rows        ✓
order-page-ready          ORD-20260723-002 (order 5), Ready, Docket / Loaded onto van / Mark dispatched (grey)     ✓
chilled-runs-dublin       Dublin Tue 03/11, 15 stops, Cavistons 12/19 picked + ✓ sub-lines, Roundwood Pending/Not allocated, footer rows  ✓
chilled-runs-enter-order  Barnhill Stores row in edit mode (boxes, Repeat last order, ←/→, Save, Cancel, + Ref, Add line…, Notes)  ✓
picking-queue             Today, 1 to do, Organic Store To do, stale bucket (Barnhill), Start picking             ✓
picking-order             Organic Store, 0 of 4 lines picked                                                       ✓
picking-item-fixed        Pick item 1 of 4, 20 bottles, batch M281026 "20 reserved for this line"                 ✓
picking-item-wheel        Pick item 4 of 4, 2 wheels, Running total, Wheel #1/#2 weight boxes, Weigh each piece first  ✓
picking-order-done        Order ready. (order 5), 5 lines picked · 9.1 kg                                          ✓
picking-order-partly      Cavistons, 1 of 3 lines picked, milk ticked + struck through                            ✓
```
The 1440 sheet shot shows every column through Notes with nothing cut off (judged from the picture; scroll width not measured).

### 6. Picture size — done
Changed: `docs/guide/screenshot.mjs` (`shot.scale ?? 1.5`, the only script change).
Check output:
```
first full run at 1.5×:  du -sh docs/guide/images → 2.9M; largest order-page-picking.png 566 288 B, order-page-ready.png 425 353 B
no PNG optimiser on the box (pngquant/optipng/oxipng/zopflipng all absent)
order-page-picking scale 1.25 → 446 525 B; scale 1 → 323 857 B (still readable: 1280 px wide, native size)
order-page-ready  scale 1.25 → 337 500 B
final: du -sh docs/guide/images → 2.7M; largest chilled-runs-dublin.png 353 338 B, then order-page-ready.png 338 119 B
```
Nothing over 400 KB. See Deviations 2.

### 7. The four pages and the index — done
Changed: `docs/guide/getting-started.md` (new), `docs/guide/orders.md` (rewritten), `docs/guide/chilled-runs.md` (new), `docs/guide/picking.md` (new), `docs/guide/README.md` (index table + demo-data line); `tests/Feature/HelpPagesTest.php` test 5 added.
Check output:
```
bold labels: every **…** in the four pages extracted (107 unique) and grepped case-sensitively, fixed strings, in resources/views + app/View:
  first pass: 2 missing — "Tuesday · Dublin Tue", "Thursday · Wicklow Thu" (tab names are built from run data, not view text) → unbolded, quoted instead.
  Also unbolded "Wheels" / "Vacuum packs" in picking.md (emphasis, not UI labels; they matched only case-insensitively).
  Fixed in prose after reading the views: the queue's in-progress tag is "3/7 picked", not "Picking"; the run-sheet row button is "Save", not "Save order".
  second pass: 107 labels, 0 missing.
php artisan test --filter='HelpPagesTest|AuthorizationBoundaryTest'  → Tests: 27 deprecated (1577 assertions) — 27 passed, 0 failed (6 HelpPagesTest)
headless Chrome (puppeteer-core, console listener on):
  office_test: sidebar Help → /help; each page opened from the index link → title + every image ✓ loaded; "← All help pages" → /help
    getting-started: 2/2 images, orders: 5/5, chilled-runs: 2/2, picking: 6/6; cross-page links resolve to /help/<page>.md
  factory_test: sidebar Help (from /dashboard) → /help; /help/picking "Picking", all images loaded
  console errors: []
```
Read-through: each page read against its pictures and the views it names; mismatches found were fixed in the prose (above) — nothing in a screen was changed. Remaining observations are under Notes for Planner.

### 8. Pointers in the project docs — done
Changed: `CLAUDE.md` (one Key Docs bullet). `docs/planImp/README.md` untouched — no natural place.
Check output:
```
grep -n 'docs/guide' CLAUDE.md → 14:- **`docs/guide/`** — the user guide, served at `/help` (`HelpController`, Help in the sidebar). …
```

## Deviations
1. **Index links made absolute (step 1).** The plan says no link rewriting is needed. That holds under `/help/<page>`, but the index is served at `/help` (no trailing slash; Apache's `.htaccess` strips one), where `href="orders.md"` resolves to `/orders.md` → 404, and `images/x.png` to `/images/x.png`. `HelpController::render()` therefore rewrites `href="<name>.md"` / `src="images/<name>.png"` to `route('help.show', …)` / `route('help.image', …)` **on the index only**; pages keep the relative paths the plan's step-1 check expects.
2. **Per-shot scale below 1.5 on two pictures (step 6).** The plan sets the default to 1.5 and wants the order-page shot under 400 KB. At 1.5 the confirmed order page (the inline picker, the tallest page) was 566 KB and the ready page 425 KB, and the box has no PNG optimiser. Rather than change the script again, the manifest carries `scale: 1` on `order-page-picking` and `1.25` on `order-page-ready` (the `scale` option already existed). The default stays 1.5.
3. **`orders.md`: "Read the order page" replaced, not kept.** The plan says keep the three existing sections. "Find an order" and "What the statuses mean" are kept (re-checked; "Delivery from" added). The third section described one picture (`order-page-organic-store.png`, now `order-page-ready.png` in the plan's manifest) top to bottom; its content is now spread over the plan's task sections (*Confirm, or cancel*, *Allocate and watch the picking*, *Change an open order*, *Send it out*) so nothing is said twice. Added one section the plan did not list, *Allocate and watch the picking*, to carry the `order-page-picking` picture the plan asks for.
4. **Manifest heights/clip (step 5)** as listed under step 5 — needed for the shots to show what the table promises.

## Verification
1. `php artisan test` → `Tests: 815 deprecated, 1 passed (5317 assertions)` — **816 tests, 0 failed** (810 + 6 in `HelpPagesTest`). Deprecations are the pre-existing `PDO::MYSQL_ATTR_SSL_CA` notice.
2. `./vendor/bin/pint --test app tests` → `PASS … 233 files`.
3. `node docs/guide/screenshot.mjs` → 15 × ✓, no ✗, `EXIT 0`.
4. `php artisan tinker docs/guide/demo-data.php` (third run) → the same four GUIDE lines (ids 11–14), nothing created.
5. `grep -n 'Route::' routes/web.php | tail -1` → `326:Route::fallback(fn () => abort(404));` — still the last route and the last line.
6. `git status --short` → below; nothing in `app/` except `HelpController.php`; no migrations.
7. Browser (headless Chrome, step 7 output): office — sidebar Help → `/help` → all four pages open, pictures load, "← All help pages" returns; factory — `/help/picking` opens from the dashboard sidebar. No console errors.

## Files changed
```
 M CLAUDE.md
 M docs/planImp/RESUME.md                     (pre-existing — Planner's)
 M package-lock.json                          (pre-existing — puppeteer-core)
 M package.json                               (pre-existing — puppeteer-core)
 M resources/css/app.css
 M resources/views/layouts/navigation.blade.php
 M routes/web.php
 M tests/Feature/AuthorizationBoundaryTest.php
?? app/Http/Controllers/HelpController.php
?? docs/guide/                                (pre-existing untracked; now: README.md, orders.md rewritten, getting-started.md,
                                               chilled-runs.md, picking.md, demo-data.php, screenshots.json, screenshot.mjs (scale),
                                               images/ — 15 PNGs, the 4 test PNGs replaced)
?? docs/planImp/implemented.md
?? docs/planImp/plan.md                       (pre-existing — Planner's)
?? resources/views/help/
?? tests/Feature/HelpPagesTest.php
```
`public/build` was rebuilt (`npm run build`, gitignored). Dev DB: four GUIDE orders added (ids 11–14, see step 4); Cavistons' 12 × 1L picked from M281026.

## Notes for Planner
1. **"Save order" is not on the run sheet.** The plan's word list puts it there; it is the delivery-runs reorder button (`delivery-runs/index.blade.php`). The run-sheet row button is **Save**. The guide uses Save.
2. **The picking queue has no "Picking" tag** — an in-progress order shows "3/7 picked" (`picking/index.blade.php:106`). Guide says so.
3. **Help from the phone shell (16c).** Factory users land on `/picking`, which has no sidebar; to reach Help they go to the dashboard first (the home icon top right of the queue). `picking.md` is written for them, so a small Help link in the picking shell now looks worth it.
4. **Full-page office shots end the sidebar after one screen.** The sidebar is `h-screen md:sticky`, so in a full-page capture it stops at viewport height (the Miscellaneous group is cut, then plain background). Cosmetic, only in pictures; a fix would be a screenshot-only style, not a view change.
5. **Pictures are dated.** The manifest hard-codes `?date=2026-10-29` (picking) and `2026-11-03` (run sheet), and demo-data's dates are computed once; re-running it later leaves the old orders. In a few weeks the dashboard and order list will show these orders as overdue. For 16c: either give demo-data a `--fresh` that cancels the GUIDE orders and makes new ones, or accept that pictures are retaken when the data is reset.
6. **`order-new-form` shows an empty form**, so the run-name hint under Delivery date and the `~` estimate the page describes are not in the picture. The script cannot fill a form; a `beforeShot` option (select a customer, add a cheese line) would be the way if wanted.
7. **Office list/order pages show the dev data's money.** The plan says no € figures in prose — there are none — but the pictures carry them (order totals, dashboard). Fine for office pages; the factory pictures have none.
8. `/help/..%2Fplanimp` is refused by Apache itself (encoded slash, `AllowEncodedSlashes` off) before Laravel; inside the test suite Laravel's 404 answers it. Both are 404.
9. The dashboard picture shows the dev box's "Expired stock 3 · 4,326 units" — dev data, harmless, but 16c's stock page will want a cleaner example.
