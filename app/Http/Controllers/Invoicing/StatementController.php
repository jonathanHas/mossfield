<?php

namespace App\Http\Controllers\Invoicing;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Statement;
use App\Services\DocumentMailer;
use App\Services\InvoicingException;
use App\Services\StatementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Per-customer statements. Preview renders the ledger live so the operator can
 * scrub the dates; only "Generate" persists a snapshot, and only a persisted
 * snapshot can be emailed — so what was sent is always reproducible.
 */
class StatementController extends Controller
{
    public function __construct(
        private StatementService $statements,
        private DocumentMailer $mailer,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Statement::class);

        $query = Statement::query()->with('customer')->latest('period_end')->latest('id');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        return view('invoicing.statements.index', [
            'statements' => $query->paginate(30)->withQueryString(),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('customer_id'),
        ]);
    }

    public function preview(Request $request, Customer $customer): View
    {
        $this->authorize('viewAny', Statement::class);

        [$from, $to] = $this->resolvePeriod($request);

        return view('invoicing.statements.preview', [
            'customer' => $customer,
            'ledger' => $this->statements->ledger($customer, $from, $to),
            'from' => $from,
            'to' => $to,
            'history' => $customer->statements()->latest('period_end')->limit(12)->get(),
        ]);
    }

    /** Persist the snapshot, and optionally email it in the same click. */
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('create', Statement::class);

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'send' => 'nullable|boolean',
        ]);

        $statement = $this->statements->generate(
            $customer,
            Carbon::parse($validated['from'])->startOfDay(),
            Carbon::parse($validated['to'])->startOfDay(),
            $request->user(),
        );

        // Land on the new row in the list, where its PDF and email button are.
        $landing = redirect()->route('invoicing.statements.index')->withFragment('statement-'.$statement->id);

        if (! $request->boolean('send')) {
            return $landing->with('success', 'Statement generated for '.$customer->name.'.');
        }

        try {
            $row = $this->mailer->sendNow($statement, $request->user());
        } catch (InvoicingException $e) {
            return $landing->with('error', 'Statement generated, but not sent: '.$e->getMessage());
        }

        return $row->status->value === 'sent'
            ? $landing->with('success', 'Statement emailed to '.$row->recipient.'.')
            : $landing->with('error', 'Statement generated, but the email could not be sent.');
    }

    public function pdf(Request $request, Statement $statement)
    {
        $this->authorize('view', $statement);
        abort_unless($request->user()->can('see-financials'), 403);

        $pdf = $this->statements->pdf($statement);

        if ($request->boolean('download')) {
            return $pdf->download($statement->documentFilename());
        }

        return $pdf->stream($statement->documentFilename());
    }

    public function email(Statement $statement): RedirectResponse
    {
        $this->authorize('view', $statement);

        try {
            $row = $this->mailer->sendNow($statement, request()->user());
        } catch (InvoicingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $row->status->value === 'sent'
            ? back()->with('success', 'Statement emailed to '.$row->recipient.'.')
            : back()->with('error', 'Could not send the statement. Please try again or check the mail settings.');
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolvePeriod(Request $request): array
    {
        if ($request->filled('from') && $request->filled('to')) {
            return [
                Carbon::parse($request->query('from'))->startOfDay(),
                Carbon::parse($request->query('to'))->startOfDay(),
            ];
        }

        // Default to this month to date — what the office asks for when a
        // customer rings (F-38). The month-end batch run picks its own period.
        return [today()->startOfMonth(), today()];
    }
}
