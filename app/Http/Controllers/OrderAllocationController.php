<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\BatchItem;
use App\Models\OrderAllocation;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class OrderAllocationController extends Controller
{
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

    public function show(Order $order): View
    {
        $order->load([
            'customer',
            'orderItems.productVariant.product',
            'orderItems.orderAllocations.batchItem.batch'
        ]);

        // Get available batch items for each order item
        $availableBatchItems = [];
        foreach ($order->orderItems as $orderItem) {
            $availableBatchItems[$orderItem->id] = BatchItem::with(['batch', 'productVariant'])
                ->where('product_variant_id', $orderItem->product_variant_id)
                ->where('quantity_remaining', '>', 0)
                ->whereHas('batch', function ($q) {
                    $q->where('status', 'active');
                })
                ->get()
                ->filter(function ($batchItem) {
                    return $batchItem->isReadyToSell() && $batchItem->available_quantity > 0;
                })
                ->sortBy('batch.production_date');
        }

        return view('order-allocations.show', compact('order', 'availableBatchItems'));
    }

    public function allocate(Request $request, OrderItem $orderItem): RedirectResponse
    {
        $validated = $request->validate([
            'batch_item_id' => 'required|exists:batch_items,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $batchItem = BatchItem::findOrFail($validated['batch_item_id']);
        
        // Verify the batch item is for the correct product variant
        if ($batchItem->product_variant_id !== $orderItem->product_variant_id) {
            return redirect()->back()
                ->withErrors(['batch_item_id' => 'Selected batch item does not match the order item product.']);
        }

        $allocation = $orderItem->allocateFromBatchItem($batchItem, $validated['quantity']);

        if (!$allocation) {
            return redirect()->back()
                ->withErrors(['quantity' => 'Cannot allocate this quantity. Check available stock and order limits.']);
        }

        return redirect()->back()
            ->with('success', "Allocated {$validated['quantity']} units from batch {$batchItem->batch->batch_code}.");
    }

    public function deallocate(OrderAllocation $allocation): RedirectResponse
    {
        if ($allocation->quantity_fulfilled > 0) {
            return redirect()->back()
                ->withErrors(['allocation' => 'Cannot deallocate - some items have already been fulfilled.']);
        }

        // Update order item allocated quantity
        $orderItem = $allocation->orderItem;
        $allocation->delete();
        
        $orderItem->quantity_allocated = $orderItem->orderAllocations()->sum('quantity_allocated');
        $orderItem->save();

        return redirect()->back()
            ->with('success', 'Allocation removed successfully.');
    }

    public function fulfill(Request $request, OrderAllocation $allocation): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1|max:' . $allocation->quantity_remaining,
        ]);

        $success = $allocation->orderItem->fulfillAllocation($allocation, $validated['quantity']);

        if (!$success) {
            return redirect()->back()
                ->withErrors(['quantity' => 'Could not fulfill this quantity.']);
        }

        return redirect()->back()
            ->with('success', "Fulfilled {$validated['quantity']} units.");
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
                    $q->where('status', 'active');
                })
                ->get()
                ->filter(function ($batchItem) {
                    return $batchItem->isReadyToSell() && $batchItem->available_quantity > 0;
                })
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
            $message = "Auto-allocated {$allocatedItems} order items.";
            if (!empty($errors)) {
                $message .= ' Some items could not be fully allocated.';
            }
            return redirect()->back()->with('success', $message);
        }

        return redirect()->back()
            ->withErrors(['auto_allocate' => 'No items could be allocated. Check stock availability.']);
    }
}