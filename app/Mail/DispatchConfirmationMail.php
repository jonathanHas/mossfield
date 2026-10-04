<?php

namespace App\Mail;

use App\Documents\DispatchConfirmation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent automatically when an order is dispatched: the priced dispatch
 * confirmation as a PDF, with a short note that the invoice will follow.
 *
 * Not ShouldQueue — queueing happens at App\Jobs\SendDocumentEmail so the
 * document_emails row is the unit of work.
 */
class DispatchConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public DispatchConfirmation $document) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->document->documentSubject());
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.dispatch-confirmation',
            with: [
                'order' => $this->document->order,
                'customer' => $this->document->documentCustomer(),
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => $this->document->documentPdfData(),
                $this->document->documentFilename(),
            )->withMime('application/pdf'),
        ];
    }
}
