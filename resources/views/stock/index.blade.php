<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Stock Overview') }}
            </h2>
            <div class="text-right">
                <p class="text-sm text-gray-600">Total Stock Value</p>
                <p class="text-2xl font-bold text-green-600">€{{ number_format($totalValue, 2) }}</p>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Ready to Sell -->
                <div class="bg-green-50 overflow-hidden shadow-sm sm:rounded-lg border border-green-200">
                    <div class="p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-green-500 rounded-full flex items-center justify-center">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-green-600">Ready to Sell</p>
                                <p class="text-2xl font-semibold text-green-900">{{ $readyToSell->count() }}</p>
                                <p class="text-sm text-green-700">€{{ number_format($readyValue, 2) }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Maturing -->
                <div class="bg-orange-50 overflow-hidden shadow-sm sm:rounded-lg border border-orange-200">
                    <div class="p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <div class="w-8 h-8 bg-orange-500 rounded-full flex items-center justify-center">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-orange-600">Maturing</p>
                                <p class="text-2xl font-semibold text-orange-900">{{ $maturing->count() }}</p>
                                <p class="text-sm text-orange-700">€{{ number_format($maturingValue, 2) }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- By Product Type -->
                <div class="bg-blue-50 overflow-hidden shadow-sm sm:rounded-lg border border-blue-200">
                    <div class="p-6">
                        <h3 class="text-sm font-medium text-blue-600 mb-3">Stock by Type</h3>
                        <div class="space-y-2">
                            @foreach($summaryByType as $summary)
                                <div class="flex justify-between text-sm">
                                    <span class="text-blue-700 capitalize">{{ $summary['type'] }}:</span>
                                    <span class="text-blue-900 font-medium">{{ $summary['total_quantity'] }} (€{{ number_format($summary['total_value'], 0) }})</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <form method="GET" action="{{ route('stock.index') }}" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <!-- Product Type Filter -->
                        <div>
                            <label for="product_type" class="block text-sm font-medium text-gray-700 mb-1">Product Type</label>
                            <select name="product_type" id="product_type" class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                                <option value="">All Types</option>
                                <option value="milk" {{ request('product_type') === 'milk' ? 'selected' : '' }}>Milk</option>
                                <option value="yoghurt" {{ request('product_type') === 'yoghurt' ? 'selected' : '' }}>Yoghurt</option>
                                <option value="cheese" {{ request('product_type') === 'cheese' ? 'selected' : '' }}>Cheese</option>
                            </select>
                        </div>

                        <!-- Readiness Filter -->
                        <div>
                            <label for="readiness" class="block text-sm font-medium text-gray-700 mb-1">Readiness</label>
                            <select name="readiness" id="readiness" class="block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                                <option value="">All Stock</option>
                                <option value="ready" {{ request('readiness') === 'ready' ? 'selected' : '' }}>Ready to Sell</option>
                                <option value="maturing" {{ request('readiness') === 'maturing' ? 'selected' : '' }}>Still Maturing</option>
                            </select>
                        </div>

                        <!-- Filter Button -->
                        <div class="flex items-end">
                            <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded text-sm w-full">
                                Apply Filters
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Ready to Sell Stock -->
            @if($readyToSell->count() > 0)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Ready to Sell ({{ $readyToSell->count() }} items)</h3>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Variant</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stock</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Price</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Value</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Production Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expiry</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($readyToSell as $item)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">{{ $item->batch->product->name }}</div>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                            @if($item->batch->product->type === 'milk') bg-blue-100 text-blue-800
                                            @elseif($item->batch->product->type === 'yoghurt') bg-purple-100 text-purple-800
                                            @elseif($item->batch->product->type === 'cheese') bg-yellow-100 text-yellow-800
                                            @endif">
                                            {{ ucfirst($item->batch->product->type) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <a href="{{ route('batches.show', $item->batch) }}" class="text-blue-600 hover:text-blue-900">
                                            {{ $item->batch->batch_code }}
                                        </a>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $item->productVariant->name }}
                                        <div class="text-xs text-gray-500">{{ $item->productVariant->size }} {{ $item->productVariant->unit }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-green-600">
                                        {{ number_format($item->quantity_remaining) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        €{{ number_format($item->productVariant->base_price, 2) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        €{{ number_format($item->quantity_remaining * $item->productVariant->base_price, 2) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $item->batch->production_date->format('d/m/Y') }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        @if($item->batch->expiry_date)
                                            <span class="{{ $item->batch->isExpired() ? 'text-red-600 font-medium' : 'text-gray-900' }}">
                                                {{ $item->batch->expiry_date->format('d/m/Y') }}
                                            </span>
                                        @else
                                            <span class="text-gray-400">N/A</span>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif

            <!-- Maturing Stock -->
            @if($maturing->count() > 0)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Maturing Stock ({{ $maturing->count() }} items)</h3>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Variant</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stock</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Price</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Future Value</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Production Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ready Date</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($maturing as $item)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">{{ $item->batch->product->name }}</div>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                            {{ ucfirst($item->batch->product->type) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <a href="{{ route('batches.show', $item->batch) }}" class="text-blue-600 hover:text-blue-900">
                                            {{ $item->batch->batch_code }}
                                        </a>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $item->productVariant->name }}
                                        <div class="text-xs text-gray-500">{{ $item->productVariant->size }} {{ $item->productVariant->unit }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-orange-600">
                                        {{ number_format($item->quantity_remaining) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        €{{ number_format($item->productVariant->base_price, 2) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        €{{ number_format($item->quantity_remaining * $item->productVariant->base_price, 2) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $item->batch->production_date->format('d/m/Y') }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-orange-600">
                                        {{ $item->batch->ready_date->format('d/m/Y') }}
                                        <div class="text-xs text-gray-500">{{ $item->batch->ready_date->diffForHumans() }}</div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif

            <!-- Empty State -->
            @if($readyToSell->count() === 0 && $maturing->count() === 0)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-center py-8">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10" />
                    </svg>
                    <h3 class="mt-2 text-sm font-medium text-gray-900">No stock available</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        No stock items match your current filters, or you haven't created any batches yet.
                    </p>
                    <div class="mt-6">
                        <a href="{{ route('batches.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                            Create First Batch
                        </a>
                    </div>
                </div>
            </div>
            @endif

        </div>
    </div>
</x-app-layout>