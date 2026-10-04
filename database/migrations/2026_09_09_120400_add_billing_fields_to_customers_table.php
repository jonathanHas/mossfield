<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Plaintext like `email`: an address we send TO, not PII we search.
            // (phone/address/city/postal_code/notes stay encrypted.)
            $table->string('billing_email')->nullable()->after('email');
            $table->string('vat_number')->nullable()->after('billing_email');
            // email | print | none — "print"/"none" keep a customer's invoices
            // out of the "not yet emailed" queue.
            $table->string('invoice_delivery')->default('email')->after('payment_terms');
            // per_order | weekly | monthly
            $table->string('invoicing_mode')->default('per_order')->after('invoice_delivery');
            $table->boolean('send_statements')->default(true)->after('invoicing_mode');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'billing_email', 'vat_number', 'invoice_delivery',
                'invoicing_mode', 'send_statements',
            ]);
        });
    }
};
