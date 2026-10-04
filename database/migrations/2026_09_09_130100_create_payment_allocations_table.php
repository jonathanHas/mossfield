<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete: an invoice with money against it can't vanish.
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            // One row per (payment, invoice) — a second allocation to the same
            // invoice widens the existing row instead.
            $table->unique(['payment_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
