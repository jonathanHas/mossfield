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
