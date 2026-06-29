<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BatchItem extends Model
{
    protected $fillable = [
        'batch_id',
        'product_variant_id',
        'quantity_produced',
        'quantity_remaining',
        'quantity_maturing',
        'unit_weight_kg',
    ];

    protected $casts = [
        'quantity_produced' => 'integer',
        'quantity_remaining' => 'integer',
        'quantity_maturing' => 'integer',
        'unit_weight_kg' => 'decimal:3',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function sourceCuttingLogs(): HasMany
    {
        return $this->hasMany(CheeseCuttingLog::class, 'source_batch_item_id');
    }

    public function targetCuttingLogs(): HasMany
    {
        return $this->hasMany(CheeseCuttingLog::class, 'target_batch_item_id');
    }

    public function sourceConversionLogs(): HasMany
    {
        return $this->hasMany(CheeseConversionLog::class, 'source_batch_item_id');
    }

    public function targetConversionLogs(): HasMany
    {
        return $this->hasMany(CheeseConversionLog::class, 'target_batch_item_id');
    }

    public function getQuantitySoldAttribute(): int
    {
        return $this->quantity_produced - $this->quantity_remaining;
    }

    public function getTotalWeightAttribute(): float
    {
        return $this->quantity_remaining * ($this->unit_weight_kg ?? 0);
    }

    public function reduceStock(int $quantity): bool
    {
        if ($this->quantity_remaining >= $quantity) {
            $this->quantity_remaining -= $quantity;
            $this->save();

            return true;
        }

        return false;
    }

    /**
     * Restore stock that was previously fulfilled (undo fulfillment).
     */
    public function restoreStock(int $quantity): bool
    {
        $this->quantity_remaining += $quantity;
        $this->save();

        return true;
    }

    public function orderAllocations(): HasMany
    {
        return $this->hasMany(OrderAllocation::class);
    }

    public function getAvailableQuantityAttribute(): int
    {
        // Available = remaining - allocated but not yet fulfilled - maturing hold.
        // The maturing hold sets wheels aside so they're never auto-assigned to
        // an order; subtracting it here propagates that to every allocation path.
        return max(0, $this->holdable_quantity - (int) $this->quantity_maturing);
    }

    /**
     * Wheels that can be set aside for maturing: remaining stock minus what is
     * already reserved by unfulfilled orders (independent of the current hold).
     * Equals available_quantity + quantity_maturing.
     */
    public function getHoldableQuantityAttribute(): int
    {
        $allocated = $this->orderAllocations()
            ->whereNull('fulfilled_at')
            ->sum('quantity_allocated');

        return max(0, $this->quantity_remaining - $allocated);
    }

    public function isReadyToSell(): bool
    {
        // Check if this batch item is ready based on product type and ready date
        if ($this->batch->product->type === 'cheese' && $this->batch->ready_date) {
            return $this->batch->ready_date <= now()->toDateString();
        }

        // Milk and yoghurt are ready immediately
        return true;
    }

    public function isAvailableForAllocation(): bool
    {
        return $this->isReadyToSell()
            && ! $this->batch->isExpired()
            && $this->available_quantity > 0;
    }
}
