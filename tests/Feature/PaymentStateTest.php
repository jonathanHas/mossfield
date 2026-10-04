<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Services\PaymentService;

/**
 * Whether an invoice is overdue is DERIVED from balance + due date, so it can
 * never go stale. The nightly command only keeps the mirrored
 * orders.payment_status column in step, which is what the order screens read.
 */
class PaymentStateTest extends InvoicingTestCase
{
    public function test_an_unpaid_invoice_past_its_due_date_is_overdue(): void
    {
        $invoice = $this->issue($this->deliveredOrder());
        $invoice->update(['due_date' => today()->subDays(5)]);

        $invoice->refresh();

        $this->assertTrue($invoice->isOverdue());
        $this->assertSame('overdue', $invoice->payment_state);
        $this->assertSame(5, $invoice->days_overdue);
    }

    public function test_a_paid_invoice_is_never_overdue(): void
    {
        $invoice = $this->issue($this->deliveredOrder(10));
        $invoice->update(['due_date' => today()->subDays(30)]);

        app(PaymentService::class)->record($this->shop, [
            'amount' => 15.00, 'received_at' => today()->toDateString(), 'method' => 'cash',
        ], [$invoice->id => 15.00]);

        $this->assertFalse($invoice->fresh()->isOverdue());
        $this->assertSame('paid', $invoice->fresh()->payment_state);
    }

    public function test_an_invoice_due_today_is_not_yet_overdue(): void
    {
        $invoice = $this->issue($this->deliveredOrder());
        $invoice->update(['due_date' => today()]);

        $this->assertFalse($invoice->fresh()->isOverdue());
    }

    public function test_the_nightly_command_flips_the_mirror_to_overdue(): void
    {
        $order = $this->deliveredOrder();
        $invoice = $this->issue($order);
        $invoice->update(['due_date' => today()->subDay()]);

        $this->assertSame('pending', $order->fresh()->payment_status);

        $this->artisan('invoicing:refresh-overdue')->assertSuccessful();

        $this->assertSame('overdue', $order->fresh()->payment_status);
    }

    public function test_the_nightly_command_clears_the_mirror_once_paid(): void
    {
        $order = $this->deliveredOrder(10);
        $invoice = $this->issue($order);
        $invoice->update(['due_date' => today()->subDay()]);
        $this->artisan('invoicing:refresh-overdue');

        $this->assertSame('overdue', $order->fresh()->payment_status);

        app(PaymentService::class)->record($this->shop, [
            'amount' => 15.00, 'received_at' => today()->toDateString(), 'method' => 'cash',
        ], [$invoice->id => 15.00]);

        $this->artisan('invoicing:refresh-overdue')->assertSuccessful();

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_the_overdue_screen_lists_and_totals_what_is_late(): void
    {
        $late = $this->issue($this->deliveredOrder(10));
        $late->update(['due_date' => today()->subDays(10)]);
        $this->issue($this->deliveredOrder(20)); // within terms

        $this->actingAs(User::factory()->create())
            ->get(route('invoicing.overdue.index'))
            ->assertOk()
            ->assertSee($late->number)
            ->assertSee('€15.00');
    }

    public function test_the_invoice_list_can_filter_to_overdue(): void
    {
        $late = $this->issue($this->deliveredOrder(10));
        $late->update(['due_date' => today()->subDays(3)]);
        $current = $this->issue($this->deliveredOrder(20));

        $this->actingAs(User::factory()->create())
            ->get(route('invoicing.invoices.index', ['overdue' => 1]))
            ->assertOk()
            ->assertSee($late->number)
            ->assertDontSee($current->number);
    }

    public function test_a_void_invoice_reports_no_balance(): void
    {
        $invoice = $this->issue($this->deliveredOrder());
        app(\App\Services\InvoiceService::class)->void($invoice, 'Duplicate');

        $this->assertSame('void', $invoice->fresh()->payment_state);
        $this->assertSame(0.0, $this->shop->fresh()->outstanding_balance);
        $this->assertSame(0, Invoice::open()->count());
    }
}
