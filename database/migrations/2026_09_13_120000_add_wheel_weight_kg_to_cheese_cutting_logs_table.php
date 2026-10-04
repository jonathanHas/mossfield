<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The wheel's measured weight before cutting (docs/planImp/TESTING_AND_FIXES.md, finding 2,
 * pass 2a). total_weight_kg stays what it always was — the weight of the
 * sellable packs that came off the wheel — so the difference is the trim.
 *
 * Nullable, no backfill: rows from before this migration were never weighed
 * as a wheel, and writing the nominal variant weight into them would present
 * a guess as a measurement — the exact thing the finding is about. A null
 * reads as "not weighed"; the ceiling (packs ≤ wheel) applies to new cuts
 * through validation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheese_cutting_logs', function (Blueprint $table) {
            $table->decimal('wheel_weight_kg', 8, 3)->nullable()->after('vacuum_packs_created');
        });
    }

    public function down(): void
    {
        Schema::table('cheese_cutting_logs', function (Blueprint $table) {
            $table->dropColumn('wheel_weight_kg');
        });
    }
};
