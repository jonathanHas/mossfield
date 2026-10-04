<?php

namespace App\Http\Controllers\Invoicing;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\InvoiceService;
use App\Services\InvoicingException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The "awaiting invoice" queue — the exceptions, now that dispatch issues
 * invoices itself (App\Services\DispatchService).
 *
 * What lands here: an order dispatched from the sheet or its own page whose
 * auto-issue failed; consolidated (weekly/monthly) customers' deliveries, which
 * wait for the period run; and anything picked to `ready` but not yet
 * dispatched. Issuing from here is the manual safety net, not the normal path.
 */
class UninvoicedOrderController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    /** Orders picked and shipped but not yet on an invoice. */
    public static function uninvoicedQuery(): Builder
    {
        return Order::query()
            ->whereNull('invoice_id')
            ->whereIn('status', ['ready', 'dispatched', 'delivered']);
    }

    public function index(): View
    {
        $this->authorize('viewAny', Invoice::class);

        $orders = self::uninvoicedQuery()
            ->with('customer')
            ->orderBy('delivery_date')
            ->orderBy('id')
            ->get();

        // Split by how the customer is billed: per-order customers can be
        // invoiced right here, consolidated ones wait for their period run.
        [$consolidated, $perOrder] = $orders->partition(
            fn (Order $order) => (bool) $order->customer?->isConsolidatedBilling()
        );

        return view('invoicing.uninvoiced.index', [
            'perOrder' => $perOrder->values(),
            'consolidated' => $consolidated->groupBy('customer_id'),
        ]);
    }

    public function issue(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('create', Invoice::class);

        try {
            $invoice = $this->invoices->issueForOrder($order, $request->user());
        } catch (InvoicingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('invoicing.invoices.show', $invoice)
            ->with('success', 'Invoice '.$invoice->number.' issued for order '.$order->order_number.'.');
    }

    /**
     * Bill every per-order customer's outstanding delivery in one go.
     * Consolidated customers are skipped by design and named in the flash.
     */
    public function issueAll(Request $request): RedirectResponse
    {
        $this->authorize('create', Invoice::class);

        $orders = self::uninvoicedQuery()->with('customer')->orderBy('id')->get()
            ->reject(fn (Order $order) => (bool) $order->customer?->isConsolidatedBilling());

        $issued = 0;
        $failures = [];

        foreach ($orders as $order) {
            try {
                $this->invoices->issueForOrder($order, $request->user());
                $issued++;
            } catch (InvoicingException $e) {
                $failures[] = $order->order_number;
            }
        }

        if ($issued === 0) {
            return back()->with('error', 'Nothing to invoice.'.
                ($failures ? ' Skipped: '.implode(', ', $failures).'.' : ''));
        }

        $message = $issued.' '.\Illuminate\Support\Str::plural('invoice', $issued).' issued.';

        if ($failures) {
            $message .= ' Skipped '.implode(', ', $failures).'.';
        }

        return redirect()->route('invoicing.invoices.index')->with('success', $message);
    }
}
