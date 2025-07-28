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
        Schema::create('cheese_cutting_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_batch_item_id')->constrained('batch_items')->onDelete('cascade'); // The wheel being cut
            $table->foreignId('target_batch_item_id')->constrained('batch_items')->onDelete('cascade'); // The vacuum packs created
            $table->date('cut_date');
            $table->integer('vacuum_packs_created'); // Number of vacuum packs created from this wheel
            $table->decimal('total_weight_kg', 8, 3); // Total weight of vacuum packs created
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cheese_cutting_logs');
    }
};
