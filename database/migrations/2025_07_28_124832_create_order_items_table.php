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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_variant_id')->constrained()->onDelete('cascade');
            $table->integer('quantity_ordered');
            $table->integer('quantity_allocated')->default(0); // From stock
            $table->integer('quantity_fulfilled')->default(0); // Actually delivered
            $table->decimal('unit_price', 8, 2); // Price at time of order
            $table->decimal('line_total', 10, 2); // quantity_ordered * unit_price
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index(['order_id', 'product_variant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
