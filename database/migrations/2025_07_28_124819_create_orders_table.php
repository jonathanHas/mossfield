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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique(); // Auto-generated: ORD-YYYYMMDD-XXX
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->date('order_date');
            $table->date('delivery_date')->nullable();
            $table->enum('status', ['pending', 'confirmed', 'preparing', 'ready', 'dispatched', 'delivered', 'cancelled'])->default('pending');
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->enum('payment_status', ['pending', 'paid', 'partial', 'overdue'])->default('pending');
            $table->text('delivery_address')->nullable(); // Override customer default address
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index(['order_date', 'status']);
            $table->index('delivery_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
