<?php

namespace App\Mail;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Emails a per-order document (the priced invoice or the price-free dispatch docket)
 * to the customer as a PDF attachment. Reuses the exact dompdf render the controller
 * uses for the ?download=1 path — passing 'pdf' => true suppresses the interactive
 * toolbar so the HTML is mail/PDF-safe. Sent synchronously (not queued) so the
 * operator gets immediate success/failure feedback.
 */
class OrderDocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $document  'invoice' or 'docket'
     */
    public function __construct(public Order $order, public string $document) {}

    public function envelope(): Envelope
    {
        $label = $this->document === 'invoice' ? 'Invoice' : 'Dispatch docket';

        return new Envelope(
            subject: "Mossfield Organic Farm — {$label} {$this->order->order_number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-document',
            with: [
                'order' => $this->order,
                'document' => $this->document,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdf = Pdf::loadView("orders.{$this->document}", [
            'order' => $this->order,
            'pdf' => true,
        ]);

        return [
            Attachment::fromData(
                fn () => $pdf->output(),
                "{$this->document}-{$this->order->order_number}.pdf",
            )->withMime('application/pdf'),
        ];
    }
}
