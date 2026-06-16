<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Assign customers to a chilled delivery run with a stop position.
     * Plain (unencrypted) columns — safe to query/order by, unlike the
     * encrypted PII columns on this table.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('delivery_run_id')
                ->nullable()
                ->after('mossorders_user_id')
                ->constrained('delivery_runs')
                ->nullOnDelete(); // deleting a run un-assigns its customers
            $table->unsignedSmallInteger('run_position')->nullable()->after('delivery_run_id');
            $table->index(['delivery_run_id', 'run_position']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['delivery_run_id', 'run_position']);
            $table->dropConstrainedForeignId('delivery_run_id');
            $table->dropColumn('run_position');
        });
    }
};
