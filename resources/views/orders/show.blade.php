<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Order Details') }} - {{ $order->order_number }}
            </h2>
            <div class="flex space-x-2">
                <a href="{{ route('orders.edit', $order) }}" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                    Edit Order
                </a>
                <a href="{{ route('orders.index') }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                    Back to Orders
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <!-- Order Header -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                        <!-- Order Information -->
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Order Information</h3>
                            <div class="space-y-2">
                                <div>
                                    <span class="font-medium text-gray-600">Order Number:</span>
                                    <span class="ml-2">{{ $order->order_number }}</span>
                                </div>
                                <div>
                                    <span class="font-medium text-gray-600">Order Date:</span>
                                    <span class="ml-2">{{ $order->order_date->format('d/m/Y') }}</span>
                                </div>
                                <div>
                                    <span class="font-medium text-gray-600">Delivery Date:</span>
                                    <span class="ml-2">{{ $order->delivery_date ? $order->delivery_date->format('d/m/Y') : 'Not specified' }}</span>
                                </div>
                                <div>
                                    <span class="font-medium text-gray-600">Status:</span>
                                    <span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                        bg-{{ $order->status_color }}-100 text-{{ $order->status_color }}-800">
                                        {{ ucfirst($order->status) }}
                                    </span>
                                </div>
                                <div>
                                    <span class="font-medium text-gray-600">Payment Status:</span>
                                    <span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                        bg-{{ $order->payment_status_color }}-100 text-{{ $order->payment_status_color }}-800">
                                        {{ ucfirst($order->payment_status) }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Customer Information -->
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Customer Information</h3>
                            <div class="space-y-2">
                                <div>
                                    <span class="font-medium text-gray-600">Name:</span>
                                    <span class="ml-2">{{ $order->customer->name }}</span>
                                </div>
                                <div>
                                    <span class="font-medium text-gray-600">Email:</span>
                                    <span class="ml-2">{{ $order->customer->email }}</span>
                                </div>
                                <div>
                                    <span class="font-medium text-gray-600">Phone:</span>
                                    <span class="ml-2">{{ $order->customer->phone ?? 'Not provided' }}</span>
                                </div>
                                <div>
                                    <span class="font-medium text-gray-600">Address:</span>
                                    <span class="ml-2">{{ $order->customer->address ?? 'Not provided' }}</span>
                                </div>
                                @if($order->delivery_address)
                                    <div>
                                        <span class="font-medium text-gray-600">Delivery Address:</span>
                                        <span class="ml-2">{{ $order->delivery_address }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Order Notes -->
                    @if($order->notes)
                        <div class="mb-8">
                            <div class="bg-yellow-50 p-4 rounded-lg">
                                <h3 class="text-lg font-medium text-gray-900 mb-2">Order Notes</h3>
                                <p class="text-gray-700">{{ $order->notes }}</p>
                            </div>
                        </div>
                    @endif

                    <!-- Order Items -->
                    <div class="mb-8">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Order Items</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full bg-white border border-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                            Product
                                        </th>
                                        <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                            Unit Price
                                        </th>
                                        <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                            Quantity Ordered
                                        </th>
                                        <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                            Quantity Allocated
                                        </th>
                                        <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                            Quantity Fulfilled
                                        </th>
                                        <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                            Line Total
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($order->orderItems as $item)
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div>
                                                    <div class="text-sm font-medium text-gray-900">
                                                        {{ $item->productVariant->product->name }}
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        {{ $item->productVariant->name }}
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                €{{ number_format($item->unit_price, 2) }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {{ $item->quantity_ordered }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {{ $item->quantity_allocated ?? 0 }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {{ $item->quantity_fulfilled ?? 0 }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                €{{ number_format($item->line_total, 2) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Order Totals -->
                    <div class="bg-gray-50 p-6 rounded-lg">
                        <div class="flex justify-end">
                            <div class="w-64 space-y-2">
                                <div class="flex justify-between">
                                    <span class="font-medium text-gray-600">Subtotal:</span>
                                    <span class="font-medium text-gray-900">€{{ number_format($order->subtotal, 2) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="font-medium text-gray-600">Tax:</span>
                                    <span class="font-medium text-gray-900">€{{ number_format($order->tax_amount, 2) }}</span>
                                </div>
                                <div class="border-t border-gray-200 pt-2">
                                    <div class="flex justify-between">
                                        <span class="font-bold text-gray-900">Total:</span>
                                        <span class="font-bold text-gray-900 text-lg">€{{ number_format($order->total_amount, 2) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Order Actions -->
                    <div class="mt-8 flex justify-between items-center">
                        <div class="text-sm text-gray-500">
                            Created: {{ $order->created_at->format('d/m/Y H:i') }}
                            @if($order->updated_at != $order->created_at)
                                | Last updated: {{ $order->updated_at->format('d/m/Y H:i') }}
                            @endif
                        </div>
                        
                        <div class="flex space-x-2">
                            @if($order->canBeCancelled())
                                <form method="POST" action="{{ route('orders.update', $order) }}" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="cancelled">
                                    <input type="hidden" name="payment_status" value="{{ $order->payment_status }}">
                                    <button type="submit" 
                                        onclick="return confirm('Are you sure you want to cancel this order?')"
                                        class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">
                                        Cancel Order
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>