<?php

namespace App\Mail;

use App\Contracts\EmailableDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Emails any finance document (invoice, credit note, statement) as a PDF
 * attachment. The document itself supplies its subject, filename and rendered
 * PDF, so adding a new document type needs no changes here.
 *
 * Not ShouldQueue: queueing happens one level up, at App\Jobs\SendDocumentEmail,
 * so the document_emails log row is the unit of work rather than the mail.
 */
class DocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public EmailableDocument $document) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->document->documentSubject());
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.document',
            with: [
                'document' => $this->document,
                'kind' => $this->document->documentKind(),
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
