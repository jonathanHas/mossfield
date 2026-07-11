<?php

namespace App\Models;

use App\Casts\EncryptedNullable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'requires_reference',
        'notes',
        'mossorders_user_id',
        'delivery_run_id',
        'run_position',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'is_active' => 'boolean',
        'requires_reference' => 'boolean',
        'mossorders_user_id' => 'integer',
        'run_position' => 'integer',
        // PII encrypted at rest. Name/email/country stay plaintext because
        // they are queried directly (unique checks, filtering).
        'phone' => EncryptedNullable::class,
        'address' => EncryptedNullable::class,
        'city' => EncryptedNullable::class,
        'postal_code' => EncryptedNullable::class,
        'notes' => EncryptedNullable::class,
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function deliveryRun(): BelongsTo
    {
        return $this->belongsTo(DeliveryRun::class);
    }

    public function specialPrices(): HasMany
    {
        return $this->hasMany(CustomerSpecialPrice::class);
    }

    /**
     * Unit price this customer pays for a variant: the negotiated special
     * price if one exists, otherwise the variant's standard base_price. The
     * value is in the same units as base_price (€/unit, or €/kg when the
     * variant is priced by weight), so it drops straight into unit_price.
     */
    public function unitPriceFor(ProductVariant $variant): float
    {
        $special = $this->specialPrices()
            ->where('product_variant_id', $variant->id)
            ->value('price');

        return $special !== null ? (float) $special : (float) $variant->base_price;
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
        if (! $this->is_active) {
            return false;
        }

        $currentOutstanding = $this->getOutstandingBalanceAttribute();

        return ($currentOutstanding + $orderAmount) <= $this->credit_limit;
    }

    public function hasOnlineAccount(): bool
    {
        return ! is_null($this->mossorders_user_id);
    }
}
