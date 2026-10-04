<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use App\Services\InvoicingException;
use App\Services\PaymentService;

/**
 * Recording money and applying it to invoices. A payment belongs to the
 * customer, so a single lump sum can settle several invoices and any remainder
 * stays as credit on account.
 */
class PaymentAllocationTest extends InvoicingTestCase
{
    private function pay(float $amount, array $allocations = []): Payment
    {
        return app(PaymentService::class)->record($this->shop, [
            'amount' => $amount,
            'received_at' => today()->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'TRF-1',
        ], $allocations);
    }

    public function test_a_lump_sum_settles_several_invoices(): void
    {
        $first = $this->issue($this->deliveredOrder(10));   // €15.00
        $second = $this->issue($this->deliveredOrder(20));  // €30.00

        $this->pay(45.00, [$first->id => 15.00, $second->id => 30.00]);

        $this->assertSame('0.00', $first->fresh()->balance);
        $this->assertSame('0.00', $second->fresh()->balance);
        $this->assertSame('paid', $first->fresh()->payment_state);
        $this->assertSame(0.0, $this->shop->fresh()->outstanding_balance);
    }

    public function test_a_part_payment_leaves_the_rest_outstanding(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10)); // €15.00

        $this->pay(5.00, [$invoice->id => 5.00]);

        $invoice->refresh();

        $this->assertSame('5.00', $invoice->amount_paid);
        $this->assertSame('10.00', $invoice->balance);
        $this->assertSame('partial', $invoice->payment_state);
        $this->assertSame('partial', $invoice->orders()->sole()->payment_status);
    }

    public function test_an_overpayment_stays_on_account_as_credit(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10)); // €15.00

        $payment = $this->pay(50.00, [$invoice->id => 15.00]);

        $this->assertSame(35.0, $payment->fresh()->unallocated_amount);
        $this->assertSame(35.0, $this->shop->fresh()->unallocatedCredit());
        // Owed less credit held: the customer is €35 in front.
        $this->assertSame(-35.0, $this->shop->fresh()->outstanding_balance);
    }

    public function test_allocating_more_than_an_invoice_owes_is_refused(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10)); // €15.00

        $this->expectException(InvoicingException::class);
        $this->pay(100.00, [$invoice->id => 20.00]);
    }

    public function test_allocating_more_than_the_payment_is_refused(): void
    {
        $first = $this->issue($this->deliveredOrder(10));
        $second = $this->issue($this->deliveredOrder(10));

        $this->expectException(InvoicingException::class);
        $this->pay(20.00, [$first->id => 15.00, $second->id => 15.00]);
    }

    public function test_another_customers_invoice_cannot_be_paid(): void
    {
        $other = \App\Models\Customer::create([
            'name' => 'Other Shop', 'email' => 'o@example.ie',
            'address' => 'X', 'city' => 'Y', 'postal_code' => 'Z',
            'payment_terms' => 'net_30', 'is_active' => true,
        ]);
        $theirs = $this->issue($this->deliveredOrder(10, customer: $other));

        $this->expectException(InvoicingException::class);
        $this->pay(15.00, [$theirs->id => 15.00]);
    }

    public function test_oldest_due_invoices_are_suggested_first(): void
    {
        $old = $this->issue($this->deliveredOrder(10));
        $old->update(['due_date' => today()->subDays(40)]);
        $new = $this->issue($this->deliveredOrder(20));

        $suggested = app(PaymentService::class)->suggestAllocations($this->shop, 20.00);

        $this->assertSame([$old->id => 15.0, $new->id => 5.0], $suggested);
    }

    public function test_credit_on_account_can_be_applied_later(): void
    {
        $this->pay(50.00);                                  // paid up front, nothing open
        $invoice = $this->issue($this->deliveredOrder(10)); // €15.00 raised afterwards

        $applied = app(PaymentService::class)->allocateCredit($this->shop);

        $this->assertSame(15.0, $applied);
        $this->assertSame('0.00', $invoice->fresh()->balance);
        $this->assertSame(35.0, $this->shop->fresh()->unallocatedCredit());
    }

    public function test_deleting_a_payment_restores_the_balances(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10));
        $payment = $this->pay(15.00, [$invoice->id => 15.00]);

        $this->assertSame('0.00', $invoice->fresh()->balance);

        app(PaymentService::class)->delete($payment);

        $this->assertSame('15.00', $invoice->fresh()->balance);
        $this->assertSame('unpaid', $invoice->fresh()->payment_state);
        $this->assertSame(0, Payment::count());
    }

    public function test_office_records_a_payment_through_the_form(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10));
        $office = User::factory()->create();

        $this->actingAs($office)
            ->post(route('invoicing.payments.store'), [
                'customer_id' => $this->shop->id,
                'amount' => '15.00',
                'received_at' => today()->toDateString(),
                'method' => 'cheque',
                'reference' => '00123',
                'allocations' => [$invoice->id => '15.00'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('0.00', $invoice->fresh()->balance);
        $this->assertSame($office->id, Payment::sole()->recorded_by);
        $this->assertSame('cheque', Payment::sole()->method->value);
    }

    public function test_the_create_form_lists_the_customers_open_invoices(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10));

        $this->actingAs(User::factory()->create())
            ->get(route('invoicing.payments.create', ['customer_id' => $this->shop->id]))
            ->assertOk()
            ->assertSee($invoice->number);
    }

    public function test_outstanding_balance_ignores_cancelled_and_unbilled_orders(): void
    {
        // The old accessor summed every order whose payment_status wasn't paid,
        // including these two. Only issued invoices count now.
        $this->deliveredOrder(100);                        // delivered but not invoiced
        $this->deliveredOrder(50, 'cancelled');
        $invoice = $this->issue($this->deliveredOrder(10)); // €15.00

        $this->assertSame(15.0, $this->shop->fresh()->outstanding_balance);
        $this->assertSame('15.00', $invoice->balance);
    }
}
