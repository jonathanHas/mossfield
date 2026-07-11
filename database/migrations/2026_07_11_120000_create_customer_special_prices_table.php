<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-customer alternative unit price for a specific product variant.
     *
     * Some customers pay a negotiated rate instead of the variant's standard
     * base_price. The stored price is in the SAME units as base_price — €/unit
     * for fixed-price variants, or €/kg for weight-priced ones — so it drops
     * straight into order_items.unit_price at line creation and the existing
     * weight-aware line/total math stays correct with no further changes.
     *
     * One override per (customer, variant). Applies only to office-entered
     * orders; Mossorders imports keep the unit_price from their payload.
     */
    public function up(): void
    {
        Schema::create('customer_special_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 8, 2); // same units as product_variants.base_price
            $table->timestamps();

            $table->unique(['customer_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_special_prices');
    }
};
