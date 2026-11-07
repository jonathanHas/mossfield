# Cycle 16d — User guide, part 3: production, stock and admin pages; stable pictures

Status: ACCEPTED
Revision: 1
Planner: Fable 5.1
Date: 2026-11-07 (accepted 2026-11-07)

Read `docs/planImp/planimp.md` first. The Implementer writes
`docs/planImp/implemented.md`; it does not edit this file.

## Goal

The last three guide pages — **Production** (batches, cutting, maturing),
**Stock** (the stock overview, products and variants, write-offs) and
**Admin** (users, settings, backup) — with their pictures, plus the four
small carry-overs from the 16c review: a milk batch so the demo orders can be
remade, the top-bar clock hidden for the capture so an unchanged screen gives
an unchanged picture, a `private` cache header on guide pictures, and two
prose links marked *(office)*. After this cycle the README has no *(to
write)* rows.

## Context

**Where the guide stands.** Seven pages in `docs/guide/` served at `/help`
by `HelpController` (cycle 16b), each with an audience in
`HelpController::AUDIENCE` — `all` (anyone in the shared route group) or
`office` (passes `see-financials`); a page missing from the map is `office`;
a picture is served only when a page the user may read references it
(cycle 16c). `HelpPagesTest` (9 tests) pins the map against the folder,
walks every page and picture as factory/office/admin, and checks the floor's
index. Pictures come from `node docs/guide/screenshot.mjs` (per-shot
options `name, as, url, width, height, scale, mobile, fullPage, clip,
waitFor, before, blur`; URL placeholders from the gitignored
`docs/guide/demo-data.json`); demo orders from
`php artisan tinker docs/guide/demo-data.php` (`GUIDE_FRESH=1` cancels and
remakes them). 29 pictures, 5.7 MB. The README has two *(to write)* rows:
"Production and stock" and "Admin".

**The screens this cycle documents** (read each view in full before
writing; the labels below are from the code and `CLAUDE.md`, but **the
prose follows the views where they differ** — in 16c the plan's wording was
wrong in seven places and the Implementer was right to follow the screen):

- *Production* group (admin/office/factory): **Batches** (`/batches`, the
  three-level `<details>` browser, all levels open by default; card header
  wheel strip — yellow free, amber allocated, deep amber maturing, grey cut,
  black sold, purple converted, red written off — and the vac-pack bar;
  "Matured from / into" lineage; `?status=expired`; "Write off →" on an
  expired card), the **new batch form** (`/batches/create`: product,
  production date, expiry auto-filled from the product's shelf life, raw
  milk litres, one quantity per variant; the sanity ceiling asks for a
  "Yes, this batch really is that big" tick; milk bottled must not exceed
  raw milk), the **batch page** (`/batches/{batch}`: info rows, finished
  weight and yield with the `~` prefix and "N of M wheels weighed", "On
  hand (nominal)", cut buttons reading free wheels, the **Write-offs** panel
  — form with item, quantity, weight for weighed variants, reason, note,
  date; history with **Reverse**; the one-click "Write off expired stock (N
  units)" on an expired batch — and **Delete** only when
  `Batch::deletionBlocker()` allows), **Cheese Cutting** (`/cheese-cutting`
  and the cut form: wheel weighed before cutting, packs, pack weight, trim
  is the difference; rules in `CheeseCutRequest`), **Mature Conversion**
  (`/cheese-conversion`: the allocator — Farmhouse and Maturing zones, drag
  or select, **Save maturing** persists the hold; **Release N to mature**
  once the batch is old enough; undo from the Mature batch's page). Factory
  reads all of these; writes are office/admin.
- *Miscellaneous*: **Stock** (`/stock`: one card per type, segments
  available / allocated / sold / written off, cheese wheels add cut and
  maturing; rows per variant and batch, FIFO; the expired-stock note on a
  card; **the total value in € at the top**), **Products** (`/products`
  grouped cards with each variant's price and free stock; `/products/{id}`
  master-detail; variant form — name, size, unit, weight, price, variable
  weight, bulk weighed, priced by weight, active; "Stock" is free sellable
  stock, the same figure the allocator uses), **Write-off reasons**
  (`/write-off-reasons`, office/admin: add, rename, deactivate; no delete).
- *Admin* group (admin only): **Users** (`/users`: list, add, edit,
  deactivate/reactivate; roles admin / office / factory / driver; username
  login, email optional; the last active admin cannot be demoted,
  deactivated or deleted; a role change or deactivation signs that user out
  everywhere; an admin cannot demote or deactivate themselves),
  **Settings** (`/settings`: the three batch ceilings, **Reset to
  defaults**), **Backup & Restore** (`/backup`: download, restore from a
  file, the warning that a restore resets invoice numbering).

**Dev data on 2026-11-07** (all ids are this box's; the manifest uses
placeholders only for orders — batch and item ids below are stable on dev
and may be hard-coded as the finance shots hard-code invoice 2):

| batch | id | items | why it matters |
|---|---|---|---|
| G030426 Farmhouse | 17 | wheel item 26: 28 of 38 left, **7 on maturing hold**; vac-pack item 29: 13 of 24; 2 cut logs | the rich cheese batch page: strip with every state, finished weight, packs |
| G281026 Farmhouse | 24 | wheel item 43: 40 of 40 | the cut form (`/cheese-cutting/cut/43`) |
| M220626 Milk | 16 | items 24/25: 1,302 + 1,308 left, **expired 2026-07-02** | the expired batch page with "Write off expired stock" and the write-offs panel |
| M281026 Milk | 22 | 1L 5,972 of 6,000, 2L 100 | **expired 2026-11-07** — the only milk the demo orders could use; GUIDE-A and GUIDE-C hold units on it |
| Y230726 Yoghurt | 20 | 250g 295, 500g 276, no expiry | yoghurt for the demo orders, never expires |

Six `stock_write_offs` rows (batches 19, 22, 23; three active, three
reversed), 8 reasons, 7 users (real staff names — **no picture of the users
list**), `settings` empty (= defaults), no conversion logs. Dashboard:
Expired stock 4 batches / 10,398 units.

**Why a milk batch comes first.** `M281026` expired today. `GUIDE_FRESH=1`
now prints "20 short on 1L Bottle" for GUIDE-A and GUIDE-C's milk pick
throws, and the current GUIDE orders sit on expired stock, so every order,
run-sheet and picking picture is wrong until there is sellable milk. The
demo script makes the batch itself (step 1) so the fix survives the next
expiry.

**Why the clock.** `layouts/partials/topbar.blade.php` line 43 prints
`now()->format('D, j M · H:i')` in every office page's top bar, so a full
re-shoot rewrites all 23 office pictures (~5 MB of new blobs) even when no
screen changed. Hidden for the capture, a page with no date content gives a
byte-identical PNG.

**Prices on everyone-pages.** The rule from 16c: a picture on an `all` page
must not show prices. `/stock` shows the total value and `/products` shows
each variant's price, to the floor as well — those are the app's own
choices (base prices, not customers' money) and are not changed here — but
the committed pictures blur them. *(Note for Jon, no step: whether the floor
should see the stock valuation and base prices in the app at all is a
decision for `OPEN_QUESTIONS.md`, not for a guide cycle.)*

**Carry-overs closed rather than carried.** A consolidated preview with
content (`GUIDE-E`) would need a weekly customer's dispatched, uninvoiced
demo order left sitting in Finance as real work; the empty-state picture and
the prose stand. The dev box's expired stock stays (it is the expired-batch
picture).

## Constraints

- Dev box only (`mossfield.local`, `mossfield_cl`); `demo-data.php` keeps
  its `local` + database-name guard; nothing on production.
- No real sends and no state changes while shooting: never press **Cut**,
  **Save maturing**, **Release**, **Write off**, **Reverse**, **Delete**,
  **Restore**, **Download backup**, **Save** on Settings, or anything on
  `/users`. A `before` may select and type, not submit.
- Pictures are committed and `/help` is open to the floor: **no picture of
  the users list or a user's edit page** (real staff names); the users
  picture is the empty **Add user** form. On `all` pages no `€` figure may
  be readable in a picture (blur); no `€` figures in the prose.
- `docs/guide/demo-data.json` stays gitignored; no migrations; application
  code changes are limited to `HelpController.php`, `topbar.blade.php` and
  `HelpPagesTest.php`.
- The Planner does not edit application code; the Implementer does not edit
  this plan. Every departure goes in `implemented.md` → Deviations.
- Pages: `production.md` ≤ 150 lines, `stock.md` ≤ 110, `admin.md` ≤ 80.
  Every bold label must exist verbatim in the views (grep it).

## Out of scope

- Changing what the floor sees on `/stock` or `/products` in the app.
- Writing off the dev box's expired stock.
- A populated consolidated preview (`GUIDE-E`) — closed, see Context.
- The driver role, Mossorders, the API.
- Cycle 17 (delete guards, parked cycle 12).

## Steps

### 0. Baseline
Check: `php artisan test` → 820 tests, 0 failed; `./vendor/bin/pint --test
app tests` clean; `git status --short` → only the archive rename of the
16c files (`R docs/planImp/{plan,implemented}.md → archive/2026-11-05-user-guide-part-2/`)
and nothing else. Record HEAD (`43552f5`).

### 1. A milk batch when the demo orders need one
Files: `docs/guide/demo-data.php`, `docs/guide/README.md`
What: after the `GUIDE_FRESH` block and before the orders, add
`$guideEnsureMilk()`: read `BatchItem::freeStockByVariant()` for the 1L and
2L Bottle variants (found through `$guideVariant`); if 1L < 60 or 2L < 20
(the four orders need 56 and 20), create a milk batch the way
`BatchController::store()` does — `Batch::create([...])` with
`product_id` = the milk product, `production_date` = today,
`expiry_date` = today + the product's `shelf_life_days` (10), `raw_milk_litres`
1200, `notes` "Guide demo batch — made by docs/guide/demo-data.php"
(the batch code comes from the model's `creating` hook) — then
`BatchItem::create` for 1L Bottle 600 and 2L Bottle 200 with
`quantity_remaining = quantity_produced` and `unit_weight_kg` from the
variant. Print `made batch <code> (1L 600, 2L 200)` or nothing. Do **not**
touch the 10L Churn. Then run it: `GUIDE_FRESH=1 php artisan tinker
docs/guide/demo-data.php`. README → *Screenshots*: the sentence about
`GUIDE_FRESH=1` gains "or when the milk they were picked from has expired;
the script makes a fresh milk batch when sellable milk is short".
Check: the fresh run prints four `cancelled` lines, one `made batch M071126
…` line (or `-1` if the code collides), four new orders with no `short`
line, `Written …`; a second plain run prints the same four orders, no
`made batch`, nothing created (count `batches` and `orders` before and
after). `/batches` shows the new batch under Milk with 600 + 200.

### 2. Hide the clock for the capture
Files: `resources/views/layouts/partials/topbar.blade.php`, `docs/guide/screenshot.mjs`, `docs/guide/README.md`
What: give the clock `<span>` a `data-clock` attribute (nothing else
changes in the view). In `screenshot.mjs` inject, for **every** shot,
`[data-clock] { visibility: hidden !important; }` (keeps the layout, drops
the text) — alongside the existing full-page CSS, and add it to the header
comment. README → *Screenshots*: one sentence — the top-bar clock is hidden
in pictures so an unchanged screen gives an unchanged file.
Check: `node docs/guide/screenshot.mjs finance-export` twice, `md5sum`
between runs → identical. `git diff --stat resources/views` → one file, one
line.

### 3. Two small 16c carry-overs
Files: `app/Http/Controllers/HelpController.php`, `docs/guide/getting-started.md`, `docs/guide/chilled-runs.md`
What: `image()` → `Cache-Control: private, max-age=3600`. In
`getting-started.md`'s *Next* list and in `chilled-runs.md`'s "See also"
line, mark the link to Orders *(office)* (the page is office-only; the floor
would get the 403 page from those links).
Check: `php artisan test --filter=HelpPagesTest` passes; `grep -n 'orders.md' docs/guide/*.md`
shows *(office)* on both prose links (the README table row is not a prose
link; leave it).

### 4. An `admin` audience
Files: `app/Http/Controllers/HelpController.php`, `tests/Feature/HelpPagesTest.php`
What: `AUDIENCE` gains `'production' => 'all'`, `'stock' => 'all'`,
`'admin' => 'admin'` (the three pages exist from steps 6–8; add the keys in
this step and the classification test fails until the files exist — that is
fine mid-cycle, or do this step after 8; say which). `canRead()`: `all` →
true; `office` → `Gate::allows('see-financials')`; `admin` →
`auth()->user()->hasRole('admin')`; anything else (including a missing key)
→ `office`. Tests: `test_every_guide_page_has_an_audience` allows `admin`;
`test_the_floor_cannot_open_office_pages_or_their_pictures` → office gets
403 on an `admin` page and on a picture used only by `admin` pages, 200 on
the rest; admin 200 everywhere; `test_the_floor_index_lists_only_its_pages`
→ factory does not see `admin.md`, office does not see `admin.md`, admin
sees every page; `test_every_guide_page_renders_with_its_title` and
`test_every_picture_a_page_uses_exists_and_is_served` act as an **admin**
(the one role that reads everything). The index filter needs no change.
Check: `php artisan test --filter='HelpPagesTest|AuthorizationBoundaryTest'`
passes once steps 6–8 exist; pint clean.

### 5. The part-3 shot list and a full re-shoot
Files: `docs/guide/screenshots.json`, `docs/guide/images/*.png`
What: add these shots (office = `office_test` at 1280 wide unless said;
`fullPage` where the page is taller than 800 px; names are the picture
names the pages use):

| name | as | url | notes |
|---|---|---|---|
| `batches-index` | office | `/batches` | the cheese cards with their wheel strips must be in the picture: `before` clicks on the Milk and Yoghurt group summaries to fold them (`details.batch-group > summary`), then a viewport shot ~1280×1000; or `fullPage` if the folded page is under ~2,500 px tall |
| `batch-new-form` | office | `/batches/create` | `before`: select the milk product so the variant rows and the auto-filled expiry show (read the form's JS for the select and what it reveals); `fullPage` |
| `batch-page-cheese` | office | `/batches/17` | `fullPage` — strip, finished weight and yield, cut packs, write-offs history |
| `batch-page-expired` | office | `/batches/16` | `fullPage` — the "Write off expired stock" button and the write-offs form |
| `cheese-cutting` | office | `/cheese-cutting` | |
| `cheese-cut-form` | office | `/cheese-cutting/cut/43` | `fullPage`; nothing typed or submitted |
| `mature-conversion` | office | `/cheese-conversion` | 1440 wide — the allocator with G030426's 7 held wheels in the Maturing zone |
| `stock-overview` | office | `/stock` | `fullPage`; **blur the total value** (the `€` figure at the top — `overview.blade.php` line 16; find a selector that matches only it) |
| `products-index` | office | `/products` | `fullPage`; **blur the price cells** (`price_label` column; the `€/kg` tag may stay — it has no figure) |
| `product-page` | office | `/products/3` | `fullPage`; blur the price column |
| `variant-form` | office | `/products/3/variants/create` | `fullPage`; check the picture for any `€` figure (the sibling list) and blur it if there is one |
| `write-off-reasons` | office | `/write-off-reasons` | |
| `users-new` | admin | `/users/create` | `fullPage`; **the only users picture** |
| `settings` | admin | `/settings` | |
| `backup` | admin | `/backup` | `fullPage` |

Then run the **whole** manifest once (`node docs/guide/screenshot.mjs`):
the demo orders changed in step 1 and the clock is gone, so every picture
is remade this once. Open every **new** PNG with Read and say what each
shows (one line each, as in 16c); open `dashboard-office`, `order-page-picking`,
`picking-order-partly` and `chilled-runs-dublin` to confirm the new
orders are in them and no picture shows an expired batch on an order.
Check: exit 0, 44 ✓; `du -sh docs/guide/images` and the largest file (keep
every PNG under 500 KB — drop `scale` to 1.25 on a page that goes over and
say so); `md5sum` of `finance-export.png` equals step 2's (the clock fix
holds through the full run); on the three `all`-page pictures with blurs no
`€` figure is readable.

### 6. Production page
Files: `docs/guide/production.md` (new)
What: title "Production: batches, cutting and maturing"; first line: for
the office and the floor — the floor reads these screens, the office
changes them. Sections, in the order the work happens: *What a batch is*
(batch code `Mddmmyy` / `Yddmmyy` / `Gddmmyy`, one line per variant, the
expiry, sellable = active and not expired); *Find a batch* (the browser,
the three levels, the wheel strip's colours and the vac-pack bar, the
numeric summary, the `?status=expired` filter and the dashboard's Expired
stock tile, lineage) with `batches-index`; *Record a batch* (the form; the
ceiling tick; bottled ≤ raw milk; shelf life fills the expiry) with
`batch-new-form`; *The batch page* (info rows; finished weight and yield —
measured where a wheel was weighed, `~` and "N of M wheels weighed"
otherwise; the cut buttons; Delete only while nothing has happened to the
batch) with `batch-page-cheese`; *Expired stock and write-offs* (what a
write-off is; the form; reserved units cannot be written off — release
them first; the one-click on an expired batch; Reverse puts units back;
reasons are managed under **Write-off reasons**, see [Stock](stock.md))
with `batch-page-expired`; *Cutting a wheel* (weigh the wheel first; packs
and pack weight; trim is what is left; the rules; packs trace back to the
wheel) with `cheese-cutting` and `cheese-cut-form`; *Maturing* (the hold —
reversible, at any age, held wheels leave the picker; **Save maturing**;
release once old enough into the Mature product, one Mature batch per
source; undo from the Mature batch's page while nothing has been cut or
sold) with `mature-conversion`. Read `CLAUDE.md` → *Grouped Batch Browser*,
*Finished weight & yield*, *Stock Write-offs*, *Cheese Cutting System*,
*Mature Conversion* and the views; the prose follows the views.
Check: `php artisan test --filter=HelpPagesTest`; the bold-label grep as in
16c (list the misses and why); as `office_test`, `/help/production` opens
from the index with all its pictures; line count ≤ 150.

### 7. Stock page
Files: `docs/guide/stock.md` (new)
What: title "Stock, products and write-offs"; for the office and the
floor. Sections: *The stock overview* (one card per type; what each segment
means — available, allocated, sold, written off; cheese adds cut and
maturing; rows are variant × batch, oldest first; the expired note on a
card; the total value is office information) with `stock-overview`;
*Products and variants* (products group variants; what the variant fields
mean in plain words — variable weight, bulk weighed, priced by weight, and
when each is ticked; "Stock" is free stock that can still be sold, the
same figure the allocator and the run sheet use; inactive variants
disappear from pickers but keep their history; `?from=` copies a variant)
with `products-index`, `product-page`, `variant-form`; *Write-offs*
(where they are recorded — on the batch page, see
[Production](production.md); the reasons list: add, rename, deactivate,
never delete; a used reason is deactivated) with `write-off-reasons`.
Read `CLAUDE.md` → *Stock Overview*, *Product & Variant Structure*,
*Products Master-Detail View*, *Stock Write-offs* → Reasons CRUD, and the
views.
Check: as step 6; ≤ 110 lines.

### 8. Admin page
Files: `docs/guide/admin.md` (new)
What: title "Admin: users, settings, backup"; first line: for admins only.
Sections: *Users* (the four roles in one line each — what admin, office,
factory and driver can reach; add a user: username, name, optional email,
role, password; factory users need no email and sign in with the
username; **Deactivate** instead of delete for someone who has left — it
signs them out everywhere and refuses their login; the last active admin
cannot be demoted or deactivated; an admin cannot change their own role)
with `users-new`; *Settings* (the batch ceilings: a typo guard, not a
limit; what happens on the batch form when a quantity is over it; **Reset
to defaults**) with `settings`; *Backup & Restore* (download a backup of
every table the app owns; restore from a file replaces the data —
including invoice numbering — so read the warning; what a restore does not
include) with `backup`. Read `CLAUDE.md` → *Roles & authorization*,
*Settings*, and the three views; the prose follows the views, including
the backup page's own warnings.
Check: as step 6; ≤ 80 lines; as `admin`, `/help/admin` opens with its
three pictures; as `office_test`, `/help/admin` → 403 and
`/help/images/users-new.png` → 403.

### 9. Index, getting started, pointers
Files: `docs/guide/README.md`, `docs/guide/getting-started.md`, `CLAUDE.md`
What: README rows — replace "Production and stock *(to write)*" with
`[Production](production.md) | Office and floor: batches, cutting, maturing, write-offs`
and `[Stock](stock.md) | Office and floor: the stock overview, products and variants, write-off reasons`,
and "Admin *(to write)*" with `[Admin](admin.md) | Admin: users, settings, backup`;
the *Who can read what* paragraph names the third audience (admin pages
for admins). `getting-started.md` → *Next*: add Production and Stock (and
Admin *(admins)*). `CLAUDE.md` → *Key Docs* `docs/guide/` bullet: the
audience sentence becomes "(`all`, `office` = the `see-financials` gate, or
`admin`)".
Check: `php artisan test --filter=HelpPagesTest`; `grep -c 'to write' docs/guide/README.md` → 0.

## Verification

1. `php artisan test` → **≥ 820 tests, 0 failed** (say the real number).
2. `./vendor/bin/pint --test app tests` → clean.
3. `node docs/guide/screenshot.mjs` → exit 0, 44 ✓; total size and largest
   file; `finance-export.png` unchanged between two runs.
4. `php artisan tinker docs/guide/demo-data.php` (no fresh) → the four
   current orders, no `made batch`, nothing created.
5. `git status --short` → `demo-data.json` absent; application changes are
   `HelpController.php`, `topbar.blade.php`, `HelpPagesTest.php` only; no
   migrations.
6. Browser (headless is fine): as `factory_test`, `/help` lists Getting
   started, Chilled runs, Picking, Production, Stock; `/help/stock` opens
   with its pictures and no readable `€` figure; `/help/admin` → 403. As
   `office_test`, `/help` lists eight pages (not Admin); `/help/production`
   opens with every picture. As `admin`, `/help` lists nine and
   `/help/admin` opens with three pictures. No console errors except the
   deliberate 403s.

## Risks

- **`<details>` clicks on `/batches`**: a `click` on a `<summary>` toggles
  it; if the group does not fold, use a `clip` on the cheese section
  instead and say so.
- **The batch form's product select** may rebuild the variant rows with
  JS; if the `before` cannot produce the filled state, shoot the empty form
  and say so.
- **Blur selectors on `/products`**: the Stock column is also monospace;
  check the picture — only prices blurred, stock figures readable.
- **The mature allocator** is drag-and-drop; it is a picture only, nothing
  is dragged.
- **Step 1 creates real dev data**: a batch with 800 bottles. It is tagged
  in `notes`; if the quantities look wrong on `/stock`, say so rather than
  deleting it.

## Review

### Revision 1 — reviewed 2026-11-07 (Planner: Fable 5.1)

**Verdict: ACCEPTED.** Implemented by Opus 5.5 on 2026-11-07. Everything
re-run by the Planner, not taken from `implemented.md`:

- `php artisan test` → 820 tests, 0 failed (5,696 assertions); pint clean.
  `User::hasRole()` exists (the `admin` branch of `canRead()` relies on it).
- The application diff is the three permitted files: `HelpController.php`
  (three `AUDIENCE` keys, the `match` in `canRead()` — a missing or unknown
  audience still falls to `see-financials`, so it fails closed — and the
  `private` cache header), `topbar.blade.php` (one attribute),
  `HelpPagesTest.php` (the three-role `readable()` walk plus the explicit
  "office-only picture" / "admin-only picture" assertions, so the 403
  branches cannot pass vacuously). No migrations; `demo-data.json` absent.
- `demo-data.php` plain run: ids 19–22 unchanged, `batches=10 orders=22`
  before and after, no `made batch`. Batch 26 `M071126` (expiry 2026-11-17,
  notes tagged, 1L 600/588, 2L 200/200, unit weights 1.000 / 2.000). The
  helper runs inside one transaction, the way the plan asked.
- `node docs/guide/screenshot.mjs finance-export` → md5
  `826af3b4507e285f6cbc41f382a678b0`, byte-identical to the working-tree
  file: the clock fix holds on a different run, later in the day.
- Pictures opened by the Planner: `stock-overview` (total value an
  unreadable smear; every other figure a count), `products-index` (prices
  blurred, Stock column readable, `€/kg` tags carry no figure),
  `product-page` (price column blurred; the sibling list shows counts
  only), `users-new` (the empty form; no staff name anywhere),
  `batches-index` (Milk and Yoghurt folded, both Farmhouse cards with
  strips, the greyed-out **Delete** on G030426 the prose describes). The
  blur selectors were checked against the views: `td.mf-td.font-mono.font-medium`
  matches only the `price_label` cell on both product pages;
  `div.text-[32px].font-mono` only the stock total. 44 PNGs, 9.3 MB, none
  over 500 KB.
- Headless Chrome, Planner's own script: factory index lists 5 pages,
  `/help/admin` 403, `users-new.png` 403, `finance-invoices.png` 403,
  `/help/stock` and `/help/production` 200 with all pictures loaded and no
  `€` figure in the text; office lists 8 (no admin), `/help/admin` 403,
  `users-new.png` 403; admin lists 9, `/help/admin` 200 with three
  pictures.
- Prose against the code: every bold label grepped (the Implementer's
  three "misses" are what it says — two of its own step headings, and the
  button is `Cut {{ variant }} (N left)`); "cheese is not sold before its
  ready date" holds (`BatchItem::isReadyToSell()` is inside
  `isAvailableForAllocation()`); the expired filter, the cut-form
  arithmetic (total = packs × pack weight, trim = wheel − packs, average
  per pack), the write-off rules, the settings flash, the backup page's own
  warnings and the user form's hints all match the views.

**Deviations** — all accepted: (1) step 4 after 6–8, as offered; (2)
`products-index` at `scale: 1` — the plan said drop the scale on a page
over 500 KB, and 1.25 was still 518 KB; (3) the five prose corrections are
the screens being right, as in 16c — **Delete** lives on the card, the
release control is "Release [n] of N" + **Release**, **Save maturing · N**,
**Reset to defaults** only once a value is stored, `UserRole::label()`
spellings; (4) a viewport shot of the folded `/batches`; (5) the new milk
batch is shown on `stock-overview` (M071126, 600 + 200) because
`batches-index` folds Milk.

**Notes** — (1) form fields with today's date still churn four pictures
daily; accepted, it is screen content, not chrome. (2) `backup.png` row
counts: harmless. (3) the floor's view of valuation and base prices in the
app stays an open item for Jon (vault), not a guide decision. (4)–(5)
consistent, nothing to change. (6) recorded in `RESUME.md`: after
2026-11-17 the demo orders need `GUIDE_FRESH=1`, which makes the next milk
batch itself.

**Planner's own observation, not the Implementer's**: `/stock` shows
G281026's 40 wheels as *Available* although the batch is not ready to sell
until 27/12/2026 — `StockOverviewService` filters on active and unexpired
but not on `ready_date`, while `/products` (18) and the allocator exclude
them. Added to `SMALL_FIXES.md` → *Display and consistency*; the guide's
"Available: free to sell" is right about the app's intent.
