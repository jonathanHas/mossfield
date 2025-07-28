<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'name',
        'size',
        'unit',
        'weight_kg',
        'base_price',
        'is_active',
    ];

    protected $casts = [
        'weight_kg' => 'decimal:3',
        'base_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batchItems(): HasMany
    {
        return $this->hasMany(BatchItem::class);
    }

    public function getFullNameAttribute(): string
    {
        return $this->product->name . ' - ' . $this->name;
    }

    public function getTotalStockAttribute(): int
    {
        return $this->batchItems()->sum('quantity_remaining');
    }
}
