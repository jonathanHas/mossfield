<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\InvoicingException;
use Illuminate\Support\Facades\DB;

/**
 * Issuing an invoice. The property that matters throughout: the invoice is a
 * SNAPSHOT. Once issued, nothing that happens to the order may change what the
 * customer was billed.
 */
class InvoiceIssueTest extends InvoicingTestCase
{
    public function test_issuing_snapshots_lines_and_totals(): void
    {
        $order = $this->deliveredOrder(10);
        $invoice = $this->issue($order);

        $this->assertSame('INV-000001', $invoice->number);
        $this->assertSame('15.00', $invoice->total);
        $this->assertSame('15.00', $invoice->balance);
        $this->assertCount(1, $invoice->lines);
        $this->assertSame('10.000', $invoice->lines->first()->quantity);
        $this->assertSame('15.00', $invoice->lines->first()->amount);
        $this->assertSame($order->id, $invoice->lines->first()->order_id);
        $this->assertSame($invoice->id, $order->fresh()->invoice_id);
    }

    /**
     * The regression the whole design exists to prevent: the old screen
     * re-derived the invoice from the live order at render time.
     */
    public function test_editing_the_order_after_issue_does_not_change_the_invoice(): void
    {
        $order = $this->deliveredOrder(10);
        $invoice = $this->issue($order);

        $order->orderItems->first()->update(['quantity_ordered' => 999, 'quantity_fulfilled' => 999]);
        $order->calculateTotals();

        $invoice->refresh();

        $this->assertSame('15.00', $invoice->total);
        $this->assertSame('10.000', $invoice->lines->first()->quantity);
        $this->assertSame('1498.50', $order->fresh()->total_amount, 'the ORDER moves; the invoice does not');
    }

    public function test_an_order_cannot_be_invoiced_twice(): void
    {
        $order = $this->deliveredOrder();
        $this->issue($order);

        $this->expectException(InvoicingException::class);
        $this->issue($order->fresh());
    }

    public function test_an_order_below_ready_cannot_be_invoiced(): void
    {
        $order = $this->deliveredOrder(10, 'confirmed');

        $this->expectException(InvoicingException::class);
        $this->issue($order);
    }

    // ── Issue only once it has left (OPEN_QUESTIONS #5, F-38a) ─────────

    public function test_a_ready_order_cannot_be_invoiced_until_dispatched(): void
    {
        $order = $this->deliveredOrder(10, 'ready');

        try {
            $this->issue($order);
            $this->fail('a ready order was invoiced');
        } catch (InvoicingException $e) {
            $this->assertSame("An invoice is issued when the order is dispatched — this one hasn't left yet.", $e->getMessage());
        }
        $this->assertNull($order->fresh()->invoice_id);

        $order->update(['status' => 'dispatched']);
        $this->assertNotNull($this->issue($order)->number);
    }

    public function test_the_queue_lists_ready_orders_apart_with_no_issue_button_and_issue_all_skips_them(): void
    {
        $ready = $this->deliveredOrder(10, 'ready');
        $shipped = $this->deliveredOrder(4, 'dispatched');
        $office = User::factory()->create();

        $this->assertSame(1, \App\Http\Controllers\Invoicing\InvoiceController::headlineStats()['uninvoiced']);

        $page = $this->actingAs($office)->get(route('invoicing.uninvoiced.index'))->assertOk()
            ->assertSee('Picked, awaiting dispatch')
            ->assertSee('Issue all 1')
            ->assertSee(route('invoicing.invoices.issue-order', $shipped), false)
            ->assertDontSee(route('invoicing.invoices.issue-order', $ready), false);
        $this->assertStringContainsString($ready->order_number, $page->getContent());

        $this->actingAs($office)->post(route('invoicing.uninvoiced.issue-all'))
            ->assertSessionHas('success', '1 invoice issued.');
        $this->assertNull($ready->fresh()->invoice_id);
        $this->assertNotNull($shipped->fresh()->invoice_id);

        // The per-order Issue route refuses too.
        $this->actingAs($office)->post(route('invoicing.invoices.issue-order', $ready))
            ->assertSessionHas('error', "An invoice is issued when the order is dispatched — this one hasn't left yet.");
    }

    public function test_due_date_comes_from_the_customers_payment_terms(): void
    {
        $order = $this->deliveredOrder();
        $invoice = $this->issue($order);

        $this->assertSame(today()->toDateString(), $invoice->issued_at->toDateString());
        $this->assertSame(today()->addDays(30)->toDateString(), $invoice->due_date->toDateString());
        $this->assertSame('net_30', $invoice->payment_terms);
    }

    public function test_delivery_charge_becomes_its_own_line_with_the_vat_split(): void
    {
        // €4.50 gross → €3.66 net + €0.84 VAT, and net + VAT is exactly the gross.
        $order = $this->deliveredOrder(deliveryCharge: 4.50);
        $invoice = $this->issue($order);

        $delivery = $invoice->lines->firstWhere('line_type', 'delivery');

        $this->assertNotNull($delivery);
        $this->assertSame('3.66', $delivery->amount);
        $this->assertSame('0.84', $delivery->vat_amount);
        $this->assertSame('23.00', $delivery->vat_rate);
        $this->assertSame('3.66', $invoice->delivery_charge_net);
        $this->assertSame('0.84', $invoice->tax_amount);
        $this->assertSame('19.50', $invoice->total, 'subtotal 15.00 + the 4.50 the customer actually pays');
    }

    public function test_a_short_closed_line_bills_only_what_was_supplied(): void
    {
        $order = $this->deliveredOrder(10);
        $item = $order->orderItems->first();
        $item->update(['quantity_fulfilled' => 4, 'fulfilled_total' => 6.00, 'short_closed_at' => now(), 'short_reason' => 'No stock']);
        $order->calculateTotals();

        $invoice = $this->issue($order->fresh());
        $line = $invoice->lines->first();

        $this->assertSame('4.000', $line->quantity);
        $this->assertSame('6.00', $line->amount);
        $this->assertStringContainsString('6 of 10 not supplied', $line->detail);
    }

    public function test_a_never_picked_short_line_bills_zero(): void
    {
        $order = $this->deliveredOrder(10);
        $order->orderItems->first()->update([
            'quantity_fulfilled' => 0, 'fulfilled_total' => null, 'short_closed_at' => now(),
        ]);
        $order->calculateTotals();

        $invoice = $this->issue($order->fresh());

        $this->assertSame('0.00', $invoice->lines->first()->amount);
        $this->assertSame('Not supplied — not charged.', $invoice->lines->first()->detail);
    }

    public function test_bill_to_details_are_snapshotted_and_the_address_is_encrypted(): void
    {
        $this->shop->update(['vat_number' => 'IE1234567X', 'billing_email' => 'accounts@example.ie']);

        $invoice = $this->issue($this->deliveredOrder());

        $this->assertSame('Kerry Organic Store', $invoice->bill_to_name);
        $this->assertSame('accounts@example.ie', $invoice->bill_to_email);
        $this->assertSame('IE1234567X', $invoice->bill_to_vat_number);
        $this->assertStringContainsString('Tralee', $invoice->bill_to_address);

        // Same treatment as the customer's own address: never stored in clear.
        $raw = DB::table('invoices')->where('id', $invoice->id)->value('bill_to_address');
        $this->assertStringNotContainsString('Tralee', $raw);

        // Renaming the customer afterwards must not rewrite history.
        $this->shop->update(['name' => 'Renamed Ltd']);
        $this->assertSame('Kerry Organic Store', $invoice->fresh()->bill_to_name);
    }

    public function test_the_order_payment_status_mirrors_the_invoice(): void
    {
        $order = $this->deliveredOrder();
        $this->issue($order);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    /**
     * The guard lives in the service, not just behind a hidden button, so no
     * route — the queue's open POST, dispatch, a future caller — can bill a
     * weekly customer per delivery by accident.
     */
    public function test_a_consolidated_customer_cannot_be_invoiced_per_order(): void
    {
        $this->shop->update(['invoicing_mode' => 'weekly']);
        $order = $this->deliveredOrder();

        try {
            $this->issue($order);
            $this->fail('expected an InvoicingException');
        } catch (InvoicingException $e) {
            $this->assertStringContainsString('consolidated run', $e->getMessage());
        }

        $this->actingAs(User::factory()->create())
            ->post(route('invoicing.invoices.issue-order', $order))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, Invoice::count());
        $this->assertNull($order->fresh()->invoice_id);
    }

    public function test_office_can_issue_from_the_uninvoiced_queue(): void
    {
        $order = $this->deliveredOrder();
        $office = User::factory()->create();

        $this->actingAs($office)
            ->post(route('invoicing.invoices.issue-order', $order))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($office->id, Invoice::sole()->created_by);
    }

    public function test_issue_all_bills_every_per_order_customer(): void
    {
        $this->deliveredOrder(4);
        $this->deliveredOrder(6);

        // A consolidated customer must not be swept up by the per-order run.
        $weekly = \App\Models\Customer::create([
            'name' => 'Weekly Wholesaler', 'email' => 'w@example.ie',
            'address' => 'Quay', 'city' => 'Cork', 'postal_code' => 'T12 X',
            'payment_terms' => 'net_30', 'invoicing_mode' => 'weekly', 'is_active' => true,
        ]);
        $this->deliveredOrder(8, customer: $weekly);

        $this->actingAs(User::factory()->create())
            ->post(route('invoicing.uninvoiced.issue-all'))
            ->assertRedirect();

        $this->assertSame(2, Invoice::count());
        $this->assertNull($weekly->orders()->sole()->invoice_id);
    }

    public function test_uninvoiced_queue_separates_per_order_from_consolidated(): void
    {
        $this->deliveredOrder();

        $this->actingAs(User::factory()->create())
            ->get(route('invoicing.uninvoiced.index'))
            ->assertOk()
            ->assertSee('Per-order billing')
            ->assertSee('Kerry Organic Store');
    }

    /** A pre-F-38a row: invoiced while still ready (issue now needs dispatched). */
    private function invoicedReadyOrder(): \App\Models\Order
    {
        $order = $this->deliveredOrder(10, 'dispatched');
        $this->issue($order);
        $order->update(['status' => 'ready']);

        return $order->fresh(['orderItems', 'invoice']);
    }

    public function test_an_invoiced_order_refuses_line_edits(): void
    {
        $order = $this->invoicedReadyOrder();
        $invoice = $order->invoice;
        $item = $order->orderItems->first();

        $this->actingAs(User::factory()->create())
            ->patch(route('orders.items.update', [$order, $item]), ['quantity_ordered' => 20])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(10, OrderItem::find($item->id)->quantity_ordered);
        $this->assertStringContainsString($invoice->number, session('error'));
    }

    public function test_an_invoiced_order_refuses_cancellation(): void
    {
        $order = $this->invoicedReadyOrder();

        $this->actingAs(User::factory()->create())
            ->patch(route('orders.update', $order), ['status' => 'cancelled'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_a_per_order_invoice_names_the_order_and_the_customers_reference(): void
    {
        // E2E F-35: a per-order invoice printed neither our order number nor
        // the customer's PO reference — only consolidated headings did.
        $order = $this->deliveredOrder(10);
        $order->update(['customer_reference' => 'PO-123']);
        $invoice = $this->issue($order);

        $this->actingAs(\App\Models\User::factory()->create())
            ->get(route('invoicing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('Your ref:')
            ->assertSee('PO-123');

        $html = view('invoicing.invoices.pdf', ['invoice' => $invoice->fresh('lines'), 'pdf' => true])->render();
        $this->assertStringContainsString('Order '.$order->order_number, $html);
        $this->assertStringContainsString('Your ref: PO-123', $html);
    }
}
