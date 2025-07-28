<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Batch extends Model
{
    protected $fillable = [
        'product_id',
        'batch_code',
        'production_date',
        'expiry_date',
        'ready_date',
        'raw_milk_litres',
        'wheels_produced',
        'notes',
        'status',
    ];

    protected $casts = [
        'production_date' => 'date',
        'expiry_date' => 'date',
        'ready_date' => 'date',
        'raw_milk_litres' => 'decimal:3',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($batch) {
            if (!$batch->batch_code) {
                $batch->batch_code = $batch->generateBatchCode();
            }

            // Set ready_date for cheese based on maturation days
            if ($batch->product && $batch->product->isCheese() && $batch->product->maturation_days) {
                $batch->ready_date = $batch->production_date->addDays($batch->product->maturation_days);
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batchItems(): HasMany
    {
        return $this->hasMany(BatchItem::class);
    }

    public function cuttingLogs(): HasMany
    {
        return $this->hasMany(CheeseCuttingLog::class, 'source_batch_item_id');
    }

    public function generateBatchCode(): string
    {
        $prefix = $this->product->getTypePrefix();
        $dateCode = $this->production_date->format('dmy');
        $baseCode = $prefix . $dateCode;
        
        // Check for duplicates and add a suffix if needed
        $counter = 1;
        $finalCode = $baseCode;
        
        while (self::where('batch_code', $finalCode)->exists()) {
            $finalCode = $baseCode . '-' . $counter;
            $counter++;
        }
        
        return $finalCode;
    }

    public function isReadyToSell(): bool
    {
        return $this->ready_date ? $this->ready_date->lte(now()) : true;
    }

    public function isExpired(): bool
    {
        return $this->expiry_date ? $this->expiry_date->lt(now()) : false;
    }

    public function getRemainingStockAttribute(): int
    {
        return $this->batchItems()->sum('quantity_remaining');
    }

    public function getFinishedProductWeightAttribute(): float
    {
        $total = 0;
        foreach ($this->batchItems as $item) {
            $total += $item->quantity_produced * ($item->unit_weight_kg ?? 0);
        }
        return $total;
    }

    public function getProductionYieldAttribute(): ?float
    {
        if (!$this->raw_milk_litres || $this->raw_milk_litres == 0) {
            return null;
        }
        
        // For milk, yield is 1:1 (just bottled)
        if ($this->product->type === 'milk') {
            return 1.0;
        }
        
        // For cheese/yoghurt, calculate yield as finished weight / raw milk
        $finishedWeight = $this->getFinishedProductWeightAttribute();
        return $finishedWeight / $this->raw_milk_litres;
    }
}
