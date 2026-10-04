<?php

namespace App\Http\Controllers\Invoicing;

use App\Enums\DocumentEmailKind;
use App\Http\Controllers\Controller;
use App\Mail\OverdueReminderMail;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\DocumentMailer;
use App\Services\InvoiceService;
use App\Services\InvoicingException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The invoice list and detail screens, plus the per-invoice actions (email,
 * remind, void). Issuing lives in UninvoicedOrderController /
 * ConsolidatedInvoiceController — the two places an invoice is actually born.
 */
class InvoiceController extends Controller
{
    public function __construct(
        private InvoiceService $invoices,
        private DocumentMailer $mailer,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Invoice::class);

        $query = Invoice::query()->with('customer')->latest('issued_at')->latest('id');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            // Void invoices are noise on the working list; ?status=void finds them.
            $query->issued();
        }

        // "Not yet emailed" is the queue this screen exists for, so it gets a
        // first-class filter rather than being buried in a status dropdown.
        if ($request->query('emailed') === '0') {
            $query->notEmailed();
        } elseif ($request->query('emailed') === '1') {
            $query->whereNotNull('emailed_at');
        }

        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        if ($request->filled('payment_state')) {
            match ($request->payment_state) {
                'paid' => $query->where('balance', '<=', 0),
                'unpaid' => $query->where('balance', '>', 0)->where('amount_paid', 0)->where('amount_credited', 0),
                'partial' => $query->where('balance', '>', 0)->where(fn ($q) => $q->where('amount_paid', '>', 0)->orWhere('amount_credited', '>', 0)),
                'overdue' => $query->overdue(),
                default => null,
            };
        }

        $invoices = $query->paginate(30)->withQueryString();

        return view('invoicing.invoices.index', [
            'invoices' => $invoices,
            'filters' => $request->only(['customer_id', 'status', 'emailed', 'overdue', 'payment_state']),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'stats' => $this->headlineStats(),
        ]);
    }

    public function show(Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        $invoice->load([
            'customer', 'lines.order', 'creditNotes', 'emails.sender',
            'allocations.payment',
        ]);

        return view('invoicing.invoices.show', [
            'invoice' => $invoice,
            'orders' => Order::whereIn('id', $invoice->lines->pluck('order_id')->filter()->unique())
                ->get()->keyBy('id'),
        ]);
    }

    public function pdf(Request $request, Invoice $invoice)
    {
        $this->authorize('view', $invoice);
        abort_unless($request->user()->can('see-financials'), 403);

        $pdf = $this->invoices->pdf($invoice);

        if ($request->boolean('download')) {
            return $pdf->download($invoice->documentFilename());
        }

        return $pdf->stream($invoice->documentFilename());
    }

    public function email(Invoice $invoice): RedirectResponse
    {
        $this->authorize('email', $invoice);

        try {
            $row = $this->mailer->sendNow($invoice, request()->user());
        } catch (InvoicingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $row->status->value === 'sent'
            ? back()->with('success', 'Invoice '.$invoice->number.' emailed to '.$row->recipient.'.')
            : back()->with('error', 'Could not send the email. Please try again or check the mail settings.');
    }

    public function remind(Invoice $invoice): RedirectResponse
    {
        $this->authorize('remind', $invoice);

        try {
            $row = $this->mailer->sendNow(
                $invoice,
                request()->user(),
                mailable: new OverdueReminderMail($invoice),
                kind: DocumentEmailKind::Reminder,
            );
        } catch (InvoicingException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($row->status->value !== 'sent') {
            return back()->with('error', 'Could not send the reminder. Please try again or check the mail settings.');
        }

        // A reminder must not count as "the invoice was sent", so stamp its own
        // column rather than emailed_at.
        $invoice->forceFill(['last_reminded_at' => now()])->save();

        return back()->with('success', 'Reminder for '.$invoice->number.' sent to '.$row->recipient.'.');
    }

    public function void(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('void', $invoice);

        $validated = $request->validate([
            'void_reason' => 'required|string|max:255',
        ]);

        try {
            $this->invoices->void($invoice, $validated['void_reason'], $request->user());
        } catch (InvoicingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('invoicing.invoices.show', $invoice)
            ->with('success', 'Invoice '.$invoice->number.' voided. Its orders are back in the uninvoiced queue.');
    }

    /** Shared with the dashboard tiles: the three numbers that mean "act now". */
    public static function headlineStats(): array
    {
        return [
            'not_emailed' => Invoice::notEmailed()->count(),
            'uninvoiced' => UninvoicedOrderController::uninvoicedQuery()->count(),
            'overdue_count' => Invoice::overdue()->count(),
            'overdue_total' => round((float) Invoice::overdue()->sum('balance'), 2),
        ];
    }
}
