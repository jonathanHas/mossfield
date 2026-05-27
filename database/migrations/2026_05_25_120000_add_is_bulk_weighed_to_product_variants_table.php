<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            // For variable-weight items: true = enter one total weight at
            // fulfilment (e.g. vacuum packs); false = per-unit weights (wheels).
            $table->boolean('is_bulk_weighed')->default(false)->after('is_priced_by_weight');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('is_bulk_weighed');
        });
    }
};
