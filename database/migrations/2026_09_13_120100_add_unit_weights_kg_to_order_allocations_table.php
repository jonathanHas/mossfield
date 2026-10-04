<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-unit weights recorded at fulfilment (finding 5): an ordered list of
 * kg figures, one per unit picked, appended across partial picks. Null on
 * bulk-weighed lines (one total, no per-unit figures) and on rows fulfilled
 * before this migration. actual_weight_kg is derived from it server-side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_allocations', function (Blueprint $table) {
            $table->json('unit_weights_kg')->nullable()->after('actual_weight_kg');
        });
    }

    public function down(): void
    {
        Schema::table('order_allocations', function (Blueprint $table) {
            $table->dropColumn('unit_weights_kg');
        });
    }
};
