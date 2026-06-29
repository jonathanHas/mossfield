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
        Schema::table('batches', function (Blueprint $table) {
            // Traceability back-link: a Mature batch points to the Farmhouse
            // batch its wheels were converted from. nullOnDelete so deleting a
            // source batch never deletes its mature offspring — it just un-links.
            $table->foreignId('source_batch_id')->nullable()->after('product_id')
                ->constrained('batches')->nullOnDelete();

            // One mature batch per (mature product, source batch) — the key the
            // conversion get-or-create relies on; the unique index also hardens
            // against concurrent conversions creating duplicate mature batches.
            $table->unique(['product_id', 'source_batch_id'], 'batches_product_source_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->dropUnique('batches_product_source_unique');
            $table->dropConstrainedForeignId('source_batch_id');
        });
    }
};
