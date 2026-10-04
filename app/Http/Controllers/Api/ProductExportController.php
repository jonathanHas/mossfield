<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BatchItem;
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
        $query = ProductVariant::with('product')
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

        // Free stock per variant in ONE query — the same figure /products shows
        // (see BatchItem::freeStockByVariant): expired/closed/unready batches,
        // units still owed to orders and the maturing hold contribute nothing.
        // This is what the portal shows customers, so it must never exceed
        // what the allocator would actually hand out.
        $freeStock = BatchItem::freeStockByVariant($variants->pluck('id'));

        // Transform variants into external API format
        $data = $variants->map(function ($variant) use ($freeStock) {
            return [
                'office_product_id' => $variant->product->id,
                'office_variant_id' => $variant->id,
                'product_name' => $variant->product->name,
                'product_type' => $variant->product->type,
                'variant_name' => $variant->name,
                'full_name' => $variant->full_name,
                'size' => $variant->size,
                'unit' => $variant->unit,
                'weight_kg' => $variant->weight_kg ? (float) $variant->weight_kg : null,
                'base_price' => (float) $variant->base_price,
                'is_priced_by_weight' => (bool) $variant->is_priced_by_weight,
                'is_active' => $variant->product->is_active && $variant->is_active,
                'stock_available' => $freeStock[$variant->id] ?? 0,
                'updated_at' => $variant->updated_at->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }
}
