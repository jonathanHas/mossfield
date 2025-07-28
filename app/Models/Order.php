<?php

namespace App\Models;

use Carbon\Carbon;
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
        'delivery_address',
        'notes',
    ];

    protected $casts = [
        'order_date' => 'date',
        'delivery_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($order) {
            if (!$order->order_number) {
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
        $lastOrder = self::where('order_number', 'like', $prefix . '%')
            ->orderBy('order_number', 'desc')
            ->first();
            
        if ($lastOrder) {
            $lastSequence = (int) substr($lastOrder->order_number, -3);
            $nextSequence = $lastSequence + 1;
        } else {
            $nextSequence = 1;
        }
        
        return $prefix . str_pad($nextSequence, 3, '0', STR_PAD_LEFT);
    }

    public function calculateTotals(): void
    {
        $this->subtotal = $this->orderItems()->sum('line_total');
        
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

    public function canBeCancelled(): bool
    {
        return in_array($this->status, ['pending', 'confirmed']);
    }
}
