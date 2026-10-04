# Report — two October passes done outside the loop; `NEXT.md` is stale

**Written:** 2026-10-04 · **Against:** `NEXT.md` still reads "move the planning and testing docs into `docs/planImp/`" — that brief was completed and reported on 2026-09-13. Nothing new arrived, so this report covers what was done since without a brief, and what the browser should now verify. **Status: both passes done, test-verified; browser verification outstanding.**

Suite: **536 passed, 0 failed** — the 7 Breeze failures are gone; any failure is now real. `pint --test` clean. All committed locally on `main`, nothing pushed (production server unreachable).

## 1. Authorisation boundary pass (`TESTING_AND_FIXES.md` → 2026-10-04)

This was your "not covered: every authorisation boundary" item, done test-first from the router. `tests/Feature/AuthorizationBoundaryTest.php` walks every route for guest / driver / factory / office / admin. Four findings fixed:

- A **"Remember me" cookie survived deactivation** — `logOutEverywhere()` deleted sessions but the recaller rebuilt one. Token is now cycled and a new `EnsureUserIsActive` middleware signs out any inactive account on its next request.
- `DELETE /orders/{order}` was a **500 for every role** (route registered, no controller method). Removed.
- `ProductRequest` validated before the policy — factory got a validation redirect, not 403, on an invalid form.
- `/stock`, cutting index/create/store and conversion index had no `authorize()`.

## 2. Housekeeping batch (first Plan/Implement cycle — `planimp.md`)

- `/stock` now uses the allocator's strict expiry (`>`): a batch expiring today is out everywhere.
- Every native `confirm()` (41, in 21 views) is replaced by **one in-app dialog** driven by `data-confirm` attributes — so you can now drive destructive actions. Cancel, Escape and backdrop close it; the OK button is red for destructive actions and carries the verb ("Delete", "Undo", "Mark short", "Dispatch"…).
- `WORK_IN_PROGRESS.md` refreshed; `AdminUserSeeder` re-run so `admin`/`admin123` exists again.

## What changed

```
 61 files changed across the two passes — the shape:
 app/Http/Middleware/EnsureUserIsActive.php (new), app/Models/User.php, bootstrap/app.php, routes/web.php
 app/Http/Requests/{ProductRequest,CheeseCutRequest}.php, 3 controllers (authorize calls)
 app/Services/StockOverviewService.php (one comparison)
 resources/js/confirm.js (new), resources/js/app.js, resources/views/layouts/partials/confirm-dialog.blade.php (new),
 both layouts, 21 views (confirm() → data-confirm)
 tests: AuthorizationBoundaryTest (new, 21 cases), ConfirmDialogTest (new, 3), StockOverviewTest (+1),
        AuthenticationTest (+2, fixed), ProfileTest (fixed); RegistrationTest + EmailVerificationTest deleted
 4 orphaned Breeze controllers + 2 views deleted
```

## What to verify in the browser

**Confirm dialog — already verified with real input on `/users` and `/picking/3/items/13`; please cover the rest:**

1. `/invoicing/customers/{customer}/statement` → **Generate & email**. The dialog should show "Generate and email this statement to …?" with a primary **Send** button. Confirm it, then check the statement was actually generated *and emailed* (Documents sent on the customer page) — this button relies on `name="send"` reaching the controller through the new submit path. If it only previews, that's a bug.
2. `/chilled-runs` → **Confirm all**, **Allocate all**, **Dispatch run** (whichever the dev data allows). Each should open the dialog; Cancel must do nothing; OK must do what it did before. Also open a row's **Edit**, type a quantity, then click another row's Edit — the *unsaved-changes* dialog (the older one) must still appear; the two dialogs must not interfere.
3. One of each remaining shape, any page: a form-level confirm (`/delivery-runs` → delete a run, Cancel it), a button-level confirm (`/orders/{order}` → Cancel order, Cancel it), and Escape / clicking the dark backdrop to dismiss. Watch the console throughout.
4. Mobile width on `/picking/{order}/items/{item}`: the dialog should sit above the fixed footer and the page should scroll again after Cancel.

**Authorisation — the HTTP matrix is pinned; what's left is what only a browser shows:**

5. Log in as `factory_test`: the sidebar must not offer Customers, Delivery Runs, Online Orders, Users, Backup or any Finance item, and `/customers`, `/invoicing`, `/users`, `/backup` typed directly must 403. On `/orders/{order}` and `/picking` no € should be visible.
6. Log in as `driver_test`: dashboard shows the route-manifest placeholder only; every business URL 403s.
7. As admin, tick **Remember me** when logging in as `office_test` in a second browser/profile, then deactivate `office_test` from `/users`. Reload the office_test tab — it must land on the login page with "This account has been deactivated." Reactivate afterwards.
8. As admin, change `office_test`'s role to factory while they're logged in elsewhere; their next page load should reflect factory access (and `/customers` 403).

## Decisions taken

- Expiry: the allocator's strict rule wins (free-stock invariant). `CLAUDE.md` updated.
- Deactivate is treated as destructive (red button) — the message says the user is signed out immediately.
- The browser-session-facing `confirm()` replacement was chosen over per-page modals so one JS file and one partial cover everything, and future actions get it by adding an attribute.

## Disagreements

None with a brief — there wasn't one. One correction to earlier docs: `WORK_IN_PROGRESS.md` listed "Tomato & Herb" and said vacuum packs were fixed-weight; the dev DB has Mediterranean and Cumin, and every cheese variant is variable-weight. Fixed.

## Test results

536 passed, 0 failed (was 529 + 7 known failures). New: `AuthorizationBoundaryTest` (21), `ConfirmDialogTest` (3), one stock case, two auth cases. Deleted: the 5 Breeze cases that tested disabled features; 2 fixed in place.

## Dev database state

`admin`/`admin123` exists again (seeder re-run); `*_test` passwords reset to `admin123`. Order 3 item 13 was round-tripped (reopen → undo → re-pick → short) and ended as it started bar timestamps. G030426 unchanged (26 free · 4 cut · 4 converted · 4 sold).
