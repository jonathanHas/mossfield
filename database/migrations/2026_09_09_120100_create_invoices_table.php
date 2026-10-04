<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            // restrictOnDelete: an issued invoice is a financial record and must
            // outlive any attempt to delete the customer.
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            // per_order | consolidated (App\Enums\InvoiceType). String, not a DB
            // enum — altering MySQL enums later is painful and SQLite ignores them.
            $table->string('type')->default('per_order');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            // issued | void (App\Enums\InvoiceStatus)
            $table->string('status')->default('issued');

            $table->date('issued_at');
            $table->date('due_date');
            // Snapshot of customer.payment_terms at issue time — the due date is
            // already computed, but the label prints on the PDF.
            $table->string('payment_terms')->nullable();

            // Bill-to snapshot: the invoice must keep printing what it printed
            // even if the customer record is later edited.
            $table->string('bill_to_name');
            $table->text('bill_to_address')->nullable();
            $table->string('bill_to_email')->nullable();
            $table->string('bill_to_vat_number')->nullable();

            // Cached sums over invoice_lines, plus the running settlement state.
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('delivery_charge_net', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->decimal('amount_credited', 10, 2)->default(0);
            $table->decimal('balance', 10, 2)->default(0);

            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->text('notes')->nullable();

            // Cache of the latest successful send; the audit trail is document_emails.
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('last_reminded_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['status', 'due_date']);
            $table->index(['status', 'emailed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
