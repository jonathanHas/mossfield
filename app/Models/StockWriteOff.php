<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * N units of a batch item that were lost. Recorded and reversed only through
 * App\Services\StockWriteOffService, which moves quantity_remaining.
 */
class StockWriteOff extends Model
{
    /**
     * Set by StockWriteOffService::record() when writing off held wheels
     * shrank the maturing hold (not persisted) — the flash must say so.
     */
    public ?int $holdReducedTo = null;

    protected $fillable = [
        'batch_item_id',
        'write_off_reason_id',
        'quantity',
        'weight_kg',
        'note',
        'form_token',
        'written_off_on',
        'written_off_by',
        'reversed_at',
        'reversed_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'weight_kg' => 'decimal:3',
        'written_off_on' => 'date',
        'reversed_at' => 'datetime',
    ];

    public function batchItem(): BelongsTo
    {
        return $this->belongsTo(BatchItem::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(WriteOffReason::class, 'write_off_reason_id');
    }

    public function writtenOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'written_off_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /**
     * Write-offs still in force. Filtering on reversed_at is legitimate — a
     * reversed write-off is not a write-off. (Not to be confused with the
     * rule that the reservation sum is never filtered on fulfilled_at.)
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('reversed_at');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
