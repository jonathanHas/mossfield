<?php

namespace Tests\Feature;

use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Services\InvoicingException;
use Illuminate\Support\Facades\DB;

/**
 * Invoice numbering. Revenue expects a sequential series, so the counter must
 * not skip on a failed issue and must not repeat under concurrent ones.
 */
class InvoiceNumberingTest extends InvoicingTestCase
{
    public function test_numbers_run_in_sequence(): void
    {
        $first = $this->issue($this->deliveredOrder());
        $second = $this->issue($this->deliveredOrder());
        $third = $this->issue($this->deliveredOrder());

        $this->assertSame(
            ['INV-000001', 'INV-000002', 'INV-000003'],
            [$first->number, $second->number, $third->number]
        );
    }

    /**
     * next() is claimed inside the issuing transaction, so a refusal rolls the
     * counter back with it instead of burning a number.
     */
    public function test_a_failed_issue_does_not_consume_a_number(): void
    {
        $this->issue($this->deliveredOrder());

        try {
            $this->issue($this->deliveredOrder(10, 'confirmed'));
        } catch (InvoicingException) {
            // expected
        }

        $this->assertSame('INV-000002', $this->issue($this->deliveredOrder())->number);
        $this->assertSame(3, (int) DB::table('document_sequences')->where('key', 'invoice')->value('next_value'));
    }

    public function test_issuing_many_in_one_request_keeps_them_unique(): void
    {
        foreach (range(1, 5) as $i) {
            $this->deliveredOrder();
        }

        $this->actingAs(\App\Models\User::factory()->create())
            ->post(route('invoicing.uninvoiced.issue-all'))
            ->assertRedirect();

        $numbers = Invoice::pluck('number');

        $this->assertCount(5, $numbers);
        $this->assertSame(5, $numbers->unique()->count());
    }

    public function test_the_number_is_zero_padded_to_six_digits(): void
    {
        DB::table('document_sequences')->where('key', 'invoice')->update(['next_value' => 42]);

        $this->assertSame('INV-000042', $this->issue($this->deliveredOrder())->number);
    }

    public function test_a_missing_counter_row_starts_itself(): void
    {
        DB::table('document_sequences')->where('key', 'invoice')->delete();

        $this->assertSame(1, DocumentSequence::next(DocumentSequence::INVOICE));
        $this->assertSame(2, DocumentSequence::next(DocumentSequence::INVOICE));
    }
}
