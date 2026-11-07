<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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
        'is_bulk_weighed',
        'base_price',
        'case_size',
        'is_active',
    ];

    protected $casts = [
        'weight_kg' => 'decimal:3',
        'is_variable_weight' => 'boolean',
        'is_priced_by_weight' => 'boolean',
        'is_bulk_weighed' => 'boolean',
        'base_price' => 'decimal:2',
        'case_size' => 'integer',
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

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function specialPrices(): HasMany
    {
        return $this->hasMany(CustomerSpecialPrice::class);
    }

    /**
     * Why this variant can't be deleted, or null when it can. The one place the
     * rule lives: the /products pages grey Delete on it,
     * ProductVariantController::destroy() refuses on it. Batch items and order
     * lines are history; special prices are config, but deleting the variant
     * would still silently drop them.
     */
    public function deletionBlocker(): ?string
    {
        ['batch_items' => $batchItems, 'order_lines' => $orderLines, 'special_prices' => $specialPrices] = $this->dependentCounts();

        $reasons = [];
        if ($batchItems) {
            $reasons[] = $batchItems.' batch '.($batchItems === 1 ? 'item' : 'items');
        }
        if ($orderLines) {
            $reasons[] = $orderLines.' order '.($orderLines === 1 ? 'line' : 'lines');
        }
        if ($specialPrices) {
            $reasons[] = $specialPrices.' customer special '.($specialPrices === 1 ? 'price' : 'prices');
        }

        if (! $reasons) {
            return null;
        }

        $total = $batchItems + $orderLines + $specialPrices;
        $history = ($batchItems || $orderLines) ? ' from past batches and orders' : '';

        return $this->picker_label.' can\'t be deleted — '.implode(' and ', $reasons).
            ($total === 1 ? ' depends' : ' depend').' on it, and deleting it would remove '.
            ($total === 1 ? 'it' : 'them').$history.'. Set the variant inactive instead.';
    }

    /** How many records hold the variant in place ("Can't delete — N records …"). */
    public function dependentRecordCount(): int
    {
        return array_sum($this->dependentCounts());
    }

    /**
     * Batch items, order lines and special prices on this variant. Reads the
     * *_count attributes when the caller eager-loaded them (the /products
     * loaders — read by array_key_exists, never ??, which re-queries on NULL),
     * else one loadCount query. Public so Product can sum its variants'.
     *
     * @return array{batch_items: int, order_lines: int, special_prices: int}
     */
    public function dependentCounts(): array
    {
        $keys = ['batch_items_count', 'order_items_count', 'special_prices_count'];

        if (! collect($keys)->every(fn ($k) => array_key_exists($k, $this->getAttributes()))) {
            $this->loadCount(['batchItems', 'orderItems', 'specialPrices']);
        }

        $attributes = $this->getAttributes();

        return [
            'batch_items' => (int) $attributes['batch_items_count'],
            'order_lines' => (int) $attributes['order_items_count'],
            'special_prices' => (int) $attributes['special_prices_count'],
        ];
    }

    /**
     * The app's wheel classification (same test the cutting/conversion
     * controllers and the batch cards apply to the variant name).
     */
    public function isWheel(): bool
    {
        return str_contains(strtolower((string) $this->name), 'wheel');
    }

    /**
     * Option text for product pickers: "Farmhouse Cheese · Whole Wheel". Three
     * cheeses each have a "Whole Wheel" and a "Vacuum Pack", so the variant
     * name alone is ambiguous once the select is closed (finding 7) — the
     * <optgroup> only helps while the list is open.
     */
    public function getPickerLabelAttribute(): string
    {
        return $this->product->short_name.' · '.$this->name;
    }

    public function getFullNameAttribute(): string
    {
        return $this->product->name.' - '.$this->name;
    }

    /**
     * Free (sellable) stock for this variant — the "Stock" figure on /products.
     *
     * Not a raw SUM(quantity_remaining): expired/closed/unready batches, units
     * still owed to orders and the maturing hold are all excluded, so it agrees
     * with /stock, the allocator and the other free-stock surfaces (see
     * BatchItem::freeStockByVariant()). List surfaces should pre-populate it
     * with attachFreeStock() rather than trigger a query per variant.
     */
    public function getTotalStockAttribute(): int
    {
        if (array_key_exists('total_stock', $this->attributes)) {
            return (int) $this->attributes['total_stock'];
        }

        return BatchItem::freeStockByVariant([$this->id])[$this->id] ?? 0;
    }

    /**
     * Populate total_stock on a set of loaded variants from one query.
     *
     * @param  Collection<int, ProductVariant>  $variants
     */
    public static function attachFreeStock(Collection $variants): void
    {
        $free = BatchItem::freeStockByVariant($variants->pluck('id'));

        foreach ($variants as $variant) {
            // Synced as original so the computed column never reads as dirty
            // (same shape a withSum() alias would have arrived in).
            $variant->setAttribute('total_stock', $free[$variant->id] ?? 0)
                ->syncOriginalAttribute('total_stock');
        }
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

    public function getEffectiveCaseSizeAttribute(): int
    {
        return max(1, (int) ($this->case_size ?? 1));
    }
}
