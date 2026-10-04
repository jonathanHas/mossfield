<?php

namespace App\Http\Controllers\Invoicing;

use App\Enums\DocumentEmailKind;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DocumentEmail;
use App\Models\Statement;
use App\Services\DocumentMailer;
use App\Services\InvoicingException;
use App\Services\StatementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The month-end run: generate a statement for every customer who needs one and
 * queue the emails.
 *
 * Queued, not inline — a run can be dozens of SMTP round-trips, which would
 * time the request out. Every recipient gets a document_emails row up front, so
 * the batch screen can show progress and retry the failures individually.
 */
class StatementBatchController extends Controller
{
    public function __construct(
        private StatementService $statements,
        private DocumentMailer $mailer,
    ) {}

    public function create(Request $request): View
    {
        $this->authorize('create', Statement::class);

        [$from, $to] = $this->resolvePeriod($request);
        $mode = $request->query('mode', 'balance');

        $candidates = $this->statements->candidatesForBatch($from, $to, $mode);

        // Named separately so the operator can see who is being skipped for
        // want of an address, rather than silently missing them.
        $missingEmail = Customer::where('is_active', true)
            ->where('send_statements', true)
            ->get()
            ->filter(fn (Customer $c) => ! $c->billingEmail())
            ->values();

        return view('invoicing.statements.batch-create', [
            'candidates' => $candidates,
            'missingEmail' => $missingEmail,
            'from' => $from,
            'to' => $to,
            'mode' => $mode,
            'recentBatches' => $this->recentBatches(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Statement::class);

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'customer_ids' => 'required|array|min:1',
            'customer_ids.*' => 'integer|exists:customers,id',
        ]);

        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->startOfDay();
        $batchId = (string) Str::uuid();

        $queued = 0;
        $skipped = [];

        foreach (Customer::whereIn('id', $validated['customer_ids'])->orderBy('name')->get() as $customer) {
            $statement = $this->statements->generate($customer, $from, $to, $request->user(), $batchId);

            try {
                $this->mailer->queue($statement, $request->user(), batchId: $batchId);
                $queued++;
            } catch (InvoicingException $e) {
                $skipped[] = $customer->name;
            }
        }

        if ($queued === 0) {
            return back()->with('error', 'No statements were queued.'.
                ($skipped ? ' No email address for: '.implode(', ', $skipped).'.' : ''));
        }

        $message = $queued.' '.Str::plural('statement', $queued).' queued for sending.';

        if ($skipped) {
            $message .= ' Skipped (no email address): '.implode(', ', $skipped).'.';
        }

        return redirect()->route('invoicing.statements.batch.show', $batchId)->with('success', $message);
    }

    public function show(string $batch): View
    {
        $this->authorize('viewAny', Statement::class);

        $rows = DocumentEmail::where('batch_id', $batch)
            ->with(['customer', 'sender'])
            ->orderBy('id')
            ->get();

        abort_if($rows->isEmpty(), 404);

        return view('invoicing.statements.batch-show', [
            'batch' => $batch,
            'rows' => $rows,
            'statements' => Statement::where('batch_id', $batch)->get()->keyBy('id'),
        ]);
    }

    /** Re-queue just the failures from a batch. */
    public function retry(Request $request, string $batch): RedirectResponse
    {
        $this->authorize('create', Statement::class);

        $failed = DocumentEmail::where('batch_id', $batch)->failed()->get();

        if ($failed->isEmpty()) {
            return back()->with('error', 'Nothing to retry — no failed sends in this batch.');
        }

        foreach ($failed as $row) {
            $row->forceFill([
                'status' => \App\Enums\DocumentEmailStatus::Queued,
                'failed_at' => null,
                'error' => null,
                'queued_at' => now(),
            ])->save();

            \App\Jobs\SendDocumentEmail::dispatch($row->id);
        }

        return back()->with('success', $failed->count().' '.Str::plural('send', $failed->count()).' re-queued.');
    }

    /** Batch ids with a count and the newest timestamp, for the run history. */
    private function recentBatches()
    {
        return DocumentEmail::query()
            ->whereNotNull('batch_id')
            ->where('kind', DocumentEmailKind::Statement->value)
            ->selectRaw('batch_id, COUNT(*) as total, MAX(created_at) as sent_at')
            ->groupBy('batch_id')
            ->orderByDesc('sent_at')
            ->limit(10)
            ->get();
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

        return [today()->subMonth()->startOfMonth(), today()->subMonth()->endOfMonth()];
    }
}
