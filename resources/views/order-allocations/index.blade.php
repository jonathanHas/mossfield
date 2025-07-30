<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Stock Allocation') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <!-- Info Banner -->
                    <div class="mb-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-blue-800">Stock Allocation Management</h3>
                                <div class="mt-2 text-sm text-blue-700">
                                    <p>Allocate available batch inventory to confirmed orders. Only orders with status "confirmed" or "preparing" are shown here.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="mb-6 bg-gray-50 p-4 rounded-lg">
                        <form method="GET" class="flex flex-wrap gap-4">
                            <div>
                                <label for="allocation_status" class="block text-sm font-medium text-gray-700 mb-1">Allocation Status</label>
                                <select name="allocation_status" id="allocation_status" class="rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="">All Orders</option>
                                    <option value="unallocated" {{ request('allocation_status') == 'unallocated' ? 'selected' : '' }}>Unallocated</option>
                                    <option value="partially_allocated" {{ request('allocation_status') == 'partially_allocated' ? 'selected' : '' }}>Partially Allocated</option>
                                    <option value="fully_allocated" {{ request('allocation_status') == 'fully_allocated' ? 'selected' : '' }}>Fully Allocated</option>
                                </select>
                            </div>
                            <div class="flex items-end">
                                <button type="submit" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                                    Filter
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Orders Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white border border-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                        Order
                                    </th>
                                    <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                        Customer
                                    </th>
                                    <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                        Order Date
                                    </th>
                                    <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                        Allocation Status
                                    </th>
                                    <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                        Items
                                    </th>
                                    <th class="px-6 py-3 border-b-2 border-gray-200 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($orders as $order)
                                    @php
                                        $totalItems = $order->orderItems->sum('quantity_ordered');
                                        $totalAllocated = $order->orderItems->sum('quantity_allocated');
                                        $allocationPercentage = $totalItems > 0 ? ($totalAllocated / $totalItems) * 100 : 0;
                                        
                                        if ($allocationPercentage == 100) {
                                            $allocationStatus = 'Fully Allocated';
                                            $allocationColor = 'green';
                                        } elseif ($allocationPercentage > 0) {
                                            $allocationStatus = 'Partially Allocated';
                                            $allocationColor = 'yellow';
                                        } else {
                                            $allocationStatus = 'Unallocated';
                                            $allocationColor = 'red';
                                        }
                                    @endphp
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <a href="{{ route('order-allocations.show', $order) }}" class="text-blue-600 hover:text-blue-900">
                                                {{ $order->order_number }}
                                            </a>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ $order->customer->name }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ $order->order_date->format('d/m/Y') }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                                bg-{{ $order->status_color }}-100 text-{{ $order->status_color }}-800">
                                                {{ ucfirst($order->status) }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                                    bg-{{ $allocationColor }}-100 text-{{ $allocationColor }}-800">
                                                    {{ $allocationStatus }}
                                                </span>
                                                @if($allocationPercentage > 0 && $allocationPercentage < 100)
                                                    <span class="ml-2 text-xs text-gray-500">
                                                        ({{ number_format($allocationPercentage, 1) }}%)
                                                    </span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ $totalAllocated }}/{{ $totalItems }} allocated
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <a href="{{ route('order-allocations.show', $order) }}" 
                                                   class="text-blue-600 hover:text-blue-900">Manage</a>
                                                <a href="{{ route('orders.show', $order) }}" 
                                                   class="text-gray-600 hover:text-gray-900">View Order</a>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">
                                            No orders requiring allocation found.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="mt-4">
                        {{ $orders->withQueryString()->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>