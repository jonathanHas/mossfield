<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'name',
        'type',
        'description',
        'image_path',
        'maturation_days',
        'shelf_life_days',
        'is_active',
        'conversion_only',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'conversion_only' => 'boolean',
        'maturation_days' => 'integer',
        'shelf_life_days' => 'integer',
    ];

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image_path
            ? '/storage/'.ltrim($this->image_path, '/')
            : null);
    }

    /**
     * The product name without the shared "Mossfield " prefix — for option
     * labels where every product carries it and it only adds width.
     */
    public function getShortNameAttribute(): string
    {
        return preg_replace('/^Mossfield\s+/i', '', (string) $this->name) ?: (string) $this->name;
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    /**
     * Why this product can't be deleted, or null when it can. The one place the
     * rule lives: /products greys Delete on it, ProductController::destroy()
     * refuses on it. Bare variants don't block — they are config and go with
     * the product.
     */
    public function deletionBlocker(): ?string
    {
        ['batches' => $batches, 'order_lines' => $orderLines, 'special_prices' => $specialPrices] = $this->dependentCounts();

        $reasons = [];
        if ($batches) {
            $reasons[] = $batches.' '.($batches === 1 ? 'batch' : 'batches');
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

        return $this->short_name.' can\'t be deleted — '.implode(' and ', $reasons).
            ($this->dependentRecordCount() === 1 ? ' depends' : ' depend').
            ' on it, and deleting it would destroy that traceability. Set the product inactive instead.';
    }

    /**
     * How many records hold the product in place ("Can't delete — N records …"):
     * batches + order lines + special prices. Not the variants' batch items —
     * they live inside the product's batches and would be counted twice.
     */
    public function dependentRecordCount(): int
    {
        return array_sum($this->dependentCounts());
    }

    /**
     * Batches from batches_count when the loader counted them (never the
     * loaded batches relation — products.show caps it at 10), else one count
     * query; order lines and special prices summed over the variants' counts,
     * from the loaded variants when each carries them, else one withCount query.
     *
     * @return array{batches: int, order_lines: int, special_prices: int}
     */
    private function dependentCounts(): array
    {
        $batches = array_key_exists('batches_count', $this->getAttributes())
            ? (int) $this->getAttributes()['batches_count']
            : $this->batches()->count();

        $keys = ['batch_items_count', 'order_items_count', 'special_prices_count'];
        $variants = $this->relationLoaded('variants')
            && $this->variants->every(fn ($v) => collect($keys)->every(fn ($k) => array_key_exists($k, $v->getAttributes())))
            ? $this->variants
            : $this->variants()->withCount(['batchItems', 'orderItems', 'specialPrices'])->get();

        $counts = $variants->map->dependentCounts();

        return [
            'batches' => $batches,
            'order_lines' => (int) $counts->sum('order_lines'),
            'special_prices' => (int) $counts->sum('special_prices'),
        ];
    }

    public function getTypePrefix(): string
    {
        return match ($this->type) {
            'milk' => 'M',
            'yoghurt' => 'Y',
            'cheese' => 'G',
            default => 'X',
        };
    }

    public function isCheese(): bool
    {
        return $this->type === 'cheese';
    }
}
