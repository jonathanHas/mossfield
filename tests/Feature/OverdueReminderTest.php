<?php

namespace Tests\Feature;

use App\Mail\OverdueReminderMail;
use App\Models\DocumentEmail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class OverdueReminderTest extends InvoicingTestCase
{
    private function overdueInvoice(int $daysLate = 10, int $qty = 10)
    {
        $invoice = $this->issue($this->deliveredOrder($qty));
        $invoice->update(['due_date' => today()->subDays($daysLate)]);

        return $invoice->fresh();
    }

    public function test_a_reminder_is_sent_and_logged_as_a_reminder(): void
    {
        Mail::fake();
        $invoice = $this->overdueInvoice();
        $office = User::factory()->create();

        $this->actingAs($office)
            ->post(route('invoicing.invoices.remind', $invoice))
            ->assertSessionHas('success');

        Mail::assertSent(OverdueReminderMail::class, fn ($mail) => $mail->invoice->is($invoice));

        $row = DocumentEmail::sole();

        $this->assertSame('reminder', $row->kind->value, 'a reminder is not an invoice send');
        $this->assertSame('sent', $row->status->value);
        $this->assertNotNull($invoice->fresh()->last_reminded_at);
        $this->assertNull($invoice->fresh()->emailed_at, 'reminding is not the same as sending the invoice');
    }

    public function test_an_invoice_within_terms_cannot_be_reminded(): void
    {
        Mail::fake();
        $invoice = $this->issue($this->deliveredOrder());

        $this->assertFalse($invoice->canBeReminded());

        $this->actingAs(User::factory()->create())
            ->post(route('invoicing.invoices.remind', $invoice))
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_a_paid_invoice_cannot_be_reminded(): void
    {
        $invoice = $this->overdueInvoice();
        app(\App\Services\PaymentService::class)->record($this->shop, [
            'amount' => 15.00, 'received_at' => today()->toDateString(), 'method' => 'cash',
        ], [$invoice->id => 15.00]);

        $this->assertFalse($invoice->fresh()->canBeReminded());
    }

    public function test_remind_all_covers_every_late_invoice_once(): void
    {
        Mail::fake();
        $first = $this->overdueInvoice(10, 10);
        $second = $this->overdueInvoice(20, 20);
        $office = User::factory()->create();

        $this->actingAs($office)
            ->post(route('invoicing.overdue.remind-all'))
            ->assertSessionHas('success');

        Mail::assertSent(OverdueReminderMail::class, 2);
        $this->assertNotNull($first->fresh()->last_reminded_at);
        $this->assertNotNull($second->fresh()->last_reminded_at);

        // A second click the same day must not send again.
        $this->actingAs($office)
            ->post(route('invoicing.overdue.remind-all'))
            ->assertSessionHas('error');

        Mail::assertSent(OverdueReminderMail::class, 2);
    }

    public function test_the_reminder_names_the_balance_and_days_late(): void
    {
        $invoice = $this->overdueInvoice(7);

        $html = (new OverdueReminderMail($invoice))->render();

        $this->assertStringContainsString('7 days past its due date', $html);
        $this->assertStringContainsString('€15.00', $html);
        $this->assertStringContainsString($invoice->number, $html);
    }
}
