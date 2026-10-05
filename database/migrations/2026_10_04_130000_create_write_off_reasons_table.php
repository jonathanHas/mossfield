<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Why stock was written off — an extendable list (OPEN_QUESTIONS #1). The
 * defaults ship here, not in a seeder, so no deploy step can forget them;
 * insertOrIgnore keyed on `code` makes a re-run harmless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('write_off_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('label', 80);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $defaults = [
            'spoiled' => 'Spoiled / mouldy',
            'damaged' => 'Damaged / dropped',
            'sample' => 'Sample / tasting',
            'own_use' => 'Own use',
            'stocktake' => 'Stocktake adjustment',
            'expired' => 'Expired',
            'returned' => 'Customer return (not resaleable)',
        ];
        $order = 10;
        foreach ($defaults as $code => $label) {
            DB::table('write_off_reasons')->insertOrIgnore([
                'code' => $code, 'label' => $label, 'is_active' => true,
                'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $order += 10;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('write_off_reasons');
    }
};
