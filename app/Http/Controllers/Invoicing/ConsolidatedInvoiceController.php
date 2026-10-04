<?php

namespace App\Http\Controllers\Invoicing;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\InvoicingException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The period run for customers who get one invoice covering a week or a month
 * of deliveries rather than one per drop.
 *
 * Preview is deliberately a separate, unsaved render: it consumes no invoice
 * number, so an operator can look at a period as often as they like without
 * burning sequence values.
 */
class ConsolidatedInvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    public function create(Request $request): View
    {
        $this->authorize('viewAny', Invoice::class);

        [$start, $end, $period] = $this->resolvePeriod($request);

        $customers = $this->invoices->consolidatedCandidates($start, $end);

        // Build each candidate's preview so the operator sees the money before
        // committing, not after.
        $previews = $customers->mapWithKeys(fn (Customer $customer) => [
            $customer->id => $this->invoices->previewConsolidated($customer, $start, $end),
        ]);

        return view('invoicing.consolidated.create', [
            'customers' => $customers,
            'previews' => $previews,
            'start' => $start,
            'end' => $end,
            'period' => $period,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Invoice::class);

        $validated = $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
            'customer_ids' => 'required|array|min:1',
            'customer_ids.*' => 'integer|exists:customers,id',
        ]);

        $start = Carbon::parse($validated['start'])->startOfDay();
        $end = Carbon::parse($validated['end'])->startOfDay();

        $issued = [];
        $skipped = [];

        foreach (Customer::whereIn('id', $validated['customer_ids'])->get() as $customer) {
            try {
                $invoice = $this->invoices->issueConsolidated($customer, $start, $end, $request->user());
                $issued[] = $invoice->number;
            } catch (InvoicingException $e) {
                $skipped[] = $customer->name;
            }
        }

        if (! $issued) {
            return back()->with('error', 'Nothing was invoiced.'.
                ($skipped ? ' No deliveries in that period for: '.implode(', ', $skipped).'.' : ''));
        }

        $message = count($issued).' consolidated '.
            \Illuminate\Support\Str::plural('invoice', count($issued)).' issued ('.implode(', ', $issued).').';

        if ($skipped) {
            $message .= ' Skipped '.implode(', ', $skipped).'.';
        }

        return redirect()->route('invoicing.invoices.index')->with('success', $message);
    }

    /**
     * The period being billed. Defaults to last month — a month-end run is the
     * common case, and a weekly run just narrows the dates.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function resolvePeriod(Request $request): array
    {
        $period = $request->query('period', 'last_month');

        if ($request->filled('start') && $request->filled('end')) {
            return [
                Carbon::parse($request->query('start'))->startOfDay(),
                Carbon::parse($request->query('end'))->startOfDay(),
                'custom',
            ];
        }

        return match ($period) {
            'this_month' => [today()->startOfMonth(), today()->endOfMonth(), 'this_month'],
            'last_week' => [today()->subWeek()->startOfWeek(), today()->subWeek()->endOfWeek(), 'last_week'],
            'this_week' => [today()->startOfWeek(), today()->endOfWeek(), 'this_week'],
            default => [today()->subMonth()->startOfMonth(), today()->subMonth()->endOfMonth(), 'last_month'],
        };
    }
}
