<?php

namespace App\Services;

use App\Enums\DocumentEmailStatus;
use App\Models\DocumentEmail;
use App\Models\Invoice;
use App\Models\Order;

/**
 * What happened when an order was dispatched. Dispatch itself always succeeded
 * if you are holding one of these; the invoice and the confirmation email are
 * each reported separately because either can fail without undoing dispatch.
 */
final class DispatchResult
{
    public function __construct(
        public readonly Order $order,
        public ?Invoice $invoice = null,
        /** True only when THIS dispatch minted the invoice (not a pre-existing one). */
        public bool $invoiceIssued = false,
        public ?string $invoiceError = null,
        public ?DocumentEmail $emailRow = null,
        /** Why no send was attempted (typically: no email address on file). */
        public ?string $emailSkippedReason = null,
    ) {}

    public function emailSent(): bool
    {
        return $this->emailRow?->status === DocumentEmailStatus::Sent;
    }

    public function emailQueued(): bool
    {
        return $this->emailRow?->status === DocumentEmailStatus::Queued;
    }

    public function emailFailed(): bool
    {
        return $this->emailRow?->status === DocumentEmailStatus::Failed;
    }
}
