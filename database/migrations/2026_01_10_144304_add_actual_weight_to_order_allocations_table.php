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
        Schema::table('order_allocations', function (Blueprint $table) {
            // Actual total weight of fulfilled items (null for fixed-weight items)
            $table->decimal('actual_weight_kg', 10, 3)->nullable()->after('quantity_fulfilled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_allocations', function (Blueprint $table) {
            $table->dropColumn('actual_weight_kg');
        });
    }
};
