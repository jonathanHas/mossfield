<?php

namespace Tests\Feature;

use App\Models\CreditNote;
use App\Models\User;
use App\Services\CreditNoteService;
use App\Services\InvoicingException;
use App\Services\PaymentService;

/**
 * Credit notes: returns, breakages and price corrections against an issued
 * invoice. They reduce the invoice directly rather than being allocated.
 */
class CreditNoteTest extends InvoicingTestCase
{
    private function credit($invoice, float $amount, float $vatRate = 0, string $reason = 'Damaged in transit'): CreditNote
    {
        return app(CreditNoteService::class)->issue($invoice, [
            ['description' => 'Returned goods', 'quantity' => 1, 'amount' => $amount, 'vat_rate' => $vatRate],
        ], $reason);
    }

    public function test_a_credit_note_reduces_what_the_invoice_owes(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10)); // €15.00

        $note = $this->credit($invoice, 4.50);

        $invoice->refresh();

        $this->assertSame('CN-000001', $note->number);
        $this->assertSame('4.50', $invoice->amount_credited);
        $this->assertSame('10.50', $invoice->balance);
        $this->assertSame(10.5, $this->shop->fresh()->outstanding_balance);
    }

    public function test_credit_cannot_exceed_what_the_invoice_is_worth(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10)); // €15.00

        $this->expectException(InvoicingException::class);
        $this->credit($invoice, 20.00);
    }

    public function test_successive_credits_cannot_overshoot_together(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10)); // €15.00
        $this->credit($invoice, 10.00);

        $this->expectException(InvoicingException::class);
        $this->credit($invoice->fresh(), 10.00);
    }

    /**
     * Crediting a fully-paid invoice can't leave it "overpaid": the surplus is
     * released back to the payment as credit the customer can spend.
     */
    public function test_crediting_a_paid_invoice_returns_the_surplus_as_credit(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10)); // €15.00
        $payment = app(PaymentService::class)->record($this->shop, [
            'amount' => 15.00, 'received_at' => today()->toDateString(), 'method' => 'bank_transfer',
        ], [$invoice->id => 15.00]);

        $this->assertSame('0.00', $invoice->fresh()->balance);

        $this->credit($invoice->fresh(), 5.00);

        $invoice->refresh();

        $this->assertSame('5.00', $invoice->amount_credited);
        $this->assertSame('10.00', $invoice->amount_paid, 'the allocation shrank to what is still owed');
        $this->assertSame('0.00', $invoice->balance);
        $this->assertSame(5.0, $payment->fresh()->unallocated_amount, 'the €5 is spendable again');
    }

    public function test_vat_is_carried_on_the_credited_line(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10, deliveryCharge: 4.50));

        $note = $this->credit($invoice, 3.66, 23.0, 'Delivery not made');

        $this->assertSame('3.66', $note->subtotal);
        $this->assertSame('0.84', $note->tax_amount);
        $this->assertSame('4.50', $note->total);
    }

    public function test_voiding_a_credit_note_puts_the_balance_back(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10));
        $note = $this->credit($invoice, 5.00);

        $this->assertSame('10.00', $invoice->fresh()->balance);

        app(CreditNoteService::class)->void($note, 'Raised in error');

        $this->assertSame('0.00', $invoice->fresh()->amount_credited);
        $this->assertSame('15.00', $invoice->fresh()->balance);
        $this->assertTrue($note->fresh()->isVoid());
    }

    public function test_a_credit_note_cannot_be_raised_against_a_void_invoice(): void
    {
        $invoice = $this->issue($this->deliveredOrder());
        app(\App\Services\InvoiceService::class)->void($invoice, 'Duplicate');

        $this->expectException(InvoicingException::class);
        $this->credit($invoice->fresh(), 1.00);
    }

    public function test_office_raises_a_credit_note_through_the_form(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10));
        $office = User::factory()->create();

        $this->actingAs($office)
            ->get(route('invoicing.credit-notes.create', $invoice))
            ->assertOk()
            ->assertSee($invoice->number);

        $this->actingAs($office)
            ->post(route('invoicing.credit-notes.store', $invoice), [
                'reason' => 'Two bottles broken on the van',
                'lines' => [
                    ['invoice_line_id' => $invoice->lines->first()->id, 'description' => '1L Bottle', 'quantity' => 2, 'amount' => '3.00', 'vat_rate' => 0],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $note = CreditNote::sole();

        $this->assertSame('3.00', $note->total);
        $this->assertSame($office->id, $note->created_by);
        $this->assertSame('12.00', $invoice->fresh()->balance);
    }

    public function test_zero_lines_are_dropped_and_an_empty_note_is_refused(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10));

        $this->expectException(InvoicingException::class);
        app(CreditNoteService::class)->issue($invoice, [
            ['description' => 'Nothing', 'quantity' => 0, 'amount' => 0, 'vat_rate' => 0],
        ], 'Nothing to credit');
    }

    public function test_the_pdf_renders_from_the_credit_note(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10));
        $note = $this->credit($invoice, 5.00);

        $pdf = app(CreditNoteService::class)->pdf($note);

        $this->assertStringStartsWith('%PDF', $pdf->output());
    }
}
