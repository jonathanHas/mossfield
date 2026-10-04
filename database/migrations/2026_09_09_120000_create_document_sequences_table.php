<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gap-free-ish document numbering. DocumentSequence::next() reads the
        // row with lockForUpdate() inside the issuing transaction, so a
        // rolled-back issue rolls the counter back with it. The unique index
        // on invoices.number / credit_notes.number is the backstop.
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->unsignedInteger('next_value')->default(1);
            $table->timestamps();
        });

        $now = now();
        DB::table('document_sequences')->insert([
            ['key' => 'invoice', 'next_value' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'credit_note', 'next_value' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
