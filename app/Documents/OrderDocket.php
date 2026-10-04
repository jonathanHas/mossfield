<?php

namespace App\Documents;

use App\Contracts\EmailableDocument;
use App\Enums\DocumentEmailKind;
use App\Mail\OrderDocumentMail;
use App\Models\Customer;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;

/**
 * Adapter that lets the price-free dispatch docket ride the same send-and-log
 * path as the finance documents, so "what did we send this customer?" has one
 * answer rather than two.
 *
 * The docket has no stored identity of its own — it is always a live render of
 * the order — so markEmailed() has nothing to stamp; the document_emails row IS
 * the record.
 */
class OrderDocket implements EmailableDocument
{
    public function __construct(public Order $order) {}

    public function documentKind(): DocumentEmailKind
    {
        return DocumentEmailKind::Docket;
    }

    public function documentCustomer(): ?Customer
    {
        return $this->order->customer;
    }

    public function documentSubject(): string
    {
        return config('invoicing.seller.name').' — Dispatch docket '.$this->order->order_number;
    }

    public function documentFilename(): string
    {
        return 'docket-'.$this->order->order_number.'.pdf';
    }

    public function documentPdfData(): string
    {
        return Pdf::loadView('orders.docket', ['order' => $this->order, 'pdf' => true])->output();
    }

    public function documentMailable(): Mailable
    {
        return new OrderDocumentMail($this->order, 'docket');
    }

    public function documentRecipient(): ?string
    {
        // The docket goes to the delivery contact, not the billing address.
        $email = trim((string) $this->order->customer?->email);

        return $email !== '' ? $email : null;
    }

    public function markEmailed(Carbon $at): void
    {
        // Nothing to stamp — the log row is the record.
    }
}
