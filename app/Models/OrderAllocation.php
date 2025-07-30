<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAllocation extends Model
{
    protected $fillable = [
        'order_item_id',
        'batch_item_id',
        'quantity_allocated',
        'quantity_fulfilled',
        'allocated_at',
        'fulfilled_at',
        'notes',
    ];

    protected $casts = [
        'quantity_allocated' => 'integer',
        'quantity_fulfilled' => 'integer',
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
}
