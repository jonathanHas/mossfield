<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

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

    /** Every write-off (active and reversed) on this batch's items. */
    public function writeOffs(): HasManyThrough
    {
        return $this->hasManyThrough(StockWriteOff::class, BatchItem::class, 'batch_id', 'batch_item_id');
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

    /**
     * What's left, per variant: "10 wheels · 18 packs" (F-14) — summing
     * wheels and packs into "29 units" said nothing. Uses the loaded
     * batchItems.productVariant when present. Variants sharing a unit (1L
     * and 2L bottles) are named instead: "214 × 1L Bottle · 80 × 2L Bottle".
     * A single-variant batch keeps "N units".
     */
    public function getRemainingLabelAttribute(): string
    {
        $items = ($this->relationLoaded('batchItems') ? $this->batchItems : $this->batchItems()->get())
            ->loadMissing('productVariant')
            ->filter(fn ($item) => (int) $item->quantity_produced > 0)
            ->values();

        if ($items->count() <= 1) {
            $qty = (int) $items->sum('quantity_remaining');

            return $qty.' '.Str::plural('unit', $qty);
        }

        $units = $items->map(fn ($item) => strtolower((string) $item->productVariant?->unit));
        $byUnit = $units->unique()->count() === $items->count() && $units->every(fn ($unit) => $unit !== '');

        return $items->map(function ($item) use ($byUnit) {
            $qty = (int) $item->quantity_remaining;

            return $byUnit
                ? $qty.' '.Str::plural($item->productVariant->unit, $qty)
                : $qty.' × '.$item->productVariant->name;
        })->implode(' · ');
    }

    /**
     * The ready-date countdown: exact days inside ~3 months ("in 60 days" —
     * diffForHumans() said "1 month from now"), the relative phrase beyond.
     */
    public function readyIn(): ?string
    {
        if (! $this->ready_date) {
            return null;
        }

        $days = (int) today()->diffInDays($this->ready_date->copy()->startOfDay(), false);

        return match (true) {
            $days === 0 => 'today',
            abs($days) >= 90 => $this->ready_date->diffForHumans(),
            $days > 0 => 'in '.$days.' '.Str::plural('day', $days),
            default => abs($days).' '.Str::plural('day', abs($days)).' ago',
        };
    }

    /** A converted Mature batch carries no raw milk of its own (it came from its source). */
    public function hasNoRawMilkOfItsOwn(): bool
    {
        return $this->source_batch_id !== null && (float) $this->raw_milk_litres == 0.0;
    }

    /**
     * Why this batch can't be deleted, or null when it can. The one place the
     * rule lives: the /batches card hides Delete on it, BatchController::destroy()
     * refuses on it.
     */
    public function deletionBlocker(): ?string
    {
        ['allocations' => $allocations, 'cuts' => $cuts, 'conversions' => $conversions, 'write_offs' => $writeOffs] = $this->dependentCounts();

        $reasons = [];
        if ($allocations) {
            $reasons[] = $allocations.' order '.($allocations === 1 ? 'allocation' : 'allocations');
        }
        if ($cuts) {
            $reasons[] = $cuts.' cheese-cutting '.($cuts === 1 ? 'record' : 'records');
        }
        if ($conversions) {
            $reasons[] = $conversions.' mature-conversion '.($conversions === 1 ? 'record' : 'records');
        }
        if ($writeOffs) {
            $reasons[] = $writeOffs.' write-off '.($writeOffs === 1 ? 'record' : 'records');
        }

        if (! $reasons) {
            return null;
        }

        return 'Batch '.$this->batch_code.' can\'t be deleted — '.implode(' and ', $reasons).
            ' depend on it, and deleting it would destroy that traceability. '.
            'Set its status to sold out or expired instead to take it out of circulation.';
    }

    /** How many records hold the batch in place (the card's "Can't delete — N records …"). */
    public function dependentRecordCount(): int
    {
        return array_sum($this->dependentCounts());
    }

    /**
     * Allocations, cutting logs and conversion logs under this batch's items.
     * Reads the *_count attributes from loaded batchItems when the caller
     * eager-loaded them (BatchController::index()), else one withCount query.
     *
     * @return array{allocations: int, cuts: int, conversions: int, write_offs: int}
     */
    private function dependentCounts(): array
    {
        // write_offs_count counts reversed write-offs too: they're history.
        $keys = ['order_allocations_count', 'source_cutting_logs_count', 'target_cutting_logs_count',
            'source_conversion_logs_count', 'target_conversion_logs_count', 'write_offs_count'];

        $items = $this->relationLoaded('batchItems')
            && $this->batchItems->every(fn ($item) => collect($keys)->every(fn ($k) => array_key_exists($k, $item->getAttributes())))
            ? $this->batchItems
            : $this->batchItems()->withCount([
                'orderAllocations',
                'sourceCuttingLogs',
                'targetCuttingLogs',
                'sourceConversionLogs',
                'targetConversionLogs',
                'writeOffs',
            ])->get();

        return [
            'allocations' => (int) $items->sum('order_allocations_count'),
            'cuts' => (int) $items->sum('source_cutting_logs_count') + (int) $items->sum('target_cutting_logs_count'),
            'conversions' => (int) $items->sum('source_conversion_logs_count') + (int) $items->sum('target_conversion_logs_count'),
            'write_offs' => (int) $items->sum('write_offs_count'),
        ];
    }

    /**
     * Active write-offs on this batch, from the LOADED batchItems.writeOffs
     * (BatchController::show() loads them): units, and the kg of the ones that
     * were weighed.
     *
     * @return array{units: int, weighed_kg: float}
     */
    public function writtenOffSummary(): array
    {
        $active = $this->batchItems->flatMap(fn ($item) => $item->relationLoaded('writeOffs') ? $item->writeOffs : collect())
            ->whereNull('reversed_at');

        return [
            'units' => (int) $active->sum('quantity'),
            'weighed_kg' => round((float) $active->sum(fn ($w) => (float) ($w->weight_kg ?? 0)), 3),
        ];
    }

    /**
     * Units an expired batch could still write off: Σ holdable (remaining −
     * still owed) per item. Uses the index's eager-loaded
     * quantity_currently_allocated when present, so a card costs no query.
     */
    public function writableUnits(): int
    {
        return (int) $this->batchItems->sum(function (BatchItem $item) {
            if (array_key_exists('quantity_currently_allocated', $item->getAttributes())) {
                return max(0, (int) $item->quantity_remaining - (int) $item->getAttributes()['quantity_currently_allocated']);
            }

            return $item->holdable_quantity;
        });
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

        // Finished weight ÷ raw milk, for every type. For milk a variant's
        // weight_kg is its volume in litres, so this is bottled ÷ raw — no
        // longer a hard-coded 1:1 (OPEN_QUESTIONS #5, F-06); the batch form
        // refuses bottling more than the raw milk entered.
        $finishedWeight = $this->getFinishedProductWeightAttribute();

        return $finishedWeight / $this->raw_milk_litres;
    }
}
