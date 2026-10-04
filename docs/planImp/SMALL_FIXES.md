# Small fixes

Deferred polish — copy, display inconsistencies, and minor UX. Each item is too
small to justify its own pass, and several touch the same views, so the intent is
to clear them in **one batch** rather than interrupting the substantive work.

Sources are noted so the context is recoverable. Items marked **judgement** are
data or pricing decisions, not code fixes — they need a decision before anyone
changes anything.

Most of these need no test. Where one is worth having, it says so.

---

## Copy and empty states

- [x] **`/cheese-cutting` empty state gives the wrong reason.** When every wheel in
  a ready batch is held or allocated, the list correctly shows nothing but says
  *"Cheese batches need to mature before they can be cut. Check back when batches
  reach their ready date."* The batch **is** ready — its wheels are spoken for.
  The cut form's refusal message gets this right (*"remaining wheels are allocated
  or set aside for maturing"*); the empty state should distinguish "nothing ready
  yet" from "everything ready is spoken for".
  *Source: 2026-09-13 re-verification of finding 9.*
  *Done 2026-09-13:* `CheeseCuttingController::index()` counts the ready batches before the free-wheel filter; when that count is non-zero and the filtered list is empty the view shows "Every ready wheel is spoken for" (with a link to Mature Conversion) instead of "need to mature". Tested in `ConsolidationPassTest`.

- [x] **Dashboard greeting splices two unrelated clauses.** Reads *"All orders
  allocated, and online sync is behind."* Two separate states joined by "and".
  *Source: browser pass, cosmetic list.*
  *Done 2026-09-13:* two sentences now — "All orders allocated. Online sync is behind."

- [x] **Allocation screen contradicts itself on partially-allocated lines.** A line
  showing 31 units allocated still displays *"No available stock. No ready-to-sell
  stock available for this product variant"* beneath it. It refers to the
  outstanding remainder; it reads as a flat contradiction. Scope the message to the
  unallocated portion, or reword.
  *Source: browser pass, cosmetic list.*
  *Done 2026-09-13:* when some units are allocated the banner reads "N of M still unallocated — no stock left for the rest" and names the allocated count; the flat "No available stock" wording is kept only when nothing is allocated.

## Display and consistency

- [x] **Batch progress bar has five segments and a four-item legend.** Renders as
  `29 · 0 · 2 · 3 · 4 / 38` with one unexplained `0`. Either label it or drop it.
  *Source: browser pass, cosmetic list.*
  *Done 2026-09-13:* the numeric summary now labels every figure ("29 free · 2 maturing · 3 cut · 1 converted · 4 sold / 38") and drops zero states, so the stray `0` (it was *allocated*) is gone; the strip and legend gained a purple **converted** state (finding 8). One shared partial (`batches/partials/_wheel-strip`, `_wheel-legend`) over `BatchItem::wheelStates()` replaced the four copies of the formula.

- [x] **Batch "total weight" columns use nominal unit weight.** 33 wheels × 5.000 kg
  = 165.00 kg, on a product sold by actual recorded weight. Worth deciding whether
  the column should read actual where actual exists, or be labelled as an estimate.
  *Source: browser pass, cosmetic list.* *Worth a test if it changes to actual.*
  *Done 2026-09-13:* superseded by pass 2b — the column is `remaining × nominal` (on-hand stock is unweighed by definition), now labelled **On hand (nominal)** with a `~`; the batch's finished weight itself is measured where measurements exist. No test needed since nothing moved to actual.

- [x] **Cut form renders an enabled "Cut wheel" button when zero wheels are free.**
  The form now correctly reports "Wheels available 0" and the controller refuses
  with a clear message, so this is cosmetic — but the form still invites the
  action. Disable the submit, or show the refusal state in place of the form.
  *Source: 2026-09-13 re-verification of finding 9.*
  *Done 2026-09-13:* the form is replaced by the refusal state ("No free wheels to cut…" with links to Mature Conversion and back) when `available_quantity < 1`. Tested.

- [x] **See also finding 8** in `TESTING_AND_FIXES.md` (open): the batch list folds
  **cut** and now **converted** wheels into "sold" — it reads `sold 5` where
  `/stock` reads `sold 4 · converted 1`. Not listed here as its own item because it
  is a real finding, but it is small and belongs in the same batch.
  *Done 2026-09-13:* closed — see finding 8.

## Routing

- [x] **`/` still serves the stock Laravel welcome page**, complete with
  documentation links, Laracasts, and a "Deploy now" button to Laravel Cloud.
  Should redirect to `/dashboard` when authenticated and `/login` otherwise.
  Note this is also the page a `back()` with no Referer can land on, which is
  outside `x-app-layout` and therefore renders no flash messages.
  *Source: browser pass, cosmetic list.* *Worth a test.*
  *Done 2026-09-13:* `/` redirects to the dashboard when signed in and to login otherwise; `welcome.blade.php` deleted. Tested (`ConsolidationPassTest`, `ExampleTest`).

## Forms and ergonomics

*Not in the 2026-09-13 consolidation pass by design (the brief scoped Copy,
Display, Routing and Infrastructure). Both are real UX work, not polish.*

- [ ] **Variable-weight fulfilment renders one kg input per unit.** A 31-wheel
  allocation produces 31 numbered weight boxes in a row. Painful on a desktop,
  unusable on a phone. Consider a compact repeating entry, a running total with
  add-as-you-weigh, or a bulk-weight mode for uniform units.
  *Source: browser pass, cosmetic list.*

- [ ] **Destructive actions use native `confirm()` via inline `onclick`.**
  Functional, but unstyleable, untestable, and it blocks browser automation
  outright (it froze two testing sessions before the cause was identified).
  A modal component would fix all three.
  *Source: browser pass, cosmetic list.*

## Infrastructure

- [x] **Fonts load from `fonts.bunny.net`.** An internal LAN application that needs
  the public internet to render as designed. Self-hosting the four families would
  remove the dependency.
  *Source: browser pass, cosmetic list.*
  *Done 2026-09-13:* Inter, JetBrains Mono, Fraunces and Figtree are bundled from `@fontsource/*` via `resources/css/app.css` (Vite emits the woff2 files into `public/build`); the three `<link>`s to bunny.net are gone. `npm run build` is part of deploy already.

## Judgement needed — data and pricing

These are not bugs until someone says they are.

- [ ] **Customer name "Avoa Ballsbridge"** — almost certainly "Avoca". The record has
  an order against it, so renaming is a data edit rather than a delete-and-recreate.

- [ ] **Yoghurt 16L Bucket priced at €72.00/kg** against a 16.000 kg unit weight,
  which implies €1,152 a bucket. Likely meant as €72.00 flat, or the
  `is_priced_by_weight` flag is set wrongly.

- [ ] **Garlic & Basil Whole Wheel is €40.00 flat** where every other whole wheel is
  €16.00/kg, and it carries the variable-weight flag without the €/kg flag. A 2 kg
  wheel at €16/kg would be €32, so this is either a deliberate premium or an error.

- [ ] **Mature Cheese Whole Wheel is €55.00 flat for a 2.300 kg wheel** — €23.91/kg
  against €16.00/kg for farmhouse. This came from `ProductSeeder` when the Mature
  product was seeded on 2026-09-12, so it is a seeder choice rather than a data
  entry slip, but it is the price the app will use. Confirm the premium is right
  and whether mature should be priced per kg like the other wheels.
  *Source: 2026-09-13 verification of the dev-database repair.*

## Not investigated

- [ ] **Dashboard reports the online order sync has never completed successfully.**
  Untouched by any pass so far. Could be configuration (`services.sync.enabled`),
  could be real. Needs its own look rather than a polish fix.

---

## Suggested handoff, when the substantive work is done

> Read `SMALL_FIXES.md`. Work through the **Copy and empty states**, **Display and
> consistency**, **Routing** and **Infrastructure** sections in one pass — they are
> deliberately batched. Add a test only for the two items that say so. Skip the
> **Judgement needed** section entirely; those are decisions, not defects. Tick each
> box as you go and leave anything you disagree with unticked with a note saying why.
