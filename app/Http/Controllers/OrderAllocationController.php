<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AllocatesOrderStock;
use App\Http\Controllers\Concerns\BuildsAllocationData;
use App\Http\Controllers\Concerns\ValidatesFulfilmentWeights;
use App\Models\BatchItem;
use App\Models\Order;
use App\Models\OrderAllocation;
use App\Models\OrderItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderAllocationController extends Controller
{
    use AllocatesOrderStock, ValidatesFulfilmentWeights;
    use BuildsAllocationData;

    public function index(Request $request): View
    {
        $query = Order::with(['customer', 'orderItems.productVariant', 'orderItems.orderAllocations'])
            ->whereIn('status', ['confirmed', 'preparing']);

        // Filter by allocation status
        if ($request->filled('allocation_status')) {
            if ($request->allocation_status === 'fully_allocated') {
                $query->whereHas('orderItems', function ($q) {
                    $q->whereRaw('quantity_allocated >= quantity_ordered');
                });
            } elseif ($request->allocation_status === 'partially_allocated') {
                $query->whereHas('orderItems', function ($q) {
                    $q->whereRaw('quantity_allocated < quantity_ordered')
                        ->where('quantity_allocated', '>', 0);
                });
            } elseif ($request->allocation_status === 'unallocated') {
                $query->whereHas('orderItems', function ($q) {
                    $q->where('quantity_allocated', 0);
                });
            }
        }

        $orders = $query->orderBy('order_date', 'asc')->paginate(20);

        return view('order-allocations.index', compact('orders'));
    }

    /**
     * The allocation UI now lives inline on the order detail page; this route
     * is kept so existing bookmarks, the worklist, and the dashboard continue
     * to resolve. Redirect to the unified order view.
     */
    public function show(Order $order): RedirectResponse
    {
        return redirect()->route('orders.show', $order);
    }

    public function allocate(Request $request, OrderItem $orderItem): RedirectResponse
    {
        $validated = $request->validate([
            'batch_item_id' => 'required|exists:batch_items,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $batchItem = BatchItem::findOrFail($validated['batch_item_id']);
        $order = $orderItem->order;

        // Verify the batch item is for the correct product variant
        if ($batchItem->product_variant_id !== $orderItem->product_variant_id) {
            return redirect()->route('orders.show', $order)
                ->withErrors(['batch_item_id' => 'Selected batch item does not match the order item product.']);
        }

        $allocation = $orderItem->allocateFromBatchItem($batchItem, $validated['quantity']);

        if (! $allocation) {
            return redirect()->route('orders.show', $order)
                ->withErrors(['quantity' => 'Cannot allocate this quantity. Check available stock and order limits.']);
        }

        $this->markPickingStarted($order);

        return redirect()->route('orders.show', $order)
            ->with('success', "Allocated {$validated['quantity']} units from batch {$batchItem->batch->batch_code}.");
    }

    public function deallocate(OrderAllocation $allocation): RedirectResponse
    {
        $orderItem = $allocation->orderItem;
        $order = $orderItem->order;

        if ($allocation->quantity_fulfilled > 0) {
            return redirect()->route('orders.show', $order)
                ->withErrors(['allocation' => 'Cannot deallocate - some items have already been fulfilled.']);
        }

        // Update order item allocated quantity
        $allocation->delete();

        $orderItem->quantity_allocated = $orderItem->orderAllocations()->sum('quantity_allocated');
        $orderItem->save();

        // Undoing a pick can drop a "ready" order back to "preparing".
        $order->reconcilePickingStatus();

        return redirect()->route('orders.show', $order)
            ->with('success', 'Allocation removed successfully.');
    }

    public function fulfill(Request $request, OrderAllocation $allocation): RedirectResponse
    {
        $orderItem = $allocation->orderItem;

        $validated = $request->validate(array_merge([
            'quantity' => 'required|integer|min:1|max:'.$allocation->quantity_remaining,
        ], $this->fulfilmentWeightRules($orderItem)), $this->fulfilmentWeightMessages());

        [$actualWeight, $unitWeights] = $this->resolveFulfilmentWeight($validated, $orderItem);
        $order = $orderItem->order;

        $success = $orderItem->fulfillAllocation(
            $allocation,
            $validated['quantity'],
            $actualWeight,
            $unitWeights
        );

        if (! $success) {
            return redirect()->route('orders.show', $order)
                ->withErrors(['quantity' => 'Could not fulfill this quantity.']);
        }

        $message = "Fulfilled {$validated['quantity']} units.";
        if ($actualWeight) {
            $message .= " Total weight: {$actualWeight}kg";
        }

        // Recorded weight may change the line value (weight-priced items), so
        // refresh the order total to the actual fulfilled amount.
        $order->calculateTotals();

        $this->markPickingComplete($order);

        return redirect()->route('orders.show', $order)->with('success', $message);
    }

    public function unfulfill(Request $request, OrderAllocation $allocation): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1|max:'.$allocation->quantity_fulfilled,
        ]);

        $order = $allocation->orderItem->order;

        $success = $allocation->orderItem->unfulfillAllocation(
            $allocation,
            $validated['quantity']
        );

        if (! $success) {
            return redirect()->route('orders.show', $order)
                ->withErrors(['quantity' => 'Could not undo fulfillment for this quantity.']);
        }

        // Reverting a pick changes the fulfilled value back toward the estimate.
        $order->calculateTotals();

        // A no-longer-fully-picked order drops from "ready" back to "preparing".
        $order->reconcilePickingStatus();

        return redirect()->route('orders.show', $order)
            ->with('success', "Undid fulfillment of {$validated['quantity']} units. Stock has been restored.");
    }

    /**
     * Reserve available stock across the order, FIFO. The loop itself lives in
     * AllocatesOrderStock so the chilled run sheet can drive it too.
     */
    public function autoAllocate(Order $order): RedirectResponse
    {
        $result = $this->autoAllocateOrder($order);

        if ($result['units'] === 0) {
            return redirect()->route('orders.show', $order)
                ->withErrors(['auto_allocate' => 'No items could be allocated. Check stock availability.']);
        }

        $message = "Auto-allocated {$result['units']} ".str('unit')->plural($result['units'])
            .' across '.$result['lines_filled'].' '.str('line')->plural($result['lines_filled']).'.';

        if ($result['lines_short'] > 0) {
            // Name them — these are the lines the office may need to chase or
            // close short, and "some lines" sent them hunting through the page.
            $named = array_slice($result['short_labels'], 0, 3);
            $extra = count($result['short_labels']) - count($named);
            $message .= ' Not fully allocated: '.implode(', ', $named)
                .($extra > 0 ? " +{$extra} more" : '').'.';
        }

        return redirect()->route('orders.show', $order)->with('success', $message);
    }

    /**
     * When every item on a preparing order has been fully fulfilled, flip
     * it to "ready" so the dispatch CTA becomes available without a manual
     * status edit.
     */
    private function markPickingComplete(Order $order): void
    {
        if ($order->status !== 'preparing') {
            return;
        }

        $order->load('orderItems');

        if ($order->isPickingComplete()) {
            $order->update(['status' => 'ready']);
        }
    }
}
