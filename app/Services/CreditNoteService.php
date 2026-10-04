<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

/**
 * Raises credit against an issued invoice — returns, breakages, price
 * corrections. A credit note reduces invoices.amount_credited directly rather
 * than being allocated like a payment: it is always tied to exactly one
 * invoice, so a second allocation table would only be a second place to check.
 */
class CreditNoteService
{
    /**
     * @param  array<int, array{invoice_line_id?: int|null, description: string, quantity?: float, amount: float, vat_rate?: float}>  $lines
     *
     * @throws InvoicingException when the invoice isn't creditable or the total overshoots
     */
    public function issue(Invoice $invoice, array $lines, string $reason, ?User $by = null): CreditNote
    {
        return DB::transaction(function () use ($invoice, $lines, $reason, $by) {
            $locked = Invoice::whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isIssued()) {
                throw new InvoicingException('A credit note can only be raised against an issued invoice.');
            }

            $prepared = $this->prepareLines($lines);

            if (! $prepared) {
                throw new InvoicingException('Add at least one line to the credit note.');
            }

            $subtotal = round(array_sum(array_column($prepared, 'amount')), 2);
            $tax = round(array_sum(array_column($prepared, 'vat_amount')), 2);
            $total = round($subtotal + $tax, 2);

            if ($total <= 0) {
                throw new InvoicingException('A credit note must be greater than zero.');
            }

            $remaining = round((float) $locked->total - (float) $locked->amount_credited, 2);

            if ($total > $remaining + 0.001) {
                throw new InvoicingException(
                    'Credit of €'.number_format($total, 2).' exceeds the €'.
                    number_format($remaining, 2).' still creditable on invoice '.$locked->number.'.'
                );
            }

            $creditNote = CreditNote::create([
                'number' => DocumentSequence::format('CN', DocumentSequence::next(DocumentSequence::CREDIT_NOTE)),
                'invoice_id' => $locked->id,
                'customer_id' => $locked->customer_id,
                'status' => 'issued',
                'reason' => $reason,
                'issued_at' => today()->toDateString(),
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $total,
                'created_by' => $by?->id,
            ]);

            foreach ($prepared as $line) {
                $creditNote->lines()->create($line);
            }

            $locked->refreshBalances();

            // Crediting can leave an invoice paid beyond what it now owes; the
            // surplus goes back to the customer as credit on account rather
            // than vanishing into a negative balance.
            app(PaymentService::class)->releaseOverAllocation($locked);

            return $creditNote->fresh(['lines', 'invoice', 'customer']);
        });
    }

    public function void(CreditNote $creditNote, string $reason, ?User $by = null): CreditNote
    {
        return DB::transaction(function () use ($creditNote, $reason) {
            $locked = CreditNote::whereKey($creditNote->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isIssued()) {
                throw new InvoicingException('This credit note is already void.');
            }

            $locked->forceFill([
                'status' => 'void',
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            $locked->invoice?->refreshBalances();

            return $locked->fresh();
        });
    }

    /**
     * Default credit lines for the UI: the invoice as it stands, ready to be
     * trimmed down to what's actually being credited.
     */
    public function suggestLinesFor(Invoice $invoice): array
    {
        return $invoice->lines->map(fn ($line) => [
            'invoice_line_id' => $line->id,
            'description' => $line->description,
            'quantity' => (float) $line->quantity,
            'amount' => (float) $line->amount,
            'vat_rate' => (float) $line->vat_rate,
        ])->all();
    }

    public function pdf(CreditNote $creditNote): \Barryvdh\DomPDF\PDF
    {
        $creditNote->loadMissing(['lines', 'invoice', 'customer']);

        return Pdf::loadView('invoicing.credit-notes.pdf', [
            'creditNote' => $creditNote,
            'pdf' => true,
        ]);
    }

    /** Normalise posted rows, dropping empties and deriving VAT per line. */
    private function prepareLines(array $lines): array
    {
        $prepared = [];
        $sort = 0;

        foreach ($lines as $line) {
            $amount = round((float) ($line['amount'] ?? 0), 2);

            if ($amount <= 0) {
                continue;
            }

            $rate = round((float) ($line['vat_rate'] ?? 0), 2);

            $prepared[] = [
                'invoice_line_id' => $line['invoice_line_id'] ?? null,
                'description' => trim((string) ($line['description'] ?? 'Credit')),
                'quantity' => (float) ($line['quantity'] ?? 0),
                'amount' => $amount,
                'vat_rate' => $rate,
                'vat_amount' => round($amount * $rate / 100, 2),
                'sort' => $sort++,
            ];
        }

        return $prepared;
    }
}
