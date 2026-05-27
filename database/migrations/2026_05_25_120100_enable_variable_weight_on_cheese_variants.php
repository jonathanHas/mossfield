<?php

use App\Models\ProductVariant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Cheese is sold by variable weight but the existing variants were never
     * flagged, so the weight-entry UI never appeared at fulfilment. Enable
     * weight CAPTURE on all cheese variants (per-unit for wheels, single total
     * for packs). Pricing (is_priced_by_weight + €/kg base price) is left to the
     * operator to set deliberately per variant — this migration never touches
     * base_price or is_priced_by_weight.
     */
    public function up(): void
    {
        ProductVariant::whereHas('product', fn ($q) => $q->where('type', 'cheese'))
            ->get()
            ->each(function (ProductVariant $variant) {
                $isWheel = str_contains(strtolower($variant->name), 'wheel');
                $variant->forceFill([
                    'is_variable_weight' => true,
                    'is_bulk_weighed' => ! $isWheel, // packs weigh in bulk, wheels per-unit
                ])->save();
            });
    }

    public function down(): void
    {
        ProductVariant::whereHas('product', fn ($q) => $q->where('type', 'cheese'))
            ->get()
            ->each(function (ProductVariant $variant) {
                $variant->forceFill([
                    'is_variable_weight' => false,
                    'is_bulk_weighed' => false,
                ])->save();
            });
    }
};
