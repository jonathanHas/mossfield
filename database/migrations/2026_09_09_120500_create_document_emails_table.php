<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The communications log: one row per outbound document, whether sent
        // inline or through the queue. This is what answers "did we send it?".
        Schema::create('document_emails', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable');
            // invoice | credit_note | statement | reminder | docket
            $table->string('kind');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient');
            $table->string('subject');
            // queued | sent | failed
            $table->string('status')->default('queued');
            // Groups a batch send (e.g. month-end statements) under one id.
            $table->uuid('batch_id')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('batch_id');
            $table->index(['kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_emails');
    }
};
