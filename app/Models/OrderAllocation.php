<?php

namespace App\Models;

use App\Casts\KilogramList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAllocation extends Model
{
    protected $fillable = [
        'order_item_id',
        'batch_item_id',
        'quantity_allocated',
        'quantity_fulfilled',
        'actual_weight_kg',
        'unit_weights_kg',
        'allocated_at',
        'fulfilled_at',
        'notes',
    ];

    protected $casts = [
        'quantity_allocated' => 'integer',
        'quantity_fulfilled' => 'integer',
        'actual_weight_kg' => 'decimal:3',
        // Per-unit kg figures in pick order, appended across partial picks
        // (finding 5). Null on bulk-weighed lines and pre-2026-09-13 rows;
        // actual_weight_kg is derived from it whenever it is present.
        'unit_weights_kg' => KilogramList::class,
        'allocated_at' => 'datetime',
        'fulfilled_at' => 'datetime',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function batchItem(): BelongsTo
    {
        return $this->belongsTo(BatchItem::class);
    }

    public function getQuantityRemainingAttribute(): int
    {
        return $this->quantity_allocated - $this->quantity_fulfilled;
    }

    public function isFullyFulfilled(): bool
    {
        return $this->quantity_fulfilled >= $this->quantity_allocated;
    }

    /**
     * Check if this allocation requires weight entry at fulfillment.
     */
    public function requiresWeight(): bool
    {
        return $this->orderItem->productVariant->is_variable_weight ?? false;
    }

    /**
     * Get the expected weight based on quantity and batch item unit weight.
     */
    public function getExpectedWeightAttribute(): ?float
    {
        if (! $this->requiresWeight()) {
            return null;
        }

        $unitWeight = $this->batchItem->unit_weight_kg
            ?? $this->orderItem->productVariant->weight_kg
            ?? 0;

        return $this->quantity_remaining * $unitWeight;
    }
}
