# Cycle 16d — User guide, part 3: production, stock and admin pages; stable pictures — implementation

Status: DONE
Plan revision: 1
Implementer: Opus 5.5
Date: 2026-11-07

## Baseline
HEAD: 43552f5
Pre-existing dirty files:
```
 M docs/planImp/RESUME.md
R  docs/planImp/implemented.md -> docs/planImp/archive/2026-11-05-user-guide-part-2/implemented.md
R  docs/planImp/plan.md -> docs/planImp/archive/2026-11-05-user-guide-part-2/plan.md
?? docs/planImp/plan.md
```
(`RESUME.md` is the Planner's, beside the archive rename.)

## Steps

### 0. Baseline — done
Check output:
```
$ php artisan test
  Tests:    819 deprecated, 1 passed (5517 assertions)     # 820 tests, 0 failed
$ ./vendor/bin/pint --test app tests
  PASS   ......................................................... 233 files
```

### 1. A milk batch when the demo orders need one — done
Changed: `docs/guide/demo-data.php` (`$guideEnsureMilk()` after the `GUIDE_FRESH` block and `$guideVariant`, before `$guideAllocate`/the orders; header comment gains a paragraph), `docs/guide/README.md` (the `GUIDE_FRESH=1` sentence is followed by "Use it too when the milk they were picked from has expired; the script makes a fresh milk batch when sellable milk is short.").
The helper reads `BatchItem::freeStockByVariant()` for 1L/2L. When 1L < 60 or 2L < 20 it creates, in one transaction, `Batch::create` (milk product, today, expiry today + `shelf_life_days` (10; `?: 10` fallback), raw milk 1200, `wheels_produced` null, the notes tag). Then `BatchItem::create` for 1L 600 / 2L 200 with remaining = produced and `unit_weight_kg` = the variant's `weight_kg`. The 10L Churn is untouched.
Check output:
```
$ (counts) batches=9 orders=18 items=20
$ GUIDE_FRESH=1 php artisan tinker docs/guide/demo-data.php
cancelled  GUIDE-A  id=15  ORD-20261105-001
cancelled  GUIDE-B  id=16  ORD-20261105-002
cancelled  GUIDE-C  id=17  ORD-20261105-003
cancelled  GUIDE-D  id=18  ORD-20261105-004
made batch M071126 (1L 600, 2L 200)
GUIDE-A  id=19  ORD-20261107-001  confirmed  2026-11-12  [1L Bottle=58, 2L Bottle=59, 500g Tub=60, Whole Wheel=61]
GUIDE-B  id=20  ORD-20261107-002  pending  2026-11-19  [1L Bottle=62, 250g Tub=63]
GUIDE-C  id=21  ORD-20261107-003  preparing  2026-11-10  [1L Bottle=64, 500g Tub=65, Whole Wheel=66]
GUIDE-D  id=22  ORD-20261107-004  pending  2026-11-10  [2L Bottle=67, Vacuum Pack=68]
Written /var/www/html/mossfield/docs/guide/demo-data.json
$ (counts) batches=10 orders=22 items=22
$ php artisan tinker docs/guide/demo-data.php      # plain re-run: same four lines, no "made batch"
$ (counts) batches=10 orders=22 items=22            # nothing created
$ (allocations of GUIDE-A / GUIDE-C)
GUIDE-A 1L Bottle M071126 alloc=20 ful=0
GUIDE-A 2L Bottle M071126 alloc=10 ful=0
GUIDE-A 500g Tub Y230726 alloc=12 ful=0
GUIDE-A Whole Wheel G030426 alloc=2 ful=0
GUIDE-C 1L Bottle M071126 alloc=12 ful=12
GUIDE-C 500g Tub Y230726 alloc=6 ful=0
GUIDE-C Whole Wheel G030426 alloc=1 ful=0
M071126 = batch id 26, expiry 2026-11-17
```
No `short` line. All milk sits on the new batch, none on the expired `M281026`. `/batches` is confirmed in step 5's `batches-index` picture.

### 2. Hide the clock for the capture — done
Changed: `resources/views/layouts/partials/topbar.blade.php` (`data-clock` on the clock span), `docs/guide/screenshot.mjs` (`clockCss` injected for every shot before the full-page CSS, plus a header-comment bullet), `docs/guide/README.md` (one sentence under *Screenshots*).
Check output:
```
$ node docs/guide/screenshot.mjs finance-export && md5sum …   → 826af3b4507e285f6cbc41f382a678b0
$ sleep 61; node docs/guide/screenshot.mjs finance-export && md5sum …   → 826af3b4507e285f6cbc41f382a678b0   # identical across a minute boundary
$ git diff --stat resources/views
 resources/views/layouts/partials/topbar.blade.php | 2 +-
 1 file changed, 1 insertion(+), 1 deletion(-)
```

### 3. Two small 16c carry-overs — done
Changed: `app/Http/Controllers/HelpController.php` (`Cache-Control: private, max-age=3600`), `docs/guide/getting-started.md`, `docs/guide/chilled-runs.md`.
Check output:
```
$ php artisan test --filter=HelpPagesTest → Tests: 9 deprecated (291 assertions)   # 0 failed
$ grep -n 'orders.md' docs/guide/*.md
chilled-runs.md:72:See also: [Orders](orders.md) *(office)* for one order at a time, …
getting-started.md:62:- [Orders](orders.md) *(office)*: entering, confirming, …
README.md:8:| [Orders](orders.md) | Office: … |            # table row, left as the plan says
```

### 5. The part-3 shot list and a full re-shoot — done (with deviations)
Changed: `docs/guide/screenshots.json` (15 shots added → 44), `docs/guide/images/*.png` (15 new, every other one remade).
Selectors and options as shot:
- `batches-index`: 1280×1000 viewport, `before` = two clicks on `details.batch-group[open] > summary`. After the Milk group folds, the same selector hits Yoghurt. Not `fullPage`: the folded page fits in the viewport.
- `batch-new-form`: `before` = select `#product_id` = 1 (milk), then waitFor `#variants-container input[type=number]`.
- `stock-overview`: blur `div.text-\[32px\].font-mono` (only the total value uses it).
- `products-index` and `product-page`: blur `td.mf-td.font-mono.font-medium` (the `price_label` cell only; size, weight and stock are `font-mono` without `font-medium`).
- `products-index` has `scale: 1` (see Deviations).
Check output:
```
$ node docs/guide/screenshot.mjs        → exit=0, 44 ✓, no ✗
$ md5sum docs/guide/images/finance-export.png
826af3b4507e285f6cbc41f382a678b0           # same as step 2's two runs: the clock fix holds through the full run
$ du -sh docs/guide/images → 9.3M
$ largest: product-page.png 485,305 · customer-page.png 439,305 · chilled-runs-dublin.png 375,683   # all < 500 KB
```
New pictures, opened one by one:
- `batches-index`: Milk (5 batches · 12 items) and Yoghurt folded; Cheese open with both Farmhouse batches. G030426's strip shows grey cut, black sold, amber allocated, deep-amber maturing and yellow free, with "18 free · 3 allocated · 7 maturing · 2 cut · 8 sold / 38" and the vac-pack bar (13 / 24 packs · 11 sold). G281026: 40 free. The variety header shows the totals legend.
- `batch-new-form`: Milk selected; 1L / 2L / 10L Churn quantity rows; expiry auto-filled 17/11/2026 (today + 10); "Raw milk that was bottled — must be at least the total bottled volume."
- `batch-page-cheese`: G030426. Finished weight ~188.79 kg, "9 of 38 wheels weighed", yield ~4.7%. Stock breakdown table, cutting history (trim loss 0.700 kg), Write-offs panel (form; "Nothing written off."). Actions: Cut Whole Wheel (18 left) / All cutting / Convert to Mature (18 free). No Delete button.
- `batch-page-expired`: M220626, Expired tag, red "Write off expired stock (2610 units)", write-off form, "Nothing written off."
- `cheese-cutting`: "Ready to cut", G030426 card with its strip, "Wheels left: 18".
- `cheese-cut-form`: G281026, wheels available 40; Cut date, Vacuum pack type, Wheel weight (kg) prefilled 5.000, Vacuum packs created, Total weight (kg), Trim / waste, Weight calculator; **Cut wheel** not pressed.
- `mature-conversion` (1440): G030426 "Ready to release". Farmhouse 18 / Maturing 7, "Release [7] of 7 to mature" + **Release**, **Save maturing · 7**. G281026 below: "Maturing — eligible 28/03/2027".
- `stock-overview`: total value blurred, unreadable. Milk card shows M071126 (1L 600: 568 available · 20 allocated · 12 sold; 2L 200: 190 · 10) and "3 expired batches still hold 8,940 units — see Batches." Yoghurt and cheese cards (wheels with maturing and cut). No other `€`.
- `products-index` (scale 1): every price cell blurred; the `€/kg` tags stay (no figure); stock figures readable.
- `product-page`: Farmhouse (id 3). Price column blurred; the sibling list shows "N in stock" only, no prices.
- `variant-form`: empty Add variant form; Base price shows the placeholder "€ 0.00" (no figure), so no blur.
- `write-off-reasons`: 8 reasons with Used counts ("+ 1 reversed"), Rename / Deactivate / Reactivate, Add reason.
- `users-new` (admin): empty Add user form (Name, Username, Email (optional), Initial password, Role = Office, Active). The sidebar footer reads "Admin / Administrator" (the seeded account, no staff name).
- `settings` (admin): Milk 5000 / Yoghurt 3000 / Cheese 200 with "default …", **Save**. No **Reset to defaults** button while nothing is stored (see Notes).
- `backup` (admin): Download backup with its password, table row counts (Total 242), Restore from backup with its warning, footnote.

Re-shot pictures checked: `dashboard-office` (queue: ORD-20261107-002/-004 and the old An Tairseach order; Value blurred), `order-page-picking` (ORD-20261107-001, milk on M071126 07/11/2026, yoghurt Y230726, cheese G030426), `picking-order-partly` (Cavistons ORD-20261107-003, 1L picked from M071126), `chilled-runs-dublin` (Cavistons -003 and Roundwood -004; stock footer 1L 568, 2L 190). No order shows an expired batch.

### 6. Production page — done
Changed: `docs/guide/production.md` (new, 117 lines).
Sections as the plan lists them. The prose follows the views where the plan's Context differs (Deviations 3).
Check output:
```
$ wc -l docs/guide/production.md → 117   (≤ 150)
$ bold-label grep (every **…** in resources/views, app/View, app/Enums, app/Http/Controllers)
MISS: 1. Set wheels aside.          # my step headings, not screen labels
MISS: 2. Release them.
MISS: Cut Whole Wheel (N left)      # the button is "Cut {{ variant name }} ({{ n }} left)", batches/show.blade.php:309–310
$ php artisan test --filter=HelpPagesTest → passes (after step 4)
```
Browser: as `office_test`, `/help/production.md` → 200 with all seven pictures loaded (Verification 6).

### 7. Stock page — done
Changed: `docs/guide/stock.md` (new, 57 lines).
Check output:
```
$ wc -l → 57   (≤ 110)
$ bold-label grep → no misses
```
Browser: as `factory_test` and `office_test`, `/help/stock.md` → 200, five pictures loaded, no `€` figure in the page text.

### 8. Admin page — done
Changed: `docs/guide/admin.md` (new, 55 lines).
Check output:
```
$ wc -l → 55   (≤ 80)
$ bold-label grep
MISS: Restore & replace all data    # in the view as "Restore &amp; replace all data" (backup/index.blade.php:131), so it is the label
```
Browser: as `admin`, `/help/admin.md` → 200 with `users-new`, `settings` and `backup` loaded. As `office_test`, `/help/admin` → 403 and `/help/images/users-new.png` → 403.

### 4. An `admin` audience — done (after step 8, once the three pages existed)
Changed: `app/Http/Controllers/HelpController.php` (`AUDIENCE` gains `production`/`stock` → `all`, `admin` → `admin`. `canRead()` is a `match`: `all` → true; `admin` → `auth()->user()?->hasRole('admin')`; anything else, a missing key included → `see-financials`. Docblocks updated.) `tests/Feature/HelpPagesTest.php`:
- New `$admin` user.
- `test_every_guide_page_renders_with_its_title` and `test_every_picture_a_page_uses_exists_and_is_served` act as admin.
- `test_every_guide_page_has_an_audience` allows `admin`.
- `test_the_floor_cannot_open_office_pages_or_their_pictures` now walks factory, office and admin against a `readable()` map (page and `.md` → 200/403; each picture → 200 only when a page that user may read uses it). It also asserts there is at least one office-only and one admin-only picture.
- `test_the_floor_index_lists_only_its_pages` checks every page link for all three users, plus the plan's spelled-out cases: factory sees no `admin.md`, office sees no `admin.md`, admin sees all nine.
Check output:
```
$ php artisan test --filter='HelpPagesTest|AuthorizationBoundaryTest'
  Tests:    30 deprecated (1952 assertions)     # 0 failed
$ ./vendor/bin/pint --test app tests → PASS 233 files
```

### 9. Index, getting started, pointers — done
Changed:
- `docs/guide/README.md`: the two *(to write)* rows become Production / Stock / Admin rows, worded as the plan gives them. *Who can read what* names the admin page.
- `docs/guide/getting-started.md`: *Next* gains Production, Stock and Admin *(admins)*.
- `CLAUDE.md`: "(`all`, `office` = the `see-financials` gate, or `admin`)".
Check output:
```
$ grep -c 'to write' docs/guide/README.md → 0
$ php artisan test --filter=HelpPagesTest → passes
```

## Deviations
1. **Step order.** Step 4's code and tests went in after steps 6–8, as the plan allowed ("or do this step after 8; say which").
2. **`products-index` at `scale: 1`, not 1.25.** At 1.5 it was 649 KB; at 1.25, 518 KB, still over 500 KB; at 1, 366 KB. It is a tall page (3,681 px at 1.5), so it needed the extra step. Every other new shot keeps the default 1.5.
3. **Prose follows the screens where the plan's Context differs:**
   - **Delete** for a batch is on the `/batches` card, not the batch page. When something depends on the batch it is greyed out, with the reason as a tooltip. `deletionBlocker()` suggests setting the status to sold out or expired instead; the page says so.
   - The Mature release control is "Release [n] of 7 to mature" with a **Release** button, not a single "Release N to mature" button. The hold button reads **Save maturing · 7**.
   - **Reset to defaults** appears only once a ceiling has been stored. Dev has none stored, so the `settings` picture has no such button; the page says when it appears.
   - Roles are labelled **Administrator** / **Office** / **Factory** / **Driver** (`UserRole::label()`).
   - Cutting lists **Cut wheel** per item; the batch page's button is "Cut Whole Wheel (N left)".
4. **`batches-index` is a viewport shot.** With Milk and Yoghurt folded the page fits in 1280×1000, so it is not `fullPage`. The plan offered both.
5. **The plan's step 1 check "`/batches` shows the new batch under Milk with 600 + 200"** is shown by `stock-overview` instead: `batches-index` folds Milk. The stock picture shows M071126 1L 600 bottles and 2L 200 bottles.

## Verification
1. `php artisan test` → `Tests: 819 deprecated, 1 passed (5696 assertions)` = **820 tests, 0 failed**. The count is unchanged: the help tests were extended in place, not added.
2. `./vendor/bin/pint --test app tests` → `PASS … 233 files`.
3. `node docs/guide/screenshot.mjs` → exit 0, **44 ✓**. Total 9.3 MB; largest `product-page.png` 485,305 bytes. `finance-export.png` md5 `826af3b4…` in step 2's two runs and after the full run.
   - Two old pictures came out byte-identical: `sidebar-help` and `picking-item-wheel`. They are absent from `git status`.
   - The other 27 changed: their screens show the new demo orders, or today's dates in a form.
4. `php artisan tinker docs/guide/demo-data.php` (no fresh) → the four orders ids 19–22, no `made batch`; `batches=10 orders=22` before and after.
5. `git status --short`:
   - Application changes: `app/Http/Controllers/HelpController.php`, `resources/views/layouts/partials/topbar.blade.php`, `tests/Feature/HelpPagesTest.php` (`git diff --stat -- app resources routes tests database` → 3 files, +68 −43).
   - No migrations; `demo-data.json` absent (gitignored).
6. Headless Chrome (puppeteer-core, script in the session scratchpad):
   - `factory_test`: `/help` lists getting-started, chilled-runs, picking, production, stock (5). `/help/stock.md` → 200, five pictures loaded, no `€` figure in the text. `/help/production.md` → 200, seven pictures. `/help/admin` → 403.
   - `office_test`: `/help` lists 8 pages (no admin). `/help/production.md` → 200 with all seven pictures. `/help/admin` → 403, `users-new.png` → 403.
   - `admin`: `/help` lists 9; `/help/admin.md` → 200 with three pictures.
   - Console errors: only the deliberate 403 loads.

## Files changed
```
 M CLAUDE.md
 M app/Http/Controllers/HelpController.php
 M docs/guide/README.md
 M docs/guide/chilled-runs.md
 M docs/guide/demo-data.php
 M docs/guide/getting-started.md
 M docs/guide/images/<27 re-shot pictures>
 M docs/guide/screenshot.mjs
 M docs/guide/screenshots.json
 M docs/planImp/RESUME.md                          (pre-existing, the Planner's)
R  docs/planImp/{plan,implemented}.md -> archive/2026-11-05-user-guide-part-2/   (pre-existing)
 M resources/views/layouts/partials/topbar.blade.php
 M tests/Feature/HelpPagesTest.php
?? docs/guide/{production,stock,admin}.md
?? docs/guide/images/<15 new pictures>
?? docs/planImp/implemented.md
?? docs/planImp/plan.md                            (pre-existing, the Planner's)
```
Dev data: batch `M071126` (id 26, 1L 600 / 2L 200, notes tagged). GUIDE-A–D re-made as orders 19–22; 15–18 cancelled with their stock returned.

## Notes for Planner
1. **Pictures with today's date in a form field** still change daily, even with the clock hidden: `batch-new-form` (production and expiry date), `batch-page-cheese` and `batch-page-expired` (write-off date), `cheese-cut-form` (cut date). The order pictures show order dates too. That is the screens' own content, as the plan expected ("a page with no date content gives a byte-identical PNG"). Hiding those would need a `before` that sets a fixed date; not done.
2. `backup.png` shows row counts per table, so it changes whenever data does. Harmless.
3. **The floor sees base prices and the stock value in the app** (`/products`, `/stock`). The pictures blur them, as the plan says. The decision on whether the app should show them to the floor is still for `OPEN_QUESTIONS.md`, as the plan noted; I didn't add it there.
4. `stock.md` and `production.md` (everyone pages) link to office/admin pages marked *(office)* / *(admins)*, following the 16c convention. A floor user who follows one gets the 403 page.
5. `/write-off-reasons` is in the `role:admin,office` group (checked with `route:list -v`), so the floor can read the reasons only through the `write-off-reasons` picture on `stock.md`. The page says "(office only)". Consistent, nothing to change.
6. `M071126` expires 2026-11-17. After that, `GUIDE_FRESH=1` makes another milk batch by itself (step 1). The plain run does not: if the current GUIDE orders' milk expires, run the fresh path.
