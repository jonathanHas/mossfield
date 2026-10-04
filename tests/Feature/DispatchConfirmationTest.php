<?php

namespace Tests\Feature;

use App\Documents\DispatchConfirmation;
use App\Mail\DispatchConfirmationMail;
use App\Mail\DocumentMail;
use App\Mail\OrderDocumentMail;
use App\Models\DocumentEmail;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\DocumentMailer;
use Illuminate\Support\Facades\Mail;

/**
 * The priced Dispatch Confirmation — the document a shop gets the moment its
 * order goes out — and the per-order "Mark dispatched" path that sends it.
 */
class DispatchConfirmationTest extends InvoicingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function readyOrder(int $qty = 10): Order
    {
        return $this->deliveredOrder($qty, 'ready');
    }

    // ── The document ──────────────────────────────────────────────────

    public function test_it_renders_prices_and_says_the_invoice_will_follow(): void
    {
        $order = $this->readyOrder();

        $this->actingAs(User::factory()->create())
            ->get(route('orders.dispatch-confirmation', $order))
            ->assertOk()
            ->assertSee('Dispatch confirmation')
            ->assertSee('INVOICE TO FOLLOW', false)
            ->assertSee('Kerry Organic Store')
            ->assertSee('Net 30 days')
            ->assertSee('€15.00')
            ->assertDontSee('INV-');
    }

    public function test_it_downloads_as_a_pdf(): void
    {
        $order = $this->readyOrder();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('orders.dispatch-confirmation', ['order' => $order, 'download' => 1]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('dispatch-confirmation-'.$order->order_number.'.pdf', $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        // Fonts are subset (config/dompdf.php), not embedded whole: a one-page
        // document was ~1.4 MB (E2E F-34).
        $this->assertLessThan(300 * 1024, strlen($response->getContent()));
    }

    public function test_it_is_unavailable_before_the_order_is_ready(): void
    {
        $order = $this->deliveredOrder(10, 'confirmed');

        $this->actingAs(User::factory()->create())
            ->get(route('orders.dispatch-confirmation', $order))
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('error');
    }

    public function test_factory_and_driver_are_denied(): void
    {
        $order = $this->readyOrder();

        $this->actingAs(User::factory()->factoryWorker()->create())
            ->get(route('orders.dispatch-confirmation', $order))->assertForbidden();
        $this->actingAs(User::factory()->driver()->create())
            ->get(route('orders.dispatch-confirmation', $order))->assertForbidden();
    }

    // ── Per-order dispatch ────────────────────────────────────────────

    public function test_mark_dispatched_issues_the_invoice_and_emails_the_confirmation(): void
    {
        $order = $this->readyOrder();
        $office = User::factory()->create();

        $this->actingAs($office)
            ->post(route('orders.dispatch', $order))
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'dispatched') && str_contains($m, 'INV-000001') && str_contains($m, 'k@example.ie'));

        $order->refresh();
        $this->assertSame('dispatched', $order->status);
        $this->assertNotNull($order->invoice_id);

        Mail::assertSent(DispatchConfirmationMail::class, fn ($mail) => $mail->hasTo('k@example.ie') && $mail->document->order->is($order));
        Mail::assertNotSent(DocumentMail::class);

        $this->assertDatabaseHas('document_emails', [
            'documentable_type' => Order::class,
            'documentable_id' => $order->id,
            'kind' => 'dispatch_confirmation',
            'recipient' => 'k@example.ie',
            'status' => 'sent',
            'sent_by' => $office->id,
        ]);
        $this->assertNull($order->invoice->emailed_at, 'the invoice waits in Finance');
    }

    public function test_the_confirmation_goes_to_the_billing_email_when_set(): void
    {
        $this->shop->update(['billing_email' => 'accounts@example.ie']);
        $order = $this->readyOrder();

        $this->actingAs(User::factory()->create())->post(route('orders.dispatch', $order));

        $this->assertSame('accounts@example.ie', DocumentEmail::sole()->recipient);
    }

    public function test_a_consolidated_customer_gets_a_confirmation_but_no_invoice(): void
    {
        $this->shop->update(['invoicing_mode' => 'weekly']);
        $order = $this->readyOrder();
        $office = User::factory()->create();

        $this->actingAs($office)->post(route('orders.dispatch', $order))
            ->assertSessionHas('success', fn ($m) => ! str_contains($m, 'INV-'));

        $this->assertNull($order->fresh()->invoice_id);
        $this->assertSame(0, Invoice::count());
        Mail::assertSent(DispatchConfirmationMail::class, 1);

        $this->actingAs($office)->get(route('orders.show', $order))
            ->assertOk()->assertSee('Billed on the weekly consolidated invoice');
    }

    public function test_a_customer_with_no_email_is_still_dispatched_and_told_so(): void
    {
        $this->shop->update(['email' => '', 'billing_email' => null]);
        $order = $this->readyOrder();
        $office = User::factory()->create();

        $this->actingAs($office)->post(route('orders.dispatch', $order))
            ->assertSessionHas('success')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'no email'));

        $this->assertSame('dispatched', $order->fresh()->status);
        Mail::assertNothingSent();

        $this->actingAs($office)->get(route('orders.show', $order))
            ->assertOk()->assertSee('Not sent — no email address', false);
    }

    public function test_an_already_invoiced_order_dispatches_without_a_second_invoice(): void
    {
        $order = $this->readyOrder();
        $invoice = $this->issue($order);

        $this->actingAs(User::factory()->create())->post(route('orders.dispatch', $order))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Already on invoice '.$invoice->number));

        $this->assertSame(1, Invoice::count());
    }

    public function test_factory_cannot_dispatch(): void
    {
        $order = $this->readyOrder();

        $this->actingAs(User::factory()->factoryWorker()->create())
            ->post(route('orders.dispatch', $order))
            ->assertForbidden();

        $this->assertSame('ready', $order->fresh()->status);
    }

    // ── Resend ────────────────────────────────────────────────────────

    public function test_office_can_resend_and_it_is_logged_again(): void
    {
        $order = $this->readyOrder();
        $office = User::factory()->create();
        $this->actingAs($office)->post(route('orders.dispatch', $order));

        $this->actingAs($office)
            ->post(route('orders.dispatch-confirmation.email', $order))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(2, DocumentEmail::where('kind', 'dispatch_confirmation')->count());
        Mail::assertSent(DispatchConfirmationMail::class, 2);
    }

    public function test_resend_is_refused_with_no_email_and_for_factory(): void
    {
        $order = $this->readyOrder();

        $this->actingAs(User::factory()->factoryWorker()->create())
            ->post(route('orders.dispatch-confirmation.email', $order))
            ->assertForbidden();

        $this->shop->update(['email' => '', 'billing_email' => null]);

        $this->actingAs(User::factory()->create())
            ->post(route('orders.dispatch-confirmation.email', $order))
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    }

    public function test_a_failed_send_shows_a_resend_button(): void
    {
        $order = $this->readyOrder();
        $office = User::factory()->create();
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP down'));

        $this->actingAs($office)->post(route('orders.dispatch', $order))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'failed'));

        $this->assertSame('failed', DocumentEmail::sole()->status->value);

        $this->actingAs($office)->get(route('orders.show', $order))
            ->assertOk()->assertSee('Resend');
    }

    // ── The job rebuilds the right adapter ────────────────────────────

    public function test_the_queue_job_resolves_both_order_documents(): void
    {
        $order = $this->readyOrder();
        $mailer = app(DocumentMailer::class);

        $confirmation = $mailer->queue(new DispatchConfirmation($order));
        $docket = $mailer->queue(new \App\Documents\OrderDocket($order));

        // sync queue: both already ran inline.
        $this->assertSame('sent', $confirmation->fresh()->status->value);
        $this->assertSame('sent', $docket->fresh()->status->value);
        Mail::assertSent(DispatchConfirmationMail::class, 1);
        Mail::assertSent(OrderDocumentMail::class, fn ($m) => $m->document === 'docket');
    }

    // ── Order page billing line ───────────────────────────────────────

    public function test_the_order_page_shows_one_billing_line_and_no_invoice_buttons(): void
    {
        $order = $this->readyOrder();
        $office = User::factory()->create();

        $this->actingAs($office)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Awaiting invoice')
            ->assertDontSee('Issue invoice')
            ->assertDontSee('Email invoice')
            ->assertDontSee('Email docket');

        $this->actingAs($office)->post(route('orders.dispatch', $order));

        $this->actingAs($office)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('INV-000001')
            ->assertSee('Unpaid')
            ->assertSee(route('invoicing.invoices.show', $order->fresh()->invoice))
            ->assertSee('Sent')
            ->assertDontSee('Awaiting invoice');
    }

    public function test_factory_sees_no_billing_line_or_payment_badge(): void
    {
        $order = $this->readyOrder();
        $office = User::factory()->create();
        $this->actingAs($office)->post(route('orders.dispatch', $order));
        // Burn the office's success flash (it names the invoice) before
        // rendering the page as factory.
        $this->actingAs($office)->get(route('orders.show', $order));

        $this->actingAs(User::factory()->factoryWorker()->create())
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertDontSee('INV-000001')
            ->assertDontSee('Awaiting invoice')
            ->assertDontSee('>Payment<', false);
    }
}
