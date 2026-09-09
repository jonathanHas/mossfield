<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Marks a line deliberately closed with less supplied than ordered
            // ("short supply") — the outstanding units will never be sent, so
            // the line stops blocking the order's preparing → ready promotion
            // and drops out of the invoice.
            //
            // The shortfall itself is NOT stored: it derives from
            // max(0, quantity_ordered - quantity_fulfilled) via
            // OrderItem::quantity_short, so it can never drift from
            // quantity_fulfilled and self-corrects if stock later arrives and
            // the line is picked after all.
            $table->timestamp('short_closed_at')->nullable()->after('fulfilled_total');
            $table->string('short_reason')->nullable()->after('short_closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['short_closed_at', 'short_reason']);
        });
    }
};
