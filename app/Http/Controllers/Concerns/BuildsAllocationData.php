<?php

namespace App\Http\Controllers\Concerns;

use App\Models\BatchItem;
use App\Models\Order;
use Illuminate\Support\Collection;

trait BuildsAllocationData
{
    /**
     * Build the per-order-item list of batch items that are available for
     * allocation (active, non-expired, with remaining stock), sorted FIFO by
     * production date. Assumes $order->orderItems is already loaded.
     *
     * @return array<int, Collection> keyed by order item id
     */
    private function buildAvailableBatchItems(Order $order): array
    {
        $availableBatchItems = [];

        foreach ($order->orderItems as $orderItem) {
            $availableBatchItems[$orderItem->id] = BatchItem::with(['batch', 'productVariant'])
                ->where('product_variant_id', $orderItem->product_variant_id)
                ->where('quantity_remaining', '>', 0)
                ->whereHas('batch', function ($q) {
                    $q->where('status', 'active')
                        ->where(function ($q) {
                            $q->whereNull('expiry_date')
                                ->orWhere('expiry_date', '>', now()->toDateString());
                        });
                })
                ->get()
                ->filter(fn ($batchItem) => $batchItem->isAvailableForAllocation())
                ->sortBy('batch.production_date');
        }

        return $availableBatchItems;
    }
}
