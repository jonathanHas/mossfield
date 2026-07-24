<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_runs', function (Blueprint $table) {
            // VAT-inclusive (gross) € charge applied to flagged customers'
            // orders on this run; the 23% VAT is broken out at invoice time.
            $table->decimal('delivery_charge', 8, 2)->default(0)->after('capacity_note');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_runs', function (Blueprint $table) {
            $table->dropColumn('delivery_charge');
        });
    }
};
