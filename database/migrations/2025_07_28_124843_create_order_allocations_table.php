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
        Schema::create('order_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->onDelete('cascade');
            $table->foreignId('batch_item_id')->constrained()->onDelete('cascade');
            $table->integer('quantity_allocated');
            $table->boolean('is_fulfilled')->default(false);
            $table->timestamp('allocated_at');
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            
            // Ensure no double allocation of the same batch to the same order item
            $table->unique(['order_item_id', 'batch_item_id']);
            $table->index('allocated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_allocations');
    }
};
