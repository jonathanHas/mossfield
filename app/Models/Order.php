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
        'delivery_charge',
        'delivery_charge_percent',
        'tax_amount',
        'total_amount',
        'payment_status',
        'dispatched_at',
        'delivered_at',
        'loaded_at',
        'delivery_address',
        'notes',
        'customer_reference',
        'mossorders_order_id',
    ];

    protected $casts = [
        'order_date' => 'date',
        'delivery_date' => 'date',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
        'loaded_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'delivery_charge' => 'decimal:2',
        'delivery_charge_percent' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'mossorders_order_id' => 'integer',
    ];

    /** VAT rate applied to the delivery charge (products themselves are 0% VAT). */
    public const DELIVERY_CHARGE_VAT_RATE = 0.23;

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

        // A negotiated percentage charge recomputes from the current subtotal
        // each time (live % of the order); a fixed charge keeps its snapshot.
        if ($this->delivery_charge_percent !== null && (float) $this->delivery_charge_percent > 0) {
            $this->delivery_charge = round($this->subtotal * (float) $this->delivery_charge_percent / 100, 2);
        }

        // Products are 0% VAT. The delivery charge is VAT-INCLUSIVE (gross), so
        // back out the 23% VAT it contains: net + vat == gross by construction,
        // so the total matches the entered figure exactly. The charge sits
        // outside the subtotal (its own net invoice line).
        $gross = round((float) ($this->delivery_charge ?? 0), 2);
        $net = round($gross / (1 + self::DELIVERY_CHARGE_VAT_RATE), 2);
        $this->tax_amount = round($gross - $net, 2);

        $this->total_amount = round($this->subtotal + $gross, 2);
        $this->save();
    }

    /**
     * The ex-VAT (net) portion of the VAT-inclusive delivery charge — what the
     * invoice shows on its own line, alongside the VAT line. net + tax == the
     * stored gross delivery_charge (products carry no VAT, so tax_amount is
     * entirely the delivery VAT).
     */
    public function getDeliveryChargeNetAttribute(): float
    {
        return round((float) $this->delivery_charge - (float) $this->tax_amount, 2);
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
     * Whether the order has progressed to at least "ready" (ready or later).
     * Gates invoice availability — an invoice is only offered once the order is
     * picked/ready. Cancelled never qualifies.
     */
    public function hasReachedReady(): bool
    {
        return in_array($this->status, ['ready', 'dispatched', 'delivered'], true);
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
