# Housekeeping batch: expiry rule, Breeze tests, confirm() dialogs, stale WIP doc

Status: ACCEPTED
Revision: 1
Planner: Fable 5.1
Date: 2026-10-04

## Goal

Four small, long-deferred fixes landed in one pass so they stop being noise in
every report. For the user: `/stock` stops showing a batch as available on the
day the allocator refuses it; the test suite reads green instead of "7 failing
is normal"; every destructive or irreversible action asks through a styled
in-app dialog instead of the browser's native `confirm()` (which the
browser-testing session cannot drive — it has frozen two sessions); and
`WORK_IN_PROGRESS.md` describes the app as it is rather than as it was in
January.

## Context

Working tree at HEAD `24ce7cb` is clean. Suite: 529 passing, 7 failing (the
Breeze baseline). `./vendor/bin/pint --test app tests database routes` clean.
`php artisan route:list | wc -l` → 157.
Nothing is pushed or deployed; the production server is unreachable.

**Expiry.** Three places decide whether a batch is still live:

- `app/Models/Batch.php:147` — `isExpired()` is `expiry_date->lt(now())`.
  `expiry_date` is cast `date` (midnight), so on the expiry day itself the
  batch **is** expired.
- `app/Models/BatchItem.php:45` — `allocatable()` uses
  `expiry_date > today` (strict). Same rule: expiring today is out. Shared by
  auto-allocate, manual allocate, picking's batch chooser and
  `BuildsAllocationData`. `CLAUDE.md:618` documents this.
- `app/Services/StockOverviewService.php:32` — `expiry_date >= $today`.
  **Divergent**: `/stock` shows a batch expiring today as available while every
  allocation path refuses it. `CLAUDE.md:296` documents the `>=`, so the doc
  moves with the code. This is `WORK_IN_PROGRESS.md` follow-up #6
  ("needs an owner") — the owner is the allocator's rule, because the free-stock
  invariant is that no screen may show as available what the allocator would
  not hand out (`TESTING_AND_FIXES.md` → *Invariants worth keeping*).

`tests/Feature/StockOverviewTest.php` exists and builds batches with
`'expiry_date' => now()->addDays(10)` (line 37); it has no expiry-boundary case.

**Breeze tests.** `TESTING_AND_FIXES.md` → *Known-failing baseline* lists the 7:
`Auth\RegistrationTest` (2), `Auth\EmailVerificationTest` (3),
`Auth\AuthenticationTest::test_users_can_authenticate_using_the_login_screen`
(posts `email`; the field is `login`), `ProfileTest::test_profile_information_can_be_updated`
(asserts `email_verified_at` is nulled on email change; `ProfileController::update`
just fills and saves — verification is disabled, `User` doesn't implement
`MustVerifyEmail`). Registration and verification routes were removed from
`routes/auth.php`; the controllers `RegisteredUserController`,
`VerifyEmailController`, `EmailVerificationNotificationController`,
`EmailVerificationPromptController` and the views `auth/register.blade.php`,
`auth/verify-email.blade.php` remain with **no references** anywhere in `app/`,
`routes/`, `resources/` or `tests/` other than the two test files being
deleted (verified by grep on 2026-10-04 — re-verify in step 2 before deleting).
`ConfirmablePasswordController` **is** routed (`confirm-password`) — keep it.

`LoginRequest::authenticate()` puts `'is_active' => true` in the credentials
array, so an inactive user gets the generic `auth.failed` on the `login` key.
`AuthenticatedSessionController::store()` redirects factory users to
`picking.index`, everyone else to `dashboard`. `UserFactory` default role is
office, password `password`; its `username` comes from Faker and may contain a
dot, so set `username` explicitly where a test posts it.

**`confirm()` dialogs.** 42 call sites in 21 Blade files (full list in step 3).
Three shapes:

1. `onsubmit="return confirm('…')"` on a `<form>` (≈18)
2. `onclick="return confirm('…')"` on a `<button type="submit">` inside its form (≈22)
3. `onclick="if (confirm('…')) document.getElementById('undo-line-form').submit();"`
   on a `<button type="button">` — two sites, both `resources/views/picking/item.blade.php`
   (lines ~213 and ~339). They submit a **sibling** form by id because the
   pick `<form>` wraps the whole branch; HTML's `form="…"` attribute on a
   submit button does the same thing without JS.

One submit button carries a name/value the controller reads:
`resources/views/invoicing/statements/preview.blade.php:39`
`<button type="submit" name="send" value="1" …>`. The replacement must submit
through `form.requestSubmit(submitter)` so the submitter's name/value is still
posted; `form.submit()` would drop it (and would bypass any `submit`
listeners).

The app already has the pieces: `resources/views/components/modal.blade.php`
(Breeze `<x-modal name="…">`, opened with
`window.dispatchEvent(new CustomEvent('open-modal', {detail: name}))`, closed
with `close-modal`, Escape and backdrop click; Alpine-driven) and a worked
example of a JS-driven confirm over it — the unsaved-changes guard in
`resources/views/chilled-runs/partials/_run-table.blade.php` (~lines 440–505:
capture-phase delegated listener, stash the pending action, open
`discard-stop`, a `#stop-discard-confirm` button runs it). Copy that shape.
Button classes available in `resources/css/app.css`: `mf-btn-primary`,
`mf-btn-secondary`, `mf-btn-ghost`, `mf-btn-danger`.

Both layouts load Alpine via `@vite(['resources/css/app.css','resources/js/app.js'])`:
`resources/views/layouts/app.blade.php` (includes `layouts.partials.feedback`
inside `<main>`) and `resources/views/layouts/picking.blade.php` (phone shell,
`.mob-shell`). `resources/js/app.js` is three lines: bootstrap, Alpine, start.
`public/build` is gitignored; dev needs `npm run build` after JS changes.

The chilled-run guard uses the attribute `data-confirm-unsaved`. The new
attribute is `data-confirm`; `closest('[data-confirm]')` does not match
`data-confirm-unsaved`, so the two guards don't collide — but the three
`onsubmit` confirms on `chilled-runs/index.blade.php` (Confirm all, Allocate
all, Dispatch run) are converted like any other.

**`WORK_IN_PROGRESS.md`.** Last updated 2026-09-12 and stale in four places:
the "Current Issue: Variable Weight Input Fields Not Rendering" section (dates
from January; per-unit weights shipped 2026-05-25, were re-verified through the
UI in pass 2a on 2026-09-13, and the page it names no longer exists); "Next
Steps" 1–4 (all done or superseded); follow-up #5 (fixed 2026-09-12, audit
finding 1) and #6 (fixed by step 1 of this plan); "Known Test Noise" (gone
after step 2). Line 7's link `[DEPLOYMENT.md](./DEPLOYMENT.md)` broke when the
file moved into `docs/planImp/`.

## Constraints

- **Free-stock invariant** (`TESTING_AND_FIXES.md` → *Invariants worth
  keeping*): no surface may report as available what the allocator would not
  hand out. Step 1 moves `/stock` toward the allocator, never the reverse. Do
  not touch `BatchItem::allocatable()` or `Batch::isExpired()`.
- Every message text in a `confirm()` call is preserved verbatim in the
  replacement (the browser-testing session's checklists quote them). Only the
  mechanism changes.
- No route, controller signature or test outside the ones named changes.
  `php artisan route:list | wc -l` must be identical before and after.
- Do not delete or alter `tests/Feature/Auth/PasswordConfirmationTest.php`,
  `PasswordResetTest.php`, `PasswordUpdateTest.php` — they pass and cover
  routed features.
- **Do not commit.** The Planner commits locally on acceptance. Nothing is
  pushed or deployed (server unreachable; see `RESUME.md`).
- Run `./vendor/bin/pint` on every PHP file you touch before finishing.

## Out of scope

- The write-off / stock-loss question (`OPEN_QUESTIONS.md` #1), cut/release
  undo (#3), scheduled reconciliation (#4).
- The `discard-stop` unsaved-changes modal and its `beforeunload` net in
  chilled runs — leave both exactly as they are.
- Restyling `components/modal.blade.php`, or any CSS beyond what the new
  dialog partial needs (prefer existing `mf-*` classes; add nothing to
  `app.css` unless unavoidable, and say so).
- The per-unit weight input UX item in `SMALL_FIXES.md`.
- The `password.confirm` route/controller (routed, keep).
- `FULFILMENT_PLAN.md` and `TESTING_AND_FIXES.md` — the Planner writes those
  up on acceptance.
- Any behaviour change in the forms being converted: same action, same
  method, same hidden inputs, same redirect.

## Steps

### 1. `/stock` uses the allocator's expiry rule

Files: `app/Services/StockOverviewService.php`, `tests/Feature/StockOverviewTest.php`, `CLAUDE.md`

What:
- `StockOverviewService.php:32`: `->orWhere('expiry_date', '>=', $today)` →
  `->orWhere('expiry_date', '>', $today)`. Add a one-line comment above the
  closure: `// Strict, matching BatchItem::allocatable() and Batch::isExpired(): a batch expiring today is already out.`
- `tests/Feature/StockOverviewTest.php`: add
  `test_a_batch_expiring_today_is_excluded_like_the_allocator_excludes_it`.
  Follow the file's existing fixture style. Create three otherwise-identical
  milk batches with stock: `expiry_date` = yesterday, today, tomorrow. Assert
  the service output (or the rendered `/stock` page as the file's other tests
  do) counts only the tomorrow batch's units, and assert
  `BatchItem::allocatable()->count()` equals the same number of items — the
  two must agree by construction.
- `CLAUDE.md:296` (Stock Overview → **Active filter**): change
  `(expiry_date IS NULL OR expiry_date >= today)` to
  `(expiry_date IS NULL OR expiry_date > today)` and append:
  `Strict, like BatchItem::allocatable() — a batch expiring today is out everywhere (fixed 2026-10-04; was >= here alone).`

Check: `php artisan test --filter=StockOverviewTest` → all pass, including the
new case. `grep -n "expiry_date" app/Services/StockOverviewService.php` shows
`'>'`.

### 2. Retire the Breeze tests that never matched this app

Files: `tests/Feature/Auth/RegistrationTest.php` (delete),
`tests/Feature/Auth/EmailVerificationTest.php` (delete),
`tests/Feature/Auth/AuthenticationTest.php`, `tests/Feature/ProfileTest.php`,
`app/Http/Controllers/Auth/{RegisteredUserController,VerifyEmailController,EmailVerificationNotificationController,EmailVerificationPromptController}.php` (delete),
`resources/views/auth/{register,verify-email}.blade.php` (delete),
`docs/planImp/TESTING_AND_FIXES.md`, `CLAUDE.md`, `docs/planImp/RESUME.md`

What:
- **Before deleting anything**, run
  `grep -rn "RegisteredUserController\|VerifyEmailController\|EmailVerificationNotificationController\|EmailVerificationPromptController\|auth.register\|auth.verify-email\|verification\.\|route('register')" app routes resources tests config`
  and paste the output. The only hits must be inside the files this step
  deletes. If anything else references them, **stop and set BLOCKED**.
- Delete the two test files, the four controllers and the two views.
- `AuthenticationTest`: in both `post('/login', …)` calls change the key
  `'email'` to `'login'`. Add two cases:
  `test_users_can_authenticate_with_their_username` (create with
  `['username' => 'office_a']`, post `login => 'office_a'`, assert
  authenticated + redirect to `route('dashboard', absolute: false)`), and
  `test_inactive_users_cannot_authenticate` (`User::factory()->inactive()->create()`,
  post with the email, `assertGuest()`, `assertSessionHasErrors('login')`).
- `ProfileTest`: in `test_profile_information_can_be_updated` delete the line
  `$this->assertNull($user->email_verified_at);`. Delete the whole
  `test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged`
  method — email verification is not a concept in this app, so the test
  asserts nothing meaningful.
- `docs/planImp/TESTING_AND_FIXES.md` → replace the **Known-failing baseline**
  section (from `## Known-failing baseline` up to but not including the `---`
  that precedes `## 2026-10-04 — Authorisation boundary pass`) with:

  ```markdown
  ## Known-failing baseline

  **None.** The suite reads green; any failure is real.

  Until 2026-10-04 seven stock Laravel Breeze tests failed on every run
  (`Auth\RegistrationTest`, `Auth\EmailVerificationTest`, one case each in
  `Auth\AuthenticationTest` and `ProfileTest`) because they tested
  self-registration and email verification, both deliberately disabled in this
  app. The registration/verification tests, their orphaned controllers and
  views were deleted; the other two were corrected to post the `login` field
  and to stop asserting `email_verified_at` behaviour. If a future Breeze
  upgrade re-scaffolds them, delete them again rather than re-documenting a
  baseline.
  ```

- `CLAUDE.md:11`: change `how to run the suites, the 7 known-failing Breeze tests (an 8th failure is real), and the audit log` to
  `how to run the suites (the suite reads green — any failure is real), and the audit log`.
- `docs/planImp/RESUME.md` → **Baseline to expect**: replace the first bullet
  with
  `- `php artisan test` → **all passing, 0 failing** (the 7 Breeze failures were retired 2026-10-04). Any failure is real.`

Check: `php artisan test tests/Feature/Auth tests/Feature/ProfileTest.php` →
all pass. `php artisan route:list | wc -l` unchanged from your baseline.
`grep -rn "7 known\|Known-failing\|known-failing" CLAUDE.md docs/planImp/RESUME.md`
→ only the rewritten lines.

### 3. One in-app confirm dialog replaces every native `confirm()`

Files: `resources/js/confirm.js` (new), `resources/js/app.js`,
`resources/views/layouts/partials/confirm-dialog.blade.php` (new),
`resources/views/layouts/app.blade.php`, `resources/views/layouts/picking.blade.php`,
`tests/Feature/ConfirmDialogTest.php` (new), and these 21 views:

```
resources/views/backup/index.blade.php
resources/views/batches/partials/batch-card.blade.php
resources/views/batches/show.blade.php
resources/views/cheese-conversion/index.blade.php
resources/views/chilled-runs/index.blade.php
resources/views/customers/index.blade.php
resources/views/customers/show.blade.php
resources/views/delivery-runs/index.blade.php
resources/views/invoicing/consolidated/create.blade.php
resources/views/invoicing/credit-notes/show.blade.php
resources/views/invoicing/invoices/show.blade.php
resources/views/invoicing/overdue/index.blade.php
resources/views/invoicing/payments/show.blade.php
resources/views/invoicing/statements/batch-create.blade.php
resources/views/invoicing/statements/preview.blade.php
resources/views/invoicing/uninvoiced/index.blade.php
resources/views/orders/partials/allocation-items.blade.php
resources/views/orders/show.blade.php
resources/views/picking/item.blade.php
resources/views/products/index.blade.php
resources/views/products/show.blade.php
resources/views/users/index.blade.php
```

Get the authoritative list with `grep -rn "confirm(" resources/views` before
you start and again after — it must be empty after.

What, in order:

**3a. The dialog partial** — `resources/views/layouts/partials/confirm-dialog.blade.php` (new):

```blade
{{-- Shared confirmation dialog. Opened by resources/js/confirm.js for any
     form[data-confirm] / button[data-confirm]; replaces native confirm(). --}}
<x-modal name="confirm-action" maxWidth="sm">
    <div class="p-5">
        <h3 id="confirm-action-title" class="text-[15px] font-medium" style="color: var(--ink);">Are you sure?</h3>
        <p id="confirm-action-message" class="mt-1 text-[13px]" style="color: var(--muted);"></p>
        <div class="mt-4 flex items-center justify-end gap-2">
            <button type="button" id="confirm-action-cancel" class="mf-btn-secondary" x-on:click="$dispatch('close')">Cancel</button>
            <button type="button" id="confirm-action-ok" class="mf-btn-primary">Confirm</button>
        </div>
    </div>
</x-modal>
```

Include it once in each layout: in `app.blade.php` directly after
`@include('layouts.partials.feedback')`; in `picking.blade.php` as the last
child of `.mob-shell`. (Check `components/modal.blade.php` is usable from the
picking layout — it needs only Alpine, which both layouts load.)

**3b. The behaviour** — `resources/js/confirm.js` (new), imported from
`app.js` after Alpine is assigned to `window` (`import './confirm';`). Two
capture-phase listeners on `document`, one pending action:

- `submit` listener: if `e.target` matches `form[data-confirm]` and the form
  is not flagged confirmed → `preventDefault()`, `stopImmediatePropagation()`,
  remember `e.submitter`, open the dialog with the form's data attributes, and
  stash `() => { flag(form); form.requestSubmit(submitter || undefined); }`.
  If the form **is** flagged → clear the flag and let it through.
- `click` listener: `const el = e.target.closest('button[data-confirm], a[data-confirm]')`.
  For a `<button>`: its `.form` (this honours the `form="…"` attribute) must
  exist and not be flagged → prevent + stop, open with the button's data
  attributes, stash `() => { flag(form); form.requestSubmit(el); }`. For an
  `<a>`: prevent, stash `() => { window.location.href = el.href; }`.
- Flag = a property on the form element (`form.__confirmed = true`), cleared
  by the submit listener when it sees it **and** by a `setTimeout(…, 0)`
  after `requestSubmit` returns — `requestSubmit` runs constraint validation
  and fires no `submit` event if a required field is empty, so without the
  timeout a flag could outlive a refused submit and the next click would skip
  the dialog.
- Opening: set `#confirm-action-title` from `data-confirm-title` (default
  "Are you sure?"), `#confirm-action-message` from `data-confirm`,
  `#confirm-action-ok` text from `data-confirm-label` (default "Confirm"),
  and swap `#confirm-action-ok`'s class between `mf-btn-danger` (when the
  element has `data-confirm-danger`) and `mf-btn-primary`; then
  `window.dispatchEvent(new CustomEvent('open-modal', { detail: 'confirm-action' }))`.
  Focus `#confirm-action-cancel` after a short timeout so Enter doesn't
  confirm by accident.
- `#confirm-action-ok` click: close (`close-modal` event), take the stashed
  action, null it, run it. Cancel / Escape / backdrop simply close; the
  stash is dropped on the next open.
- Use `textContent`, never `innerHTML`, for the message — messages contain
  customer names and emails.

**3c. Convert the 42 sites.** Rules:

| Current | Replacement |
| --- | --- |
| `<form … onsubmit="return confirm('MSG');">` | `<form … data-confirm="MSG">` |
| `<button type="submit" … onclick="return confirm('MSG')">` | `<button type="submit" … data-confirm="MSG">` |
| `<button type="button" … onclick="if (confirm('MSG')) document.getElementById('ID').submit();">` | `<button type="submit" form="ID" … data-confirm="MSG">` (drop `onclick`, keep classes/styles) |

- `MSG` is the existing text, unchanged, with Blade interpolation kept. It
  now sits in a double-quoted HTML attribute: remove JS escapes (`\'` → `'`),
  and if any message contains a literal `"` wrap that one in `{{ }}` or use
  `&quot;`. Check each file renders (`php artisan view:cache` compiles all
  views and fails loudly on a Blade error; run `php artisan view:clear` after).
- Add `data-confirm-danger` and a `data-confirm-label` on the destructive /
  irreversible ones: delete (product, variant, batch, customer, run, user,
  special price, payment), remove allocation / stop, undo pick / fulfilment,
  cancel order, close short / mark short, restore backup, return wheels to
  hold. Labels: the verb the message uses ("Delete", "Remove", "Undo",
  "Cancel order", "Mark short", "Restore", "Return"). Neutral actions (confirm
  order, dispatch, deliver, auto-allocate, confirm all, allocate all, issue,
  email, send reminder(s), generate statement(s), release to mature, apply
  credit) keep the primary button with labels like "Confirm", "Dispatch",
  "Issue", "Send", "Release", "Apply".
- `resources/views/invoicing/statements/preview.blade.php:39` keeps
  `name="send" value="1"` — `requestSubmit(el)` carries it.
- Do **not** convert `data-confirm-unsaved` (chilled runs) or touch the
  `discard-stop` modal.

**3d. Pin it** — `tests/Feature/ConfirmDialogTest.php` (new), `RefreshDatabase`:

- `test_no_view_uses_the_native_confirm_dialog`: walk
  `resource_path('views')` recursively (`Illuminate\Support\Facades\File::allFiles`)
  and assert no file contains `confirm(` or `onsubmit=` — print the offending
  `path:line` on failure.
- `test_both_layouts_render_the_shared_dialog`: admin `GET /users` →
  `assertSee('id="confirm-action"', false)` (or whatever the compiled modal's
  outer id/attribute is — check the rendered HTML, not the Blade) and
  `assertSee('data-confirm="Deactivate', false)`; factory `GET /picking` →
  the same dialog marker.
- `test_a_destructive_action_is_marked_danger`: admin `GET /users` →
  `assertSee('data-confirm-danger', false)`.

Check: `grep -rn "confirm(" resources/views` → empty. `npm run build` →
succeeds. `php artisan test --filter=ConfirmDialogTest` → pass. Then a real
run: `composer run dev` (or `php artisan serve` + `npm run dev`), log in as
`admin`/`admin123`, open `/users`, click **Deactivate** on `driver_test` —
the dialog appears, **Cancel** leaves the user active, repeat and **Deactivate**
deactivates them (then **Reactivate**). Open `/picking` as `factory_test`,
pick a line partially if the dev DB allows, and confirm the **Undo** link
opens the dialog and submits `undo-line-form`. Watch the console for errors
on both pages. Record what you saw.

### 4. Bring `WORK_IN_PROGRESS.md` up to date

Files: `docs/planImp/WORK_IN_PROGRESS.md`

What:
- Line 5: `**Last Updated**: 2026-10-04`.
- Line 7: `[`DEPLOYMENT.md`](./DEPLOYMENT.md)` → `[`DEPLOYMENT.md`](../../DEPLOYMENT.md)`.
- Insert, directly under the `---` that follows line 7 (i.e. as the newest
  "Recently Completed" entry, above *Dispatch → Finance join*):

  ```markdown
  ## Recently Completed: Housekeeping batch (2026-10-04)

  Planned in `docs/planImp/plan.md` under the Plan/Implement protocol
  (`planimp.md`), implemented by a separate session. `/stock` now uses the
  allocator's strict expiry rule (a batch expiring today is out everywhere —
  follow-up #6 below, closed); the 7 always-failing Breeze tests are gone and
  the suite reads green; every native `confirm()` is replaced by one shared
  in-app dialog (`layouts/partials/confirm-dialog.blade.php` +
  `resources/js/confirm.js`, driven by `data-confirm` attributes — the
  browser-testing session can now drive destructive actions); this file was
  refreshed.

  ---

  ## Recently Completed: Authorisation boundary pass (2026-10-04)

  `tests/Feature/AuthorizationBoundaryTest.php` walks the router and checks
  every route for guest / driver / factory / office / admin. Four findings
  fixed: a "Remember me" cookie survived deactivation (token now cycled +
  `EnsureUserIsActive` middleware); `DELETE /orders/{order}` was a 500
  (route removed); `ProductRequest` validated before the policy check;
  four read actions had no `authorize()`. Full write-up in
  `TESTING_AND_FIXES.md` → 2026-10-04.

  ---
  ```

- Delete the whole section `## Current Issue: Variable Weight Input Fields Not Rendering`
  (from that heading through the `---` before `## Recently Completed Features`).
  Resolved: per-unit weights shipped 2026-05-25 and were verified through the
  UI in pass 2a (2026-09-13).
- Replace everything from `## Next Steps` through the end of the
  `## Known Test Noise` section (stop before `## Useful Commands`) with:

  ```markdown
  ## Next Steps

  1. **Decide the write-off question** — `OPEN_QUESTIONS.md` #1. The app has
     no way to record stock being lost (spoilage, samples, expiry); the
     reconciliation closes only on cutting waste. Needs Jon's answers on
     reason codes, whether expiry is a write-off, who may do it and whether it
     reverses, before any code.
  2. **Browser verification of the two October passes** — authorisation
     boundaries per role in the UI (nav visibility, not just HTTP), and the
     new confirm dialog on every converted action.
  3. **Still untested** (from `TESTING_AND_FIXES.md` → *Not covered*): invoicing
     and VAT through the UI, picking and delivery runs end to end, the online
     sync, double-submit / back-button / two-tab edits, mobile layout.
  4. **Fulfilment Phase 4** (`FULFILMENT_PLAN.md`): driver route + proof of
     delivery. Phase 2's dispatch queue is largely superseded by "Dispatch run".

  ### Follow-ups from the 2026-07-26 allocation work — both closed

  5. `BatchItem::available_quantity` double-counting partially-fulfilled
     allocations — **fixed 2026-09-12** (audit finding 1; the reservation is
     `quantity_allocated − quantity_fulfilled` at all eight free-stock sites).
  6. Expiry `>` vs `>=` — **fixed 2026-10-04**: `StockOverviewService` now
     uses the allocator's strict `>`.

  ---
  ```

- Delete the `## Known Test Noise` section entirely (it is inside the range
  replaced above — confirm it's gone).
- In `## Database State` → *Migrations Run*, append
  `- `2026_09_13_*` (wheel weight at cutting, per-unit fulfilment weights)`
  after the `2026_07_27` line.

Check: `grep -n "Current Issue\|Known Test Noise\|./DEPLOYMENT.md\|Last Updated" docs/planImp/WORK_IN_PROGRESS.md`
→ only the new `Last Updated` line. Read the file top to bottom once; it
should describe October 2026.

## Verification

Run in order and paste results:

1. `php artisan test` → **0 failed**. Passing count ≈ 529 − 7 (removed/fixed
   Breeze) + 1 (stock) + 2 (auth) + 3 (confirm) ≈ 528; record the exact figure.
2. `./vendor/bin/pint --test app tests database routes` → clean.
3. `grep -rn "confirm(" resources/views` → no output.
4. `php artisan route:list | wc -l` → same number as your baseline.
5. `npm run build` → completes; `public/build/manifest.json` lists `confirm.js`
   content inside the app bundle (it's imported, not a separate entry — just
   confirm the build succeeded and `grep -l "confirm-action" public/build/assets/*.js` finds the bundle).
6. `git status --short` → only the files this plan names.
7. The two browser checks from step 3 (`/users` deactivate round-trip; `/picking`
   undo dialog), with console clean.

## Risks

- **Submitter semantics.** `form.submit()` drops the clicked button's
  name/value and skips `submit` listeners; `requestSubmit(submitter)` keeps
  both. The statements preview relies on `name="send"`. Use `requestSubmit`
  everywhere.
- **Flag leak.** If the confirmed-flag isn't cleared after a validation-refused
  `requestSubmit`, the next click submits without asking. The `setTimeout`
  reset in 3b exists for this; keep it.
- **Alpine `x-on:submit` forms.** The capture-phase document listener runs
  before any handler on the form itself, so an Alpine `@submit` on a
  `data-confirm` form would be blocked until confirmed and then run on the
  re-submit. None of the 42 sites is Alpine-bound today (the picking pick form
  has no confirm); if you find one, note it rather than special-casing.
- **Blade in attributes.** Messages move from a JS string into an HTML
  attribute. `{{ }}` output is HTML-escaped, which is correct there; a literal
  `"` inside a message would end the attribute early. `php artisan view:cache`
  catches Blade syntax errors but not a truncated attribute — eyeball any
  message containing quotes.
- **The modal component's z-index and `overflow-y-hidden` on `<body>`** are
  tuned for the desktop layout. On the picking layout check the dialog isn't
  hidden behind `.mob-footer` (fixed) and that the body scroll lock releases
  on cancel.
- **Deleting controllers.** The grep in step 2 is the guard. `routes/auth.php`
  imports only the controllers it uses; if it imported a deleted one, `php
  artisan route:list` would error — which is why that check is in
  Verification.

## Review

Reviewed 2026-10-04 against `implemented.md` (Status: DONE) and the working
tree diff. Verification rerun by the Planner: `php artisan test` → 536 passed,
0 failed; `pint --test app tests database routes` → clean; `grep -rn "confirm("
resources/views` → empty; `route:list | wc -l` → 157; `npm run build` → ok.

### Criteria

| Step | Result |
| --- | --- |
| 1 Expiry | **Pass.** `>` at `StockOverviewService.php:33` with the comment; new test proves the service and `BatchItem::allocatable()` agree (one batch of three, 300 units) and the Implementer red-checked it. `CLAUDE.md:296` updated as specified. |
| 2 Breeze | **Pass.** Guard grep pasted — every hit inside a deleted file. Two test files, four controllers, two views gone; `routes/auth.php` untouched (157 routes). `AuthenticationTest` posts `login`, gains username + inactive cases; `ProfileTest` drops the two `email_verified_at` assertions. Baseline docs rewritten in all three places. |
| 3 Confirm dialog | **Pass.** `confirm.js` matches 3b: capture-phase `submit` and `click` listeners, `requestSubmit(submitter)`, flag cleared both on the re-entrant submit and by `setTimeout(0)`, `textContent` for the message, Cancel focused. Partial included once per layout. 41 sites converted with messages verbatim (spot-checked `statements/preview` — `name="send"` kept; both picking `form="…"` buttons; `orders/show`; `users/index`; `chilled-runs/index`). 23 marked danger. Three tests pass. Browser check done **with real input** on `/users` and `/picking/3/items/13`, console clean. |
| 4 WIP doc | **Pass.** All four edits plus the second broken link; structure reads October 2026. |

### Deviations — all accepted

1. Test scans for `confirm(` only, not `onsubmit=` — accepted; the plan's
   `onsubmit=` ban was overbroad (`cheese-conversion/index.blade.php:54`'s
   `onsubmit="return false"` is a read-only guard, not a confirm).
2. Partial comment reworded to dodge its own grep — accepted.
3. `window.confirm` fallback when no dialog markup is on the page — accepted;
   fail-closed is the right default and no view contains `confirm(`.
4. Deactivate user marked danger; "Close short" / "Mark short" labels —
   accepted, better than the plan's list.
5. Second `DEPLOYMENT.md` link fixed — accepted.
6. `AdminUserSeeder` re-run on the dev DB — accepted and recorded in
   `RESUME.md` (the `admin` login `CLAUDE.md` documents now exists again).
7. 41 sites not 42 — accepted; the Planner's count was wrong.

### Notes for Planner — decisions

- Not clicked through with real input: `statements/preview` "Generate &
  email" and the three chilled-run buttons → **handed to the browser session**
  via `handoff/REPORT.md` (its checklist).
- Dev-DB changes → **recorded** in `RESUME.md`.
- `WORK_IN_PROGRESS.md` "Vacuum packs remain fixed weight" stale → **fixed now
  by the Planner** (docs, not code): the block now lists the real ten cheese
  variants from the dev DB, including that Garlic & Basil and Mature wheels
  are not €/kg (which is `SMALL_FIXES.md`'s open judgement item).
- `onsubmit="return false"` → `x-on:submit.prevent` → **rejected**: harmless,
  no automation impact, and out of this plan's scope.
- `a[data-confirm]` unused → noted, keep (cheap, specified).
- Import hoisting in `app.js` → noted, harmless as analysed.

Nothing to redo. Archive: `mkdir -p docs/planImp/archive/2026-10-04-housekeeping-batch`
and move `plan.md` + `implemented.md` there.
