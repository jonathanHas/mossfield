<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\InvoicingException;
use Illuminate\Support\Facades\DB;

/**
 * Consolidated billing: one invoice per customer covering a period of
 * deliveries, for shops that get a drop most days and want a single bill.
 */
class ConsolidatedInvoiceTest extends InvoicingTestCase
{
    private Customer $weekly;

    protected function setUp(): void
    {
        parent::setUp();

        $this->weekly = Customer::create([
            'name' => 'Weekly Wholesaler',
            'email' => 'w@example.ie',
            'address' => 'Quay St', 'city' => 'Galway', 'postal_code' => 'H91 AB12',
            'country' => 'Ireland', 'payment_terms' => 'net_14',
            'invoicing_mode' => 'weekly', 'is_active' => true,
        ]);
    }

    private function issueWeek(): Invoice
    {
        return app(InvoiceService::class)->issueConsolidated(
            $this->weekly,
            today()->startOfWeek(),
            today()->endOfWeek(),
        );
    }

    public function test_one_invoice_covers_every_delivery_in_the_period(): void
    {
        $this->deliveredOrder(4, customer: $this->weekly, deliveryDate: today()->startOfWeek()->toDateString());
        $this->deliveredOrder(6, customer: $this->weekly, deliveryDate: today()->toDateString());

        $invoice = $this->issueWeek();

        $this->assertSame('consolidated', $invoice->type->value);
        $this->assertSame('15.00', $invoice->total, '(4 + 6) bottles at €1.50');
        $this->assertCount(2, $invoice->lines);
        $this->assertSame(2, Order::where('invoice_id', $invoice->id)->count());
        $this->assertSame(today()->addDays(14)->toDateString(), $invoice->due_date->toDateString());
    }

    public function test_deliveries_outside_the_period_are_left_alone(): void
    {
        $inside = $this->deliveredOrder(4, customer: $this->weekly, deliveryDate: today()->toDateString());
        $outside = $this->deliveredOrder(6, customer: $this->weekly, deliveryDate: today()->subWeeks(3)->toDateString());

        $invoice = $this->issueWeek();

        $this->assertSame($invoice->id, $inside->fresh()->invoice_id);
        $this->assertNull($outside->fresh()->invoice_id);
        $this->assertSame('6.00', $invoice->total);
    }

    public function test_orders_that_have_not_shipped_are_not_billed(): void
    {
        $this->deliveredOrder(4, 'ready', $this->weekly, today()->toDateString());
        $this->deliveredOrder(4, 'confirmed', $this->weekly, today()->toDateString());
        $shipped = $this->deliveredOrder(2, 'dispatched', $this->weekly, today()->toDateString());

        $invoice = $this->issueWeek();

        $this->assertCount(1, $invoice->lines);
        $this->assertSame($invoice->id, $shipped->fresh()->invoice_id);
    }

    public function test_running_the_same_period_twice_finds_nothing_left(): void
    {
        $this->deliveredOrder(4, customer: $this->weekly, deliveryDate: today()->toDateString());
        $this->issueWeek();

        $this->expectException(InvoicingException::class);
        $this->issueWeek();
    }

    /**
     * Mossorders imports arrive with a null delivery_date, so period matching
     * falls back to when the order was actually delivered.
     */
    public function test_an_imported_order_with_no_delivery_date_is_matched_on_delivered_at(): void
    {
        $order = $this->deliveredOrder(4, customer: $this->weekly, deliveryDate: today()->toDateString());
        $order->update(['delivery_date' => null, 'delivered_at' => today()->setTime(9, 0)]);

        $invoice = $this->issueWeek();

        $this->assertSame($invoice->id, $order->fresh()->invoice_id);
    }

    public function test_preview_shows_the_money_without_consuming_a_number(): void
    {
        $this->deliveredOrder(4, customer: $this->weekly, deliveryDate: today()->toDateString());

        $preview = app(InvoiceService::class)->previewConsolidated(
            $this->weekly, today()->startOfWeek(), today()->endOfWeek()
        );

        $this->assertSame('PREVIEW', $preview->number);
        $this->assertSame(6.0, round((float) $preview->total, 2));
        $this->assertCount(1, $preview->lines);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(1, (int) DB::table('document_sequences')->where('key', 'invoice')->value('next_value'));
    }

    public function test_the_run_screen_previews_and_then_issues(): void
    {
        $this->deliveredOrder(4, customer: $this->weekly, deliveryDate: today()->toDateString());
        $office = User::factory()->create();

        $this->actingAs($office)
            ->get(route('invoicing.consolidated.create', [
                'start' => today()->startOfWeek()->toDateString(),
                'end' => today()->endOfWeek()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Weekly Wholesaler');

        $this->actingAs($office)
            ->post(route('invoicing.consolidated.store'), [
                'start' => today()->startOfWeek()->toDateString(),
                'end' => today()->endOfWeek()->toDateString(),
                'customer_ids' => [$this->weekly->id],
            ])
            ->assertRedirect(route('invoicing.invoices.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, Invoice::count());
    }

    public function test_only_consolidated_customers_appear_in_the_run(): void
    {
        // A per-order customer's delivery must never be swept into a period run.
        $this->deliveredOrder(4);
        $this->deliveredOrder(4, customer: $this->weekly, deliveryDate: today()->toDateString());

        $candidates = app(InvoiceService::class)
            ->consolidatedCandidates(today()->startOfWeek(), today()->endOfWeek());

        $this->assertCount(1, $candidates);
        $this->assertSame($this->weekly->id, $candidates->first()->id);
    }

    public function test_the_pdf_groups_lines_under_each_order(): void
    {
        $a = $this->deliveredOrder(4, customer: $this->weekly, deliveryDate: today()->toDateString());
        $b = $this->deliveredOrder(6, customer: $this->weekly, deliveryDate: today()->toDateString());
        $invoice = $this->issueWeek();

        $html = view('invoicing.invoices.pdf', ['invoice' => $invoice->fresh('lines'), 'pdf' => true])->render();

        $this->assertStringContainsString($a->order_number, $html);
        $this->assertStringContainsString($b->order_number, $html);
    }

    public function test_a_consolidated_pdf_shows_each_reference_once_per_order(): void
    {
        $a = $this->deliveredOrder(4, customer: $this->weekly, deliveryDate: today()->toDateString());
        $b = $this->deliveredOrder(6, customer: $this->weekly, deliveryDate: today()->toDateString());
        $a->update(['customer_reference' => 'PO-AAA']);
        $b->update(['customer_reference' => 'PO-BBB']);
        $invoice = $this->issueWeek();

        $html = view('invoicing.invoices.pdf', ['invoice' => $invoice->fresh('lines'), 'pdf' => true])->render();

        $this->assertSame(1, substr_count($html, 'PO-AAA'), 'in its order heading only, not repeated in the header');
        $this->assertSame(1, substr_count($html, 'PO-BBB'));
        $this->assertStringNotContainsString('Your ref:', $html, 'the per-order header line is for per-order invoices');
    }
}
