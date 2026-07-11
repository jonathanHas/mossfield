<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerSpecialPriceRequest;
use App\Models\Customer;
use App\Models\CustomerSpecialPrice;
use Illuminate\Http\RedirectResponse;

/**
 * Manage a customer's per-variant alternative prices from the customer show
 * page. Office/admin only — access is enforced by the route group (matching
 * CustomerController and ProductVariantController, which likewise skip
 * in-method authorize() calls).
 */
class CustomerSpecialPriceController extends Controller
{
    public function store(CustomerSpecialPriceRequest $request, Customer $customer): RedirectResponse
    {
        $customer->specialPrices()->create($request->validated());

        return redirect()->route('customers.show', $customer)
            ->with('success', 'Special price added.');
    }

    public function update(CustomerSpecialPriceRequest $request, Customer $customer, CustomerSpecialPrice $specialPrice): RedirectResponse
    {
        abort_if($specialPrice->customer_id !== $customer->id, 404);

        $specialPrice->update($request->validated());

        return redirect()->route('customers.show', $customer)
            ->with('success', 'Special price updated.');
    }

    public function destroy(Customer $customer, CustomerSpecialPrice $specialPrice): RedirectResponse
    {
        abort_if($specialPrice->customer_id !== $customer->id, 404);

        $specialPrice->delete();

        return redirect()->route('customers.show', $customer)
            ->with('success', 'Special price removed.');
    }
}
