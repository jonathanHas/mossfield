<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceDelivery;
use App\Models\Customer;
use App\Models\ProductVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $query = Customer::withCount('orders');

        // Search by name or email
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by active status
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        // Filter by online account status
        if ($request->filled('has_online_account')) {
            if ($request->has_online_account === '1') {
                $query->whereNotNull('mossorders_user_id');
            } else {
                $query->whereNull('mossorders_user_id');
            }
        }

        $customers = $query->orderBy('name')->paginate(20);

        return view('customers.index', compact('customers'));
    }

    public function create(): View
    {
        return view('customers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->tidyInput($request);
        $validated = $this->normalise($request->validate($this->rules($request), $this->messages()), $request);

        $customer = Customer::create($validated);

        // Straight onto the new customer — special prices, run and account
        // all live on its page (F-16).
        $redirect = redirect()->route('customers.show', $customer)
            ->with('success', 'Customer created successfully.');

        return $this->withDuplicateEmailWarning($redirect, $customer);
    }

    public function show(Customer $customer): View
    {
        $customer->load([
            'orders' => function ($query) {
                $query->orderBy('order_date', 'desc');
            },
            'specialPrices.productVariant.product',
        ]);

        // Active variants (grouped by product) for the "add special price" picker.
        $productVariants = ProductVariant::with('product')
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->groupBy('product.name');

        // Finance panels — only for users who can see money at all.
        $finance = null;
        if (request()->user()?->can('see-financials')) {
            $finance = [
                'aged' => $customer->agedBalances(),
                'credit' => $customer->unallocatedCredit(),
                'openTotal' => round((float) $customer->invoices()->open()->sum('balance'), 2),
                'invoices' => $customer->invoices()->latest('issued_at')->latest('id')->limit(10)->get(),
                'emails' => \App\Models\DocumentEmail::where('customer_id', $customer->id)
                    ->latest('id')->limit(10)->get(),
            ];
        }

        return view('customers.show', compact('customer', 'productVariants', 'finance'));
    }

    public function edit(Customer $customer): View
    {
        return view('customers.edit', compact('customer'));
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->tidyInput($request);
        $validated = $this->normalise($request->validate($this->rules($request, $customer), $this->messages()), $request);

        $customer->update($validated);

        $redirect = redirect()->route('customers.show', $customer)
            ->with('success', 'Customer updated successfully.');

        return $this->withDuplicateEmailWarning($redirect, $customer);
    }

    /**
     * The one rule set for create and edit. Email, city and postal code are
     * optional (D20/D21) — except that an Email-delivery customer needs an
     * email address to be sent anything.
     */
    private function rules(Request $request, ?Customer $customer = null): array
    {
        // An absent select keeps what is stored — the DB default (email) on create.
        // Enum on both sides: the stored value is cast to InvoiceDelivery.
        $delivery = InvoiceDelivery::tryFrom((string) $request->input('invoice_delivery'))
            ?? $customer?->invoice_delivery
            ?? InvoiceDelivery::Email;

        return [
            'name' => 'required|string|max:255',
            'email' => ['nullable', 'email', 'max:255', Rule::requiredIf(fn () => $delivery === InvoiceDelivery::Email)],
            'phone' => 'nullable|string|max:255',
            'address' => 'required|string',
            'city' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:255',
            'country' => 'required|string|max:255',
            'credit_limit' => 'nullable|numeric|min:0',
            'payment_terms' => 'required|in:immediate,net_7,net_14,net_30',
            // Billing preferences — how (and whether) this customer is invoiced.
            'billing_email' => 'nullable|email|max:255',
            'vat_number' => 'nullable|string|max:64',
            // Nullable, not required: these have DB defaults, and older forms
            // (and the chilled-run quick-create path) don't post them.
            'invoice_delivery' => 'nullable|in:email,print,none',
            'invoicing_mode' => 'nullable|in:per_order,weekly,monthly',
            'send_statements' => 'boolean',
            'is_active' => 'boolean',
            'requires_reference' => 'boolean',
            'delivery_charge_percent' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string',
            'mossorders_user_id' => 'nullable|integer|unique:customers,mossorders_user_id'.($customer ? ','.$customer->id : ''),
        ];
    }

    private function messages(): array
    {
        return [
            'email.required' => 'An email address is needed while Invoice delivery is Email — or choose Print / post or No invoice sent.',
        ];
    }

    /**
     * Trim the free-text contact fields and drop trailing commas, so
     * "Kilmantin Hill,," is stored as "Kilmantin Hill" and a blank field is
     * NULL, never ''.
     */
    private function tidyInput(Request $request): void
    {
        $tidied = [];
        foreach (['name', 'email', 'address', 'city', 'postal_code', 'country'] as $field) {
            if (! $request->has($field) || ! is_string($value = $request->input($field))) {
                continue;
            }
            $value = preg_replace('/[\s,]+$/u', '', trim($value));
            $tidied[$field] = $value === '' ? null : $value;
        }

        $request->merge($tidied);
    }

    /**
     * Post-validation coercions shared by store() and update().
     */
    private function normalise(array $validated, Request $request): array
    {
        $validated['is_active'] = $request->has('is_active');
        $validated['requires_reference'] = $request->has('requires_reference');
        $validated['send_statements'] = $request->has('send_statements');

        // Blank means no limit, stored as 0 (the column is NOT NULL).
        $validated['credit_limit'] = filled($validated['credit_limit'] ?? null) ? $validated['credit_limit'] : 0;

        // An absent select must leave the stored preference alone rather than
        // writing null over it.
        foreach (['invoice_delivery', 'invoicing_mode'] as $field) {
            if (blank($validated[$field] ?? null)) {
                unset($validated[$field]);
            }
        }
        $validated['delivery_charge_percent'] = $this->normalizeChargePercent($validated['delivery_charge_percent'] ?? null);

        return $validated;
    }

    /**
     * A blank delivery-charge % means "none" (null); any value is rounded to
     * two decimals. Keeps the column null rather than 0.00 so "no percentage"
     * is unambiguous downstream.
     */
    private function normalizeChargePercent(mixed $value): ?float
    {
        return $value !== null && $value !== '' ? round((float) $value, 2) : null;
    }

    /**
     * Attach a non-blocking warning when other customers share this customer's email.
     * Duplicate emails are allowed (e.g. a supplier's separate shops invoice to one address);
     * the warning just makes reuse a deliberate choice rather than an accident.
     */
    private function withDuplicateEmailWarning(RedirectResponse $redirect, Customer $customer): RedirectResponse
    {
        if (blank($customer->email)) {
            return $redirect;
        }

        $dupes = Customer::where('email', $customer->email)
            ->where('id', '!=', $customer->id)
            ->pluck('name');

        if ($dupes->isNotEmpty()) {
            $redirect->with('warning', 'Heads up: this email is also used by '.$dupes->implode(', ').'.');
        }

        return $redirect;
    }

    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        // Back to the list as it was filtered (the delete form carries the
        // index's search / is_active query).
        $filters = array_filter($request->only('search', 'is_active'), fn ($value) => $value !== null && $value !== '');

        // Check if customer has orders
        if ($customer->orders()->count() > 0) {
            return redirect()->route('customers.index', $filters)
                ->with('error', 'Cannot delete customer with existing orders. Please set customer to inactive instead.');
        }

        $customer->delete();

        return redirect()->route('customers.index', $filters)
            ->with('success', 'Customer deleted successfully.');
    }
}
