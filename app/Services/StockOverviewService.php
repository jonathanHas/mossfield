<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\BatchItem;
use Illuminate\Support\Facades\DB;

class StockOverviewService
{
    public function build(): array
    {
        // Sellable stock = batch.status='active' AND not past expiry. Mirrors
        // BatchItem::isAvailableForAllocation(). Stale-active rows whose
        // expiry has passed are excluded so totals don't double-count
        // unsellable inventory.
        $today = now()->startOfDay()->toDateString();

        $items = BatchItem::query()
            ->with(['batch.product', 'productVariant'])
            ->withCount(['sourceCuttingLogs', 'sourceConversionLogs'])
            ->withSum([
                'orderAllocations as quantity_currently_allocated' => fn ($q) => $q->whereNull('fulfilled_at'),
            ], DB::raw('quantity_allocated - quantity_fulfilled'))
            ->whereHas('batch', function ($q) use ($today) {
                $q->where('status', 'active')
                    ->where(function ($q) use ($today) {
                        $q->whereNull('expiry_date')
                            ->orWhere('expiry_date', '>=', $today);
                    });
            })
            ->get();

        $byType = $items->groupBy(fn ($i) => $i->batch->product->type);

        return [
            'total_value' => $this->totalValue($items),
            'milk' => $this->buildSimpleCard($byType->get('milk', collect()), 'milk'),
            'yoghurt' => $this->buildSimpleCard($byType->get('yoghurt', collect()), 'yoghurt'),
            'cheese' => $this->buildCheeseCard($byType->get('cheese', collect())),
        ];
    }

    private function totalValue($items): float
    {
        return (float) $items->sum(fn ($i) => $i->productVariant->calculatePrice($i->available_quantity));
    }

    /**
     * Expand milk or yoghurt items into one row per (variant, batch) — FIFO order within a variant.
     */
    private function buildSimpleCard($items, string $type): array
    {
        if ($items->isEmpty()) {
            return [
                'active_batches' => collect(),
                'raw_litres' => 0.0,
                'variants' => [],
                'variant_count' => 0,
                'tag' => null,
            ];
        }

        $batches = $items->pluck('batch')->unique('id')->values();
        $rawLitres = (float) $batches->sum(fn ($b) => (float) ($b->raw_milk_litres ?? 0));
        // When a card aggregates more than one batch, showing a single batch
        // code next to combined totals reads as if those numbers belong to
        // that one batch. Switch to a count instead.
        $tag = $batches->count() === 1
            ? $batches->first()?->batch_code
            : $batches->count().' batches';

        $rows = $items->groupBy(fn ($i) => $i->product_variant_id.'|'.$i->batch_id)->map(function ($group) {
            $first = $group->first();
            $variant = $first->productVariant;
            $batch = $first->batch;
            $produced = (int) $group->sum('quantity_produced');
            $remaining = (int) $group->sum('quantity_remaining');
            $allocated = (int) $group->sum(function ($i) {
                $alloc = (int) ($i->quantity_currently_allocated ?? 0);

                return max(0, min((int) $i->quantity_remaining, $alloc));
            });
            $available = max(0, $remaining - $allocated);
            $sold = max(0, $produced - $remaining);

            $expiry = $batch->expiry_date;
            $daysToExpiry = $expiry ? (int) now()->startOfDay()->diffInDays($expiry, false) : null;
            $expiryWarn = $daysToExpiry !== null && $daysToExpiry >= 0 && $daysToExpiry < 5;

            return [
                'label' => $variant->name,
                'case_size' => $variant->effective_case_size,
                'total' => $produced,
                'segments' => [
                    'available' => $available,
                    'allocated' => $allocated,
                    'sold' => $sold,
                ],
                'expiry' => $expiry,
                'expiry_warn' => $expiryWarn,
                'batch_code' => $batch->batch_code,
                '_sort_variant' => $variant->name,
                '_sort_date' => optional($batch->production_date)->toDateString() ?? '',
            ];
        })
            ->sortBy([['_sort_variant', 'asc'], ['_sort_date', 'asc']])
            ->values()
            ->all();

        return [
            'active_batches' => $batches,
            'raw_litres' => $rawLitres,
            'variants' => $rows,
            'variant_count' => $items->pluck('product_variant_id')->unique()->count(),
            'tag' => $tag,
        ];
    }

    /**
     * Cheese mirrors the wheel/pack breakdown logic in batches/partials/batch-card.blade.php.
     * Emits one row per (variant, batch), splitting wheels (with cut tracking) from packs.
     */
    private function buildCheeseCard($items): array
    {
        if ($items->isEmpty()) {
            return ['wheels' => [], 'packs' => [], 'tag' => null, 'active_batches' => collect()];
        }

        $batches = $items->pluck('batch')->unique('id')->values();
        $tag = $batches->count() === 1
            ? $batches->first()?->batch_code
            : $batches->count().' batches';

        $wheels = [];
        $packs = [];

        $isWheel = fn ($name) => str_contains(strtolower($name), 'wheel');

        foreach ($items->groupBy(fn ($i) => $i->product_variant_id.'|'.$i->batch_id) as $group) {
            $first = $group->first();
            $variant = $first->productVariant;
            $batch = $first->batch;
            $produced = (int) $group->sum('quantity_produced');
            $remaining = (int) $group->sum('quantity_remaining');
            $allocated = (int) $group->sum(function ($i) {
                return max(0, min((int) $i->quantity_remaining, (int) ($i->quantity_currently_allocated ?? 0)));
            });

            $label = $variant->product->name.' · '.$variant->name;
            $sortKey = [
                '_sort_label' => $label,
                '_sort_date' => optional($batch->production_date)->toDateString() ?? '',
            ];

            if ($isWheel($variant->name)) {
                $cut = (int) $group->sum(fn ($i) => (int) ($i->source_cutting_logs_count ?? 0));
                $converted = (int) $group->sum(fn ($i) => (int) ($i->source_conversion_logs_count ?? 0));
                $maturing = (int) $group->sum(fn ($i) => (int) $i->quantity_maturing);
                $sold = max(0, $produced - $remaining - $cut - $converted);
                $remainingClamped = max(0, $produced - $cut - $converted - $sold);
                // Held-for-maturing wheels are part of remaining stock but set
                // aside — never available to sell or allocate.
                $maturing = min($maturing, $remainingClamped);
                $available = max(0, $remainingClamped - $allocated - $maturing);
                $wheels[] = [
                    'label' => $label,
                    'total' => $produced,
                    'segments' => [
                        'available' => $available,
                        'allocated' => $allocated,
                        'maturing' => $maturing,
                        'cut' => $cut,
                        'converted' => $converted,
                        'sold' => $sold,
                    ],
                    'batch_code' => $batch->batch_code,
                ] + $sortKey;
            } else {
                if ($produced <= 0) {
                    continue;
                }
                $sold = max(0, $produced - $remaining);
                $available = max(0, $remaining - $allocated);
                $packs[] = [
                    'label' => $label,
                    'total' => $produced,
                    'segments' => [
                        'available' => $available,
                        'allocated' => $allocated,
                        'sold' => $sold,
                    ],
                    'batch_code' => $batch->batch_code,
                ] + $sortKey;
            }
        }

        $sortRows = fn (array $rows) => collect($rows)
            ->sortBy([['_sort_label', 'asc'], ['_sort_date', 'asc']])
            ->values()
            ->all();

        return [
            'wheels' => $sortRows($wheels),
            'packs' => $sortRows($packs),
            'tag' => $tag,
            'active_batches' => $batches,
        ];
    }
}
