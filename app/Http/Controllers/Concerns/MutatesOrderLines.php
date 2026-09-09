<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;

/**
 * Shared order-line mutation invariants, used by OrderController's item
 * actions and ChilledRunController::saveStop(). The rules these helpers
 * encode (and that every caller must preserve):
 *
 *  - Decreasing a line's quantity unwinds the difference via
 *    OrderItem::releaseUnits() — reservations released first, then picked
 *    stock returned to its batch.
 *  - Removing a line returns ALL its committed stock before deleting.
 *  - Cancelling keeps the lines as history (never deletes them) while
 *    returning all committed stock — consistent with every other cancel path.
 *  - Raising a short-closed line's quantity (or dropping it to at most what
 *    was already picked) clears the short close — new demand must re-enter
 *    picking instead of riding along as "settled", and a line with no
 *    shortfall left must stop reporting one.
 *  - line_total recompute is automatic via OrderItem::boot()'s saving hook;
 *    unit_price is locked at line creation from the customer's rate for the
 *    variant (Customer::unitPriceFor — a per-variant special price if set,
 *    else the variant's base_price).
 *
 * Callers are responsible for wrapping in a transaction and for running
 * Order::calculateTotals() + reconcilePickingStatus() afterwards.
 */
trait MutatesOrderLines
{
    /**
     * Set an existing line's ordered quantity. Increases just raise the
     * number; decreases release the difference (reserved first, picked last).
     */
    protected function setLineQuantity(OrderItem $line, int $qty): void
    {
        $old = (int) $line->quantity_ordered;

        // Release only what no longer fits — committed units beyond the new
        // quantity — NOT the drop in ordered quantity. On an under-committed
        // line the two differ: ordered 10 with 4 picked, trimmed to 4, must
        // release nothing (the 4 picks still fit), where releasing old − qty
        // would have unwound all four and handed the stock back. They coincide
        // whenever the line is fully committed, which is why this never showed
        // before short-closed lines made under-committed edits routine.
        $excess = (int) $line->quantity_allocated - $qty;

        if ($excess > 0) {
            $line->releaseUnits($excess);
        }

        $line->quantity_ordered = $qty;

        // Raising the quantity on a short-closed line adds demand nobody has
        // assessed — reopen it so it re-enters picking rather than riding along
        // as "settled" on a ready order that nobody will pick again. Dropping to
        // (or below) what was actually picked leaves no shortfall at all, so the
        // close is moot: clear it too rather than leave a "Short 0" badge.
        if ($line->short_closed_at !== null && ($qty > $old || $line->quantity_fulfilled >= $qty)) {
            $line->short_closed_at = null;
            $line->short_reason = null;
        }

        $line->save(); // saving() hook recomputes line_total
    }

    /**
     * Set the order's TOTAL ordered quantity for a variant — creating the
     * line if absent, updating it if present. Accidental duplicate lines for
     * the same variant (possible via the order-create form) are consolidated:
     * extras are removed (returning their stock) and the first line carries
     * the posted total.
     */
    protected function applyLineQuantity(Order $order, ProductVariant $variant, int $qty): void
    {
        $lines = $order->orderItems()
            ->where('product_variant_id', $variant->id)
            ->orderBy('id')
            ->get();

        if ($lines->isEmpty()) {
            $order->orderItems()->create([
                'product_variant_id' => $variant->id,
                'quantity_ordered' => $qty,
                'unit_price' => $order->customer->unitPriceFor($variant),
            ]);

            return;
        }

        foreach ($lines->slice(1) as $duplicate) {
            $this->removeLine($duplicate);
        }

        $this->setLineQuantity($lines->first(), $qty);
    }

    /**
     * Remove a line, returning all committed stock (reserved + picked) to its
     * batches first — the FK cascade alone would strand quantity_remaining.
     */
    protected function removeLine(OrderItem $line): void
    {
        $line->releaseUnits($line->quantity_allocated);
        $line->delete();
    }

    /**
     * Cancel an order, returning every line's committed stock but KEEPING the
     * lines as history. Used when an edit would leave the order empty — an
     * order can't go empty, so it cancels instead.
     */
    protected function cancelOrderKeepingLines(Order $order): void
    {
        foreach ($order->orderItems()->get() as $line) {
            $line->releaseUnits($line->quantity_allocated);
        }

        $order->update(['status' => 'cancelled']);
    }
}
