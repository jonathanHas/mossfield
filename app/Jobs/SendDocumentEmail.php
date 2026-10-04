<?php

namespace App\Jobs;

use App\Contracts\EmailableDocument;
use App\Documents\DispatchConfirmation;
use App\Documents\OrderDocket;
use App\Enums\DocumentEmailKind;
use App\Enums\DocumentEmailStatus;
use App\Mail\OverdueReminderMail;
use App\Models\DocumentEmail;
use App\Models\Order;
use App\Services\DocumentMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one already-logged document email. Takes the log row's id rather than
 * the document so a retry can never produce a second, untracked send.
 */
class SendDocumentEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public int $documentEmailId) {}

    public function handle(DocumentMailer $mailer): void
    {
        $row = DocumentEmail::find($this->documentEmailId);

        // Already sent (a retry after a timeout that actually delivered), or
        // the row was cleaned up — either way, don't send again.
        if (! $row || $row->status === DocumentEmailStatus::Sent) {
            return;
        }

        $document = $this->resolveDocument($row);

        if (! $document) {
            $row->forceFill([
                'status' => DocumentEmailStatus::Failed,
                'failed_at' => now(),
                'error' => 'The document no longer exists.',
            ])->save();

            return;
        }

        $mailable = $row->kind === DocumentEmailKind::Reminder
            ? new OverdueReminderMail($document)
            : null;

        if (! $mailer->deliver($row, $document, $mailable)) {
            // Surface the failure to the queue so `tries`/`backoff` apply; the
            // row is already marked failed, and a later success overwrites it.
            throw new \RuntimeException('Sending '.$row->kind->value.' to '.$row->recipient.' failed.');
        }
    }

    public function failed(\Throwable $e): void
    {
        $row = DocumentEmail::find($this->documentEmailId);

        if (! $row || $row->status === DocumentEmailStatus::Sent) {
            return;
        }

        $row->forceFill([
            'status' => DocumentEmailStatus::Failed,
            'failed_at' => $row->failed_at ?? now(),
            // Keep whatever deliver() already recorded: that is the real cause
            // ("SMTP refused"), where $e is only this job's wrapper around it.
            // Overwriting it would leave the operator reading "sending failed".
            'error' => $row->error ?: \Illuminate\Support\Str::limit($e->getMessage(), 2000),
        ])->save();
    }

    private function resolveDocument(DocumentEmail $row): ?EmailableDocument
    {
        $model = $row->documentable;

        if ($model instanceof EmailableDocument) {
            return $model;
        }

        // Dockets and dispatch confirmations are both logged against the
        // order; the row's kind says which adapter to rebuild.
        if ($model instanceof Order) {
            return match ($row->kind) {
                DocumentEmailKind::Docket => new OrderDocket($model),
                DocumentEmailKind::DispatchConfirmation => new DispatchConfirmation($model),
                default => null,
            };
        }

        return null;
    }
}
