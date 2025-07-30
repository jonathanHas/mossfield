<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_variant_id',
        'quantity_ordered',
        'quantity_allocated',
        'quantity_fulfilled',
        'unit_price',
        'line_total',
        'notes',
    ];

    protected $casts = [
        'quantity_ordered' => 'integer',
        'quantity_allocated' => 'integer',
        'quantity_fulfilled' => 'integer',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::saving(function ($orderItem) {
            $orderItem->line_total = $orderItem->quantity_ordered * $orderItem->unit_price;
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
        // Check if batch item has enough stock
        if ($batchItem->quantity_remaining < $quantity) {
            return null;
        }

        // Check if this would over-allocate the order item
        $currentlyAllocated = $this->orderAllocations()->sum('quantity_allocated');
        if (($currentlyAllocated + $quantity) > $this->quantity_ordered) {
            return null;
        }

        // Create the allocation
        $allocation = $this->orderAllocations()->create([
            'batch_item_id' => $batchItem->id,
            'quantity_allocated' => $quantity,
            'quantity_fulfilled' => 0,
            'allocated_at' => now(),
        ]);

        // Update order item allocated quantity
        $this->quantity_allocated = $this->orderAllocations()->sum('quantity_allocated');
        $this->save();

        return $allocation;
    }

    public function fulfillAllocation(OrderAllocation $allocation, int $quantity): bool
    {
        // Check if we can fulfill this quantity
        if ($allocation->quantity_remaining < $quantity) {
            return false;
        }

        // Update allocation
        $allocation->quantity_fulfilled += $quantity;
        if ($allocation->quantity_fulfilled >= $allocation->quantity_allocated) {
            $allocation->fulfilled_at = now();
        }
        $allocation->save();

        // Reduce batch item stock
        $allocation->batchItem->reduceStock($quantity);

        // Update order item fulfilled quantity
        $this->quantity_fulfilled = $this->orderAllocations()->sum('quantity_fulfilled');
        $this->save();

        return true;
    }
}
