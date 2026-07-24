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
        'apply_delivery_charge',
        'delivery_charge_percent',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'is_active' => 'boolean',
        'requires_reference' => 'boolean',
        'mossorders_user_id' => 'integer',
        'run_position' => 'integer',
        'apply_delivery_charge' => 'boolean',
        'delivery_charge_percent' => 'decimal:2',
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

    /**
     * The delivery charge (€, ex VAT) a NEW order for this customer should
     * snapshot: the current run's charge when the customer is flagged, on a
     * run, and the run has a charge set — otherwise 0. Snapshot semantics:
     * changing the run's charge later never touches existing orders. This is
     * the single leverage point applied at every order-creation site.
     */
    public function currentDeliveryCharge(): float
    {
        // A negotiated percentage overrides the fixed run charge; its € amount
        // is computed in Order::calculateTotals() (it needs the order subtotal).
        if ($this->deliveryChargePercent() !== null) {
            return 0.0;
        }

        if (! $this->apply_delivery_charge) {
            return 0.0;
        }

        return round(max(0, (float) ($this->deliveryRun?->delivery_charge ?? 0)), 2);
    }

    /**
     * The customer's negotiated delivery-charge rate (% of order value), or
     * null when they have none. Snapshotted onto new orders; when present it
     * overrides the fixed run charge (see [[currentDeliveryCharge]]).
     */
    public function deliveryChargePercent(): ?float
    {
        $pct = (float) ($this->delivery_charge_percent ?? 0);

        return $pct > 0 ? round($pct, 2) : null;
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
