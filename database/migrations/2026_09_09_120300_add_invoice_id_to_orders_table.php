<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // The order's LIVE invoice. Voiding an invoice nulls this, which
            // returns the order to the uninvoiced queue; invoice_lines.order_id
            // keeps the history of what was billed on the voided document.
            $table->foreignId('invoice_id')->nullable()->after('mossorders_order_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });
    }
};
