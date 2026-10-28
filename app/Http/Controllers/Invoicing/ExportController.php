<?php

namespace App\Http\Controllers\Invoicing;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV extracts for the accountant. Streamed rather than built in memory so a
 * full year doesn't blow the request limit.
 */
class ExportController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Invoice::class);

        return view('invoicing.export.index', [
            'from' => today()->subMonth()->startOfMonth(),
            'to' => today()->subMonth()->endOfMonth(),
        ]);
    }

    public function download(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Invoice::class);
        abort_unless($request->user()->can('see-financials'), 403);

        $validated = $request->validate([
            'type' => 'required|in:invoices,payments',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->startOfDay();

        $filename = $validated['type'].'-'.$from->format('Y-m-d').'-to-'.$to->format('Y-m-d').'.csv';

        $rows = $validated['type'] === 'invoices'
            ? $this->invoiceRows($from, $to)
            : $this->paymentRows($from, $to);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Invoices and credit notes in one sheet. Credit notes carry NEGATIVE
     * amounts so a column sum is the period's net turnover; void invoices are
     * kept as rows with zero amounts so the sum is still the net turnover.
     */
    private function invoiceRows(Carbon $from, Carbon $to): \Generator
    {
        yield ['Date', 'Type', 'Number', 'Customer', 'VAT number', 'Net', 'VAT', 'Gross', 'Paid', 'Credited', 'Balance', 'Status', 'Due date'];

        $invoices = Invoice::with('customer')
            ->whereBetween('issued_at', [$from->toDateString(), $to->toDateString()])
            ->orderBy('issued_at')->orderBy('id')->get();

        foreach ($invoices as $invoice) {
            $net = round((float) $invoice->subtotal + (float) $invoice->delivery_charge_net, 2);
            $money = fn (float $amount) => number_format($invoice->status === InvoiceStatus::Void ? 0 : $amount, 2, '.', '');

            yield [
                $invoice->issued_at?->format('Y-m-d'),
                'Invoice',
                $invoice->number,
                $invoice->bill_to_name,
                $invoice->bill_to_vat_number,
                $money($net),
                $money((float) $invoice->tax_amount),
                $money((float) $invoice->total),
                $money((float) $invoice->amount_paid),
                $money((float) $invoice->amount_credited),
                $money((float) $invoice->balance),
                $invoice->status->value,
                $invoice->due_date?->format('Y-m-d'),
            ];
        }

        $creditNotes = CreditNote::with(['customer', 'invoice'])
            ->whereBetween('issued_at', [$from->toDateString(), $to->toDateString()])
            ->orderBy('issued_at')->orderBy('id')->get();

        foreach ($creditNotes as $note) {
            yield [
                $note->issued_at?->format('Y-m-d'),
                'Credit note',
                $note->number,
                $note->customer?->name,
                $note->invoice?->bill_to_vat_number,
                number_format(-1 * (float) $note->subtotal, 2, '.', ''),
                number_format(-1 * (float) $note->tax_amount, 2, '.', ''),
                number_format(-1 * (float) $note->total, 2, '.', ''),
                '0.00',
                '0.00',
                '0.00',
                $note->status,
                '',
            ];
        }
    }

    private function paymentRows(Carbon $from, Carbon $to): \Generator
    {
        yield ['Date', 'Customer', 'Amount', 'Method', 'Reference', 'Allocated', 'Unallocated', 'Invoices', 'Recorded by'];

        $payments = Payment::with(['customer', 'allocations.invoice', 'recorder'])
            ->whereBetween('received_at', [$from->toDateString(), $to->toDateString()])
            ->orderBy('received_at')->orderBy('id')->get();

        foreach ($payments as $payment) {
            yield [
                $payment->received_at?->format('Y-m-d'),
                $payment->customer?->name,
                number_format((float) $payment->amount, 2, '.', ''),
                $payment->method_label,
                $payment->reference,
                number_format($payment->allocated_amount, 2, '.', ''),
                number_format($payment->unallocated_amount, 2, '.', ''),
                $payment->allocations->map(fn ($a) => $a->invoice?->number)->filter()->implode(' '),
                $payment->recorder?->name,
            ];
        }
    }
}
