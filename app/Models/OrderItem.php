<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_variant_id',
        'quantity_ordered',
        'quantity_allocated',
        'quantity_fulfilled',
        'weight_fulfilled_kg',
        'unit_price',
        'line_total',
        'fulfilled_total',
        'notes',
    ];

    protected $casts = [
        'quantity_ordered' => 'integer',
        'quantity_allocated' => 'integer',
        'quantity_fulfilled' => 'integer',
        'weight_fulfilled_kg' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'fulfilled_total' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($orderItem) {
            $variant = $orderItem->productVariant;

            if ($variant && $variant->is_priced_by_weight) {
                $weightPerUnit = (float) ($variant->weight_kg ?? 0);
                $orderItem->line_total = round($orderItem->quantity_ordered * $weightPerUnit * $orderItem->unit_price, 2);
            } else {
                $orderItem->line_total = round($orderItem->quantity_ordered * $orderItem->unit_price, 2);
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function getQuantityRemainingAttribute(): int
    {
        return $this->quantity_ordered - $this->quantity_fulfilled;
    }

    public function isFullyFulfilled(): bool
    {
        return $this->quantity_fulfilled >= $this->quantity_ordered;
    }

    public function orderAllocations(): HasMany
    {
        return $this->hasMany(OrderAllocation::class);
    }

    public function allocateFromBatchItem(BatchItem $batchItem, int $quantity): ?OrderAllocation
    {
        return DB::transaction(function () use ($batchItem, $quantity) {
            // Lock the batch_item and re-read to serialise concurrent allocators.
            $lockedBatchItem = BatchItem::whereKey($batchItem->id)->lockForUpdate()->first();
            if (! $lockedBatchItem) {
                return null;
            }

            // Recompute current allocations under the lock so we never approve
            // a quantity that another transaction has already claimed.
            if ($lockedBatchItem->available_quantity < $quantity) {
                return null;
            }

            $currentlyAllocated = (int) $this->orderAllocations()->sum('quantity_allocated');
            if (($currentlyAllocated + $quantity) > $this->quantity_ordered) {
                return null;
            }

            $allocation = $this->orderAllocations()->create([
                'batch_item_id' => $lockedBatchItem->id,
                'quantity_allocated' => $quantity,
                'quantity_fulfilled' => 0,
                'allocated_at' => now(),
            ]);

            $this->quantity_allocated = $currentlyAllocated + $quantity;
            $this->save();

            return $allocation;
        });
    }

    public function fulfillAllocation(OrderAllocation $allocation, int $quantity, ?float $actualWeightKg = null): bool
    {
        if ($this->isVariableWeight() && $actualWeightKg === null) {
            return false;
        }

        return DB::transaction(function () use ($allocation, $quantity, $actualWeightKg) {
            // Lock the batch_item first to serialise stock changes, then re-read
            // the allocation under the same transaction so quantities are fresh.
            $lockedBatchItem = BatchItem::whereKey($allocation->batch_item_id)->lockForUpdate()->first();
            if (! $lockedBatchItem) {
                return false;
            }

            $allocation->refresh();

            if ($allocation->quantity_remaining < $quantity) {
                return false;
            }

            // Atomic, guarded decrement — even with the lock, this is a final
            // safety net: if quantity_remaining somehow can't cover the fulfil,
            // no row is updated and we abort the transaction.
            $decremented = BatchItem::where('id', $lockedBatchItem->id)
                ->where('quantity_remaining', '>=', $quantity)
                ->decrement('quantity_remaining', $quantity);

            if ($decremented === 0) {
                return false;
            }

            $allocation->quantity_fulfilled += $quantity;
            if ($actualWeightKg !== null) {
                $allocation->actual_weight_kg = ($allocation->actual_weight_kg ?? 0) + $actualWeightKg;
            }
            if ($allocation->quantity_fulfilled >= $allocation->quantity_allocated) {
                $allocation->fulfilled_at = now();
            }
            $allocation->save();

            $this->quantity_fulfilled = (int) $this->orderAllocations()->sum('quantity_fulfilled');
            $this->weight_fulfilled_kg = $this->orderAllocations()->sum('actual_weight_kg');
            $this->recalculateFulfilledTotal();
            $this->save();

            return true;
        });
    }

    /**
     * One-tap pick for the mobile picking flow: allocate from the chosen batch
     * item and record fulfilment in a single transaction. order_allocations is
     * unique per (order_item, batch_item), so a pick always lands on a single
     * row: any existing reservation (office pre-allocation) is reused, widened
     * for the shortfall via extendAllocation(), then fulfilled once with the
     * full recorded weight. Returns false (nothing changed) on stale stock,
     * over-allocation, or a missing weight on a variable-weight line.
     */
    public function pickFromBatchItem(BatchItem $batchItem, int $quantity, ?float $actualWeightKg = null): bool
    {
        if ($quantity <= 0) {
            return false;
        }

        if ($this->isVariableWeight() && $actualWeightKg === null) {
            return false;
        }

        try {
            return DB::transaction(function () use ($batchItem, $quantity, $actualWeightKg) {
                $allocation = $this->orderAllocations()
                    ->where('batch_item_id', $batchItem->id)
                    ->first();

                $reserved = $allocation
                    ? max(0, $allocation->quantity_allocated - $allocation->quantity_fulfilled)
                    : 0;
                $shortfall = $quantity - min($reserved, $quantity);

                if ($shortfall > 0) {
                    if ($allocation) {
                        if (! $this->extendAllocation($allocation, $batchItem, $shortfall)) {
                            throw new \RuntimeException("Failed to extend allocation {$allocation->id}.");
                        }
                    } else {
                        $allocation = $this->allocateFromBatchItem($batchItem, $shortfall);
                        if (! $allocation) {
                            throw new \RuntimeException('Failed to allocate from batch item.');
                        }
                    }
                }

                if (! $this->fulfillAllocation($allocation, $quantity, $actualWeightKg)) {
                    throw new \RuntimeException("Failed to fulfil allocation {$allocation->id}.");
                }

                return true;
            });
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Widen an existing allocation by $units under the same guards as
     * allocateFromBatchItem (stock availability, never above the ordered
     * quantity). Needed because order_allocations is unique per
     * (order_item, batch_item) — a re-pick on a batch can't add a second row.
     */
    private function extendAllocation(OrderAllocation $allocation, BatchItem $batchItem, int $units): bool
    {
        return DB::transaction(function () use ($allocation, $batchItem, $units) {
            $lockedBatchItem = BatchItem::whereKey($batchItem->id)->lockForUpdate()->first();
            if (! $lockedBatchItem) {
                return false;
            }

            // available_quantity already excludes this allocation's own
            // unfulfilled reservation, so it must cover just the widening.
            if ($lockedBatchItem->available_quantity < $units) {
                return false;
            }

            $currentlyAllocated = (int) $this->orderAllocations()->sum('quantity_allocated');
            if (($currentlyAllocated + $units) > $this->quantity_ordered) {
                return false;
            }

            $allocation->refresh();
            $allocation->quantity_allocated += $units;
            if ($allocation->quantity_fulfilled < $allocation->quantity_allocated) {
                $allocation->fulfilled_at = null;
            }
            $allocation->save();

            $this->quantity_allocated = $currentlyAllocated + $units;
            $this->save();

            return true;
        });
    }

    /**
     * Check if this order item has variable weight.
     */
    public function isVariableWeight(): bool
    {
        return $this->productVariant->is_variable_weight ?? false;
    }

    /**
     * Whether weight is captured as a single total at fulfilment (e.g. vacuum
     * packs) instead of per-unit (e.g. cheese wheels). Only meaningful when
     * isVariableWeight() is true.
     */
    public function isBulkWeighed(): bool
    {
        return $this->productVariant->is_bulk_weighed ?? false;
    }

    /**
     * Check if this order item is priced by weight.
     */
    public function isPricedByWeight(): bool
    {
        return $this->productVariant->is_priced_by_weight ?? false;
    }

    /**
     * Recalculate the fulfilled total based on actual weight or quantity.
     */
    public function recalculateFulfilledTotal(): void
    {
        if ($this->isPricedByWeight() && $this->weight_fulfilled_kg > 0) {
            $this->fulfilled_total = round($this->weight_fulfilled_kg * $this->unit_price, 2);
        } else {
            $this->fulfilled_total = round($this->quantity_fulfilled * $this->unit_price, 2);
        }
    }

    /**
     * The amount to display/charge for this line: the actual fulfilled total
     * (recorded weight × unit price for weight-priced items) once the line is
     * FULLY fulfilled, otherwise the pre-fulfilment estimate (line_total, which
     * covers the whole ordered quantity). Using the fulfilled total only when
     * complete avoids understating a partially-picked line.
     */
    public function getInvoiceableTotalAttribute(): float
    {
        return $this->isFullyFulfilled() && $this->fulfilled_total > 0
            ? (float) $this->fulfilled_total
            : (float) $this->line_total;
    }

    /**
     * Distinct batch codes this line was picked/allocated from, for traceability
     * on dockets and invoices. Requires orderAllocations.batchItem.batch to be
     * loaded. Fulfilled allocations first; falls back to any allocation with a
     * batch when nothing is fulfilled yet.
     */
    public function getBatchCodesAttribute(): array
    {
        $withBatch = $this->orderAllocations->filter(fn ($a) => $a->batchItem?->batch);

        $fulfilled = $withBatch->filter(fn ($a) => $a->quantity_fulfilled > 0);
        $source = $fulfilled->isNotEmpty() ? $fulfilled : $withBatch;

        return $source
            ->map(fn ($a) => $a->batchItem->batch->batch_code)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Undo fulfillment for an allocation (restore stock to batch).
     */
    public function unfulfillAllocation(OrderAllocation $allocation, int $quantity): bool
    {
        return DB::transaction(function () use ($allocation, $quantity) {
            $lockedBatchItem = BatchItem::whereKey($allocation->batch_item_id)->lockForUpdate()->first();
            if (! $lockedBatchItem) {
                return false;
            }

            $allocation->refresh();

            if ($allocation->quantity_fulfilled < $quantity) {
                return false;
            }

            $weightToRemove = null;
            if ($allocation->actual_weight_kg && $allocation->quantity_fulfilled > 0) {
                $weightPerUnit = $allocation->actual_weight_kg / $allocation->quantity_fulfilled;
                $weightToRemove = $weightPerUnit * $quantity;
            }

            $allocation->quantity_fulfilled -= $quantity;
            if ($weightToRemove !== null) {
                $allocation->actual_weight_kg = max(0, ($allocation->actual_weight_kg ?? 0) - $weightToRemove);
            }
            if ($allocation->quantity_fulfilled < $allocation->quantity_allocated) {
                $allocation->fulfilled_at = null;
            }
            $allocation->save();

            BatchItem::where('id', $lockedBatchItem->id)->increment('quantity_remaining', $quantity);

            $this->quantity_fulfilled = (int) $this->orderAllocations()->sum('quantity_fulfilled');
            $this->weight_fulfilled_kg = $this->orderAllocations()->sum('actual_weight_kg');
            $this->recalculateFulfilledTotal();
            $this->save();

            return true;
        });
    }

    /**
     * Release up to $units of committed quantity from this line, unwinding
     * allocations. Reserved-but-unfulfilled units are released first (just a
     * soft reservation — no stock change); fulfilled units are unwound last via
     * unfulfillAllocation(), which restores BatchItem.quantity_remaining. This
     * is what edit-quantity and remove-line use so the FK cascade never strands
     * picked stock. Returns the number of units actually released (capped at the
     * line's total committed quantity).
     */
    public function releaseUnits(int $units): int
    {
        if ($units <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($units) {
            $remaining = $units;

            // Pass 1 — release reserved (unfulfilled) units: no stock movement.
            $allocations = $this->orderAllocations()
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            foreach ($allocations as $alloc) {
                if ($remaining <= 0) {
                    break;
                }

                $reserved = $alloc->quantity_allocated - $alloc->quantity_fulfilled;
                if ($reserved <= 0) {
                    continue;
                }

                $take = min($reserved, $remaining);

                if ($alloc->quantity_fulfilled === 0 && $take === $alloc->quantity_allocated) {
                    $alloc->delete();
                } else {
                    $alloc->quantity_allocated -= $take;
                    $alloc->save();
                }

                $remaining -= $take;
            }

            // Pass 2 — unwind fulfilled units: restores batch stock.
            if ($remaining > 0) {
                $fulfilledAllocations = $this->orderAllocations()
                    ->where('quantity_fulfilled', '>', 0)
                    ->orderByDesc('id')
                    ->get();

                foreach ($fulfilledAllocations as $alloc) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $take = min($alloc->quantity_fulfilled, $remaining);

                    if (! $this->unfulfillAllocation($alloc, $take)) {
                        throw new \RuntimeException("Failed to unfulfil allocation {$alloc->id}.");
                    }

                    $alloc->refresh();
                    if ($alloc->quantity_fulfilled === 0) {
                        $alloc->delete();
                    } else {
                        // Drop the reservation freed alongside the unfulfilled units.
                        $alloc->quantity_allocated = max($alloc->quantity_fulfilled, $alloc->quantity_allocated - $take);
                        $alloc->save();
                    }

                    $remaining -= $take;
                }
            }

            // Roll up the line's counters from the surviving allocations.
            $this->quantity_allocated = (int) $this->orderAllocations()->sum('quantity_allocated');
            $this->quantity_fulfilled = (int) $this->orderAllocations()->sum('quantity_fulfilled');
            $this->weight_fulfilled_kg = $this->orderAllocations()->sum('actual_weight_kg');
            $this->recalculateFulfilledTotal();
            $this->save();

            return $units - $remaining;
        });
    }
}
