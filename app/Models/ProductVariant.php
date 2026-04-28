<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'name',
        'size',
        'unit',
        'image_path',
        'weight_kg',
        'is_variable_weight',
        'is_priced_by_weight',
        'base_price',
        'is_active',
    ];

    protected $casts = [
        'weight_kg' => 'decimal:3',
        'is_variable_weight' => 'boolean',
        'is_priced_by_weight' => 'boolean',
        'base_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image_path
            ? '/storage/'.ltrim($this->image_path, '/')
            : null);
    }

    public function getDisplayImageUrlAttribute(): ?string
    {
        return $this->image_url ?? $this->product?->image_url;
    }

    public function batchItems(): HasMany
    {
        return $this->hasMany(BatchItem::class);
    }

    public function getFullNameAttribute(): string
    {
        return $this->product->name.' - '.$this->name;
    }

    public function getTotalStockAttribute(): int
    {
        if (array_key_exists('total_stock', $this->attributes)) {
            return (int) $this->attributes['total_stock'];
        }

        return (int) $this->batchItems()->sum('quantity_remaining');
    }

    /**
     * Get the price display label (e.g., "€12.50/kg" or "€3.50")
     */
    public function getPriceLabelAttribute(): string
    {
        if ($this->is_priced_by_weight) {
            return '€'.number_format($this->base_price, 2).'/kg';
        }

        return '€'.number_format($this->base_price, 2);
    }

    /**
     * Calculate the price for a given quantity and optional weight.
     */
    public function calculatePrice(int $quantity, ?float $weightKg = null): float
    {
        if ($this->is_priced_by_weight) {
            $weight = $weightKg ?? ($quantity * ($this->weight_kg ?? 0));

            return round($weight * $this->base_price, 2);
        }

        return round($quantity * $this->base_price, 2);
    }

    /**
     * Estimated price per unit (uses nominal weight_kg for weight-priced variants).
     */
    public function getEstimatedUnitPriceAttribute(): float
    {
        return $this->calculatePrice(1);
    }
}
