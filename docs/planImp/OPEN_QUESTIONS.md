# Open questions

Design decisions that haven't been made yet, each written up with enough context
to decide later without re-deriving it. These are **not** defects — nothing here
is broken. They are places where the app has no opinion and probably needs one.

Newest first. When one is settled, record the decision here and move the
implementation into `handoff/NEXT.md`.

---

## 1. The app has no way to record stock being lost

**Raised:** 2026-09-13, after the stock model work · **Status:** open, needs Jon

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
