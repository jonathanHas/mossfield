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
        'unit_weight_kg',
    ];

    protected $casts = [
        'quantity_produced' => 'integer',
        'quantity_remaining' => 'integer',
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
}
