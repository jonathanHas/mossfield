<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email, city and postal code become optional on the customer form (UX review
 * 2026-10-13, D20/D21) — a print-invoice customer may have no email, and the
 * office was typing "x" into postal code to get past the form. NULL, never ''.
 */
return new class extends Migration
{
    private const COLUMNS = ['email', 'city', 'postal_code'];

    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->string($column)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            DB::table('customers')->whereNull($column)->update([$column => '']);
        }

        Schema::table('customers', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->string($column)->nullable(false)->change();
            }
        });
    }
};
