<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money received from a customer. It belongs to the CUSTOMER, not an invoice:
 * shops pay lump sums covering several invoices, or pay against a statement.
 * What it settles lives in payment_allocations; the remainder is credit on
 * account, spendable later via PaymentService::allocateCredit().
 */
class Payment extends Model
{
    protected $fillable = [
        'customer_id', 'amount', 'received_at', 'method',
        'reference', 'notes', 'recorded_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'received_at' => 'date',
        'method' => PaymentMethod::class,
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function getAllocatedAmountAttribute(): float
    {
        return round((float) $this->allocations()->sum('amount'), 2);
    }

    /** The part of this payment not yet applied to any invoice. */
    public function getUnallocatedAmountAttribute(): float
    {
        return round(max(0, (float) $this->amount - $this->allocated_amount), 2);
    }

    public function isFullyAllocated(): bool
    {
        return $this->unallocated_amount <= 0;
    }

    public function getMethodLabelAttribute(): string
    {
        return $this->method?->label() ?? '—';
    }
}
