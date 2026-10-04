<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\DocumentMailer;
use App\Services\InvoicingException;
use App\Services\StatementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The unattended month-end run. Same code path as the batch screen, so a
 * scheduled run and a hand-driven one produce identical document_emails rows
 * and can be inspected on the same page.
 *
 * Off unless INVOICING_AUTO_STATEMENTS=true — nobody wants statements going out
 * on their own until they've watched the manual run work once.
 */
class SendMonthlyStatements extends Command
{
    protected $signature = 'invoicing:send-statements
                            {--mode=balance : balance|activity — who to include}
                            {--dry-run : List the recipients without generating or sending}';

    protected $description = 'Generate and queue month-end statements for customers with a balance';

    public function handle(StatementService $statements, DocumentMailer $mailer): int
    {
        // The run covers the month that just ended.
        $from = today()->subMonth()->startOfMonth();
        $to = today()->subMonth()->endOfMonth();
        $mode = (string) $this->option('mode');

        $candidates = $statements->candidatesForBatch($from, $to, $mode);

        if ($candidates->isEmpty()) {
            $this->info('No customers need a statement for '.$from->format('F Y').'.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['Customer', 'Send to', 'Balance'],
                $candidates->map(fn (Customer $c) => [
                    $c->name, $c->billingEmail(), number_format($c->outstanding_balance, 2),
                ])->all()
            );

            return self::SUCCESS;
        }

        $batchId = (string) Str::uuid();
        $queued = 0;
        $skipped = [];

        foreach ($candidates as $customer) {
            $statement = $statements->generate($customer, $from, $to, null, $batchId);

            try {
                $mailer->queue($statement, null, batchId: $batchId);
                $queued++;
            } catch (InvoicingException $e) {
                $skipped[] = $customer->name;
            }
        }

        $message = "invoicing:send-statements queued {$queued} statements for "
            .$from->format('F Y')." (batch {$batchId})"
            .($skipped ? '; skipped '.implode(', ', $skipped) : '');

        $this->info($message);
        Log::channel('sync')->info($message);

        return self::SUCCESS;
    }
}
