<?php

namespace Tests\Feature;

use App\Models\DocumentEmail;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * The communications log and the "not yet emailed" queue — the two things the
 * office asked for: which invoices haven't gone out, and what has been sent.
 */
class DocumentEmailLogTest extends InvoicingTestCase
{
    public function test_a_send_stamps_the_invoice_and_writes_a_log_row(): void
    {
        Mail::fake();
        $invoice = $this->issue($this->deliveredOrder());
        $office = User::factory()->create();

        $this->actingAs($office)
            ->post(route('invoicing.invoices.email', $invoice))
            ->assertSessionHas('success');

        $invoice->refresh();

        $this->assertNotNull($invoice->emailed_at);
        $this->assertTrue($invoice->hasBeenEmailed());

        $row = DocumentEmail::sole();

        $this->assertSame('invoice', $row->kind->value);
        $this->assertSame('sent', $row->status->value);
        $this->assertSame('k@example.ie', $row->recipient);
        $this->assertSame($office->id, $row->sent_by);
        $this->assertSame($this->shop->id, $row->customer_id);
    }

    public function test_the_billing_email_is_preferred_over_the_main_one(): void
    {
        Mail::fake();
        $this->shop->update(['billing_email' => 'accounts@example.ie']);
        $invoice = $this->issue($this->deliveredOrder());

        $this->actingAs(User::factory()->create())
            ->post(route('invoicing.invoices.email', $invoice));

        $this->assertSame('accounts@example.ie', DocumentEmail::sole()->recipient);
    }

    public function test_an_invoice_with_no_address_is_refused_with_a_message(): void
    {
        Mail::fake();
        $this->shop->update(['email' => '', 'billing_email' => null]);
        $invoice = $this->issue($this->deliveredOrder());

        $this->actingAs(User::factory()->create())
            ->post(route('invoicing.invoices.email', $invoice))
            ->assertSessionHas('error');

        $this->assertNull($invoice->fresh()->emailed_at);
        $this->assertSame(0, DocumentEmail::count(), 'nothing was attempted, so nothing is logged');
        Mail::assertNothingSent();
    }

    public function test_a_failed_send_leaves_the_invoice_unstamped(): void
    {
        $invoice = $this->issue($this->deliveredOrder());
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('Connection refused'));

        $this->actingAs(User::factory()->create())
            ->post(route('invoicing.invoices.email', $invoice))
            ->assertSessionHas('error');

        $this->assertNull($invoice->fresh()->emailed_at, 'a failure must never look like a send');

        $row = DocumentEmail::sole();

        $this->assertSame('failed', $row->status->value);
        $this->assertStringContainsString('Connection refused', $row->error);
    }

    public function test_the_not_emailed_queue_lists_what_still_needs_sending(): void
    {
        Mail::fake();
        $sent = $this->issue($this->deliveredOrder(10));
        $unsent = $this->issue($this->deliveredOrder(20));

        $office = User::factory()->create();
        $this->actingAs($office)->post(route('invoicing.invoices.email', $sent));

        $this->assertSame(1, Invoice::notEmailed()->count());

        // Burn the "Invoice INV-… emailed" flash first: it names the sent
        // invoice, which would otherwise look like a row on the queue.
        $this->actingAs($office)->get(route('invoicing.invoices.index'));

        $this->actingAs($office)
            ->get(route('invoicing.invoices.index', ['emailed' => 0]))
            ->assertOk()
            ->assertSee($unsent->number)
            ->assertDontSee($sent->number);
    }

    /**
     * A customer who takes paper invoices was never going to be emailed, so
     * counting them would leave the queue permanently non-empty.
     */
    public function test_print_only_customers_stay_out_of_the_not_emailed_queue(): void
    {
        $this->shop->update(['invoice_delivery' => 'print']);
        $invoice = $this->issue($this->deliveredOrder());

        $this->assertSame(0, Invoice::notEmailed()->count());

        $this->actingAs(User::factory()->create())
            ->get(route('invoicing.invoices.index', ['emailed' => 0]))
            ->assertOk()
            ->assertDontSee($invoice->number);
    }

    public function test_the_log_screen_lists_and_filters_sends(): void
    {
        Mail::fake();
        $invoice = $this->issue($this->deliveredOrder());
        $office = User::factory()->create();
        $this->actingAs($office)->post(route('invoicing.invoices.email', $invoice));

        $this->actingAs($office)
            ->get(route('invoicing.emails.index'))
            ->assertOk()
            ->assertSee('k@example.ie')
            ->assertSee('Invoice');

        $this->actingAs($office)
            ->get(route('invoicing.emails.index', ['kind' => 'statement']))
            ->assertOk()
            ->assertDontSee('k@example.ie');
    }

    public function test_the_invoice_page_shows_its_own_email_history(): void
    {
        Mail::fake();
        $invoice = $this->issue($this->deliveredOrder());
        $office = User::factory()->create();

        $this->actingAs($office)->post(route('invoicing.invoices.email', $invoice));

        $this->actingAs($office)
            ->get(route('invoicing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Email history')
            ->assertSee('k@example.ie');
    }
}
