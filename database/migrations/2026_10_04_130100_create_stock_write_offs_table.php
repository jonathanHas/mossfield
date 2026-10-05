<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A write-off: N units of a batch item that were lost (spoiled, dropped,
 * sampled, expired …). The units leave via batch_items.quantity_remaining;
 * this row is the record. Reversal stamps reversed_at/by — rows are never
 * deleted (the cascade only follows a batch delete, which
 * Batch::deletionBlocker() refuses while write-offs exist).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_write_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('write_off_reason_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('weight_kg', 8, 3)->nullable();
            $table->string('note', 255)->nullable();
            $table->date('written_off_on');
            $table->foreignId('written_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['batch_item_id', 'reversed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_write_offs');
    }
};
