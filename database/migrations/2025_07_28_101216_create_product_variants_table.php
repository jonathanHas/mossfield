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
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('name'); // e.g., "1L Bottle", "2L Bottle", "250g Tub", "Wheel", "Vacuum Pack"
            $table->string('size'); // e.g., "1L", "2L", "250g", "500g", "wheel", "pack"
            $table->string('unit'); // e.g., "bottle", "tub", "wheel", "pack"
            $table->decimal('weight_kg', 8, 3)->nullable(); // For tracking weight
            $table->decimal('base_price', 8, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
