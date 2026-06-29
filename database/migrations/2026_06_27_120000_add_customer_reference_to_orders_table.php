<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional customer-supplied reference / purchase-order number, distinct from the
     * auto-generated order_number. Settable on create and editable later (office order
     * forms + Chilled Runs inline editor). Tucked behind a small reveal in the UI.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_reference')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('customer_reference');
        });
    }
};
