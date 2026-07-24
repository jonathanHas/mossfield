<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Snapshot of the run's delivery charge at order creation, stored
            // VAT-inclusive (gross) and editable per order. Sits outside
            // subtotal; Order::calculateTotals() backs out the 23% VAT into
            // tax_amount so net + VAT == this gross figure.
            $table->decimal('delivery_charge', 8, 2)->default(0)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivery_charge');
        });
    }
};
