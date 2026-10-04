<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One token per rendered payment form. A double-clicked or re-posted form
 * carries the same token, so the unique index turns the repeat into a
 * redirect instead of a second payment (E2E finding F-39).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('form_token', 36)->nullable()->after('notes');
            $table->unique('form_token', 'payments_form_token_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_form_token_unique');
            $table->dropColumn('form_token');
        });
    }
};
