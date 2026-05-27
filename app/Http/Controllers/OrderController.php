<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsAllocationData;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OrderController extends Controller
{
    use BuildsAllocationData;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Order::class);
        $query = Order::with(['customer', 'orderItems.productVariant']);

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        $orders = $query->orderBy('order_date', 'desc')->paginate(20);
        $customers = Customer::where('is_active', true)->orderBy('name')->get();

        return view('orders.index', compact('orders', 'customers'));
    }

    public function show(Request $request, Order $order): View
    {
        $this->authorize('view', $order);
        $order->load([
            'customer',
            'orderItems.productVariant.product',
            'orderItems.orderAllocations.batchItem.batch',
        ]);

        $listFilters = array_filter($request->only(['status', 'payment_status', 'customer_id']), fn ($v) => $v !== null && $v !== '');

        $listLimit = 50;
        $listQuery = Order::with(['customer', 'orderItems.productVariant.product'])
            ->orderBy('order_date', 'desc');

        foreach ($listFilters as $field => $value) {
            $listQuery->where($field, $value);
        }

        $listTotal = (clone $listQuery)->count();
        $orderList = $listQuery->limit($listLimit)->get();

        // Ensure the selected order is present in the list even when it falls outside the cap or filters.
        if (! $orderList->contains('id', $order->id)) {
            $orderList->prepend($order);
        }

        // The picking workflow (allocate/fulfill) is rendered inline on this page for
        // orders that are mid-fulfillment. Only build the batch-availability data when
        // the rich allocation block will actually render and the user can mutate it.
        $availableBatchItems = [];
        if (in_array($order->status, ['confirmed', 'preparing', 'ready'], true)
            && $request->user()->can('update', $order)) {
            $availableBatchItems = $this->buildAvailableBatchItems($order);
        }

        // Items can be added inline while the order is still open. Provide the
        // variant list for the picker only when that control will render.
        $productVariants = collect();
        if (! in_array($order->status, ['dispatched', 'delivered', 'cancelled'], true)
            && $request->user()->can('update', $order)) {
            $productVariants = $this->activeVariantsGrouped();
        }

        return view('orders.show', compact('order', 'orderList', 'listFilters', 'listTotal', 'listLimit', 'availableBatchItems', 'productVariants'));
    }

    public function create(): View
    {
        $this->authorize('create', Order::class);
        $customers = Customer::where('is_active', true)->orderBy('name')->get();
        $productVariants = $this->activeVariantsGrouped();

        return view('orders.create', compact('customers', 'productVariants'));
    }

    /**
     * Active variants of active products, grouped by product name — the option
     * source for the order item picker on both create and show (add-item).
     */
    private function activeVariantsGrouped(): \Illuminate\Support\Collection
    {
        return ProductVariant::with('product')
            ->whereHas('product', function ($q) {
                $q->where('is_active', true);
            })
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->groupBy('product.name');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);

        $items = collect($request->input('items', []))
            ->filter(fn ($item) => is_array($item) && ! empty($item['product_variant_id'] ?? null))
            ->values()
            ->all();
        $request->merge(['items' => $items]);

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'order_date' => 'required|date',
            'delivery_date' => 'nullable|date|after_or_equal:order_date',
            'delivery_address' => 'nullable|string',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_variant_id' => 'required|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
        ], [
            'items.required' => 'You must add at least one item to the order.',
            'items.min' => 'You must add at least one item to the order.',
        ]);

        $order = DB::transaction(function () use ($validated) {
            // Create the order
            $order = Order::create([
                'customer_id' => $validated['customer_id'],
                'order_date' => $validated['order_date'],
                'delivery_date' => $validated['delivery_date'],
                'delivery_address' => $validated['delivery_address'],
                'notes' => $validated['notes'],
                'status' => 'pending',
                'payment_status' => 'pending',
            ]);

            // Add order items
            foreach ($validated['items'] as $itemData) {
                $variant = ProductVariant::find($itemData['product_variant_id']);

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_variant_id' => $itemData['product_variant_id'],
                    'quantity_ordered' => $itemData['quantity'],
                    'unit_price' => $variant->base_price,
                ]);
            }

            // Calculate totals
            $order->calculateTotals();

            return $order;
        });

        return redirect()->route('orders.show', $order)
            ->with('success', 'Order '.$order->order_number.' created successfully!');
    }

    public function edit(Order $order): View
    {
        $this->authorize('update', $order);
        $order->load(['orderItems.productVariant']);
        $customers = Customer::where('is_active', true)->orderBy('name')->get();

        return view('orders.edit', compact('order', 'customers'));
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', $order);
        $validated = $request->validate([
            'delivery_date' => 'nullable|date|after_or_equal:order_date',
            'status' => 'required|in:pending,confirmed,preparing,ready,dispatched,delivered,cancelled',
            'payment_status' => 'required|in:pending,paid,partial,overdue',
            'delivery_address' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $isCancelling = $validated['status'] === 'cancelled' && $order->status !== 'cancelled';
        $releasedUnits = 0;

        DB::transaction(function () use ($order, $validated, $isCancelling, &$releasedUnits) {
            $order->update($validated);

            if (! $isCancelling) {
                return;
            }

            // Cancelling returns every reserved and picked unit to its batch —
            // reserved units just drop their reservation, picked units restore
            // BatchItem.quantity_remaining (via OrderItem::releaseUnits).
            $order->load('orderItems');

            foreach ($order->orderItems as $orderItem) {
                $releasedUnits += $orderItem->releaseUnits($orderItem->quantity_allocated);
            }
        });

        $message = 'Order updated successfully.';
        if ($isCancelling) {
            $message = $releasedUnits > 0
                ? 'Order cancelled. Reserved and picked stock has been returned to its batch.'
                : 'Order cancelled.';
        }

        return redirect()->route('orders.show', $order)
            ->with('success', $message);
    }

    public function markDispatched(Order $order): RedirectResponse
    {
        $this->authorize('update', $order);

        if ($order->status !== 'ready') {
            return back()->with('error', 'Only ready orders can be dispatched.');
        }

        $order->update([
            'status' => 'dispatched',
            'dispatched_at' => now(),
        ]);

        return redirect()->route('orders.show', $order)
            ->with('success', 'Order '.$order->order_number.' marked as dispatched.');
    }

    public function markDelivered(Order $order): RedirectResponse
    {
        $this->authorize('update', $order);

        if ($order->status !== 'dispatched') {
            return back()->with('error', 'Only dispatched orders can be marked as delivered.');
        }

        $order->update([
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        return redirect()->route('orders.show', $order)
            ->with('success', 'Order '.$order->order_number.' marked as delivered.');
    }

    /**
     * Add a line to an existing order. Allowed while the order is still open
     * (pending → ready); blocked once dispatched/delivered/cancelled. If the
     * variant is already on the order the quantity is merged into that line
     * rather than creating a duplicate. Adding unpicked stock to a ready order
     * reopens picking (ready → preparing); fulfilling it flips it back via
     * OrderAllocationController::markPickingComplete().
     */
    public function storeItem(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('update', $order);

        if (in_array($order->status, ['dispatched', 'delivered', 'cancelled'], true)) {
            return redirect()->route('orders.show', $order)
                ->with('error', "Items can't be added to a {$order->status} order.");
        }

        $validated = $request->validate([
            'product_variant_id' => 'required|exists:product_variants,id',
            'quantity' => 'required|integer|min:1',
        ]);

        DB::transaction(function () use ($order, $validated) {
            $variant = ProductVariant::findOrFail($validated['product_variant_id']);

            $existing = $order->orderItems()
                ->where('product_variant_id', $variant->id)
                ->first();

            if ($existing) {
                // Merge: bump ordered qty. Allocated/fulfilled are untouched, so
                // the line becomes under-allocated and its picker reappears.
                $existing->quantity_ordered += $validated['quantity'];
                $existing->save();
            } else {
                $order->orderItems()->create([
                    'product_variant_id' => $variant->id,
                    'quantity_ordered' => $validated['quantity'],
                    'unit_price' => $variant->base_price,
                ]);
            }

            $order->calculateTotals();

            // The order had been fully picked; new work means it's no longer ready.
            if ($order->status === 'ready') {
                $order->update(['status' => 'preparing']);
            }
        });

        return redirect()->route('orders.show', $order)
            ->with('success', 'Item added to order.');
    }

    /**
     * Change a line's ordered quantity. Increasing just raises the quantity
     * (the inline picker then shows the shortfall); decreasing unwinds the
     * difference via OrderItem::releaseUnits() — releasing reservations first
     * and returning any picked stock to its batch. Blocked once the order is
     * dispatched/delivered/cancelled.
     */
    public function updateItem(Request $request, Order $order, OrderItem $orderItem): RedirectResponse
    {
        $this->authorize('update', $order);
        abort_if($orderItem->order_id !== $order->id, 404);

        if (in_array($order->status, ['dispatched', 'delivered', 'cancelled'], true)) {
            return redirect()->route('orders.show', $order)
                ->with('error', "Items can't be changed on a {$order->status} order.");
        }

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $newQty = (int) $validated['quantity'];
        $oldQty = (int) $orderItem->quantity_ordered;

        if ($newQty === $oldQty) {
            return redirect()->route('orders.show', $order)->with('success', 'No change to quantity.');
        }

        DB::transaction(function () use ($order, $orderItem, $newQty, $oldQty) {
            if ($newQty < $oldQty) {
                // Release the difference (reserved units first, then picked stock).
                $orderItem->releaseUnits($oldQty - $newQty);
            }

            $orderItem->quantity_ordered = $newQty;
            $orderItem->save(); // saving() hook recomputes line_total

            $order->calculateTotals();
            $order->reconcilePickingStatus();
        });

        return redirect()->route('orders.show', $order)
            ->with('success', 'Item quantity updated.');
    }

    /**
     * Remove a line from an order, returning any reserved/picked stock to its
     * batch first (the FK cascade alone would strand it). An order must keep at
     * least one line; blocked once dispatched/delivered/cancelled.
     */
    public function destroyItem(Request $request, Order $order, OrderItem $orderItem): RedirectResponse
    {
        $this->authorize('update', $order);
        abort_if($orderItem->order_id !== $order->id, 404);

        if (in_array($order->status, ['dispatched', 'delivered', 'cancelled'], true)) {
            return redirect()->route('orders.show', $order)
                ->with('error', "Items can't be removed from a {$order->status} order.");
        }

        // An order can't go empty: removing its only line cancels the order
        // instead (keeping the line as history), returning any committed stock.
        if ($order->orderItems()->count() <= 1) {
            DB::transaction(function () use ($order, $orderItem) {
                $orderItem->releaseUnits($orderItem->quantity_allocated);
                $order->update(['status' => 'cancelled']);
            });

            return redirect()->route('orders.show', $order)
                ->with('success', 'That was the only item, so order '.$order->order_number.' has been cancelled. Any reserved or picked stock has been returned to its batch.');
        }

        DB::transaction(function () use ($order, $orderItem) {
            // Return all committed stock (reserved + picked) before deleting.
            $orderItem->releaseUnits($orderItem->quantity_allocated);
            $orderItem->delete();

            $order->calculateTotals();
            $order->reconcilePickingStatus();
        });

        return redirect()->route('orders.show', $order)
            ->with('success', 'Item removed. Any picked stock has been returned to its batch.');
    }
}
