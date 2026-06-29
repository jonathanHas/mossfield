<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When set, this customer always needs a reference on their orders, so the
     * "Customer ref" field auto-expands for them on the order forms and the
     * Chilled Runs row (otherwise it stays hidden behind the small reveal button).
     * Plain boolean — safe to query, unlike the encrypted PII columns.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('requires_reference')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('requires_reference');
        });
    }
};
