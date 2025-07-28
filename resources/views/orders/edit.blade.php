<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Edit Order') }} - {{ $order->order_number }}
            </h2>
            <div class="flex space-x-2">
                <a href="{{ route('orders.show', $order) }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                    View Order
                </a>
                <a href="{{ route('orders.index') }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                    Back to Orders
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <!-- Order Summary -->
                    <div class="mb-6 bg-gray-50 p-4 rounded-lg">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <span class="font-medium text-gray-600">Customer:</span>
                                <div class="text-lg font-medium">{{ $order->customer->name }}</div>
                            </div>
                            <div>
                                <span class="font-medium text-gray-600">Order Date:</span>
                                <div class="text-lg font-medium">{{ $order->order_date->format('d/m/Y') }}</div>
                            </div>
                            <div>
                                <span class="font-medium text-gray-600">Total Amount:</span>
                                <div class="text-lg font-medium">€{{ number_format($order->total_amount, 2) }}</div>
                            </div>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('orders.update', $order) }}">
                        @csrf
                        @method('PATCH')
                        
                        <!-- Hidden field to maintain order_date for validation -->
                        <input type="hidden" name="order_date" value="{{ $order->order_date->format('Y-m-d') }}">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <x-input-label for="status" :value="__('Order Status')" />
                                <select id="status" name="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                                    <option value="pending" {{ $order->status == 'pending' ? 'selected' : '' }}>Pending</option>
                                    <option value="confirmed" {{ $order->status == 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                                    <option value="preparing" {{ $order->status == 'preparing' ? 'selected' : '' }}>Preparing</option>
                                    <option value="ready" {{ $order->status == 'ready' ? 'selected' : '' }}>Ready</option>
                                    <option value="dispatched" {{ $order->status == 'dispatched' ? 'selected' : '' }}>Dispatched</option>
                                    <option value="delivered" {{ $order->status == 'delivered' ? 'selected' : '' }}>Delivered</option>
                                    <option value="cancelled" {{ $order->status == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                                </select>
                                <x-input-error :messages="$errors->get('status')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="payment_status" :value="__('Payment Status')" />
                                <select id="payment_status" name="payment_status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                                    <option value="pending" {{ $order->payment_status == 'pending' ? 'selected' : '' }}>Pending</option>
                                    <option value="paid" {{ $order->payment_status == 'paid' ? 'selected' : '' }}>Paid</option>
                                    <option value="partial" {{ $order->payment_status == 'partial' ? 'selected' : '' }}>Partial</option>
                                    <option value="overdue" {{ $order->payment_status == 'overdue' ? 'selected' : '' }}>Overdue</option>
                                </select>
                                <x-input-error :messages="$errors->get('payment_status')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="delivery_date" :value="__('Delivery Date')" />
                                <x-text-input id="delivery_date" name="delivery_date" type="date" class="mt-1 block w-full" 
                                    :value="old('delivery_date', $order->delivery_date?->format('Y-m-d'))" />
                                <x-input-error :messages="$errors->get('delivery_date')" class="mt-2" />
                            </div>
                        </div>

                        <div class="mb-6">
                            <x-input-label for="delivery_address" :value="__('Delivery Address')" />
                            <textarea id="delivery_address" name="delivery_address" rows="3" 
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('delivery_address', $order->delivery_address) }}</textarea>
                            <x-input-error :messages="$errors->get('delivery_address')" class="mt-2" />
                        </div>

                        <div class="mb-6">
                            <x-input-label for="notes" :value="__('Order Notes')" />
                            <textarea id="notes" name="notes" rows="4" 
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">{{ old('notes', $order->notes) }}</textarea>
                            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
                        </div>

                        <!-- Order Items (Read-only) -->
                        <div class="mb-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Order Items</h3>
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <div class="overflow-x-auto">
                                    <table class="min-w-full">
                                        <thead>
                                            <tr class="border-b border-gray-200">
                                                <th class="text-left py-2 text-sm font-medium text-gray-600">Product</th>
                                                <th class="text-left py-2 text-sm font-medium text-gray-600">Unit Price</th>
                                                <th class="text-left py-2 text-sm font-medium text-gray-600">Quantity</th>
                                                <th class="text-left py-2 text-sm font-medium text-gray-600">Line Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($order->orderItems as $item)
                                                <tr class="border-b border-gray-100">
                                                    <td class="py-2">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            {{ $item->productVariant->product->name }}
                                                        </div>
                                                        <div class="text-sm text-gray-500">
                                                            {{ $item->productVariant->name }}
                                                        </div>
                                                    </td>
                                                    <td class="py-2 text-sm text-gray-900">
                                                        €{{ number_format($item->unit_price, 2) }}
                                                    </td>
                                                    <td class="py-2 text-sm text-gray-900">
                                                        {{ $item->quantity_ordered }}
                                                    </td>
                                                    <td class="py-2 text-sm font-medium text-gray-900">
                                                        €{{ number_format($item->line_total, 2) }}
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            <tr class="border-t-2 border-gray-200">
                                                <td colspan="3" class="py-2 text-right font-medium text-gray-900">
                                                    Order Total:
                                                </td>
                                                <td class="py-2 text-sm font-bold text-gray-900">
                                                    €{{ number_format($order->total_amount, 2) }}
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                <p class="text-sm text-gray-500 mt-2">
                                    <em>Note: Order items cannot be modified after creation. Create a new order if item changes are needed.</em>
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-4">
                            <a href="{{ route('orders.show', $order) }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                                Cancel
                            </a>
                            <x-primary-button>
                                {{ __('Update Order') }}
                            </x-primary-button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>