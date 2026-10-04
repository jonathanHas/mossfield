<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InvoicingException;
use App\Services\PaymentService;

/**
 * Voiding. A void invoice hands its orders back to the uninvoiced queue but
 * keeps its own record — including the lines it billed — so the number series
 * stays explicable.
 */
class InvoiceVoidTest extends InvoicingTestCase
{
    public function test_voiding_frees_the_order_and_keeps_the_document(): void
    {
        $order = $this->deliveredOrder();
        $invoice = $this->issue($order);

        app(\App\Services\InvoiceService::class)->void($invoice, 'Wrong customer');

        $invoice->refresh();
        $order->refresh();

        $this->assertTrue($invoice->isVoid());
        $this->assertSame('Wrong customer', $invoice->void_reason);
        $this->assertSame('0.00', $invoice->balance, 'a void invoice owes nothing');
        $this->assertNull($order->invoice_id, 'the order goes back in the queue');
        $this->assertSame('pending', $order->payment_status);
        $this->assertCount(1, $invoice->lines, 'the voided document keeps what it billed');
    }

    public function test_the_freed_order_can_be_billed_again_on_a_new_number(): void
    {
        $order = $this->deliveredOrder();
        $first = $this->issue($order);

        app(\App\Services\InvoiceService::class)->void($first, 'Priced before the weights were corrected');

        $second = $this->issue($order->fresh());

        $this->assertSame('INV-000002', $second->number);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_an_invoice_with_a_payment_cannot_be_voided(): void
    {
        $order = $this->deliveredOrder();
        $invoice = $this->issue($order);

        app(PaymentService::class)->record($this->shop, [
            'amount' => 5.00, 'received_at' => today()->toDateString(), 'method' => 'bank_transfer',
        ], [$invoice->id => 5.00]);

        $this->assertFalse($invoice->fresh()->isVoidable());

        $this->expectException(InvoicingException::class);
        app(\App\Services\InvoiceService::class)->void($invoice->fresh(), 'nope');
    }

    public function test_an_invoice_with_a_credit_note_cannot_be_voided(): void
    {
        $invoice = $this->issue($this->deliveredOrder());

        app(\App\Services\CreditNoteService::class)->issue($invoice, [
            ['description' => 'Returned', 'quantity' => 1, 'amount' => 1.50, 'vat_rate' => 0],
        ], 'Damaged');

        $this->assertFalse($invoice->fresh()->isVoidable());
    }

    public function test_void_requires_a_reason_and_flashes_success(): void
    {
        $invoice = $this->issue($this->deliveredOrder());
        $office = User::factory()->create();

        $this->actingAs($office)
            ->post(route('invoicing.invoices.void', $invoice), [])
            ->assertSessionHasErrors('void_reason');

        $this->actingAs($office)
            ->post(route('invoicing.invoices.void', $invoice), ['void_reason' => 'Duplicate'])
            ->assertRedirect(route('invoicing.invoices.show', $invoice))
            ->assertSessionHas('success');

        $this->assertTrue($invoice->fresh()->isVoid());
    }

    public function test_voided_invoices_are_hidden_from_the_working_list(): void
    {
        $invoice = $this->issue($this->deliveredOrder());
        app(\App\Services\InvoiceService::class)->void($invoice, 'Duplicate');

        $office = User::factory()->create();

        $this->actingAs($office)->get(route('invoicing.invoices.index'))
            ->assertOk()->assertDontSee($invoice->number);

        $this->actingAs($office)->get(route('invoicing.invoices.index', ['status' => 'void']))
            ->assertOk()->assertSee($invoice->number);
    }
}
