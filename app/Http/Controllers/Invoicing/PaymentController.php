<?php

namespace App\Http\Controllers\Invoicing;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\InvoicingException;
use App\Services\PaymentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Recording money in and applying it to invoices.
 *
 * The form is server-driven in two steps (?customer_id= reloads with that
 * customer's open invoices) because the allocation table has to be built from
 * real balances, and the same pattern is already used by the chilled-run row
 * editor.
 */
class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Payment::class);

        $query = Payment::query()->with(['customer', 'allocations'])
            ->latest('received_at')->latest('id');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        return view('invoicing.payments.index', [
            'payments' => $query->paginate(30)->withQueryString(),
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('customer_id'),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Payment::class);

        $customer = $request->filled('customer_id')
            ? Customer::find($request->customer_id)
            : null;

        $openInvoices = $customer
            ? $customer->invoices()->open()->orderBy('due_date')->orderBy('id')->get()
            : collect();

        return view('invoicing.payments.create', [
            'customer' => $customer,
            'customers' => Customer::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'openInvoices' => $openInvoices,
            'methods' => PaymentMethod::selectable(),
            // One per rendered form: a repeat POST of this form is recognised
            // and redirected instead of recording the money twice.
            'formToken' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Payment::class);

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'amount' => 'required|numeric|min:0.01',
            'form_token' => 'nullable|uuid',
            'received_at' => 'required|date',
            'method' => 'required|in:'.implode(',', array_column(PaymentMethod::cases(), 'value')),
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'allocations' => 'nullable|array',
            'allocations.*' => 'nullable|numeric|min:0',
        ], [
            'amount.min' => 'A payment must be more than zero. Record refunds as credit notes.',
        ]);

        if ($repeat = $this->repeatSubmission($validated['form_token'] ?? null)) {
            return $repeat;
        }

        $customer = Customer::findOrFail($validated['customer_id']);

        try {
            $payment = $this->payments->record(
                $customer,
                $validated,
                $validated['allocations'] ?? [],
                $request->user(),
            );
        } catch (UniqueConstraintViolationException) {
            // Two near-simultaneous posts of the same form: the second insert
            // hits the unique form_token index.
            return $this->repeatSubmission($validated['form_token'] ?? null)
                ?? back()->withInput()->with('error', 'This payment could not be recorded. Please try again.');
        } catch (InvoicingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $spare = $payment->unallocated_amount;

        $message = 'Payment of €'.number_format((float) $payment->amount, 2).' recorded for '.$customer->name.'.';

        if ($spare > 0) {
            $message .= ' €'.number_format($spare, 2).' is on account as credit.';
        }

        return redirect()->route('invoicing.payments.show', $payment)->with('success', $message);
    }

    /** The payment this form already recorded, as a redirect — or null. */
    private function repeatSubmission(?string $formToken): ?RedirectResponse
    {
        if (! $formToken) {
            return null;
        }

        $existing = Payment::where('form_token', $formToken)->first();

        return $existing
            ? redirect()->route('invoicing.payments.show', $existing)
                ->with('success', 'Payment already recorded — this was a repeat submission.')
            : null;
    }

    public function show(Payment $payment): View
    {
        $this->authorize('view', $payment);

        $payment->load(['customer', 'allocations.invoice', 'recorder']);

        return view('invoicing.payments.show', ['payment' => $payment]);
    }

    public function destroy(Payment $payment): RedirectResponse
    {
        $this->authorize('delete', $payment);

        $customer = $payment->customer;
        $amount = (float) $payment->amount;

        $this->payments->delete($payment);

        return redirect()->route('invoicing.payments.index')
            ->with('success', 'Payment of €'.number_format($amount, 2).' for '.$customer->name.' removed; invoice balances restored.');
    }

    /** Spend a customer's credit on account against their oldest open invoices. */
    public function applyCredit(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('create', Payment::class);

        $applied = $this->payments->allocateCredit($customer, $request->user());

        if ($applied <= 0) {
            return back()->with('error', 'Nothing to apply — there is no credit on account, or no open invoices.');
        }

        return back()->with('success', '€'.number_format($applied, 2).' of credit applied to open invoices.');
    }
}
