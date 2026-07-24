<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Per-order snapshot of the customer's delivery-charge rate. When
            // set, Order::calculateTotals() recomputes delivery_charge as
            // subtotal × this % (VAT-inclusive) on every recalc — a live
            // percentage of the order rather than a fixed snapshot.
            $table->decimal('delivery_charge_percent', 5, 2)->nullable()->after('delivery_charge');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivery_charge_percent');
        });
    }
};
