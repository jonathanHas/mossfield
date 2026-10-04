<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Statement;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Builds customer statements: the running account of invoices, credit notes and
 * payments over a period, plus the aged breakdown of what's still open.
 *
 * A generated statement stores its ledger as JSON. Payments can later be
 * corrected or deleted, so re-deriving from live data would not reliably
 * reproduce what was emailed — the PDF regenerates from the snapshot instead.
 */
class StatementService
{
    /**
     * The account movement for a period, with a running balance.
     *
     * @return array{opening_balance: float, closing_balance: float, lines: array, aged: array}
     */
    public function ledger(Customer $customer, Carbon $from, Carbon $to): array
    {
        $opening = $this->balanceBefore($customer, $from);

        $lines = collect();

        $invoices = $customer->invoices()->issued()
            ->whereBetween('issued_at', [$from->toDateString(), $to->toDateString()])
            ->orderBy('issued_at')->orderBy('id')->get();

        foreach ($invoices as $invoice) {
            $lines->push([
                'date' => $invoice->issued_at->toDateString(),
                'type' => 'invoice',
                'reference' => $invoice->number,
                'description' => $invoice->isConsolidated() && $invoice->period_start
                    ? 'Invoice — deliveries '.$invoice->period_start->format('j M').' to '.$invoice->period_end->format('j M')
                    : 'Invoice',
                'debit' => round((float) $invoice->total, 2),
                'credit' => 0.0,
                'sort' => 0,
            ]);
        }

        $creditNotes = $customer->creditNotes()->issued()
            ->whereBetween('issued_at', [$from->toDateString(), $to->toDateString()])
            ->orderBy('issued_at')->orderBy('id')->get();

        foreach ($creditNotes as $note) {
            $lines->push([
                'date' => $note->issued_at->toDateString(),
                'type' => 'credit_note',
                'reference' => $note->number,
                'description' => 'Credit note — '.$note->reason,
                'debit' => 0.0,
                'credit' => round((float) $note->total, 2),
                'sort' => 1,
            ]);
        }

        $payments = $customer->payments()
            ->whereBetween('received_at', [$from->toDateString(), $to->toDateString()])
            ->orderBy('received_at')->orderBy('id')->get();

        foreach ($payments as $payment) {
            $lines->push([
                'date' => $payment->received_at->toDateString(),
                'type' => 'payment',
                'reference' => $payment->reference ?: $payment->method_label,
                'description' => 'Payment received — '.$payment->method_label,
                'debit' => 0.0,
                'credit' => round((float) $payment->amount, 2),
                'sort' => 2,
            ]);
        }

        $ordered = $lines->sortBy([['date', 'asc'], ['sort', 'asc'], ['reference', 'asc']])->values();

        $running = $opening;
        $withBalance = $ordered->map(function (array $line) use (&$running) {
            $running = round($running + $line['debit'] - $line['credit'], 2);
            $line['balance'] = $running;

            return $line;
        })->all();

        return [
            'opening_balance' => round($opening, 2),
            'closing_balance' => round($running, 2),
            'lines' => $withBalance,
            'aged' => $this->agedBalances($customer, $to),
        ];
    }

    /**
     * The customer's account balance immediately before a date: everything
     * invoiced, less everything credited and paid.
     */
    public function balanceBefore(Customer $customer, Carbon $date): float
    {
        $invoiced = (float) $customer->invoices()->issued()
            ->whereDate('issued_at', '<', $date->toDateString())->sum('total');

        $credited = (float) $customer->creditNotes()->issued()
            ->whereDate('issued_at', '<', $date->toDateString())->sum('total');

        $paid = (float) $customer->payments()
            ->whereDate('received_at', '<', $date->toDateString())->sum('amount');

        return round($invoiced - $credited - $paid, 2);
    }

    /**
     * Open invoice balances bucketed by how far past due they are, plus any
     * credit sitting on account. "current" is everything not yet due.
     */
    public function agedBalances(Customer $customer, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ? $asOf->copy()->startOfDay() : today();

        $buckets = [
            'current' => 0.0,
            'days_1_30' => 0.0,
            'days_31_60' => 0.0,
            'days_61_90' => 0.0,
            'days_90_plus' => 0.0,
        ];

        foreach ($customer->invoices()->open()->get() as $invoice) {
            $balance = (float) $invoice->balance;
            $daysOverdue = $invoice->due_date
                ? (int) $invoice->due_date->startOfDay()->diffInDays($asOf, false)
                : 0;

            $key = match (true) {
                $daysOverdue <= 0 => 'current',
                $daysOverdue <= 30 => 'days_1_30',
                $daysOverdue <= 60 => 'days_31_60',
                $daysOverdue <= 90 => 'days_61_90',
                default => 'days_90_plus',
            };

            $buckets[$key] = round($buckets[$key] + $balance, 2);
        }

        $buckets['credit'] = $customer->unallocatedCredit();
        $buckets['total'] = round(array_sum([
            $buckets['current'], $buckets['days_1_30'], $buckets['days_31_60'],
            $buckets['days_61_90'], $buckets['days_90_plus'],
        ]) - $buckets['credit'], 2);

        return $buckets;
    }

    /** Build and persist a statement snapshot. */
    public function generate(
        Customer $customer,
        Carbon $from,
        Carbon $to,
        ?User $by = null,
        ?string $batchId = null,
    ): Statement {
        $ledger = $this->ledger($customer, $from, $to);

        return Statement::create([
            'customer_id' => $customer->id,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'opening_balance' => $ledger['opening_balance'],
            'closing_balance' => $ledger['closing_balance'],
            'aged' => $ledger['aged'],
            'lines' => $ledger['lines'],
            'generated_at' => now(),
            'generated_by' => $by?->id,
            'batch_id' => $batchId,
        ]);
    }

    /**
     * Customers a month-end run should send to.
     *
     * - "balance": anyone who owes (or is owed) money.
     * - "activity": anyone with movement in the period, even if they settled.
     *
     * Inactive customers, opt-outs and anyone with no billing address are
     * excluded either way.
     *
     * @return Collection<int, Customer>
     */
    public function candidatesForBatch(Carbon $from, Carbon $to, string $mode = 'balance'): Collection
    {
        $customers = Customer::query()
            ->where('is_active', true)
            ->where('send_statements', true)
            ->orderBy('name')
            ->get();

        return $customers->filter(function (Customer $customer) use ($from, $to, $mode) {
            if (! $customer->billingEmail()) {
                return false;
            }

            if ($mode === 'activity') {
                $ledger = $this->ledger($customer, $from, $to);

                return ! empty($ledger['lines']);
            }

            return abs($customer->outstanding_balance) > 0.001;
        })->values();
    }

    public function pdf(Statement $statement): \Barryvdh\DomPDF\PDF
    {
        $statement->loadMissing('customer');

        return Pdf::loadView('invoicing.statements.pdf', [
            'statement' => $statement,
            'pdf' => true,
        ]);
    }
}
