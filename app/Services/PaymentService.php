<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records money received and applies it to invoices.
 *
 * A payment belongs to the customer, not an invoice: shops pay lump sums that
 * cover several invoices, or pay against a statement. Anything not applied
 * stays as credit on account and can be spent later — which is why the invoice
 * payment state is always derived from allocations rather than typed by hand.
 */
class PaymentService
{
    /**
     * @param  array<int|string, float|string|null>  $allocations  invoice id => amount
     *
     * @throws InvoicingException on over-allocation or a foreign/settled invoice
     */
    public function record(Customer $customer, array $data, array $allocations, ?User $by = null): Payment
    {
        return DB::transaction(function () use ($customer, $data, $allocations, $by) {
            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw new InvoicingException('A payment must be greater than zero.');
            }

            $payment = Payment::create([
                'customer_id' => $customer->id,
                'amount' => $amount,
                'received_at' => $data['received_at'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $by?->id,
                'form_token' => $data['form_token'] ?? null,
            ]);

            $this->applyAllocations($payment, $allocations);

            return $payment->fresh(['allocations']);
        });
    }

    /**
     * Apply (or top up) allocations for an existing payment. Invoices are locked
     * so two operators can't both allocate the last euro of the same balance.
     *
     * @param  array<int|string, float|string|null>  $allocations  invoice id => amount
     *
     * @throws InvoicingException
     */
    public function applyAllocations(Payment $payment, array $allocations): void
    {
        DB::transaction(function () use ($payment, $allocations) {
            $requested = 0.0;

            foreach ($allocations as $invoiceId => $raw) {
                $amount = round((float) $raw, 2);

                if ($amount <= 0) {
                    continue;
                }

                $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->first();

                if (! $invoice || $invoice->customer_id !== $payment->customer_id) {
                    throw new InvoicingException('That invoice does not belong to this customer.');
                }

                if (! $invoice->isIssued()) {
                    throw new InvoicingException('Invoice '.$invoice->number.' is void and cannot take a payment.');
                }

                $existing = (float) PaymentAllocation::where('payment_id', $payment->id)
                    ->where('invoice_id', $invoice->id)
                    ->value('amount');

                // The invoice's own balance already nets off this payment's
                // existing row, so add it back before checking headroom.
                $headroom = round((float) $invoice->balance + $existing, 2);

                if ($amount > $headroom) {
                    throw new InvoicingException(
                        'Cannot apply €'.number_format($amount, 2).' to invoice '.$invoice->number.
                        ' — only €'.number_format($headroom, 2).' is outstanding.'
                    );
                }

                $requested += $amount - $existing;

                PaymentAllocation::updateOrCreate(
                    ['payment_id' => $payment->id, 'invoice_id' => $invoice->id],
                    ['amount' => $amount],
                );

                $invoice->refreshBalances();
            }

            $payment->refresh();

            if ($payment->allocated_amount > (float) $payment->amount + 0.001) {
                throw new InvoicingException(
                    'Allocated €'.number_format($payment->allocated_amount, 2).
                    ' is more than the payment of €'.number_format((float) $payment->amount, 2).'.'
                );
            }
        });
    }

    /**
     * What a payment of $amount should settle: oldest due date first. Used to
     * prefill the allocation form and by allocateCredit().
     *
     * @return array<int, float> invoice id => amount
     */
    public function suggestAllocations(Customer $customer, float $amount): array
    {
        $remaining = round($amount, 2);
        $suggested = [];

        $invoices = $customer->invoices()->open()
            ->orderBy('due_date')->orderBy('id')->get();

        foreach ($invoices as $invoice) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (float) $invoice->balance);

            if ($take > 0) {
                $suggested[$invoice->id] = round($take, 2);
                $remaining = round($remaining - $take, 2);
            }
        }

        return $suggested;
    }

    /**
     * Spend a customer's unallocated credit against their open invoices,
     * oldest first. Returns the total applied.
     */
    public function allocateCredit(Customer $customer, ?User $by = null): float
    {
        return DB::transaction(function () use ($customer) {
            $applied = 0.0;

            $payments = $customer->payments()->orderBy('received_at')->orderBy('id')->get();

            foreach ($payments as $payment) {
                $spare = $payment->unallocated_amount;

                if ($spare <= 0) {
                    continue;
                }

                $suggested = $this->suggestAllocations($customer, $spare);

                if (! $suggested) {
                    break; // Nothing open left to pay.
                }

                // Merge with what this payment already covers, so updateOrCreate
                // widens rather than replaces.
                $merged = [];
                foreach ($suggested as $invoiceId => $amount) {
                    $existing = (float) PaymentAllocation::where('payment_id', $payment->id)
                        ->where('invoice_id', $invoiceId)
                        ->value('amount');
                    $merged[$invoiceId] = round($existing + $amount, 2);
                    $applied += $amount;
                }

                $this->applyAllocations($payment, $merged);
            }

            return round($applied, 2);
        });
    }

    /**
     * Remove a payment entirely, restoring the balances it was settling.
     * There is deliberately no edit: correcting a payment means deleting and
     * re-recording it, which keeps the audit honest.
     */
    public function delete(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $invoiceIds = $payment->allocations()->pluck('invoice_id')->all();

            $payment->allocations()->delete();
            $payment->delete();

            foreach (Invoice::whereIn('id', $invoiceIds)->get() as $invoice) {
                $invoice->refreshBalances();
            }
        });
    }

    /**
     * Trim allocations on an invoice that a credit note has just over-settled,
     * newest first. The released money returns to the payment as credit on
     * account rather than disappearing.
     */
    public function releaseOverAllocation(Invoice $invoice): float
    {
        return DB::transaction(function () use ($invoice) {
            $invoice->refresh();

            $payable = round(max(0, (float) $invoice->total - (float) $invoice->amount_credited), 2);
            $excess = round((float) $invoice->amount_paid - $payable, 2);

            if ($excess <= 0) {
                return 0.0;
            }

            $released = 0.0;

            $allocations = $invoice->allocations()->orderByDesc('id')->get();

            foreach ($allocations as $allocation) {
                if ($excess <= 0) {
                    break;
                }

                $take = min($excess, (float) $allocation->amount);
                $remaining = round((float) $allocation->amount - $take, 2);

                if ($remaining > 0) {
                    $allocation->update(['amount' => $remaining]);
                } else {
                    $allocation->delete();
                }

                $excess = round($excess - $take, 2);
                $released += $take;
            }

            $invoice->refreshBalances();

            return round($released, 2);
        });
    }
}
