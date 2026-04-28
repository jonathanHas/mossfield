<?php

namespace App\Http\Controllers;

use App\Models\BatchItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockController extends Controller
{
    public function index(Request $request): View
    {
        // Get all stock items with remaining quantity > 0
        $stockQuery = BatchItem::with(['batch.product', 'productVariant'])
            ->where('quantity_remaining', '>', 0)
            ->whereHas('batch', function ($q) {
                $q->where('status', 'active');
            });

        // Apply filters
        if ($request->filled('product_type')) {
            $stockQuery->whereHas('batch.product', function ($q) use ($request) {
                $q->where('type', $request->product_type);
            });
        }

        if ($request->filled('readiness')) {
            if ($request->readiness === 'ready') {
                $stockQuery->whereHas('batch', function ($q) {
                    $q->where(function ($subQ) {
                        $subQ->whereNull('ready_date')
                            ->orWhere('ready_date', '<=', now());
                    });
                });
            } elseif ($request->readiness === 'maturing') {
                $stockQuery->whereHas('batch', function ($q) {
                    $q->where('ready_date', '>', now());
                });
            }
        }

        $stockItems = $stockQuery->orderBy('created_at', 'desc')->get();

        // Group stock items
        $readyToSell = $stockItems->filter(function ($item) {
            return $item->batch->isReadyToSell();
        })->sortBy(function ($item) {
            // Sort ready items by expiry date (earliest first), then production date
            return $item->batch->expiry_date ?? $item->batch->production_date;
        });

        $maturing = $stockItems->filter(function ($item) {
            return ! $item->batch->isReadyToSell();
        })->sortBy('batch.ready_date');

        // Calculate totals
        $readyValue = $readyToSell->sum(function ($item) {
            return $item->productVariant->calculatePrice($item->quantity_remaining);
        });

        $maturingValue = $maturing->sum(function ($item) {
            return $item->productVariant->calculatePrice($item->quantity_remaining);
        });

        $totalValue = $readyValue + $maturingValue;

        // Get summary statistics
        $summaryByType = $stockItems->groupBy('batch.product.type')->map(function ($items, $type) {
            $totalQuantity = $items->sum('quantity_remaining');
            $totalValue = $items->sum(function ($item) {
                return $item->productVariant->calculatePrice($item->quantity_remaining);
            });

            return [
                'type' => $type,
                'total_quantity' => $totalQuantity,
                'total_value' => $totalValue,
                'ready_quantity' => $items->filter(fn ($item) => $item->batch->isReadyToSell())->sum('quantity_remaining'),
                'maturing_quantity' => $items->filter(fn ($item) => ! $item->batch->isReadyToSell())->sum('quantity_remaining'),
            ];
        });

        // Enhanced maturing data for calendar view
        $maturingCalendar = $this->organizeMaturingByTimeAndType($maturing);

        // Enhanced maturing data for gantt timeline view
        $maturingTimeline = $this->organizeMaturingForGanttView($maturing, $request->get('sort', 'ready_date'));

        return view('stock.index', compact(
            'readyToSell',
            'maturing',
            'maturingCalendar',
            'maturingTimeline',
            'readyValue',
            'maturingValue',
            'totalValue',
            'summaryByType'
        ));
    }

    private function organizeMaturingByTimeAndType($maturing)
    {
        $organized = [];
        $now = Carbon::now();

        // Group by month and week
        foreach ($maturing as $item) {
            if (! $item->batch->ready_date) {
                continue;
            }

            $readyDate = Carbon::parse($item->batch->ready_date);
            $monthKey = $readyDate->format('Y-m');
            $weekOfMonth = (int) ceil($readyDate->day / 7);
            $weekKey = 'week_'.$weekOfMonth;

            // Calculate maturation progress
            $productionDate = Carbon::parse($item->batch->production_date);
            $totalMaturationDays = $productionDate->diffInDays($readyDate);
            $daysSinceProduction = $productionDate->diffInDays($now);
            $progress = $totalMaturationDays > 0 ? min(100, ($daysSinceProduction / $totalMaturationDays) * 100) : 100;

            // Determine urgency
            $daysUntilReady = (int) $now->diffInDays($readyDate, false);
            $urgency = 'normal';
            if ($daysUntilReady <= 7) {
                $urgency = 'urgent';
            } elseif ($daysUntilReady <= 14) {
                $urgency = 'soon';
            }

            $organized[$monthKey]['meta'] = [
                'month_name' => $readyDate->format('F Y'),
                'month_short' => $readyDate->format('M'),
                'year' => $readyDate->year,
            ];

            $organized[$monthKey]['weeks'][$weekKey]['items'][] = [
                'item' => $item,
                'product_name' => $item->batch->product->name,
                'batch_code' => $item->batch->batch_code,
                'ready_date' => $readyDate,
                'days_until_ready' => $daysUntilReady,
                'progress' => $progress,
                'urgency' => $urgency,
                'cheese_type' => $this->getCheeseTypeFromName($item->batch->product->name),
            ];

            // Group by cheese type within week
            $cheeseType = $this->getCheeseTypeFromName($item->batch->product->name);
            $organized[$monthKey]['weeks'][$weekKey]['by_type'][$cheeseType][] = $organized[$monthKey]['weeks'][$weekKey]['items'][array_key_last($organized[$monthKey]['weeks'][$weekKey]['items'])];
        }

        // Sort by month, then by week
        ksort($organized);
        foreach ($organized as &$month) {
            if (isset($month['weeks'])) {
                ksort($month['weeks']);
            }
        }

        return $organized;
    }

    private function getCheeseTypeFromName($productName)
    {
        $name = strtolower($productName);

        if (str_contains($name, 'garlic') || str_contains($name, 'basil')) {
            return 'garlic_basil';
        } elseif (str_contains($name, 'tomato') || str_contains($name, 'herb')) {
            return 'tomato_herb';
        } elseif (str_contains($name, 'cumin')) {
            return 'cumin_seed';
        } elseif (str_contains($name, 'mature')) {
            return 'mature';
        } else {
            return 'farmhouse';
        }
    }

    private function getCheeseTypeColor($cheeseType)
    {
        return match ($cheeseType) {
            'farmhouse' => 'amber',
            'garlic_basil' => 'green',
            'tomato_herb' => 'red',
            'cumin_seed' => 'yellow',
            'mature' => 'purple',
            default => 'gray',
        };
    }

    private function organizeMaturingForGanttView($maturing, $sortBy = 'ready_date')
    {
        $now = Carbon::now();
        $timeline = [];

        // Generate timeline columns (next 24 weeks = ~6 months)
        $timelineColumns = [];
        $monthSpans = [];
        $currentMonth = null;
        $monthWeekCount = 0;

        for ($i = 0; $i < 24; $i++) {
            $weekStart = $now->copy()->addWeeks($i)->startOfWeek();
            $monthKey = $weekStart->format('Y-m');
            $monthName = $weekStart->format('M Y');
            $isNewMonth = $i === 0 || $weekStart->format('m') !== $now->copy()->addWeeks($i - 1)->startOfWeek()->format('m');

            // Track month spans for header layout
            if ($isNewMonth) {
                if ($currentMonth !== null) {
                    $monthSpans[] = [
                        'month' => $currentMonth,
                        'week_count' => $monthWeekCount,
                        'start_index' => $i - $monthWeekCount,
                    ];
                }
                $currentMonth = $monthName;
                $monthWeekCount = 1;
            } else {
                $monthWeekCount++;
            }

            $timelineColumns[] = [
                'week_start' => $weekStart,
                'week_end' => $weekStart->copy()->endOfWeek(),
                'month_name' => $weekStart->format('M'),
                'month_name_full' => $monthName,
                'week_label' => $weekStart->format('j'),
                'month_key' => $monthKey,
                'is_new_month' => $isNewMonth,
            ];
        }

        // Add the last month span
        if ($currentMonth !== null) {
            $monthSpans[] = [
                'month' => $currentMonth,
                'week_count' => $monthWeekCount,
                'start_index' => 24 - $monthWeekCount,
            ];
        }

        // Process each maturing batch
        $batchRows = [];
        foreach ($maturing as $item) {
            if (! $item->batch->ready_date) {
                continue;
            }

            $readyDate = Carbon::parse($item->batch->ready_date);
            $productionDate = Carbon::parse($item->batch->production_date);

            // Calculate which week column the ready date falls into
            $readyWeekColumn = null;
            $productionWeekColumn = null;

            foreach ($timelineColumns as $index => $column) {
                if ($readyDate->between($column['week_start'], $column['week_end'])) {
                    $readyWeekColumn = $index;
                }
                if ($productionDate->between($column['week_start'], $column['week_end'])) {
                    $productionWeekColumn = $index;
                }
            }

            // Calculate progress and urgency
            $totalMaturationDays = $productionDate->diffInDays($readyDate);
            $daysSinceProduction = $productionDate->diffInDays($now);
            $progress = $totalMaturationDays > 0 ? min(100, ($daysSinceProduction / $totalMaturationDays) * 100) : 100;
            $daysUntilReady = (int) $now->diffInDays($readyDate, false);

            $urgency = 'normal';
            if ($daysUntilReady <= 7) {
                $urgency = 'urgent';
            } elseif ($daysUntilReady <= 14) {
                $urgency = 'soon';
            }

            // Calculate visual quantity indicators
            $quantity = $item->quantity_remaining;
            $quantityIndicators = min(10, max(1, (int) ceil($quantity / 10))); // 1-10 dots

            $batchRows[] = [
                'batch_code' => $item->batch->batch_code,
                'product_name' => $item->batch->product->name,
                'cheese_type' => $this->getCheeseTypeFromName($item->batch->product->name),
                'quantity' => $quantity,
                'quantity_indicators' => $quantityIndicators,
                'production_date' => $productionDate,
                'ready_date' => $readyDate,
                'ready_week_column' => $readyWeekColumn,
                'production_week_column' => $productionWeekColumn,
                'progress' => $progress,
                'urgency' => $urgency,
                'days_until_ready' => $daysUntilReady,
                'item' => $item,
                'maturation_span' => [
                    'start_column' => $productionWeekColumn,
                    'end_column' => $readyWeekColumn,
                    'length' => $readyWeekColumn !== null && $productionWeekColumn !== null
                        ? $readyWeekColumn - $productionWeekColumn + 1 : 1,
                ],
            ];
        }

        // Sort batches based on selected sort option
        usort($batchRows, function ($a, $b) use ($sortBy) {
            switch ($sortBy) {
                case 'cheese_type':
                    $typeCompare = strcmp($a['cheese_type'], $b['cheese_type']);

                    return $typeCompare !== 0 ? $typeCompare : ($a['ready_date']->timestamp <=> $b['ready_date']->timestamp);

                case 'batch_code':
                    return strcmp($a['batch_code'], $b['batch_code']);

                case 'quantity':
                    $quantityCompare = $b['quantity'] <=> $a['quantity']; // Descending (largest first)

                    return $quantityCompare !== 0 ? $quantityCompare : strcmp($a['batch_code'], $b['batch_code']);

                case 'ready_date':
                default:
                    $dateCompare = $a['ready_date']->timestamp <=> $b['ready_date']->timestamp;

                    return $dateCompare !== 0 ? $dateCompare : strcmp($a['batch_code'], $b['batch_code']);
            }
        });

        return [
            'columns' => $timelineColumns,
            'month_spans' => $monthSpans,
            'batches' => $batchRows,
        ];
    }
}
