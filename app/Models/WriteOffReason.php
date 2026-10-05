<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Why stock was written off. Extendable by office/admin; a reason in use is
 * deactivated, never deleted (the FK is restrictOnDelete).
 */
class WriteOffReason extends Model
{
    protected $fillable = ['code', 'label', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('label');
    }

    public function writeOffs(): HasMany
    {
        return $this->hasMany(StockWriteOff::class);
    }

    public function isInUse(): bool
    {
        return $this->writeOffs()->exists();
    }
}
