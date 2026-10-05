# Open questions

Design decisions that haven't been made yet, each written up with enough context
to decide later without re-deriving it. These are **not** defects — nothing here
is broken. They are places where the app has no opinion and probably needs one.

Newest first. When one is settled, record the decision here and move the
implementation into `handoff/NEXT.md`.

---

## 1. The app has no way to record stock being lost

**Raised:** 2026-09-13, after the stock model work · **Status:** **decided
2026-10-04, built 2026-10-05** — Plan/Implement cycle 7
(`archive/2026-10-05-write-offs/`; write-up in `TESTING_AND_FIXES.md`).

### Decision (Jon, 2026-10-04)

1. **Reason codes** — a fixed list the office can **extend** (and an optional
   short **note** on each write-off). Seeded: spoiled, damaged, sample, own
   use, stocktake, expired, returned.
2. **Expiry is a write-off.** Office/admin write expired stock off (a
   one-click "write off everything left, reason: expired" on the batch).
3. **Who:** office/admin only, logged with who and when.
4. **Reversible.** A write-off can be reversed; the record stays as history.
5. **Units and kilograms.** Interpreted for v1 as: a write-off is always a
   whole number of units, and for variable-weight stock the operator may
   record the **measured weight of those units** (so the finished-weight
   model uses the real figure instead of nominal). Partial-weight loss on a
   unit that stays on the shelf (trim, a salvaged half-wheel) is **not**
   covered — it would need a sixth state in the weight model; raise it again
   if it turns out to matter.
6. Written-off units **count toward finished weight** (the make produced
   them) and **not toward sellable stock**, as proposed above.

### The gap

Stock can leave a batch in exactly three ways: sold (fulfilled against an order),
cut into vacuum packs, or converted to mature. That is the complete list — there
is no write-off, wastage, adjustment, stocktake or scrap route anywhere in
`routes/web.php`.

So there is nowhere to record:

- a wheel that goes mouldy or cracks during maturation
- a dropped crate of bottles
- samples, tastings, and cheese given away
- staff or own use
- shrinkage found at a stocktake
- a customer return that can't be resold
- stock that simply expired on the shelf

Trim loss at cutting is the one loss the app *does* capture, as of pass 2a.

### Why it matters more than it looks

**It undermines the reason the waste figure was built.** The stated goal was
reconciling production against sales. Cutting waste is now visible to the gram —
and every other kind of loss is invisible, so the reconciliation closes on the one
category that was measured and silently absorbs the rest.

**Expired stock can never leave the books.** Batch M230726 expired on 02/08/2026
and still carries 951 units as `quantity_remaining`. Marking the batch `expired`
hides those units from the free-stock queries but does not remove them; they stay
on the record as remaining stock forever. Today that is cosmetic because nothing
sells them. It stops being cosmetic the moment anyone totals stock across batches
for a valuation or a year-end figure.

**Losses get miscoded.** With nowhere honest to put a loss, the pressure is to
record it as something it wasn't — a phantom sale, a cut that never happened, or
nothing at all.

### The shape a fix would take

The four-state model from pass 2b is the natural home. Add a fifth state:

```
on hand + dispatched + cut + converted + written off = produced
```

The reconciliation stays intact, the batch page gains a column, and
`BatchItem::weight_breakdown` gains a branch. Mechanically it is a small change —
the model was built to extend this way.

**A written-off unit should count toward finished weight but not toward sellable
stock.** The make produced it; it was lost afterwards. That distinction separates
two questions the app currently can't tell apart:

- **Yield** — what came out of the vat, which loss does not change
- **Saleable yield** — what actually reached a customer, which loss does change

Jon's production-versus-sales reconciliation is really the second one, and it
can't be computed at all until losses are recorded.

### Questions to settle before building

1. **Reason codes, or free text?** Reasons are what make the figure useful —
   "we lose 3% to mould in maturation" is actionable, "we lose 3%" is not. A short
   fixed list (spoiled, damaged, sample, own use, stocktake, expired, returned)
   with an optional note is probably right, but the list should be yours.
2. **Is expiry a write-off?** Arguably expired stock should be written off rather
   than hidden, which would also solve the 951 bottles. That implies either a bulk
   action on a batch or an automatic sweep — and an automatic one needs care, since
   it would silently reduce stock overnight.
3. **Who can do it?** Writing off stock is destructive and unauditable if anyone
   can do it quietly. Given the `UserRole` enum already exists, this likely wants
   to be admin/office only, and always logged with who and when.
4. **Is it reversible?** A mis-keyed write-off with no undo is the cut problem
   again (see question 3 below).
5. **Should losses be valued?** Cost of loss per batch is a different and bigger
   question — worth naming now so it isn't discovered halfway through.
6. **Whole units only, or weight too?** A cracked wheel might be partly salvageable.
   Probably out of scope for a first version, but it decides whether the write-off
   records units, kilograms, or both.

### Where it would touch

`batch_items` (a `quantity_written_off` column, or a `stock_write_offs` table —
the table is better, since reasons and dates need somewhere to live),
`BatchItem::weight_breakdown` and the free-stock query, the batch page, `/stock`,
and the reconciliation test.

---

## 2. Lineage yield — should a matured wheel's sale weight count back to the make?

**Raised:** 2026-09-13 in the pass 2b report · **Status:** answered, deliberately
unbuilt

A converted wheel leaves for a mature batch and is weighed when it eventually
sells — but from the mature batch. `batches.source_batch_id` means that weight
could be attributed back to the original make.

The pass 2b report's answer, which stands: yes eventually, but as a **second
figure** ("yield including matured"), not folded into the batch's own number. Two
reasons. The batch's own figure has to stay reconcilable against its own 38 wheels
and its own raw milk, and the mature batch already counts those wheels as its own
produced — folding the weight back would put one wheel's kilos in two batches. And
a mature wheel loses moisture over the extra months, so an attributed figure would
systematically understate the farmhouse make.

The data is all there. It is a small accessor and a second line on the page when
wanted.

**Note this interacts with question 1:** if a wheel is written off *after*
conversion, the loss belongs to the mature batch, but the yield question is about
the original make. Worth deciding both together.

---

## 3. Cuts are irreversible and have no audit view

**Raised:** 2026-09-12, during the original browser pass · **Status:** open

There is no way to view a cut as a record, and no way to reverse one. Two bogus
cuts recorded during testing had to be repaired directly in the database, because
the UI offered nothing.

Pass 2a added validation, so an absurd cut is now refused — but a *plausible* one
is not. A picker who types 5.120 as 51.20 will be accepted, and the only remedy is
a developer with database access.

The batch page now shows cutting history, so the record is visible. What's missing
is the ability to correct it. Conversion has the same shape: release is one-way.

Related to question 1's "is it reversible" — a write-off with no undo would be the
third instance of the same gap.

---

## 4. The reconciliation is reported, not watched

**Raised:** 2026-09-13 · **Status:** open, low urgency

`on hand + dispatched + cut + converted = produced` is asserted in the test suite
and shown in red on the batch page when it fails.

Nothing watches for it in running data. A batch that drifts would display its
warning on a page nobody opens daily. If the reconciliation is worth asserting —
and it is, it would have caught the original cutting bug on its own — it is
probably worth a scheduled check that reports drift somewhere someone looks. The
scheduler already runs `invoicing:refresh-overdue` nightly, so there is a pattern
to follow.

---

## 5. Decisions surfaced by the 2026-10-04 end-to-end run

**Raised:** 2026-10-04 (findings in `archive/2026-10-04-e2e-browser-run/implemented.md`)
· **Status:** **all eight decided** (seven on 2026-10-04, F-38 on 2026-10-05;
below, per item); implementation is Plan/Implement cycle 8.

### Decisions (Jon, 2026-10-04)

- **F-24 — one delivery charge per drop.** A flagged customer pays the run
  charge once per (customer, run, delivery date); later same-day orders carry
  €0. (Percentage customers are unaffected — theirs scales with each order.)
- **F-25 — the credit limit warns.** Confirming an order that would take
  `outstanding + order total` over the limit shows a warning naming the
  figures; it does not block.
- **F-10 — Mature is reachable by conversion only.** Not offered as a fresh
  production batch.
- **F-09 — a sanity ceiling, yes.** Design to be set in the plan (a per-type
  ceiling with an explicit "yes, really" rather than a hard refusal).
- **F-06 — milk yield must not exceed 100 %.** Bottled volume above the raw
  milk entered is refused; yield is computed (bottled ÷ raw), not hard-coded.
- **F-33 — order notes stay internal.** Not printed on the dispatch
  confirmation or the docket.
- **F-34 — align per-order dispatch with the run button**: Mark dispatched
  also requires the loaded tick.
- **F-38 — decided 2026-10-05** (Jon took the Planner's recommendations):
  (a) manual *Issue invoice* is only offered for `dispatched`/`delivered`
  orders — a `ready` order shows in the queue as "awaiting dispatch", not
  billable (dispatch issues the invoice itself); (b) choosing weekly or
  monthly invoicing on the customer form defaults the payment terms to
  **Net 14** unless the office changes them.

- **F-24 — one delivery charge per drop, or per order?** A flagged customer
  with two orders for the same run day is charged the run fee twice (€12.30
  for one van stop; a €15 top-up order carries 41 % delivery). Options: charge
  once per (customer, run, date) with later same-day orders at €0; warn on
  the second order; keep per order.
- **F-25 — does the credit limit mean anything?** The customer form requires
  it, `Customer::canPlaceOrder()` exists, nothing calls it. Enforce (block
  confirm when outstanding + order > limit), warn, or make the field optional
  and say it's informational.
- **F-10 — should Mature be offered as a fresh production batch?** CLAUDE.md
  says Mature is only reached by releasing aged Farmhouse. The batch form
  offers it anyway. Hide it (a `reachable_by_conversion_only` flag on the
  product), or keep it for bought-in stock.
- **F-09 — a sanity ceiling on batch quantities?** 3 000 wheels was accepted
  without a word. A per-type ceiling, a "that's N× the usual — sure?" confirm
  when qty × nominal weight far exceeds raw milk ÷ typical yield, or nothing.
- **F-06 — milk yield is hard-coded 100 %.** 500 L raw → 432 L bottled is
  accepted and shows "Yield ~100 %". Compute it (finished ÷ raw) like cheese,
  validate raw = bottled for milk, or leave it.
- **F-33 — are order notes customer-visible?** The order's Notes print on the
  dispatch confirmation (emailed) and the docket. Either split "notes for the
  customer" from internal notes, or label the field "printed on the
  confirmation".
- **F-34 — must a per-order dispatch be loaded first?** The run button
  insists on the loaded tick; the per-order **Mark dispatched** doesn't.
  Align them, or keep the per-order route as the office override.
- **F-38 — two finance defaults.** (a) To Invoice offers *Issue* on orders
  that are `ready` but not dispatched — one click bills goods still in the
  fridge; keep (the page explains it) or restrict to dispatched/delivered.
  (b) A weekly-consolidated customer created with the default *Immediate*
  terms gets consolidated invoices due on the issue day; default terms from
  invoicing mode, warn, or leave.
