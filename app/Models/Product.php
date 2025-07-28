<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'name',
        'type',
        'description',
        'maturation_days',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'maturation_days' => 'integer',
    ];

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
