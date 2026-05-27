<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsAllocationData;
use App\Models\BatchItem;
use App\Models\Order;
use App\Models\OrderAllocation;
use App\Models\OrderItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderAllocationController extends Controller
{
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
        $isVariableWeight = $orderItem->isVariableWeight();

        // Build validation rules dynamically
        $rules = [
            'quantity' => 'required|integer|min:1|max:'.$allocation->quantity_remaining,
        ];

        if ($isVariableWeight) {
            $rules['actual_weight_kg'] = 'required|numeric|min:0.001';
        }

        $validated = $request->validate($rules);

        $actualWeight = $validated['actual_weight_kg'] ?? null;
        $order = $orderItem->order;

        $success = $orderItem->fulfillAllocation(
            $allocation,
            $validated['quantity'],
            $actualWeight
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

    public function autoAllocate(Order $order): RedirectResponse
    {
        $allocatedItems = 0;
        $errors = [];

        foreach ($order->orderItems as $orderItem) {
            $remainingToAllocate = $orderItem->quantity_ordered - $orderItem->quantity_allocated;

            if ($remainingToAllocate <= 0) {
                continue; // Already fully allocated
            }

            // Find available batch items (FIFO - First In, First Out)
            $batchItems = BatchItem::with('batch')
                ->where('product_variant_id', $orderItem->product_variant_id)
                ->where('quantity_remaining', '>', 0)
                ->whereHas('batch', function ($q) {
                    $q->where('status', 'active')
                        ->where(function ($q) {
                            $q->whereNull('expiry_date')
                                ->orWhere('expiry_date', '>', now()->toDateString());
                        });
                })
                ->get()
                ->filter(fn ($batchItem) => $batchItem->isAvailableForAllocation())
                ->sortBy('batch.production_date');

            foreach ($batchItems as $batchItem) {
                if ($remainingToAllocate <= 0) {
                    break;
                }

                $quantityToAllocate = min($remainingToAllocate, $batchItem->available_quantity);

                if ($quantityToAllocate > 0) {
                    $allocation = $orderItem->allocateFromBatchItem($batchItem, $quantityToAllocate);

                    if ($allocation) {
                        $allocatedItems++;
                        $remainingToAllocate -= $quantityToAllocate;
                    }
                }
            }

            if ($remainingToAllocate > 0) {
                $errors[] = "Could not fully allocate {$orderItem->productVariant->name} - insufficient stock.";
            }
        }

        if ($allocatedItems > 0) {
            $this->markPickingStarted($order);

            $message = "Auto-allocated {$allocatedItems} order items.";
            if (! empty($errors)) {
                $message .= ' Some items could not be fully allocated.';
            }

            return redirect()->route('orders.show', $order)->with('success', $message);
        }

        return redirect()->route('orders.show', $order)
            ->withErrors(['auto_allocate' => 'No items could be allocated. Check stock availability.']);
    }

    /**
     * Bump a confirmed order to "preparing" once any stock has been allocated.
     * Keeps the order detail's status stepper in sync with reality so the
     * user doesn't have to manually flip status via the edit form.
     */
    private function markPickingStarted(Order $order): void
    {
        if ($order->status === 'confirmed') {
            $order->update(['status' => 'preparing']);
        }
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

        if ($order->isFullyFulfilled()) {
            $order->update(['status' => 'ready']);
        }
    }
}
