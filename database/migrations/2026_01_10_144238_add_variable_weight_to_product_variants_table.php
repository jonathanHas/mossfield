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
        Schema::table('product_variants', function (Blueprint $table) {
            // Whether this variant has variable weight (requires weighing at fulfillment)
            $table->boolean('is_variable_weight')->default(false)->after('weight_kg');

            // Whether pricing is per kg (true) or per unit (false)
            $table->boolean('is_priced_by_weight')->default(false)->after('is_variable_weight');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['is_variable_weight', 'is_priced_by_weight']);
        });
    }
};
