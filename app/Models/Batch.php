<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Batch extends Model
{
    protected $fillable = [
        'product_id',
        'source_batch_id',
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
            if (! $batch->batch_code) {
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

    /**
     * Cuts made from this batch's wheels. Through batch_items — the log keys
     * on the wheel ITEM, not the batch (the old hasMany compared batches.id
     * with source_batch_item_id and matched a coincidental item instead).
     */
    public function cuttingLogs(): HasManyThrough
    {
        return $this->hasManyThrough(CheeseCuttingLog::class, BatchItem::class, 'batch_id', 'source_batch_item_id');
    }

    /**
     * Trim lost across every measured cut on this batch (Σ wheel − packs).
     * Unmeasured (pre-2026-09-13) cuts contribute nothing and are counted
     * separately so the figure is never quietly partial.
     */
    public function getTrimLossKgAttribute(): float
    {
        return round((float) $this->cuttingLogs->sum(fn (CheeseCuttingLog $log) => $log->waste_kg ?? 0), 3);
    }

    public function getMeasuredCutsCountAttribute(): int
    {
        return $this->cuttingLogs->filter->isMeasured()->count();
    }

    public function getUnmeasuredCutsCountAttribute(): int
    {
        return $this->cuttingLogs->reject->isMeasured()->count();
    }

    /**
     * The Farmhouse batch this Mature batch was converted from (null for
     * normal batches). The conversion get-or-creates one Mature batch per
     * source, so this is a clean 1:1 traceability link.
     */
    public function sourceBatch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'source_batch_id');
    }

    /**
     * Mature batches produced by converting wheels from this batch.
     */
    public function matureBatches(): HasMany
    {
        return $this->hasMany(Batch::class, 'source_batch_id');
    }

    /**
     * Whether this batch is old enough to convert into Mature cheese.
     * Age-based eligibility (default 5 months) — deliberately distinct from
     * maturation_days / ready_date, which only gate "ready to sell".
     */
    public function isEligibleForMaturation(): bool
    {
        if (! $this->production_date) {
            return false;
        }

        $months = (int) config('mossfield.mature_conversion_months', 5);

        return $this->production_date->copy()->addMonths($months)->lte(now());
    }

    public function generateBatchCode(): string
    {
        $prefix = $this->product->getTypePrefix();
        $dateCode = $this->production_date->format('dmy');
        $baseCode = $prefix.$dateCode;

        // Check for duplicates and add a suffix if needed
        $counter = 1;
        $finalCode = $baseCode;

        while (self::where('batch_code', $finalCode)->exists()) {
            $finalCode = $baseCode.'-'.$counter;
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

    /**
     * What the batch's output weighs: per item, measured where a measurement
     * exists and nominal where it doesn't (see BatchItem::weight_breakdown).
     * A cut wheel counts once, as the wheel — never again as its packs.
     * weight_coverage says how much of this figure is measured.
     */
    public function getFinishedProductWeightAttribute(): float
    {
        return round((float) $this->weightBreakdowns()->sum('total_kg'), 3);
    }

    /**
     * One breakdown per item, with the parent batch handed down so the item's
     * cheese-or-not check doesn't lazy-load batch + product per row.
     *
     * @return \Illuminate\Support\Collection<int, array>
     */
    private function weightBreakdowns(): \Illuminate\Support\Collection
    {
        return $this->batchItems->map(function (BatchItem $item) {
            if (! $item->relationLoaded('batch')) {
                $item->setRelation('batch', $this);
            }

            return $item->weight_breakdown;
        });
    }

    /**
     * How much of finished_product_weight is measured, so a mostly-nominal
     * figure never reads as fact. Wheel counts cover wheel items only.
     *
     * @return array{has_wheels: bool, wheels_produced: int, wheels_weighed: int, measured_kg: float, nominal_kg: float, reconciles: bool}
     */
    public function getWeightCoverageAttribute(): array
    {
        $breakdowns = $this->weightBreakdowns();
        $wheels = $breakdowns->where('kind', 'wheel');

        return [
            'has_wheels' => $wheels->isNotEmpty(),
            'wheels_produced' => (int) $wheels->sum('produced'),
            'wheels_weighed' => (int) $wheels->sum('weighed'),
            'measured_kg' => round((float) $breakdowns->sum('measured_kg'), 3),
            'nominal_kg' => round((float) $breakdowns->sum('nominal_kg'), 3),
            'reconciles' => $breakdowns->every(fn ($b) => $b['reconciles']),
        ];
    }

    public function getProductionYieldAttribute(): ?float
    {
        if (! $this->raw_milk_litres || $this->raw_milk_litres == 0) {
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
