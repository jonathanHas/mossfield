<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
