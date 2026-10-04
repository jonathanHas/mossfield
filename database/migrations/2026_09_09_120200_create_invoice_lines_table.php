<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A frozen copy of what was billed. Order lines keep moving after issue
        // (picks, weight edits, calculateTotals), so nothing here may be derived
        // from the live order at render time.
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            // Kept as history across voids/re-issues; nullOnDelete so deleting an
            // order never destroys the invoice it was billed on.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();

            // product | delivery | adjustment
            $table->string('line_type')->default('product');
            $table->string('description');
            // Secondary note under the description: the kg figure, or the
            // short-supply sentence.
            $table->string('detail')->nullable();
            $table->json('batch_codes')->nullable();

            $table->decimal('quantity', 10, 3)->default(0);
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->string('unit_price_label')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            // Products are 0% VAT; only the delivery line carries 23%.
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 10, 2)->default(0);

            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
