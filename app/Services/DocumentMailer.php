<?php

namespace App\Services;

use App\Contracts\EmailableDocument;
use App\Enums\DocumentEmailKind;
use App\Enums\DocumentEmailStatus;
use App\Jobs\SendDocumentEmail;
use App\Models\DocumentEmail;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The single door every outbound customer document goes through.
 *
 * Two paths, one log: sendNow() sends inline so a button press gets an
 * immediate success/failure flash (matching the old OrderController::
 * emailDocument behaviour), while queue() writes the row first and lets
 * App\Jobs\SendDocumentEmail do the sending — used by batch runs where waiting
 * on N SMTP round-trips in a request is not an option.
 *
 * Either way a document_emails row exists before anything is attempted, so a
 * crash mid-send can never leave a silent gap in the audit trail.
 */
class DocumentMailer
{
    /**
     * Send immediately and record the outcome.
     *
     * @throws InvoicingException when there's no address to send to
     */
    public function sendNow(
        EmailableDocument $document,
        ?User $by = null,
        ?string $to = null,
        ?Mailable $mailable = null,
        ?DocumentEmailKind $kind = null,
        ?string $batchId = null,
    ): DocumentEmail {
        $recipient = $this->resolveRecipient($document, $to);

        $row = $this->log($document, $recipient, $by, $kind, $batchId);

        $this->deliver($row, $document, $mailable);

        return $row->refresh();
    }

    /**
     * Record the intent, then hand the send to the queue.
     */
    public function queue(
        EmailableDocument $document,
        ?User $by = null,
        ?string $to = null,
        ?DocumentEmailKind $kind = null,
        ?string $batchId = null,
    ): DocumentEmail {
        $recipient = $this->resolveRecipient($document, $to);

        $row = $this->log($document, $recipient, $by, $kind, $batchId);

        SendDocumentEmail::dispatch($row->id);

        return $row;
    }

    /**
     * Perform the send for an existing log row and stamp the result. Shared by
     * sendNow() and the queued job so success/failure is recorded identically.
     */
    public function deliver(DocumentEmail $row, EmailableDocument $document, ?Mailable $mailable = null): bool
    {
        try {
            Mail::to($row->recipient)->send($mailable ?? $document->documentMailable());
        } catch (\Throwable $e) {
            $row->forceFill([
                'status' => DocumentEmailStatus::Failed,
                'failed_at' => now(),
                'error' => \Illuminate\Support\Str::limit($e->getMessage(), 2000),
            ])->save();

            Log::channel('sync')->error('document email failed', [
                'document_email' => $row->id,
                'kind' => $row->kind->value,
                'recipient' => $row->recipient,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $sentAt = now();

        $row->forceFill([
            'status' => DocumentEmailStatus::Sent,
            'sent_at' => $sentAt,
            'failed_at' => null,
            'error' => null,
        ])->save();

        // Only stamp the document when what went out WAS the document. A
        // reminder carries the invoice as an attachment but is not the invoice
        // being sent, and marking emailed_at would drop it out of the "not yet
        // emailed" queue without the customer ever having been invoiced.
        if ($row->kind === $document->documentKind()) {
            $document->markEmailed($sentAt);
        }

        return true;
    }

    private function log(
        EmailableDocument $document,
        string $recipient,
        ?User $by,
        ?DocumentEmailKind $kind,
        ?string $batchId,
    ): DocumentEmail {
        $kind ??= $document->documentKind();

        return DocumentEmail::create([
            'documentable_type' => $document instanceof \Illuminate\Database\Eloquent\Model
                ? $document->getMorphClass()
                : \App\Models\Order::class,
            'documentable_id' => $document instanceof \Illuminate\Database\Eloquent\Model
                ? $document->getKey()
                : $document->order->id,
            'kind' => $kind,
            'customer_id' => $document->documentCustomer()?->id,
            'recipient' => $recipient,
            'subject' => $kind === DocumentEmailKind::Reminder
                ? config('invoicing.seller.name').' — Payment reminder'
                : $document->documentSubject(),
            'status' => DocumentEmailStatus::Queued,
            'batch_id' => $batchId,
            'sent_by' => $by?->id,
            'queued_at' => now(),
        ]);
    }

    private function resolveRecipient(EmailableDocument $document, ?string $to): string
    {
        $recipient = trim((string) ($to ?: $document->documentRecipient()));

        if ($recipient === '') {
            throw new InvoicingException('This customer has no email address on file.');
        }

        return $recipient;
    }
}
