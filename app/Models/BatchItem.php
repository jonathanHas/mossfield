<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class BatchItem extends Model
{
    protected $fillable = [
        'batch_id',
        'product_variant_id',
        'quantity_produced',
        'quantity_remaining',
        'quantity_maturing',
        'unit_weight_kg',
    ];

    protected $casts = [
        'quantity_produced' => 'integer',
        'quantity_remaining' => 'integer',
        'quantity_maturing' => 'integer',
        'unit_weight_kg' => 'decimal:3',
    ];

    /**
     * Candidates for allocation: stock left, on an active, non-expired batch.
     *
     * This is only the SQL half of "available" — the maturing hold, the cheese
     * ready_date and the reservation arithmetic stay in PHP (see
     * isAvailableForAllocation() / getAvailableQuantityAttribute()), so every
     * caller still filters the result. Expiry is strict (`>`), matching
     * Batch::isExpired(): a batch expiring today is already expired.
     */
    public function scopeAllocatable(Builder $query): Builder
    {
        return $query->where('quantity_remaining', '>', 0)
            ->whereHas('batch', function ($q) {
                $q->where('status', 'active')
                    ->where(function ($q) {
                        $q->whereNull('expiry_date')
                            ->orWhere('expiry_date', '>', now()->toDateString());
                    });
            });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function sourceCuttingLogs(): HasMany
    {
        return $this->hasMany(CheeseCuttingLog::class, 'source_batch_item_id');
    }

    public function targetCuttingLogs(): HasMany
    {
        return $this->hasMany(CheeseCuttingLog::class, 'target_batch_item_id');
    }

    public function sourceConversionLogs(): HasMany
    {
        return $this->hasMany(CheeseConversionLog::class, 'source_batch_item_id');
    }

    public function targetConversionLogs(): HasMany
    {
        return $this->hasMany(CheeseConversionLog::class, 'target_batch_item_id');
    }

    public function writeOffs(): HasMany
    {
        return $this->hasMany(StockWriteOff::class);
    }

    /**
     * Units written off and not reversed. Reads the `written_off_units`
     * aggregate when the caller eager-loaded it (array_key_exists — withSum
     * yields NULL for an empty set, and NULL means 0, not "query"), then a
     * loaded writeOffs relation, then one query.
     */
    public function getWrittenOffUnitsAttribute(): int
    {
        if (array_key_exists('written_off_units', $this->getAttributes())) {
            return (int) $this->getAttributes()['written_off_units'];
        }

        if ($this->relationLoaded('writeOffs')) {
            return (int) $this->writeOffs->whereNull('reversed_at')->sum('quantity');
        }

        return (int) $this->writeOffs()->active()->sum('quantity');
    }

    /** Units that left by sale: written-off units also left, but weren't sold. */
    public function getQuantitySoldAttribute(): int
    {
        return max(0, $this->quantity_produced - $this->quantity_remaining - $this->written_off_units);
    }

    /**
     * Nominal weight of what is ON HAND (remaining × the variant's unit
     * weight). Deliberately nominal: nothing still on the shelf has been
     * weighed — wheels are weighed on the way out (fulfilment, cutting), so
     * this column has no measured basis to move to. Label it as an estimate.
     */
    public function getTotalWeightAttribute(): float
    {
        return $this->quantity_remaining * ($this->unit_weight_kg ?? 0);
    }

    /**
     * The at-a-glance wheel strip: every wheel of this item in exactly one of
     * free / allocated / maturing / cut / converted / written off / sold,
     * summing to produced.
     * Shared by the /batches and /cheese-cutting cards and their group headers
     * (it used to be copied four times, and none of the copies knew about
     * converted — so a converted wheel showed as "sold", finding 8).
     *
     * Uses the aggregates the index queries already load when present
     * (source_cutting_logs_count, converted_wheels, quantity_currently_allocated,
     * written_off_units) and falls back to querying, so it is safe on a single
     * item too.
     *
     * @return array{produced: int, free: int, allocated: int, maturing: int, cut: int, converted: int, written_off: int, sold: int}
     */
    public function wheelStates(): array
    {
        // "Was it eager-loaded?" is array_key_exists, not ??: withSum yields
        // NULL for an empty set, and a ?? fallback re-queried every item with
        // no conversions or no allocations — the /batches N+1. A loaded null
        // is 0.
        $loaded = fn (string $key) => array_key_exists($key, $this->getAttributes());

        $produced = (int) $this->quantity_produced;
        $cut = (int) ($loaded('source_cutting_logs_count')
            ? $this->getAttribute('source_cutting_logs_count')
            : ($this->relationLoaded('sourceCuttingLogs') ? $this->sourceCuttingLogs->count() : $this->sourceCuttingLogs()->count()));
        $converted = (int) ($loaded('converted_wheels')
            ? $this->getAttribute('converted_wheels')
            : ($this->relationLoaded('sourceConversionLogs') ? $this->sourceConversionLogs->sum('wheels_converted') : $this->sourceConversionLogs()->sum('wheels_converted')));
        $reserved = (int) ($loaded('quantity_currently_allocated')
            ? $this->getAttribute('quantity_currently_allocated')
            : $this->orderAllocations()->sum(DB::raw('quantity_allocated - quantity_fulfilled')));
        $written_off = $this->written_off_units; // eager-loaded sum, loaded relation, or one query

        // Sold whole = whatever left the shelf that wasn't cut, converted or written off.
        $sold = max(0, $produced - (int) $this->quantity_remaining - $cut - $converted - $written_off);
        $remaining = max(0, $produced - $cut - $converted - $sold - $written_off);
        $allocated = max(0, min($remaining, $reserved));
        $free = max(0, $remaining - $allocated);
        $maturing = min($free, (int) $this->quantity_maturing); // held wheels live inside free
        $free = max(0, $free - $maturing);

        return compact('produced', 'free', 'allocated', 'maturing', 'cut', 'converted', 'written_off', 'sold');
    }

    /**
     * Where every unit of this item is, and what it weighs — measured where a
     * measurement exists, nominal where it doesn't (docs/planImp/TESTING_AND_FIXES.md,
     * finding 2, pass 2b).
     *
     * Wheels are in exactly one of five states:
     *   on hand (incl. maturing)  nominal
     *   dispatched whole          measured — order_allocations.actual_weight_kg
     *   cut                       measured — cheese_cutting_logs.wheel_weight_kg
     *                             (nominal when null: cut before 2026-09-13)
     *   converted out to Mature   nominal — weighed only when sold from there
     *   written off               measured — stock_write_offs.weight_kg, nominal
     *                             when not weighed (active write-offs only)
     * and on_hand + dispatched + cut + converted + written_off must equal
     * produced. Written-off units still count in the finished weight: the make
     * produced them (OPEN_QUESTIONS #1).
     *
     * Vacuum packs that came off a cut wheel contribute NOTHING — the wheel
     * already did. Only packs no cut log accounts for count, nominally.
     * Milk/yoghurt units: produced × unit weight, as always — write-offs are
     * counted (written_off) but don't change the weight.
     *
     * Reads only LOADED relations — eager-load orderAllocations,
     * sourceCuttingLogs, sourceConversionLogs, targetCuttingLogs and writeOffs
     * on the batch items first (BatchController::show() does), or every row
     * costs four queries. An unloaded writeOffs reads as none (no query).
     *
     * @return array{kind: string, produced: int, on_hand: int, dispatched: int, cut: int, converted: int, written_off: int, reconciles: bool, weighed: int, measured_kg: float, nominal_kg: float, total_kg: float}
     */
    public function getWeightBreakdownAttribute(): array
    {
        $unit = (float) ($this->unit_weight_kg ?? 0);
        $produced = (int) $this->quantity_produced;
        $variant = $this->productVariant;
        $isCheese = ($this->batch?->product?->type ?? null) === 'cheese';
        // Active write-offs only (a reversed one isn't a write-off). Never
        // lazy-loaded here: an unloaded relation reads as none.
        $writeOffs = $this->relationLoaded('writeOffs')
            ? $this->writeOffs->whereNull('reversed_at')
            : collect();

        $out = [
            'kind' => 'unit',
            'produced' => $produced,
            'on_hand' => (int) $this->quantity_remaining,
            'dispatched' => 0,
            'cut' => 0,
            'converted' => 0,
            'written_off' => 0,
            'reconciles' => true,
            'weighed' => 0,
            'measured_kg' => 0.0,
            'nominal_kg' => 0.0,
            'total_kg' => 0.0,
        ];

        if ($isCheese && $variant?->isWheel()) {
            $out['kind'] = 'wheel';

            $dispatched = 0;
            $dispatchedNominalUnits = 0;
            foreach ($this->orderAllocations as $allocation) {
                $units = (int) $allocation->quantity_fulfilled;
                if ($units <= 0) {
                    continue;
                }
                $dispatched += $units;
                if ((float) $allocation->actual_weight_kg > 0) {
                    $out['weighed'] += $units;
                    $out['measured_kg'] += (float) $allocation->actual_weight_kg;
                } else {
                    $dispatchedNominalUnits += $units;
                }
            }

            $cutNominalUnits = 0;
            foreach ($this->sourceCuttingLogs as $log) {
                if ($log->isMeasured()) {
                    $out['weighed']++;
                    $out['measured_kg'] += (float) $log->wheel_weight_kg;
                } else {
                    $cutNominalUnits++;
                }
            }

            $converted = (int) $this->sourceConversionLogs->sum('wheels_converted');

            $writtenOff = 0;
            $writtenOffNominalUnits = 0;
            foreach ($writeOffs as $writeOff) {
                $units = (int) $writeOff->quantity;
                $writtenOff += $units;
                if ((float) $writeOff->weight_kg > 0) {
                    $out['weighed'] += $units;
                    $out['measured_kg'] += (float) $writeOff->weight_kg;
                } else {
                    $writtenOffNominalUnits += $units;
                }
            }

            $out['dispatched'] = $dispatched;
            $out['cut'] = $this->sourceCuttingLogs->count();
            $out['converted'] = $converted;
            $out['written_off'] = $writtenOff;
            $out['reconciles'] = ($out['on_hand'] + $dispatched + $out['cut'] + $converted + $writtenOff) === $produced;
            $out['nominal_kg'] = ($out['on_hand'] + $converted + $cutNominalUnits + $dispatchedNominalUnits + $writtenOffNominalUnits) * $unit;
        } elseif ($isCheese) {
            // Packs: only those no cut log explains are this item's own weight.
            $out['kind'] = 'pack';
            $out['written_off'] = (int) $writeOffs->sum('quantity');
            $fromCuts = (int) $this->targetCuttingLogs->sum('vacuum_packs_created');
            $out['nominal_kg'] = max(0, $produced - $fromCuts) * $unit;
        } else {
            $out['written_off'] = (int) $writeOffs->sum('quantity');
            $out['nominal_kg'] = $produced * $unit;
        }

        $out['measured_kg'] = round($out['measured_kg'], 3);
        $out['nominal_kg'] = round($out['nominal_kg'], 3);
        $out['total_kg'] = round($out['measured_kg'] + $out['nominal_kg'], 3);

        return $out;
    }

    public function reduceStock(int $quantity): bool
    {
        if ($this->quantity_remaining >= $quantity) {
            $this->quantity_remaining -= $quantity;
            $this->save();

            return true;
        }

        return false;
    }

    /**
     * Restore stock that was previously fulfilled (undo fulfillment).
     */
    public function restoreStock(int $quantity): bool
    {
        $this->quantity_remaining += $quantity;
        $this->save();

        return true;
    }

    public function orderAllocations(): HasMany
    {
        return $this->hasMany(OrderAllocation::class);
    }

    /**
     * Free stock per variant in ONE query — the bulk form of available_quantity
     * for a list surface (/products, the sibling list) where reading the
     * accessor per item would fire a SUM on every row.
     *
     * Mirrors ChilledRunController::stockByVariant(): allocatable() candidates,
     * the reservation as units still OWED (quantity_allocated −
     * quantity_fulfilled — a picked unit has already left quantity_remaining),
     * then the same PHP predicates the allocator applies, so this figure can
     * never promise more than auto-allocate would hand out. It is the seventh
     * member of the free-stock invariant (see docs/planImp/TESTING_AND_FIXES.md) — change
     * the expression here and in the other six together.
     *
     * @param  iterable<int>  $variantIds
     * @return array<int, int> variant_id => free units (variants with none are absent)
     */
    public static function freeStockByVariant(iterable $variantIds): array
    {
        $ids = collect($variantIds)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return static::allocatable()
            ->whereIn('product_variant_id', $ids)
            ->with('batch.product')
            ->withSum(
                'orderAllocations as reserved_units',
                DB::raw('quantity_allocated - quantity_fulfilled')
            )
            ->get()
            ->filter(fn (self $item) => $item->isReadyToSell() && ! $item->batch->isExpired())
            ->groupBy('product_variant_id')
            ->map(fn ($items) => (int) $items->sum(fn (self $item) => max(0, (int) $item->quantity_remaining
                - (int) ($item->reserved_units ?? 0)
                - (int) $item->quantity_maturing)))
            ->all();
    }

    public function getAvailableQuantityAttribute(): int
    {
        // Available = remaining - allocated but not yet fulfilled - maturing hold.
        // The maturing hold sets wheels aside so they're never auto-assigned to
        // an order; subtracting it here propagates that to every allocation path.
        return max(0, $this->holdable_quantity - (int) $this->quantity_maturing);
    }

    /**
     * Wheels that can be set aside for maturing: remaining stock minus what is
     * already reserved by unfulfilled orders (independent of the current hold).
     * Equals available_quantity + quantity_maturing.
     *
     * The reservation is `quantity_allocated - quantity_fulfilled` — the units
     * still owed, NOT the whole allocation. A picked unit has already left
     * `quantity_remaining`, so counting it here would subtract it twice and
     * hide real stock from every allocation path. Do not re-add a
     * `whereNull('fulfilled_at')` filter: a fully-fulfilled allocation
     * contributes zero to this sum anyway, and on a short-closed line
     * `releaseUnits()` leaves `fulfilled_at` null even though nothing is
     * outstanding — which is exactly how that stock went missing permanently.
     * Same expression as StockOverviewService, BatchController,
     * CheeseCuttingController and CheeseConversionController; keep all of them
     * and ChilledRunController::stockByVariant() in agreement.
     */
    public function getHoldableQuantityAttribute(): int
    {
        $allocated = (int) $this->orderAllocations()
            ->sum(DB::raw('quantity_allocated - quantity_fulfilled'));

        return max(0, $this->quantity_remaining - $allocated);
    }

    public function isReadyToSell(): bool
    {
        // Check if this batch item is ready based on product type and ready date
        if ($this->batch->product->type === 'cheese' && $this->batch->ready_date) {
            return $this->batch->ready_date <= now()->toDateString();
        }

        // Milk and yoghurt are ready immediately
        return true;
    }

    public function isAvailableForAllocation(): bool
    {
        return $this->isReadyToSell()
            && ! $this->batch->isExpired()
            && $this->available_quantity > 0;
    }
}
