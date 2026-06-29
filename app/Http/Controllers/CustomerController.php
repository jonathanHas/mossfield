<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:255',
            'address' => 'required|string',
            'city' => 'required|string|max:255',
            'postal_code' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'credit_limit' => 'required|numeric|min:0',
            'payment_terms' => 'required|in:immediate,net_7,net_14,net_30',
            'is_active' => 'boolean',
            'requires_reference' => 'boolean',
            'notes' => 'nullable|string',
            'mossorders_user_id' => 'nullable|integer|unique:customers,mossorders_user_id',
        ]);

        $validated['is_active'] = $request->has('is_active');
        $validated['requires_reference'] = $request->has('requires_reference');

        $customer = Customer::create($validated);

        $redirect = redirect()->route('customers.index')
            ->with('success', 'Customer created successfully.');

        return $this->withDuplicateEmailWarning($redirect, $customer);
    }

    public function show(Customer $customer): View
    {
        $customer->load(['orders' => function ($query) {
            $query->orderBy('order_date', 'desc');
        }]);

        return view('customers.show', compact('customer'));
    }

    public function edit(Customer $customer): View
    {
        return view('customers.edit', compact('customer'));
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:255',
            'address' => 'required|string',
            'city' => 'required|string|max:255',
            'postal_code' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'credit_limit' => 'required|numeric|min:0',
            'payment_terms' => 'required|in:immediate,net_7,net_14,net_30',
            'is_active' => 'boolean',
            'requires_reference' => 'boolean',
            'notes' => 'nullable|string',
            'mossorders_user_id' => 'nullable|integer|unique:customers,mossorders_user_id,'.$customer->id,
        ]);

        $validated['is_active'] = $request->has('is_active');
        $validated['requires_reference'] = $request->has('requires_reference');

        $customer->update($validated);

        $redirect = redirect()->route('customers.show', $customer)
            ->with('success', 'Customer updated successfully.');

        return $this->withDuplicateEmailWarning($redirect, $customer);
    }

    /**
     * Attach a non-blocking warning when other customers share this customer's email.
     * Duplicate emails are allowed (e.g. a supplier's separate shops invoice to one address);
     * the warning just makes reuse a deliberate choice rather than an accident.
     */
    private function withDuplicateEmailWarning(RedirectResponse $redirect, Customer $customer): RedirectResponse
    {
        $dupes = Customer::where('email', $customer->email)
            ->where('id', '!=', $customer->id)
            ->pluck('name');

        if ($dupes->isNotEmpty()) {
            $redirect->with('warning', 'Heads up: this email is also used by '.$dupes->implode(', ').'.');
        }

        return $redirect;
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        // Check if customer has orders
        if ($customer->orders()->count() > 0) {
            return redirect()->route('customers.index')
                ->with('error', 'Cannot delete customer with existing orders. Please set customer to inactive instead.');
        }

        $customer->delete();

        return redirect()->route('customers.index')
            ->with('success', 'Customer deleted successfully.');
    }
}
