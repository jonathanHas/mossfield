<?php

namespace App\Http\Controllers\Concerns;

use App\Models\BatchItem;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * FIFO auto-allocation for one order, shared by the order detail banner
 * (OrderAllocationController::autoAllocate) and the chilled run sheet's
 * "Allocate all" / per-stop "Allocate".
 *
 * Reservation only — no stock is decremented until a pick is recorded. See
 * OrderItem::allocateFromBatchItem(), which re-reads the batch item under
 * lockForUpdate() and re-checks availability, so concurrent callers can never
 * over-reserve; the loser simply gets null back and the line reports short.
 *
 * Ability: 'update' on the Order (office/admin). A future factory-facing
 * allocate surface would need OrderPolicy::fulfill instead — hard-coded here
 * rather than pre-abstracted.
 */
trait AllocatesOrderStock
{
    /**
     * Reserve available stock against every outstanding line, oldest batch
     * first. Returns a result struct; the caller owns the redirect and flash.
     *
     * Counts UNITS, not allocation rows — a single line filled from three
     * batches is one filled line, not three.
     *
     * @return array{units: int, lines_considered: int, lines_filled: int, lines_short: int, short_labels: array<int, string>}
     */
    protected function autoAllocateOrder(Order $order): array
    {
        $this->authorize('update', $order);

        $order->loadMissing('orderItems.productVariant');

        $result = [
            'units' => 0,
            'lines_considered' => 0,
            'lines_filled' => 0,
            'lines_short' => 0,
            'short_labels' => [],
        ];

        // One transaction per ORDER, never per run: allocateFromBatchItem()
        // takes lockForUpdate() on batch_items, and holding those across a
        // whole run's stops would serialise the write path. Its own
        // transaction nests as a savepoint.
        DB::transaction(function () use ($order, &$result) {
            foreach ($order->orderItems as $orderItem) {
                $outstanding = $orderItem->quantity_ordered - $orderItem->quantity_allocated;

                // A short-closed line is a decision, not a gap: never silently
                // re-reserve against it (and never report it as short — the
                // office has already dealt with it). Reopening the line is the
                // way back in. Skipped before lines_considered++ so the flash
                // stays honest.
                if ($outstanding <= 0 || $orderItem->isShortClosed()) {
                    continue; // Already fully allocated, or closed short
                }

                $result['lines_considered']++;

                $batchItems = BatchItem::allocatable()
                    ->with('batch')
                    ->where('product_variant_id', $orderItem->product_variant_id)
                    ->get()
                    ->filter(fn ($batchItem) => $batchItem->isAvailableForAllocation())
                    ->sortBy('batch.production_date');

                foreach ($batchItems as $batchItem) {
                    if ($outstanding <= 0) {
                        break;
                    }

                    $quantity = min($outstanding, $batchItem->available_quantity);

                    if ($quantity > 0 && $orderItem->allocateFromBatchItem($batchItem, $quantity)) {
                        $result['units'] += $quantity;
                        $outstanding -= $quantity;
                    }
                }

                if ($outstanding > 0) {
                    $result['lines_short']++;
                    $result['short_labels'][] = $orderItem->productVariant?->name ?? 'line';
                } else {
                    $result['lines_filled']++;
                }
            }

            if ($result['units'] > 0) {
                $this->markPickingStarted($order);
            }
        });

        return $result;
    }

    /**
     * Bump a confirmed order to "preparing" once any stock has been allocated.
     * Keeps the order detail's status stepper in sync with reality so the
     * user doesn't have to manually flip status via the edit form.
     */
    protected function markPickingStarted(Order $order): void
    {
        if ($order->status === 'confirmed') {
            $order->update(['status' => 'preparing']);
        }
    }
}
