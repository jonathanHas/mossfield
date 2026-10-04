<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A statement stores its ledger as JSON rather than a PDF blob: payments
        // can later be corrected or deleted, so re-deriving from live data is not
        // guaranteed to reproduce what was actually emailed. The PDF regenerates
        // from this snapshot.
        Schema::create('statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('opening_balance', 10, 2)->default(0);
            $table->decimal('closing_balance', 10, 2)->default(0);
            // {current, days_30, days_60, days_90_plus, credit}
            $table->json('aged')->nullable();
            $table->json('lines')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('generated_at');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('batch_id')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'period_end']);
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statements');
    }
};
