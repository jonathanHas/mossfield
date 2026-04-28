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
     * Check if this order item has variable weight.
     */
    public function isVariableWeight(): bool
    {
        return $this->productVariant->is_variable_weight ?? false;
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
     * Get the invoiceable total (fulfilled_total if available, otherwise line_total).
     */
    public function getInvoiceableTotalAttribute(): float
    {
        return $this->fulfilled_total ?? $this->line_total;
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
}
