<?php

namespace App\Documents;

use App\Contracts\EmailableDocument;
use App\Enums\DocumentEmailKind;
use App\Mail\DispatchConfirmationMail;
use App\Models\Customer;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;

/**
 * The priced "what went on the van" document emailed automatically at
 * dispatch. It shows quantities, weights and value so the shop can check the
 * delivery against the money straight away, but carries no invoice number —
 * the numbered invoice follows from Finance once the office sends it.
 *
 * Like OrderDocket this is a live render of the order, not a stored document,
 * so markEmailed() has nothing to stamp: the document_emails row is the record.
 *
 * NB: `public Order $order` must stay public — DocumentMailer::log() reads it
 * to key the log row against the order for non-Model documents.
 */
class DispatchConfirmation implements EmailableDocument
{
    public function __construct(public Order $order) {}

    public function documentKind(): DocumentEmailKind
    {
        return DocumentEmailKind::DispatchConfirmation;
    }

    public function documentCustomer(): ?Customer
    {
        return $this->order->customer;
    }

    public function documentSubject(): string
    {
        return config('invoicing.seller.name').' — Dispatch confirmation '.$this->order->order_number;
    }

    public function documentFilename(): string
    {
        return 'dispatch-confirmation-'.$this->order->order_number.'.pdf';
    }

    public function documentPdfData(): string
    {
        $this->order->loadMissing([
            'customer',
            'orderItems.productVariant.product',
            'orderItems.orderAllocations.batchItem.batch',
        ]);

        return Pdf::loadView('orders.dispatch-confirmation', ['order' => $this->order, 'pdf' => true])->output();
    }

    public function documentMailable(): Mailable
    {
        return new DispatchConfirmationMail($this);
    }

    public function documentRecipient(): ?string
    {
        // It carries prices, so it goes where the invoice goes.
        return $this->order->customer?->billingEmail();
    }

    public function markEmailed(Carbon $at): void
    {
        // Nothing to stamp — the log row is the record.
    }
}
