<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Allocate Stock') }} - {{ $order->order_number }}
            </h2>
            <div class="flex space-x-2">
                <form method="POST" action="{{ route('order-allocations.auto-allocate', $order) }}" class="inline">
                    @csrf
                    <button type="submit" 
                        onclick="return confirm('Auto-allocate available stock to this order using FIFO (First In, First Out)?')"
                        class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded">
                        Auto Allocate
                    </button>
                </form>
                <a href="{{ route('order-allocations.index') }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                    Back to Allocations
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <!-- Order Summary -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6 text-gray-900">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <span class="font-medium text-gray-600">Customer:</span>
                            <div class="text-lg font-medium">{{ $order->customer->name }}</div>
                        </div>
                        <div>
                            <span class="font-medium text-gray-600">Order Date:</span>
                            <div class="text-lg font-medium">{{ $order->order_date->format('d/m/Y') }}</div>
                        </div>
                        <div>
                            <span class="font-medium text-gray-600">Status:</span>
                            <div>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                    bg-{{ $order->status_color }}-100 text-{{ $order->status_color }}-800">
                                    {{ ucfirst($order->status) }}
                                </span>
                            </div>
                        </div>
                        <div>
                            <span class="font-medium text-gray-600">Total:</span>
                            <div class="text-lg font-medium">€{{ number_format($order->total_amount, 2) }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Items and Allocations -->
            @foreach($order->orderItems as $orderItem)
                @php
                    $allocationPercentage = $orderItem->quantity_ordered > 0 ? 
                        ($orderItem->quantity_allocated / $orderItem->quantity_ordered) * 100 : 0;
                @endphp
                
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-6 text-gray-900">
                        <!-- Order Item Header -->
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">{{ $orderItem->productVariant->product->name }}</h3>
                                <p class="text-sm text-gray-600">{{ $orderItem->productVariant->name }}</p>
                                <p class="text-sm text-gray-500">
                                    Ordered: {{ $orderItem->quantity_ordered }} | 
                                    Allocated: {{ $orderItem->quantity_allocated }} | 
                                    Fulfilled: {{ $orderItem->quantity_fulfilled }}
                                </p>
                            </div>
                            <div class="text-right">
                                <div class="text-lg font-medium">€{{ number_format($orderItem->line_total, 2) }}</div>
                                @if($allocationPercentage > 0)
                                    <div class="text-sm text-gray-500">
                                        {{ number_format($allocationPercentage, 1) }}% allocated
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Progress Bar -->
                        <div class="mb-4">
                            <div class="flex justify-between text-sm text-gray-600 mb-1">
                                <span>Allocation Progress</span>
                                <span>{{ $orderItem->quantity_allocated }}/{{ $orderItem->quantity_ordered }}</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-blue-600 h-2 rounded-full" style="width: {{ min(100, $allocationPercentage) }}%"></div>
                            </div>
                        </div>

                        <!-- Current Allocations -->
                        @if($orderItem->orderAllocations->count() > 0)
                            <div class="mb-4">
                                <h4 class="font-medium text-gray-900 mb-2">Current Allocations</h4>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full bg-gray-50 border border-gray-200 rounded">
                                        <thead class="bg-gray-100">
                                            <tr>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-600 uppercase">Batch</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-600 uppercase">Production Date</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-600 uppercase">Allocated</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-600 uppercase">Fulfilled</th>
                                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-600 uppercase">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200">
                                            @foreach($orderItem->orderAllocations as $allocation)
                                                <tr>
                                                    <td class="px-4 py-2 text-sm text-gray-900">
                                                        {{ $allocation->batchItem->batch->batch_code }}
                                                    </td>
                                                    <td class="px-4 py-2 text-sm text-gray-500">
                                                        {{ $allocation->batchItem->batch->production_date->format('d/m/Y') }}
                                                    </td>
                                                    <td class="px-4 py-2 text-sm text-gray-900">
                                                        {{ $allocation->quantity_allocated }}
                                                    </td>
                                                    <td class="px-4 py-2 text-sm text-gray-900">
                                                        {{ $allocation->quantity_fulfilled }}
                                                    </td>
                                                    <td class="px-4 py-2 text-sm font-medium space-x-2">
                                                        @if($allocation->quantity_remaining > 0)
                                                            <form method="POST" action="{{ route('order-allocations.fulfill', $allocation) }}" class="inline">
                                                                @csrf
                                                                <input type="number" name="quantity" 
                                                                    min="1" max="{{ $allocation->quantity_remaining }}" 
                                                                    value="{{ $allocation->quantity_remaining }}"
                                                                    class="w-16 px-2 py-1 text-xs border border-gray-300 rounded">
                                                                <button type="submit" class="text-green-600 hover:text-green-900">Fulfill</button>
                                                            </form>
                                                        @endif
                                                        @if($allocation->quantity_fulfilled == 0)
                                                            <form method="POST" action="{{ route('order-allocations.deallocate', $allocation) }}" class="inline">
                                                                @csrf
                                                                @method('DELETE')
                                                                <button type="submit" 
                                                                    onclick="return confirm('Remove this allocation?')"
                                                                    class="text-red-600 hover:text-red-900">Remove</button>
                                                            </form>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif

                        <!-- Available Batch Items for Allocation -->
                        @if($orderItem->quantity_allocated < $orderItem->quantity_ordered && isset($availableBatchItems[$orderItem->id]) && $availableBatchItems[$orderItem->id]->count() > 0)
                            <div>
                                <h4 class="font-medium text-gray-900 mb-2">Available Stock</h4>
                                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                    <form method="POST" action="{{ route('order-allocations.allocate', $orderItem) }}" class="space-y-4">
                                        @csrf
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Select Batch</label>
                                                <select name="batch_item_id" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                    <option value="">Choose batch...</option>
                                                    @foreach($availableBatchItems[$orderItem->id] as $batchItem)
                                                        <option value="{{ $batchItem->id }}">
                                                            {{ $batchItem->batch->batch_code }} 
                                                            ({{ $batchItem->batch->production_date->format('d/m/Y') }}) 
                                                            - {{ $batchItem->available_quantity }} available
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Quantity to Allocate</label>
                                                <input type="number" name="quantity" 
                                                    min="1" 
                                                    max="{{ $orderItem->quantity_ordered - $orderItem->quantity_allocated }}"
                                                    value="{{ $orderItem->quantity_ordered - $orderItem->quantity_allocated }}"
                                                    required 
                                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            </div>
                                            <div class="flex items-end">
                                                <button type="submit" class="w-full bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                                                    Allocate
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        @elseif($orderItem->quantity_allocated < $orderItem->quantity_ordered)
                            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                                <div class="flex">
                                    <div class="flex-shrink-0">
                                        <svg class="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                    <div class="ml-3">
                                        <h3 class="text-sm font-medium text-yellow-800">No Available Stock</h3>
                                        <div class="mt-2 text-sm text-yellow-700">
                                            <p>No ready-to-sell stock available for this product variant. Check production status or create new batches.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-app-layout>