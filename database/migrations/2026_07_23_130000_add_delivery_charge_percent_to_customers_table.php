<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Negotiated delivery charge as a % of order value. When set, it
            // overrides the fixed run charge. Null = no percentage. Plain
            // column, safe to query.
            $table->decimal('delivery_charge_percent', 5, 2)->nullable()->after('apply_delivery_charge');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('delivery_charge_percent');
        });
    }
};
