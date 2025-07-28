<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Cheese Cutting') }}
            </h2>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
                    <span class="block sm:inline">{{ session('success') }}</span>
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-semibold mb-4">Ready to Cut Cheese Batches</h3>
                    
                    @if($readyBatches->count() > 0)
                        <div class="space-y-6">
                            @foreach($readyBatches as $batch)
                                <div class="border border-gray-200 rounded-lg p-6">
                                    <div class="flex justify-between items-start mb-4">
                                        <div>
                                            <h4 class="text-lg font-medium text-gray-900">{{ $batch->product->name }}</h4>
                                            <p class="text-sm text-gray-600">Batch: {{ $batch->batch_code }}</p>
                                            <p class="text-sm text-gray-600">Production Date: {{ $batch->production_date->format('d/m/Y') }}</p>
                                            @if($batch->ready_date)
                                                <p class="text-sm text-green-600">Ready Date: {{ $batch->ready_date->format('d/m/Y') }}</p>
                                            @else
                                                <p class="text-sm text-green-600">Ready: Immediate</p>
                                            @endif
                                        </div>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            Ready to Cut
                                        </span>
                                    </div>

                                    <!-- Available Wheels -->
                                    <div class="mt-4">
                                        <h5 class="text-md font-medium text-gray-700 mb-2">Available Wheels</h5>
                                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                            @foreach($batch->batchItems as $batchItem)
                                                @if(str_contains(strtolower($batchItem->productVariant->name), 'wheel') && $batchItem->quantity_remaining > 0)
                                                    <div class="bg-gray-50 rounded-lg p-4">
                                                        <div class="flex justify-between items-center">
                                                            <div>
                                                                <p class="font-medium text-gray-900">{{ $batchItem->productVariant->name }}</p>
                                                                <p class="text-sm text-gray-600">{{ $batchItem->quantity_remaining }} wheels remaining</p>
                                                                <p class="text-xs text-gray-500">{{ number_format($batchItem->unit_weight_kg, 2) }}kg each</p>
                                                            </div>
                                                            <a href="{{ route('cheese-cutting.create', $batchItem) }}" 
                                                               class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-3 rounded text-sm">
                                                                Cut Wheel
                                                            </a>
                                                        </div>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>

                                    <!-- Existing Vacuum Packs (if any) -->
                                    @php
                                        $vacuumPacks = $batch->batchItems->filter(function($item) {
                                            return str_contains(strtolower($item->productVariant->name), 'vacuum') && $item->quantity_produced > 0;
                                        });
                                    @endphp
                                    
                                    @if($vacuumPacks->count() > 0)
                                        <div class="mt-4 pt-4 border-t border-gray-200">
                                            <h5 class="text-md font-medium text-gray-700 mb-2">Vacuum Packs Created</h5>
                                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                                @foreach($vacuumPacks as $vacuumPack)
                                                    <div class="bg-blue-50 rounded-lg p-3">
                                                        <p class="font-medium text-gray-900">{{ $vacuumPack->productVariant->name }}</p>
                                                        <p class="text-sm text-gray-600">{{ $vacuumPack->quantity_produced }} packs produced</p>
                                                        <p class="text-sm text-green-600">{{ $vacuumPack->quantity_remaining }} remaining</p>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10" />
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900">No cheese ready to cut</h3>
                            <p class="mt-1 text-sm text-gray-500">
                                Cheese batches need to mature before they can be cut. Check back when batches reach their ready date.
                            </p>
                            <div class="mt-6">
                                <a href="{{ route('batches.index', ['type' => 'cheese']) }}" 
                                   class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                    View All Cheese Batches
                                </a>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>