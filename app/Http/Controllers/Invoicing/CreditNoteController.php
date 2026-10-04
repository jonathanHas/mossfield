<?php

namespace App\Http\Controllers\Invoicing;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\CreditNoteService;
use App\Services\DocumentMailer;
use App\Services\InvoicingException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CreditNoteController extends Controller
{
    public function __construct(
        private CreditNoteService $creditNotes,
        private DocumentMailer $mailer,
    ) {}

    public function create(Invoice $invoice): View
    {
        $this->authorize('create', CreditNote::class);

        $invoice->load(['lines', 'customer']);

        return view('invoicing.credit-notes.create', [
            'invoice' => $invoice,
            'suggested' => $this->creditNotes->suggestLinesFor($invoice),
            'creditable' => round((float) $invoice->total - (float) $invoice->amount_credited, 2),
        ]);
    }

    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('create', CreditNote::class);

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
            'lines' => 'required|array|min:1',
            'lines.*.invoice_line_id' => 'nullable|integer|exists:invoice_lines,id',
            'lines.*.description' => 'required_with:lines.*.amount|nullable|string|max:255',
            'lines.*.quantity' => 'nullable|numeric|min:0',
            'lines.*.amount' => 'nullable|numeric|min:0',
            'lines.*.vat_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            $creditNote = $this->creditNotes->issue(
                $invoice,
                $validated['lines'],
                $validated['reason'],
                $request->user(),
            );
        } catch (InvoicingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('invoicing.credit-notes.show', $creditNote)
            ->with('success', 'Credit note '.$creditNote->number.' issued for €'.number_format((float) $creditNote->total, 2).'.');
    }

    public function show(CreditNote $creditNote): View
    {
        $this->authorize('view', $creditNote);

        $creditNote->load(['lines', 'invoice', 'customer', 'emails.sender']);

        return view('invoicing.credit-notes.show', ['creditNote' => $creditNote]);
    }

    public function pdf(Request $request, CreditNote $creditNote)
    {
        $this->authorize('view', $creditNote);
        abort_unless($request->user()->can('see-financials'), 403);

        $pdf = $this->creditNotes->pdf($creditNote);

        if ($request->boolean('download')) {
            return $pdf->download($creditNote->documentFilename());
        }

        return $pdf->stream($creditNote->documentFilename());
    }

    public function email(CreditNote $creditNote): RedirectResponse
    {
        $this->authorize('view', $creditNote);

        try {
            $row = $this->mailer->sendNow($creditNote, request()->user());
        } catch (InvoicingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $row->status->value === 'sent'
            ? back()->with('success', 'Credit note '.$creditNote->number.' emailed to '.$row->recipient.'.')
            : back()->with('error', 'Could not send the email. Please try again or check the mail settings.');
    }

    public function void(Request $request, CreditNote $creditNote): RedirectResponse
    {
        $this->authorize('void', $creditNote);

        $validated = $request->validate(['void_reason' => 'required|string|max:255']);

        try {
            $this->creditNotes->void($creditNote, $validated['void_reason'], $request->user());
        } catch (InvoicingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Credit note '.$creditNote->number.' voided; the invoice balance is restored.');
    }
}
