<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One token per rendered write-off / cut form, as on payments. Back + submit
 * re-posts the same token, so the repeat is refused instead of writing off or
 * cutting a second time (UX review 2026-10-28, finding 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_write_offs', function (Blueprint $table) {
            $table->string('form_token', 36)->nullable()->after('note');
            $table->unique('form_token', 'stock_write_offs_form_token_unique');
        });

        Schema::table('cheese_cutting_logs', function (Blueprint $table) {
            $table->string('form_token', 36)->nullable()->after('notes');
            $table->unique('form_token', 'cheese_cutting_logs_form_token_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stock_write_offs', function (Blueprint $table) {
            $table->dropUnique('stock_write_offs_form_token_unique');
            $table->dropColumn('form_token');
        });

        Schema::table('cheese_cutting_logs', function (Blueprint $table) {
            $table->dropUnique('cheese_cutting_logs_form_token_unique');
            $table->dropColumn('form_token');
        });
    }
};
