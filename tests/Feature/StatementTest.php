<?php

namespace Tests\Feature;

use App\Models\Statement;
use App\Models\User;
use App\Services\CreditNoteService;
use App\Services\PaymentService;
use App\Services\StatementService;
use Illuminate\Support\Facades\Mail;

/**
 * Customer statements: the running account over a period, plus the aged
 * breakdown of what's still open.
 */
class StatementTest extends InvoicingTestCase
{
    private function ledger($from = null, $to = null): array
    {
        return app(StatementService::class)->ledger(
            $this->shop,
            $from ?? today()->startOfMonth(),
            $to ?? today()->endOfMonth(),
        );
    }

    public function test_the_ledger_runs_a_balance_through_the_period(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10)); // €15.00
        app(PaymentService::class)->record($this->shop, [
            'amount' => 5.00, 'received_at' => today()->toDateString(), 'method' => 'cash',
        ], [$invoice->id => 5.00]);

        $ledger = $this->ledger();

        $this->assertSame(0.0, $ledger['opening_balance']);
        $this->assertCount(2, $ledger['lines']);
        $this->assertSame(15.0, $ledger['lines'][0]['debit']);
        $this->assertSame(5.0, $ledger['lines'][1]['credit']);
        $this->assertSame(10.0, $ledger['closing_balance']);
    }

    public function test_activity_before_the_period_becomes_the_opening_balance(): void
    {
        $old = $this->issue($this->deliveredOrder(10));
        $old->update(['issued_at' => today()->subMonths(2)]);

        $ledger = $this->ledger();

        $this->assertSame(15.0, $ledger['opening_balance']);
        $this->assertEmpty($ledger['lines']);
        $this->assertSame(15.0, $ledger['closing_balance']);
    }

    public function test_credit_notes_appear_as_credits(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10));
        app(CreditNoteService::class)->issue($invoice, [
            ['description' => 'Returned', 'quantity' => 1, 'amount' => 4.00, 'vat_rate' => 0],
        ], 'Damaged');

        $ledger = $this->ledger();

        $credit = collect($ledger['lines'])->firstWhere('type', 'credit_note');

        $this->assertSame(4.0, $credit['credit']);
        $this->assertSame(11.0, $ledger['closing_balance']);
    }

    public function test_open_invoices_are_bucketed_by_how_late_they_are(): void
    {
        $current = $this->issue($this->deliveredOrder(10));   // €15, due in 30 days
        $late = $this->issue($this->deliveredOrder(20));      // €30
        $late->update(['due_date' => today()->subDays(45)]);
        $ancient = $this->issue($this->deliveredOrder(4));    // €6
        $ancient->update(['due_date' => today()->subDays(120)]);

        $aged = app(StatementService::class)->agedBalances($this->shop);

        $this->assertSame(15.0, $aged['current']);
        $this->assertSame(0.0, $aged['days_1_30']);
        $this->assertSame(30.0, $aged['days_31_60']);
        $this->assertSame(6.0, $aged['days_90_plus']);
        $this->assertSame(51.0, $aged['total']);
    }

    public function test_credit_on_account_shows_separately_in_the_aged_view(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10)); // €15
        app(PaymentService::class)->record($this->shop, [
            'amount' => 40.00, 'received_at' => today()->toDateString(), 'method' => 'bank_transfer',
        ], [$invoice->id => 15.00]);

        $aged = app(StatementService::class)->agedBalances($this->shop);

        $this->assertSame(25.0, $aged['credit']);
        $this->assertSame(-25.0, $aged['total']);
    }

    public function test_generating_stores_the_ledger_as_a_snapshot(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10));

        $statement = app(StatementService::class)->generate(
            $this->shop, today()->startOfMonth(), today()->endOfMonth()
        );

        $this->assertSame(15.0, (float) $statement->closing_balance);
        $this->assertCount(1, $statement->lines);
        $this->assertSame($invoice->number, $statement->lines[0]['reference']);

        // The snapshot is what was sent, so later movement must not rewrite it.
        app(PaymentService::class)->record($this->shop, [
            'amount' => 15.00, 'received_at' => today()->toDateString(), 'method' => 'cash',
        ], [$invoice->id => 15.00]);

        $this->assertSame(15.0, (float) $statement->fresh()->closing_balance);
    }

    public function test_office_previews_and_generates_from_the_customer_page(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10));
        $office = User::factory()->create();

        $this->actingAs($office)
            ->get(route('invoicing.statements.preview', [
                'customer' => $this->shop,
                'from' => today()->startOfMonth()->toDateString(),
                'to' => today()->endOfMonth()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee($invoice->number);

        $this->actingAs($office)
            ->post(route('invoicing.statements.store', $this->shop), [
                'from' => today()->startOfMonth()->toDateString(),
                'to' => today()->endOfMonth()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, Statement::count());
    }

    public function test_generating_and_sending_emails_and_logs_it(): void
    {
        Mail::fake();
        $this->issue($this->deliveredOrder(10));
        $office = User::factory()->create();

        $this->actingAs($office)
            ->post(route('invoicing.statements.store', $this->shop), [
                'from' => today()->startOfMonth()->toDateString(),
                'to' => today()->endOfMonth()->toDateString(),
                'send' => 1,
            ])
            ->assertSessionHas('success');

        $statement = Statement::sole();

        $this->assertNotNull($statement->emailed_at);
        $this->assertDatabaseHas('document_emails', [
            'documentable_type' => Statement::class,
            'documentable_id' => $statement->id,
            'kind' => 'statement',
            'recipient' => 'k@example.ie',
            'status' => 'sent',
        ]);
        Mail::assertSent(\App\Mail\DocumentMail::class);
    }

    public function test_the_pdf_renders_from_the_stored_snapshot(): void
    {
        $this->issue($this->deliveredOrder(10));
        $statement = app(StatementService::class)->generate(
            $this->shop, today()->startOfMonth(), today()->endOfMonth()
        );

        $pdf = app(StatementService::class)->pdf($statement);

        $this->assertStringStartsWith('%PDF', $pdf->output());
    }
}
