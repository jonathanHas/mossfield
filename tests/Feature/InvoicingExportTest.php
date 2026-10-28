<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CreditNoteService;
use App\Services\PaymentService;

/**
 * CSV extracts for the accountant.
 */
class InvoicingExportTest extends InvoicingTestCase
{
    private function csv(array $params): array
    {
        $response = $this->get(route('invoicing.export.download', $params));
        $response->assertOk();

        $body = trim($response->streamedContent());

        return array_map('str_getcsv', explode("\n", $body));
    }

    public function test_the_invoice_sheet_carries_the_net_vat_and_gross(): void
    {
        $this->actingAs(User::factory()->create());
        $this->shop->update(['vat_number' => 'IE1234567X']);
        $invoice = $this->issue($this->deliveredOrder(10, deliveryCharge: 4.50));

        $rows = $this->csv([
            'type' => 'invoices',
            'from' => today()->subDay()->toDateString(),
            'to' => today()->addDay()->toDateString(),
        ]);

        $this->assertSame(
            ['Date', 'Type', 'Number', 'Customer', 'VAT number', 'Net', 'VAT', 'Gross', 'Paid', 'Credited', 'Balance', 'Status', 'Due date'],
            $rows[0]
        );

        $this->assertSame($invoice->number, $rows[1][2]);
        $this->assertSame('IE1234567X', $rows[1][4]);
        $this->assertSame('18.66', $rows[1][5], 'subtotal 15.00 + delivery net 3.66');
        $this->assertSame('0.84', $rows[1][6]);
        $this->assertSame('19.50', $rows[1][7]);
    }

    /** Credit notes go negative so a column sum is the period's net turnover. */
    public function test_credit_notes_appear_as_negative_rows(): void
    {
        $this->actingAs(User::factory()->create());
        $invoice = $this->issue($this->deliveredOrder(10));
        app(CreditNoteService::class)->issue($invoice, [
            ['description' => 'Returned', 'quantity' => 1, 'amount' => 4.00, 'vat_rate' => 0],
        ], 'Damaged');

        $rows = $this->csv([
            'type' => 'invoices',
            'from' => today()->subDay()->toDateString(),
            'to' => today()->addDay()->toDateString(),
        ]);

        $creditRow = collect($rows)->firstWhere(1, 'Credit note');

        $this->assertNotNull($creditRow);
        $this->assertSame('-4.00', $creditRow[7]);

        $net = collect($rows)->skip(1)->sum(fn ($r) => (float) $r[7]);
        $this->assertSame(11.0, round($net, 2));
    }

    /** A void invoice keeps its row but adds nothing to the column sums. */
    public function test_a_void_invoice_exports_with_zero_money(): void
    {
        $this->actingAs(User::factory()->create());
        $live = $this->issue($this->deliveredOrder(10));
        $void = $this->issue($this->deliveredOrder(4));
        app(\App\Services\InvoiceService::class)->void($void, 'Wrong customer');

        $rows = $this->csv([
            'type' => 'invoices',
            'from' => today()->subDay()->toDateString(),
            'to' => today()->addDay()->toDateString(),
        ]);

        $voidRow = collect($rows)->firstWhere(2, $void->number);
        $this->assertSame('void', $voidRow[11]);
        $this->assertSame($void->bill_to_name, $voidRow[3]);
        $this->assertSame(['0.00', '0.00', '0.00', '0.00', '0.00', '0.00'], array_slice($voidRow, 5, 6));

        $liveRow = collect($rows)->firstWhere(2, $live->number);
        $this->assertSame('15.00', $liveRow[5]);
        $this->assertSame('15.00', $liveRow[7]);
        $this->assertSame('15.00', $liveRow[10]);

        $this->assertSame(15.0, round(collect($rows)->skip(1)->sum(fn ($r) => (float) $r[7]), 2));
    }

    public function test_the_payment_sheet_names_what_each_payment_settled(): void
    {
        $office = User::factory()->create();
        $this->actingAs($office);
        $invoice = $this->issue($this->deliveredOrder(10));
        app(PaymentService::class)->record($this->shop, [
            'amount' => 20.00, 'received_at' => today()->toDateString(),
            'method' => 'bank_transfer', 'reference' => 'TRF-9',
        ], [$invoice->id => 15.00], $office);

        $rows = $this->csv([
            'type' => 'payments',
            'from' => today()->subDay()->toDateString(),
            'to' => today()->addDay()->toDateString(),
        ]);

        $this->assertSame('Kerry Organic Store', $rows[1][1]);
        $this->assertSame('20.00', $rows[1][2]);
        $this->assertSame('Bank transfer', $rows[1][3]);
        $this->assertSame('TRF-9', $rows[1][4]);
        $this->assertSame('15.00', $rows[1][5]);
        $this->assertSame('5.00', $rows[1][6], 'the unallocated remainder');
        $this->assertSame($invoice->number, $rows[1][7]);
    }

    public function test_rows_outside_the_range_are_left_out(): void
    {
        $this->actingAs(User::factory()->create());
        $old = $this->issue($this->deliveredOrder(10));
        $old->update(['issued_at' => today()->subMonths(6)]);

        $rows = $this->csv([
            'type' => 'invoices',
            'from' => today()->subDay()->toDateString(),
            'to' => today()->addDay()->toDateString(),
        ]);

        $this->assertCount(1, $rows, 'only the header');
    }

    public function test_the_export_screen_renders(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('invoicing.export.index'))
            ->assertOk()
            ->assertSee('Accounting export');
    }
}
