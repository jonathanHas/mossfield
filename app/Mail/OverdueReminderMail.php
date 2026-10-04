<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A polite nudge on an overdue invoice, with the invoice PDF attached so the
 * customer doesn't have to go looking for it.
 */
class OverdueReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: config('invoicing.seller.name').' — Payment reminder: invoice '.$this->invoice->number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.overdue-reminder',
            with: ['invoice' => $this->invoice],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => $this->invoice->documentPdfData(),
                $this->invoice->documentFilename(),
            )->withMime('application/pdf'),
        ];
    }
}
