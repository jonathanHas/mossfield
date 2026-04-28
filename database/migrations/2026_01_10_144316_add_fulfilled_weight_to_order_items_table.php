<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Actual total weight fulfilled (sum of allocation weights)
            $table->decimal('weight_fulfilled_kg', 10, 3)->nullable()->after('quantity_fulfilled');

            // Actual invoiced amount (may differ from line_total for variable-weight items)
            $table->decimal('fulfilled_total', 10, 2)->nullable()->after('line_total');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['weight_fulfilled_kg', 'fulfilled_total']);
        });
    }
};
