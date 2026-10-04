<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\DeliveryRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        return redirect()->route('delivery-runs.index')->withFragment('stop-'.$customer->id)
            ->with('success', "{$customer->name} added to {$deliveryRun->name}.");
    }

    /**
     * Change the stop order. Two shapes, exactly one per request:
     *  - positions: [customer_id => wanted position] — the run panel's
     *    Position boxes + Save order;
     *  - move: {customer_id, direction: up|down} — the ↑/↓ buttons, a swap
     *    with the neighbour.
     * Either way the run is renumbered 1…n and the page lands on the stop
     * that moved (F-15).
     */
    public function reorder(Request $request, DeliveryRun $deliveryRun): RedirectResponse
    {
        $this->authorize('update', $deliveryRun);

        $validated = $request->validate([
            'positions' => 'required_without:move|prohibits:move|array',
            'positions.*' => 'required|integer|min:1',
            'move' => 'required_without:positions|array',
            'move.customer_id' => 'required_with:move|integer',
            'move.direction' => 'required_with:move|in:up,down',
        ]);

        $stops = $deliveryRun->customers()->get()->values();
        $onRun = $stops->pluck('id')->all();

        $posted = isset($validated['positions'])
            ? array_map('intval', array_keys($validated['positions']))
            : [(int) $validated['move']['customer_id']];
        if (array_diff($posted, $onRun)) {
            return redirect()->route('delivery-runs.index')
                ->with('error', 'That stop is not on '.$deliveryRun->name.' — reload the page and try again.');
        }

        $ordered = isset($validated['positions'])
            ? $this->orderByWantedPosition($stops, $validated['positions'])
            : $this->swapWithNeighbour($stops, (int) $validated['move']['customer_id'], $validated['move']['direction']);

        // Land on the stop the operator moved: the ↑/↓ one, or the first
        // whose Position box was changed (in its new place). Falls back to
        // the first stop whose number changed.
        if (isset($validated['move'])) {
            $landOn = (int) $validated['move']['customer_id'];
        } else {
            $current = $stops->mapWithKeys(fn ($stop, $index) => [$stop->id => $index + 1]);
            $landOn = $ordered->first(fn ($stop) => isset($validated['positions'][$stop->id])
                    && (int) $validated['positions'][$stop->id] !== $current[$stop->id])?->id
                ?? $ordered->first(fn ($stop, $index) => (int) $stop->run_position !== $index + 1)?->id;
        }

        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $index => $stop) {
                if ((int) $stop->run_position !== $index + 1) {
                    $stop->update(['run_position' => $index + 1]);
                }
            }
        });

        $redirect = redirect()->route('delivery-runs.index');
        if ($landOn) {
            $redirect = $redirect->withFragment('stop-'.$landOn);
        }

        return $redirect->with('success', "Stop order updated for {$deliveryRun->name}.");
    }

    /**
     * A stop given a new position is taken out and put back in at that
     * position; everyone else keeps their relative order and closes up
     * around it. So "17" on a 17-stop run means last, and "1" means first.
     */
    private function orderByWantedPosition(Collection $stops, array $positions): Collection
    {
        $current = $stops->mapWithKeys(fn ($stop, $index) => [$stop->id => $index + 1]);

        $moved = $stops
            ->filter(fn ($stop) => isset($positions[$stop->id]) && (int) $positions[$stop->id] !== $current[$stop->id])
            ->sortBy([
                fn ($a, $b) => (int) $positions[$a->id] <=> (int) $positions[$b->id],
                fn ($a, $b) => $current[$a->id] <=> $current[$b->id],
            ]);

        $result = $stops->reject(fn ($stop) => $moved->contains('id', $stop->id))->values()->all();
        foreach ($moved as $stop) {
            $at = min(max((int) $positions[$stop->id], 1) - 1, count($result));
            array_splice($result, $at, 0, [$stop]);
        }

        return collect($result);
    }

    private function swapWithNeighbour(Collection $stops, int $customerId, string $direction): Collection
    {
        $list = $stops->all();
        $index = $stops->search(fn ($stop) => $stop->id === $customerId);
        $other = $direction === 'up' ? $index - 1 : $index + 1;

        if ($other >= 0 && $other < count($list)) {
            [$list[$index], $list[$other]] = [$list[$other], $list[$index]];
        }

        return collect($list);
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

        // The stop row is gone; land on the run it left.
        return redirect()->route('delivery-runs.index')->withFragment('run-'.$run->id)
            ->with('success', "{$customer->name} removed from {$run->name}.");
    }

    /**
     * Toggle whether this stop's new orders get the run's delivery charge.
     * Redirects with ?charges=1 so the reveal stays open for consecutive
     * flags (a fresh visit always starts hidden).
     */
    public function toggleCharge(Customer $customer): RedirectResponse
    {
        $run = $customer->deliveryRun;

        if (! $run) {
            return redirect()->route('delivery-runs.index')
                ->with('error', "{$customer->name} is not assigned to a run.");
        }

        $this->authorize('update', $run);

        $customer->update(['apply_delivery_charge' => ! $customer->apply_delivery_charge]);

        return redirect()->route('delivery-runs.index', ['charges' => 1])->withFragment('stop-'.$customer->id)
            ->with('success', $customer->apply_delivery_charge
                ? "{$customer->name} will now get the {$run->name} delivery charge on new orders."
                : "{$customer->name} will no longer get a delivery charge.");
    }

    private function validateRun(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'day_of_week' => 'nullable|integer|between:1,7',
            'driver' => 'nullable|string|max:255',
            'capacity_note' => 'nullable|string|max:255',
            'delivery_charge' => 'nullable|numeric|min:0|max:999999.99',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $validated['delivery_charge'] = round((float) ($validated['delivery_charge'] ?? 0), 2);
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);
        $validated['is_active'] = $request->boolean('is_active');

        return $validated;
    }
}
