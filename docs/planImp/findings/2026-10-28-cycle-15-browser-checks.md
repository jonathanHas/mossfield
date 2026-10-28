# Finding — cycle 15 browser checks: "fulfilled" survives on the order page

Date: 2026-10-28 · Source: post-acceptance browser checks for cycle 15 (check 9 of 9)

## What happened

On an open order page the items panel still reads **"fulfilled"** in four places, next
to the new "To pick" / "Picked" copy:

- `ORD-20260710-001` (confirmed, `/orders/3`), every line: `To pick` · `0 allocated · 0 picked`,
  and under **Current allocations** `0 of 23 fulfilled`.
- `ORD-20260723-001` (ready, `/orders/4`), every settled line: `✓ 20 of 20 fulfilled`.

The sources:

| File | Line | Shown when |
|---|---|---|
| `resources/views/orders/partials/allocation-items.blade.php` | 32 | line fully picked (summary tick), confirmed/preparing/ready |
| `resources/views/orders/partials/allocation-items.blade.php` | 81 | "Current allocations" header, every open line |
| `resources/views/orders/show.blade.php` | 427 | read-only items table, fully picked line (pending/dispatched/delivered/cancelled) |
| `resources/views/orders/show.blade.php` | 435 | read-only items table, any other line |

## Why

Step 13's completeness grep excludes every line containing `quantity_fulfilled` (it is in
the `grep -vi` list as an identifier). These four lines print the word "fulfilled" as copy
*and* contain `{{ $…->quantity_fulfilled }}`, so the grep filtered them out and printed
nothing. The step's file list was right; the check that was meant to prove it was blind to
exactly this shape of line.

## Options

1. **Change the four strings** to `{{ … }} of {{ … }} picked` (matching the stepper's
   existing "N of M picked", `status-stepper.blade.php:38`) and tighten the grep so it matches
   the *copy* rather than discarding the line, e.g. exclude only the identifier tokens:
   `grep -rnoiE '[^_>$]fulfil[a-z]*' resources/views --include='*.blade.php'` and review what's
   left. Add `assertDontSee(' fulfilled', false)` (or an equivalent) to
   `OrderShowAllocationInlineTest` on a confirmed and a ready order.
2. Change the strings only, no test. Cheaper, but the next copy pass can reintroduce them.
3. Leave it. The page mixes "picked" and "fulfilled" for the same number.

## Recommendation

Option 1, folded into the next plan as a small step: four one-word view edits, one test
assertion, and a corrected grep. No PHP, route or form-id changes.

## What it does not affect

Data, totals, routes and JS are untouched. Form ids (`fulfil-{id}`), routes and model
names stay as step 13 required. The other eight cycle 15 checks passed. "To pick",
"N allocated · N picked", the **Picked** column header and the **Pick** buttons all render
as planned.
