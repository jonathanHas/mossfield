<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's negotiated alternative unit price for one product variant.
 * The price is in the same units as ProductVariant::$base_price (€/unit, or
 * €/kg for weight-priced variants) and is used in place of base_price when an
 * office-entered order line is created for the customer.
 */
class CustomerSpecialPrice extends Model
{
    protected $fillable = [
        'customer_id',
        'product_variant_id',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
