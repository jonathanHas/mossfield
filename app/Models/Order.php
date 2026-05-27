<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_number',
        'customer_id',
        'order_date',
        'delivery_date',
        'status',
        'subtotal',
        'tax_amount',
        'total_amount',
        'payment_status',
        'dispatched_at',
        'delivered_at',
        'delivery_address',
        'notes',
        'mossorders_order_id',
    ];

    protected $casts = [
        'order_date' => 'date',
        'delivery_date' => 'date',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'mossorders_order_id' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (! $order->order_number) {
                $order->order_number = $order->generateOrderNumber();
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function generateOrderNumber(): string
    {
        $date = $this->order_date ?? now();
        $dateString = $date->format('Ymd');
        $prefix = "ORD-{$dateString}-";

        // Find the next sequence number for today
        $lastOrder = self::where('order_number', 'like', $prefix.'%')
            ->orderBy('order_number', 'desc')
            ->first();

        if ($lastOrder) {
            $lastSequence = (int) substr($lastOrder->order_number, -3);
            $nextSequence = $lastSequence + 1;
        } else {
            $nextSequence = 1;
        }

        return $prefix.str_pad($nextSequence, 3, '0', STR_PAD_LEFT);
    }

    public function calculateTotals(): void
    {
        // Use each line's invoiceable total — the actual fulfilled value (e.g.
        // recorded weight × €/kg) once fulfilled, otherwise the estimate — so an
        // order's total reflects what's actually being shipped, not the nominal
        // estimate. Computed in PHP because invoiceable_total isn't a column.
        $this->subtotal = round($this->orderItems()->get()->sum(fn ($item) => $item->invoiceable_total), 2);

        // Products are 0% VAT - only courier costs would have VAT
        $this->tax_amount = 0.00;

        $this->total_amount = $this->subtotal + $this->tax_amount;
        $this->save();
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'gray',
            'confirmed' => 'blue',
            'preparing' => 'yellow',
            'ready' => 'green',
            'dispatched' => 'purple',
            'delivered' => 'emerald',
            'cancelled' => 'red',
            default => 'gray',
        };
    }

    public function getPaymentStatusColorAttribute(): string
    {
        return match ($this->payment_status) {
            'pending' => 'yellow',
            'paid' => 'green',
            'partial' => 'orange',
            'overdue' => 'red',
            default => 'gray',
        };
    }

    public function isFullyAllocated(): bool
    {
        return $this->orderItems->every(function ($item) {
            return $item->quantity_allocated >= $item->quantity_ordered;
        });
    }

    public function isFullyFulfilled(): bool
    {
        return $this->orderItems->isNotEmpty()
            && $this->orderItems->every(function ($item) {
                return $item->quantity_fulfilled >= $item->quantity_ordered;
            });
    }

    public function canBeCancelled(): bool
    {
        // Anything not yet shipped can be cancelled; cancelling returns reserved
        // and picked stock to its batch (see OrderController::update()).
        return in_array($this->status, ['pending', 'confirmed', 'preparing', 'ready']);
    }

    /**
     * Keep the ready/preparing boundary in sync after items or fulfilment
     * change: a ready order with unpicked work drops to preparing, and a
     * fully-picked preparing order advances to ready. Intentionally one-
     * directional around that boundary — never demotes preparing → confirmed.
     */
    public function reconcilePickingStatus(): void
    {
        $this->load('orderItems');

        if ($this->status === 'ready' && ! $this->isFullyFulfilled()) {
            $this->update(['status' => 'preparing']);
        } elseif ($this->status === 'preparing' && $this->isFullyFulfilled()) {
            $this->update(['status' => 'ready']);
        }
    }

    public function scopeFromMossorders($query)
    {
        return $query->whereNotNull('mossorders_order_id');
    }
}
