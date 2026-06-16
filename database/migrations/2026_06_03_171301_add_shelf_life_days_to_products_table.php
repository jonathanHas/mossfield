<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('shelf_life_days')->nullable()->after('maturation_days');
        });

        // Preserve existing behaviour: milk batches were auto-expired at
        // production date + 10 days (hardcoded in the batch create form JS).
        DB::table('products')
            ->where('type', 'milk')
            ->update(['shelf_life_days' => 10]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('shelf_life_days');
        });
    }
};
