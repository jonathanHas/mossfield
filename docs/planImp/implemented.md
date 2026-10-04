# Housekeeping batch: expiry rule, Breeze tests, confirm() dialogs, stale WIP doc — implementation

Status: DONE
Plan revision: 1
Implementer: Opus
Date: 2026-10-04

## Baseline
HEAD: 218f492
Pre-existing dirty files:
clean
`php artisan route:list | wc -l` → 157

## Steps
### 1. `/stock` uses the allocator's expiry rule — done
Changed: `app/Services/StockOverviewService.php` (`>=` → `>`, comment), `tests/Feature/StockOverviewTest.php` (new case), `CLAUDE.md:296`
Check output:
```
PASS  Tests\Feature\StockOverviewTest
✓ stock overview renders for seeded data
✓ stock overview renders empty state
✓ a batch expiring today is excluded like the allocator excludes it
Tests:    3 passed (12 assertions)

$ grep -n "expiry_date" app/Services/StockOverviewService.php
32:                        $q->whereNull('expiry_date')
33:                            ->orWhere('expiry_date', '>', $today);
91:            $expiry = $batch->expiry_date;
```
Red check: temporarily reverting line 33 to `'>='` makes the new case fail
(`Failed asserting that actual size 2 matches expected size 1.`); restored.
The test asserts on `StockOverviewService::build()['milk']` directly (one
active batch, one row, 300 available) and that `BatchItem::allocatable()`
returns exactly 1 item / 300 units.

### 2. Retire the Breeze tests that never matched this app — done
Changed: deleted `tests/Feature/Auth/{RegistrationTest,EmailVerificationTest}.php`,
`app/Http/Controllers/Auth/{RegisteredUserController,VerifyEmailController,EmailVerificationNotificationController,EmailVerificationPromptController}.php`,
`resources/views/auth/{register,verify-email}.blade.php`; edited
`tests/Feature/Auth/AuthenticationTest.php`, `tests/Feature/ProfileTest.php`,
`docs/planImp/TESTING_AND_FIXES.md` (Known-failing baseline), `CLAUDE.md:11`,
`docs/planImp/RESUME.md` (Baseline to expect, first bullet).

Guard grep (before deleting) — every hit is inside a file this step deletes:
```
app/Http/Controllers/Auth/RegisteredUserController.php:15:class RegisteredUserController extends Controller
app/Http/Controllers/Auth/RegisteredUserController.php:22:        return view('auth.register');
app/Http/Controllers/Auth/EmailVerificationNotificationController.php:9:class EmailVerificationNotificationController extends Controller
app/Http/Controllers/Auth/EmailVerificationPromptController.php:10:class EmailVerificationPromptController extends Controller
app/Http/Controllers/Auth/EmailVerificationPromptController.php:19:                    : view('auth.verify-email');
app/Http/Controllers/Auth/VerifyEmailController.php:10:class VerifyEmailController extends Controller
resources/views/auth/register.blade.php:2:    <form method="POST" action="{{ route('register') }}">
resources/views/auth/verify-email.blade.php:13:        <form method="POST" action="{{ route('verification.send') }}">
tests/Feature/Auth/EmailVerificationTest.php:32:            'verification.verify',
tests/Feature/Auth/EmailVerificationTest.php:49:            'verification.verify',
```
Check output:
```
$ php artisan test tests/Feature/Auth tests/Feature/ProfileTest.php
✓ users can authenticate using the login screen
✓ users can not authenticate with invalid password
✓ users can authenticate with their username
✓ inactive users cannot authenticate
✓ users can logout
PASS PasswordConfirmationTest (3), PasswordResetTest (4), PasswordUpdateTest (2)
PASS ProfileTest (4: page displayed, info updated, delete account, wrong password on delete)
Tests:    19 passed (50 assertions)

$ php artisan route:list | wc -l
157        (baseline 157)

$ grep -rn "7 known\|Known-failing\|known-failing" CLAUDE.md docs/planImp/RESUME.md
(no output — the rewritten lines no longer contain those phrases)
```

### 3. One in-app confirm dialog replaces every native `confirm()` — done
Changed: `resources/js/confirm.js` (new), `resources/js/app.js` (`import './confirm';`),
`resources/views/layouts/partials/confirm-dialog.blade.php` (new),
`resources/views/layouts/{app,picking}.blade.php` (include), `tests/Feature/ConfirmDialogTest.php` (new),
and 21 views (41 sites — the plan said 42; `grep` found 41):
backup/index, batches/partials/batch-card, batches/show, cheese-conversion/index,
chilled-runs/index (3), customers/index, customers/show (2), delivery-runs/index (2),
invoicing/{consolidated/create, credit-notes/show, invoices/show (2), overdue/index,
payments/show, statements/batch-create, statements/preview, uninvoiced/index},
orders/partials/allocation-items (4), orders/show (6), picking/item (5),
products/index (2), products/show, users/index (2).

Conversion was scripted with an explicit per-(file, line) table of label + danger
so each site was classified by hand; messages moved verbatim (only JS `\'`
unescaped to `'`; no message contained a literal `"`). Danger (red OK) on:
delete product/variant/batch/customer/run/user, remove payment/stop/special
price/allocation/line, undo (fulfilment, pick ×2), cancel order, close short /
mark short (×3), restore backup, return wheels to hold, **deactivate user**.
Neutral (primary OK) with labels: Confirm, Dispatch, Mark delivered,
Auto-allocate, Allocate, Issue, Send, Generate, Release, Apply, Reopen.
Both picking `type="button" onclick="if (confirm…) …submit()"` sites became
`type="submit" form="undo-line-form"` / `form="short-line-form"`.
`statements/preview` keeps `name="send" value="1"` (the click path calls
`requestSubmit(button)`).

`confirm.js` follows 3b. One addition: if a page has no dialog markup (outside
both layouts), it falls back to `window.confirm` rather than letting the action
through unasked — see Deviations.

Check output:
```
$ grep -rn "confirm(" resources/views
(no output)

$ php artisan view:cache
   INFO  Blade templates cached successfully.     (then view:clear)

$ php artisan test --filter=ConfirmDialogTest
✓ no view uses the native confirm dialog
✓ both layouts render the shared dialog
✓ a destructive action is marked danger
Tests:    3 passed (8 assertions)

$ npm run build
public/build/assets/app-dopIUWe7.js   81.55 kB │ gzip: 30.27 kB
✓ built in 3.18s
$ grep -l "confirm-action" public/build/assets/*.js
public/build/assets/app-dopIUWe7.js
```
Layout marker used by the test: `id="confirm-action-ok"` (the Breeze modal
component renders no id of its own).

**Browser check** — `php artisan serve --host=127.0.0.1 --port=8000` (Apache's
`mossfield.local` was up too, but I used 127.0.0.1 for the run), fresh build,
no `public/hot`.

*Dev-DB prerequisite:* there was no `admin` user in `mossfield_cl` (admins are
`jon`, `Bibi`, `lorraine`, `claude_a`). I ran `php artisan db:seed --class=AdminUserSeeder`
(idempotent `updateOrCreate`, refuses in production). That created `admin` and
reset `office_test`/`factory_test`/`driver_test` to the documented `admin123`.

*Tool limitation, stated plainly:* the Chrome extension's mouse and keyboard
events did not reach the page. The tab reported `document.visibilityState ===
"hidden"`, a capture-phase `pointerdown`/`click` logger recorded nothing for
tool clicks, and the screenshot frame (1568 px) didn't match the viewport
(1920 px). Because the tab was hidden, `requestAnimationFrame` was paused, so
Alpine's x-show leave transitions never finished and computed `display` stayed
`block`. So I drove every interaction with **DOM-dispatched events**
(`element.click()`, a `keydown` Escape on `window`) and read the modal's real
state from `Alpine.$data(root).show`. These go through the same capture-phase
handlers and the same `requestSubmit` path, but **this is not a real-mouse run**.
The screenshot I did get shows the dialog rendered correctly over `/users`:
"Are you sure?" title, the full message, Cancel and a red **Deactivate**
button. The browser-testing session should still repeat these checks with real
input.

`/users` as admin (driver_test row):
```
click Deactivate → show=true, message "Deactivate Driver Tester? They will be signed out immediately.",
                   OK label "Deactivate", OK class mf-btn-danger, focus on #confirm-action-cancel
Cancel           → show=false, body scroll-lock released, URL unchanged; DB is_active = true
Escape (window keydown) → show=false
click Deactivate → OK → flash "Driver Tester deactivated.", Reactivate button appears
Reactivate       → DB is_active = true (restored)
console errors: none
```
`/picking/3/items/13` as factory_test. Order 3 item 13 (Farmhouse vacuum pack,
bulk-weighed) was short-closed at 1/20 — there was no unsettled part-picked line
in the dev DB. I ran a round trip through three converted sites and left the
line as I found it:
```
Reopen form   → dialog "Reopen this line? …", label Reopen, primary; Cancel → show=false, no submit;
                OK → "Line reopened — it needs picking again." (order → preparing)
Undo button   → button.form.id = "undo-line-form" (it sits inside the /pick form);
  (form=…)      dialog label Undo, mf-btn-danger; Cancel → no submit;
                OK → "Undid pick of 1 — stock restored to batch." (bi 29: 54 → 55)
re-pick 1 × 0.250 kg from bi 29 via the pick form (no dialog) → "Picked 1 × Vacuum Pack (0.25 kg)…"
Can't finish  → button.form.id = "short-line-form"; dialog message
  (form=…)      "Can't finish this line? The 19 unpicked units will be released back to stock and won't be supplied or billed.",
                label Mark short; Cancel → no submit; OK → "Marked short — 19 × Vacuum Pack…",
                redirected to /picking/3 "Order closed — short."
console errors: none
```
Final DB state of item 13: `ready 1/20`, reason `No stock`, allocation
`[29,1,1,"0.250"]`, bi 29 `quantity_remaining = 54`. That matches the start,
except `short_closed_at` is now 2026-10-04 10:49 (it was 2026-09-10 17:33) and
the allocation's fulfilment timestamps are new.

**Real-input re-run (same day, after Chrome was reset).** This time the tab
was `visible`, and a capture-phase `pointerdown` logger recorded the tool's
clicks with `isTrusted: true`, so these are real mouse and keyboard events.
Same server (`php artisan serve` on 127.0.0.1), same build.
```
/picking/3/items/13 as factory_test:
  click "Stock arrived — reopen and pick" → dialog over the fixed .mob-footer, focus on Cancel
  real Escape                → show=false, display none, scroll-lock released, no submit
  reopen again, real backdrop click → closes, no submit
  reopen again, real click on "Reopen" → "Line reopened — it needs picking again."
  inline "Undo" (form=undo-line-form) → dialog with red "Undo"; real Cancel → closes, "1 of 20 already picked" unchanged
  "Can't finish this line — mark short" (form=short-line-form) → dialog with red "Mark short";
      real click → "Marked short — 19 × Vacuum Pack won't be supplied or billed." → /picking/3 "Order closed — short."
/users as admin (logged in by typing admin / admin123):
  Deactivate on Driver Tester → dialog, red "Deactivate"; real Cancel → closed, no flash, still active
  Deactivate → real click on "Deactivate" → "Driver Tester deactivated."; Reactivate → active again
console errors: none, on either page
```
Final DB state: `driver_test` active; order 3 `ready`; item 13 at 1/20,
short-closed with reason "No stock", allocation `[29,1,1,"0.250"]`,
bi 29 `quantity_remaining = 54`. Only `short_closed_at` changed (now
2026-10-04 11:34). This round didn't undo a pick, so stock never moved.

### 4. Bring `WORK_IN_PROGRESS.md` up to date — done
Changed: `docs/planImp/WORK_IN_PROGRESS.md`: Last Updated set to 2026-10-04;
DEPLOYMENT link fixed on line 7 **and line 417**, which had the same broken
`./DEPLOYMENT.md` link (see Deviations); two new Recently Completed entries
added at the top; the "Current Issue: Variable Weight…" section deleted; Next
Steps → end of Known Test Noise replaced with the plan's text; `2026_09_13_*`
line appended under Migrations Run (both migration files checked to exist).
Check output:
```
$ grep -n "Current Issue\|Known Test Noise\|./DEPLOYMENT.md\|Last Updated" docs/planImp/WORK_IN_PROGRESS.md
5:**Last Updated**: 2026-10-04
7:> **Production install runbook lives in [`DEPLOYMENT.md`](../../DEPLOYMENT.md).** …
417:**New doc:** created [`DEPLOYMENT.md`](../../DEPLOYMENT.md) as the single operator runbook …
```
Lines 7 and 417 match only because `.` in the grep pattern is a regex
wildcard, so it also matches the corrected `../../DEPLOYMENT.md`. A literal
search (`grep -F "./DEPLOYMENT.md" | grep -vF "../../DEPLOYMENT.md"`) finds
nothing. Section order now: Housekeeping → Authorisation boundary → Dispatch→Finance
→ … → Recently Completed Features → Database State → Next Steps → Useful Commands.

## Deviations

1. **`ConfirmDialogTest::test_no_view_uses_the_native_confirm_dialog` checks
   `confirm(` only, not `onsubmit=`.** `cheese-conversion/index.blade.php:54`
   has `@if(! $canConvert) onsubmit="return false" @endif` on the hold form. It
   isn't a confirm: it stops a read-only user's form from posting. The plan
   says not to change behaviour in that file beyond the confirm sites. Banning
   `onsubmit=` would have meant rewriting it or failing the test. Every
   converted site used `confirm(`, so the narrower check still pins this work.
2. **`confirm-dialog.blade.php` comment reworded.** The plan's snippet said
   "replaces native confirm()". That text would itself trip `grep "confirm("`
   and the new test. It now reads "replaces the browser's native confirm
   dialog."
3. **`confirm.js` falls back to `window.confirm` when the dialog markup is
   missing** (a page rendered outside both layouts). Without this, `open()`
   finds no dialog and the action would go through without anyone being asked.
   No current page triggers it: all 21 converted views use one of the two
   layouts. This is JS, so the views stay free of native `confirm(` calls.
4. **Deactivate user is marked danger.** The plan's danger list didn't name
   it, but the message says the user is signed out immediately and the plan's
   test asserts `data-confirm-danger` on `/users` (Delete would satisfy that
   on its own anyway). Office "Close short" is labelled **Close short** (the
   message's verb), and the two picking short sites are labelled **Mark short**.
5. **`WORK_IN_PROGRESS.md:417` link fixed as well as line 7.** It had the same
   broken `./DEPLOYMENT.md`, and the plan's Check expects no stale hits.
6. **Dev DB:** ran `AdminUserSeeder` to restore the `admin` user the plan's
   browser check assumes (details in step 3). The browser checks used
   DOM-dispatched events because the extension's input didn't reach the hidden
   tab (step 3).
7. **41 sites, not 42.** That is what `grep` returned at the start; all are
   converted.

## Verification

1. `php artisan test` → **Tests: 536 passed (3532 assertions), 0 failed.**
   The plan's ≈528 estimate subtracted the 7 failing tests from the passing
   count. Actual arithmetic: 529 + 7 = 536 before; −6 deleted (Registration 2,
   EmailVerification 3, ProfileTest 1) +6 added (stock 1, auth 2, confirm 3)
   = 536. The AuthenticationTest and ProfileTest cases that used to fail are
   fixed in place.
2. `./vendor/bin/pint --test app tests database routes` → `PASS … 261 files`
   (after running `pint` on the 5 touched PHP files: `PASS … 5 files`, no changes needed).
3. `grep -rn "confirm(" resources/views` → no output (rc=1).
4. `php artisan route:list | wc -l` → **157** (baseline 157).
5. `npm run build` → `✓ built in 3.18s`;
   `grep -l "confirm-action" public/build/assets/*.js` → `public/build/assets/app-dopIUWe7.js`.
   `mf-btn-danger` is present in the built CSS, so the class confirm.js
   toggles survived Tailwind's purge.
6. `git status --short` → see Files changed; only plan-named files.
7. Browser checks: done, first with DOM-dispatched events and then **re-run with real mouse and keyboard input** (step 3). `/users`
   deactivate round trip (Cancel → still active; Escape closes; OK →
   deactivated; reactivated) and `/picking` Reopen / Undo (`undo-line-form`) /
   Can't finish (`short-line-form`). Console clean on both pages.

## Files changed
All mine (baseline was clean):
```
 M CLAUDE.md
 D app/Http/Controllers/Auth/EmailVerificationNotificationController.php
 D app/Http/Controllers/Auth/EmailVerificationPromptController.php
 D app/Http/Controllers/Auth/RegisteredUserController.php
 D app/Http/Controllers/Auth/VerifyEmailController.php
 M app/Services/StockOverviewService.php
 M docs/planImp/RESUME.md
 M docs/planImp/TESTING_AND_FIXES.md
 M docs/planImp/WORK_IN_PROGRESS.md
 M resources/js/app.js
 D resources/views/auth/register.blade.php
 D resources/views/auth/verify-email.blade.php
 M resources/views/{backup/index, batches/partials/batch-card, batches/show,
     cheese-conversion/index, chilled-runs/index, customers/index, customers/show,
     delivery-runs/index, invoicing/consolidated/create, invoicing/credit-notes/show,
     invoicing/invoices/show, invoicing/overdue/index, invoicing/payments/show,
     invoicing/statements/batch-create, invoicing/statements/preview,
     invoicing/uninvoiced/index, layouts/app, layouts/picking,
     orders/partials/allocation-items, orders/show, picking/item, products/index,
     products/show, users/index}.blade.php
 M tests/Feature/Auth/AuthenticationTest.php
 D tests/Feature/Auth/EmailVerificationTest.php
 D tests/Feature/Auth/RegistrationTest.php
 M tests/Feature/ProfileTest.php
 M tests/Feature/StockOverviewTest.php
?? docs/planImp/implemented.md
?? resources/js/confirm.js
?? resources/views/layouts/partials/confirm-dialog.blade.php
?? tests/Feature/ConfirmDialogTest.php
```
`public/build/` was rebuilt (gitignored). Nothing committed.

## Notes for Planner

- **Real-input browser check: done** (see the re-run block in step 3).
  Not yet clicked through with real input: `statements/preview` "Generate &
  email" (check that `name="send"` reaches the controller) and the chilled-run
  Confirm all / Allocate all / Dispatch run buttons, which share the page with
  the `data-confirm-unsaved` guard. Worth a line in the next browser brief.
- **Dev DB changes:** `AdminUserSeeder` re-run (created `admin`; reset the
  three `*_test` passwords to `admin123`). Order 3 item 13 went through
  reopen → undo → re-pick 1 × 0.250 kg → short. The end state matches the
  start except `short_closed_at` (now 2026-10-04 10:49) and fresh allocation
  timestamps.
- **`WORK_IN_PROGRESS.md` → Database State** still says "Vacuum packs remain
  fixed weight (variable: no, by_weight: no)". That is stale: all cheese
  variants are variable-weight and packs are bulk-weighed. It was outside the
  plan's listed edits, so I left it.
- `cheese-conversion/index.blade.php:54` `onsubmit="return false"` (read-only
  hold form). It's harmless, but if the Planner wants the broader "no inline
  `onsubmit=`" rule from the original test spec, it could become
  `x-on:submit.prevent` under the same `@if`.
- `confirm.js` also supports `a[data-confirm]` (as specified), but no current
  site uses a link.
- `resources/js/app.js`: ES imports are hoisted, so `import './confirm'` runs
  before `window.Alpine = Alpine`, whatever its position in the file. That's
  harmless: confirm.js only adds `document` listeners and dispatches window
  events at click time.
