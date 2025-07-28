<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Batch: ') . $batch->batch_code }}
            </h2>
            <div class="flex space-x-2">
                <a href="{{ route('batches.edit', $batch) }}" class="bg-yellow-500 hover:bg-yellow-700 text-white font-bold py-2 px-4 rounded">
                    Edit Batch
                </a>
                <a href="{{ route('batches.index') }}" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                    Back to Batches
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            @if (session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
                    <span class="block sm:inline">{{ session('success') }}</span>
                </div>
            @endif

            <!-- Batch Information -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-semibold mb-4">Batch Information</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Batch Code</label>
                            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $batch->batch_code }}</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Product</label>
                            <p class="mt-1 text-sm text-gray-900">{{ $batch->product->name }}</p>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium mt-1
                                @if($batch->product->type === 'milk') bg-blue-100 text-blue-800
                                @elseif($batch->product->type === 'yoghurt') bg-purple-100 text-purple-800
                                @elseif($batch->product->type === 'cheese') bg-yellow-100 text-yellow-800
                                @endif">
                                {{ ucfirst($batch->product->type) }}
                            </span>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Production Date</label>
                            <p class="mt-1 text-sm text-gray-900">{{ $batch->production_date->format('d/m/Y') }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Raw Milk Used</label>
                            <p class="mt-1 text-sm text-gray-900">{{ number_format($batch->raw_milk_litres, 2) }} litres</p>
                            @if($batch->wheels_produced && $batch->product->type === 'cheese')
                                <p class="text-xs text-gray-600 mt-1">({{ $batch->wheels_produced }} wheels produced)</p>
                            @endif
                        </div>

                        @if($batch->finished_product_weight > 0)
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Finished Product Weight</label>
                            <p class="mt-1 text-sm text-gray-900">{{ number_format($batch->finished_product_weight, 2) }} kg</p>
                            @if($batch->production_yield)
                                <p class="text-xs text-gray-600 mt-1">
                                    Yield: {{ number_format($batch->production_yield * 100, 1) }}%
                                    @if($batch->product->type === 'cheese')
                                        ({{ number_format($batch->production_yield, 3) }} kg cheese per litre milk)
                                    @elseif($batch->product->type === 'yoghurt')
                                        ({{ number_format($batch->production_yield, 3) }} kg yoghurt per litre milk)
                                    @endif
                                </p>
                            @endif
                        </div>
                        @endif

                        @if($batch->ready_date)
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Ready Date</label>
                            <p class="mt-1 text-sm text-gray-900">{{ $batch->ready_date->format('d/m/Y') }}</p>
                            @if(!$batch->isReadyToSell())
                                <span class="text-orange-600 text-xs">
                                    ({{ $batch->ready_date->diffForHumans() }})
                                </span>
                            @else
                                <span class="text-green-600 text-xs">Ready Now</span>
                            @endif
                        </div>
                        @endif

                        @if($batch->expiry_date)
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Expiry Date</label>
                            <p class="mt-1 text-sm text-gray-900">{{ $batch->expiry_date->format('d/m/Y') }}</p>
                            @if($batch->isExpired())
                                <span class="text-red-600 text-xs">Expired</span>
                            @endif
                        </div>
                        @endif

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Status</label>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium mt-1
                                @if($batch->status === 'active') bg-green-100 text-green-800
                                @elseif($batch->status === 'sold_out') bg-red-100 text-red-800
                                @else bg-gray-100 text-gray-800
                                @endif">
                                {{ ucfirst(str_replace('_', ' ', $batch->status)) }}
                            </span>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Stock Remaining</label>
                            <p class="mt-1 text-lg font-semibold {{ $batch->remaining_stock > 0 ? 'text-green-600' : 'text-red-600' }}">
                                {{ $batch->remaining_stock }} units
                            </p>
                        </div>
                    </div>

                    @if($batch->notes)
                    <div class="mt-6">
                        <label class="block text-sm font-medium text-gray-700">Production Notes</label>
                        <p class="mt-1 text-sm text-gray-900 bg-gray-50 rounded-md p-3">{{ $batch->notes }}</p>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Stock Breakdown by Variant -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-semibold mb-4">Stock Breakdown by Variant</h3>
                    
                    @if($batch->batchItems->count() > 0)
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Variant</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Size</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Weight</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Produced</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sold</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Remaining</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Weight</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($batch->batchItems as $item)
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            {{ $item->productVariant->name }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ $item->productVariant->size }} {{ $item->productVariant->unit }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ $item->unit_weight_kg ? number_format($item->unit_weight_kg, 3) . ' kg' : 'N/A' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ number_format($item->quantity_produced) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ number_format($item->quantity_sold) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium {{ $item->quantity_remaining > 0 ? 'text-green-600' : 'text-red-600' }}">
                                            {{ number_format($item->quantity_remaining) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ $item->unit_weight_kg ? number_format($item->total_weight, 2) . ' kg' : 'N/A' }}
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-gray-500 text-center py-4">No batch items recorded.</p>
                    @endif
                </div>
            </div>

            <!-- Cheese Cutting History (if applicable) -->
            @if($batch->product->type === 'cheese' && $batch->cuttingLogs->count() > 0)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-semibold mb-4">Cheese Cutting History</h3>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cut Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Source Wheel</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vacuum Packs Created</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Weight</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Avg Pack Weight</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Notes</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($batch->cuttingLogs as $log)
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $log->cut_date->format('d/m/Y') }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $log->sourceBatchItem->productVariant->name }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ number_format($log->vacuum_packs_created) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ number_format($log->total_weight_kg, 2) }} kg
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ number_format($log->average_pack_weight, 3) }} kg
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $log->notes ?? 'N/A' }}
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif

            <!-- Actions -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-semibold mb-4">Actions</h3>
                    <div class="flex flex-wrap gap-2">
                        @if($batch->product->type === 'cheese')
                            @php
                                $availableWheels = $batch->batchItems->filter(function($item) {
                                    return str_contains(strtolower($item->productVariant->name), 'wheel') && $item->quantity_remaining > 0;
                                });
                            @endphp
                            @if($availableWheels->count() > 0 && ($batch->ready_date === null || $batch->ready_date <= now()))
                                <a href="{{ route('cheese-cutting.index') }}" class="bg-purple-500 hover:bg-purple-700 text-white font-bold py-2 px-4 rounded text-sm">
                                    Cut Cheese Wheels
                                </a>
                            @else
                                <button class="bg-gray-400 text-white font-bold py-2 px-4 rounded text-sm cursor-not-allowed" disabled>
                                    @if($batch->ready_date && $batch->ready_date > now())
                                        Cheese Not Ready ({{ $batch->ready_date->format('d/m/Y') }})
                                    @else
                                        No Wheels Available
                                    @endif
                                </button>
                            @endif
                        @endif
                        <button class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded text-sm">
                            Adjust Stock
                        </button>
                        <button class="bg-green-500 hover:bg-green-700 text-white font-bold py-2 px-4 rounded text-sm">
                            Generate Traceability Report
                        </button>
                    </div>
                    <p class="text-sm text-gray-500 mt-2">Additional features coming soon</p>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>