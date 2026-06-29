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
        Schema::create('cheese_conversion_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_batch_item_id')->constrained('batch_items')->onDelete('cascade'); // The farmhouse wheel(s) being converted
            $table->foreignId('target_batch_item_id')->constrained('batch_items')->onDelete('cascade'); // The mature wheel(s) created
            $table->date('conversion_date');
            $table->integer('wheels_converted'); // Number of wheels moved in this conversion
            $table->decimal('total_weight_kg', 8, 3); // Total weight of the converted mature wheels
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cheese_conversion_logs');
    }
};
