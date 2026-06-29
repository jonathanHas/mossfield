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
        Schema::table('batch_items', function (Blueprint $table) {
            // Wheels set aside ("maturing hold"): still physical farmhouse stock
            // (quantity_remaining unchanged) but excluded from order allocation.
            // Reversible; consumed when released into the Mature product.
            $table->integer('quantity_maturing')->default(0)->after('quantity_remaining');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('batch_items', function (Blueprint $table) {
            $table->dropColumn('quantity_maturing');
        });
    }
};
