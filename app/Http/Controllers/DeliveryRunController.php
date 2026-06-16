<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\DeliveryRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Delivery run management (office/admin): define the weekly runs and assign
 * customers as ordered stops. The factory-facing read view is the chilled run
 * sheet (ChilledRunController).
 */
class DeliveryRunController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', DeliveryRun::class);

        $runs = DeliveryRun::with('customers')
            ->withCount('customers')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $unassignedCustomers = Customer::whereNull('delivery_run_id')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('delivery-runs.index', compact('runs', 'unassignedCustomers'));
    }

    public function create(): View
    {
        $this->authorize('create', DeliveryRun::class);

        return view('delivery-runs.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', DeliveryRun::class);

        $validated = $this->validateRun($request);

        DeliveryRun::create($validated);

        return redirect()->route('delivery-runs.index')
            ->with('success', "Delivery run \"{$validated['name']}\" created.");
    }

    public function edit(DeliveryRun $deliveryRun): View
    {
        $this->authorize('update', $deliveryRun);

        return view('delivery-runs.edit', ['run' => $deliveryRun]);
    }

    public function update(Request $request, DeliveryRun $deliveryRun): RedirectResponse
    {
        $this->authorize('update', $deliveryRun);

        $deliveryRun->update($this->validateRun($request));

        return redirect()->route('delivery-runs.index')
            ->with('success', "Delivery run \"{$deliveryRun->name}\" updated.");
    }

    public function destroy(DeliveryRun $deliveryRun): RedirectResponse
    {
        $this->authorize('delete', $deliveryRun);

        // FK is nullOnDelete — customers are un-assigned, not deleted.
        $name = $deliveryRun->name;
        $deliveryRun->delete();

        return redirect()->route('delivery-runs.index')
            ->with('success', "Delivery run \"{$name}\" deleted — its customers are now unassigned.");
    }

    /**
     * Add a customer as the last stop on a run.
     */
    public function assign(Request $request, DeliveryRun $deliveryRun): RedirectResponse
    {
        $this->authorize('update', $deliveryRun);

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
        ]);

        $customer = Customer::findOrFail($validated['customer_id']);

        if ($customer->delivery_run_id !== null) {
            return redirect()->route('delivery-runs.index')
                ->with('error', "{$customer->name} is already assigned to a run.");
        }

        $customer->update([
            'delivery_run_id' => $deliveryRun->id,
            'run_position' => ((int) $deliveryRun->customers()->max('run_position')) + 1,
        ]);

        return redirect()->route('delivery-runs.index')
            ->with('success', "{$customer->name} added to {$deliveryRun->name}.");
    }

    /**
     * Save a new stop order — an array of customer ids in the wanted order.
     */
    public function reorder(Request $request, DeliveryRun $deliveryRun): RedirectResponse
    {
        $this->authorize('update', $deliveryRun);

        $validated = $request->validate([
            'positions' => 'required|array',
            'positions.*' => 'integer|exists:customers,id',
        ]);

        DB::transaction(function () use ($deliveryRun, $validated) {
            foreach (array_values($validated['positions']) as $index => $customerId) {
                Customer::where('id', $customerId)
                    ->where('delivery_run_id', $deliveryRun->id)
                    ->update(['run_position' => $index + 1]);
            }
        });

        return redirect()->route('delivery-runs.index')
            ->with('success', "Stop order updated for {$deliveryRun->name}.");
    }

    /**
     * Remove a customer from their run.
     */
    public function unassign(Customer $customer): RedirectResponse
    {
        $run = $customer->deliveryRun;

        if (! $run) {
            return redirect()->route('delivery-runs.index')
                ->with('error', "{$customer->name} is not assigned to a run.");
        }

        $this->authorize('update', $run);

        $customer->update(['delivery_run_id' => null, 'run_position' => null]);

        return redirect()->route('delivery-runs.index')
            ->with('success', "{$customer->name} removed from {$run->name}.");
    }

    private function validateRun(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'day_of_week' => 'nullable|integer|between:1,7',
            'driver' => 'nullable|string|max:255',
            'capacity_note' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);
        $validated['is_active'] = $request->boolean('is_active');

        return $validated;
    }
}
