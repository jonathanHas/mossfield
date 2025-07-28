<?php

namespace App\Http\Controllers;

use App\Models\BatchItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;

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
        });

        $maturing = $stockItems->filter(function ($item) {
            return !$item->batch->isReadyToSell();
        });

        // Calculate totals
        $readyValue = $readyToSell->sum(function ($item) {
            return $item->quantity_remaining * $item->productVariant->base_price;
        });

        $maturingValue = $maturing->sum(function ($item) {
            return $item->quantity_remaining * $item->productVariant->base_price;
        });

        $totalValue = $readyValue + $maturingValue;

        // Get summary statistics
        $summaryByType = $stockItems->groupBy('batch.product.type')->map(function ($items, $type) {
            $totalQuantity = $items->sum('quantity_remaining');
            $totalValue = $items->sum(function ($item) {
                return $item->quantity_remaining * $item->productVariant->base_price;
            });
            
            return [
                'type' => $type,
                'total_quantity' => $totalQuantity,
                'total_value' => $totalValue,
                'ready_quantity' => $items->filter(fn($item) => $item->batch->isReadyToSell())->sum('quantity_remaining'),
                'maturing_quantity' => $items->filter(fn($item) => !$item->batch->isReadyToSell())->sum('quantity_remaining'),
            ];
        });

        return view('stock.index', compact(
            'readyToSell',
            'maturing',
            'readyValue',
            'maturingValue',
            'totalValue',
            'summaryByType'
        ));
    }
}
