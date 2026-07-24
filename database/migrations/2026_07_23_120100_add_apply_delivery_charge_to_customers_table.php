<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Whether new orders for this customer get their run's delivery
            // charge. Plain boolean (NOT EncryptedNullable) — safe to query,
            // mirrors requires_reference.
            $table->boolean('apply_delivery_charge')->default(false)->after('requires_reference');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('apply_delivery_charge');
        });
    }
};
