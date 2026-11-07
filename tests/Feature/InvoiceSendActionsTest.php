<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;

/**
 * Cycle 18 (UX review finding 4): the invoice can be sent from where it was
 * issued — Email invoice on the order page's billing line, PDF / Email on
 * each Invoices-list row, and Send all not yet emailed over the list.
 */
class InvoiceSendActionsTest extends InvoicingTestCase
{
    private User $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->office = User::factory()->create();
    }

    private function customer(string $name, ?string $email, string $delivery = 'email'): Customer
    {
        return Customer::create([
            'name' => $name, 'email' => $email, 'address' => '2 Main St', 'country' => 'Ireland',
            'payment_terms' => 'net_30', 'is_active' => true, 'invoice_delivery' => $delivery,
        ]);
    }

    private function invoiceFor(?Customer $customer = null): Invoice
    {
        return $this->issue($this->deliveredOrder(customer: $customer));
    }

    private function emailAction(Invoice $invoice): string
    {
        return 'action="'.route('invoicing.invoices.email', $invoice).'"';
    }

    // --- Order page ---------------------------------------------------------

    public function test_order_page_emails_an_unsent_invoice(): void
    {
        $invoice = $this->invoiceFor();
        $order = $invoice->orders()->first();

        $this->actingAs($this->office)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee($this->emailAction($invoice), false)
            ->assertSee('Email invoice')
            ->assertSee('data-confirm-title="Email this invoice?"', false);

        $this->actingAs($this->office)
            ->from(route('orders.show', $order))
            ->post(route('invoicing.invoices.email', $invoice))
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('success');

        $this->assertNotNull($invoice->fresh()->emailed_at);
        $this->assertSame('sent', $invoice->emails()->sole()->status->value);

        $this->actingAs($this->office)->get(route('orders.show', $order))
            ->assertSee('emailed '.today()->format('d/m/Y'))
            ->assertDontSee($this->emailAction($invoice), false);
    }

    public function test_factory_sees_no_billing_line_on_the_order_page(): void
    {
        $invoice = $this->invoiceFor();

        $this->actingAs(User::factory()->factoryWorker()->create())
            ->get(route('orders.show', $invoice->orders()->first()))
            ->assertOk()
            ->assertDontSee($this->emailAction($invoice), false)
            ->assertDontSee($invoice->number);
    }

    public function test_order_page_without_an_address_says_so(): void
    {
        $invoice = $this->invoiceFor($this->customer('No Address Shop', null));

        $this->actingAs($this->office)->get(route('orders.show', $invoice->orders()->first()))
            ->assertSee('no email address')
            ->assertDontSee($this->emailAction($invoice), false);
    }

    public function test_order_page_for_a_paper_customer_shows_the_delivery_mode(): void
    {
        $invoice = $this->invoiceFor($this->customer('Paper Shop', 'paper@example.ie', 'print'));

        $this->actingAs($this->office)->get(route('orders.show', $invoice->orders()->first()))
            ->assertSee('Print / post')
            ->assertDontSee($this->emailAction($invoice), false);
    }

    // --- Invoices list ------------------------------------------------------

    public function test_list_rows_offer_pdf_and_email_only_while_unsent(): void
    {
        $unsent = $this->invoiceFor();
        $paper = $this->invoiceFor($this->customer('Paper Shop', 'paper@example.ie', 'print'));
        $sent = $this->invoiceFor($this->customer('Sent Shop', 'sent@example.ie'));
        $sent->markEmailed(now());

        $response = $this->actingAs($this->office)->get(route('invoicing.invoices.index'))->assertOk();

        foreach ([$unsent, $paper, $sent] as $invoice) {
            $response->assertSee('href="'.route('invoicing.invoices.pdf', $invoice).'"', false);
        }
        $response->assertSee($this->emailAction($unsent), false)
            ->assertDontSee($this->emailAction($paper), false)
            ->assertDontSee($this->emailAction($sent), false);
    }

    public function test_empty_list_spans_nine_columns(): void
    {
        $this->actingAs($this->office)->get(route('invoicing.invoices.index'))
            ->assertSee('colspan="9"', false)
            ->assertSee('No invoices match these filters.');
    }

    // --- Send all -----------------------------------------------------------

    public function test_send_all_queues_every_unsent_emailable_invoice(): void
    {
        $first = $this->invoiceFor();
        $second = $this->invoiceFor($this->customer('Second Shop', 'second@example.ie'));
        $paper = $this->invoiceFor($this->customer('Paper Shop', 'paper@example.ie', 'print'));
        $noAddress = $this->invoiceFor($this->customer('No Address Shop', null));

        $this->actingAs($this->office)
            ->post(route('invoicing.invoices.send-all'))
            ->assertRedirect(route('invoicing.emails.index'))
            ->assertSessionHas('success', fn ($m) => str_starts_with($m, '2 invoices queued for sending')
                && str_contains($m, 'Skipped (no email address): No Address Shop ('.$noAddress->number.')'));

        $this->assertNotNull($first->fresh()->emailed_at);
        $this->assertNotNull($second->fresh()->emailed_at);
        $this->assertNull($paper->fresh()->emailed_at);
        $this->assertNull($noAddress->fresh()->emailed_at);

        $rows = $first->emails()->get()->merge($second->emails()->get());
        $this->assertCount(2, $rows);
        $this->assertSame(['sent'], $rows->pluck('status')->map->value->unique()->values()->all());
        $this->assertCount(1, $rows->pluck('batch_id')->unique());
        $this->assertNotNull($rows->first()->batch_id);
        $this->assertSame(0, $paper->emails()->count());
    }

    public function test_send_all_with_nothing_to_send_warns(): void
    {
        $this->actingAs($this->office)
            ->post(route('invoicing.invoices.send-all'))
            ->assertRedirect(route('invoicing.invoices.index'))
            ->assertSessionHas('warning', 'Nothing to send — every invoice that should be emailed has been.');
    }

    public function test_send_all_is_office_only(): void
    {
        $this->invoiceFor();

        $this->actingAs(User::factory()->factoryWorker()->create())
            ->post(route('invoicing.invoices.send-all'))
            ->assertForbidden();
    }

    public function test_send_all_button_shows_the_count_only_when_there_is_something_to_send(): void
    {
        $this->actingAs($this->office)->get(route('invoicing.invoices.index'))
            ->assertDontSee('Send all not yet emailed');

        $this->invoiceFor();
        $this->invoiceFor($this->customer('Second Shop', 'second@example.ie'));

        $this->actingAs($this->office)->get(route('invoicing.invoices.index'))
            ->assertSee('Send all not yet emailed (2)')
            ->assertSee('action="'.route('invoicing.invoices.send-all').'"', false)
            ->assertSee('data-confirm-title="Send all unsent invoices?"', false);
    }
}
