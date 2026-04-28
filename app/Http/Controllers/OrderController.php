<?php

namespace App\Http\Controllers;

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
        $order->load(['customer', 'orderItems.productVariant.product']);

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

        return view('orders.show', compact('order', 'orderList', 'listFilters', 'listTotal', 'listLimit'));
    }

    public function create(): View
    {
        $this->authorize('create', Order::class);
        $customers = Customer::where('is_active', true)->orderBy('name')->get();
        $productVariants = ProductVariant::with('product')
            ->whereHas('product', function ($q) {
                $q->where('is_active', true);
            })
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->groupBy('product.name');

        return view('orders.create', compact('customers', 'productVariants'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Order::class);
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

        $order->update($validated);

        return redirect()->route('orders.show', $order)
            ->with('success', 'Order updated successfully.');
    }
}
