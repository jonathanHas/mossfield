<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Export product variants for the Mossorders online ordering system.
 *
 * The `office_product_id` and `office_variant_id` fields in the response are
 * intentionally exposed so Mossorders can round-trip product references back
 * to us when importing orders. This is safe ONLY because the endpoint is
 * token-gated to a single trusted peer. Do NOT copy this shape for any
 * endpoint reachable by less-trusted consumers.
 */
class ProductExportController extends Controller
{
    /**
     * Export product variants for external ordering system.
     *
     * Returns a flattened list of product variants with stock information.
     * Intended for syncing with external online ordering service (mossorders).
     *
     * Query Parameters:
     * - only_active (0/1, default 1): Filter to only active products/variants
     * - updated_since (ISO8601 datetime): Filter variants updated after this date
     */
    public function index(Request $request): JsonResponse
    {
        // Validate query parameters
        $validator = Validator::make($request->all(), [
            'only_active' => 'nullable|in:0,1',
            'updated_since' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Invalid query parameters',
                'messages' => $validator->errors(),
            ], 422);
        }

        // Parse filters
        $onlyActive = $request->input('only_active', '1') === '1';
        $updatedSince = $request->input('updated_since');

        // Build query
        $query = ProductVariant::with(['product', 'batchItems.batch'])
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->select('product_variants.*');

        // Filter by active status
        if ($onlyActive) {
            $query->where('product_variants.is_active', true)
                ->where('products.is_active', true);
        }

        // Filter by updated_since
        if ($updatedSince) {
            $query->where('product_variants.updated_at', '>', $updatedSince);
        }

        // Order by product type, then product name, then variant name
        $query->orderBy('products.type')
            ->orderBy('products.name')
            ->orderBy('product_variants.name');

        $variants = $query->get();

        // Transform variants into external API format
        $data = $variants->map(function ($variant) {
            return [
                'office_product_id' => $variant->product->id,
                'office_variant_id' => $variant->id,
                'product_name' => $variant->product->name,
                'product_type' => $variant->product->type,
                'variant_name' => $variant->name,
                'full_name' => $this->getFullName($variant),
                'size' => $variant->size,
                'unit' => $variant->unit,
                'weight_kg' => $variant->weight_kg ? (float) $variant->weight_kg : null,
                'base_price' => (float) $variant->base_price,
                'is_priced_by_weight' => (bool) $variant->is_priced_by_weight,
                'is_active' => $variant->product->is_active && $variant->is_active,
                'stock_available' => $this->calculateStockAvailable($variant),
                'updated_at' => $variant->updated_at->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Get the full display name for a variant.
     * Uses the existing accessor if available, otherwise computes it.
     */
    private function getFullName(ProductVariant $variant): string
    {
        // Use existing accessor if available
        if (method_exists($variant, 'getFullNameAttribute')) {
            return $variant->full_name;
        }

        // Fallback: compute it
        return $variant->product->name.' - '.$variant->name;
    }

    /**
     * Calculate available stock for a variant.
     *
     * Uses the existing total_stock accessor if available.
     * Otherwise, sums batch_items.quantity_remaining for ready batches only.
     */
    private function calculateStockAvailable(ProductVariant $variant): int
    {
        // Use existing accessor if available
        if (method_exists($variant, 'getTotalStockAttribute')) {
            // Filter to only ready batches
            return $variant->batchItems
                ->filter(function ($batchItem) {
                    return $this->isBatchReady($batchItem);
                })
                ->sum('quantity_remaining');
        }

        // Fallback: compute it
        return $variant->batchItems()
            ->whereHas('batch', function ($query) {
                $query->where('status', 'active')
                    ->where(function ($q) {
                        $q->whereNull('ready_date')
                            ->orWhere('ready_date', '<=', now());
                    });
            })
            ->sum('quantity_remaining');
    }

    /**
     * Check if a batch item is ready to sell.
     */
    private function isBatchReady($batchItem): bool
    {
        $batch = $batchItem->batch;

        // Check batch status
        if ($batch->status !== 'active') {
            return false;
        }

        // Check if ready date has passed (for cheese maturation)
        if ($batch->ready_date && $batch->ready_date > now()) {
            return false;
        }

        return true;
    }
}
