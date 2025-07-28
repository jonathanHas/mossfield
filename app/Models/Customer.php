<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'email', 
        'phone',
        'address',
        'city',
        'postal_code',
        'country',
        'credit_limit',
        'payment_terms',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function getFullAddressAttribute(): string
    {
        return "{$this->address}, {$this->city}, {$this->postal_code}, {$this->country}";
    }

    public function getOutstandingBalanceAttribute(): float
    {
        return $this->orders()
            ->whereIn('payment_status', ['pending', 'partial', 'overdue'])
            ->sum('total_amount');
    }

    public function canPlaceOrder(float $orderAmount): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $currentOutstanding = $this->getOutstandingBalanceAttribute();
        return ($currentOutstanding + $orderAmount) <= $this->credit_limit;
    }
}
