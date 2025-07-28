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
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('batch_code')->unique(); // Auto-generated: M/Y/G + ddmmyy
            $table->date('production_date');
            $table->date('expiry_date')->nullable();
            $table->date('ready_date')->nullable(); // For cheese maturation
            $table->decimal('total_quantity_kg', 10, 3); // Total quantity produced in kg/litres
            $table->text('notes')->nullable();
            $table->enum('status', ['active', 'sold_out', 'expired'])->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
