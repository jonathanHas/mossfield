<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Some products are only made by converting another (Mature, by releasing
 * matured Farmhouse wheels) and never have production batches of their own
 * (OPEN_QUESTIONS #5, F-10). The flag is set on Mature here, not in a
 * seeder, so production needs no extra deploy step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('conversion_only')->default(false)->after('is_active');
        });

        DB::table('products')->where('name', 'Mossfield Mature Cheese')->update(['conversion_only' => true]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('conversion_only');
        });
    }
};
