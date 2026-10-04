<?php

namespace App\Http\Controllers\Invoicing;

use App\Enums\DocumentEmailKind;
use App\Http\Controllers\Controller;
use App\Mail\OverdueReminderMail;
use App\Models\Invoice;
use App\Services\DocumentMailer;
use App\Services\InvoicingException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The debtors screen: who owes what, oldest first, with a one-click reminder.
 */
class OverdueController extends Controller
{
    public function __construct(private DocumentMailer $mailer) {}

    public function index(): View
    {
        $this->authorize('viewAny', Invoice::class);

        $invoices = Invoice::overdue()
            ->with('customer')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        return view('invoicing.overdue.index', [
            'invoices' => $invoices,
            'total' => round((float) $invoices->sum('balance'), 2),
            // Grouped so a customer with five late invoices reads as one debt.
            'byCustomer' => $invoices->groupBy('customer_id'),
        ]);
    }

    /**
     * Remind on every overdue invoice that qualifies. Invoices already reminded
     * today are skipped so a double-click doesn't send twice.
     */
    public function remindAll(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $invoices = Invoice::overdue()->with('customer')->orderBy('due_date')->get()
            ->filter(fn (Invoice $invoice) => $invoice->canBeReminded())
            ->reject(fn (Invoice $invoice) => $invoice->last_reminded_at?->isToday());

        $sent = 0;
        $failed = [];

        foreach ($invoices as $invoice) {
            try {
                $row = $this->mailer->sendNow(
                    $invoice,
                    $request->user(),
                    mailable: new OverdueReminderMail($invoice),
                    kind: DocumentEmailKind::Reminder,
                );

                if ($row->status->value === 'sent') {
                    $invoice->forceFill(['last_reminded_at' => now()])->save();
                    $sent++;
                } else {
                    $failed[] = $invoice->number;
                }
            } catch (InvoicingException $e) {
                $failed[] = $invoice->number;
            }
        }

        if ($sent === 0) {
            return back()->with('error', 'No reminders sent.'.
                ($failed ? ' Could not send: '.implode(', ', $failed).'.' : ' Nothing qualifies right now.'));
        }

        $message = $sent.' '.\Illuminate\Support\Str::plural('reminder', $sent).' sent.';

        if ($failed) {
            $message .= ' Failed: '.implode(', ', $failed).'.';
        }

        return back()->with('success', $message);
    }
}
